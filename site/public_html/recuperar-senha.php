<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/auth.php';

$error = null;
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf($_POST['csrf'] ?? null)) throw new AppException('Sessão expirada. Tente novamente.');
        if (!throttle('forgot', 5, 900)) throw new AppException('Muitos pedidos. Aguarde alguns minutos.');
        if (!filter_var((string)($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL)) throw new AppException('Informe um e-mail válido.');
        request_password_reset((string)$_POST['email']);
        $sent = true;
    } catch (AppException $e) {
        $error = $e->getMessage();
    }
}

auth_page_start('Recuperar senha', $sent ? 'Confira seu e-mail 📬' : 'Esqueceu a senha?', $sent ? '' : 'Informe o e-mail da sua conta. Enviaremos um link para você criar uma nova senha.');
if ($sent): ?>
      <div class="auth-alert success">Se <b><?= e($_POST['email']) ?></b> tiver uma conta, você receberá um link em instantes. Ele vale por 1 hora.</div>
      <p class="muted">Não chegou? Verifique o spam. Se entrou antes com o Google, use o botão "Continuar com Google".</p>
      <a class="btn btn-ghost btn-block" href="/entrar">Voltar para o login</a>
<?php else:
auth_alert($error); ?>
      <form method="post" class="form" novalidate data-auth-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="field"><label for="f-email">E-mail</label><input id="f-email" type="email" name="email" required autocomplete="email" autofocus></div>
        <button class="btn btn-primary btn-block btn-lg">Enviar link <?= icon('send') ?></button>
      </form>
      <p class="auth-foot"><a class="auth-link" href="/entrar">← Voltar para o login</a></p>
<?php endif;
auth_page_end();
