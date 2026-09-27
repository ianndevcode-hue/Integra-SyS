<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/auth.php';

redirect_if_logged_in();
$error = null;
$notice = ['conta-criada' => 'Conta criada! Confirme seu e-mail pelo link que enviamos e depois entre.', 'senha-alterada' => 'Senha alterada. Entre com a nova senha.', 'saiu' => 'Você saiu da sua conta.'][$_GET['ok'] ?? ''] ?? null;
$googleError = isset($_GET['erro']) ? mb_substr((string)$_GET['erro'], 0, 200) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf($_POST['csrf'] ?? null)) throw new AppException('Sessão expirada. Tente novamente.');
        if (!throttle('login-page', 8, 900)) throw new AppException('Muitas tentativas. Aguarde 15 minutos ou redefina sua senha.');
        $res = login_with_password((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), !empty($_POST['remember']));
        $next = (string)($_POST['next'] ?? '');
        header('Location: ' . ($res['type'] === 'customer' && str_starts_with($next, '/cliente') ? $next : $res['redirect']));
        exit;
    } catch (AppException $e) {
        $error = $e->getMessage();
    }
}

auth_page_start('Entrar', 'Entrar na sua conta', 'Clientes acompanham projetos, faturas e chamados. A equipe acessa o painel de gestão.');
auth_alert($error ?? $googleError);
auth_alert($notice, 'success');
echo google_button();
?>
      <form method="post" class="form" novalidate data-auth-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e((string)($_GET['next'] ?? '')) ?>">
        <div class="field"><label for="l-email">E-mail</label><input id="l-email" type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>" autofocus></div>
        <div class="field">
          <label for="l-pass" style="display:flex;justify-content:space-between">Senha <a href="/recuperar-senha" class="auth-link" style="font-weight:500">Esqueci minha senha</a></label>
          <div class="pass-wrap"><input id="l-pass" type="password" name="password" required autocomplete="current-password"><button type="button" class="pass-toggle" aria-label="Mostrar senha" data-toggle-pass>👁</button></div>
        </div>
        <label class="consent"><input type="checkbox" name="remember" value="1"> <span>Manter conectado neste dispositivo (30 dias)</span></label>
        <button class="btn btn-primary btn-block btn-lg">Entrar <?= icon('arrow') ?></button>
      </form>
      <?php if (signup_enabled()): ?><p class="auth-foot">Ainda não tem conta? <a href="/cadastro" class="auth-link">Criar conta de cliente</a></p><?php endif; ?>
<?php auth_page_end();
