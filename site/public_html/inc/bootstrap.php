<?php
declare(strict_types=1);

/**
 * Application bootstrap: config, error handling, session, shared helpers.
 */

define('APP_ROOT', dirname(__DIR__));
define('INC_PATH', __DIR__);
define('STORAGE_PATH', APP_ROOT . '/storage');

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

$configFile = INC_PATH . '/config.php';
$GLOBALS['config'] = is_file($configFile) ? require $configFile : null;

function config(?string $key = null, $default = null)
{
    $cfg = $GLOBALS['config'] ?? [];
    if ($key === null) return $cfg;
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function is_installed(): bool
{
    return $GLOBALS['config'] !== null;
}

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

require_once INC_PATH . '/db.php';
require_once INC_PATH . '/crypto.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/settings.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $sessionDir = STORAGE_PATH . '/sessions';
    if (is_dir($sessionDir) && is_writable($sessionDir)) session_save_path($sessionDir);
    ini_set('session.gc_maxlifetime', '28800');
    session_name('ICSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function client_ip(): string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function money($value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

/** Portable accent removal (iconv TRANSLIT output varies between libc builds). */
function strip_accents(string $text): string
{
    static $map = null;
    if ($map === null) {
        $from = ['á','à','â','ã','ä','å','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ','ý','ÿ',
                 'Á','À','Â','Ã','Ä','Å','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ','Ý'];
        $to = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n','y','y',
               'A','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N','Y'];
        $map = array_combine($from, $to);
    }
    return strtr($text, $map);
}

function slugify(string $text): string
{
    $text = strip_accents($text);
    $text = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $text));
    return trim($text, '-') ?: bin2hex(random_bytes(4));
}

function only_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string)$value);
}

function log_line(string $channel, string $message, array $context = []): void
{
    $line = json_encode([
        'ts' => date('c'),
        'channel' => $channel,
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(STORAGE_PATH . '/logs/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Simple fixed-window throttle keyed by IP + bucket, stored in DB.
 */
function throttle(string $bucket, int $maxHits, int $windowSeconds): bool
{
    $key = $bucket . ':' . client_ip();
    $since = date('Y-m-d H:i:s', time() - $windowSeconds);
    db_exec('DELETE FROM rate_limits WHERE hit_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    $hits = (int)db_value('SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND hit_at >= ?', [$key, $since]);
    if ($hits >= $maxHits) return false;
    db_insert('rate_limits', ['rate_key' => $key, 'hit_at' => now()]);
    return true;
}

/** User-facing domain error (message is safe to show in pt-BR). */
class AppException extends RuntimeException {}

require_once INC_PATH . '/features.php';

// Automatic schema upgrade for existing installs (no shell access needed on Hostinger).
if (is_installed() && PHP_SAPI !== 'cli') {
    require_once INC_PATH . '/schema.php';
    if ((int)setting('schema_version', 1) < SCHEMA_VERSION) {
        run_migrations();
        set_setting('schema_version', (string)SCHEMA_VERSION);
    }
}
