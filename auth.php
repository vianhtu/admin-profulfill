<?php
// auth.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Base64-url encode
 */
function base64url_encode(string $data): string {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64-url decode
 */
function base64url_decode(string $data): string {
	$pad = 4 - (strlen($data) % 4);
	$data .= str_repeat('=', $pad);
	return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Tạo JWT từ payload
 */
function jwt_create(array $payload): string {
	$header = ['typ' => 'JWT', 'alg' => JWT_ALGO];
	$payload['iat'] = time();
	$payload['exp'] = time() + JWT_EXPIRE;

	$base64Header  = base64url_encode(json_encode($header));
	$base64Payload = base64url_encode(json_encode($payload));

	$signature = hash_hmac(
		'sha256',
		"$base64Header.$base64Payload",
		JWT_SECRET,
		true
	);
	$base64Sig = base64url_encode($signature);

	return "$base64Header.$base64Payload.$base64Sig";
}

/**
 * Kiểm tra tính hợp lệ của JWT
 */
function jwt_verify(string $token): ?array {
	$parts = explode('.', $token);
	if (count($parts) !== 3) {
		return null;
	}

	list($base64Header, $base64Payload, $base64Sig) = $parts;
	$header  = json_decode(base64url_decode($base64Header), true);
	$payload = json_decode(base64url_decode($base64Payload), true);
	$sig0    = base64url_decode($base64Sig);

	// 1. Kiểm tra header alg
	if (empty($header['alg']) || $header['alg'] !== JWT_ALGO) {
		return null;
	}

	// 2. Tái tạo signature và so sánh
	$rawSig = hash_hmac(
		'sha256',
		"$base64Header.$base64Payload",
		JWT_SECRET,
		true
	);
	if (!hash_equals($rawSig, $sig0)) {
		return null;
	}

	// 3. Kiểm tra thời gian
	if (isset($payload['exp']) && time() > $payload['exp']) {
		return null;
	}

	return $payload;
}

/**
 * Bắt buộc user phải login trước khi truy cập
 */
function require_auth(): array {
	if (empty($_COOKIE[COOKIE_NAME])) {
		header('Location: ./html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}

	$payload = jwt_verify($_COOKIE[COOKIE_NAME]);
	if (!$payload || empty($payload['user_id'])) {
		// Xóa cookie giả mạo / hết hạn
		setcookie(
			COOKIE_NAME,
			'',
			time() - 3600,
			COOKIE_PATH,
			COOKIE_DOMAIN,
			//COOKIE_SECURE,
			COOKIE_HTTPONLY
		);
		header('Location: ./html/horizontal-menu-template-no-customizer/auth-login-basic.php');
		exit;
	}

	return $payload;
}