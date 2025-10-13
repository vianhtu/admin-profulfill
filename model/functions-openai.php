<?php
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client as GuzzleClient;
function openai_client(): GuzzleClient
{
    return new GuzzleClient([
        'base_uri' => 'https://api.openai.com',
        'timeout' => 120,
    ]);
}

function openai_request(string $method, string $uri, array $json = [], bool $body = false): array
{
    try {
        $client = openai_client();
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . OPENAI_API_KEY
            ],
            'http_errors' => false,
        ];

        if ($method === 'POST') {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $json;
        }

        $response = $client->request($method, $uri, $options);

        $status = $response->getStatusCode();
        $raw = (string)$response->getBody();

        if ($status < 200 || $status >= 300) {
            return ['status' => 'error', 'message' => "HTTP {$status} : {$raw}", 'code' => $status];
        }

        if ($body) {
            return ['status' => 'success', 'body' => $raw, 'code' => $status];
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return ['status' => 'success', 'data' => $data, 'code' => $status];
    } catch (GuzzleException $e) {
        return ['status' => 'error', 'message' => 'HTTP error: ' . $e->getMessage()];
    } catch (JsonException $e) {
        return ['status' => 'error', 'message' => 'JSON parse error: ' . $e->getMessage()];
    }
}

function openai_create_and_upload_batch_file(string $jsonl_content): array
{
    $jsonlDir = ROOT_DIR . "/jsonl/";
    if (!is_dir($jsonlDir) && !mkdir($jsonlDir, 0777, true) && !is_dir($jsonlDir)) {
        return ['status' => 'error', 'message' => 'Openai Failed to create jsonl directory'];
    }
    $file_name = 'batch_prompts_' . time() . '.jsonl';
    $file_path = $jsonlDir . $file_name;
    if (file_put_contents($file_path, $jsonl_content) === false) {
        return ['status' => 'error', 'message' => 'Openai Failed to save JSONL file locally.'];
    }

    try {
        $client = openai_client();
        $resp = $client->request('POST', '/v1/files', [
            'headers' => [
                'Authorization' => 'Bearer ' . OPENAI_API_KEY
            ],
            'multipart' => [
                [
                    'name'     => 'purpose',
                    'contents' => 'batch',
                ],
                [
                    'name'     => 'file',
                    'contents' => fopen($file_path, 'rb'),
                    'filename' => $file_name,
                ],
                [
                    'name'     => 'expires_after[anchor]',
                    'contents' => 'last_active_at',
                ],
                [
                    'name'     => 'expires_after[days]',
                    'contents' => '1',
                ]
            ],
            'http_errors' => false,
        ]);
        $status = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($status < 200 || $status >= 300) {
            @unlink($file_path);
            return ['status' => 'error', 'message' => "Openai Start request file upload failed: HTTP {$status} : {$body}", 'code' => $status];
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if(empty($data['id'])){
            @unlink($file_path);
            return ['status' => 'error', 'message' => "Openai No file.uri/name in upload response"];
        }
        $batchRes = openai_create_batches($data['id']);
        @unlink($file_path);
        return $batchRes;
    } catch (GuzzleException $e) {
        @unlink($file_path);
        return ['status' => 'error', 'message' => 'Openai HTTP error: ' . $e->getMessage()];
    } catch (JsonException $e) {
        @unlink($file_path);
        return ['status' => 'error', 'message' => 'Openai JSON parse error: ' . $e->getMessage()];
    } finally {
        if( file_exists( $file_path )) {
            @unlink($file_path);
        }
    }
}

function openai_create_batches($file_name): array
{
    $payload = [
        'input_file_id'     => $file_name,
        'endpoint'          => '/v1/responses',
        'completion_window' => '24h',
    ];

    $res = openai_request('POST', '/v1/batches', $payload);
    if ($res['status'] !== 'success') {
        $res['message'] = 'Openai Create Batches ' . $res['message'];
        return $res;
    }

    return ['status' => 'success', 'batch_id' => $res['id']];
}

