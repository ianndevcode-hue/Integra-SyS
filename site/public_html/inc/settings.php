<?php
declare(strict_types=1);

/**
 * Key/value settings persisted in the `settings` table.
 * Keys listed in SECRET_SETTINGS are encrypted at rest.
 */

const SECRET_SETTINGS = ['asaas_api_key', 'asaas_webhook_token', 'nfse_cert_pfx', 'nfse_cert_password', 'cloudflare_api_token', 'mail_password', 'mail_cf_token', 'google_client_secret', 'nfse_sigiss_password', 'search_api_key', 'fh_pluggy_client_secret', 'fh_pluggy_api_key', 'fh_pluggy_webhook_token'];

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    if (func_num_args() === 3) { // internal cache reset hook
        $cache = null;
        return null;
    }
    if (!array_key_exists($key, $cache)) return $default;
    $value = $cache[$key];
    if (in_array($key, SECRET_SETTINGS, true)) $value = decrypt_secret($value);
    return $value === '' || $value === null ? $default : $value;
}

function set_setting(string $key, $value): void
{
    $value = (string)$value;
    if (in_array($key, SECRET_SETTINGS, true) && $value !== '') $value = encrypt_secret($value);
    $exists = db_value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);
    if ($exists) {
        db_exec('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    } else {
        db_insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
    setting('', null, true);
}
