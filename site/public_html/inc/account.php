<?php
declare(strict_types=1);

/**
 * Account flows shared by staff (users) and customers (portal):
 * one-time tokens, remember-me, Google sign-in (OpenID Connect), signup,
 * e-mail verification, password reset and invitations.
 */

require_once INC_PATH . '/mail.php';

const TOKEN_TTL = ['reset' => 3600, 'invite' => 7 * 86400, 'verify' => 3 * 86400, 'remember' => 30 * 86400];
const REMEMBER_COOKIE = ['user' => 'ICREM_U', 'customer' => 'ICREM_C'];

/* ------------------------------------------------------------ passwords */

function password_problem(string $password): ?string
{
    if (mb_strlen($password) < 8) return 'A senha precisa ter pelo menos 8 caracteres.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) return 'Use letras e números na senha.';
    if (in_array(mb_strtolower($password), ['12345678', 'password', 'senha123', 'integra123', 'qwerty123'], true)) return 'Essa senha é muito comum. Escolha outra.';
    return null;
}

/* --------------------------------------------------------------- tokens */

/** Create a one-time token. Returns "selector.validator" (only the validator hash is stored). */
function auth_token_create(string $type, int $id, string $purpose, ?int $ttl = null): string
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db_insert('auth_tokens', [
        'subject_type' => $type, 'subject_id' => $id, 'purpose' => $purpose,
        'selector' => $selector, 'token_hash' => hash('sha256', $validator),
        'expires_at' => date('Y-m-d H:i:s', time() + ($ttl ?? TOKEN_TTL[$purpose] ?? 3600)),
        'ip' => client_ip(), 'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'created_at' => now(),
    ]);
    return $selector . '.' . $validator;
}

/** Validate a token; returns the row or null. Does not consume it. */
function auth_token_check(string $token, array $purposes): ?array
{
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $token, $m)) return null;
    $row = db_one('SELECT * FROM auth_tokens WHERE selector = ?', [$m[1]]);
    if (!$row || !in_array($row['purpose'], $purposes, true) || $row['used_at'] || $row['expires_at'] < now()) return null;
    return hash_equals($row['token_hash'], hash('sha256', $m[2])) ? $row : null;
}

function auth_token_consume(array $row): void
{
    db_exec('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [now(), $row['id']]);
}

function auth_tokens_revoke(string $type, int $id, array $purposes): void
{
    $ph = implode(',', array_fill(0, count($purposes), '?'));
    db_exec("UPDATE auth_tokens SET used_at = ? WHERE subject_type = ? AND subject_id = ? AND used_at IS NULL AND purpose IN ($ph)", array_merge([now(), $type, $id], $purposes));
}

/* ---------------------------------------------------------- remember me */

function auth_cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function remember_issue(string $type, int $id): void
{
    $token = auth_token_create($type, $id, 'remember');
    setcookie(REMEMBER_COOKIE[$type], $token, ['expires' => time() + TOKEN_TTL['remember'], 'path' => '/', 'secure' => auth_cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
}

function remember_forget(string $type): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE[$type]] ?? '';
    if ($cookie && ($row = auth_token_check($cookie, ['remember']))) auth_token_consume($row);
    setcookie(REMEMBER_COOKIE[$type], '', ['expires' => time() - 3600, 'path' => '/', 'secure' => auth_cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
}

/**
 * Restore a session from the remember-me cookie (rotating the token). Returns subject id or null.
 */
function remember_restore(string $type): ?int
{
    $cookie = $_COOKIE[REMEMBER_COOKIE[$type]] ?? '';
    if ($cookie === '') return null;
    $row = auth_token_check($cookie, ['remember']);
    if (!$row || $row['subject_type'] !== $type) {
        setcookie(REMEMBER_COOKIE[$type], '', ['expires' => time() - 3600, 'path' => '/']);
        return null;
    }
    auth_token_consume($row);
    remember_issue($type, (int)$row['subject_id']);
    return (int)$row['subject_id'];
}

/* ---------------------------------------------------------- sessions */

function login_customer(array $customer, bool $remember = false): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$customer['id'];
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db_exec('UPDATE customers SET portal_last_login_at = ? WHERE id = ?', [now(), $customer['id']]);
    if ($remember) remember_issue('customer', (int)$customer['id']);
}

function login_staff(array $user, bool $remember = false): void
{
    login_user($user);
    if ($remember) remember_issue('user', (int)$user['id']);
    audit('login', 'user', $user['id']);
}

function logout_everything(): void
{
    remember_forget('user');
    remember_forget('customer');
    logout_user();
}

/**
 * Password login for both audiences. Staff takes precedence when the same e-mail exists in both.
 * @return array{type:string,redirect:string}
 */
function login_with_password(string $email, string $password, bool $remember): array
{
    $email = mb_strtolower(trim($email));
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user && password_verify($password, $user['password_hash'])) {
        if (!(int)$user['active']) throw new AppException('Usuário desativado. Fale com o administrador.');
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) db_update('users', (int)$user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        login_staff($user, $remember);
        return ['type' => 'user', 'redirect' => '/admin/'];
    }
    $customer = db_one('SELECT * FROM customers WHERE email = ? AND portal_password_hash IS NOT NULL', [$email]);
    if ($customer && password_verify($password, $customer['portal_password_hash'])) {
        if (!(int)$customer['portal_enabled']) throw new AppException('Seu acesso à Área do Cliente está desativado. Fale com a nossa equipe.');
        if (!$customer['email_verified_at']) {
            send_verification_email($customer);
            throw new AppException('Confirme seu e-mail para entrar. Acabamos de reenviar o link de confirmação para ' . $email . '.');
        }
        login_customer($customer, $remember);
        return ['type' => 'customer', 'redirect' => '/cliente/'];
    }
    throw new AppException('E-mail ou senha incorretos.');
}

/* ---------------------------------------------------------- e-mails */

function send_verification_email(array $customer): void
{
    auth_tokens_revoke('customer', (int)$customer['id'], ['verify']);
    $token = auth_token_create('customer', (int)$customer['id'], 'verify');
    mail_queue($customer['email'], 'Confirme seu e-mail — ' . COMPANY['name'], mail_template('Confirme seu e-mail',
        '<p>Olá, ' . e(explode(' ', trim($customer['name']))[0]) . '! Falta só um passo para acessar a Área do Cliente da Integra Code.</p><p>Clique no botão abaixo para confirmar seu e-mail. O link vale por 3 dias.</p>',
        ['label' => 'Confirmar meu e-mail', 'url' => app_link('/verificar-email?t=' . $token)], 'Se você não criou esta conta, ignore esta mensagem.'), ['event' => 'verify_email', 'name' => $customer['name']]);
}

function send_password_link(string $type, array $subject, string $purpose): void
{
    auth_tokens_revoke($type, (int)$subject['id'], ['reset', 'invite']);
    $token = auth_token_create($type, (int)$subject['id'], $purpose);
    $first = e(explode(' ', trim($subject['name']))[0]);
    $area = $type === 'user' ? 'o painel administrativo' : 'a Área do Cliente';
    if ($purpose === 'invite') {
        $title = 'Seu acesso está pronto 🎉';
        $body = "<p>Olá, $first! Você recebeu acesso a <b>$area</b> da Integra Code" . ($type === 'customer' ? ', onde acompanha seus projetos, faturas e chamados' : '') . '.</p><p>Crie sua senha pelo botão abaixo (o link vale por 7 dias). Você também pode entrar com a sua conta Google usando este mesmo e-mail.</p>';
        $subjectLine = 'Seu acesso à ' . ($type === 'user' ? 'equipe' : 'Área do Cliente') . ' — ' . COMPANY['name'];
        $label = 'Criar minha senha';
    } else {
        $title = 'Redefinição de senha';
        $body = "<p>Olá, $first! Recebemos um pedido para redefinir a senha de acesso a <b>$area</b>.</p><p>O link abaixo vale por <b>1 hora</b> e só pode ser usado uma vez.</p>";
        $subjectLine = 'Redefinir sua senha — ' . COMPANY['name'];
        $label = 'Criar nova senha';
    }
    mail_queue($subject['email'], $subjectLine, mail_template($title, $body, ['label' => $label, 'url' => app_link('/redefinir-senha?t=' . $token)],
        'Se você não fez este pedido, ignore este e-mail — sua senha continua a mesma. Pedido feito do IP ' . client_ip() . '.'), ['event' => $purpose === 'invite' ? 'invite' : 'password_reset', 'name' => $subject['name']]);
}

/** Always behaves the same (prevents account enumeration). */
function request_password_reset(string $email): void
{
    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if ($user = db_one('SELECT * FROM users WHERE email = ? AND active = 1', [$email])) send_password_link('user', $user, 'reset');
    if ($customer = db_one('SELECT * FROM customers WHERE email = ? AND portal_enabled = 1', [$email])) send_password_link('customer', $customer, 'reset');
}

/**
 * Apply a new password from a reset/invite token and log the person in.
 * @return array{type:string,redirect:string}
 */
function complete_password_reset(string $token, string $password): array
{
    $row = auth_token_check($token, ['reset', 'invite']);
    if (!$row) throw new AppException('Este link expirou ou já foi usado. Peça um novo.');
    if ($problem = password_problem($password)) throw new AppException($problem);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    auth_token_consume($row);
    $id = (int)$row['subject_id'];
    if ($row['subject_type'] === 'user') {
        $user = db_find('users', $id);
        if (!$user || !(int)$user['active']) throw new AppException('Usuário indisponível.');
        db_update('users', $id, ['password_hash' => $hash]);
        auth_tokens_revoke('user', $id, ['reset', 'invite', 'remember']);
        login_staff($user);
        audit('password_reset', 'user', $id);
        return ['type' => 'user', 'redirect' => '/admin/'];
    }
    $customer = db_find('customers', $id);
    if (!$customer) throw new AppException('Cadastro indisponível.');
    db_update('customers', $id, ['portal_password_hash' => $hash, 'portal_enabled' => 1, 'email_verified_at' => $customer['email_verified_at'] ?: now(), 'updated_at' => now()]);
    auth_tokens_revoke('customer', $id, ['reset', 'invite', 'remember']);
    login_customer(db_find('customers', $id));
    return ['type' => 'customer', 'redirect' => '/cliente/'];
}

/* --------------------------------------------------------------- signup */

function signup_enabled(): bool
{
    return setting('portal_signup_enabled', '1') === '1';
}

/**
 * Customer self-signup. Returns a user-facing message.
 */
function signup_customer(array $in): string
{
    if (!signup_enabled()) throw new AppException('O cadastro está temporariamente fechado. Fale com a nossa equipe.');
    $name = trim((string)($in['name'] ?? ''));
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    $password = (string)($in['password'] ?? '');
    if (mb_strlen($name) < 3) throw new AppException('Informe seu nome completo.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppException('Informe um e-mail válido.');
    if ($problem = password_problem($password)) throw new AppException($problem);
    if (empty($in['consent'])) throw new AppException('É necessário aceitar a Política de Privacidade.');
    if (db_value('SELECT id FROM users WHERE email = ?', [$email])) throw new AppException('Este e-mail pertence à equipe. Use a opção "Entrar".');

    $existing = db_one('SELECT * FROM customers WHERE email = ?', [$email]);
    if ($existing) {
        if ($existing['portal_password_hash'] && $existing['email_verified_at']) throw new AppException('Você já tem uma conta. Entre com seu e-mail e senha ou recupere a senha.');
        // Existing CRM customer: prove e-mail ownership before granting access to their data.
        send_password_link('customer', $existing, 'invite');
        return 'Encontramos seu cadastro de cliente! Enviamos um link para ' . $email . ' para você criar sua senha com segurança.';
    }
    $id = db_insert('customers', [
        'name' => mb_substr((string)($in['company'] ?? '') ?: $name, 0, 160),
        'trade_name' => ($in['company'] ?? '') ? mb_substr($name, 0, 160) : null,
        'email' => $email, 'phone' => mb_substr((string)($in['phone'] ?? ''), 0, 30) ?: null,
        'status' => 'lead', 'portal_enabled' => 1, 'portal_password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'notes' => 'Conta criada pelo cadastro do site.', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $customer = db_find('customers', $id);
    send_verification_email($customer);
    mail_team('Nova conta na Área do Cliente: ' . $name, mail_template('Novo cadastro no site', mail_details(['Nome' => $name, 'Empresa' => $in['company'] ?? '', 'E-mail' => $email, 'Telefone' => $in['phone'] ?? '']), ['label' => 'Ver cliente', 'url' => app_link('/admin/#/customers/' . $id)]), ['event' => 'signup_team', 'reply_to' => $email]);
    return 'Conta criada! Enviamos um link de confirmação para ' . $email . '. Confirme para entrar.';
}

function verify_customer_email(string $token): array
{
    $row = auth_token_check($token, ['verify']);
    if (!$row || $row['subject_type'] !== 'customer') throw new AppException('Este link de confirmação expirou ou já foi usado. Entre com sua senha para receber um novo.');
    auth_token_consume($row);
    db_update('customers', (int)$row['subject_id'], ['email_verified_at' => now(), 'updated_at' => now()]);
    $customer = db_find('customers', (int)$row['subject_id']);
    login_customer($customer);
    return $customer;
}

/* ------------------------------------------------------ Google sign-in */

function google_enabled(): bool
{
    return setting('google_login_enabled', '0') === '1' && setting('google_client_id', '') !== '' && setting('google_client_secret', '') !== '';
}

function google_redirect_uri(): string
{
    return app_link('/google-login');
}

/** @param string $intent 'auto' | 'admin' */
function google_start(string $intent): string
{
    start_session();
    $_SESSION['google_oauth'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 'intent' => $intent, 'at' => time()];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('google_client_id'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['google_oauth']['state'],
        'nonce' => $_SESSION['google_oauth']['nonce'],
        'prompt' => 'select_account',
        'access_type' => 'online',
    ]);
}

function base64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/**
 * Handle the OAuth callback. The id_token comes straight from Google's token endpoint over TLS,
 * so per OpenID Connect Core §3.1.3.7 its signature check may be skipped; claims are still validated.
 * @return array{type:string,redirect:string,created?:bool}
 */
function google_finish(string $code, string $state): array
{
    start_session();
    $flow = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);
    if (!$flow || !hash_equals($flow['state'], $state) || time() - $flow['at'] > 600) throw new AppException('A sessão de login expirou. Tente entrar com o Google novamente.');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code, 'client_id' => setting('google_client_id'), 'client_secret' => setting('google_client_secret'),
        'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code',
    ])]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tok = $raw ? (json_decode($raw, true) ?? []) : [];
    if ($status !== 200 || empty($tok['id_token'])) {
        log_line('google', 'token exchange failed', ['status' => $status, 'error' => $tok['error'] ?? null]);
        throw new AppException('O Google não confirmou o login (' . ($tok['error_description'] ?? $tok['error'] ?? 'HTTP ' . $status) . ').');
    }
    $parts = explode('.', $tok['id_token']);
    $claims = json_decode(base64url_decode($parts[1] ?? ''), true) ?: [];
    $validIss = in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
    if (!$validIss || ($claims['aud'] ?? '') !== setting('google_client_id') || (int)($claims['exp'] ?? 0) < time() || !hash_equals($flow['nonce'], (string)($claims['nonce'] ?? ''))) {
        throw new AppException('Resposta do Google inválida. Tente novamente.');
    }
    if (empty($claims['email']) || empty($claims['email_verified'])) throw new AppException('Sua conta Google não tem um e-mail verificado.');

    $email = mb_strtolower($claims['email']);
    $sub = (string)$claims['sub'];
    $name = trim((string)($claims['name'] ?? '')) ?: explode('@', $email)[0];

    $user = db_one('SELECT * FROM users WHERE google_sub = ? OR email = ? ORDER BY google_sub = ? DESC LIMIT 1', [$sub, $email, $sub]);
    if ($user) {
        if (!(int)$user['active']) throw new AppException('Usuário desativado. Fale com o administrador.');
        db_update('users', (int)$user['id'], ['google_sub' => $sub, 'avatar_url' => mb_substr((string)($claims['picture'] ?? ''), 0, 255) ?: null]);
        login_staff($user, true);
        return ['type' => 'user', 'redirect' => '/admin/'];
    }
    if ($flow['intent'] === 'admin') throw new AppException("O e-mail $email não tem acesso ao painel administrativo.");

    $customer = db_one('SELECT * FROM customers WHERE google_sub = ? OR email = ? ORDER BY google_sub = ? DESC LIMIT 1', [$sub, $email, $sub]);
    $created = false;
    if (!$customer) {
        if (!signup_enabled()) throw new AppException('Não encontramos uma conta com este e-mail. Fale com a nossa equipe para liberar seu acesso.');
        $id = db_insert('customers', [
            'name' => mb_substr($name, 0, 160), 'email' => $email, 'status' => 'lead', 'portal_enabled' => 1,
            'google_sub' => $sub, 'email_verified_at' => now(), 'notes' => 'Conta criada com login Google.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customer = db_find('customers', $id);
        $created = true;
        mail_team('Nova conta (Google) na Área do Cliente: ' . $name, mail_template('Novo cadastro com Google', mail_details(['Nome' => $name, 'E-mail' => $email]), ['label' => 'Ver cliente', 'url' => app_link('/admin/#/customers/' . $id)]), ['event' => 'signup_team']);
    } else {
        // Google verified the e-mail, which proves ownership of the CRM record.
        db_update('customers', (int)$customer['id'], ['google_sub' => $sub, 'portal_enabled' => 1, 'email_verified_at' => $customer['email_verified_at'] ?: now(), 'updated_at' => now()]);
        $customer = db_find('customers', (int)$customer['id']);
    }
    login_customer($customer, true);
    return ['type' => 'customer', 'redirect' => '/cliente/' . ($created ? '?bemvindo=1' : ''), 'created' => $created];
}
