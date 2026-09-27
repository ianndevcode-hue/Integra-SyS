<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/auth.php';

redirect_if_logged_in();
if (!signup_enabled()) { header('Location: /entrar'); exit; }
$error = null;
$done = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf($_POST['csrf'] ?? null)) throw new AppException('Sessão expirada. Tente novamente.');
        if (!empty($_POST['website'])) throw new AppException('Não foi possível concluir.');
        if (!throttle('signup', 5, 3600)) throw new AppException('Muitos cadastros deste endereço. Tente mais tarde.');
        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) throw new AppException('As senhas não conferem.');
        $done = signup_customer($_POST);
    } catch (AppException $e) {
        $error = $e->getMessage();
    }
}

auth_page_start('Criar conta', $done ? 'Verifique seu e-mail 📬' : 'Criar conta de cliente', $done ? '' : 'Acompanhe seus projetos, faturas e chamados com a Integra Code.');
if ($done): ?>
      <div class="auth-alert success"><?= e($done) ?></div>
      <p class="muted">Não chegou? Confira a caixa de spam ou <a class="auth-link" href="/entrar">entre com seu e-mail e senha</a> para reenviar a confirmação.</p>
      <a class="btn btn-ghost btn-block" href="/entrar">Ir para o login</a>
<?php else:
auth_alert($error);
echo google_button('auto', 'Criar conta com Google');
?>
      <form method="post" class="form" novalidate data-auth-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="form-row">
          <div class="field"><label for="s-name">Seu nome*</label><input id="s-name" name="name" required autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></div>
          <div class="field"><label for="s-company">Empresa</label><input id="s-company" name="company" autocomplete="organization" value="<?= e($_POST['company'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="s-email">E-mail*</label><input id="s-email" type="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="field"><label for="s-phone">WhatsApp</label><input id="s-phone" name="phone" data-mask="phone" inputmode="tel" autocomplete="tel" value="<?= e($_POST['phone'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="s-pass">Senha*</label><div class="pass-wrap"><input id="s-pass" type="password" name="password" required minlength="8" autocomplete="new-password" data-strength><button type="button" class="pass-toggle" aria-label="Mostrar senha" data-toggle-pass>👁</button></div><div class="strength" aria-hidden="true"><i></i></div><span class="help muted" style="font-size:.8rem">Mínimo 8 caracteres, com letras e números.</span></div>
        <div class="field"><label for="s-pass2">Confirmar senha*</label><input id="s-pass2" type="password" name="password_confirm" required autocomplete="new-password"></div>
        <label class="consent"><input type="checkbox" name="consent" value="1" required> <span>Li e concordo com a <a href="/privacidade" target="_blank">Política de Privacidade</a>.</span></label>
        <button class="btn btn-primary btn-block btn-lg">Criar conta <?= icon('arrow') ?></button>
      </form>
      <p class="auth-foot">Já tem conta? <a class="auth-link" href="/entrar">Entrar</a></p>
<?php endif;
auth_page_end();
