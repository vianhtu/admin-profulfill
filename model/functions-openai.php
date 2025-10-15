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

function openai_request(string $method, string $uri, array $options = [], bool $body = false): array
{
    try {
        $client = openai_client();
        $openai_key = getOption('openai_key', null, 0);
        if (empty($openai_key)) {
            return ['status' => 'error', 'message' => 'API key is not set'];
        }
        $client_options = mergeOptions([
            'headers' => [
                'Authorization' => 'Bearer ' . $openai_key
            ],
            'http_errors' => false,
        ], $options);

        $response = $client->request($method, $uri, $client_options);
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

    $options = [
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
                'contents' => 'created_at',
            ],
            [
                'name'     => 'expires_after[seconds]',
                'contents' => '172800',
            ]
        ]
    ];

    $request_upload_file = openai_request('POST', '/v1/files', $options);
    if ($request_upload_file['status'] !== 'success' || empty($request_upload_file['data']['id'])) {
        $request_upload_file['message'] = 'Openai Upload File Error : ' . $request_upload_file['message'] ?: 'No file ID returned';
        @unlink($file_path);
        return $request_upload_file;
    }

    $payload = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ],
        'json' => [
            'input_file_id'     => $request_upload_file['data']['id'],
            'endpoint'          => '/v1/responses',
            'completion_window' => '24h',
        ]
    ];
    $request_create_batch = openai_request('POST', '/v1/batches', $payload);
    @unlink($file_path);
    if ($request_create_batch['status'] !== 'success') {
        $request_create_batch['message'] = 'Openai Create Batches Error : ' . $request_create_batch['message'];
        return $request_create_batch;
    }

    return ['status' => 'success', 'batch_id' => $request_create_batch['data']['id']];
}

function openai_get_batches_by_name($batch_name): array{
    $res = openai_request('GET', "/v1/batches/{$batch_name}", ['headers'=> ['Accept' => 'application/json']]);
    if ($res['status'] !== 'success'){
        $res['message'] = 'Openai Get Batches Error : ' . $res['message'];
        return $res;
    }

    $data = $res['data'];
    $files_json = [];
    $error_log = [];
    if(!empty($data['output_file_id'])){
        $file = openai_request('GET', "/v1/files/{$data['output_file_id']}/content", [], true);
        if ($file['status'] !== 'success'){
            $file['message'] = 'Openai Get File Error : ' . $file['message'];
            return $file;
        }
        $file_content = $file['body'];
        $lines = preg_split("/\r\n|\n|\r/", $file_content);
        foreach ($lines as $index => $line) {
            // bỏ qua line rỗng.
            if (trim($line) === ''){
                $error_log[$index] = ['message' => 'Line is empty'];
                continue;
            }
            $json = json_decode($line, true);
            // nếu json lỗi.
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                $error_log[$index] = ['message' => json_last_error_msg() ?: 'Decoded JSON is not an array'];
                continue;
            }
            // item json null.
            $raw = $json['response']['body']['output'][1]['content'][0]['text'] ?? null;
            if ($raw === null) {
                $error_log[$index] = ['message' => 'No output json'];
                continue;
            }
            $decoded_raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_raw)) {
                $error_log[$index] = [
                    'custom_id' => $json['custom_id'] ?? null,
                    'message' => json_last_error_msg() ?: 'Decoded JSON is not an array'
                ];
                continue;
            }

            $files_json[] = [
                'id' => $json['custom_id'],
                'json' => $decoded_raw
            ];
        }
    }

    $status = $data['status'] ?? 'error';
    switch ($data['status']){
        case 'in_progress':
            $status = 'running';
            break;
        case 'completed':
            $status = 'ready';
            break;
        case 'expired':
            $status = 'expired';
            break;
        case 'failed':
            $status = 'error';
            break;
    }

    $res['data'] = [
        'status' => $status,
        'completed' => $data['request_counts']['completed'] ?? 0,
        'failed' => $data['request_counts']['failed'] ?? 0,
        'input_tokens' => $data['usage']['input_tokens'] ?? 0,
        'output_tokens' => $data['usage']['output_tokens'] ?? 0,
        'file' => $files_json,
        'error' => $error_log
    ];
    return $res;
}

