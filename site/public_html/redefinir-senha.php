<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/auth.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$row = auth_token_check($token, ['reset', 'invite']);
$error = null;

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf($_POST['csrf'] ?? null)) throw new AppException('Sessão expirada. Tente novamente.');
        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) throw new AppException('As senhas não conferem.');
        $res = complete_password_reset($token, (string)$_POST['password']);
        header('Location: ' . $res['redirect']);
        exit;
    } catch (AppException $e) {
        $error = $e->getMessage();
    }
}

$isInvite = $row && $row['purpose'] === 'invite';
$email = $row ? (string)db_value($row['subject_type'] === 'user' ? 'SELECT email FROM users WHERE id = ?' : 'SELECT email FROM customers WHERE id = ?', [$row['subject_id']]) : '';
auth_page_start($isInvite ? 'Criar senha' : 'Nova senha', !$row ? 'Link inválido' : ($isInvite ? 'Crie sua senha de acesso' : 'Crie uma nova senha'), $row ? 'Conta: <b>' . e($email) . '</b>' : '');
if (!$row): ?>
      <div class="auth-alert error">Este link expirou ou já foi usado. Por segurança, links de senha valem por tempo limitado e só funcionam uma vez.</div>
      <a class="btn btn-primary btn-block" href="/recuperar-senha">Pedir um novo link</a>
<?php else:
auth_alert($error); ?>
      <form method="post" class="form" novalidate data-auth-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <input type="email" name="username" value="<?= e($email) ?>" autocomplete="username" hidden>
        <div class="field"><label for="r-pass">Nova senha</label><div class="pass-wrap"><input id="r-pass" type="password" name="password" required minlength="8" autocomplete="new-password" data-strength autofocus><button type="button" class="pass-toggle" aria-label="Mostrar senha" data-toggle-pass>👁</button></div><div class="strength" aria-hidden="true"><i></i></div><span class="help muted" style="font-size:.8rem">Mínimo 8 caracteres, com letras e números.</span></div>
        <div class="field"><label for="r-pass2">Confirmar senha</label><input id="r-pass2" type="password" name="password_confirm" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block btn-lg"><?= $isInvite ? 'Criar senha e entrar' : 'Salvar e entrar' ?> <?= icon('arrow') ?></button>
      </form>
<?php endif;
auth_page_end();
