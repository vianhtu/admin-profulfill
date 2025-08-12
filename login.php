<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = new PDO(
	"mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
	DB_USER,
	DB_PASS,
	$options
);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$user  = trim($_POST['username'] ?? '');
	$pass  = $_POST['password'] ?? '';

	if ($user === '' || $pass === '') {
		$err = 'Vui lòng điền đầy đủ thông tin.';
	} else {
		// Lấy user từ DB
		$stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
		$stmt->execute([$user]);
		$row = $stmt->fetch();

		if ($row && password_verify($pass, $row['password_hash'])) {
			// Tạo JWT
			$token = jwt_create(['user_id' => (int)$row['id']]);

			// Đặt cookie
			setcookie(
				COOKIE_NAME,
				$token,
				time() + JWT_EXPIRE,
				COOKIE_PATH,
				COOKIE_DOMAIN,
				//COOKIE_SECURE,
				COOKIE_HTTPONLY
			);

			header('Location: dashboards.php');
			exit;
		} else {
			$err = 'Tài khoản hoặc mật khẩu không đúng.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
	<meta charset="UTF-8">
	<title>Login</title>
</head>
<body>
<h2>Đăng nhập</h2>
<?php if ($err): ?>
	<p style="color:red;"><?= htmlspecialchars($err) ?></p>
<?php endif ?>
<form method="post" action="">
	<label>
		Username:<br>
		<input type="text" name="username" required autofocus>
	</label><br><br>
	<label>
		Password:<br>
		<input type="password" name="password" required>
	</label><br><br>
	<button type="submit">Login</button>
</form>
</body>
</html>