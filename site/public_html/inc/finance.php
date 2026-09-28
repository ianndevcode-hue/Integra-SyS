<?php
declare(strict_types=1);

/**
 * Financial domain: Asaas charges, statement import, reconciliation,
 * cash flow, P&L (DRE) and partner profit distribution.
 */

require_once INC_PATH . '/asaas.php';

const ASAAS_PAID_STATUSES = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
const BILLING_TYPES = ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD'];


function fail(string $message): void
{
    throw new AppException($message);
}

/** Every Asaas operation needs a real API key: there is no simulated mode. */
function asaas_require(): void
{
    if (!AsaasClient::isConfigured()) {
        fail('Integração Asaas não configurada. Informe a chave de API em Configurações → Asaas.');
    }
}

/* ---------------------------------------------------------------- Customers */

function asaas_ensure_customer(array $customer): string
{
    if (!empty($customer['asaas_customer_id'])) return $customer['asaas_customer_id'];

    asaas_require();
    $doc = only_digits($customer['document'] ?? '');
    if (!in_array(strlen($doc), [11, 14], true)) {
        fail('Cadastre um CPF ou CNPJ válido no cliente antes de emitir cobranças no Asaas.');
    }
    $client = new AsaasClient();
    $found = $client->get('/customers', ['cpfCnpj' => $doc, 'limit' => 1]);
    if (!empty($found['data'][0]['id'])) {
        $asaasId = $found['data'][0]['id'];
    } else {
        $created = $client->post('/customers', array_filter([
            'name' => $customer['name'],
            'cpfCnpj' => $doc,
            'email' => $customer['email'] ?: null,
            'mobilePhone' => only_digits($customer['phone'] ?? '') ?: null,
            'postalCode' => only_digits($customer['postal_code'] ?? '') ?: null,
            'externalReference' => 'customer:' . $customer['id'],
        ]));
        $asaasId = $created['id'];
    }
    db_update('customers', (int)$customer['id'], ['asaas_customer_id' => $asaasId, 'updated_at' => now()]);
    return $asaasId;
}

/* ------------------------------------------------------------------ Charges */

function create_charge(array $in): array
{
    asaas_require();
    $customer = db_find('customers', (int)($in['customer_id'] ?? 0));
    if (!$customer) fail('Selecione um cliente válido.');
    $amount = round((float)($in['amount'] ?? 0), 2);
    if ($amount < 5) fail('O valor mínimo de uma cobrança no Asaas é R$ 5,00.');
    $dueDate = (string)($in['due_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || $dueDate < today()) fail('Informe um vencimento a partir de hoje.');
    $billingType = strtoupper((string)($in['billing_type'] ?? 'UNDEFINED'));
    if (!in_array($billingType, BILLING_TYPES, true)) fail('Forma de pagamento inválida.');
    $description = trim((string)($in['description'] ?? '')) ?: 'Serviços Integra Code';

    $ts = now();
    $chargeId = db_insert('charges', [
        'customer_id' => $customer['id'],
        'project_id' => !empty($in['project_id']) ? (int)$in['project_id'] : null,
        'contract_id' => !empty($in['contract_id']) ? (int)$in['contract_id'] : null,
        'billing_type' => $billingType, 'amount' => $amount, 'due_date' => $dueDate,
        'description' => mb_substr($description, 0, 255), 'status' => 'CREATING',
        'demo' => 0, 'created_at' => $ts, 'updated_at' => $ts,
    ]);

    try {
        $asaasCustomer = asaas_ensure_customer($customer);
        $payment = (new AsaasClient())->post('/payments', [
            'customer' => $asaasCustomer,
            'billingType' => $billingType,
            'value' => $amount,
            'dueDate' => $dueDate,
            'description' => mb_substr($description, 0, 500),
            'externalReference' => 'charge:' . $chargeId,
        ]);
    } catch (Throwable $e) {
        db_exec('DELETE FROM charges WHERE id = ?', [$chargeId]);
        throw $e;
    }

    db_update('charges', $chargeId, [
        'asaas_payment_id' => $payment['id'],
        'status' => $payment['status'] ?? 'PENDING',
        'invoice_url' => $payment['invoiceUrl'] ?? null,
        'bank_slip_url' => $payment['bankSlipUrl'] ?? null,
        'net_amount' => $payment['netValue'] ?? null,
        'updated_at' => now(),
    ]);

    // Link to an existing receivable or create one so the charge flows into cash flow.
    $entryId = !empty($in['entry_id']) ? (int)$in['entry_id'] : null;
    if ($entryId) {
        db_update('financial_entries', $entryId, ['charge_id' => $chargeId, 'updated_at' => now()]);
    } elseif (!isset($in['create_entry']) || $in['create_entry']) {
        $entryId = db_insert('financial_entries', [
            'entry_type' => 'receivable', 'description' => 'Cobrança: ' . mb_substr($description, 0, 200),
            'category_id' => !empty($in['category_id']) ? (int)$in['category_id'] : null,
            'customer_id' => $customer['id'], 'project_id' => !empty($in['project_id']) ? (int)$in['project_id'] : null,
            'contract_id' => !empty($in['contract_id']) ? (int)$in['contract_id'] : null,
            'amount' => $amount, 'due_date' => $dueDate, 'competence_date' => $dueDate, 'status' => 'open',
            'payment_method' => $billingType, 'charge_id' => $chargeId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($entryId) db_update('charges', $chargeId, ['entry_id' => $entryId]);

    audit('create', 'charge', $chargeId, ['amount' => $amount, 'customer' => $customer['name']]);
    $created = db_find('charges', $chargeId);
    if (function_exists('mail_event_charge_created')) mail_event_charge_created($created, $customer);
    return $created;
}

function apply_payment_update(array $charge, array $payment): array
{
    $status = (string)($payment['status'] ?? $charge['status']);
    $paidAt = $payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? $payment['confirmedDate'] ?? null;
    $isPaid = in_array($status, ASAAS_PAID_STATUSES, true);

    db_update('charges', (int)$charge['id'], array_filter([
        'status' => $status,
        'net_amount' => $payment['netValue'] ?? null,
        'invoice_url' => $payment['invoiceUrl'] ?? null,
        'bank_slip_url' => $payment['bankSlipUrl'] ?? null,
        'paid_at' => $isPaid ? ($paidAt ?: today()) : null,
        'updated_at' => now(),
    ], fn($v) => $v !== null));

    if (!empty($charge['fh_subscription_id']) && function_exists('fh_charge_updated')) {
        try { fh_charge_updated(db_find('charges', (int)$charge['id']), $isPaid); } catch (Throwable $e) { log_line('fiscalhub', 'charge hook failed', ['charge' => $charge['id'], 'error' => $e->getMessage()]); }
    }

    if (!empty($charge['entry_id'])) {
        $entry = db_find('financial_entries', (int)$charge['entry_id']);
        if ($entry) {
            if ($isPaid && $entry['status'] !== 'paid') {
                db_update('financial_entries', (int)$entry['id'], [
                    'status' => 'paid', 'paid_amount' => $payment['value'] ?? $charge['amount'],
                    'paid_at' => $paidAt ?: today(), 'updated_at' => now(),
                ]);
                $fresh = db_find('charges', (int)$charge['id']);
                if (function_exists('mail_event_charge_paid')) mail_event_charge_paid($fresh);
                if (function_exists('nfse_auto_emit_for_charge')) nfse_auto_emit_for_charge($fresh);
            } elseif (in_array($status, ['DELETED', 'CANCELED'], true) && $entry['status'] === 'open') {
                db_update('financial_entries', (int)$entry['id'], ['status' => 'canceled', 'updated_at' => now()]);
            } elseif ($status === 'REFUNDED' && $entry['status'] === 'paid') {
                db_update('financial_entries', (int)$entry['id'], ['status' => 'open', 'paid_amount' => null, 'paid_at' => null, 'updated_at' => now()]);
            }
        }
    }
    return db_find('charges', (int)$charge['id']);
}

function refresh_charge(array $charge): array
{
    if ($charge['demo'] || !AsaasClient::isConfigured()) return $charge;
    $payment = (new AsaasClient())->get('/payments/' . rawurlencode($charge['asaas_payment_id']));
    return apply_payment_update($charge, $payment);
}

function cancel_charge(array $charge): array
{
    if (in_array($charge['status'], ASAAS_PAID_STATUSES, true)) fail('Cobrança já paga não pode ser cancelada. Use o estorno no Asaas.');
    if (!$charge['demo']) {
        asaas_require();
        (new AsaasClient())->delete('/payments/' . rawurlencode($charge['asaas_payment_id']));
    }
    audit('cancel', 'charge', $charge['id']);
    return apply_payment_update($charge, ['status' => 'DELETED']);
}

function charge_pix(array $charge): array
{
    if ($charge['demo']) fail('Esta cobrança não foi registrada no Asaas.');
    asaas_require();
    $res = (new AsaasClient())->get('/payments/' . rawurlencode($charge['asaas_payment_id']) . '/pixQrCode');
    db_update('charges', (int)$charge['id'], ['pix_payload' => $res['payload'] ?? null]);
    return ['payload' => $res['payload'] ?? '', 'encodedImage' => $res['encodedImage'] ?? null];
}

function handle_asaas_webhook(array $event): array
{
    $payment = $event['payment'] ?? null;
    if (!$payment || empty($payment['id'])) return ['handled' => false, 'reason' => 'no payment'];
    $charge = db_one('SELECT * FROM charges WHERE asaas_payment_id = ?', [$payment['id']]);
    if (!$charge) {
        log_line('asaas', 'webhook for unknown payment', ['event' => $event['event'] ?? null, 'id' => $payment['id']]);
        return ['handled' => false, 'reason' => 'unknown payment'];
    }
    if (($event['event'] ?? '') === 'PAYMENT_DELETED') $payment['status'] = 'DELETED';
    apply_payment_update($charge, $payment);
    audit('webhook', 'charge', $charge['id'], ['event' => $event['event'] ?? null, 'status' => $payment['status'] ?? null]);
    return ['handled' => true];
}

/* ------------------------------------------------------ Statement / extrato */

function asaas_balance(): array
{
    if (!AsaasClient::isConfigured()) return ['balance' => null, 'configured' => false];
    $res = (new AsaasClient())->get('/finance/balance');
    return ['balance' => (float)($res['balance'] ?? 0), 'configured' => true];
}

function import_asaas_statement(string $start, string $end): array
{
    if ($start > $end) fail('A data inicial deve ser anterior à final.');
    asaas_require();
    $transactions = (new AsaasClient())->all('/financialTransactions', [
        'startDate' => $start, 'finishDate' => $end,
    ]);

    $imported = 0;
    $skipped = 0;
    foreach ($transactions as $tx) {
        $extId = (string)$tx['id'];
        if (db_value("SELECT id FROM bank_transactions WHERE source = 'asaas' AND external_id = ?", [$extId])) {
            $skipped++;
            continue;
        }
        db_insert('bank_transactions', [
            'source' => 'asaas', 'external_id' => $extId,
            'tx_date' => substr((string)$tx['date'], 0, 10),
            'description' => mb_substr((string)($tx['description'] ?? ''), 0, 255),
            'amount' => round((float)$tx['value'], 2),
            'balance' => isset($tx['balance']) ? round((float)$tx['balance'], 2) : null,
            'tx_type' => $tx['type'] ?? null,
            'payment_external_id' => $tx['paymentId'] ?? ($tx['payment'] ?? null),
            'reconciled' => 0, 'ignored' => 0, 'imported_at' => now(),
        ]);
        $imported++;
    }
    $auto = auto_reconcile();
    audit('import', 'bank_statement', null, compact('start', 'end', 'imported', 'skipped', 'auto'));
    return ['imported' => $imported, 'skipped' => $skipped, 'auto_reconciled' => $auto];
}


/* ----------------------------------------------------------- Reconciliation */

function reconcile_candidates(array $tx, int $windowDays = 7): array
{
    $type = (float)$tx['amount'] >= 0 ? 'receivable' : 'payable';
    $abs = abs((float)$tx['amount']);

    if (!empty($tx['payment_external_id'])) {
        $entryId = db_value('SELECT entry_id FROM charges WHERE asaas_payment_id = ?', [$tx['payment_external_id']]);
        if ($entryId) {
            $entry = db_one('SELECT * FROM financial_entries WHERE id = ? AND bank_transaction_id IS NULL', [$entryId]);
            if ($entry) return [['entry' => $entry, 'score' => 100, 'reason' => 'Cobrança Asaas vinculada']];
        }
    }

    $from = date('Y-m-d', strtotime($tx['tx_date'] . " -$windowDays days"));
    $to = date('Y-m-d', strtotime($tx['tx_date'] . " +$windowDays days"));
    $rows = db_all(
        "SELECT e.*, c.name AS category_name, cu.name AS customer_name FROM financial_entries e
         LEFT JOIN categories c ON c.id = e.category_id LEFT JOIN customers cu ON cu.id = e.customer_id
         WHERE e.entry_type = ? AND e.status != 'canceled' AND e.bank_transaction_id IS NULL
         AND ABS(COALESCE(e.paid_amount, e.amount) - ?) < 0.01
         AND COALESCE(e.paid_at, e.due_date) BETWEEN ? AND ?",
        [$type, $abs, $from, $to]
    );
    $out = [];
    foreach ($rows as $r) {
        $diff = abs((strtotime($r['paid_at'] ?: $r['due_date']) - strtotime($tx['tx_date'])) / 86400);
        $out[] = ['entry' => $r, 'score' => (int)max(50, 95 - $diff * 6), 'reason' => 'Valor igual, ' . (int)$diff . ' dia(s) de diferença'];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

function reconcile_link(int $txId, int $entryId): void
{
    $tx = db_find('bank_transactions', $txId);
    $entry = db_find('financial_entries', $entryId);
    if (!$tx || !$entry) fail('Transação ou lançamento não encontrado.');
    if ($tx['reconciled']) fail('Esta transação já está conciliada.');
    if ($entry['bank_transaction_id']) fail('Este lançamento já está conciliado com outra transação.');
    $expected = $entry['entry_type'] === 'receivable' ? 1 : -1;
    if (($tx['amount'] >= 0 ? 1 : -1) !== $expected) fail('O sinal da transação não corresponde ao tipo do lançamento.');

    db_transaction(function () use ($tx, $entry) {
        $update = ['bank_transaction_id' => $tx['id'], 'updated_at' => now()];
        if ($entry['status'] !== 'paid') {
            $update += ['status' => 'paid', 'paid_amount' => abs((float)$tx['amount']), 'paid_at' => $tx['tx_date']];
        }
        db_update('financial_entries', (int)$entry['id'], $update);
        db_update('bank_transactions', (int)$tx['id'], ['reconciled' => 1, 'entry_id' => $entry['id']]);
    });
}

function reconcile_unlink(int $txId): void
{
    $tx = db_find('bank_transactions', $txId);
    if (!$tx) fail('Transação não encontrada.');
    db_transaction(function () use ($tx) {
        if ($tx['entry_id']) db_update('financial_entries', (int)$tx['entry_id'], ['bank_transaction_id' => null, 'updated_at' => now()]);
        db_update('bank_transactions', (int)$tx['id'], ['reconciled' => 0, 'entry_id' => null, 'ignored' => 0]);
    });
}

function reconcile_create_entry(int $txId, ?int $categoryId, ?string $description): int
{
    $tx = db_find('bank_transactions', $txId);
    if (!$tx) fail('Transação não encontrada.');
    if ($tx['reconciled']) fail('Esta transação já está conciliada.');
    $entryId = db_insert('financial_entries', [
        'entry_type' => $tx['amount'] >= 0 ? 'receivable' : 'payable',
        'description' => $description ?: ($tx['description'] ?: 'Movimentação bancária'),
        'category_id' => $categoryId, 'amount' => abs((float)$tx['amount']), 'due_date' => $tx['tx_date'],
        'competence_date' => $tx['tx_date'], 'paid_amount' => abs((float)$tx['amount']), 'paid_at' => $tx['tx_date'],
        'status' => 'paid', 'payment_method' => 'Asaas', 'created_at' => now(), 'updated_at' => now(),
    ]);
    reconcile_link($txId, $entryId);
    return $entryId;
}

function auto_reconcile(): int
{
    $count = 0;
    $feeCategory = db_value("SELECT id FROM categories WHERE dre_group = 'expense' AND name LIKE 'Tarifas%'");
    $pending = db_all('SELECT * FROM bank_transactions WHERE reconciled = 0 AND ignored = 0 ORDER BY tx_date');
    foreach ($pending as $tx) {
        $isFee = $tx['amount'] < 0 && (stripos((string)$tx['tx_type'], 'FEE') !== false || stripos((string)$tx['description'], 'taxa') === 0);
        if ($isFee && $feeCategory) {
            reconcile_create_entry((int)$tx['id'], (int)$feeCategory, 'Tarifa Asaas: ' . $tx['description']);
            $count++;
            continue;
        }
        $candidates = reconcile_candidates($tx);
        if ($candidates && ($candidates[0]['score'] >= 100 || count($candidates) === 1)) {
            reconcile_link((int)$tx['id'], (int)$candidates[0]['entry']['id']);
            $count++;
        }
    }
    return $count;
}

/* -------------------------------------------------------------- Cash flow */

function cash_realized_until(string $dateExclusive): float
{
    return (float)db_value(
        "SELECT COALESCE(SUM(CASE WHEN entry_type = 'receivable' THEN paid_amount ELSE -paid_amount END), 0)
         FROM financial_entries WHERE status = 'paid' AND paid_at < ?",
        [$dateExclusive]
    );
}

function cashflow_year(int $year): array
{
    $realized = db_all(
        "SELECT SUBSTR(paid_at, 6, 2) AS m, entry_type, SUM(paid_amount) AS total FROM financial_entries
         WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY SUBSTR(paid_at, 6, 2), entry_type",
        ["$year-01-01", "$year-12-31"]
    );
    $projected = db_all(
        "SELECT SUBSTR(due_date, 6, 2) AS m, entry_type, SUM(amount) AS total FROM financial_entries
         WHERE status = 'open' AND due_date BETWEEN ? AND ? GROUP BY SUBSTR(due_date, 6, 2), entry_type",
        ["$year-01-01", "$year-12-31"]
    );
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $months[$i] = ['month' => $i, 'in' => 0.0, 'out' => 0.0, 'in_projected' => 0.0, 'out_projected' => 0.0];
    }
    foreach ($realized as $r) $months[(int)$r['m']][$r['entry_type'] === 'receivable' ? 'in' : 'out'] += (float)$r['total'];
    foreach ($projected as $r) $months[(int)$r['m']][$r['entry_type'] === 'receivable' ? 'in_projected' : 'out_projected'] += (float)$r['total'];

    $opening = cash_realized_until("$year-01-01");
    $running = $opening;
    foreach ($months as &$mo) {
        $mo['net'] = round($mo['in'] - $mo['out'], 2);
        $mo['net_projected'] = round($mo['in'] + $mo['in_projected'] - $mo['out'] - $mo['out_projected'], 2);
        $running += $mo['net_projected'];
        $mo['balance'] = round($running, 2);
    }
    unset($mo);
    return ['year' => $year, 'opening_balance' => round($opening, 2), 'months' => array_values($months)];
}

function cashflow_projection(int $days): array
{
    $balance = cash_realized_until(date('Y-m-d', strtotime('+1 day')));
    $end = date('Y-m-d', strtotime("+$days days"));
    $overdue = db_one(
        "SELECT COALESCE(SUM(CASE WHEN entry_type='receivable' THEN amount ELSE 0 END),0) AS rin,
                COALESCE(SUM(CASE WHEN entry_type='payable' THEN amount ELSE 0 END),0) AS rout
         FROM financial_entries WHERE status = 'open' AND due_date < ?",
        [today()]
    );
    $rows = db_all(
        "SELECT due_date, entry_type, SUM(amount) AS total FROM financial_entries
         WHERE status = 'open' AND due_date BETWEEN ? AND ? GROUP BY due_date, entry_type ORDER BY due_date",
        [today(), $end]
    );
    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['due_date']][$r['entry_type']] = (float)$r['total'];
    }
    $series = [];
    $running = $balance;
    for ($d = 0; $d <= $days; $d++) {
        $date = date('Y-m-d', strtotime("+$d days"));
        $in = $byDay[$date]['receivable'] ?? 0;
        $out = $byDay[$date]['payable'] ?? 0;
        $running += $in - $out;
        $series[] = ['date' => $date, 'in' => $in, 'out' => $out, 'balance' => round($running, 2)];
    }
    return [
        'current_balance' => round($balance, 2),
        'overdue_receivable' => (float)$overdue['rin'],
        'overdue_payable' => (float)$overdue['rout'],
        'series' => $series,
    ];
}

/* ---------------------------------------------------------------- DRE / P&L */

function pnl(string $start, string $end): array
{
    $rows = db_all(
        "SELECT e.entry_type, COALESCE(c.dre_group, CASE WHEN e.entry_type='receivable' THEN 'other_income' ELSE 'expense' END) AS grp,
                COALESCE(c.name, 'Sem categoria') AS category, COALESCE(c.color, '#94a3b8') AS color, SUM(e.paid_amount) AS total
         FROM financial_entries e LEFT JOIN categories c ON c.id = e.category_id
         WHERE e.status = 'paid' AND e.paid_at BETWEEN ? AND ?
         GROUP BY e.entry_type, grp, category, color ORDER BY total DESC",
        [$start, $end]
    );
    $groups = ['revenue' => 0.0, 'other_income' => 0.0, 'tax' => 0.0, 'cost' => 0.0, 'expense' => 0.0, 'investment' => 0.0, 'distribution' => 0.0];
    $byCategory = [];
    foreach ($rows as $r) {
        $groups[$r['grp']] = ($groups[$r['grp']] ?? 0) + (float)$r['total'];
        $byCategory[] = ['group' => $r['grp'], 'type' => $r['entry_type'], 'category' => $r['category'], 'color' => $r['color'], 'total' => round((float)$r['total'], 2)];
    }
    $netRevenue = $groups['revenue'] - $groups['tax'];
    $grossProfit = $netRevenue - $groups['cost'];
    $operatingProfit = $grossProfit - $groups['expense'];
    $netProfit = $operatingProfit + $groups['other_income'];
    $pct = fn($v) => $groups['revenue'] > 0 ? round($v / $groups['revenue'] * 100, 1) : 0;

    return [
        'start' => $start, 'end' => $end,
        'groups' => array_map(fn($v) => round($v, 2), $groups),
        'net_revenue' => round($netRevenue, 2),
        'gross_profit' => round($grossProfit, 2),
        'operating_profit' => round($operatingProfit, 2),
        'net_profit' => round($netProfit, 2),
        'gross_margin' => $pct($grossProfit),
        'net_margin' => $pct($netProfit),
        'by_category' => $byCategory,
    ];
}

function pnl_monthly(int $months = 12): array
{
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $first = date('Y-m-01', strtotime(date('Y-m-01') . " -$i months"));
        $last = date('Y-m-t', strtotime($first));
        $p = pnl($first, $last);
        $out[] = [
            'month' => substr($first, 0, 7),
            'revenue' => $p['groups']['revenue'] + $p['groups']['other_income'],
            'expenses' => $p['groups']['tax'] + $p['groups']['cost'] + $p['groups']['expense'],
            'profit' => $p['net_profit'],
        ];
    }
    return $out;
}

/* ----------------------------------------------------- Partner distribution */

function distribution_preview(string $start, string $end, float $reservePercent): array
{
    if ($start > $end) fail('Período inválido.');
    if ($reservePercent < 0 || $reservePercent > 100) fail('A reserva deve estar entre 0% e 100%.');
    $partners = db_all('SELECT * FROM partners WHERE active = 1 ORDER BY share_percent DESC');
    $totalShare = array_sum(array_map(fn($p) => (float)$p['share_percent'], $partners));

    $p = pnl($start, $end);
    $already = (float)db_value(
        'SELECT COALESCE(SUM(distributable), 0) FROM distributions WHERE period_start <= ? AND period_end >= ?',
        [$end, $start]
    );
    $net = $p['net_profit'];
    $afterReserve = max(0, $net * (1 - $reservePercent / 100));
    $distributable = round(max(0, $afterReserve - $already), 2);

    $items = [];
    foreach ($partners as $partner) {
        $items[] = [
            'partner_id' => (int)$partner['id'], 'name' => $partner['name'], 'pix_key' => $partner['pix_key'],
            'share_percent' => (float)$partner['share_percent'],
            'amount' => $totalShare > 0 ? round($distributable * (float)$partner['share_percent'] / 100, 2) : 0,
        ];
    }
    return [
        'start' => $start, 'end' => $end, 'net_profit' => $net, 'reserve_percent' => $reservePercent,
        'reserve_amount' => round($net > 0 ? $net * $reservePercent / 100 : 0, 2),
        'already_distributed' => round($already, 2), 'distributable' => $distributable,
        'total_share' => $totalShare, 'items' => $items,
        'warning' => abs($totalShare - 100) > 0.01 ? 'A soma das participações dos sócios ativos é ' . $totalShare . '% (deveria ser 100%).' : null,
    ];
}

function create_distribution(string $start, string $end, float $reservePercent, string $paymentDate, ?string $notes): int
{
    $preview = distribution_preview($start, $end, $reservePercent);
    if ($preview['warning']) fail($preview['warning']);
    if ($preview['distributable'] <= 0) fail('Não há lucro disponível para distribuir neste período.');
    $categoryId = db_value("SELECT id FROM categories WHERE dre_group = 'distribution' LIMIT 1");
    $user = current_user();

    return db_transaction(function () use ($preview, $paymentDate, $notes, $categoryId, $user) {
        $distId = db_insert('distributions', [
            'period_start' => $preview['start'], 'period_end' => $preview['end'], 'net_profit' => $preview['net_profit'],
            'reserve_percent' => $preview['reserve_percent'], 'distributable' => $preview['distributable'],
            'notes' => $notes, 'created_by' => $user['name'] ?? null, 'created_at' => now(),
        ]);
        foreach ($preview['items'] as $item) {
            if ($item['amount'] <= 0) continue;
            $entryId = db_insert('financial_entries', [
                'entry_type' => 'payable',
                'description' => sprintf('Distribuição de lucros %s a %s - %s', date('d/m/Y', strtotime($preview['start'])), date('d/m/Y', strtotime($preview['end'])), $item['name']),
                'category_id' => $categoryId, 'supplier' => $item['name'], 'amount' => $item['amount'],
                'due_date' => $paymentDate, 'competence_date' => $preview['end'], 'status' => 'open',
                'payment_method' => 'PIX', 'distribution_id' => $distId,
                'notes' => $item['pix_key'] ? 'Chave PIX: ' . $item['pix_key'] : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            db_insert('distribution_items', [
                'distribution_id' => $distId, 'partner_id' => $item['partner_id'], 'share_percent' => $item['share_percent'],
                'amount' => $item['amount'], 'entry_id' => $entryId,
            ]);
        }
        audit('create', 'distribution', $distId, ['amount' => $preview['distributable']]);
        return $distId;
    });
}
