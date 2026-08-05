<?php
/**
 * HACK — bộ tấn công chức năng UPLOAD FILE (logo site: Site::upload_logo + delete_logo_file).
 *
 * Kỹ thuật: bỏ qua xác thực/role, upload web-shell (nhiều biến thể đuôi/MIME/nội dung),
 * SVG chứa script, giả mạo MIME, vượt kích thước, path traversal trong tên file, và path
 * traversal trong tham số 'replace' để xóa file tùy ý ngoài thư mục upload.
 *
 * LƯU Ý CLI: move_uploaded_file() chỉ chạy với upload HTTP thật nên trong CLI luôn thất bại
 * SAU khi qua validation. Vì vậy "lọt" được đánh giá theo việc file có VƯỢT QUA LỚP VALIDATION
 * bảo mật hay không (đuôi/MIME/getimagesize/size/quyền/CSRF), không phải theo việc lưu thành công.
 */

return function (HackRunner $h): void {

    // File hợp lệ 96x96 (để cô lập từng lớp kiểm tra khi cần vượt qua validation ảnh)
    $validPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAYAAADimHc4AAAAAXNSR0IArs4c6QAAABJJREFUeF7t'
        . 'wTEBAAAAwqD1T20ND6AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADwoBAAAB'
        . '6TdT/QAAAABJRU5ErkJggg=='
    );

    /** Tạo file tạm với nội dung cho trước, trả tmp path. */
    $tmp = function (string $content) {
        $p = tempnam(sys_get_temp_dir(), 'zzabup');
        file_put_contents($p, $content);
        return $p;
    };
    /** Dựng $_FILES['file'] như một upload. */
    $files = function (string $name, string $tmpPath, string $mime = 'image/png') {
        return ['file' => ['name' => $name, 'type' => $mime, 'tmp_name' => $tmpPath,
            'error' => 0, 'size' => filesize($tmpPath)]];
    };
    /** File có vượt qua lớp validation bảo mật không (dù lưu có thể fail ở CLI). */
    $passedValidation = function (array $res): bool {
        if (($res['status'] ?? '') !== 'error') {
            return true; // success -> chắc chắn qua
        }
        $m = (string)($res['message'] ?? '');
        // 2 thông báo này nghĩa là ĐÃ qua validation, chỉ fail ở bước ghi file (CLI)
        return str_contains($m, 'Failed to save') || str_contains($m, 'Uploaded file not found');
    };
    // csrf hợp lệ của user thật (kẻ tấn công là user hợp lệ, biết token CỦA MÌNH)
    $CSRF = 'VICTIM_SECRET';

    // ===== 1. Vượt xác thực / leo thang quyền =====
    $h->attack('Upload', 'ANON (chưa đăng nhập) upload logo', 'ANON', function ($atk, $fx) use ($tmp, $files, $validPng, $passedValidation) {
        $t = $tmp($validPng);
        $_POST = ['csrf_token' => ''];
        $_FILES = $files('logo.png', $t);
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Upload', 'USER chỉ-view upload logo (leo thang role)', 'USR_VIEW', function ($atk, $fx) use ($tmp, $files, $validPng, $passedValidation, $CSRF) {
        $t = $tmp($validPng);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('logo.png', $t);
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    // ===== 2. CSRF =====
    $h->attack('Upload', 'Upload không kèm CSRF token', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $validPng, $passedValidation) {
        $t = $tmp($validPng);
        $_POST = []; // không gửi token
        $_FILES = $files('logo.png', $t);
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    // ===== 3. Web-shell — nhiều biến thể =====
    $php = "<?php system(\$_GET['c']); ?>";

    $h->attack('Upload', 'Web-shell .php', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $php, $passedValidation, $CSRF) {
        $t = $tmp($php);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('shell.php', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Upload', 'Web-shell .phtml', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $php, $passedValidation, $CSRF) {
        $t = $tmp($php);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('shell.phtml', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Upload', 'Đuôi kép shell.php.png (đuôi png, nội dung PHP)', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $php, $passedValidation, $CSRF) {
        $t = $tmp($php);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('shell.php.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Upload', 'Đuôi kép shell.png.php', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $php, $passedValidation, $CSRF) {
        $t = $tmp($php);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('shell.png.php', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    // ===== 4. Giả mạo MIME / nội dung không phải ảnh =====
    $h->attack('Upload', 'Text đổi đuôi .png', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $passedValidation, $CSRF) {
        $t = $tmp('day khong phai anh');
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('fake.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    $h->attack('Upload', 'PHP + khai báo MIME image/png', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $php, $passedValidation, $CSRF) {
        $t = $tmp($php);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('a.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'NGHIÊM TRỌNG');

    // ===== 5. SVG chứa script (XSS lưu trữ nếu phục vụ inline) =====
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
    $h->attack('Upload', 'SVG chứa <script>, đuôi .svg', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $svg, $passedValidation, $CSRF) {
        $t = $tmp($svg);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('x.svg', $t, 'image/svg+xml');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    $h->attack('Upload', 'SVG chứa <script>, đuôi .png', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $svg, $passedValidation, $CSRF) {
        $t = $tmp($svg);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('x.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    // ===== 6. Vượt kích thước qua trường size giả =====
    $h->attack('Upload', 'Khai báo size 3MB (vượt 2MB)', 'USR_OUT', function ($atk, $fx) use ($tmp, $validPng, $passedValidation, $CSRF) {
        $t = $tmp($validPng);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = ['file' => ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => $t,
            'error' => 0, 'size' => 3 * 1024 * 1024]];
        $res = Site::upload_logo();
        @unlink($t);
        return ['breach' => $passedValidation($res), 'note' => json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'TRUNG BÌNH');

    // ===== 7. Path traversal trong TÊN file (đích lưu) =====
    $h->attack('Upload', 'Tên file ../../ (traversal đích lưu)', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $validPng, $CSRF) {
        $t = $tmp($validPng);
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('../../../../evil.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        // handleFileUploads đặt tên bằng UUID -> tên gốc không ảnh hưởng đích lưu.
        // "lọt" chỉ khi có bằng chứng file rơi ra ngoài uploads/ (kiểm ở note).
        $escaped = is_file('/var/www/html/admin-profulfill/evil.php')
            || is_file('/var/www/html/admin-profulfill/evil.png');
        return ['breach' => $escaped, 'note' => 'Đặt tên UUID nên tên gốc bị bỏ qua. ' . json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'CAO');

    // ===== 8. Path traversal trong tham số 'replace' -> xóa file tùy ý =====
    // Đòn khai thác trực tiếp delete_logo_file (private) qua reflection, vì move_uploaded_file
    // không chạy được ở CLI. Đây là lỗ hổng đã phát hiện & vá 2026-08-05.
    $h->attack('Upload', "replace='uploads/sites/../../config.php' xóa file ngoài thư mục", 'USR_OUT', function ($atk, $fx) {
        $root = '/var/www/html/admin-profulfill';
        $sentinel = $root . '/zzab_sentinel_' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($sentinel, 'do-not-delete');
        $rel = 'uploads/sites/../../' . basename($sentinel);
        $m = new ReflectionMethod('Site', 'delete_logo_file');
        $m->setAccessible(true);
        $m->invoke(null, $fx->conn, $rel);
        $deleted = !is_file($sentinel);
        @unlink($sentinel);
        return ['breach' => $deleted, 'note' => $deleted
            ? 'File ngoài uploads/sites ĐÃ BỊ XÓA qua traversal!'
            : 'Sentinel còn nguyên — traversal bị chặn.'];
    }, 'NGHIÊM TRỌNG');

    $h->attack('Upload', "replace đường dẫn tuyệt đối (ngoài uploads)", 'USR_OUT', function ($atk, $fx) {
        // Dùng sentinel riêng thay vì file hệ thống thật để tuyệt đối an toàn khi test.
        $sentinel = sys_get_temp_dir() . '/zzab_abs_' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($sentinel, 'x');
        $m = new ReflectionMethod('Site', 'delete_logo_file');
        $m->setAccessible(true);
        // Đường dẫn tuyệt đối không bắt đầu bằng 'uploads/sites/' -> phải bị chặn ngay
        $m->invoke(null, $fx->conn, $sentinel);
        $deleted = !is_file($sentinel);
        @unlink($sentinel);
        return ['breach' => $deleted, 'note' => $deleted ? 'File tuyệt đối BỊ XÓA!' : 'Bị chặn đúng.'];
    }, 'NGHIÊM TRỌNG');

    // ===== 9. Polyglot ảnh-hợp-lệ + PHP (thông tin: đã giảm thiểu nhiều lớp) =====
    $h->attack('Upload', 'Polyglot: PNG hợp lệ + PHP nối đuôi, .png', 'USR_OUT', function ($atk, $fx) use ($tmp, $files, $validPng, $php, $passedValidation, $CSRF) {
        $t = $tmp($validPng . $php); // ảnh hợp lệ, có payload PHP ở đuôi
        $_POST = ['csrf_token' => $CSRF];
        $_FILES = $files('poly.png', $t, 'image/png');
        $res = Site::upload_logo();
        @unlink($t);
        // Đây LÀ ảnh hợp lệ nên qua validation là bình thường. KHÔNG coi là lỗ hổng vì:
        // (1) resize_logo() vẽ lại ảnh -> payload bị loại; (2) nginx phục vụ uploads/sites
        // dạng tĩnh chỉ .png/.jpg, không đưa qua PHP. breach=false, chỉ ghi chú.
        return ['breach' => false, 'note' => 'Ảnh hợp lệ (qua validation là đúng); giảm thiểu bằng '
            . 're-encode + nginx phục vụ tĩnh. ' . json_encode($res, JSON_UNESCAPED_UNICODE)];
    }, 'THẤP');
};
