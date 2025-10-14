<?php
require 'vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Client as GuzzleClient;

function menuArgs():array
{
    return [
        'Dashboards' => [
            'icon' => 'tabler-smart-home',
            'link' => '' // để trống => mặc định dùng $currentMenu
        ],
        'eCommerce' => [
            'icon' => 'tabler-building-store',
            'sub' => [
                'products' => [
                    'label' => 'Products',
                    'roles' => ['view','add','edit','delete']
                ],
                'copyright' => [
                    'label' => 'Copyright Warning',
                    'roles' => ['view','edit']
                ],
                'keywords' => [
                    'label' => 'Keywords',
                    'roles' => ['view','add','edit','delete']
                ],
            ]
        ],
        'Platform Orders' => [
            'icon' => 'tabler-shopping-bag',
            'sub' => [
                'orders' => [
                    'label' => 'Orders',
                    'roles' => ['view','add','edit','delete']
                ],
            ]
        ],
        'Export' => [
            'icon' => 'tabler-file-type-xls',
            'sub' => [
                'exports_download' => [
                    'label' => 'Download',
                    'roles' => ['view','add','edit','delete']
                ],
                'exports_xlsx' => [
                    'label' => 'Files',
                    'roles' => ['view','add','edit','delete']
                ]
            ]
        ],
        'Phones' => [
            'icon' => 'tabler-device-mobile-message',
            'sub' => [
                'phones_numbers' => [
                    'label' => 'Numbers',
                    'roles' => ['view','add','edit','delete']
                ],
                'phones_sms' => [
                    'label' => 'SMS',
                    'roles' => ['view']
                ],
            ]
        ],
        'Teams' => ['icon' => 'tabler-brand-asana', 'link' => 'teams', 'roles' => ['view','add','edit','delete']],
        'Users' => ['icon' => 'tabler-users', 'link' => 'users', 'roles' => ['view','add','edit','delete']],
        'Roles & Permissions' => ['icon' => 'tabler-lock', 'link' => 'roles-permissions', 'roles' => ['view','add','edit','delete']]
    ];
}
function renderMenu($currentMenu): void
{
    $menuItems = menuArgs();

    foreach ($menuItems as $mainLabel => $mainData) {
        $icon = $mainData['icon'];

        if (!empty($mainData['sub'])) {
            // Có submenu
            $subMenuHtml = '';
            $isOpen = false;

            foreach ($mainData['sub'] as $key => $value) {
                $label = is_array($value) ? $value['label'] : $value;
                $target = is_array($value) && isset($value['target']) ? 'target="_blank"' : '';
                $activeClass = ($currentMenu === $key) ? 'active' : '';
                if ($activeClass) $isOpen = true;

                // Nếu có roles trong menuArgs => kiểm tra quyền
                if (isset($value['roles'])) {
                    if (!checkRoles('', $key)) {
                        continue; // Không có quyền => bỏ qua
                    }
                }
                // Nếu không có roles => ai cũng xem được

                $subMenuHtml .= "<li class='menu-item {$activeClass}'>
                    <a href='index.php?menu={$key}' class='menu-link' {$target}>
                        <div data-i18n='{$label}'>{$label}</div>
                    </a>
                </li>";
            }

            // Chỉ render menu cha nếu có ít nhất 1 submenu được phép
            if ($subMenuHtml !== '') {
                $openClass = $isOpen ? 'active open' : '';
                echo "<li class='menu-item {$openClass}'>
                    <a href='javascript:void(0);' class='menu-link menu-toggle'>
                        <i class='menu-icon icon-base ti {$icon}'></i>
                        <div data-i18n='{$mainLabel}'>{$mainLabel}</div>
                    </a>
                    <ul class='menu-sub'>{$subMenuHtml}</ul>
                </li>";
            }
        } else {
            // Không có submenu
            $link = trim($mainData['link']) === '' ? '' : $mainData['link'];
            $activeClass = ($currentMenu === $link) ? 'active' : '';
            $href = $link === '' ? 'index.php' : "index.php?menu={$link}";

            // Nếu có roles => kiểm tra quyền
            if (isset($mainData['roles'])) {
                if (!checkRoles('', $link)) {
                    continue;
                }
            }
            // Nếu không có roles => ai cũng xem được

            echo "<li class='menu-item {$activeClass}'>
                <a href='{$href}' class='menu-link'>
                    <i class='menu-icon icon-base ti {$icon}'></i>
                    <div data-i18n='{$mainLabel}'>{$mainLabel}</div>
                </a>
            </li>";
        }
    }
}

function renderSelect($id, $label, $options, $selected = null): void
{
	echo "<label class='form-label mb-1' for='{$id}'>{$label}</label>";
	echo "<select id='{$id}' name='{$id}' class='select2 form-select'>";
    echo "<option value=''>Select a {$label}</option>";
	foreach ($options as $key => $value) {
		$isSelected = ($selected === $key) ? "selected" : "";
		echo "<option value='{$key}' {$isSelected}>{$value['title']}</option>";
	}
	echo "</select>";
}

function checkRoles(string|array $role = '', string $menu = ''): bool
{
    // Nếu là admin thì luôn true
    if (isAdmin()) {
        return true;
    }

    // Nếu không truyền $menu thì lấy từ GET
    if ($menu === '') {
        $menu = $_GET['menu'] ?? '';
    }

    if ($menu === '') {
        return false; // Không xác định được menu
    }

    // Lấy danh sách quyền của user
    $userRoles = $_SESSION['auth']['roles'] ?? [];

    // Nếu không truyền role => mặc định kiểm tra view
    if ($role === '' || $role === []) {
        // Có view thì true
        if (!empty($userRoles[$menu]['view'])) {
            return true;
        }
        // Không có view nhưng có add/edit/delete thì cũng coi như view
        foreach (['add', 'edit', 'delete'] as $r) {
            if (!empty($userRoles[$menu][$r])) {
                return true;
            }
        }
        return false;
    }

    // Nếu $role là mảng
    if (is_array($role)) {
        foreach ($role as $r) {
            // Nếu đang check 'view' thì áp dụng logic đặc biệt
            if ($r === 'view') {
                if (!empty($userRoles[$menu]['view'])) {
                    return true;
                }
                foreach (['add', 'edit', 'delete'] as $extra) {
                    if (!empty($userRoles[$menu][$extra])) {
                        return true;
                    }
                }
            } else {
                if (!empty($userRoles[$menu][$r])) {
                    return true;
                }
            }
        }
        return false;
    }

    // Nếu $role là string
    if ($role === 'view') {
        if (!empty($userRoles[$menu]['view'])) {
            return true;
        }
        foreach (['add', 'edit', 'delete'] as $r) {
            if (!empty($userRoles[$menu][$r])) {
                return true;
            }
        }
        return false;
    }

    return !empty($userRoles[$menu][$role]);
}

function gemini_client(): GuzzleClient
{
    return new GuzzleClient([
        'base_uri' => 'https://generativelanguage.googleapis.com',
        'timeout' => 120,
    ]);
}

function gemini_request(string $method, string $uri, array $json = [], bool $body = false): array
{
    try {
        $client = gemini_client();
        $options = [
            'headers' => [
                'x-goog-api-key' => GEMINI_API_KEY
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

function gemini_start_resumable_upload(string $file_name, int $file_size, string $file_type): array
{
    try {
        $client = gemini_client();
        $resp = $client->request('POST', '/upload/v1beta/files', [
            'headers' => [
                'x-goog-api-key' => GEMINI_API_KEY,
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string)$file_size,
                'X-Goog-Upload-Header-Content-Type' => $file_type,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['file' => ['display_name' => $file_name]]),
            'http_errors' => false,
        ]);
        $status = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($status < 200 || $status >= 300) {
            return ['status' => 'error', 'message' => "Start request failed: HTTP {$status} : {$body}", 'code' => $status];
        }
        $uploadUrl = null;
        foreach ($resp->getHeaders() as $name => $values) {
            if (strtolower($name) === 'x-goog-upload-url') {
                $uploadUrl = $values[0];
                break;
            }
        }
        if (!$uploadUrl) {
            return ['status' => 'error', 'message' => 'Upload URL not found in response headers'];
        }
        return ['status' => 'success', 'uploadUrl' => $uploadUrl];
    } catch (GuzzleException $e) {
        return ['status' => 'error', 'message' => 'HTTP error: ' . $e->getMessage()];
    }
}

function gemini_upload_finalize(string $uploadUrl, string $file_path, int $file_size): array
{
    try {
        $client = gemini_client();
        $resp = $client->request('POST', $uploadUrl, [
            'headers' => [
                'Content-Length' => (string)$file_size,
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ],
            'body' => fopen($file_path, 'rb'),
            'http_errors' => false,
            'verify' => true,
        ]);
        $status = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($status < 200 || $status >= 300) {
            return ['status' => 'error', 'message' => "Upload failed: HTTP {$status} : {$body}", 'code' => $status];
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return ['status' => 'success', 'data' => $data];
    } catch (GuzzleException $e) {
        return ['status' => 'error', 'message' => 'HTTP error: ' . $e->getMessage()];
    } catch (JsonException $e) {
        return ['status' => 'error', 'message' => 'JSON parse error: ' . $e->getMessage()];
    }
}

function gemini_create_and_upload_batch_file(string $jsonl_content, $downloadId, $model): array
{
    $jsonlDir = ROOT_DIR . "/jsonl/";
    if (!is_dir($jsonlDir) && !mkdir($jsonlDir, 0777, true) && !is_dir($jsonlDir)) {
        return ['status' => 'error', 'message' => 'Failed to create jsonl directory'];
    }
    $file_name = 'batch_prompts_' . time() . '.jsonl';
    $file_path = $jsonlDir . $file_name;
    if (file_put_contents($file_path, $jsonl_content) === false) {
        return ['status' => 'error', 'message' => 'Failed to save JSONL file locally.'];
    }
    $file_type = mime_content_type($file_path) ?: 'application/jsonl';
    $file_size = filesize($file_path);
    $start = gemini_start_resumable_upload($file_name, $file_size, $file_type);
    if ($start['status'] !== 'success') {
        @unlink($file_path);
        return $start;
    }
    $uploadUrl = $start['uploadUrl'];
    $upload = gemini_upload_finalize($uploadUrl, $file_path, $file_size);
    if ($upload['status'] !== 'success') {
        @unlink($file_path);
        return $upload;
    }
    $decoded = $upload['data'];
    if (!isset($decoded['file']['uri']) && !isset($decoded['file']['name'])) {
        @unlink($file_path);
        return ['status' => 'error', 'message' => "No file.uri/name in response"];
    }
    $fileUriRaw = $decoded['file']['uri'] ?? $decoded['file']['name'];
    if (!preg_match('#files/[^/]+$#', $fileUriRaw, $matches)) {
        @unlink($file_path);
        return ['status' => 'error', 'message' => "No file id in url: {$fileUriRaw}"];
    }
    $fileId = $matches[0];
    $batchRes = gemini_create_batches($downloadId, $fileId, $model);
    @unlink($file_path);
    return $batchRes;
}

function gemini_create_cache($downloadId): array
{
    $promptCache = getAISitePrompt($downloadId);
    if (empty($promptCache)) {
        return ['status' => 'error', 'message' => "Prompt is null: {$downloadId}"];
    }

    $payload = [
        'model' => 'models/gemini-2.5-flash',
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => buildCompressedPromptFromText($promptCache)]
                ]
            ]
        ],
        'ttl' => '48h',
        'displayName' => 'cache-' . $downloadId
    ];

    $res = gemini_request('POST', '/v1beta/cachedContents', $payload);
    if ($res['status'] !== 'success') {
        return $res;
    }

    $data = $res['data'];
    if (!empty($data['name'])) {
        return ['status' => 'success', 'id' => $data['name']];
    }

    return ['status' => 'error', 'message' => 'Cache created but ID not found in response.'];
}

function gemini_create_batches($downloadId, $file_name, $model): array
{
    $payload = [
        'batch' => [
            'display_name' => (string)$downloadId,
            'input_config' => [
                'file_name' => $file_name,
            ],
        ],
    ];

    $res = gemini_request('POST', "/v1beta/models/{$model}:batchGenerateContent", $payload);
    if ($res['status'] !== 'success') {
        return $res;
    }

    return ['status' => 'success', 'batch_id' => $res['batch']['name']];
}

function gemini_get_batches_by_name($batch_name): array
{
    $res = gemini_request('GET', "/v1beta/{$batch_name}");
    if ($res['status'] !== 'success'){
        return $res;
    }

    $data = $res['data'];
    $state = $data['metadata']['state'] ?? null;
    if ($state === 'BATCH_STATE_EXPIRED') {
        return ['status' => 'expired', 'file_name' => $data['metadata']['inputConfig']['fileName'] ?? null];
    }
    if ($state !== 'BATCH_STATE_SUCCEEDED') {
        return ['status' => 'running', 'message' => 'Batch not completed yet'];
    }

    $responsesFile = $data['response']['responsesFile'] ?? null;
    if (empty($responsesFile)) {
        return ['status' => 'error', 'message' => 'responsesFile not found in batch response'];
    }

    $resp = gemini_request('GET', "/download/v1beta/{$responsesFile}:download?alt=media", [], true);
    if ($resp['status'] !== 'success'){
        return $resp;
    }
    $body = $resp['body'];
    $lines = preg_split("/\r\n|\n|\r/", $body);
    $items = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $json = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Làm sạch và parse JSON
            $raw = $json['response']['candidates'][0]['content']['parts'][0]['text'];
            $clean = preg_replace('/^```json\s*|\s*```$/', '', trim((string)$raw));
            $item_json = json_decode($clean, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($item_json)) {
                continue;
            }
            $items[$json['key']] = [
                'data' => $item_json,
                'total_token' => (int)$json['response']['usageMetadata']['totalTokenCount'],
            ];
        }
    }
    return ['status' => 'success', 'items' => $items];
}

function buildCompressedPromptFromText(string $fullText): string {
    $promptOneLine = preg_replace('/\s+/', ' ', $fullText);
    return trim($promptOneLine);
}

function AIProcessDownloadProducts($downloadId, $model, $ai): array
{
    $conn = db();
    $promptTemplate = AIGetPrompt($downloadId);

    $stmt = $conn->prepare("
        SELECT DISTINCT p.ID, p.title, p.sku, p.images
        FROM posts p
        INNER JOIN download_relationships dr ON dr.post_id = p.ID
        WHERE dr.download_id = ?
        AND p.status = 'schedule'
    ");
    $stmt->bind_param("i", $downloadId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [['status' => 'error', 'message' => 'Execute failed: ' . $stmt->error]];
    }
    $result = $stmt->get_result();

    // Nếu không có sản phẩm nào → cập nhật download.status = 'error'
    if ($result->num_rows === 0) {
        $updateDownload = $conn->prepare("UPDATE download SET status = 'error' WHERE ID = ?");
        $updateDownload->bind_param("i", $downloadId);
        $updateDownload->execute();
        $updateDownload->close();
        $stmt->close();
        return [['status' => 'error', 'message' => "Không có sản phẩm để xử lý. Đã cập nhật download ID {$downloadId} thành 'error'."]];
    }

    $jsonl_content = '';
    while ($row = $result->fetch_assoc()) {
        $title = $row['title'] ?? '';
        $mainImage = '';
        if (!empty($row['images'])) {
            $imagesArray = json_decode($row['images'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($imagesArray)) {
                $mainImage = $imagesArray['main'] ?? '';
                if (!empty($mainImage)) {
                    $mainImage = preg_replace('/il_\d+xN/', 'il_1024xN', $mainImage);
                }
            }
        }

        // Tạo prompt
        $prompt = strtr($promptTemplate, [
            '{title}' => $title,
            '{image}' => $mainImage
        ]);

        $request_data = AIJsonlContent($row['ID'], $prompt, $ai, $model);
        if(empty($request_data)) {
            continue;
        }

        $jsonl_content .= json_encode($request_data, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $stmt->close();

    // 2. Tạo File JSONL and upload.
    $batch_result = AIBatchApi($jsonl_content, $downloadId, $ai, $model);
    if ($batch_result['status'] == 'error') {
        return $batch_result;
    }
    $batch_id = $batch_result['batch_id'];
    // update. job name to data.
    $stmt = $conn->prepare("UPDATE download SET batch_name = ?, ai_name = ?, status = 'running' WHERE ID = ?");
    $stmt->bind_param("ssi", $batch_id, $ai, $downloadId);
    $stmt->execute();
    $stmt->close();

    return [['status' => 'success', 'name' => $batch_id]];
}

function AIGetPrompt($downloadId)
{
    $conn = db();
    $stmt = $conn->prepare("
        SELECT DISTINCT site.prompt
        FROM site
        INNER JOIN exports ON exports.site_id = site.ID
        INNER JOIN download ON download.exports_id = exports.ID
        WHERE download.ID = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $downloadId);
    $stmt->execute();
    $stmt->bind_result($prompt);

    if ($stmt->fetch()) {
        return $prompt;
    } else {
        return '';
    }
}

function AIJsonlContent($id, $prompt, $ai, $model = ''): array
{
    $prompt = buildCompressedPromptFromText($prompt);
    return match ($ai) {
        'google' => [
            "key" => (string)$id,
            "request" => [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => $prompt
                            ]
                        ],
                        "role" => "user"
                    ]
                ]
            ]
        ],
        'openai' => [
            "custom_id" => (string)$id,
            "method" => "POST",
            "url" => "/v1/responses",
            "body" => [
                "model" => $model,
                "input" => [
                    [
                        "role" => "user",
                        "content" => $prompt
                    ]
                ]
            ]
        ],
        default => [],
    };
}

function AIBatchApi($jsonl_content, $downloadId, $ai, $model): array
{
    return match ($ai) {
        'google' => gemini_create_and_upload_batch_file($jsonl_content, $downloadId, $model),
        'openai' => openai_create_and_upload_batch_file($jsonl_content),
        default => ['status'=> 'error', 'message' => 'Dịch vụ AI không được hỗ trợ.'],
    };
}

function getOption($key, $team = null, $authors = null, $default = null)
{
    $team = $team ?? ($_SESSION['auth']['team'] ?? 0);
    $authors = $authors ?? ($_SESSION['auth']['user_id'] ?? 0);
    $conn = db();
    $stmt = $conn->prepare("SELECT value FROM options WHERE name = ? AND team_id = ? AND authors_id = ? LIMIT 1");
    $stmt->bind_param("sii", $key, $team, $authors);
    $stmt->execute();
    $stmt->bind_result($value);
    if ($stmt->fetch()) {
        return $value;
    } else {
        return $default;
    }
}

function actionDownloadTableModel(): array
{
    $conn = db();
    if(!checkRoles(['add','delete'], 'exports_download')){
        return ['status'  => 'error', 'message' => 'Bạn Không có quyền chạy lệnh.'];
    }

    $idsInput = $_POST['ids'] ?? null;
    $model = $_POST['model'] ?? null;
    $ai = $_POST['ai'] ?? null;
    if (empty($idsInput) || empty($model) || empty($ai) ) {
        return ['status'  => 'error', 'message' => 'Không có ids hoặc modal được gửi lên.'];
    }

    $idsFiltered = array_map('intval', $idsInput);
    if (count($idsFiltered) === 0) {
        return ['status' => 'error', 'message' => 'Không có ids hợp lệ.'];
    }

    $idList = implode(',', $idsFiltered);
    $sql = "SELECT ID FROM download WHERE ID IN ($idList) AND status = 'schedule' OR status = 'error'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $downloadId = $row['ID'];
        $ids[$row['ID']] = AIProcessDownloadProducts($downloadId, $model, $ai);
    }

    return ['status' => 'success', 'ids' => $ids];
}

function getDataTableParams(array $allowedCols, string $defaultCol = 'ID'): array {
    $draw             = intval($_POST['draw'] ?? 1);
    $start            = intval($_POST['start'] ?? 0);
    $length           = intval($_POST['length'] ?? 10);
    $orderColumnIndex = intval($_POST['order'][0]['column'] ?? 0);
    $orderColumn      = $_POST['columns'][$orderColumnIndex]['data'] ?? $defaultCol;
    $orderDir         = strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $searchValue      = trim($_POST['search']['value'] ?? '');

    // Chỉ cho phép sort theo cột hợp lệ
    if (!in_array($orderColumn, $allowedCols)) {
        $orderColumn = $defaultCol;
    }

    return [
        'draw'        => $draw,
        'start'       => $start,
        'length'      => $length,
        'orderColumn' => $orderColumn,
        'orderDir'    => $orderDir,
        'searchValue' => $searchValue
    ];
}

function getAllTypes(): array {
    return getAllData('type', 'name');
}

function getAllAuthors(): array {
    return getAllData('authors', 'username');
}

function getAllSites(): array {
    return getAllData('site', 'name');
}

function getAllRoles(): array {
    $roles = [0 => ['title' => 'Admin']];
    return $roles + getAllData('roles_permissions', 'name');
}

function getAllTeams(): array {
    return getAllData('team', 'name');
}

function getAllData(string $table, string $field): array {
    $conn = db();
    $stmt = $conn->query("SELECT ID, {$field} FROM {$table}");
    $data = [];
    while ($row = $stmt->fetch_assoc()) {
        $data[$row['ID']] = [
            'title' => $row[$field]
        ];
    }
    $stmt->close();
    return $data;
}


function getAuthorsProductInfo(): ?array {
	$sql = "SELECT
    -- Tổng số bài viết
    COUNT(*) AS total_items,
    -- Tổng số bài viết đang chờ duyệt
    COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_items,
    -- Tổng số bài viết của tác giả hiện tại
    COUNT(CASE WHEN author_id = ? THEN 1 END) AS author_items,
    -- Tổng số bài viết trong tháng hiện tại
    COUNT(CASE WHEN MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS total_this_month,
    -- Tổng số bài viết đang chờ duyệt trong tháng hiện tại
    COUNT(CASE WHEN status = 'pending' AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS pending_this_month,
    -- Tổng số bài viết của tác giả hiện tại trong tháng hiện tại
    COUNT(CASE WHEN author_id = ? AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE()) THEN 1 END) AS author_this_month
    FROM posts";
	$stmt = db()->prepare($sql);
	$stmt->bind_param('ii', $_SESSION['auth']['user_id'],$_SESSION['auth']['user_id']);
	$stmt->execute();
	$result = $stmt->get_result();
	$data = $result->fetch_assoc();
	$stmt->close();
	return $data;
}

function getMissingOrders(): array
{
    // Kết nối DB
    $conn = db();

    // 1. Kiểm tra key trong bảng options
    $key = $_GET['key'] ?? '';
    if (!empty($key)) {
        $res = $conn->query("SELECT value FROM options WHERE name = 'sys_orders' LIMIT 1");
        $row = $res->fetch_assoc();
        if (!$row || $key !== $row['value']) {
            return ['error' => 'Invalid key'];
        }
    } else {
        return ['error' => 'Api key not found in options'];
    }

    // Đọc dữ liệu JSON từ body request
    $input = json_decode(file_get_contents('php://input'), true);
    $orderIds = $input['orderIds'] ?? [];
    if (empty($orderIds)) {
        return ['missingOrders' => []];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "SELECT host_id FROM orders WHERE host_id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($orderIds));
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $existingHostIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingHostIds[] = $row['host_id'];
    }

    // Tìm đơn chưa có trong DB
    $missing = array_values(array_diff($orderIds, $existingHostIds));

    return ['missingOrders' => $missing];
}

function getStoresTableFilter(): array {
	$conn = db();
	// Lấy giá trị tìm kiếm từ POST
	$q    = isset($_POST['q']) ? trim($_POST['q']) : '';
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$perPage = 20;
	$offset  = ($page - 1) * $perPage;

// Chuẩn bị câu truy vấn (Prepared Statement để chống SQL injection)
	$sql = "SELECT t.id, CONCAT(s.name, ' (', t.name, ')') AS name
        FROM store AS t
        JOIN site s ON t.site_id = s.id
        WHERE (? = '' OR t.name LIKE ?)
        ORDER BY t.name ASC
        LIMIT ?, ?";
	$stmt = $conn->prepare($sql);

	$like = "%{$q}%";
	$stmt->bind_param("ssii", $q, $like, $offset, $perPage);
	$stmt->execute();

	$result = $stmt->get_result();
	$items = [];
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();

	// Kiểm tra còn dữ liệu trang tiếp theo hay không
	$more = (count($items) === $perPage);
	return [
		'items' => $items,
		'more'  => $more
	];
}

function getAccountsByID($id): array {
	$conn = db();
	$check = $conn->prepare( "SELECT a.id, CONCAT(s.name, ' (', a.name, ')') AS name
		FROM accounts AS a
		JOIN site s ON a.site_id = s.id
		WHERE a.id = ?" );
	$check->bind_param( "i", $id );
	$check->execute();
	$result = $check->get_result();
	$types = [];
	if ( $result->num_rows > 0 ) {
		$row = $result->fetch_assoc();
		$types[$row['id']] = [
			'title' => $row['name']
		];
	}
	return $types;
}

function getAccountsTableFilter(): array {
	$conn = db();
	// Lấy giá trị tìm kiếm từ POST
	$q    = isset($_POST['q']) ? trim($_POST['q']) : '';
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$perPage = 20;
	$offset  = ($page - 1) * $perPage;

// Chuẩn bị câu truy vấn (Prepared Statement để chống SQL injection)
	$sql = "SELECT a.id, CONCAT(s.name, ' (', a.name, ')') AS name
        FROM accounts AS a
        JOIN site s ON a.site_id = s.id
        WHERE (? = '' OR a.name LIKE ? OR a.email LIKE ?)
        ORDER BY a.site_id ASC
        LIMIT ?, ?";
	$stmt = $conn->prepare($sql);

	$like = "%{$q}%";
	$stmt->bind_param("sssii", $q, $like, $like, $offset, $perPage);
	$stmt->execute();

	$result = $stmt->get_result();
	$items = [];
	while ($row = $result->fetch_assoc()) {
		$items[] = $row;
	}
	$stmt->close();

	// Kiểm tra còn dữ liệu trang tiếp theo hay không
	$more = (count($items) === $perPage);
	return [
		'items' => $items,
		'more'  => $more
	];
}

function getProductsTable(): array {
    $allowedCols = ['ID', 'title', 'status', 'date', 'type_id', 'author_id', 'badge'];
    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'products')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM posts")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "(posts.title LIKE '%$searchEsc%' OR posts.sku LIKE '%$searchEsc%' OR posts.badge LIKE '%$searchEsc%')";
    }

    // Lọc theo status (string)
    addTableFilter($whereClauses, 'posts.status', 8, 'string', $conn);

    // Lọc theo type (int)
    addTableFilter($whereClauses, 'posts.type_id', 4, 'int', $conn);

    // Lọc theo author (int)
    addTableFilter($whereClauses, 'posts.author_id', 5, 'int', $conn);

    // Lọc theo sites
    $filterSites = $_POST['sites'] ?? [];
    if (!empty($filterSites) && is_array($filterSites)) {
        $idsStr = implode(',', array_map('intval', $filterSites));
        if ($idsStr !== '') {
            $whereClauses[] = "posts.site_id IN ($idsStr)";
        }
    }

    // Lọc theo khoảng ngày
    $minDate = $_POST['minDate'] ?? '';
    $maxDate = $_POST['maxDate'] ?? '';
    if ($minDate !== '' && $maxDate !== '') {
        $escMin = $conn->real_escape_string($minDate);
        $escMax = $conn->real_escape_string($maxDate);
        $whereClauses[] = "DATE(`posts.date`) BETWEEN '$escMin' AND '$escMax'";
    } elseif ($minDate !== '') {
        $escMin = $conn->real_escape_string($minDate);
        $whereClauses[] = "DATE(`posts.date`) >= '$escMin'";
    } elseif ($maxDate !== '') {
        $escMax = $conn->real_escape_string($maxDate);
        $whereClauses[] = "DATE(`posts.date`) <= '$escMax'";
    }

    // Lọc theo stores
    $filterStores = $_POST['stores'] ?? [];
    if (!empty($filterStores) && is_array($filterStores)) {
        $idsStr = implode(',', array_map('intval', $filterStores));
        if ($idsStr !== '') {
            $whereClauses[] = "posts.store_id IN ($idsStr)";
        }
    }

    // Lọc theo accounts
    $joinAccounts = '';
    $filterAccounts = $_POST['accounts'] ?? [];
    if (!empty($filterAccounts) && is_array($filterAccounts)) {
        $idsStr = implode(',', array_map('intval', $filterAccounts));
        if ($idsStr !== '') {
            $joinAccounts = "INNER JOIN accounts_relationships ar ON ar.post_id = posts.ID";
            $whereClauses[] = "ar.account_id IN ($idsStr)";
        }
    }

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query("SELECT COUNT(posts.ID) AS cnt FROM posts $joinAccounts $where")->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT posts.ID, posts.title, posts.status, posts.sku, posts.images, posts.badge, posts.date, posts.type_id, posts.author_id
            FROM posts
            $joinAccounts
            $where
            ORDER BY posts.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $imgs = json_decode($row['images']);
        $updatedUrl = '';
        if ($imgs && isset($imgs->main)) {
            $updatedUrl = preg_replace('/il_\d+xN/', 'il_100xN', $imgs->main);
        }
        $data[] = [
            "id"            => $row['ID'],
            "title"         => $row['title'],
            "sku"           => $row['sku'],
            "type_id"       => $row['type_id'],
            "author_id"     => $row['author_id'],
            "badge"         => $row['badge'],
            "date"          => $row['date'],
            "status"        => $row['status'],
            "image"         => $updatedUrl,
            "product_brand" => "Etsy"
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getProductsTableFilters(): array {
    $options = [];
    $options['types'] = getAllTypes();
    $options['authors'] = getAllAuthors();
    $options['sites'] = getAllSites();
    return $options;
}

function getProductCopyrightWarning(): array
{
    $conn = db();

    $where = "
        TRIM(a.copyright_warning) <> ''
        AND TRIM(a.copyrighted_content) <> ''
        AND LOWER(a.copyright_warning) NOT IN ('none','no','n/a','false','not applicable')
        AND LOWER(a.copyrighted_content) NOT IN ('none','no','n/a','false','not applicable')
    ";

    // Lấy page và limit từ request
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 6;
    $page  = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    // Lấy danh sách items
    $sql = "
        SELECT a.copyright_warning, a.copyrighted_content, p.title, p.images, p.sku, p.ID
        FROM amazon_listings AS a
        INNER JOIN posts AS p ON p.ID = a.post_id
        WHERE $where
        ORDER BY a.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $data = [];
    foreach ($conn->query($sql) as $row) {
        $imgs = json_decode($row['images']);
        $mainImg = !empty($imgs->main) ? preg_replace('/il_\d+xN/', 'il_500xN', $imgs->main) : '';
        $data[] = [
            "id" => $row['ID'],
            "sku" => $row['sku'],
            "title" => $row['title'],
            "img"  => $mainImg,
            "copyrighted_content" => $row['copyrighted_content'],
            "copyright_warning" => $row['copyright_warning'],
        ];
    }

    // Đếm tổng số
    $total = $conn->query("
        SELECT COUNT(*) AS total
        FROM amazon_listings AS a
        INNER JOIN posts AS p ON p.ID = a.post_id
        WHERE $where
    ")->fetch_assoc()['total'] ?? 0;

    return [
        'total' => (int)$total,
        'items' => $data,
        'page'  => $page,
        'limit' => $limit
    ];
}

function getKeywordsTable(): array
{
    $allowedCols = ['ID', 'name', 'status'];
    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'keywords')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(ID) AS cnt FROM keywords")->fetch_assoc()['cnt'];

    // Điều kiện lọc
    $whereClauses = [];
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "(name LIKE '%$searchEsc%')";
    }
    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query("SELECT COUNT(ID) AS cnt FROM keywords $where")->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT *
            FROM keywords
            $where
            ORDER BY {$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"        => $row['ID'],
            "name"      => $row['name'],
            "status"    => $row['status'],
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getOrdersTable(): array {
    $allowedCols = ['ID', 'status', 'purchase_date', 'delivery_date', 'ship_date'];
    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'orders')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(ID) AS cnt FROM orders")->fetch_assoc()['cnt'];

    // Điều kiện lọc
    $whereClauses = [];
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "(orders.host_id LIKE '%$searchEsc%' 
            OR orders.full_name LIKE '%$searchEsc%' 
            OR orders.phone LIKE '%$searchEsc%' 
            OR orders.items LIKE '%$searchEsc%')";
    }
    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query("SELECT COUNT(ID) AS cnt FROM orders $where")->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT orders.*, accounts.site_id, accounts.name AS account_name, accounts.email AS account_email
            FROM orders
            INNER JOIN accounts ON accounts.ID = orders.account_id
            $where
            ORDER BY orders.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"               => $row['ID'],
            "host_id"          => $row['host_id'],
            "purchase_date"    => $row['purchase_date'],
            "delivery_date"    => $row['delivery_date'],
            "ship_date"        => $row['ship_date'],
            "full_name"        => $row['full_name'],
            "phone"            => $row['phone'],
            "street_address_1" => $row['street_address_1'],
            "street_address_2" => $row['street_address_2'],
            "city"             => $row['city'],
            "state"            => $row['state'],
            "zip_code"         => $row['zip_code'],
            "country"          => $row['country'],
            "total_price"      => $row['total_price'],
            "items"            => $row['items'],
            "status"           => $row['status'],
            "site_id"          => $row['site_id'],
            "account_name"     => $row['account_name'],
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getDownloadTable(): array {
    $allowedCols = ['ID', 'status', 'date', 'download_date', 'total_items'];
    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'exports_download')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM download")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "(exports.file_name LIKE '%$searchEsc%' 
            OR accounts.email LIKE '%$searchEsc%' 
            OR accounts.name LIKE '%$searchEsc%')";
    }
    // Lọc theo status (string)
    addTableFilter($whereClauses, 'download.status', 4, 'string', $conn);

    // Lọc theo type (int)
    addTableFilter($whereClauses, 'exports.type_id', 10, 'int', $conn);

    // Lọc theo role (int)
    addTableFilter($whereClauses, 'exports.site_id', 3, 'int', $conn);

    // Lọc theo team name (int)
    addTableFilter($whereClauses, 'download.author_id', 9, 'int', $conn);

    // Lọc theo accounts
    $filterAccounts = $_POST['accounts'] ?? [];
    if (!empty($filterAccounts) && is_array($filterAccounts)) {
        $idsStr = implode(',', array_map('intval', $filterAccounts));
        if ($idsStr !== '') {
            $whereClauses[] = "exports.accounts_id IN ($idsStr)";
        }
    }

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';
    $join  = 'INNER JOIN exports ON exports.ID = download.exports_id 
              INNER JOIN accounts ON accounts.ID = exports.accounts_id';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query(
        "SELECT COUNT(DISTINCT download.ID) AS cnt 
         FROM download $join $where"
    )->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT download.ID, download.author_id, exports.site_id, exports.type_id, exports.file_name, accounts.name, accounts.site_id AS account_site_id,
                   download.status, download.date, download.download_date, download.total_items, download.failed_items, download.completed_items,
                   download.total_token, download.ai_name
            FROM download
            $join
            $where
            ORDER BY download.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"                => $row['ID'],
            "account_site_id"   => $row['account_site_id'],
            "name"              => $row['name'],
            "site_id"           => $row['site_id'],
            "type_id"           => $row['type_id'],
            "author_id"         => $row['author_id'],
            "status"            => $row['status'],
            "date"              => $row['date'],
            "download_date"     => $row['download_date'],
            "total_items"       => $row['total_items'],
            "failed_items"      => $row['failed_items'],
            "completed_items"   => $row['completed_items'],
            "total_token"       => $row['total_token'],
            "temp_file_name"    => $row['file_name'],
            "ai_name"           => $row['ai_name'],
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getDownloadProductsProcess(): array
{
    if(!checkRoles('view', 'exports_download')){
        return [];
    }
    $conn = db();

    // Lấy danh sách ID từ request (form-data hoặc JSON đều hỗ trợ)
    $ids = [];
    if ($_SERVER['CONTENT_TYPE'] === 'application/json') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
    } else {
        $ids = $_POST['ids'] ?? [];
    }

    if (empty($ids) || !is_array($ids)) {
        return [];
    }

    // Tạo placeholder cho câu lệnh IN
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
    SELECT ID, total_items, completed_items, status
    FROM download
    WHERE ID IN ($placeholders)";

    $stmt = $conn->prepare($sql);

    // Tạo chuỗi kiểu dữ liệu, tất cả là integer
    $types = str_repeat('i', count($ids));

    // bind_param cần tham chiếu, nên dùng call_user_func_array
    $stmt->bind_param($types, ...$ids);

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $total   = (int)$row['total_items'];
        $completed_items = (int)$row['completed_items'];

        // Tính % hoàn thành
        $progress = $total > 0 ? round(($completed_items / $total) * 100) : 0;

        $data[] = [
            'id'       => (int)$row['ID'],
            'progress' => $progress,
            'completed'=> $completed_items,
            'total'    => $total,
            'status'   => $row['status']
        ];
    }

    return $data;
}

function getFilesTable(): array {
    $allowedCols = ['ID', 'file_name', 'date_create', 'type_id', 'site_id', 'authors_id'];

    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'exports_xlsx')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM exports")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "exports.file_name LIKE '%$searchEsc%'";
    }

    // Lọc theo type (int)
    addTableFilter($whereClauses, 'exports.type_id', 3, 'int', $conn);

    // Lọc theo role (int)
    addTableFilter($whereClauses, 'exports.site_id', 4, 'int', $conn);

    // Lọc theo team name (int)
    addTableFilter($whereClauses, 'exports.authors_id', 5, 'int', $conn);

    // Lọc theo accounts
    $filterAccounts = $_POST['accounts'] ?? [];
    if (!empty($filterAccounts) && is_array($filterAccounts)) {
        $idsStr = implode(',', array_map('intval', $filterAccounts));
        if ($idsStr !== '') {
            $whereClauses[] = "exports.accounts_id IN ($idsStr)";
        }
    }

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query(
        "SELECT COUNT(ID) AS cnt FROM exports $where"
    )->fetch_assoc()['cnt'];

    $join  = 'INNER JOIN accounts ON accounts.ID = exports.accounts_id';

    // Lấy dữ liệu
    $sql = "SELECT DISTINCT exports.ID, 
                   accounts.site_id AS account_site_id, 
                   accounts.name AS account_name, 
                   exports.type_id, 
                   exports.site_id, 
                   exports.authors_id, 
                   exports.file_name, 
                   exports.date_create
            FROM exports
            $join
            $where
            ORDER BY exports.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"              => $row['ID'],
            "file_name"       => $row['file_name'],
            "type_id"         => $row['type_id'],
            "site_id"         => $row['site_id'],
            "authors_id"      => $row['authors_id'],
            "date_create"     => $row['date_create'],
            "account_site_id" => $row['account_site_id'],
            "account_name"    => $row['account_name'],
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getFilesTableFilter(): array
{
    if(!checkRoles('view', 'exports_xlsx')){
        return [];
    }
    $conn = db();
    $id   = isset($_POST['id']) ? $_POST['id'] : '';
    $type = isset($_POST['type']) ? $_POST['type'] : '';

    // Xây dựng câu truy vấn động
    $sql = "SELECT a.id, CONCAT(t.name, ' (', a.file_name, ')') AS name
            FROM exports AS a
            JOIN type t ON a.type_id = t.id
            WHERE a.accounts_id = ?";

    // Nếu có type, thêm điều kiện vào truy vấn
    if ($type !== '') {
        $sql .= " AND a.type_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id, $type); // cả id và type đều là số nguyên
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[$row['id']] = $row['name'];
    }

    $stmt->close();
    return $items;
}

function getAuthorsTable():array
{
    $allowedCols = ['ID', 'team_id', 'level', 'wage', 'username', 'status', 'date'];

    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'users')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(*) AS cnt FROM authors")->fetch_assoc()['cnt'];

    $whereClauses = [];

    // Lọc theo search
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "(email LIKE '%$searchEsc%' OR username LIKE '%$searchEsc%')";
    }

    // Lọc theo status (int)
    addTableFilter($whereClauses, 'authors.status', 6, 'int', $conn);

    // Lọc theo role (int)
    addTableFilter($whereClauses, 'authors.level', 3, 'int', $conn);

    // Lọc theo team name (int)
    addTableFilter($whereClauses, 'authors.team_id', 4, 'int', $conn);

    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query(
        "SELECT COUNT(ID) AS cnt FROM authors $where"
    )->fetch_assoc()['cnt'];

    $join  = 'LEFT JOIN roles_permissions ON roles_permissions.ID = authors.level
              LEFT JOIN team ON team.ID = authors.team_id';

    // Lấy dữ liệu
    $sql = "SELECT authors.ID, team.name AS team_name, authors.email, authors.status, authors.username, roles_permissions.name AS roles_name, authors.wage, authors.insurance, authors.date
            FROM authors
            $join
            $where
            ORDER BY authors.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    // Chuẩn bị dữ liệu trả về
    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"          => $row['ID'],
            "team_id"     => $row['team_name'],
            "email"       => $row['email'],
            "status"      => $row['status'],
            "username"    => $row['username'],
            "level"       => $row['roles_name'],
            "wage"        => formatCurrencyVND($row['wage']),
            "insurance"   => formatCurrencyVND($row['insurance']),
            "date"        => $row['date'],
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getAuthorsTableFilters(): array {
    if(!checkRoles('view', 'users')){
        return [];
    }
    $options = [];
    $options['role'] = getAllRoles();
    $options['team'] = getAllTeams();
    return $options;
}

function getRolesPermissionsTable(): array {
    $allowedCols = ['ID', 'name'];

    // Lấy tham số từ DataTables
    $params = getDataTableParams($allowedCols);
    if(!checkRoles('view', 'roles-permissions')){
        return [
            "draw"            => $params['draw'],
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ];
    }

    $conn = db();

    // Tổng số bản ghi
    $totalRecords = $conn->query("SELECT COUNT(ID) AS cnt FROM roles_permissions")->fetch_assoc()['cnt'];

    // Điều kiện lọc
    $whereClauses = [];
    if ($params['searchValue'] !== '') {
        $searchEsc = $conn->real_escape_string($params['searchValue']);
        $whereClauses[] = "rp.name LIKE '%$searchEsc%'";
    }
    $where = $whereClauses ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

    // Tổng số bản ghi sau khi lọc
    $totalFiltered = $conn->query("SELECT COUNT(ID) AS cnt FROM roles_permissions rp $where")->fetch_assoc()['cnt'];

    // Lấy dữ liệu
    $sql = "SELECT rp.ID, rp.name, rp.roles, COUNT(a.ID) AS authors_count
            FROM roles_permissions rp
            LEFT JOIN authors a ON a.level = rp.ID
            $where
            GROUP BY rp.ID, rp.name, rp.roles
            ORDER BY rp.{$params['orderColumn']} {$params['orderDir']}
            LIMIT {$params['start']}, {$params['length']}";
    $rs = $conn->query($sql);

    $data = [];
    while ($row = $rs->fetch_assoc()) {
        $data[] = [
            "id"          => $row['ID'],
            "name"        => $row['name'],
            "roles"       => $row['roles'],
            "count"       => $row['authors_count']
        ];
    }

    return [
        "draw"            => $params['draw'],
        "recordsTotal"    => $totalRecords,
        "recordsFiltered" => $totalFiltered,
        "data"            => $data
    ];
}

function getRolesPermissions(): array
{
    if(!checkRoles('edit', 'roles-permissions')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền sửa roles'
        ];
    }
    $conn = db(); // Hàm db() trả về kết nối mysqli

    // Lấy id từ POST
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        return [
            'status'  => 'error',
            'message' => 'Thiếu hoặc ID không hợp lệ'
        ];
    }

    // Chuẩn bị truy vấn
    $stmt = $conn->prepare("SELECT name, roles FROM roles_permissions WHERE ID = ?");
    if (!$stmt) {
        return [
            'status'  => 'error',
            'message' => 'Lỗi prepare: ' . $conn->error
        ];
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        return [
            'status'      => 'success',
            'role_name'   => $row['name'],
            'permissions' => json_decode($row['roles'], true) ?? []
        ];
    } else {
        return [
            'status'  => 'error',
            'message' => 'Không tìm thấy role'
        ];
    }
}

function getXlsxByID($id): array {
	$conn = db();
	$check = $conn->prepare( "SELECT * FROM exports WHERE id = ?" );
	$check->bind_param( "i", $id );
	$check->execute();
	$result = $check->get_result();
	if ( $result->num_rows > 0 ) {
		$row = $result->fetch_assoc();
		return $row;
	} else {
		return [];
	}
}

function getXlsxFileHeader(string $filePath, string $sheetName = '', int $headerRowIndex = 1): array {
	// Kiểm tra file tồn tại
	if (!file_exists($filePath)) {
		return ['status' => 'error', 'message' => "File không tồn tại: $filePath"];
	}

	try {
		// Load file Excel
		$spreadsheet = IOFactory::load($filePath);
        $xlsx = [];
        // Lấy mảng tên sheets
        $sheetNames = $spreadsheet->getSheetNames();
        foreach ($sheetNames as $name) {
            $xlsx['tabs'][$name] = [
                'title' => $name
            ];
        }

        $sheet = $sheetName === ''
            ? $spreadsheet->getActiveSheet()
            : ($spreadsheet->getSheetByName($sheetName) ?? null);

        if (!$sheet) {
            return ['status' => 'error', 'message' => "Sheet '$sheetName' không tồn tại."];
        }

		// Xác định số cột tối đa
		$highestColumn = $sheet->getHighestColumn(); // Ví dụ: 'F'
		$highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

		// Lặp qua từng cột
		for ($col = 1; $col <= $highestColumnIndex; $col++) {
			// Tạo tọa độ ô (ví dụ: B4, C4...)
			$cellCoordinate = Coordinate::stringFromColumnIndex($col) . $headerRowIndex;
			$cellValue = $sheet->getCell($cellCoordinate)->getValue();

			if (empty($cellValue) || strtolower(trim($cellValue)) === 'null') {
				continue;
			}

            $xlsx['columns'][] = [
				'column' => Coordinate::stringFromColumnIndex($col),
				'row' => $headerRowIndex,
				'value' => $cellValue,
			];
		}

		return ['status' => 'success', 'xlxs' => $xlsx];

	} catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
		return ['status' => 'error', 'message' => 'Lỗi đọc file Excel: ' . $e->getMessage()];
	} catch (\Throwable $e) {
		return ['status' => 'error', 'message' => 'Lỗi không xác định: ' . $e->getMessage()];
	}
}

/**
 * Thêm điều kiện lọc vào $whereClauses
 *
 * @param array  $whereClauses  Mảng chứa các điều kiện WHERE
 * @param string $field         Tên cột trong DB (vd: authors.status)
 * @param int    $colIndex      Index cột trong DataTables
 * @param string $type          Kiểu dữ liệu: int|string|like
 * @param mysqli $conn          Kết nối DB để escape string
 */
function addTableFilter(array &$whereClauses, string $field, int $colIndex, string $type, mysqli $conn): void {
    $val = trim($_POST['columns'][$colIndex]['search']['value'] ?? '', '^$');
    if ($val === '') return;

    switch ($type) {
        case 'int':
            $whereClauses[] = "$field = " . (int)$val;
            break;
        case 'string':
            $esc = $conn->real_escape_string($val);
            $whereClauses[] = "$field = '$esc'";
            break;
        case 'like':
            $esc = $conn->real_escape_string($val);
            $whereClauses[] = "$field LIKE '%$esc%'";
            break;
    }
}

function addOrders(): array
{
    // Kết nối DB
    $conn = db();

    // 1. Kiểm tra key trong bảng options
    $key = $_GET['key'] ?? '';
    if (!empty($key)) {
        $res = $conn->query("SELECT value FROM options WHERE name = 'sys_orders' LIMIT 1");
        $row = $res->fetch_assoc();
        if (!$row || $key !== $row['value']) {
            return ['error' => 'Invalid key'];
        }
    } else {
        return ['error' => 'Api key not found in options'];
    }

    // Kiểm tra email trong bảng accounts
    $account_id = 0;
    $account_email = $_GET['email'] ?? '';
    if (!empty($account_email)) {
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $account_email);
        $stmt->execute();
        $stmt->bind_result($account_id);
        $stmt->fetch();
        $stmt->close();

        if (empty($account_id)) {
            return ['error' => 'Email not found in accounts'];
        }
    } else {
        return ['error' => 'Account email not found in options'];
    }

    // Đọc dữ liệu JSON từ body request
    $input = json_decode(file_get_contents('php://input'), true);
    $orders = $input['orders'] ?? [];
    if (empty($orders)) {
        return [];
    }

    // Chuẩn bị dữ liệu insert
    $values = [];
    foreach ($orders as $order) {
        if (!empty($order['Id'])) {
            $host_id = $conn->real_escape_string($order['Id']);
            $status = $conn->real_escape_string($order['Status']);
            $purchase_date = date('Y-m-d H:i:s', (int)$order['purchaseDate']);
            $delivery_date = date('Y-m-d H:i:s', (int) $order['DeliveryDate']);
            $ship_date = date('Y-m-d H:i:s', (int) $order['ShipDate']);
            $total_price = $order['Amount']['CurrencyCode'] === "USD" ? (float) $order['Amount']['Amount'] : 0;
            $full_name = $conn->real_escape_string($order['Address']['name']);
            $phone = $conn->real_escape_string($order['Address']['phoneNumber'] ?? '');
            $street_address_1 = $conn->real_escape_string($order['Address']['line1']);
            $street_address_2 = $conn->real_escape_string($order['Address']['line2'] ?? '');
            $city = $conn->real_escape_string($order['Address']['city']);
            $state= $conn->real_escape_string($order['Address']['stateOrRegion'] ?? '');
            $zip_code = $conn->real_escape_string($order['Address']['postalCode'] ?? '');
            $country = $conn->real_escape_string($order['Address']['countryCode']);
            $items = $conn->real_escape_string(json_encode($order['Items'] ?? []));
            $values[] = "($account_id, '$host_id', '$items', '$status','$purchase_date','$delivery_date','$ship_date', $total_price, '$full_name', '$phone', '$street_address_1', '$street_address_2', '$city', '$state', '$zip_code', '$country')";
        }
    }

    if (!empty($values)) {
        $sql = "
            INSERT IGNORE INTO orders (account_id, host_id, items, status, purchase_date, delivery_date, ship_date, total_price, full_name, phone, street_address_1, street_address_2, city, state, zip_code, country)
            VALUES " . implode(',', $values);

        $conn->query($sql);
    }

    return $orders;
}

function addKeywords(): array
{
    if(!checkRoles(['add','edit'], 'keywords')){
        return ['error' => ['Bạn Không có quyền thêm và sửa từ khóa']];
    }
    $conn = db();
    $result = [
        'success' => [],
        'error'   => [],
    ];

    $rawKeywords = $_POST['keywords'] ?? '';
    $status      = (int) ($_POST['keywordsStatus'] ?? 0);

    // Chỉ nhận status 1, 2, 3
    if (!in_array($status, [1, 2, 3], true)) {
        return ['error' => ['Trạng thái không hợp lệ!']];
    }

    // Tách theo dòng
    $lines = preg_split('/\r\n|\r|\n/', $rawKeywords);

    $keywords = [];
    foreach ($lines as $line) {
        // Tách tiếp theo dấu phẩy nếu có
        $parts = explode(',', $line);
        foreach ($parts as $part) {
            $kw = trim($part);
            $kw = preg_replace('/\s+/', ' ', $kw); // chuẩn hóa khoảng trắng
            if ($kw !== '') {
                $keywords[] = mb_strtolower($kw); // chuẩn hóa chữ thường
            }
        }
    }

    // Loại bỏ trùng lặp
    $keywords = array_unique($keywords);

    if (empty($keywords)) {
        return ['error' => ['Không có từ khóa hợp lệ!']];
    }

    // Chuẩn bị statement
    $stmt = $conn->prepare("
        INSERT INTO keywords (name, status)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status)
    ");

    if (!$stmt) {
        return ['error' => ['Lỗi prepare: ' . $conn->error]];
    }

    foreach ($keywords as $kw) {
        $stmt->bind_param('si', $kw, $status);
        if ($stmt->execute()) {
            $result['success'][] = $kw;
        } else {
            $result['error'][] = "Lỗi với từ khóa '{$kw}': " . $stmt->error;
        }
    }

    $stmt->close();
    return $result;
}

function addXlsx(): array {
    if(!checkRoles(['add','edit'], 'exports_xlsx')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền thêm và sửa file excel'
        ];
    }
    $conn = db();
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
        return ['status' => 'error', 'message' => 'CSRF token không hợp lệ'];
    }

    // Kiểm tra xem có file mới được upload không
    $fileUploaded = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
    $originalName = '';
    $uniqueName   = '';

    // Nếu có file mới, xử lý kiểm tra và lưu
    if ($fileUploaded) {
        $file         = $_FILES['file'];
        $originalName = $file['name'];
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedMime  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        if ($file['type'] !== $allowedMime || $extension !== 'xlsx') {
            return ['status' => 'error', 'message' => 'Chỉ chấp nhận file .xlsx'];
        }

        $uniqueName = uniqid('export_', true) . '.xlsx';
        $uploadDir  = __DIR__ . '/xlsx/';
        $targetPath = $uploadDir . $uniqueName;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['status' => 'error', 'message' => 'Không thể lưu file'];
        }
    }

    // Dữ liệu từ form
    $id           = $_POST['id'] ?? null;
    $site_id      = (int) ($_POST['site'] ?? 0);
    $type_id      = (int) ($_POST['type'] ?? 0);
    $accounts_id  = (int) ($_POST['account'] ?? 0);
    $authors_id = (int)(
    isAdmin() && !empty($_POST['author'])
        ? $_POST['author']
        : ($_SESSION['auth']['user_id'] ?? 0)
    );
    $date_create  = date('Y-m-d H:i:s');
    $xlsx_options = $_POST['options'] ?? '';
    $row_header = (int) ($_POST['header'] ?? 0);
    $row_item = (int) ($_POST['startRow'] ?? 0);
    $sheet_name = $_POST['sheet_name'] ?? '';

    // Nếu có ID, kiểm tra bản ghi để cập nhật
    if ($id) {
        $check = $conn->prepare("SELECT file_name, file_dir FROM exports WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $row         = $result->fetch_assoc();
            $oldFileName = $row['file_name'];
            $oldFileDir  = $row['file_dir'];

            // Nếu có file mới và tên khác, xóa file cũ
            if ($fileUploaded && $originalName !== $oldFileName && file_exists(__DIR__ . '/xlsx/' . $oldFileDir)) {
                unlink(__DIR__ . '/xlsx/' . $oldFileDir);
            }

            // Nếu không có file mới, giữ nguyên tên và đường dẫn file cũ
            if (!$fileUploaded) {
                $originalName = $oldFileName;
                $uniqueName   = $oldFileDir;
            }

            // Cập nhật bản ghi
            $update = $conn->prepare("
                UPDATE exports SET
                    accounts_id = ?, type_id = ?, site_id = ?, authors_id = ?,
                    date_create = ?, file_name = ?, file_dir = ?, file_default = ?, row_header = ?, row_item = ?, sheet_name = ?
                WHERE id = ?
            ");
            $update->bind_param("iiiissssiisi", $accounts_id, $type_id, $site_id, $authors_id, $date_create, $originalName, $uniqueName, $xlsx_options, $row_header, $row_item, $sheet_name, $id);

            if ($update->execute()) {
                return ['status' => 'updated', 'id' => $id, 'file' => $uniqueName];
            } else {
                return ['status' => 'error', 'message' => 'Lỗi khi cập nhật dữ liệu'];
            }
        }
    }

    // Nếu không có ID hoặc không tìm thấy bản ghi, thêm mới
    if (!$fileUploaded) {
        return ['status' => 'error', 'message' => 'Vui lòng chọn file .xlsx để thêm mới'];
    }

    $insert = $conn->prepare("
        INSERT INTO exports (accounts_id, type_id, site_id, authors_id, date_create, file_name, file_dir, file_default, row_header, row_item)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param("iiiissssii", $accounts_id, $type_id, $site_id, $authors_id, $date_create, $originalName, $uniqueName, $xlsx_options, $row_header, $row_item);

    if ($insert->execute()) {
        return ['status' => 'inserted', 'id' => $insert->insert_id, 'file' => $uniqueName];
    } else {
        return ['status' => 'error', 'message' => 'Lỗi khi thêm dữ liệu'];
    }
}

function addRolesPermissions(): array
{
    if(!checkRoles(['add','edit'], 'roles-permissions')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền thêm và sửa roles'
        ];
    }
    $conn = db(); // Hàm db() trả về kết nối mysqli

    $roleName = $_POST['role_name'] ?? '';
    $permissions = $_POST['permissions'] ?? [];

    if (trim($roleName) === '') {
        return ['status' => 'error', 'message' => 'Tên không được để trống.'];
    }

    $rolesJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);

    // Kiểm tra trùng tên role
    $stmtCheck = $conn->prepare("SELECT id FROM roles_permissions WHERE name = ?");
    $stmtCheck->bind_param("s", $roleName);
    $stmtCheck->execute();
    $result = $stmtCheck->get_result();

    if ($result->num_rows > 0) {
        // Nếu tồn tại → update
        $row = $result->fetch_assoc();
        $roleId = $row['id'];

        $stmtUpdate = $conn->prepare("UPDATE roles_permissions SET roles = ? WHERE id = ?");
        $stmtUpdate->bind_param("si", $rolesJson, $roleId);

        if ($stmtUpdate->execute()) {
            $stmtCheck->close();
            $stmtUpdate->close();
            return ['status' => 'success', 'message' => 'Đã cập nhật vai trò'];
        } else {
            $stmtCheck->close();
            $stmtUpdate->close();
            return ['status' => 'error', 'message' => 'Lỗi khi cập nhật: ' . $stmtUpdate->error];
        }
    } else {
        // Nếu chưa tồn tại → insert
        $stmtInsert = $conn->prepare("INSERT INTO roles_permissions (name, roles) VALUES (?, ?)");
        $stmtInsert->bind_param("ss", $roleName, $rolesJson);

        if ($stmtInsert->execute()) {
            $stmtCheck->close();
            $stmtInsert->close();
            return ['status' => 'success', 'message' => 'Đã thêm vai trò mới'];
        } else {
            $stmtCheck->close();
            $stmtInsert->close();
            return ['status' => 'error', 'message' => 'Lỗi khi thêm: ' . $stmtInsert->error];
        }
    }
}

function duplicateXlsx(): array {
    if(!checkRoles(['add','edit'], 'exports_xlsx')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền thêm hoặc sửa file excel'
        ];
    }
	$conn = db();
	$id = $_POST['id'] ?? null;
	$csrfToken = $_POST['csrf_token'] ?? '';

	// 1. Validate input
	if (!is_numeric($id) || $id <= 0) {
		return ['status' => 'error', 'message' => 'ID không hợp lệ'];
	}
	if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
		return ['status' => 'error', 'message' => 'CSRF token không hợp lệ'];
	}

	// 2. Lấy dữ liệu gốc
	$stmt = $conn->prepare("SELECT * FROM exports WHERE id = ?");
	$stmt->bind_param("i", $id);
	$stmt->execute();
	$result = $stmt->get_result();
	if ($result->num_rows <= 0) {
		return ['status' => 'error', 'message' => 'ID không tồn tại'];
	}
	$row = $result->fetch_assoc();
    $authors_id = $_SESSION['auth']['user_id'] ?? null;
	// 4. Insert bản ghi mới
	$stmt2 = $conn->prepare("INSERT INTO exports (type_id, site_id, authors_id, accounts_id, file_default, row_header, row_item, sheet_name, date_create) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
	$stmt2->bind_param("iiiissss", $row['type_id'], $row['site_id'], $authors_id, $row['accounts_id'], $row['file_default'], $row['row_header'], $row['row_item'],$row['sheet_name']);
	$success = $stmt2->execute();

	if ($success) {
		$newId = $stmt2->insert_id;
		return ['status' => 'success', 'message' => 'Nhân bản thành công', 'newRecord' => $newId];
	}

	return ['status' => 'error', 'message' => 'Không thể nhân bản'];
}

function deleteXlsx(): array {
    if(!checkRoles('delete', 'exports_xlsx')){
        return [
            'status'  => 'error',
            'message' => 'Bạn Không có quyền xóa file excel'
        ];
    }
	$conn = db();
	$id = $_POST['id'] ?? null;
	$csrfToken = $_POST['csrf_token'] ?? '';
	// 2. Kiểm tra dữ liệu đầu vào
	if (!is_numeric($id) || $id <= 0) {
		return ['status' => 'error', 'message' => 'ID không hợp lệ'];
	}
	if (!isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
		return ['status' => 'error', 'message' => 'CSRF token không hợp lệ'];
	}

	$check = $conn->prepare("SELECT file_dir FROM exports WHERE id = ?");
	$check->bind_param("i", $id);
	$check->execute();
	$check_result = $check->get_result();
	if ($check_result->num_rows <= 0) {
		return ['status' => 'error', 'message' => 'ID không tồn tại'];
	}

	// 5. Gọi hàm xóa, xử lý lỗi và log
	try {
		$result = deleteTableRow('exports', $id);
		if ($result['success'] && $result['affected_rows'] > 0) {
			// delete file.
			$row = $check_result->fetch_assoc();
			$FileDir  = $row['file_dir'];
			// xóa file
			if ($FileDir && file_exists(__DIR__ . '/xlsx/' . $FileDir)) {
				unlink(__DIR__ . '/xlsx/' . $FileDir);
			}
			return ['status' => 'success', 'message' => 'Xóa dữ liệu thành công'];
		}
		return ['status' => 'error', 'message' => 'Không tìm thấy dữ liệu để xóa'];
	} catch (Throwable $e) {
		return ['status' => 'error', 'error' => "Xóa exports ID={$id} lỗi: " . $e->getMessage()];
	}
}

function downloadXlsx(): array
{
    $conn = db();
    $downloadID = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Kiểm tra trạng thái trước
    $checkSql = "SELECT accounts.sku, exports.file_default, exports.file_name AS t_file, exports.file_dir, exports.row_header, exports.row_item, download.file_name AS d_file
                 FROM download
                 INNER JOIN exports ON exports.ID = download.exports_id
                 INNER JOIN accounts ON accounts.ID = exports.accounts_id
                 WHERE download.id = ?
                 AND download.status = 'ready'"; // ready
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param('i', $downloadID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $statusRow = $checkResult->fetch_assoc();

    if (!$statusRow) {
        // Không tồn tại hoặc chưa sẵn sàng
        echo json_encode(['status' => 'error', 'message' => "Chưa sẵn sàng"]);
        exit();
    }

    // Kiểm tra và load file .xlxs
    $filePath = ROOT_DIR . "/xlsx/" . $statusRow['file_dir'];
    if (!file_exists($filePath)) {
        echo json_encode(['status' => 'error', 'message' => "File không tồn tại: $filePath"]);
        exit();
    }

    try {
        // Load file Excel
        $spreadsheet = IOFactory::load($filePath);
        $sheetName = 'Template';
        // Lấy sheet theo tên
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            echo json_encode(['status' => 'error', 'message' => "Sheet '$sheetName' không tồn tại."]);
            exit();
        }

        // Xác định số cột tối đa
        $highestColumn = $sheet->getHighestColumn(); // Ví dụ: 'F'
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        $headerRowIndex = (int)$statusRow['row_header'] ?? 4;

        // Lặp qua từng cột
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            // Tạo tọa độ ô (ví dụ: B4, C4...)
            $cellCoordinate = Coordinate::stringFromColumnIndex($col) . $headerRowIndex;
            $cellValue = $sheet->getCell($cellCoordinate)->getValue();

            if (empty($cellValue) || strtolower(trim($cellValue)) === 'null') {
                continue;
            }

            $headers[Coordinate::stringFromColumnIndex($col)] =  $cellValue;
        }
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi đọc file Excel: ' . $e->getMessage()]);
        exit();
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi không xác định: ' . $e->getMessage()]);
        exit();
    }

    // Lấy dữ liệu
    $sql = "SELECT DISTINCT al.item_name, al.product_description, al.meta_data, p.sku, p.images
            FROM amazon_listings AS al
            INNER JOIN posts p ON al.post_id = p.ID
            WHERE al.download_id = ?
            AND (TRIM(al.copyright_warning) = ''
            OR TRIM(al.copyrighted_content) = ''
            OR LOWER(al.copyright_warning) IN ('none','no','n/a','false','not applicable')
            OR LOWER(al.copyrighted_content) IN ('none','no','n/a','false','not applicable')
            OR al.copyright_warning IS NULL
            OR al.copyrighted_content IS NULL)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $downloadID);
    $stmt->execute();
    $result = $stmt->get_result();

    // chạy toàn bộ sản phẩm.
    $startRow = (int)$statusRow['row_item'] ?? 7; // bắt đầu row.
    $counter  = 0; // đếm số sản phẩm đã xử lý
    $colorIndex = 1;
    while ($row = $result->fetch_assoc()) {
        $counter++; // tăng đếm mỗi sản phẩm
        // get default headers value.
        $default_values = !empty($statusRow['file_default']) ? json_decode($statusRow['file_default'], true) : [];
        $images = json_decode($row['images'], true);
        $default_values[] = [
            'text'  => 'Main Image URL',
            'value' => $images['main'] ?? ''
        ];
        $default_values[] = [
            'text'  => 'Other Image URL',
            'value' => $images['images'] ?? []
        ];

        // map AI values.
        $default_values[] = [
            'text'  => 'Item Name',
            'value' => $row['item_name']
        ];
        $default_values[] = [
            'text'  => 'Product Description',
            'value' => $row['product_description']
        ];
        $meta_data = json_decode($row['meta_data'], true);
        $default_values[] = [
            'text'  => 'Color Family',
            'value' => $meta_data['Color']
        ];
        // Xóa 2 key
        unset($meta_data['Main Image URL'], $meta_data['Color'], $meta_data['Wall Art Form']);
        foreach ($meta_data as $key => $meta){
            if(!is_array($meta)){
                $meta = explode(',' , $meta);
            }
            if($key === 'Generic Keyword'){
                $meta = splitKeywordsIntoColumns($meta, 5);
            }
            $default_values[] = [
                'text'  => $key,
                'value' => $meta
            ];
        }

        // ✅ Nếu là sản phẩm Parent (mỗi 20 sản phẩm)
        $isParent = ($counter === 1) || ($counter % 21 === 0);
        if ($isParent) {
            $colorIndex = 1;
            $SKU = $statusRow['sku'] . '-'. $row['sku'];
            $default_values[] = [
                'text'  => 'Parentage Level',
                'value' => 'Parent'
            ];
            $default_values[] = [
                'text'  => 'Color',
                'value' => ''
            ];
            $default_values[] = [
                'text'  => 'SKU',
                'value' => $SKU
            ];
        } else {
            $default_values[] = [
                'text'  => 'Parentage Level',
                'value' => 'Child'
            ];
            $default_values[] = [
                'text'  => 'Parent SKU',
                'value' => $SKU
            ];
            $default_values[] = [
                'text'  => 'Color',
                'value' => 'Color ' . $colorIndex
            ];
            $default_values[] = [
                'text'  => 'SKU',
                'value' => $SKU .'-' .$row['sku']
            ];
        }

        // Ghi dòng sản phẩm gốc
        writeRowXlsx($sheet, $headers, $default_values, $startRow);

        // Nếu là Parent → thêm dòng bản sao
        if ($isParent) {
            $startRow++;
            $parentCopy = $default_values;
            $parentCopy[] = [
                'text'  => 'Parent SKU',
                'value' => $SKU
            ];
            // Ví dụ: sửa
            foreach ($parentCopy as &$item) {
                switch ($item['text']){
                    case 'Parentage Level':
                        $item['value'] = 'Child';
                        break;
                    case 'Color':
                        $item['value'] = 'Color 1';
                        break;
                    case 'SKU':
                        $item['value'] = $SKU . '-' . $row['sku'];
                        break;
                }
            }
            writeRowXlsx($sheet, $headers, $parentCopy, $startRow);
        }

        // chạy thêm dữ liệu từ AI
        $startRow++;
        $colorIndex++;
    }

    // Thư mục lưu file export
    $exportDir = ROOT_DIR . "/export/";
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0777, true);
    }

    // Nếu có file cũ thì xóa
    if (!empty($statusRow['d_file'])) {
        $oldFile = $exportDir . $statusRow['d_file'];
        if (file_exists($oldFile)) {
            unlink($oldFile);
        }
    }

    // Tạo tên file mới (tránh trùng)
    $newFileName = 'export_' . $downloadID . '_' . date('Ymd_His') . '_' . $statusRow['t_file'];
    $newFilePath = $exportDir . $newFileName;

    // Lưu file mới
    $writer = new Xlsx($spreadsheet);
    $writer->save($newFilePath);

    // Cập nhật tên file vào DB
    $now = date('Y-m-d H:i:s'); // thời gian hiện tại
    $updateSql = "UPDATE download SET file_name = ?, download_date = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param('ssi', $newFileName, $now, $downloadID);
    $updateStmt->execute();
    // Bảo đảm không có output trước khi gửi header
    if (ob_get_level()) {
        ob_end_clean();
    }
    if (is_readable($newFilePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . basename($newFilePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($newFilePath));
        $fp = fopen($newFilePath, 'rb');
        set_time_limit(0);
        // Gửi nội dung
        fpassthru($fp);
        fclose($fp);
        exit();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi file chưa được tạo hoặc không thể đọc.']);
        exit();
    }
}

function writeRowXlsx($sheet, $headers, $values, $rowNum): void
{
    foreach ($values as $item) {
        $text = $item['text'];
        $value = $item['value'] ?? '';

        // Trường hợp 1: Có location và text tồn tại trong headers
        if (!empty($item['location']) && isset($headers[$item['location']]) && $headers[$item['location']] === $text) {
            $sheet->setCellValue($item['location'] . $rowNum, $value);
            continue;
        }

        // Tìm tất cả key trong headers có text trùng
        $matchedKeys = array_keys($headers, $text);

        if (count($matchedKeys) === 1) {
            // Trường hợp 2: text chỉ xuất hiện 1 lần
            if(is_array($value)){
                $value = $value[0];
            }
            $sheet->setCellValue($matchedKeys[0] . $rowNum, $value);
        } elseif (count($matchedKeys) > 1) {
            // Trường hợp 3: text xuất hiện nhiều lần
            if (!is_array($value)) {
                // 3a: value là string → gán vào item đầu tiên
                $sheet->setCellValue($matchedKeys[0] . $rowNum, $value);
            } else {
                // 3b: value là array → gán lần lượt
                foreach ($matchedKeys as $i => $colKey) {
                    if (isset($value[$i])) {
                        $sheet->setCellValue($colKey . $rowNum, $value[$i]);
                    }
                }
            }
        }
    }
}

function deleteTableRow($table, $row_id): array {
	$conn = db(); // Hàm db() trả về đối tượng mysqli

	// 🔒 Xác thực tên bảng để tránh SQL Injection qua $table
	$allowedTables = ['exports']; // ví dụ whitelist
	if (!in_array($table, $allowedTables)) {
		return [
			'success' => false,
			'affected_rows' => 0,
			'error' => 'Bảng không hợp lệ'
		];
	}

	$sql = "DELETE FROM `$table` WHERE id = ?";
	$stmt = $conn->prepare($sql);
	// 'i' = integer, 's' = string, 'd' = double, 'b' = blob
	$stmt->bind_param('i', $row_id);

	$success = $stmt->execute();
	$affected_rows = $stmt->affected_rows;

	$stmt->close();

	return [
		'success' => $success,
		'affected_rows' => $affected_rows
	];
}

function insertAmazonListingFromAI($downloadId, string $post_id, array $aiData): array
{
    $conn = db();
    $tableColumns = [
        'download_id',
        'post_id',
        'item_name',
        'product_description',
        'copyright_warning',
        'copyrighted_content',
        'watermark_warning',
        'meta_data',
        'created_at',
        'updated_at'
    ];

    $mapKeys = [
        'Item Name' => 'item_name',
        'Product Description' => 'product_description',
        'Copyright Warning' => 'copyright_warning',
        'Copyrighted Content' => 'copyrighted_content',
        'Watermark Warning' => 'watermark_warning'
    ];

    $now = date('Y-m-d H:i:s');
    $insertData = [
        'download_id' => $downloadId,
        'post_id' => $post_id,
        'created_at' => $now,
        'updated_at' => $now
    ];

    $metaData = [];

    foreach ($aiData as $key => $value) {
        if (isset($mapKeys[$key])) {
            $col = $mapKeys[$key];
            if (in_array($col, $tableColumns, true)) {
                // Normalize scalars to strings, allow null for empty values
                $insertData[$col] = $value === '' ? null : (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        } else {
            $metaData[$key] = $value;
        }
    }

    // Only include meta_data if there's something to store
    if (!empty($metaData)) {
        $json = json_encode($metaData, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['status' => 'error', 'message' => 'Failed to encode meta_data to JSON: ' . json_last_error_msg()];
        }
        $insertData['meta_data'] = $json;
    } else {
        $insertData['meta_data'] = null;
    }

    // Build INSERT
    $cols = array_keys($insertData);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO amazon_listings (" . implode(',', $cols) . ") VALUES ($placeholders)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return ['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error];
    }

    // Determine types for bind_param: i for integer download_id, s for strings, allow nulls
    $types = '';
    $values = [];
    foreach ($cols as $c) {
        $v = $insertData[$c];
        if ($c === 'download_id') {
            $types .= 'i';
            $values[] = $v === null ? null : (int)$v;
        } else {
            $types .= 's';
            $values[] = $v === null ? null : (string)$v;
        }
    }

    // mysqli requires variables by reference for bind_param
    $bindParams = [];
    $bindParams[] = & $types;
    foreach ($values as $k => $val) {
        $bindParams[] = & $values[$k];
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
        $err = 'bind_param failed: ' . $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => $err];
    }

    if (!$stmt->execute()) {
        $err = 'Execute failed: ' . $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => $err];
    }

    $insertId = $stmt->insert_id;
    $stmt->close();
    return ['status' => 'inserted', 'id' => $insertId];
}

function saveExportQuery(): array
{
    if(!checkRoles('add', 'exports_download')){
        return ['status' => 'error', 'message' => 'Bạn Không có quyền thêm và sửa từ khóa'];
    }
    $conn = db();
    $products = getProductsTable();

    if (empty($products['data'])) {
        return ['status' => 'error', 'message' => 'Không có sản phẩm nào để xử lý'];
    }

    // Lấy dữ liệu từ form & session
    $account_id  = intval($_POST['exported'] ?? 0);
    $exports_id  = intval($_POST['file'] ?? 0);
    $author_id   = intval($_SESSION['auth']['user_id'] ?? 0);
    $date_create = date('Y-m-d H:i:s');
    $status      = 'schedule';
    $total_items = count($products['data']);

    // Kiểm tra tài khoản tồn tại
    $checkAccount = $conn->prepare("SELECT id FROM accounts WHERE id = ?");
    $checkAccount->bind_param("i", $account_id);
    $checkAccount->execute();
    $checkAccount->store_result();

    if ($checkAccount->num_rows === 0) {
        $checkAccount->close();
        return ['status' => 'error', 'message' => 'Tài khoản không tồn tại'];
    }
    $checkAccount->close();

    // Thêm bản ghi download
    $insertDownload = $conn->prepare("
        INSERT INTO download (author_id, exports_id, status, date, total_items)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insertDownload->bind_param("iissi", $author_id, $exports_id, $status, $date_create, $total_items);

    if (!$insertDownload->execute()) {
        return ['status' => 'error', 'message' => 'Lỗi khi thêm bản ghi download: ' . $insertDownload->error];
    }

    $new_id = $conn->insert_id;
    $insertDownload->close();

    $postIds = array_column($products['data'], 'id');
    if (empty($postIds)) {
        return ['status' => 'error', 'message' => 'Không có bài viết nào để cập nhật'];
    }

    $chunks = array_chunk($postIds, 500);
    $conn->begin_transaction();

    try {
        foreach ($chunks as $chunk) {
            // UPDATE posts với prepared statement và placeholder động
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sqlUpdate = "UPDATE posts SET status = ? WHERE id IN ($placeholders)";
            $updatePosts = $conn->prepare($sqlUpdate);

            $types = 's' . str_repeat('i', count($chunk));
            $params = array_merge([$status], array_map('intval', $chunk));
            $updatePosts->bind_param($types, ...$params);

            if (!$updatePosts->execute()) {
                throw new Exception("Lỗi cập nhật trạng thái bài viết: " . $updatePosts->error);
            }
            $updatePosts->close();

            // Chèn accounts_relationships
            $a_values = [];
            foreach ($chunk as $post_id) {
                $a_values[] = "($account_id, " . intval($post_id) . ")";
            }
            $sqlAccRel = "INSERT IGNORE INTO accounts_relationships (account_id, post_id) VALUES " . implode(',', $a_values);
            if (!$conn->query($sqlAccRel)) {
                throw new Exception("Lỗi chèn quan hệ tài khoản: " . $conn->error);
            }

            // Chèn download_relationships
            $d_values = [];
            foreach ($chunk as $post_id) {
                $d_values[] = "($new_id, " . intval($post_id) . ")";
            }
            $sqlDownRel = "INSERT IGNORE INTO download_relationships (download_id, post_id) VALUES " . implode(',', $d_values);
            if (!$conn->query($sqlDownRel)) {
                throw new Exception("Lỗi chèn quan hệ download: " . $conn->error);
            }
        }

        $conn->commit();
        return ['status' => 'inserted', 'id' => $new_id];

    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function splitKeywordsIntoColumns(array $keywords, int $numColumns = 5): array {
    // Khởi tạo mảng rỗng cho các cột
    $columns = array_fill(0, $numColumns, []);

    // Phân bổ từ khóa vào từng cột theo vòng lặp
    foreach ($keywords as $index => $keyword) {
        $columnIndex = $index % $numColumns;
        $columns[$columnIndex][] = trim($keyword);
    }

    // Nối các từ khóa trong mỗi cột bằng dấu ;
    return array_map(fn($col) => implode('; ', $col), $columns);
}

/**
 * Trả về chuỗi mô tả khoảng thời gian tương đối (ví dụ "2 hours ago").
 *
 * @param string $datetime Chuỗi ngày giờ hợp lệ (ví dụ "2025-10-12 09:00:00")
 * @param string $locale   Mã ngôn ngữ để mở rộng sau này (hiện chưa dùng)
 * @return string
 * @throws InvalidArgumentException Nếu $datetime không thể parse
 */
function timeAgo(string $datetime, string $locale = 'en'): string
{
    // Chuyển chuỗi sang timestamp; kiểm tra tính hợp lệ
    $time = strtotime($datetime);
    if ($time === false) {
        throw new InvalidArgumentException("Invalid datetime string: $datetime");
    }

    $now = time();
    $diff = $now - $time;

    // Trường hợp tương lai hoặc bằng thời điểm hiện tại
    if ($diff < 0) {
        return 'In the future'; // hoặc có thể trả về 'Just now' theo yêu cầu
    }
    if ($diff < 60) {
        return 'Just now';
    }

    // Đơn vị theo thứ tự từ lớn xuống nhỏ
    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
        1        => 'second'
    ];

    foreach ($units as $seconds => $label) {
        $value = (int) floor($diff / $seconds);
        if ($value >= 1) {
            // dạng số nhiều nếu cần
            $plural = $value > 1 ? 's' : '';
            return "$value $label$plural ago";
        }
    }

    // Fallback an toàn (không nên tới đây)
    return 'Just now';
}

function formatCurrencyVND($input): string
{
    // Ép kiểu về số nguyên
    $number = intval($input);

    // Định dạng số với dấu phẩy và thêm hậu tố VND
    return number_format($number, 0, ',', ',') . ' VND';
}

function getDebug()
{
    $_SESSION['auth'] = ['user_id' => 1, 'team' => 1];
    return openai_get_batches_by_name('batch_68edd78c58908190a7cdf5777118cc1a');
    // Spawn worker
    //$cmd = "php " . __DIR__ . "/worker.php 173 batches/zwddup4vdx5tpo5srm92tz6rvgvah3rxqifu > /dev/null 2>&1 &";
    //exec($cmd);
}