<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require INC_PATH . '/content.php';
require INC_PATH . '/account.php';

// CSRF-protected logout: POST with token, or GET with ?t=token (links in menus).
if (verify_csrf($_POST['csrf'] ?? $_GET['t'] ?? null)) {
    logout_everything();
    header('Location: /entrar?ok=saiu');
} else {
    header('Location: /');
}
