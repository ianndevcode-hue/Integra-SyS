<?php
declare(strict_types=1);

/**
 * Google sign-in (OpenID Connect). Start: /google-login?intent=auto|admin — Callback: /google-login?code=...&state=...
 * The callback URL must be registered in Google Cloud as an "Authorized redirect URI".
 */

require __DIR__ . '/inc/bootstrap.php';
require INC_PATH . '/content.php';
require INC_PATH . '/account.php';

$fail = function (string $message, string $intent = 'auto') {
    header('Location: ' . ($intent === 'admin' ? '/admin/?erro=' : '/entrar?erro=') . rawurlencode($message));
    exit;
};

if (!google_enabled()) $fail('O login com Google não está ativado.');

if (isset($_GET['error'])) {
    $fail($_GET['error'] === 'access_denied' ? 'Login com Google cancelado.' : 'O Google retornou um erro: ' . mb_substr((string)$_GET['error'], 0, 80));
}

if (isset($_GET['code'], $_GET['state'])) {
    start_session();
    $intent = $_SESSION['google_oauth']['intent'] ?? 'auto';
    if (!throttle('google-callback', 20, 900)) $fail('Muitas tentativas. Aguarde alguns minutos.', $intent);
    try {
        $res = google_finish((string)$_GET['code'], (string)$_GET['state']);
        header('Location: ' . $res['redirect']);
    } catch (AppException $e) {
        $fail($e->getMessage(), $intent);
    }
    exit;
}

header('Location: ' . google_start(($_GET['intent'] ?? '') === 'admin' ? 'admin' : 'auto'));
