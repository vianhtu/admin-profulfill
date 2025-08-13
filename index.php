<?php
// index.php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (is_logged_in() || attempt_cookie_login()) {
	header('Location: ./dashboards.php', true, 302);
	exit;
}

header('Location: ./html/vertical-menu-template-no-customizer/auth-login-basic.php', true, 302);
exit;