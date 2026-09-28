<?php
declare(strict_types=1);

/**
 * Session auth for staff (admin panel) and customers (client portal).
 */

const ROLES = [
    'admin' => ['*'],
    'finance' => ['dashboard', 'customers', 'finance', 'charges', 'partners'],
    'manager' => ['dashboard', 'customers', 'projects', 'leads', 'appointments', 'tickets', 'content'],
    'support' => ['dashboard', 'customers', 'tickets', 'leads', 'appointments', 'content'],
];

function current_user(): ?array
{
    start_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id && !empty($_COOKIE['ICREM_U'])) {
        require_once INC_PATH . '/account.php';
        if ($rid = remember_restore('user')) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id = $rid;
            if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }
    if (!$id) return null;
    static $user = null;
    if ($user === null || (int)$user['id'] !== (int)$id) {
        $user = db_one('SELECT id, name, email, role, active FROM users WHERE id = ?', [$id]);
        if (!$user || !(int)$user['active']) {
            unset($_SESSION['user_id']);
            return null;
        }
    }
    return $user;
}

function can(string $area, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) return false;
    $perms = ROLES[$user['role']] ?? [];
    return in_array('*', $perms, true) || in_array($area, $perms, true);
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db_exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    start_session();
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function current_customer(): ?array
{
    start_session();
    $id = $_SESSION['customer_id'] ?? null;
    if (!$id && !empty($_COOKIE['ICREM_C'])) {
        require_once INC_PATH . '/account.php';
        if ($rid = remember_restore('customer')) {
            session_regenerate_id(true);
            $_SESSION['customer_id'] = $id = $rid;
        }
    }
    if (!$id) return null;
    $customer = db_one('SELECT id, name, email, trade_name, google_sub, portal_password_hash IS NOT NULL AS has_password FROM customers WHERE id = ? AND portal_enabled = 1', [$id]);
    if (!$customer) unset($_SESSION['customer_id']);
    return $customer;
}

function audit(string $action, string $entity, $entityId = null, $details = null): void
{
    try {
        $user = current_user();
        db_insert('audit_log', [
            'user_id' => $user['id'] ?? null,
            'user_name' => $user['name'] ?? 'sistema',
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId !== null ? (string)$entityId : null,
            'details' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip' => client_ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        log_line('audit', 'failed', ['error' => $e->getMessage()]);
    }
}
