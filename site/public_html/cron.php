<?php
declare(strict_types=1);

/**
 * Scheduled tasks: send queued e-mails, auto-close resolved tickets, housekeeping. Hostinger: hPanel → Avançado → Cron Jobs →
 *   php /home/USUARIO/domains/integra-code.tech/public_html/cron.php   (a cada 5 minutos)
 * Or by URL: https://integra-code.tech/cron.php?key=CHAVE (key shown in Configurações → E-mail).
 */

require __DIR__ . '/inc/bootstrap.php';
require INC_PATH . '/content.php';
require INC_PATH . '/mail.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)setting('cron_key', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
$closed = tickets_autoclose();
$billing = null;
if (setting('contracts_auto_billing', '0') === '1') {
    require_once INC_PATH . '/asaas.php';
    require_once INC_PATH . '/finance.php';
    require_once INC_PATH . '/pricing.php';
    $billing = contracts_bill_due((int)setting('contracts_lead_days', '10'));
}
// Integra Fiscal Hub: renewals, overdue subscriptions and recurring invoices (at most once per hour).
$fiscal = null;
if (setting('fh_cron_last', '') !== date('Y-m-d H') && (int)date('G') >= 6) {
    set_setting('fh_cron_last', date('Y-m-d H'));
    try {
        require_once INC_PATH . '/asaas.php';
        require_once INC_PATH . '/finance.php';
        require_once INC_PATH . '/ai.php';
        require_once INC_PATH . '/fiscalhub.php';
        $fiscal = ['billing' => fh_billing_run(), 'recurring' => fh_recurring_run(), 'finance' => fhf_cron()];
    } catch (Throwable $e) {
        log_line('fiscalhub', 'cron failed', ['error' => $e->getMessage()]);
        $fiscal = ['error' => $e->getMessage()];
    }
}
$result = mail_flush(50) + ['tickets_closed' => $closed, 'contracts' => $billing, 'fiscal_hub' => $fiscal];
db_exec('DELETE FROM rate_limits WHERE hit_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
db_exec('DELETE FROM auth_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 7)]);
echo date('c') . ' ' . json_encode($result) . PHP_EOL;
