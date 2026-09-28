<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — financial module API (/fh/fin/...), scoped to the logged portal customer.
 * Reading needs any Fiscal Hub subscription; changes need an active one (same rule as emission).
 * Loaded by api/index.php right after fiscalhub_routes.php (uses fh_guard / fh_send_file).
 */

/** [customer id, access] for reads; bootstraps default categories and the main account. */
function fhf_guard(): array
{
    [$cid, $a] = fh_guard();
    fhf_bootstrap($cid);
    return [$cid, $a];
}

/** Same, but blocks changes while the subscription is unpaid, expired, suspended or canceled. */
function fhf_guard_write(): array
{
    [$cid, $a] = fhf_guard();
    if (!$a['can_emit']) json_error($a['message'] ?: 'Assinatura inativa: o financeiro está disponível só para consulta.', 402);
    return [$cid, $a];
}

function fhf_of_allowed(array $access): bool
{
    return fhf_of_enabled() && !empty($access['plan']['flags']['open_finance']);
}

route('GET', '/fh/fin/bootstrap', function () {
    [$cid, $a] = fhf_guard();
    json_out(['accounts' => fhf_accounts($cid), 'categories' => fhf_categories($cid), 'settings' => fhf_settings($cid), 'account_kinds' => FHF_ACCOUNT_KINDS, 'methods' => FHF_METHODS, 'dre_groups' => FHF_DRE,
        'can_edit' => (bool)$a['can_emit'], 'openfinance' => ['enabled' => fhf_of_enabled(), 'allowed' => fhf_of_allowed($a)], 'connections' => (int)db_value('SELECT COUNT(*) FROM fh_fin_connections WHERE customer_id = ?', [$cid]),
        'emitters' => db_all('SELECT id, legal_name, trade_name FROM fh_emitters WHERE customer_id = ? AND active = 1 ORDER BY legal_name', [$cid])]);
});

route('GET', '/fh/fin/summary', function () {
    [$cid] = fhf_guard();
    json_out(fhf_summary($cid));
});

route('GET', '/fh/fin/cashflow', function () {
    [$cid] = fhf_guard();
    $from = fhf_date($_GET['from'] ?? '', date('Y-m-01'));
    $to = fhf_date($_GET['to'] ?? '', date('Y-m-d', strtotime($from . ' +89 days')));
    $acc = !empty($_GET['account_id']) ? (int)fhf_account($cid, (int)$_GET['account_id'])['id'] : null;
    json_out(fhf_cashflow($cid, $from, $to, (string)($_GET['group'] ?? 'day'), $acc));
});

route('GET', '/fh/fin/dre', function () {
    [$cid] = fhf_guard();
    $from = fhf_date($_GET['from'] ?? '', date('Y-01-01'));
    $to = fhf_date($_GET['to'] ?? '', date('Y-m-t'));
    json_out(fhf_dre($cid, $from, $to, (string)($_GET['basis'] ?? 'cash')));
});

/* ------------------------------------------------------------- entries */

route('GET', '/fh/fin/entries', function () {
    [$cid] = fhf_guard();
    json_out(fhf_entries_query($cid, $_GET));
});
route('GET', '/fh/fin/entries.csv', function () {
    [$cid] = fhf_guard();
    $kind = ($_GET['kind'] ?? '') === 'payable' ? 'contas-a-pagar' : (($_GET['kind'] ?? '') === 'receivable' ? 'contas-a-receber' : 'lancamentos');
    fh_send_file($kind . '-' . date('Y-m-d') . '.csv', fhf_entries_csv(fhf_entries_query($cid, $_GET, false)), 'text/csv; charset=utf-8');
});
route('POST', '/fh/fin/entries', function () {
    [$cid] = fhf_guard_write();
    json_out(['data' => fhf_entry_create($cid, input())], 201);
});
route('GET', '/fh/fin/entries/{id}', function ($p) {
    [$cid] = fhf_guard();
    $e = fhf_entry_public(fhf_entry($cid, (int)$p['id']));
    $e['series'] = $e['series_key'] ? array_map('fhf_entry_public', db_all('SELECT * FROM fh_fin_entries WHERE series_key = ? AND customer_id = ? ORDER BY due_date, id', [$e['series_key'], $cid])) : [];
    $e['transaction'] = $e['transaction_id'] ? db_one('SELECT t.*, a.name AS account_name FROM fh_fin_transactions t JOIN fh_fin_accounts a ON a.id = t.account_id WHERE t.id = ?', [$e['transaction_id']]) : null;
    $e['invoice'] = $e['invoice_id'] ? db_one('SELECT id, nfse_number, dps_number, status, amount, net_amount, issued_at FROM fh_invoices WHERE id = ? AND customer_id = ?', [$e['invoice_id'], $cid]) : null;
    json_out($e);
});
route('PUT', '/fh/fin/entries/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_entry_update($cid, (int)$p['id'], input()));
});
route('DELETE', '/fh/fin/entries/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(['deleted' => fhf_entry_delete($cid, (int)$p['id'], (string)($_GET['scope'] ?? 'one'))]);
});
route('POST', '/fh/fin/entries/{id}/pay', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_entry_pay($cid, (int)$p['id'], input()));
});
route('POST', '/fh/fin/entries/{id}/reopen', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_entry_reopen($cid, (int)$p['id']));
});
route('POST', '/fh/fin/entries/{id}/cancel', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_entry_cancel($cid, (int)$p['id']));
});
route('POST', '/fh/fin/entries/bulk', function () {
    [$cid] = fhf_guard_write();
    $in = input();
    $ids = array_slice(array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))), 0, 300);
    if (!$ids) json_error('Selecione os lançamentos.', 422);
    $action = (string)($in['action'] ?? '');
    $ok = 0;
    $errors = [];
    foreach ($ids as $id) {
        try {
            if ($action === 'pay') fhf_entry_pay($cid, $id, ['paid_at' => $in['paid_at'] ?? today(), 'account_id' => $in['account_id'] ?? null, 'payment_method' => $in['payment_method'] ?? null]);
            elseif ($action === 'delete') fhf_entry_delete($cid, $id);
            elseif ($action === 'category') fhf_entry_update($cid, $id, ['category_id' => $in['category_id'] ?? null]);
            else throw new AppException('Ação inválida.');
            $ok++;
        } catch (AppException $e) {
            $errors[] = "#$id: " . $e->getMessage();
        }
    }
    json_out(['ok' => $ok, 'errors' => $errors]);
});
route('GET', '/fh/fin/parties', function () {
    [$cid] = fhf_guard();
    json_out(['data' => fhf_parties($cid, trim((string)($_GET['q'] ?? '')), in_array($_GET['kind'] ?? '', ['receivable', 'payable'], true) ? $_GET['kind'] : '')]);
});

/* ------------------------------------------------- accounts & categories */

route('GET', '/fh/fin/accounts', function () {
    [$cid] = fhf_guard();
    json_out(['data' => fhf_accounts($cid), 'imports' => db_all('SELECT i.*, a.name AS account_name FROM fh_fin_imports i JOIN fh_fin_accounts a ON a.id = i.account_id WHERE i.customer_id = ? ORDER BY i.id DESC LIMIT 30', [$cid])]);
});
route('POST', '/fh/fin/accounts', function () {
    [$cid] = fhf_guard_write();
    json_out(fhf_account_save($cid, input(), null), 201);
});
route('PUT', '/fh/fin/accounts/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_account_save($cid, input(), fhf_account($cid, (int)$p['id'])));
});
route('DELETE', '/fh/fin/accounts/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(['result' => fhf_account_delete($cid, (int)$p['id'])]);
});
route('POST', '/fh/fin/transfers', function () {
    [$cid] = fhf_guard_write();
    json_out(fhf_transfer($cid, input()), 201);
});
route('GET', '/fh/fin/categories', function () {
    [$cid] = fhf_guard();
    json_out(['data' => fhf_categories($cid)]);
});
route('POST', '/fh/fin/categories', function () {
    [$cid] = fhf_guard_write();
    json_out(fhf_category_save($cid, input(), null), 201);
});
route('PUT', '/fh/fin/categories/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_category_save($cid, input(), fhf_category($cid, (int)$p['id'])));
});
route('DELETE', '/fh/fin/categories/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(['result' => fhf_category_delete($cid, (int)$p['id'])]);
});
route('GET', '/fh/fin/settings', function () {
    [$cid] = fhf_guard();
    json_out(fhf_settings($cid));
});
route('PUT', '/fh/fin/settings', function () {
    [$cid] = fhf_guard_write();
    json_out(fhf_settings_save($cid, input()));
});
route('POST', '/fh/fin/invoices/import', function () {
    [$cid] = fhf_guard_write();
    json_out(['created' => fhf_import_invoices($cid)]);
});

/* ------------------------------------------------ statement & reconciliation */

route('GET', '/fh/fin/transactions', function () {
    [$cid] = fhf_guard();
    json_out(fhf_transactions($cid, $_GET));
});
route('POST', '/fh/fin/import', function () {
    [$cid] = fhf_guard_write();
    if (!throttle('fhf-import-' . $cid, 60, 3600)) json_error('Muitas importações seguidas. Aguarde alguns minutos.', 429);
    $in = input();
    $content = isset($in['content_b64']) ? (string)base64_decode((string)$in['content_b64'], true) : (string)($in['content'] ?? '');
    if ($content === '' || strlen($content) > 8 * 1024 * 1024) json_error('Envie o arquivo do extrato (até 8 MB).', 422);
    $name = mb_substr(basename((string)($in['file_name'] ?? 'extrato')), 0, 190);
    $format = ($in['format'] ?? '') === 'csv' || preg_match('/\.(csv|txt)$/i', $name) && stripos($content, '<OFX') === false ? 'csv' : 'ofx';
    $parsed = $format === 'csv' ? fhf_csv_parse($content) : fhf_ofx_parse($content);
    json_out(fhf_import_statement($cid, (int)fhf_account($cid, (int)($in['account_id'] ?? 0))['id'], $format, $parsed, $name) + ['format' => $format, 'period' => [$parsed['start'], $parsed['end']], 'balance' => $parsed['balance']]);
});
route('DELETE', '/fh/fin/imports/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(['removed' => fhf_import_undo($cid, (int)$p['id'])]);
});
route('GET', '/fh/fin/transactions/{id}/candidates', function ($p) {
    [$cid] = fhf_guard();
    $tx = fhf_tx($cid, (int)$p['id']);
    $rules = db_all('SELECT r.*, c.name AS category_name FROM fh_fin_rules r LEFT JOIN fh_fin_categories c ON c.id = r.category_id WHERE r.customer_id = ?', [$cid]);
    json_out(['transaction' => fhf_tx_public($tx), 'data' => fhf_candidates($cid, $tx, trim((string)($_GET['q'] ?? '')), 15), 'rule' => fhf_rule_for($rules, $tx)]);
});
route('POST', '/fh/fin/transactions/{id}/reconcile', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_reconcile($cid, (int)$p['id'], (array)(input()['entry_ids'] ?? [])));
});
route('POST', '/fh/fin/transactions/{id}/create-entry', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_tx_create_entry($cid, (int)$p['id'], input()));
});
route('POST', '/fh/fin/transactions/{id}/unlink', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_tx_unlink($cid, (int)$p['id']));
});
route('POST', '/fh/fin/transactions/{id}/ignore', function ($p) {
    [$cid] = fhf_guard_write();
    json_out(fhf_tx_ignore($cid, (int)$p['id'], filter_var(input()['ignore'] ?? true, FILTER_VALIDATE_BOOLEAN)));
});
route('POST', '/fh/fin/reconcile/auto', function () {
    [$cid] = fhf_guard_write();
    $in = input();
    json_out(fhf_auto_reconcile($cid, !empty($in['account_id']) ? (int)fhf_account($cid, (int)$in['account_id'])['id'] : null));
});
route('GET', '/fh/fin/rules', function () {
    [$cid] = fhf_guard();
    json_out(['data' => db_all('SELECT r.*, c.name AS category_name FROM fh_fin_rules r LEFT JOIN fh_fin_categories c ON c.id = r.category_id WHERE r.customer_id = ? ORDER BY r.hits DESC, r.id DESC', [$cid])]);
});
route('DELETE', '/fh/fin/rules/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    db_exec('DELETE FROM fh_fin_rules WHERE id = ? AND customer_id = ?', [(int)$p['id'], $cid]);
    json_out(['ok' => true]);
});

/* ------------------------------------------ bank connections (automatic statements) */

route('GET', '/fh/fin/connections', function () {
    [$cid, $a] = fhf_guard();
    $mp = fhf_mp_creds($cid);
    json_out(['data' => array_map('fhf_conn_public', db_all('SELECT * FROM fh_fin_connections WHERE customer_id = ? ORDER BY id DESC', [$cid])),
        'providers' => FHF_PROVIDERS, 'meupluggy' => ['configured' => (bool)$mp, 'client_id' => $mp ? substr($mp['client_id'], 0, 8) . '…' : null],
        'openfinance' => ['enabled' => fhf_of_enabled(), 'allowed' => fhf_of_allowed($a)], 'script' => 'https://cdn.pluggy.ai/pluggy-connect/latest/pluggy-connect.js',
        'webhook_ready' => str_starts_with((string)config('app_url'), 'https://')]);
});
route('POST', '/fh/fin/connections/{id}/sync', function ($p) {
    [$cid, $a] = fhf_guard_write();
    $conn = fhf_conn($cid, (int)$p['id']);
    if ($conn['provider'] === 'pluggy' && !fhf_of_allowed($a)) json_error('A conexão Open Finance da Integra não está disponível no seu plano.', 403);
    if (!throttle('fhf-sync-' . $cid, 30, 3600)) json_error('Muitas sincronizações seguidas. Aguarde alguns minutos.', 429);
    set_time_limit(240);
    json_out(fhf_conn_sync($conn));
});
route('DELETE', '/fh/fin/connections/{id}', function ($p) {
    [$cid] = fhf_guard_write();
    fhf_conn_delete($cid, (int)$p['id']);
    json_out(['ok' => true]);
});
// Banco Inter PJ (official API, free for Inter business accounts)
route('POST', '/fh/fin/connections/inter', function () {
    [$cid] = fhf_guard_write();
    if (!throttle('fhf-conn-' . $cid, 20, 3600)) json_error('Muitas tentativas seguidas. Aguarde alguns minutos.', 429);
    $in = input();
    $existing = !empty($in['connection_id']) ? fhf_conn($cid, (int)$in['connection_id']) : null;
    set_time_limit(240);
    json_out(fhf_inter_connect($cid, $in, $existing), 201);
});
// Asaas (the customer's own account)
route('POST', '/fh/fin/connections/asaas', function () {
    [$cid] = fhf_guard_write();
    if (!throttle('fhf-conn-' . $cid, 20, 3600)) json_error('Muitas tentativas seguidas. Aguarde alguns minutos.', 429);
    $in = input();
    $existing = !empty($in['connection_id']) ? fhf_conn($cid, (int)$in['connection_id']) : null;
    set_time_limit(240);
    json_out(fhf_asaas_connect($cid, $in, $existing), 201);
});
// Open Finance: free with the customer's own Meu Pluggy app, or through the Integra aggregator account
route('PUT', '/fh/fin/meupluggy', function () {
    [$cid] = fhf_guard_write();
    if (!throttle('fhf-conn-' . $cid, 20, 3600)) json_error('Muitas tentativas seguidas. Aguarde alguns minutos.', 429);
    $in = input();
    json_out(fhf_mp_save($cid, (string)($in['client_id'] ?? ''), (string)($in['client_secret'] ?? '')));
});
route('DELETE', '/fh/fin/meupluggy', function () {
    [$cid] = fhf_guard_write();
    set_setting('fhf_mp_' . $cid, '');
    json_out(['ok' => true]);
});
route('POST', '/fh/fin/openfinance/token', function () {
    [$cid, $a] = fhf_guard_write();
    $in = input();
    $provider = ($in['provider'] ?? 'pluggy') === 'meupluggy' ? 'meupluggy' : 'pluggy';
    if ($provider === 'pluggy' && !fhf_of_allowed($a)) json_error(fhf_of_enabled() ? 'A conexão Open Finance da Integra não está incluída no seu plano. Use o Meu Pluggy (grátis), a API do seu banco ou o OFX.' : 'Use o Meu Pluggy (grátis), a API do seu banco ou a importação do OFX.', 403);
    if (!throttle('fhf-of-token-' . $cid, 30, 3600)) json_error('Muitas tentativas seguidas. Aguarde alguns minutos.', 429);
    $conn = !empty($in['connection_id']) ? fhf_conn($cid, (int)$in['connection_id']) : null;
    json_out(fhf_of_connect_token($cid, $provider, $conn));
});
route('POST', '/fh/fin/openfinance/items', function () {
    [$cid, $a] = fhf_guard_write();
    $in = input();
    $provider = ($in['provider'] ?? 'pluggy') === 'meupluggy' ? 'meupluggy' : 'pluggy';
    if ($provider === 'pluggy' && !fhf_of_allowed($a)) json_error('Open Finance da Integra indisponível no seu plano.', 403);
    set_time_limit(240);
    json_out(fhf_of_register_item($cid, trim((string)($in['item_id'] ?? '')), $provider), 201);
});

// Pluggy webhook (item/updated, transactions/created...): authenticated by the secret token in the URL.
route('POST', '/fh/openfinance/webhook', function () {
    $token = (string)setting('fh_pluggy_webhook_token', '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) json_error('forbidden', 403);
    $in = input();
    $itemId = (string)($in['itemId'] ?? ($in['item']['id'] ?? ''));
    $conn = $itemId !== '' ? db_one("SELECT * FROM fh_fin_connections WHERE item_id = ? AND provider = 'pluggy'", [$itemId]) : null;
    if ($conn && $conn['provider'] === 'pluggy' && fhf_of_enabled()) {
        try { fhf_conn_sync($conn); } catch (Throwable $e) { log_line('fiscalhub', 'pluggy webhook sync failed', ['item' => $itemId, 'error' => $e->getMessage()]); }
    }
    json_out(['ok' => true]);
});

// Team: check the aggregator credentials.
route('POST', '/fh-admin/openfinance/test', function () {
    guard('users');
    set_setting('fh_pluggy_api_key', '');
    fhf_pluggy_key(null, true);
    $connectors = fhf_pluggy('GET', '/connectors?countries=BR&isOpenFinance=true');
    json_out(['ok' => true, 'connectors' => (int)($connectors['total'] ?? count($connectors['results'] ?? [])), 'webhook' => fhf_of_webhook_url(),
        'connections' => (int)db_value('SELECT COUNT(*) FROM fh_fin_connections')]);
});
