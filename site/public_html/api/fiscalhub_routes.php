<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub API.
 *   /fh/...        customer app (portal session + CSRF), scoped to the logged customer
 *   /fh/checkout   public online contracting
 *   /fh-admin/...  Integra Code team
 * Loaded by api/index.php before the generic CRUD routes.
 */

function fh_customer_guard(): array
{
    $c = current_customer();
    if (!$c) json_error('Sessão expirada. Entre novamente na Área do Cliente.', 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_error('Token de segurança inválido. Recarregue a página.', 419);
    return $c;
}

/** Customer with a subscription (any state): can view, configure and download. */
function fh_guard(): array
{
    $c = fh_customer_guard();
    $a = fh_access((int)$c['id']);
    if (!$a['can_view']) json_error('Contrate um plano do ' . FH_NAME . ' para usar o emissor.', 402);
    return [(int)$c['id'], $a, $c];
}

function fh_owned_emitter(int $cid, $id): array
{
    return fh_emitter($cid, (int)$id);
}

function fh_send_file(string $name, string $content, string $type): void
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
}

/* ------------------------------------------------------------------ public */

route('POST', '/fh/checkout', function () {
    if (!throttle('fh-checkout', 10, 3600)) json_error('Muitas tentativas. Aguarde alguns minutos.', 429);
    $in = input();
    if (!empty($in['website'])) json_out(['ok' => true]);
    if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf'] ?? null))) json_error('Sessão expirada. Recarregue a página.', 419);
    $r = fh_checkout($in, current_customer());
    json_out(['ok' => true, 'subscription_id' => $r['subscription']['id'], 'pay_url' => $r['pay_url'], 'error' => $r['error'], 'free' => !empty($r['free']), 'portal' => !empty($r['free']) ? '/cliente/fiscal/#/empresa/nova' : '/cliente/fiscal/#/assinatura']);
});

route('GET', '/fh/plans', function () {
    json_out(['data' => fh_plans(), 'terms_version' => FH_TERMS_VERSION]);
});

/* -------------------------------------------------------------- customer */

route('GET', '/fh/me', function () {
    $c = fh_customer_guard();
    $a = fh_access((int)$c['id']);
    $emitters = array_map('fh_emitter_public', db_all('SELECT * FROM fh_emitters WHERE customer_id = ? ORDER BY active DESC, legal_name', [$c['id']]));
    json_out(['customer' => ['id' => $c['id'], 'name' => $c['name'], 'email' => $c['email']], 'access' => $a, 'emitters' => $emitters, 'status_labels' => FH_SUB_STATUS,
        'ai' => ai_configured(), 'situations' => array_map(fn($s) => $s[3], FH_ISS_SITUATIONS), 'imunidades' => FH_IMUNIDADES, 'reg_esp' => FH_REG_ESP, 'ded_types' => FH_DED_TYPES]);
});

route('GET', '/fh/lc116', function () {
    fh_customer_guard();
    json_out(['data' => array_map(fn($r) => ['code' => $r[0], 'name' => $r[1], 'ctribnac' => fh_lc_to_ctribnac($r[0]), 'sigiss' => fh_lc_to_sigiss($r[0]),
        'nbs' => array_map(fn($c, $d) => ['code' => (string)$c, 'name' => $d], array_keys($n = nfse_nbs_for_lc($r[0])), $n)], fh_lc116_list())]);
});

route('GET', '/fh/cep/{cep}', function ($p) {
    fh_customer_guard();
    $r = cep_lookup((string)$p['cep']);
    if (!$r) json_error('CEP não encontrado.', 404);
    json_out(['street' => $r['logradouro'] ?? '', 'district' => $r['bairro'] ?? '', 'city' => $r['localidade'] ?? '', 'uf' => $r['uf'] ?? '', 'city_ibge' => $r['ibge'] ?? '']);
});

route('GET', '/fh/cnpj/{cnpj}', function ($p) {
    fh_customer_guard();
    if (!throttle('fh-cnpj', 30, 600)) json_error('Muitas consultas seguidas.', 429);
    $cnpj = only_digits((string)$p['cnpj']);
    if (!fh_valid_doc($cnpj) || strlen($cnpj) !== 14) json_error('CNPJ inválido.', 422);
    $ch = curl_init(rtrim((string)config('cnpj_api_url', 'https://brasilapi.com.br/api/cnpj/v1'), '/') . "/$cnpj");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['User-Agent: IntegraFiscalHub/1.0']]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = $raw ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($j)) json_error('Não foi possível consultar o CNPJ agora. Preencha manualmente.', 502);
    json_out(['legal_name' => $j['razao_social'] ?? '', 'trade_name' => $j['nome_fantasia'] ?? '', 'email' => mb_strtolower((string)($j['email'] ?? '')), 'phone' => $j['ddd_telefone_1'] ?? '',
        'cep' => only_digits((string)($j['cep'] ?? '')), 'street' => trim(($j['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($j['logradouro'] ?? '')), 'number' => $j['numero'] ?? '', 'complement' => $j['complemento'] ?? '',
        'district' => $j['bairro'] ?? '', 'city' => $j['municipio'] ?? '', 'uf' => $j['uf'] ?? '', 'city_ibge' => (string)($j['codigo_municipio_ibge'] ?? ''), 'cnae' => (string)($j['cnae_fiscal'] ?? ''),
        'simples' => $j['opcao_pelo_simples'] ?? null, 'mei' => $j['opcao_pelo_mei'] ?? null]);
});

// emitters
route('GET', '/fh/emitters', function () {
    [$cid] = fh_guard();
    json_out(['data' => array_map('fh_emitter_public', db_all('SELECT * FROM fh_emitters WHERE customer_id = ? ORDER BY active DESC, legal_name', [$cid]))]);
});
route('POST', '/fh/emitters', function () {
    [$cid] = fh_guard();
    json_out(fh_emitter_public(fh_emitter_save($cid, input(), null)), 201);
});
route('PUT', '/fh/emitters/{id}', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_emitter_public(fh_emitter_save($cid, input(), fh_owned_emitter($cid, $p['id']))));
});
route('DELETE', '/fh/emitters/{id}', function ($p) {
    [$cid] = fh_guard();
    $em = fh_owned_emitter($cid, $p['id']);
    db_update('fh_emitters', (int)$em['id'], ['active' => 0, 'updated_at' => now()]);
    json_out(['ok' => true]);
});
route('POST', '/fh/emitters/{id}/certificate', function ($p) {
    [$cid] = fh_guard();
    $in = input();
    $r = fh_emitter_certificate(fh_owned_emitter($cid, $p['id']), (string)($in['pfx_b64'] ?? ''), (string)($in['password'] ?? ''));
    json_out($r + ['emitter' => fh_emitter_public(fh_owned_emitter($cid, $p['id']))]);
});
route('DELETE', '/fh/emitters/{id}/certificate', function ($p) {
    [$cid] = fh_guard();
    db_update('fh_emitters', (int)fh_owned_emitter($cid, $p['id'])['id'], ['cert_pfx' => null, 'cert_password' => null, 'cert_subject' => null, 'cert_valid_to' => null, 'updated_at' => now()]);
    json_out(['ok' => true]);
});
route('GET', '/fh/emitters/{id}/sigiss-codes', function ($p) {
    [$cid] = fh_guard();
    if (!throttle('fh-sigcodes-' . $cid, 20, 3600)) json_error('Muitas consultas seguidas. Aguarde alguns minutos.', 429);
    $em = fh_owned_emitter($cid, $p['id']);
    if ($em['provider'] !== 'sigiss') json_error('Disponível para empresas que emitem pelo SIGISS.', 422);
    json_out(['data' => fh_sigiss_known_codes($em, !empty($_GET['refresh']))]);
});
route('POST', '/fh/emitters/{id}/test', function ($p) {
    [$cid] = fh_guard();
    if (!throttle('fh-test-' . $cid, 20, 3600)) json_error('Muitos testes seguidos. Aguarde alguns minutos.', 429);
    json_out(fh_test_connection(fh_owned_emitter($cid, $p['id'])));
});

// takers
route('GET', '/fh/emitters/{id}/takers', function ($p) {
    [$cid] = fh_guard();
    $em = fh_owned_emitter($cid, $p['id']);
    $q = trim((string)($_GET['q'] ?? ''));
    $rows = db_all('SELECT t.*, (SELECT COUNT(*) FROM fh_invoices i WHERE i.taker_id = t.id AND i.status = \'authorized\') AS invoices, (SELECT COALESCE(SUM(amount),0) FROM fh_invoices i WHERE i.taker_id = t.id AND i.status = \'authorized\') AS billed
        FROM fh_takers t WHERE t.emitter_id = ?' . ($q !== '' ? ' AND (LOWER(t.name) LIKE ? OR t.document LIKE ?)' : '') . ' ORDER BY t.name LIMIT 1000', $q !== '' ? [$em['id'], '%' . mb_strtolower($q) . '%', '%' . only_digits($q) . '%'] : [$em['id']]);
    json_out(['data' => $rows, 'total' => count($rows)]);
});
route('POST', '/fh/emitters/{id}/takers', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_taker_save(fh_owned_emitter($cid, $p['id']), input(), null), 201);
});
route('PUT', '/fh/takers/{id}', function ($p) {
    [$cid] = fh_guard();
    $t = db_one('SELECT t.* FROM fh_takers t JOIN fh_emitters e ON e.id = t.emitter_id WHERE t.id = ? AND e.customer_id = ?', [(int)$p['id'], $cid]);
    if (!$t) json_error('Cliente não encontrado.', 404);
    json_out(fh_taker_save(db_find('fh_emitters', (int)$t['emitter_id']), input(), $t));
});
route('DELETE', '/fh/takers/{id}', function ($p) {
    [$cid] = fh_guard();
    $t = db_one('SELECT t.* FROM fh_takers t JOIN fh_emitters e ON e.id = t.emitter_id WHERE t.id = ? AND e.customer_id = ?', [(int)$p['id'], $cid]);
    if (!$t) json_error('Cliente não encontrado.', 404);
    if (db_value('SELECT id FROM fh_recurring WHERE taker_id = ? AND active = 1', [$t['id']])) json_error('Este cliente tem notas recorrentes ativas. Desative-as antes.', 409);
    db_exec('DELETE FROM fh_takers WHERE id = ?', [$t['id']]);
    json_out(['ok' => true]);
});

// services
route('GET', '/fh/emitters/{id}/services', function ($p) {
    [$cid] = fh_guard();
    $em = fh_owned_emitter($cid, $p['id']);
    json_out(['data' => db_all('SELECT s.*, (SELECT COUNT(*) FROM fh_invoices i WHERE i.service_id = s.id AND i.status = \'authorized\') AS invoices FROM fh_services s WHERE s.emitter_id = ? ORDER BY s.active DESC, s.name', [$em['id']])]);
});
route('POST', '/fh/emitters/{id}/services', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_service_save(fh_owned_emitter($cid, $p['id']), input(), null), 201);
});
route('PUT', '/fh/services/{id}', function ($p) {
    [$cid] = fh_guard();
    $s = db_one('SELECT s.* FROM fh_services s JOIN fh_emitters e ON e.id = s.emitter_id WHERE s.id = ? AND e.customer_id = ?', [(int)$p['id'], $cid]);
    if (!$s) json_error('Serviço não encontrado.', 404);
    json_out(fh_service_save(db_find('fh_emitters', (int)$s['emitter_id']), input(), $s));
});
route('DELETE', '/fh/services/{id}', function ($p) {
    [$cid] = fh_guard();
    $s = db_one('SELECT s.* FROM fh_services s JOIN fh_emitters e ON e.id = s.emitter_id WHERE s.id = ? AND e.customer_id = ?', [(int)$p['id'], $cid]);
    if (!$s) json_error('Serviço não encontrado.', 404);
    if (db_value('SELECT COUNT(*) FROM fh_invoices WHERE service_id = ?', [$s['id']])) db_update('fh_services', (int)$s['id'], ['active' => 0, 'updated_at' => now()]);
    else db_exec('DELETE FROM fh_services WHERE id = ?', [$s['id']]);
    json_out(['ok' => true]);
});

// invoices
route('GET', '/fh/invoices', function () {
    [$cid] = fh_guard();
    json_out(fh_invoices_query($cid, $_GET));
});
route('POST', '/fh/invoices/preview', function () {
    [$cid] = fh_guard();
    $in = input();
    $em = fh_owned_emitter($cid, $in['emitter_id'] ?? 0);
    $cfg = fh_tax_cfg($em);
    $sit = FH_ISS_SITUATIONS[$in['situation'] ?? 'tp'] ?? FH_ISS_SITUATIONS['tp'];
    $exempt = in_array($in['situation'] ?? 'tp', ['is', 'im', 'nt', 'ex'], true) || $em['op_simp_nac'] === '2';
    $doc = !empty($in['taker_id']) ? (string)db_value('SELECT document FROM fh_takers WHERE id = ? AND emitter_id = ?', [(int)$in['taker_id'], $em['id']]) : only_digits((string)($in['taker']['document'] ?? ''));
    $t = nfse_taxes(['amount' => $in['amount'] ?? 0, 'discount_amount' => $in['discount_incond'] ?? 0, 'deductions' => $in['deductions'] ?? 0, 'iss_rate' => $exempt ? 0 : ($in['iss_rate'] ?? $cfg['aliquota']),
        'iss_withheld' => $sit[1] !== '1', 'toma_document' => $doc, 'pis_cofins_cst' => $in['pis_cofins_cst'] ?? ''] + array_intersect_key($in, array_flip(['pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate', 'pis_withheld', 'cofins_withheld', 'csll_withheld', 'irrf_withheld', 'inss_withheld'])), $cfg);
    if ($em['op_simp_nac'] === '2') foreach (['pis', 'cofins', 'csll', 'irrf', 'inss'] as $k) { $t[$k . '_amount'] = 0; $t[$k . '_withheld'] = 0; }
    $t['net_amount'] = round($t['net_amount'] - max(0, (float)($in['discount_cond'] ?? 0)), 2);
    $t['note'] = nfse_taxes_note($t);
    json_out($t);
});
route('POST', '/fh/invoices', function () {
    [$cid] = fh_guard();
    $in = input();
    $em = fh_owned_emitter($cid, $in['emitter_id'] ?? 0);
    if (!(int)$em['active']) json_error('Esta empresa está desativada.', 422);
    $inv = fh_invoice_save($em, $in);
    if (!empty($in['transmit'])) {
        try {
            $inv = fh_transmit((int)$inv['id'], $cid);
        } catch (NfseException $e) {
            json_out(['invoice' => fh_invoice_public(db_find('fh_invoices', (int)$inv['id'])), 'error' => $e->getMessage(), 'details' => $e->details], 201);
        } catch (AppException $e) {
            json_out(['invoice' => fh_invoice_public(db_find('fh_invoices', (int)$inv['id'])), 'error' => $e->getMessage(), 'details' => []], 201);
        }
    }
    json_out(['invoice' => fh_invoice_public($inv)], 201);
});
route('GET', '/fh/invoices/{id}', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_invoice_public(fh_invoice($cid, (int)$p['id'])));
});
route('PUT', '/fh/invoices/{id}', function ($p) {
    [$cid] = fh_guard();
    $inv = fh_invoice($cid, (int)$p['id']);
    json_out(fh_invoice_public(fh_invoice_save(db_find('fh_emitters', (int)$inv['emitter_id']), input(), $inv)));
});
route('DELETE', '/fh/invoices/{id}', function ($p) {
    [$cid] = fh_guard();
    $inv = fh_invoice($cid, (int)$p['id']);
    if (!in_array($inv['status'], ['draft', 'rejected'], true)) json_error('Somente rascunhos e notas rejeitadas podem ser excluídos. Notas emitidas devem ser canceladas.', 409);
    db_exec('DELETE FROM fh_invoices WHERE id = ?', [$inv['id']]);
    json_out(['ok' => true]);
});
route('POST', '/fh/invoices/{id}/transmit', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_invoice_public(fh_transmit((int)$p['id'], $cid)));
});
route('POST', '/fh/invoices/{id}/cancel', function ($p) {
    [$cid] = fh_guard();
    $in = input();
    json_out(fh_invoice_public(fh_cancel((int)$p['id'], $cid, (int)($in['reason'] ?? 0), (string)($in['justification'] ?? ''))));
});
route('POST', '/fh/invoices/{id}/sync', function ($p) {
    [$cid] = fh_guard();
    json_out(fh_invoice_public(fh_sigiss_sync((int)$p['id'], $cid)));
});
route('POST', '/fh/invoices/{id}/void', function ($p) {
    [$cid, , $c] = fh_guard();
    if (!throttle('fh-void-' . $cid, 30, 3600)) json_error('Muitas inutilizações seguidas. Aguarde.', 429);
    json_out(fh_invoice_public(fh_void((int)$p['id'], $cid, (string)(input()['justification'] ?? ''), $c['email'] ?? $c['name'] ?? null)));
});
route('POST', '/fh/invoices/{id}/substitute', function ($p) {
    [$cid] = fh_guard();
    $in = input();
    json_out(fh_invoice_public(fh_substitute((int)$p['id'], $cid, (string)($in['motivo'] ?? '99'), (string)($in['descricao'] ?? ''))), 201);
});
route('POST', '/fh/invoices/{id}/duplicate', function ($p) {
    [$cid] = fh_guard();
    $inv = fh_invoice($cid, (int)$p['id']);
    $in = fh_invoice_to_input($inv);
    $in['competence_date'] = today();
    json_out(fh_invoice_public(fh_invoice_save(db_find('fh_emitters', (int)$inv['emitter_id']), $in, null, 'manual')), 201);
});
route('POST', '/fh/invoices/{id}/email', function ($p) {
    [$cid] = fh_guard();
    if (!throttle('fh-mail-' . $cid, 60, 3600)) json_error('Muitos envios seguidos. Aguarde.', 429);
    $inv = fh_invoice($cid, (int)$p['id']);
    fh_email_invoice($inv, db_find('fh_emitters', (int)$inv['emitter_id']), trim((string)(input()['to'] ?? '')) ?: null);
    json_out(['ok' => true]);
});
route('GET', '/fh/invoices/{id}/pdf', function ($p) {
    [$cid] = fh_guard();
    $inv = fh_invoice($cid, (int)$p['id']);
    fh_send_file(fh_file_base($inv) . '.pdf', fh_pdf($inv, db_find('fh_emitters', (int)$inv['emitter_id'])), 'application/pdf');
});
route('GET', '/fh/invoices/{id}/xml', function ($p) {
    [$cid] = fh_guard();
    $inv = fh_invoice($cid, (int)$p['id']);
    if ($inv['status'] === 'draft') json_error('Rascunho não tem XML.', 422);
    fh_send_file(fh_file_base($inv) . '.xml', fh_xml($inv, db_find('fh_emitters', (int)$inv['emitter_id'])), 'application/xml');
});
route('GET', '/fh/download', function () {
    [$cid] = fh_guard();
    if (!throttle('fh-zip-' . $cid, 40, 3600)) json_error('Muitos downloads seguidos. Aguarde alguns minutos.', 429);
    $rows = fh_invoices_query($cid, $_GET + ['per_page' => 1000], false);
    $rows = array_values(array_filter($rows, fn($r) => $r['status'] !== 'draft'));
    if (count($rows) > 1500) json_error('Selecione até 1.500 notas por download (use o filtro de período).', 422);
    $what = in_array($_GET['what'] ?? '', ['pdf', 'xml', 'both'], true) ? $_GET['what'] : 'both';
    set_time_limit(300);
    fh_send_file('notas-fiscais-' . date('Y-m-d-His') . '.zip', fh_zip($rows, $what), 'application/zip');
});
route('GET', '/fh/export.csv', function () {
    [$cid] = fh_guard();
    fh_send_file('notas-fiscais-' . date('Y-m-d') . '.csv', fh_csv(fh_invoices_query($cid, $_GET, false)), 'text/csv; charset=utf-8');
});

// reports
route('GET', '/fh/reports', function () {
    [$cid] = fh_guard();
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['start'] ?? '')) ? $_GET['start'] : date('Y-01-01');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['end'] ?? '')) ? $_GET['end'] : today();
    $eid = !empty($_GET['emitter_id']) ? (int)fh_owned_emitter($cid, $_GET['emitter_id'])['id'] : null;
    json_out(fh_report($cid, $eid, $start, $end));
});
route('POST', '/fh/reports/ai', function () {
    [$cid] = fh_guard();
    $in = input();
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['start'] ?? '')) ? $in['start'] : date('Y-01-01');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['end'] ?? '')) ? $in['end'] : today();
    $eid = !empty($in['emitter_id']) ? (int)fh_owned_emitter($cid, $in['emitter_id'])['id'] : null;
    json_out(fh_ai_report($cid, fh_report($cid, $eid, $start, $end)));
});

// batch
route('POST', '/fh/batch/import', function () {
    [$cid] = fh_guard();
    fh_require_flag($cid, 'batch');
    $in = input();
    json_out(fh_batch_import(fh_owned_emitter($cid, $in['emitter_id'] ?? 0), (string)($in['csv'] ?? '')));
});
route('POST', '/fh/batch/transmit', function () {
    [$cid] = fh_guard();
    $ids = array_slice(array_values(array_filter(array_map('intval', (array)(input()['ids'] ?? [])))), 0, 50);
    if (!$ids) json_error('Selecione as notas.', 422);
    set_time_limit(600);
    $out = ['ok' => [], 'errors' => []];
    foreach ($ids as $id) {
        try {
            $inv = fh_transmit($id, $cid);
            $out['ok'][] = ['id' => $id, 'number' => $inv['nfse_number']];
        } catch (Throwable $e) {
            $out['errors'][] = ['id' => $id, 'error' => $e->getMessage() . ($e instanceof NfseException && $e->details ? ' — ' . implode(' ', $e->details) : '')];
            if ($e instanceof AppException && str_contains($e->getMessage(), 'limite')) break;
        }
    }
    json_out($out);
});

// recurring
function fh_recurring_owned(int $cid, $id): array
{
    $r = db_one('SELECT r.* FROM fh_recurring r JOIN fh_emitters e ON e.id = r.emitter_id WHERE r.id = ? AND e.customer_id = ?', [(int)$id, $cid]);
    if (!$r) json_error('Recorrência não encontrada.', 404);
    return $r;
}
/** Next $n emission dates of a recurrence (respecting the end date). */
function fh_recurring_schedule(string $next, int $day, int $interval, ?string $end, int $n = 4): array
{
    $out = [];
    for ($d = $next; count($out) < $n && (!$end || $d <= $end); $d = fh_recurring_next($d, $day, $interval)) $out[] = $d;
    return $out;
}
route('GET', '/fh/recurring', function () {
    [$cid] = fh_guard();
    $rows = db_all('SELECT r.*, t.name AS taker_name, t.email AS taker_email, t.document AS taker_document, s.name AS service_name, e.legal_name AS emitter_name, e.trade_name AS emitter_trade,
            (SELECT COUNT(*) FROM fh_invoices i WHERE i.recurring_id = r.id AND i.status = \'authorized\') AS emitted_count,
            (SELECT COALESCE(SUM(i.amount), 0) FROM fh_invoices i WHERE i.recurring_id = r.id AND i.status = \'authorized\') AS emitted_total,
            (SELECT MAX(i.id) FROM fh_invoices i WHERE i.recurring_id = r.id) AS last_invoice_id
        FROM fh_recurring r JOIN fh_emitters e ON e.id = r.emitter_id LEFT JOIN fh_takers t ON t.id = r.taker_id LEFT JOIN fh_services s ON s.id = r.service_id
        WHERE e.customer_id = ? ORDER BY r.active DESC, r.next_run', [$cid]);
    foreach ($rows as &$r) {
        $r['interval_months'] = (int)($r['interval_months'] ?? 1) ?: 1;
        $r['last_invoice'] = $r['last_invoice_id'] ? db_one('SELECT id, status, nfse_number, amount, issued_at, created_at, error_message FROM fh_invoices WHERE id = ?', [(int)$r['last_invoice_id']]) : null;
        $r['schedule'] = (int)($r['active'] ?? 0) ? fh_recurring_schedule($r['next_run'], (int)$r['day_of_month'], $r['interval_months'], $r['end_date']) : [];
        $r['next_due'] = fh_recurring_due(max((string)$r['next_run'], today()), $r['due_day'] ?? null);
        $r['preview'] = fh_recurring_text((string)$r['description'], (string)$r['next_run'], $r['next_due']);
    }
    unset($r);
    json_out(['data' => $rows, 'intervals' => FH_RECURRING_INTERVALS]);
});
route('POST', '/fh/recurring/preview', function () {
    fh_guard();
    $in = input();
    $day = max(1, min(28, (int)($in['day_of_month'] ?? 1)));
    $interval = isset(FH_RECURRING_INTERVALS[(int)($in['interval_months'] ?? 1)]) ? (int)$in['interval_months'] : 1;
    $next = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['next_run'] ?? '')) ? $in['next_run'] : (date('j') <= $day ? date('Y-m-') . sprintf('%02d', $day) : fh_recurring_next(today(), $day, 1));
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['end_date'] ?? '')) ? $in['end_date'] : null;
    $sched = fh_recurring_schedule($next, $day, $interval, $end, 6);
    $dueDay = (int)($in['due_day'] ?? 0);
    $dues = array_map(fn($d) => fh_recurring_due(max($d, today()), $dueDay), $sched);
    json_out(['schedule' => $sched, 'dues' => $dues, 'text' => fh_recurring_text((string)($in['description'] ?? ''), $sched[0] ?? $next, $dues[0] ?? null)]);
});
$fhRecurringSave = function (int $cid, array $in, ?array $existing) {
    fh_require_flag($cid, 'recurring');
    $em = fh_owned_emitter($cid, $in['emitter_id'] ?? $existing['emitter_id'] ?? 0);
    $taker = db_one('SELECT id FROM fh_takers WHERE id = ? AND emitter_id = ?', [(int)($in['taker_id'] ?? 0), $em['id']]);
    if (!$taker) throw new AppException('Escolha o cliente (tomador).');
    $svc = !empty($in['service_id']) ? db_one('SELECT id FROM fh_services WHERE id = ? AND emitter_id = ?', [(int)$in['service_id'], $em['id']]) : null;
    $amount = round((float)str_replace(',', '.', (string)($in['amount'] ?? 0)), 2);
    if ($amount <= 0) throw new AppException('Informe o valor.');
    $day = max(1, min(28, (int)($in['day_of_month'] ?? 1)));
    $interval = isset(FH_RECURRING_INTERVALS[(int)($in['interval_months'] ?? 1)]) ? (int)$in['interval_months'] : 1;
    $next = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['next_run'] ?? '')) ? $in['next_run'] : (date('j') <= $day ? date('Y-m-') . sprintf('%02d', $day) : fh_recurring_next(today(), $day, 1));
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['end_date'] ?? '')) ? $in['end_date'] : null;
    if ($end && $end < $next) throw new AppException('A data de encerramento é anterior à próxima emissão.');
    $d = ['emitter_id' => $em['id'], 'taker_id' => $taker['id'], 'service_id' => $svc['id'] ?? null, 'amount' => $amount, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 2000),
        'day_of_month' => $day, 'interval_months' => $interval, 'due_day' => (int)($in['due_day'] ?? 0) >= 1 ? min(31, (int)$in['due_day']) : null, 'next_run' => $next, 'end_date' => $end, 'active' => filter_var($in['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'updated_at' => now()];
    if (!$svc) throw new AppException('Escolha o serviço (com os códigos fiscais) da nota recorrente. Cadastre-o em Serviços, se ainda não existir.');
    if (trim($d['description']) === '') throw new AppException('Descreva o serviço da nota (discriminação).');
    if ($existing) { db_update('fh_recurring', (int)$existing['id'], $d); return db_find('fh_recurring', (int)$existing['id']); }
    return db_find('fh_recurring', db_insert('fh_recurring', $d + ['created_at' => now()]));
};
route('POST', '/fh/recurring', function () use ($fhRecurringSave) {
    [$cid] = fh_guard();
    json_out($fhRecurringSave($cid, input(), null), 201);
});
route('PUT', '/fh/recurring/{id}', function ($p) use ($fhRecurringSave) {
    [$cid] = fh_guard();
    $r = fh_recurring_owned($cid, $p['id']);
    json_out($fhRecurringSave($cid, input() + $r, $r));
});
route('POST', '/fh/recurring/{id}/toggle', function ($p) {
    [$cid] = fh_guard();
    $r = fh_recurring_owned($cid, $p['id']);
    $upd = ['active' => (int)$r['active'] ? 0 : 1, 'updated_at' => now()];
    // resuming after a pause: never schedule in the past (the missed months are not emitted retroactively)
    if (!(int)$r['active'] && $r['next_run'] < today()) $upd['next_run'] = date('j') <= (int)$r['day_of_month'] ? date('Y-m-') . sprintf('%02d', (int)$r['day_of_month']) : fh_recurring_next(today(), (int)$r['day_of_month'], 1);
    db_update('fh_recurring', (int)$r['id'], $upd);
    json_out(db_find('fh_recurring', (int)$r['id']));
});
route('POST', '/fh/recurring/{id}/run', function ($p) {
    [$cid] = fh_guard();
    fh_require_flag($cid, 'recurring');
    fh_require_emit($cid);
    $r = fh_recurring_owned($cid, $p['id']);
    // Emits the pending occurrence now (its month fills the description; the competence is never in the future)
    try {
        $inv = fh_recurring_emit($r, (string)$r['next_run']);
    } catch (NfseException $e) {
        json_error($e->getMessage(), 422, ['details' => $e->details]);
    }
    db_update('fh_recurring', (int)$r['id'], ['last_run' => today(), 'next_run' => fh_recurring_next((string)$r['next_run'], (int)$r['day_of_month'], (int)($r['interval_months'] ?? 1) ?: 1), 'updated_at' => now()]);
    json_out(['invoice' => fh_invoice_public($inv)]);
});
route('GET', '/fh/recurring/{id}/history', function ($p) {
    [$cid] = fh_guard();
    $r = fh_recurring_owned($cid, $p['id']);
    json_out(['data' => db_all('SELECT id, status, nfse_number, dps_number, amount, competence_date, issued_at, created_at, error_message FROM fh_invoices WHERE recurring_id = ? ORDER BY id DESC LIMIT 60', [$r['id']])]);
});
route('POST', '/fh/recurring/{id}/duplicate', function ($p) use ($fhRecurringSave) {
    [$cid] = fh_guard();
    $r = fh_recurring_owned($cid, $p['id']);
    json_out($fhRecurringSave($cid, ['active' => false, 'next_run' => ''] + $r, null), 201);
});
route('DELETE', '/fh/recurring/{id}', function ($p) {
    [$cid] = fh_guard();
    $r = fh_recurring_owned($cid, $p['id']);
    db_exec('DELETE FROM fh_recurring WHERE id = ?', [$r['id']]);
    json_out(['ok' => true]);
});

// subscription (self-service)
route('GET', '/fh/subscription', function () {
    $c = fh_customer_guard();
    $a = fh_access((int)$c['id']);
    $sub = $a['sub'];
    json_out(['access' => $a, 'plans' => fh_plans(), 'events' => $sub ? db_all('SELECT kind, description, amount, created_at FROM fh_sub_events WHERE subscription_id = ? ORDER BY id DESC LIMIT 30', [$sub['id']]) : [],
        'charges' => $sub ? db_all('SELECT id, amount, due_date, status, invoice_url, paid_at, description FROM charges WHERE fh_subscription_id = ? ORDER BY id DESC LIMIT 24', [$sub['id']]) : [], 'open_charge' => $sub ? fh_open_charge((int)$sub['id']) : null]);
});
route('POST', '/fh/subscription/pay', function () {
    $c = fh_customer_guard();
    $sub = fh_subscription((int)$c['id']);
    if (!$sub || $sub['status'] === 'canceled') json_error('Nenhuma assinatura para pagar.', 404);
    if (fh_is_free($sub)) json_error('Seu plano é gratuito: não há nada a pagar.', 422);
    if ($open = fh_open_charge((int)$sub['id'])) {
        try { $open = refresh_charge($open); } catch (Throwable $e) { /* keep */ }
        if (in_array($open['status'], ASAAS_PAID_STATUSES, true)) json_out(['paid' => true, 'access' => fh_access((int)$c['id'])]);
        if ($open['invoice_url']) json_out(['pay_url' => $open['invoice_url']]);
    }
    if (!throttle('fh-pay-' . $c['id'], 5, 3600)) json_error('Aguarde alguns minutos para gerar uma nova cobrança.', 429);
    $charge = fh_create_charge($sub, today());
    json_out(['pay_url' => $charge['invoice_url']]);
});
route('POST', '/fh/subscription/plan', function () {
    $c = fh_customer_guard();
    $in = input();
    json_out(fh_customer_change_plan((int)$c['id'], (string)($in['plan'] ?? ''), (string)($in['cycle'] ?? 'monthly')));
});
route('POST', '/fh/subscription/cancel', function () {
    $c = fh_customer_guard();
    json_out(fh_customer_cancel((int)$c['id'], mb_substr(trim((string)(input()['reason'] ?? '')), 0, 255)));
});

/* ------------------------------------------------------------------ admin */

route('GET', '/fh-admin/dashboard', function () {
    guard('customers');
    json_out(fh_admin_dashboard() + ['plans' => fh_plans(false), 'status_labels' => FH_SUB_STATUS]);
});
route('GET', '/fh-admin/subscriptions', function () {
    guard('customers');
    $w = [];
    $p = [];
    if (!empty($_GET['status'])) { $w[] = 's.status = ?'; $p[] = $_GET['status']; }
    if (!empty($_GET['plan'])) { $w[] = 's.plan_code = ?'; $p[] = $_GET['plan']; }
    if (!empty($_GET['q'])) { $w[] = '(LOWER(c.name) LIKE ? OR LOWER(c.email) LIKE ? OR c.document LIKE ?)'; $q = '%' . mb_strtolower((string)$_GET['q']) . '%'; array_push($p, $q, $q, '%' . only_digits((string)$_GET['q']) . '%'); }
    $month = date('Y-m-01 00:00:00');
    $rows = db_all("SELECT s.*, c.name AS customer_name, c.email AS customer_email, c.document AS customer_document, p.name AS plan_name, p.notes_limit,
        (SELECT COUNT(*) FROM fh_emitters e WHERE e.customer_id = s.customer_id AND e.active = 1) AS emitters,
        (SELECT COUNT(*) FROM fh_invoices i WHERE i.customer_id = s.customer_id AND i.status IN ('authorized','canceled') AND i.issued_at >= ?) AS notes_month,
        (SELECT MIN(cert_valid_to) FROM fh_emitters e WHERE e.customer_id = s.customer_id AND e.provider = 'nacional' AND e.active = 1) AS cert_valid_to
        FROM fh_subscriptions s JOIN customers c ON c.id = s.customer_id LEFT JOIN fh_plans p ON p.code = s.plan_code" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.id DESC LIMIT 500', array_merge([$month], $p));
    json_out(['data' => $rows, 'total' => count($rows)]);
});
route('GET', '/fh-admin/subscriptions/{id}', function ($p) {
    guard('customers');
    $s = db_one('SELECT s.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.document AS customer_document FROM fh_subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.id = ?', [(int)$p['id']]);
    if (!$s) json_error('Assinatura não encontrada.', 404);
    $cid = (int)$s['customer_id'];
    $usage = [];
    for ($i = 5; $i >= 0; $i--) { $m = date('Y-m', strtotime("first day of -$i months")); $usage[] = ['month' => $m, 'notes' => fh_notes_used($cid, $m)]; }
    json_out(['subscription' => $s, 'access' => fh_access($cid), 'events' => db_all('SELECT * FROM fh_sub_events WHERE subscription_id = ? ORDER BY id DESC', [$s['id']]),
        'charges' => db_all('SELECT id, amount, due_date, status, invoice_url, paid_at, description FROM charges WHERE fh_subscription_id = ? ORDER BY id DESC', [$s['id']]),
        'emitters' => array_map('fh_emitter_public', db_all('SELECT * FROM fh_emitters WHERE customer_id = ?', [$cid])), 'usage' => $usage,
        'invoices' => db_all("SELECT id, status, nfse_number, amount, toma_name, issued_at, error_message, provider FROM fh_invoices WHERE customer_id = ? ORDER BY id DESC LIMIT 20", [$cid])]);
});
route('POST', '/fh-admin/subscriptions', function () {
    guard('customers');
    json_out(fh_admin_create(input()), 201);
});
route('PUT', '/fh-admin/subscriptions/{id}', function ($p) {
    guard('customers');
    json_out(fh_admin_update((int)$p['id'], input()));
});
route('POST', '/fh-admin/subscriptions/{id}/free-months', function ($p) {
    guard('customers');
    $in = input();
    json_out(fh_admin_free_months((int)$p['id'], (int)($in['months'] ?? 0), trim((string)($in['reason'] ?? '')), !empty($in['cancel_open_charge'])));
});
route('POST', '/fh-admin/subscriptions/{id}/activate', function ($p) {
    guard('finance');
    $in = input();
    $sub = db_find('fh_subscriptions', (int)$p['id']);
    if (!$sub) json_error('Assinatura não encontrada.', 404);
    $months = max(1, min(24, (int)($in['months'] ?? ($sub['cycle'] === 'yearly' ? 12 : 1))));
    json_out(fh_extend($sub, $months, 'manual', 'Ativação manual pela equipe (pagamento fora do Asaas)' . (!empty($in['reason']) ? ': ' . trim((string)$in['reason']) : '') . '.'));
});
route('POST', '/fh-admin/subscriptions/{id}/charge', function ($p) {
    guard('charges');
    $sub = db_find('fh_subscriptions', (int)$p['id']);
    if (!$sub) json_error('Assinatura não encontrada.', 404);
    if (fh_open_charge((int)$sub['id'])) json_error('Já existe uma cobrança em aberto para esta assinatura.', 409);
    json_out(fh_create_charge($sub, (string)(input()['due_date'] ?? '') ?: today()), 201);
});
route('GET', '/fh-admin/plans', function () {
    guard('customers');
    json_out(['data' => fh_plans(false), 'refs' => fh_market_refs(), 'suggested' => fh_default_plans(), 'factor' => FH_MARKET_FACTOR, 'market_date' => FH_MARKET_DATE]);
});
route('PUT', '/fh-admin/plans/{id}', function ($p) {
    guard('users');
    $plan = db_find('fh_plans', (int)$p['id']);
    if (!$plan) json_error('Plano não encontrado.', 404);
    $in = input();
    $d = validate_fields(['name' => ['type' => 'string', 'max' => 80], 'tagline' => ['type' => 'string', 'max' => 200], 'price_monthly' => ['type' => 'decimal'], 'price_yearly' => ['type' => 'decimal'],
        'notes_limit' => ['type' => 'int'], 'companies_limit' => ['type' => 'int'], 'highlight' => ['type' => 'bool'], 'active' => ['type' => 'bool']], $in, true);
    if (isset($in['features'])) $d['features'] = json_encode(array_values(array_filter(array_map('trim', is_array($in['features']) ? $in['features'] : explode("\n", (string)$in['features'])))), JSON_UNESCAPED_UNICODE);
    if (isset($in['flags']) && is_array($in['flags'])) $d['flags'] = json_encode(['ai_quota' => max(0, (int)($in['flags']['ai_quota'] ?? 0)), 'recurring' => !empty($in['flags']['recurring']), 'batch' => !empty($in['flags']['batch']), 'priority' => !empty($in['flags']['priority']), 'open_finance' => !empty($in['flags']['open_finance'])]);
    db_update('fh_plans', (int)$plan['id'], $d + ['updated_at' => now()]);
    audit('update', 'fh_plan', $plan['id'], array_keys($d));
    json_out(fh_plan_row(db_find('fh_plans', (int)$plan['id'])));
});
route('POST', '/fh-admin/plans/reset-prices', function () {
    guard('users');
    foreach (fh_default_plans() as $d) db_exec('UPDATE fh_plans SET price_monthly = ?, price_yearly = ?, market_avg = ?, updated_at = ? WHERE code = ?', [$d['price_monthly'], $d['price_yearly'], $d['market_avg'], now(), $d['code']]);
    audit('reset', 'fh_plans');
    json_out(['data' => fh_plans(false)]);
});
route('GET', '/fh-admin/invoices', function () {
    guard('customers');
    $w = [];
    $p = [];
    if (!empty($_GET['status'])) { $w[] = 'i.status = ?'; $p[] = $_GET['status']; }
    if (!empty($_GET['q'])) { $w[] = '(LOWER(c.name) LIKE ? OR LOWER(i.toma_name) LIKE ? OR i.nfse_number LIKE ?)'; $q = '%' . mb_strtolower((string)$_GET['q']) . '%'; array_push($p, $q, $q, $q); }
    $rows = db_all('SELECT i.id, i.status, i.provider, i.environment, i.nfse_number, i.dps_number, i.amount, i.toma_name, i.issued_at, i.created_at, i.error_message, e.legal_name AS emitter_name, c.name AS customer_name, i.customer_id
        FROM fh_invoices i JOIN fh_emitters e ON e.id = i.emitter_id JOIN customers c ON c.id = i.customer_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY i.id DESC LIMIT 300', $p);
    json_out(['data' => $rows, 'total' => count($rows)]);
});
