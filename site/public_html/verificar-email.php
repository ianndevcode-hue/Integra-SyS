<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/auth.php';

try {
    verify_customer_email((string)($_GET['t'] ?? ''));
    header('Location: /cliente/?bemvindo=1');
    exit;
} catch (AppException $e) {
    auth_page_start('Confirmar e-mail', 'Não foi possível confirmar');
    auth_alert($e->getMessage());
    echo '<a class="btn btn-primary btn-block" href="/entrar">Entrar e reenviar confirmação</a>';
    auth_page_end();
}
