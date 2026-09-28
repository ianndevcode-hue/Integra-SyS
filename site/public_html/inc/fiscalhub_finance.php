<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — financeiro do cliente: contas bancárias, categorias, contas a pagar e a
 * receber (parcelas e recorrência), fluxo de caixa, DRE, extrato bancário (OFX, CSV ou Open
 * Finance via Pluggy) e conciliação. Everything is scoped by customer_id (the portal customer).
 *
 * Money rules: entry.amount is always positive; kind tells the direction. Statement lines
 * (fh_fin_transactions.amount) are signed: credit > 0, debit < 0. Account balance = opening
 * balance + paid receivables − paid payables booked on that account.
 * SQL is portable (SQLite/MySQL/MariaDB): no date functions; dates are compared as Y-m-d strings.
 */

const FHF_ACCOUNT_KINDS = ['checking' => 'Conta corrente', 'savings' => 'Poupança', 'payment' => 'Conta de pagamento / maquininha', 'cash' => 'Caixa (dinheiro)', 'credit_card' => 'Cartão de crédito', 'investment' => 'Aplicação financeira'];
const FHF_METHODS = ['pix' => 'PIX', 'boleto' => 'Boleto', 'transfer' => 'Transferência / TED', 'card' => 'Cartão', 'debit' => 'Débito automático', 'cash' => 'Dinheiro', 'check' => 'Cheque', 'other' => 'Outro'];
const FHF_DRE = ['revenue' => 'Receita de vendas e serviços', 'deduction' => 'Impostos sobre o faturamento', 'cost' => 'Custos (fornecedores e terceiros)', 'payroll' => 'Pessoal e pró-labore',
    'expense' => 'Despesas operacionais', 'financial' => 'Resultado financeiro', 'investment' => 'Investimentos', 'owner' => 'Sócios (aportes e retiradas)', 'other' => 'Outros'];

/* ================================================================ SETUP */

function fhf_default_categories(): array
{
    return [
        'income' => [['Venda de serviços', 'revenue'], ['Venda de produtos', 'revenue'], ['Receitas financeiras (rendimentos)', 'financial'], ['Aporte dos sócios', 'owner'], ['Outras receitas', 'other']],
        'expense' => [['Impostos (DAS, ISS e federais)', 'deduction'], ['Fornecedores e mercadorias', 'cost'], ['Serviços terceirizados', 'cost'], ['Salários e encargos', 'payroll'], ['Pró-labore', 'payroll'],
            ['Aluguel e condomínio', 'expense'], ['Energia, água, internet e telefone', 'expense'], ['Softwares e assinaturas', 'expense'], ['Marketing e publicidade', 'expense'], ['Contabilidade', 'expense'],
            ['Material de escritório e limpeza', 'expense'], ['Transporte e combustível', 'expense'], ['Manutenção e reparos', 'expense'], ['Tarifas bancárias', 'financial'], ['Juros e multas', 'financial'],
            ['Equipamentos e investimentos', 'investment'], ['Retirada dos sócios', 'owner'], ['Outras despesas', 'other']],
    ];
}

/** First access: default categories and a main account. Idempotent. */
function fhf_bootstrap(int $cid): void
{
    if (!(int)db_value('SELECT COUNT(*) FROM fh_fin_categories WHERE customer_id = ?', [$cid])) {
        foreach (fhf_default_categories() as $kind => $list) {
            foreach ($list as $i => [$name, $group]) {
                db_insert('fh_fin_categories', ['customer_id' => $cid, 'kind' => $kind, 'name' => $name, 'dre_group' => $group, 'active' => 1, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
    if (!(int)db_value('SELECT COUNT(*) FROM fh_fin_accounts WHERE customer_id = ?', [$cid])) {
        db_insert('fh_fin_accounts', ['customer_id' => $cid, 'name' => 'Conta principal', 'kind' => 'checking', 'opening_balance' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function fhf_settings(int $cid): array
{
    $s = json_decode((string)setting('fhf_cfg_' . $cid, ''), true) ?: [];
    return $s + ['auto_receivable' => true, 'receivable_days' => 0, 'receivable_account_id' => null, 'receivable_category_id' => null, 'use_net_amount' => true];
}

function fhf_settings_save(int $cid, array $in): array
{
    $cur = fhf_settings($cid);
    if (array_key_exists('auto_receivable', $in)) $cur['auto_receivable'] = filter_var($in['auto_receivable'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('use_net_amount', $in)) $cur['use_net_amount'] = filter_var($in['use_net_amount'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('receivable_days', $in)) $cur['receivable_days'] = max(0, min(180, (int)$in['receivable_days']));
    if (array_key_exists('receivable_account_id', $in)) $cur['receivable_account_id'] = $in['receivable_account_id'] ? fhf_account($cid, (int)$in['receivable_account_id'])['id'] : null;
    if (array_key_exists('receivable_category_id', $in)) $cur['receivable_category_id'] = $in['receivable_category_id'] ? fhf_category($cid, (int)$in['receivable_category_id'])['id'] : null;
    set_setting('fhf_cfg_' . $cid, json_encode($cur));
    return $cur;
}

/* ============================================================ LOOKUPS */

function fhf_account(int $cid, int $id): array
{
    $a = db_one('SELECT * FROM fh_fin_accounts WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$a) throw new AppException('Conta bancária não encontrada.');
    return $a;
}

function fhf_category(int $cid, int $id): array
{
    $c = db_one('SELECT * FROM fh_fin_categories WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$c) throw new AppException('Categoria não encontrada.');
    return $c;
}

function fhf_entry(int $cid, int $id): array
{
    $e = db_one('SELECT * FROM fh_fin_entries WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$e) throw new AppException('Lançamento não encontrado.');
    return $e;
}

function fhf_tx(int $cid, int $id): array
{
    $t = db_one('SELECT * FROM fh_fin_transactions WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$t) throw new AppException('Movimentação do extrato não encontrada.');
    return $t;
}

function fhf_default_account(int $cid): ?int
{
    $s = fhf_settings($cid);
    if ($s['receivable_account_id'] && db_value('SELECT id FROM fh_fin_accounts WHERE id = ? AND customer_id = ? AND active = 1', [$s['receivable_account_id'], $cid])) return (int)$s['receivable_account_id'];
    $id = db_value("SELECT id FROM fh_fin_accounts WHERE customer_id = ? AND active = 1 AND kind != 'credit_card' ORDER BY id LIMIT 1", [$cid]);
    return $id ? (int)$id : null;
}

function fhf_date(?string $v, ?string $default = null): ?string
{
    $v = trim((string)$v);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) return $v;
    return $default;
}

/** "1.234,56" | "1234.56" | 1234.56 → 1234.56 */
function fhf_money($v): float
{
    if (is_int($v) || is_float($v)) return round((float)$v, 2);
    $s = preg_replace('/[^\d,.\-]/', '', (string)$v);
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (substr_count($s, '.') > 1) $s = str_replace('.', '', $s);
    return round((float)$s, 2);
}

function fhf_add_months(string $date, int $n, ?int $day = null): string
{
    $day = $day ?: (int)substr($date, 8, 2);
    $ts = strtotime(substr($date, 0, 7) . '-01 +' . $n . ' months');
    return date('Y-m-', $ts) . sprintf('%02d', min($day, (int)date('t', $ts)));
}

/* ============================================================ ACCOUNTS */

/** Accounts with system balance (paid entries) and the last statement balance. */
function fhf_accounts(int $cid, bool $activeOnly = false): array
{
    $rows = db_all('SELECT * FROM fh_fin_accounts WHERE customer_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY active DESC, id', [$cid]);
    $bal = fhf_balances($cid);
    foreach ($rows as &$a) {
        $a['balance'] = $bal[(int)$a['id']] ?? round((float)$a['opening_balance'], 2);
        $a['pending'] = (int)db_value("SELECT COUNT(*) FROM fh_fin_transactions WHERE account_id = ? AND status = 'pending'", [$a['id']]);
        $a['kind_label'] = FHF_ACCOUNT_KINDS[$a['kind']] ?? $a['kind'];
        $a['stmt_balance'] = $a['stmt_balance'] === null ? null : (float)$a['stmt_balance'];
        $a['opening_balance'] = (float)$a['opening_balance'];
    }
    return $rows;
}

/** Balance per account id at the end of $until (inclusive; null = all time). */
function fhf_balances(int $cid, ?string $until = null): array
{
    $out = [];
    foreach (db_all('SELECT id, opening_balance, opening_date FROM fh_fin_accounts WHERE customer_id = ?', [$cid]) as $a) {
        $open = $a['opening_date'] && $until !== null && $until < $a['opening_date'] ? 0.0 : (float)$a['opening_balance'];
        $p = [$a['id']];
        $w = "account_id = ? AND status = 'paid'";
        if ($a['opening_date']) { $w .= ' AND paid_at >= ?'; $p[] = $a['opening_date']; }
        if ($until !== null) { $w .= ' AND paid_at <= ?'; $p[] = $until; }
        $sum = db_one("SELECT COALESCE(SUM(CASE WHEN kind = 'receivable' THEN paid_amount ELSE 0 END), 0) AS inc, COALESCE(SUM(CASE WHEN kind = 'payable' THEN paid_amount ELSE 0 END), 0) AS outg FROM fh_fin_entries WHERE $w", $p);
        $out[(int)$a['id']] = round($open + (float)$sum['inc'] - (float)$sum['outg'], 2);
    }
    return $out;
}

function fhf_account_save(int $cid, array $in, ?array $existing): array
{
    $d = [];
    foreach (['name' => 120, 'kind' => 20, 'bank_code' => 10, 'bank_name' => 120, 'agency' => 20, 'account_number' => 30, 'color' => 20] as $f => $max) if (array_key_exists($f, $in)) $d[$f] = mb_substr(trim((string)$in[$f]), 0, $max) ?: null;
    if (!$existing && empty($d['name'])) throw new AppException('Dê um nome para a conta (ex.: Banco do Brasil, Caixa da loja).');
    if (array_key_exists('name', $d) && !$d['name']) throw new AppException('Dê um nome para a conta.');
    if (isset($d['kind']) && !isset(FHF_ACCOUNT_KINDS[$d['kind']])) $d['kind'] = 'checking';
    if (array_key_exists('opening_balance', $in)) $d['opening_balance'] = fhf_money($in['opening_balance']);
    if (array_key_exists('opening_date', $in)) $d['opening_date'] = fhf_date($in['opening_date']);
    if (array_key_exists('emitter_id', $in)) $d['emitter_id'] = $in['emitter_id'] ? (int)fh_emitter($cid, (int)$in['emitter_id'])['id'] : null;
    if (array_key_exists('active', $in)) $d['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if ($existing) {
        db_update('fh_fin_accounts', (int)$existing['id'], $d + ['updated_at' => now()]);
        return fhf_account($cid, (int)$existing['id']);
    }
    return fhf_account($cid, db_insert('fh_fin_accounts', $d + ['customer_id' => $cid, 'kind' => $d['kind'] ?? 'checking', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]));
}

function fhf_account_delete(int $cid, int $id): string
{
    $a = fhf_account($cid, $id);
    $used = (int)db_value('SELECT COUNT(*) FROM fh_fin_entries WHERE account_id = ?', [$id]) + (int)db_value('SELECT COUNT(*) FROM fh_fin_transactions WHERE account_id = ?', [$id]);
    if ($used) {
        db_update('fh_fin_accounts', $id, ['active' => 0, 'updated_at' => now()]);
        return 'deactivated';
    }
    db_exec('DELETE FROM fh_fin_accounts WHERE id = ?', [$a['id']]);
    return 'deleted';
}

/** Transfer between own accounts: a paid payable on the origin and a paid receivable on the destination. */
function fhf_transfer(int $cid, array $in): array
{
    $from = fhf_account($cid, (int)($in['from_account_id'] ?? 0));
    $to = fhf_account($cid, (int)($in['to_account_id'] ?? 0));
    if ($from['id'] === $to['id']) throw new AppException('Escolha contas diferentes.');
    $amount = fhf_money($in['amount'] ?? 0);
    if ($amount <= 0) throw new AppException('Informe o valor da transferência.');
    $date = fhf_date($in['date'] ?? '', today());
    $key = 'tr-' . bin2hex(random_bytes(6));
    $desc = mb_substr(trim((string)($in['description'] ?? '')) ?: "Transferência {$from['name']} → {$to['name']}", 0, 255);
    $base = ['customer_id' => $cid, 'description' => $desc, 'amount' => $amount, 'due_date' => $date, 'competence_date' => $date, 'status' => 'paid', 'paid_at' => $date, 'paid_amount' => $amount,
        'payment_method' => 'transfer', 'series_key' => $key, 'origin' => 'transfer', 'created_at' => now(), 'updated_at' => now()];
    $out = db_insert('fh_fin_entries', $base + ['kind' => 'payable', 'account_id' => $from['id']]);
    $in2 = db_insert('fh_fin_entries', $base + ['kind' => 'receivable', 'account_id' => $to['id']]);
    return ['out_id' => $out, 'in_id' => $in2, 'series_key' => $key];
}

/* ========================================================== CATEGORIES */

function fhf_categories(int $cid): array
{
    return db_all('SELECT c.*, (SELECT COUNT(*) FROM fh_fin_entries e WHERE e.category_id = c.id) AS uses FROM fh_fin_categories c WHERE c.customer_id = ? ORDER BY c.kind, c.active DESC, c.position, c.name', [$cid]);
}

function fhf_category_save(int $cid, array $in, ?array $existing): array
{
    $d = [];
    if (array_key_exists('name', $in)) $d['name'] = mb_substr(trim((string)$in['name']), 0, 120);
    if ((!$existing || array_key_exists('name', $in)) && mb_strlen($d['name'] ?? '') < 2) throw new AppException('Informe o nome da categoria.');
    if (!$existing) $d['kind'] = ($in['kind'] ?? '') === 'income' ? 'income' : 'expense';
    if (array_key_exists('dre_group', $in)) $d['dre_group'] = isset(FHF_DRE[$in['dre_group']]) ? $in['dre_group'] : 'other';
    if (array_key_exists('color', $in)) $d['color'] = mb_substr((string)$in['color'], 0, 20) ?: null;
    if (array_key_exists('active', $in)) $d['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if ($existing) {
        db_update('fh_fin_categories', (int)$existing['id'], $d + ['updated_at' => now()]);
        return fhf_category($cid, (int)$existing['id']);
    }
    $pos = 1 + (int)db_value('SELECT COALESCE(MAX(position), 0) FROM fh_fin_categories WHERE customer_id = ? AND kind = ?', [$cid, $d['kind']]);
    return fhf_category($cid, db_insert('fh_fin_categories', $d + ['customer_id' => $cid, 'dre_group' => $d['dre_group'] ?? ($d['kind'] === 'income' ? 'revenue' : 'expense'), 'active' => 1, 'position' => $pos, 'created_at' => now(), 'updated_at' => now()]));
}

function fhf_category_delete(int $cid, int $id): string
{
    fhf_category($cid, $id);
    if ((int)db_value('SELECT COUNT(*) FROM fh_fin_entries WHERE category_id = ?', [$id])) {
        db_update('fh_fin_categories', $id, ['active' => 0, 'updated_at' => now()]);
        return 'deactivated';
    }
    db_exec('DELETE FROM fh_fin_categories WHERE id = ?', [$id]);
    db_exec('UPDATE fh_fin_rules SET category_id = NULL WHERE category_id = ?', [$id]);
    return 'deleted';
}

/* ============================================================== ENTRIES */

/** Adds the virtual "overdue" status and labels used by the portal. */
function fhf_entry_public(array $e): array
{
    $e['amount'] = (float)$e['amount'];
    $e['paid_amount'] = $e['paid_amount'] === null ? null : (float)$e['paid_amount'];
    $e['interest'] = (float)$e['interest'];
    $e['discount'] = (float)$e['discount'];
    $e['display_status'] = $e['status'] === 'open' && $e['due_date'] < today() ? 'overdue' : $e['status'];
    $e['days_late'] = $e['display_status'] === 'overdue' ? (int)floor((strtotime(today()) - strtotime($e['due_date'])) / 86400) : 0;
    return $e;
}

/**
 * List with filters: kind, status (open|overdue|pending|paid|canceled), from/to (on date_field:
 * due|paid|competence), category_id, account_id, emitter_id, q, sort, dir, page, per_page.
 */
function fhf_entries_query(int $cid, array $q, bool $paginate = true): array
{
    $w = ['e.customer_id = ?'];
    $p = [$cid];
    if (in_array($q['kind'] ?? '', ['receivable', 'payable'], true)) { $w[] = 'e.kind = ?'; $p[] = $q['kind']; }
    $st = (string)($q['status'] ?? '');
    if ($st === 'overdue') { $w[] = "e.status = 'open' AND e.due_date < ?"; $p[] = today(); }
    elseif ($st === 'pending' || $st === 'open') { $w[] = "e.status = 'open'"; }
    elseif (in_array($st, ['paid', 'canceled'], true)) { $w[] = 'e.status = ?'; $p[] = $st; }
    elseif ($st === '') { $w[] = "e.status != 'canceled'"; }
    $field = ['paid' => 'e.paid_at', 'competence' => 'COALESCE(e.competence_date, e.due_date)'][$q['date_field'] ?? ''] ?? 'e.due_date';
    if ($from = fhf_date($q['from'] ?? '')) { $w[] = "$field >= ?"; $p[] = $from; }
    if ($to = fhf_date($q['to'] ?? '')) { $w[] = "$field <= ?"; $p[] = $to; }
    foreach (['category_id', 'account_id', 'emitter_id', 'taker_id'] as $f) if (!empty($q[$f])) { $w[] = "e.$f = ?"; $p[] = (int)$q[$f]; }
    if (($q['origin'] ?? '') !== '') { $w[] = 'e.origin = ?'; $p[] = (string)$q['origin']; }
    if (empty($q['include_transfers'])) $w[] = "e.origin != 'transfer'";
    if (!empty($q['ids'])) {
        $ids = array_values(array_filter(array_map('intval', is_array($q['ids']) ? $q['ids'] : explode(',', (string)$q['ids']))));
        $w[] = $ids ? 'e.id IN (' . implode(',', $ids) . ')' : '1 = 0';
    }
    if (($s = trim((string)($q['q'] ?? ''))) !== '') {
        $like = '%' . mb_strtolower($s) . '%';
        $w[] = '(LOWER(e.description) LIKE ? OR LOWER(e.party_name) LIKE ? OR LOWER(e.document_number) LIKE ? OR e.party_document LIKE ?)';
        array_push($p, $like, $like, $like, '%' . (only_digits($s) ?: '#') . '%');
    }
    $where = implode(' AND ', $w);
    $sorts = ['due_date' => 'e.due_date', 'amount' => 'e.amount', 'description' => 'e.description', 'party_name' => 'e.party_name', 'paid_at' => 'e.paid_at', 'created_at' => 'e.created_at'];
    $sort = $sorts[$q['sort'] ?? ''] ?? 'e.due_date';
    $dir = strtolower((string)($q['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
    $sql = "SELECT e.*, c.name AS category_name, c.color AS category_color, a.name AS account_name FROM fh_fin_entries e LEFT JOIN fh_fin_categories c ON c.id = e.category_id LEFT JOIN fh_fin_accounts a ON a.id = e.account_id WHERE $where ORDER BY $sort $dir, e.id $dir";
    if (!$paginate) return array_map('fhf_entry_public', db_all($sql . ' LIMIT 5000', $p));
    $per = max(1, min(200, (int)($q['per_page'] ?? 25)));
    $page = max(1, (int)($q['page'] ?? 1));
    $sum = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS amount, COALESCE(SUM(CASE WHEN e.status = 'paid' THEN e.paid_amount ELSE 0 END), 0) AS paid,
        COALESCE(SUM(CASE WHEN e.status = 'open' THEN e.amount ELSE 0 END), 0) AS open, COALESCE(SUM(CASE WHEN e.status = 'open' AND e.due_date < ? THEN e.amount ELSE 0 END), 0) AS overdue
        FROM fh_fin_entries e WHERE $where", array_merge([today()], $p));
    return ['data' => array_map('fhf_entry_public', db_all($sql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p)), 'total' => (int)$sum['n'],
        'sum' => ['amount' => round((float)$sum['amount'], 2), 'paid' => round((float)$sum['paid'], 2), 'open' => round((float)$sum['open'], 2), 'overdue' => round((float)$sum['overdue'], 2)]];
}

/** Validated entry fields (shared by create and update). */
function fhf_entry_fields(int $cid, array $in, ?array $existing): array
{
    $d = [];
    if (!$existing) $d['kind'] = ($in['kind'] ?? '') === 'payable' ? 'payable' : 'receivable';
    $kind = $d['kind'] ?? $existing['kind'];
    if (array_key_exists('description', $in)) $d['description'] = mb_substr(trim((string)$in['description']), 0, 255);
    if ((!$existing || array_key_exists('description', $in)) && mb_strlen($d['description'] ?? '') < 2) throw new AppException('Descreva o lançamento.');
    if (array_key_exists('amount', $in)) $d['amount'] = fhf_money($in['amount']);
    if ((!$existing || array_key_exists('amount', $in)) && ($d['amount'] ?? 0) <= 0) throw new AppException('Informe o valor.');
    if (array_key_exists('due_date', $in) || !$existing) {
        $d['due_date'] = fhf_date($in['due_date'] ?? '');
        if (!$d['due_date']) throw new AppException('Informe a data de vencimento.');
    }
    if (array_key_exists('competence_date', $in)) $d['competence_date'] = fhf_date($in['competence_date']);
    if (array_key_exists('category_id', $in)) {
        if ($in['category_id']) {
            $cat = fhf_category($cid, (int)$in['category_id']);
            if ($cat['kind'] !== ($kind === 'receivable' ? 'income' : 'expense')) throw new AppException($kind === 'receivable' ? 'Escolha uma categoria de receita.' : 'Escolha uma categoria de despesa.');
            $d['category_id'] = (int)$cat['id'];
        } else $d['category_id'] = null;
    }
    if (array_key_exists('account_id', $in)) $d['account_id'] = $in['account_id'] ? (int)fhf_account($cid, (int)$in['account_id'])['id'] : null;
    if (array_key_exists('emitter_id', $in)) $d['emitter_id'] = $in['emitter_id'] ? (int)fh_emitter($cid, (int)$in['emitter_id'])['id'] : null;
    if (array_key_exists('taker_id', $in)) {
        $d['taker_id'] = null;
        if ($in['taker_id']) {
            $t = db_one('SELECT t.* FROM fh_takers t JOIN fh_emitters e ON e.id = t.emitter_id WHERE t.id = ? AND e.customer_id = ?', [(int)$in['taker_id'], $cid]);
            if (!$t) throw new AppException('Cliente não encontrado.');
            $d['taker_id'] = (int)$t['id'];
            if (empty($in['party_name'])) { $d['party_name'] = $t['name']; $d['party_document'] = $t['document']; }
        }
    }
    foreach (['party_name' => 200, 'document_number' => 60, 'cost_center' => 120, 'tags' => 255] as $f => $max) if (array_key_exists($f, $in)) $d[$f] = mb_substr(trim((string)$in[$f]), 0, $max) ?: null;
    if (array_key_exists('party_document', $in)) $d['party_document'] = substr(only_digits((string)$in['party_document']), 0, 20) ?: null;
    if (array_key_exists('notes', $in)) $d['notes'] = mb_substr(trim((string)$in['notes']), 0, 4000) ?: null;
    if (array_key_exists('payment_method', $in)) $d['payment_method'] = isset(FHF_METHODS[$in['payment_method']]) ? $in['payment_method'] : null;
    return $d;
}

/**
 * Create one entry, N installments (amount split, monthly) or a monthly/weekly/yearly recurrence
 * (same amount repeated N times). Optional "paid" creates it already settled.
 * @return array created entries
 */
function fhf_entry_create(int $cid, array $in): array
{
    $d = fhf_entry_fields($cid, $in, null);
    $installments = max(1, min(120, (int)($in['installments'] ?? 1)));
    $recurrence = in_array($in['recurrence'] ?? '', ['weekly', 'monthly', 'yearly'], true) ? $in['recurrence'] : null;
    $repeat = $recurrence ? max(2, min(120, (int)($in['repeat'] ?? 12))) : 1;
    if ($installments > 1 && $recurrence) throw new AppException('Escolha parcelas OU recorrência, não os dois.');
    $count = max($installments, $repeat);
    $series = $count > 1 ? ($recurrence ? 'rc-' : 'pc-') . bin2hex(random_bytes(6)) : null;
    $amounts = array_fill(0, $count, $d['amount']);
    if ($installments > 1) {
        $cents = (int)round($d['amount'] * 100);
        $base = intdiv($cents, $installments);
        $amounts = array_fill(0, $installments, $base / 100);
        $amounts[0] = ($base + $cents - $base * $installments) / 100;
    }
    $paid = !empty($in['paid']);
    $day = (int)substr($d['due_date'], 8, 2);
    $ids = [];
    db_transaction(function () use ($cid, $d, $count, $amounts, $series, $recurrence, $installments, $day, $paid, $in, &$ids) {
        for ($i = 0; $i < $count; $i++) {
            $due = $i === 0 ? $d['due_date'] : ($recurrence === 'weekly' ? date('Y-m-d', strtotime($d['due_date'] . ' +' . (7 * $i) . ' days')) : ($recurrence === 'yearly' ? fhf_add_months($d['due_date'], 12 * $i, $day) : fhf_add_months($d['due_date'], $i, $day)));
            $row = $d + ['customer_id' => $cid, 'status' => 'open', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()];
            $row['amount'] = $amounts[$i];
            $row['due_date'] = $due;
            if ($installments > 1) {
                $row['installment'] = $i + 1;
                $row['installments'] = $installments;
                $row['description'] = mb_substr($d['description'] . ' (' . ($i + 1) . '/' . $installments . ')', 0, 255);
            }
            if ($series) { $row['series_key'] = $series; $row['recurrence'] = $recurrence; }
            if (!empty($d['competence_date']) && $i > 0) $row['competence_date'] = $recurrence === 'weekly' ? $due : fhf_add_months($d['competence_date'], $recurrence === 'yearly' ? 12 * $i : $i);
            if ($paid && $i === 0) {
                $row['status'] = 'paid';
                $row['paid_at'] = fhf_date($in['paid_at'] ?? '', $due);
                $row['paid_amount'] = $row['amount'];
                $row['account_id'] = $row['account_id'] ?? fhf_default_account($cid);
            }
            $ids[] = db_insert('fh_fin_entries', $row);
        }
    });
    return array_map(fn($id) => fhf_entry_public(fhf_entry($cid, $id)), $ids);
}

/** Update one entry; with apply_series=true the text/category/amount also go to the next open ones of the series. */
function fhf_entry_update(int $cid, int $id, array $in): array
{
    $e = fhf_entry($cid, $id);
    if ($e['origin'] === 'transfer') throw new AppException('Transferências entre contas não podem ser editadas; exclua e lance de novo.');
    $d = fhf_entry_fields($cid, $in, $e);
    if ($e['status'] === 'paid' && isset($d['amount']) && abs($d['amount'] - (float)$e['amount']) > 0.004 && $e['transaction_id']) throw new AppException('Este lançamento está conciliado com o extrato. Desfaça a conciliação para alterar o valor.');
    if ($e['status'] === 'paid' && array_key_exists('paid_at', $in) && ($pa = fhf_date($in['paid_at']))) $d['paid_at'] = $pa;
    if ($e['status'] === 'paid' && array_key_exists('paid_amount', $in) && !$e['transaction_id']) $d['paid_amount'] = fhf_money($in['paid_amount']);
    db_update('fh_fin_entries', $id, $d + ['updated_at' => now()]);
    if (!empty($in['apply_series']) && $e['series_key']) {
        $keys = ['category_id', 'account_id', 'party_name', 'party_document', 'taker_id', 'cost_center', 'payment_method', 'tags', 'notes'];
        if (!$e['installments']) array_push($keys, 'description', 'amount'); // recurrences share text and amount; installments keep their own
        $share = array_intersect_key($d, array_flip($keys));
        if ($share) fhf_update_where('fh_fin_entries', $share + ['updated_at' => now()], 'series_key = ? AND customer_id = ? AND status = ? AND due_date > ?', [$e['series_key'], $cid, 'open', $e['due_date']]);
    }
    return fhf_entry_public(fhf_entry($cid, $id));
}

/** UPDATE with a custom WHERE (db_update works by id only). */
function fhf_update_where(string $table, array $data, string $where, array $params): void
{
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
    db_exec("UPDATE $table SET $sets WHERE $where", array_merge(array_values($data), $params));
}

/**
 * Settle an entry: paid_at, paid_amount (default amount + interest − discount), account_id,
 * payment_method. A smaller payment with keep_remainder=true leaves the rest as a new open entry.
 */
function fhf_entry_pay(int $cid, int $id, array $in): array
{
    $e = fhf_entry($cid, $id);
    if ($e['status'] !== 'open') throw new AppException($e['status'] === 'paid' ? 'Este lançamento já está baixado.' : 'Lançamento cancelado.');
    $interest = max(0, fhf_money($in['interest'] ?? 0));
    $discount = max(0, fhf_money($in['discount'] ?? 0));
    $expected = round((float)$e['amount'] + $interest - $discount, 2);
    $paidAmount = isset($in['paid_amount']) && $in['paid_amount'] !== '' ? fhf_money($in['paid_amount']) : $expected;
    if ($paidAmount <= 0) throw new AppException('Informe o valor pago.');
    $account = !empty($in['account_id']) ? (int)fhf_account($cid, (int)$in['account_id'])['id'] : ($e['account_id'] ? (int)$e['account_id'] : fhf_default_account($cid));
    if (!$account) throw new AppException('Cadastre uma conta bancária ou caixa para registrar o pagamento.');
    $date = fhf_date($in['paid_at'] ?? '', today());
    if ($date > date('Y-m-d', strtotime('+1 day'))) throw new AppException('A data do pagamento não pode ser futura.');
    $remainder = round($expected - $paidAmount, 2);
    db_transaction(function () use ($e, $cid, $in, $interest, $discount, $paidAmount, $account, $date, $remainder) {
        $upd = ['status' => 'paid', 'paid_at' => $date, 'paid_amount' => $paidAmount, 'interest' => $interest, 'discount' => $discount, 'account_id' => $account, 'updated_at' => now()];
        if (!empty($in['payment_method']) && isset(FHF_METHODS[$in['payment_method']])) $upd['payment_method'] = $in['payment_method'];
        if ($remainder > 0.004 && !empty($in['keep_remainder'])) {
            $upd['amount'] = round($paidAmount - $interest + $discount, 2);
            $rest = $e;
            unset($rest['id']);
            db_insert('fh_fin_entries', array_merge($rest, ['amount' => $remainder, 'status' => 'open', 'paid_at' => null, 'paid_amount' => null, 'interest' => 0, 'discount' => 0, 'transaction_id' => null,
                'description' => mb_substr($e['description'] . ' (saldo restante)', 0, 255), 'created_at' => now(), 'updated_at' => now()]));
        }
        db_update('fh_fin_entries', (int)$e['id'], $upd);
    });
    return fhf_entry_public(fhf_entry($cid, $id));
}

function fhf_entry_reopen(int $cid, int $id): array
{
    $e = fhf_entry($cid, $id);
    if ($e['origin'] === 'transfer') throw new AppException('Exclua a transferência em vez de reabrir.');
    db_transaction(function () use ($e) {
        if ($e['transaction_id']) fhf_tx_release((int)$e['transaction_id'], (int)$e['id']);
        db_update('fh_fin_entries', (int)$e['id'], ['status' => 'open', 'paid_at' => null, 'paid_amount' => null, 'interest' => 0, 'discount' => 0, 'transaction_id' => null, 'updated_at' => now()]);
    });
    return fhf_entry_public(fhf_entry($cid, $id));
}

function fhf_entry_cancel(int $cid, int $id): array
{
    $e = fhf_entry($cid, $id);
    if ($e['status'] === 'paid') throw new AppException('Reabra o lançamento antes de cancelar (ele está baixado).');
    db_update('fh_fin_entries', $id, ['status' => 'canceled', 'updated_at' => now()]);
    return fhf_entry_public(fhf_entry($cid, $id));
}

/** Delete one entry (or the rest of its series with scope=series). Transfers delete both sides. */
function fhf_entry_delete(int $cid, int $id, string $scope = 'one'): int
{
    $e = fhf_entry($cid, $id);
    $rows = [$e];
    if ($e['origin'] === 'transfer' && $e['series_key']) $rows = db_all('SELECT * FROM fh_fin_entries WHERE series_key = ? AND customer_id = ?', [$e['series_key'], $cid]);
    elseif ($scope === 'series' && $e['series_key']) $rows = db_all("SELECT * FROM fh_fin_entries WHERE series_key = ? AND customer_id = ? AND (id = ? OR (status = 'open' AND due_date >= ?))", [$e['series_key'], $cid, $id, $e['due_date']]);
    db_transaction(function () use ($rows) {
        foreach ($rows as $r) {
            if ($r['transaction_id']) fhf_tx_release((int)$r['transaction_id'], (int)$r['id']);
            db_exec('DELETE FROM fh_fin_entries WHERE id = ?', [$r['id']]);
        }
    });
    return count($rows);
}

/** Party names for autocomplete (entries + NFS-e customers). */
function fhf_parties(int $cid, string $q, string $kind = ''): array
{
    $like = '%' . mb_strtolower($q) . '%';
    $out = [];
    foreach (db_all('SELECT party_name AS name, MAX(party_document) AS document FROM fh_fin_entries WHERE customer_id = ? AND party_name IS NOT NULL AND LOWER(party_name) LIKE ?' . ($kind ? ' AND kind = ?' : '') . ' GROUP BY party_name ORDER BY party_name LIMIT 15',
        $kind ? [$cid, $like, $kind] : [$cid, $like]) as $r) $out[mb_strtolower($r['name'])] = ['name' => $r['name'], 'document' => $r['document'], 'taker_id' => null];
    if ($kind !== 'payable') {
        foreach (db_all('SELECT t.id, t.name, t.document FROM fh_takers t JOIN fh_emitters e ON e.id = t.emitter_id WHERE e.customer_id = ? AND LOWER(t.name) LIKE ? ORDER BY t.name LIMIT 15', [$cid, $like]) as $t) {
            $out[mb_strtolower($t['name'])] = ['name' => $t['name'], 'document' => $t['document'], 'taker_id' => (int)$t['id']];
        }
    }
    return array_values($out);
}

/* ======================================================= NFS-e HOOKS */

/** Authorized NFS-e → receivable (net of withholdings), once per invoice. */
function fhf_on_invoice_authorized(array $inv): void
{
    $cid = (int)$inv['customer_id'];
    $s = fhf_settings($cid);
    if (!$s['auto_receivable'] || ($inv['environment'] ?? '') !== 'production') return;
    if (db_value('SELECT id FROM fh_fin_entries WHERE invoice_id = ? AND customer_id = ?', [$inv['id'], $cid])) return;
    fhf_bootstrap($cid);
    fhf_invoice_entry($cid, $inv, $s);
}

function fhf_invoice_entry(int $cid, array $inv, array $s): int
{
    $cat = $s['receivable_category_id'] && db_value('SELECT id FROM fh_fin_categories WHERE id = ? AND customer_id = ?', [$s['receivable_category_id'], $cid])
        ? (int)$s['receivable_category_id']
        : (int)(db_value("SELECT id FROM fh_fin_categories WHERE customer_id = ? AND kind = 'income' AND active = 1 AND dre_group = 'revenue' ORDER BY position LIMIT 1", [$cid]) ?: 0);
    $issued = substr((string)($inv['issued_at'] ?: now()), 0, 10);
    $amount = round((float)($s['use_net_amount'] ? ($inv['net_amount'] ?: $inv['amount']) : $inv['amount']), 2);
    $num = $inv['nfse_number'] ?: ('DPS ' . $inv['dps_number']);
    return db_insert('fh_fin_entries', ['customer_id' => $cid, 'emitter_id' => $inv['emitter_id'], 'kind' => 'receivable', 'description' => mb_substr('NFS-e nº ' . $num . ' — ' . ($inv['service_name'] ?: mb_substr((string)$inv['description'], 0, 60)), 0, 255),
        'category_id' => $cat ?: null, 'account_id' => $s['receivable_account_id'] ?: null, 'party_name' => $inv['toma_name'], 'party_document' => only_digits((string)$inv['toma_document']) ?: null, 'taker_id' => $inv['taker_id'] ?: null,
        'invoice_id' => $inv['id'], 'amount' => $amount, 'due_date' => (json_decode((string)($inv['extra'] ?? ''), true)['vencimento'] ?? null) ?: date('Y-m-d', strtotime($issued . ' +' . (int)$s['receivable_days'] . ' days')), 'competence_date' => $inv['competence_date'] ?: $issued,
        'status' => 'open', 'document_number' => 'NFS-e ' . $num, 'origin' => 'invoice', 'created_at' => now(), 'updated_at' => now()]);
}

/** Canceled NFS-e → cancel its open receivable (paid ones stay, with a note). */
function fhf_on_invoice_canceled(array $inv): void
{
    foreach (db_all('SELECT * FROM fh_fin_entries WHERE invoice_id = ? AND customer_id = ?', [$inv['id'], $inv['customer_id']]) as $e) {
        if ($e['status'] === 'open') db_update('fh_fin_entries', (int)$e['id'], ['status' => 'canceled', 'notes' => trim(($e['notes'] ?? '') . "\nNota fiscal cancelada em " . date('d/m/Y') . '.'), 'updated_at' => now()]);
        elseif ($e['status'] === 'paid') db_update('fh_fin_entries', (int)$e['id'], ['notes' => trim(($e['notes'] ?? '') . "\nAtenção: a nota fiscal foi cancelada em " . date('d/m/Y') . ' depois do recebimento.'), 'updated_at' => now()]);
    }
}

/** Receivables for authorized invoices of the last 12 months that have none yet. */
function fhf_import_invoices(int $cid): int
{
    $s = fhf_settings($cid);
    $n = 0;
    foreach (db_all("SELECT i.* FROM fh_invoices i WHERE i.customer_id = ? AND i.status = 'authorized' AND i.environment = 'production' AND i.issued_at >= ?
        AND NOT EXISTS (SELECT 1 FROM fh_fin_entries e WHERE e.invoice_id = i.id) ORDER BY i.issued_at", [$cid, date('Y-m-d', strtotime('-12 months'))]) as $inv) {
        fhf_invoice_entry($cid, $inv, $s);
        $n++;
    }
    return $n;
}

/* ============================================================ REPORTS */

/** Portal dashboard numbers. */
function fhf_summary(int $cid): array
{
    $today = today();
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $accounts = fhf_accounts($cid, true);
    $total = round(array_sum(array_map(fn($a) => $a['kind'] === 'credit_card' ? 0 : $a['balance'], $accounts)), 2);
    $sum = function (string $kind, string $where, array $params) use ($cid) {
        $r = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM fh_fin_entries WHERE customer_id = ? AND kind = ? AND origin != 'transfer' AND $where", array_merge([$cid, $kind], $params));
        return ['count' => (int)$r['n'], 'amount' => round((float)$r['amount'], 2)];
    };
    $paid = function (string $kind, string $from, string $to) use ($cid) {
        return round((float)db_value("SELECT COALESCE(SUM(paid_amount), 0) FROM fh_fin_entries WHERE customer_id = ? AND kind = ? AND status = 'paid' AND origin != 'transfer' AND paid_at >= ? AND paid_at <= ?", [$cid, $kind, $from, $to]), 2);
    };
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("first day of -$i months"));
        $months[] = ['month' => $m, 'in' => $paid('receivable', "$m-01", date('Y-m-t', strtotime("$m-01"))), 'out' => $paid('payable', "$m-01", date('Y-m-t', strtotime("$m-01")))];
    }
    $proj = [];
    foreach ([30, 60, 90] as $d) {
        $until = date('Y-m-d', strtotime("+$d days"));
        $in = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM fh_fin_entries WHERE customer_id = ? AND kind = 'receivable' AND status = 'open' AND due_date <= ?", [$cid, $until]);
        $out = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM fh_fin_entries WHERE customer_id = ? AND kind = 'payable' AND status = 'open' AND due_date <= ?", [$cid, $until]);
        $proj[] = ['days' => $d, 'in' => round($in, 2), 'out' => round($out, 2), 'balance' => round($total + $in - $out, 2)];
    }
    return [
        'balance' => $total, 'accounts' => $accounts,
        'receivable' => ['month' => $sum('receivable', "status = 'open' AND due_date >= ? AND due_date <= ?", [$monthStart, $monthEnd]), 'overdue' => $sum('receivable', "status = 'open' AND due_date < ?", [$today]), 'today' => $sum('receivable', "status = 'open' AND due_date = ?", [$today])],
        'payable' => ['month' => $sum('payable', "status = 'open' AND due_date >= ? AND due_date <= ?", [$monthStart, $monthEnd]), 'overdue' => $sum('payable', "status = 'open' AND due_date < ?", [$today]), 'today' => $sum('payable', "status = 'open' AND due_date = ?", [$today])],
        'month' => ['in' => $paid('receivable', $monthStart, $monthEnd), 'out' => $paid('payable', $monthStart, $monthEnd)],
        'months' => $months, 'projection' => $proj,
        'upcoming' => array_map('fhf_entry_public', db_all("SELECT e.*, c.name AS category_name FROM fh_fin_entries e LEFT JOIN fh_fin_categories c ON c.id = e.category_id WHERE e.customer_id = ? AND e.status = 'open' AND e.origin != 'transfer' AND e.due_date <= ? ORDER BY e.due_date, e.id LIMIT 12", [$cid, date('Y-m-d', strtotime('+7 days'))])),
        'pending_reconciliation' => (int)db_value("SELECT COUNT(*) FROM fh_fin_transactions WHERE customer_id = ? AND status = 'pending'", [$cid]),
        'expiring' => array_map(fn($c) => ['id' => (int)$c['id'], 'provider' => $c['provider'], 'label' => $c['label'] ?: $c['connector_name'], 'consent_expires_at' => $c['consent_expires_at'], 'expired' => $c['consent_expires_at'] < now()],
            db_all('SELECT id, provider, label, connector_name, consent_expires_at FROM fh_fin_connections WHERE customer_id = ? AND consent_expires_at IS NOT NULL AND consent_expires_at <= ?', [$cid, date('Y-m-d H:i:s', strtotime('+30 days'))])),
    ];
}

/**
 * Cash flow between $from and $to grouped by day|week|month: realized (paid) and projected (open,
 * by due date; overdue items are projected on today). Starting balance = balances at $from − 1 day.
 */
function fhf_cashflow(int $cid, string $from, string $to, string $group = 'day', ?int $accountId = null): array
{
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 731) $to = date('Y-m-d', strtotime($from . ' +731 days'));
    $group = in_array($group, ['day', 'week', 'month'], true) ? $group : 'day';
    $bal = fhf_balances($cid, date('Y-m-d', strtotime($from . ' -1 day')));
    $cardIds = array_map('intval', fhf_column("SELECT id FROM fh_fin_accounts WHERE customer_id = ? AND kind = 'credit_card'", [$cid]));
    $start = $accountId ? ($bal[$accountId] ?? 0.0) : array_sum(array_diff_key($bal, array_flip($cardIds)));
    $key = function (string $d) use ($group): string {
        if ($group === 'month') return substr($d, 0, 7);
        if ($group === 'week') return date('o-\WW', strtotime($d));
        return $d;
    };
    $buckets = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $k = $key($d);
        if (!isset($buckets[$k])) $buckets[$k] = ['key' => $k, 'start' => $d, 'end' => $d, 'in_real' => 0.0, 'out_real' => 0.0, 'in_proj' => 0.0, 'out_proj' => 0.0];
        $buckets[$k]['end'] = $d;
    }
    $acc = $accountId ? ' AND account_id = ' . (int)$accountId : '';
    foreach (db_all("SELECT paid_at AS d, kind, SUM(paid_amount) AS s FROM fh_fin_entries WHERE customer_id = ? AND status = 'paid' AND origin != 'transfer' AND paid_at >= ? AND paid_at <= ?$acc GROUP BY paid_at, kind", [$cid, $from, $to]) as $r) {
        $k = $key($r['d']);
        if (isset($buckets[$k])) $buckets[$k][$r['kind'] === 'receivable' ? 'in_real' : 'out_real'] += (float)$r['s'];
    }
    // transfers only matter when a single account is shown
    if ($accountId) {
        foreach (db_all("SELECT paid_at AS d, kind, SUM(paid_amount) AS s FROM fh_fin_entries WHERE customer_id = ? AND status = 'paid' AND origin = 'transfer' AND paid_at >= ? AND paid_at <= ?$acc GROUP BY paid_at, kind", [$cid, $from, $to]) as $r) {
            $k = $key($r['d']);
            if (isset($buckets[$k])) $buckets[$k][$r['kind'] === 'receivable' ? 'in_real' : 'out_real'] += (float)$r['s'];
        }
    }
    $today = today();
    $overdue = ['in' => 0.0, 'out' => 0.0];
    foreach (db_all("SELECT due_date AS d, kind, SUM(amount) AS s FROM fh_fin_entries WHERE customer_id = ? AND status = 'open' AND due_date <= ?" . ($accountId ? ' AND (account_id = ' . (int)$accountId . ' OR account_id IS NULL)' : '') . ' GROUP BY due_date, kind', [$cid, $to]) as $r) {
        $d = $r['d'] < $today ? $today : $r['d'];
        if ($r['d'] < $today) $overdue[$r['kind'] === 'receivable' ? 'in' : 'out'] += (float)$r['s'];
        if ($d < $from) continue;
        $k = $key($d);
        if (isset($buckets[$k])) $buckets[$k][$r['kind'] === 'receivable' ? 'in_proj' : 'out_proj'] += (float)$r['s'];
    }
    $running = $start;
    $rows = [];
    $tot = ['in_real' => 0.0, 'out_real' => 0.0, 'in_proj' => 0.0, 'out_proj' => 0.0];
    $lowest = null;
    foreach ($buckets as $b) {
        $running += $b['in_real'] - $b['out_real'] + $b['in_proj'] - $b['out_proj'];
        foreach ($tot as $k => $v) $tot[$k] += $b[$k];
        $row = array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $b) + ['balance' => round($running, 2), 'future' => $b['end'] >= $today];
        if ($row['future'] && ($lowest === null || $row['balance'] < $lowest['balance'])) $lowest = ['key' => $b['key'], 'start' => $b['start'], 'balance' => $row['balance']];
        $rows[] = $row;
    }
    return ['from' => $from, 'to' => $to, 'group' => $group, 'start_balance' => round($start, 2), 'end_balance' => round($running, 2), 'rows' => $rows,
        'totals' => array_map(fn($v) => round($v, 2), $tot), 'overdue' => array_map(fn($v) => round($v, 2), $overdue), 'lowest' => $lowest];
}

function fhf_column(string $sql, array $params = []): array
{
    return array_map(fn($r) => reset($r), db_all($sql, $params));
}

/**
 * DRE (income statement). basis=cash: paid entries by payment date (paid amount);
 * basis=competence: non-canceled entries by competence (or due) date.
 */
function fhf_dre(int $cid, string $from, string $to, string $basis = 'cash'): array
{
    $cash = $basis !== 'competence';
    $sql = $cash
        ? "SELECT e.kind, e.category_id, c.name, c.dre_group, SUM(e.paid_amount) AS total FROM fh_fin_entries e LEFT JOIN fh_fin_categories c ON c.id = e.category_id WHERE e.customer_id = ? AND e.status = 'paid' AND e.origin != 'transfer' AND e.paid_at >= ? AND e.paid_at <= ? GROUP BY e.kind, e.category_id, c.name, c.dre_group"
        : "SELECT e.kind, e.category_id, c.name, c.dre_group, SUM(e.amount) AS total FROM fh_fin_entries e LEFT JOIN fh_fin_categories c ON c.id = e.category_id WHERE e.customer_id = ? AND e.status != 'canceled' AND e.origin != 'transfer' AND COALESCE(e.competence_date, e.due_date) >= ? AND COALESCE(e.competence_date, e.due_date) <= ? GROUP BY e.kind, e.category_id, c.name, c.dre_group";
    $groups = [];
    foreach (FHF_DRE as $g => $label) $groups[$g] = ['group' => $g, 'label' => $label, 'income' => 0.0, 'expense' => 0.0, 'items' => []];
    foreach (db_all($sql, [$cid, $from, $to]) as $r) {
        $g = $r['dre_group'] && isset($groups[$r['dre_group']]) ? $r['dre_group'] : ($r['kind'] === 'receivable' ? ($r['category_id'] ? 'other' : 'revenue') : 'other');
        $v = round((float)$r['total'], 2);
        $groups[$g][$r['kind'] === 'receivable' ? 'income' : 'expense'] += $v;
        $groups[$g]['items'][] = ['category_id' => $r['category_id'] ? (int)$r['category_id'] : null, 'name' => $r['name'] ?: 'Sem categoria', 'kind' => $r['kind'], 'total' => $v];
    }
    $net = fn($g) => $groups[$g]['income'] - $groups[$g]['expense'];
    $gross = $groups['revenue']['income'] - $groups['revenue']['expense'];
    $netRevenue = $gross - $groups['deduction']['expense'] + $groups['deduction']['income'];
    $grossProfit = $netRevenue + $net('cost');
    $operating = $grossProfit + $net('payroll') + $net('expense');
    $result = $operating + $net('financial') + $net('other');
    $cashAfter = $result + $net('investment') + $net('owner');
    foreach ($groups as &$g) { $g['income'] = round($g['income'], 2); $g['expense'] = round($g['expense'], 2); usort($g['items'], fn($a, $b) => $b['total'] <=> $a['total']); }
    return ['from' => $from, 'to' => $to, 'basis' => $cash ? 'cash' : 'competence', 'groups' => array_values($groups),
        'lines' => ['gross_revenue' => round($gross, 2), 'net_revenue' => round($netRevenue, 2), 'gross_profit' => round($grossProfit, 2), 'operating_result' => round($operating, 2), 'net_result' => round($result, 2), 'cash_result' => round($cashAfter, 2),
            'margin' => $gross > 0 ? round($result / $gross * 100, 1) : null]];
}

/* ====================================================== STATEMENTS */

/**
 * Parse an OFX file (1.x SGML or 2.x XML; bank or credit card statement).
 * @return array{bank_id:?string, account:?string, currency:?string, start:?string, end:?string, balance:?float, balance_date:?string, transactions:array}
 */
function fhf_ofx_parse(string $raw): array
{
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = str_replace("\r", '', $raw);
    if (stripos($raw, '<OFX') === false) throw new AppException('Arquivo OFX inválido: não encontrei o conteúdo do extrato. Baixe o extrato em formato OFX (Money/Quicken) no internet banking.');
    $leaf = function (string $block, string $tag): ?string {
        return preg_match('/<' . $tag . '>\s*([^<\n]*)/i', $block, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : null;
    };
    $date = function (?string $v): ?string {
        if (!$v || !preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    };
    $num = function (?string $v): float {
        $v = trim((string)$v);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $v) || preg_match('/^-?\d+,\d+$/', $v)) $v = str_replace(['.', ','], ['', '.'], $v);
        return round((float)str_replace([' ', '+'], '', $v), 2);
    };
    $out = ['bank_id' => $leaf($raw, 'BANKID'), 'account' => $leaf($raw, 'ACCTID'), 'currency' => $leaf($raw, 'CURDEF'), 'start' => $date($leaf($raw, 'DTSTART')), 'end' => $date($leaf($raw, 'DTEND')),
        'balance' => null, 'balance_date' => null, 'transactions' => []];
    if (preg_match('/<LEDGERBAL>(.*?)(<\/LEDGERBAL>|<AVAILBAL>|<\/STMTRS>|<\/CCSTMTRS>)/is', $raw, $m)) {
        $out['balance'] = $leaf($m[1], 'BALAMT') !== null ? $num($leaf($m[1], 'BALAMT')) : null;
        $out['balance_date'] = $date($leaf($m[1], 'DTASOF'));
    }
    preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $raw, $mm);
    $seen = [];
    foreach ($mm[1] as $block) {
        $d = $date($leaf($block, 'DTPOSTED')) ?: $date($leaf($block, 'DTUSER'));
        $amt = $leaf($block, 'TRNAMT');
        if (!$d || $amt === null || $amt === '') continue;
        $amount = $num($amt);
        $type = strtoupper((string)$leaf($block, 'TRNTYPE'));
        if ($amount > 0 && in_array($type, ['DEBIT', 'PAYMENT', 'FEE', 'SRVCHG', 'ATM', 'POS', 'CHECK', 'DIRECTDEBIT'], true) && preg_match('/^\s*-/', (string)$amt) === 0 && stripos($raw, '<CCSTMTRS>') === false) {
            // a few banks export debits as positive numbers: trust the transaction type
            $amount = -$amount;
        }
        $name = $leaf($block, 'NAME') ?? '';
        $memo = $leaf($block, 'MEMO') ?? '';
        $desc = trim($memo !== '' && stripos($memo, $name) === false ? trim($name . ' ' . $memo) : ($memo !== '' ? $memo : $name)) ?: ($amount >= 0 ? 'Crédito' : 'Débito');
        $fitid = $leaf($block, 'FITID');
        $base = $fitid !== null && $fitid !== '' ? 'f:' . $fitid : 'h:' . substr(sha1($d . '|' . $amount . '|' . mb_strtolower($desc)), 0, 24);
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $out['transactions'][] = ['external_id' => mb_substr($seen[$base] > 1 ? $base . '#' . $seen[$base] : $base, 0, 120), 'date' => $d, 'amount' => $amount,
            'description' => mb_substr(preg_replace('/\s+/', ' ', $desc), 0, 255), 'memo' => $memo !== '' && $memo !== $desc ? mb_substr($memo, 0, 255) : null, 'doc_number' => $leaf($block, 'CHECKNUM') ?: $leaf($block, 'REFNUM')];
    }
    if (!$out['transactions'] && !preg_match('/<BANKTRANLIST>/i', $raw)) throw new AppException('Não encontrei movimentações neste OFX.');
    return $out;
}

/**
 * Parse a CSV statement: date; description; amount (signed) — or date; description; credit; debit.
 * Separator ; or , or tab; dates dd/mm/yyyy or yyyy-mm-dd; decimal comma or dot; header optional.
 */
function fhf_csv_parse(string $raw): array
{
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r", '', $raw));
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (!$lines) throw new AppException('Planilha vazia.');
    $sep = substr_count($lines[0], ';') >= 2 ? ';' : (substr_count($lines[0], "\t") >= 2 ? "\t" : ',');
    $out = ['bank_id' => null, 'account' => null, 'currency' => 'BRL', 'start' => null, 'end' => null, 'balance' => null, 'balance_date' => null, 'transactions' => []];
    $seen = [];
    foreach ($lines as $i => $line) {
        $c = array_map('trim', str_getcsv($line, $sep));
        $d = fhf_date($c[0] ?? '');
        if (!$d) {
            if ($i === 0) continue; // header
            throw new AppException('Linha ' . ($i + 1) . ': data inválida (use dd/mm/aaaa).');
        }
        $desc = mb_substr($c[1] ?? '', 0, 255) ?: 'Movimentação';
        if (count($c) >= 4 && ($c[2] !== '' || $c[3] !== '')) $amount = fhf_money($c[2] ?: 0) - abs(fhf_money($c[3] ?: 0));
        else $amount = fhf_money($c[2] ?? 0);
        if (abs($amount) < 0.005) continue;
        $base = 'h:' . substr(sha1($d . '|' . $amount . '|' . mb_strtolower($desc)), 0, 24);
        $seen[$base] = ($seen[$base] ?? 0) + 1;
        $out['transactions'][] = ['external_id' => $seen[$base] > 1 ? $base . '#' . $seen[$base] : $base, 'date' => $d, 'amount' => $amount, 'description' => $desc, 'memo' => null, 'doc_number' => null];
    }
    if (!$out['transactions']) throw new AppException('Não encontrei movimentações na planilha. Use as colunas: data; descrição; valor.');
    return $out;
}

/** Insert statement lines (skipping duplicates) and record the import. */
function fhf_import_statement(int $cid, int $accountId, string $source, array $parsed, ?string $fileName = null): array
{
    $acc = fhf_account($cid, $accountId);
    $new = 0;
    $dup = 0;
    $dates = array_column($parsed['transactions'], 'date');
    $importId = db_insert('fh_fin_imports', ['customer_id' => $cid, 'account_id' => $acc['id'], 'source' => $source, 'file_name' => $fileName ? mb_substr($fileName, 0, 190) : null,
        'period_start' => $parsed['start'] ?: ($dates ? min($dates) : null), 'period_end' => $parsed['end'] ?: ($dates ? max($dates) : null), 'balance' => $parsed['balance'], 'balance_date' => $parsed['balance_date'], 'created_at' => now()]);
    db_transaction(function () use ($cid, $acc, $source, $parsed, $importId, &$new, &$dup) {
        foreach ($parsed['transactions'] as $t) {
            if (db_value('SELECT id FROM fh_fin_transactions WHERE account_id = ? AND external_id = ?', [$acc['id'], $t['external_id']])) { $dup++; continue; }
            db_insert('fh_fin_transactions', ['customer_id' => $cid, 'account_id' => $acc['id'], 'source' => $source, 'external_id' => $t['external_id'], 'import_id' => $importId, 'tx_date' => $t['date'],
                'amount' => $t['amount'], 'description' => $t['description'], 'memo' => $t['memo'] ?? null, 'doc_number' => $t['doc_number'] ? mb_substr((string)$t['doc_number'], 0, 60) : null,
                'balance' => $t['balance'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $new++;
        }
    });
    db_update('fh_fin_imports', $importId, ['count_new' => $new, 'count_dup' => $dup]);
    $upd = ['updated_at' => now()];
    if ($parsed['balance'] !== null && (!$acc['stmt_balance_date'] || ($parsed['balance_date'] ?: today()) >= $acc['stmt_balance_date'])) { $upd['stmt_balance'] = $parsed['balance']; $upd['stmt_balance_date'] = $parsed['balance_date'] ?: today(); }
    if (!$acc['bank_code'] && $parsed['bank_id']) $upd['bank_code'] = mb_substr((string)$parsed['bank_id'], 0, 10);
    if (!$acc['account_number'] && $parsed['account']) $upd['account_number'] = mb_substr((string)$parsed['account'], 0, 30);
    db_update('fh_fin_accounts', (int)$acc['id'], $upd);
    return ['import_id' => $importId, 'new' => $new, 'duplicates' => $dup, 'suggestions' => $new ? fhf_count_suggestions($cid, (int)$acc['id']) : 0];
}

/** Undo an import: removes its lines that were not reconciled yet. */
function fhf_import_undo(int $cid, int $importId): int
{
    $imp = db_one('SELECT * FROM fh_fin_imports WHERE id = ? AND customer_id = ?', [$importId, $cid]);
    if (!$imp) throw new AppException('Importação não encontrada.');
    $n = (int)db_value("SELECT COUNT(*) FROM fh_fin_transactions WHERE import_id = ? AND status != 'reconciled'", [$importId]);
    db_exec("DELETE FROM fh_fin_transactions WHERE import_id = ? AND status != 'reconciled'", [$importId]);
    if (!(int)db_value('SELECT COUNT(*) FROM fh_fin_transactions WHERE import_id = ?', [$importId])) db_exec('DELETE FROM fh_fin_imports WHERE id = ?', [$importId]);
    return $n;
}

function fhf_tx_public(array $t): array
{
    $t['amount'] = (float)$t['amount'];
    $t['balance'] = $t['balance'] === null ? null : (float)$t['balance'];
    return $t;
}

/** Statement lines with filters (account_id, from, to, status, q) + period totals. */
function fhf_transactions(int $cid, array $q): array
{
    $w = ['t.customer_id = ?'];
    $p = [$cid];
    if (!empty($q['account_id'])) { $w[] = 't.account_id = ?'; $p[] = (int)$q['account_id']; }
    if ($from = fhf_date($q['from'] ?? '')) { $w[] = 't.tx_date >= ?'; $p[] = $from; }
    if ($to = fhf_date($q['to'] ?? '')) { $w[] = 't.tx_date <= ?'; $p[] = $to; }
    if (in_array($q['status'] ?? '', ['pending', 'reconciled', 'ignored'], true)) { $w[] = 't.status = ?'; $p[] = $q['status']; }
    if (($q['direction'] ?? '') === 'in') $w[] = 't.amount > 0';
    if (($q['direction'] ?? '') === 'out') $w[] = 't.amount < 0';
    if (($s = trim((string)($q['q'] ?? ''))) !== '') { $w[] = '(LOWER(t.description) LIKE ? OR LOWER(t.memo) LIKE ?)'; $like = '%' . mb_strtolower($s) . '%'; array_push($p, $like, $like); }
    $where = implode(' AND ', $w);
    $per = max(1, min(500, (int)($q['per_page'] ?? 100)));
    $page = max(1, (int)($q['page'] ?? 1));
    $rows = db_all("SELECT t.*, a.name AS account_name, c.name AS category_name FROM fh_fin_transactions t JOIN fh_fin_accounts a ON a.id = t.account_id LEFT JOIN fh_fin_categories c ON c.id = t.category_id WHERE $where ORDER BY t.tx_date DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    $sum = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS inc, COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END), 0) AS outg,
        SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN t.status = 'reconciled' THEN 1 ELSE 0 END) AS reconciled FROM fh_fin_transactions t WHERE $where", $p);
    $rules = db_all('SELECT r.*, c.name AS category_name FROM fh_fin_rules r LEFT JOIN fh_fin_categories c ON c.id = r.category_id WHERE r.customer_id = ?', [$cid]);
    $out = [];
    foreach ($rows as $t) {
        $t = fhf_tx_public($t);
        if ($t['status'] === 'pending') {
            $cands = fhf_candidates($cid, $t, '', 3);
            $t['suggestion'] = $cands && $cands[0]['score'] >= 60 ? $cands[0] : null;
            $t['rule'] = fhf_rule_for($rules, $t);
        } elseif ($t['status'] === 'reconciled') {
            $t['entries'] = db_all('SELECT id, kind, description, amount, paid_amount, due_date, party_name FROM fh_fin_entries WHERE transaction_id = ?', [$t['id']]);
        }
        $out[] = $t;
    }
    return ['data' => $out, 'total' => (int)$sum['n'], 'sum' => ['in' => round((float)$sum['inc'], 2), 'out' => round((float)$sum['outg'], 2), 'pending' => (int)$sum['pending'], 'reconciled' => (int)$sum['reconciled']]];
}

function fhf_words(string $s): array
{
    $s = mb_strtolower(strip_accents($s));
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $stop = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'em', 'para', 'pix', 'ted', 'doc', 'transf', 'transferencia', 'pagamento', 'pagto', 'pgto', 'recebido', 'recebimento', 'enviado', 'boleto', 'ltda', 'me', 'eireli', 'sa', 'compra', 'cartao', 'debito', 'credito'];
    return array_values(array_unique(array_filter(explode(' ', $s), fn($w) => strlen($w) >= 3 && !ctype_digit($w) && !in_array($w, $stop, true))));
}

/**
 * Open entries that may correspond to a statement line, best first. Score: exact amount 50 (±2% 20),
 * date proximity up to 30, party/description words up to 20. With $q, text search ignores amount.
 */
function fhf_candidates(int $cid, array $tx, string $q = '', int $limit = 8): array
{
    $kind = (float)$tx['amount'] > 0 ? 'receivable' : 'payable';
    $abs = abs((float)$tx['amount']);
    $p = [$cid, $kind];
    $w = "e.customer_id = ? AND e.kind = ? AND e.status = 'open'";
    if ($q !== '') {
        $w .= ' AND (LOWER(e.description) LIKE ? OR LOWER(e.party_name) LIKE ? OR LOWER(e.document_number) LIKE ?)';
        $like = '%' . mb_strtolower($q) . '%';
        array_push($p, $like, $like, $like);
    } else {
        $w .= ' AND e.amount >= ? AND e.amount <= ? AND e.due_date >= ? AND e.due_date <= ?';
        array_push($p, round($abs * 0.98, 2), round($abs * 1.02 + 0.01, 2), date('Y-m-d', strtotime($tx['tx_date'] . ' -45 days')), date('Y-m-d', strtotime($tx['tx_date'] . ' +45 days')));
    }
    $words = fhf_words($tx['description'] . ' ' . ($tx['memo'] ?? ''));
    $out = [];
    foreach (db_all("SELECT e.*, c.name AS category_name FROM fh_fin_entries e LEFT JOIN fh_fin_categories c ON c.id = e.category_id WHERE $w ORDER BY e.due_date LIMIT 200", $p) as $e) {
        $score = abs((float)$e['amount'] - $abs) < 0.005 ? 50 : (abs((float)$e['amount'] - $abs) <= $abs * 0.02 ? 20 : 0);
        $days = abs((strtotime($e['due_date']) - strtotime($tx['tx_date'])) / 86400);
        $score += $days < 1 ? 30 : ($days <= 3 ? 25 : ($days <= 7 ? 18 : ($days <= 15 ? 10 : ($days <= 30 ? 4 : 0))));
        $ew = fhf_words(($e['party_name'] ?? '') . ' ' . $e['description']);
        $common = $words && $ew ? count(array_intersect($words, $ew)) : 0;
        $score += min(20, $common * 10);
        if (($d = only_digits((string)$e['party_document'])) && strlen($d) >= 11 && str_contains(only_digits($tx['description'] . ' ' . ($tx['memo'] ?? '')), $d)) $score += 20;
        $e = fhf_entry_public($e);
        $e['score'] = min(100, $score);
        $out[] = $e;
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['due_date'], $b['due_date']));
    return array_slice($out, 0, $limit);
}

function fhf_count_suggestions(int $cid, ?int $accountId = null): int
{
    $n = 0;
    foreach (db_all("SELECT * FROM fh_fin_transactions WHERE customer_id = ? AND status = 'pending'" . ($accountId ? ' AND account_id = ' . (int)$accountId : '') . ' LIMIT 500', [$cid]) as $t) {
        $c = fhf_candidates($cid, $t, '', 1);
        if ($c && $c[0]['score'] >= 60) $n++;
    }
    return $n;
}

/**
 * Link a statement line to one or more open entries (all marked paid on the line date/account).
 * One entry with a different amount: the difference becomes interest (more) or discount (less).
 * Several entries: their amounts must add up to the line.
 */
function fhf_reconcile(int $cid, int $txId, array $entryIds): array
{
    $tx = fhf_tx($cid, $txId);
    if ($tx['status'] === 'reconciled') throw new AppException('Esta movimentação já está conciliada.');
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) throw new AppException('Escolha o(s) lançamento(s) correspondente(s).');
    $kind = (float)$tx['amount'] > 0 ? 'receivable' : 'payable';
    $abs = round(abs((float)$tx['amount']), 2);
    $entries = [];
    foreach ($entryIds as $id) {
        $e = fhf_entry($cid, $id);
        if ($e['kind'] !== $kind) throw new AppException($kind === 'receivable' ? 'Uma entrada no extrato só pode baixar contas a receber.' : 'Uma saída no extrato só pode baixar contas a pagar.');
        if ($e['status'] !== 'open') throw new AppException('O lançamento "' . $e['description'] . '" não está em aberto.');
        $entries[] = $e;
    }
    $sum = round(array_sum(array_map(fn($e) => (float)$e['amount'], $entries)), 2);
    if (count($entries) > 1 && abs($sum - $abs) > 0.01) throw new AppException('A soma dos lançamentos (' . number_format($sum, 2, ',', '.') . ') precisa ser igual ao valor do extrato (' . number_format($abs, 2, ',', '.') . ').');
    db_transaction(function () use ($tx, $entries, $abs) {
        foreach ($entries as $e) {
            $upd = ['status' => 'paid', 'paid_at' => $tx['tx_date'], 'account_id' => $tx['account_id'], 'transaction_id' => $tx['id'], 'updated_at' => now(), 'paid_amount' => (float)$e['amount'], 'interest' => 0, 'discount' => 0];
            if (count($entries) === 1) {
                $diff = round($abs - (float)$e['amount'], 2);
                $upd['paid_amount'] = $abs;
                if ($diff > 0) $upd['interest'] = $diff; elseif ($diff < 0) $upd['discount'] = -$diff;
            }
            db_update('fh_fin_entries', (int)$e['id'], $upd);
        }
        db_update('fh_fin_transactions', (int)$tx['id'], ['status' => 'reconciled', 'entry_id' => $entries[0]['id'], 'updated_at' => now()]);
    });
    return fhf_tx_public(fhf_tx($cid, $txId));
}

/** Book the statement line as a new paid entry (bank fee, sale not registered...). Optionally learn a rule. */
function fhf_tx_create_entry(int $cid, int $txId, array $in): array
{
    $tx = fhf_tx($cid, $txId);
    if ($tx['status'] === 'reconciled') throw new AppException('Esta movimentação já está conciliada.');
    $kind = (float)$tx['amount'] > 0 ? 'receivable' : 'payable';
    $d = fhf_entry_fields($cid, ['kind' => $kind, 'description' => trim((string)($in['description'] ?? '')) ?: $tx['description'], 'amount' => abs((float)$tx['amount']), 'due_date' => $tx['tx_date']]
        + array_intersect_key($in, array_flip(['category_id', 'party_name', 'party_document', 'cost_center', 'notes', 'emitter_id', 'taker_id', 'competence_date'])), null);
    $id = 0;
    db_transaction(function () use ($cid, $tx, $d, &$id) {
        $id = db_insert('fh_fin_entries', $d + ['customer_id' => $cid, 'status' => 'paid', 'paid_at' => $tx['tx_date'], 'paid_amount' => $d['amount'], 'account_id' => $tx['account_id'], 'origin' => 'statement',
            'transaction_id' => $tx['id'], 'created_at' => now(), 'updated_at' => now()]);
        db_update('fh_fin_transactions', (int)$tx['id'], ['status' => 'reconciled', 'entry_id' => $id, 'category_id' => $d['category_id'] ?? null, 'updated_at' => now()]);
    });
    if (!empty($in['remember']) && (!empty($d['category_id']) || !empty($d['party_name']))) fhf_rule_learn($cid, $tx, $d['category_id'] ?? null, $d['party_name'] ?? null, (string)($in['rule_text'] ?? ''));
    return fhf_tx_public(fhf_tx($cid, $txId));
}

/** Undo the reconciliation: entries created from the line are deleted, the others reopened. */
function fhf_tx_unlink(int $cid, int $txId): array
{
    $tx = fhf_tx($cid, $txId);
    db_transaction(function () use ($tx) {
        foreach (db_all('SELECT * FROM fh_fin_entries WHERE transaction_id = ?', [$tx['id']]) as $e) {
            if ($e['origin'] === 'statement') db_exec('DELETE FROM fh_fin_entries WHERE id = ?', [$e['id']]);
            else db_update('fh_fin_entries', (int)$e['id'], ['status' => 'open', 'paid_at' => null, 'paid_amount' => null, 'interest' => 0, 'discount' => 0, 'transaction_id' => null, 'updated_at' => now()]);
        }
        db_update('fh_fin_transactions', (int)$tx['id'], ['status' => 'pending', 'entry_id' => null, 'updated_at' => now()]);
    });
    return fhf_tx_public(fhf_tx($cid, $txId));
}

/** An entry left the reconciliation (reopened/deleted): the line goes back to pending when nothing else is linked. */
function fhf_tx_release(int $txId, int $entryId): void
{
    db_exec('UPDATE fh_fin_entries SET transaction_id = NULL WHERE id = ?', [$entryId]);
    $other = db_value('SELECT id FROM fh_fin_entries WHERE transaction_id = ? AND id != ?', [$txId, $entryId]);
    if ($other) db_exec('UPDATE fh_fin_transactions SET entry_id = ?, updated_at = ? WHERE id = ?', [$other, now(), $txId]);
    else db_exec("UPDATE fh_fin_transactions SET status = 'pending', entry_id = NULL, updated_at = ? WHERE id = ?", [now(), $txId]);
}

function fhf_tx_ignore(int $cid, int $txId, bool $ignore): array
{
    $tx = fhf_tx($cid, $txId);
    if ($tx['status'] === 'reconciled') throw new AppException('Desfaça a conciliação antes.');
    db_update('fh_fin_transactions', (int)$tx['id'], ['status' => $ignore ? 'ignored' : 'pending', 'updated_at' => now()]);
    return fhf_tx_public(fhf_tx($cid, $txId));
}

/** Reconcile every pending line whose best candidate is unambiguous (exact amount, close date). */
function fhf_auto_reconcile(int $cid, ?int $accountId = null): array
{
    $done = 0;
    $used = [];
    foreach (db_all("SELECT * FROM fh_fin_transactions WHERE customer_id = ? AND status = 'pending'" . ($accountId ? ' AND account_id = ' . (int)$accountId : '') . ' ORDER BY tx_date LIMIT 1000', [$cid]) as $tx) {
        $c = array_values(array_filter(fhf_candidates($cid, $tx, '', 5), fn($e) => !isset($used[$e['id']])));
        if (!$c || $c[0]['score'] < 70 || abs($c[0]['amount'] - abs((float)$tx['amount'])) > 0.005) continue;
        if (isset($c[1]) && $c[1]['score'] >= $c[0]['score'] - 5) continue; // ambiguous: let the user choose
        fhf_reconcile($cid, (int)$tx['id'], [$c[0]['id']]);
        $used[$c[0]['id']] = true;
        $done++;
    }
    return ['reconciled' => $done, 'pending' => (int)db_value("SELECT COUNT(*) FROM fh_fin_transactions WHERE customer_id = ? AND status = 'pending'" . ($accountId ? ' AND account_id = ' . (int)$accountId : ''), [$cid])];
}

/* ------------------------------------------------------------- rules */

function fhf_rule_key(string $desc): string
{
    return implode(' ', array_slice(fhf_words($desc), 0, 3));
}

/** Remember "lines containing these words → category/party". $text: the words chosen by the user (default: the first three of the line). */
function fhf_rule_learn(int $cid, array $tx, ?int $categoryId, ?string $party, string $text = ''): void
{
    $key = $text !== '' ? implode(' ', array_slice(fhf_words($text), 0, 5)) : fhf_rule_key($tx['description']);
    if ($key === '') return;
    $dir = (float)$tx['amount'] > 0 ? 'in' : 'out';
    $r = db_one('SELECT id FROM fh_fin_rules WHERE customer_id = ? AND match_text = ? AND direction = ?', [$cid, $key, $dir]);
    if ($r) db_exec('UPDATE fh_fin_rules SET category_id = ?, party_name = ?, hits = hits + 1 WHERE id = ?', [$categoryId, $party, $r['id']]);
    else db_insert('fh_fin_rules', ['customer_id' => $cid, 'match_text' => $key, 'direction' => $dir, 'category_id' => $categoryId, 'party_name' => $party ? mb_substr($party, 0, 200) : null, 'hits' => 1, 'created_at' => now()]);
}

function fhf_rule_for(array $rules, array $tx): ?array
{
    $dir = (float)$tx['amount'] > 0 ? 'in' : 'out';
    $words = fhf_words($tx['description']);
    foreach ($rules as $r) {
        if ($r['direction'] && $r['direction'] !== $dir) continue;
        $need = explode(' ', $r['match_text']);
        if ($need && !array_diff($need, $words)) return ['category_id' => $r['category_id'] ? (int)$r['category_id'] : null, 'category_name' => $r['category_name'], 'party_name' => $r['party_name']];
    }
    return null;
}

/* ======================================================== CSV EXPORT */

function fhf_entries_csv(array $rows): string
{
    $f = fopen('php://temp', 'r+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Tipo', 'Descrição', 'Cliente/Fornecedor', 'CPF/CNPJ', 'Categoria', 'Conta', 'Vencimento', 'Competência', 'Valor', 'Situação', 'Pago em', 'Valor pago', 'Juros', 'Desconto', 'Forma', 'Documento', 'Centro de custo'], ';', '"', '\\');
    $st = ['open' => 'Em aberto', 'overdue' => 'Vencido', 'paid' => 'Pago', 'canceled' => 'Cancelado'];
    $br = fn($v) => $v === null ? '' : number_format((float)$v, 2, ',', '');
    $d = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
    foreach ($rows as $r) {
        fputcsv($f, [$r['kind'] === 'receivable' ? 'A receber' : 'A pagar', $r['description'], $r['party_name'] ?? '', $r['party_document'] ?? '', $r['category_name'] ?? '', $r['account_name'] ?? '', $d($r['due_date']), $d($r['competence_date']),
            $br($r['amount']), $st[$r['display_status']] ?? $r['status'], $d($r['paid_at']), $br($r['paid_amount']), $br($r['interest']), $br($r['discount']), FHF_METHODS[$r['payment_method'] ?? ''] ?? '', $r['document_number'] ?? '', $r['cost_center'] ?? ''], ';', '"', '\\');
    }
    rewind($f);
    return stream_get_contents($f);
}

/* ============================================= BANK CONNECTIONS (automatic statements) */

/**
 * Automatic statement sources, one row per connection in fh_fin_connections:
 *  - pluggy     Open Finance through the Integra Code aggregator account (paid by Integra Code; plan flag "open_finance")
 *  - meupluggy  Open Finance for free: the customer's own Meu Pluggy + Pluggy developer app (their client id/secret)
 *  - inter      Banco Inter PJ official API (free for Inter business accounts): OAuth + mTLS certificate from the Inter app
 *  - asaas      the customer's own Asaas account (API key), free
 * Bank passwords never pass through this system. Credentials are stored encrypted (AES-256-GCM).
 */
const FHF_PROVIDERS = [
    'meupluggy' => ['name' => 'Open Finance (Meu Pluggy)', 'free' => true],
    'inter' => ['name' => 'Banco Inter PJ (API oficial)', 'free' => true],
    'asaas' => ['name' => 'Asaas (sua conta)', 'free' => true],
    'pluggy' => ['name' => 'Open Finance Integra', 'free' => false],
];

function fhf_conn(int $cid, int $id): array
{
    $c = db_one('SELECT * FROM fh_fin_connections WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$c) throw new AppException('Conexão bancária não encontrada.');
    return $c;
}

function fhf_conn_public(array $c): array
{
    unset($c['credentials']);
    $c['provider_name'] = FHF_PROVIDERS[$c['provider']]['name'] ?? $c['provider'];
    $c['accounts'] = db_all('SELECT id, name, kind, stmt_balance, last_sync_at FROM fh_fin_accounts WHERE connection_id = ? ORDER BY id', [$c['id']]);
    return $c;
}

function fhf_creds(array $conn): array
{
    return json_decode(decrypt_secret($conn['credentials'] ?? ''), true) ?: [];
}

/** Small HTTP client for the bank APIs (JSON or form body, optional mTLS certificate files). */
function fhf_http(string $method, string $url, array $headers = [], $body = null, array $tls = []): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: IntegraFiscalHub/1.0'], $headers)];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
    if ($tls) { $opts[CURLOPT_SSLCERT] = $tls['cert']; $opts[CURLOPT_SSLKEY] = $tls['key']; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new AppException('Não foi possível falar com o banco agora (' . $err . '). Tente de novo em instantes.');
    return ['status' => $status, 'json' => json_decode((string)$raw, true) ?? [], 'body' => (string)$raw];
}

/* ------------------------------------------------------------------ Pluggy */

function fhf_of_enabled(): bool
{
    return setting('fh_openfinance_enabled', '0') === '1' && setting('fh_pluggy_client_id') && setting('fh_pluggy_client_secret');
}

/** $creds null = Integra Code aggregator account; otherwise the customer's own Pluggy app (Meu Pluggy). */
function fhf_pluggy(string $method, string $path, ?array $body = null, ?array $creds = null, bool $retry = true): array
{
    $base = rtrim((string)config('pluggy_api_url', 'https://api.pluggy.ai'), '/');
    $res = fhf_http($method, $base . $path, ['Content-Type: application/json', 'X-API-KEY: ' . fhf_pluggy_key($creds)], $body);
    if ($res['status'] === 401 && $retry) { fhf_pluggy_key($creds, true); return fhf_pluggy($method, $path, $body, $creds, false); }
    if ($res['status'] >= 400) {
        log_line('fiscalhub', 'pluggy error', ['path' => $path, 'status' => $res['status'], 'body' => mb_substr($res['body'], 0, 400)]);
        throw new AppException('Open Finance: ' . ($res['json']['message'] ?? ('erro HTTP ' . $res['status'])) . '.');
    }
    return $res['json'];
}

/** API key (valid 2 h), cached encrypted in settings for 100 minutes per credential. */
function fhf_pluggy_key(?array $creds = null, bool $renew = false): string
{
    $id = $creds ? (string)($creds['client_id'] ?? '') : (string)setting('fh_pluggy_client_id', '');
    $secret = $creds ? (string)($creds['client_secret'] ?? '') : (string)setting('fh_pluggy_client_secret', '');
    if ($id === '' || $secret === '') throw new AppException($creds ? 'Informe o Client ID e o Client Secret da sua aplicação Pluggy.' : 'Open Finance não configurado. Fale com a Integra Code.');
    $cacheKey = $creds ? 'fhf_pk_' . substr(hash('sha256', $id), 0, 20) : 'fh_pluggy_api_key';
    if (!$renew) {
        $cached = json_decode($creds ? decrypt_secret((string)setting($cacheKey, '')) : (string)setting($cacheKey, ''), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() && !empty($cached['key'])) return $cached['key'];
    }
    $res = fhf_http('POST', rtrim((string)config('pluggy_api_url', 'https://api.pluggy.ai'), '/') . '/auth', ['Content-Type: application/json'], ['clientId' => $id, 'clientSecret' => $secret]);
    if ($res['status'] >= 400 || empty($res['json']['apiKey'])) throw new AppException('Open Finance: Client ID ou Client Secret recusados pela Pluggy (HTTP ' . $res['status'] . '). Confira as credenciais da aplicação.');
    $val = json_encode(['key' => $res['json']['apiKey'], 'exp' => time() + 6000]);
    set_setting($cacheKey, $creds ? encrypt_secret($val) : $val);
    return $res['json']['apiKey'];
}

function fhf_of_webhook_url(): string
{
    if (!setting('fh_pluggy_webhook_token')) set_setting('fh_pluggy_webhook_token', bin2hex(random_bytes(16)));
    return rtrim((string)config('app_url'), '/') . '/api/fh/openfinance/webhook?token=' . setting('fh_pluggy_webhook_token');
}

/** Customer's own Pluggy app credentials (Meu Pluggy, free), stored encrypted per customer. */
function fhf_mp_creds(int $cid): ?array
{
    $c = json_decode(decrypt_secret((string)setting('fhf_mp_' . $cid, '')), true);
    return is_array($c) && !empty($c['client_id']) ? $c : null;
}

function fhf_mp_save(int $cid, string $clientId, string $clientSecret): array
{
    $clientId = trim($clientId);
    $clientSecret = trim($clientSecret);
    if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $clientId) || strlen($clientSecret) < 8) throw new AppException('Cole o Client ID e o Client Secret da aplicação criada no dashboard.pluggy.ai.');
    $creds = ['client_id' => $clientId, 'client_secret' => $clientSecret];
    fhf_pluggy_key($creds, true); // validates
    set_setting('fhf_mp_' . $cid, encrypt_secret(json_encode($creds)));
    return ['ok' => true, 'client_id' => substr($clientId, 0, 8) . '…'];
}

/** Connect token for the Pluggy widget: Integra account (pluggy) or the customer's app (meupluggy). */
function fhf_of_connect_token(int $cid, string $provider, ?array $conn = null): array
{
    $creds = $provider === 'meupluggy' ? fhf_mp_creds($cid) : null;
    if ($provider === 'meupluggy' && !$creds) throw new AppException('Cadastre primeiro o Client ID e o Client Secret da sua aplicação Pluggy.');
    $body = ['clientUserId' => 'fh-customer-' . $cid];
    if ($provider === 'pluggy' && str_starts_with((string)config('app_url'), 'https://')) $body['webhookUrl'] = fhf_of_webhook_url();
    if ($conn) $body['itemId'] = $conn['item_id'];
    $j = fhf_pluggy('POST', '/connect_token', $body, $creds);
    if (empty($j['accessToken'])) throw new AppException('Open Finance: não foi possível iniciar a conexão.');
    $connectors = [];
    if ($provider === 'meupluggy') {
        // show only the free MeuPluggy connector when the app lists it
        try {
            foreach ((fhf_pluggy('GET', '/connectors?name=' . rawurlencode('MeuPluggy'), null, $creds)['results'] ?? []) as $c) {
                if (stripos(str_replace(' ', '', (string)($c['name'] ?? '')), 'meupluggy') !== false) $connectors[] = (int)$c['id'];
            }
        } catch (Throwable $e) { /* optional filter */ }
    }
    return ['access_token' => (string)$j['accessToken'], 'connector_ids' => $connectors];
}

/** Register the item created by the widget and pull accounts + the last 90 days. */
function fhf_of_register_item(int $cid, string $itemId, string $provider = 'pluggy'): array
{
    if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $itemId)) throw new AppException('Identificador da conexão inválido.');
    $provider = $provider === 'meupluggy' ? 'meupluggy' : 'pluggy';
    $creds = $provider === 'meupluggy' ? fhf_mp_creds($cid) : null;
    if ($provider === 'meupluggy' && !$creds) throw new AppException('Cadastre primeiro as credenciais da sua aplicação Pluggy.');
    $item = fhf_pluggy('GET', '/items/' . rawurlencode($itemId), null, $creds);
    $owner = (string)($item['clientUserId'] ?? '');
    if ($provider === 'pluggy' && $owner !== '' && $owner !== 'fh-customer-' . $cid) throw new AppException('Esta conexão bancária pertence a outro usuário.');
    $existing = db_one('SELECT * FROM fh_fin_connections WHERE item_id = ? AND provider = ?', [$itemId, $provider]);
    if ($existing && (int)$existing['customer_id'] !== $cid) throw new AppException('Esta conexão bancária pertence a outro usuário.');
    $d = ['label' => mb_substr((string)($item['connector']['name'] ?? 'Banco'), 0, 120), 'connector_id' => (string)($item['connector']['id'] ?? ''), 'connector_name' => mb_substr((string)($item['connector']['name'] ?? 'Banco'), 0, 120),
        'status' => mb_substr((string)($item['status'] ?? ''), 0, 30), 'consent_expires_at' => !empty($item['consentExpiresAt']) ? date('Y-m-d H:i:s', strtotime((string)$item['consentExpiresAt'])) : null, 'error_message' => null, 'updated_at' => now()];
    if ($existing) { db_update('fh_fin_connections', (int)$existing['id'], $d); $connId = (int)$existing['id']; }
    else $connId = db_insert('fh_fin_connections', $d + ['customer_id' => $cid, 'provider' => $provider, 'item_id' => $itemId, 'created_at' => now()]);
    return fhf_conn_sync(db_find('fh_fin_connections', $connId));
}

function fhf_sync_pluggy(array $conn, string $from): array
{
    $cid = (int)$conn['customer_id'];
    $creds = $conn['provider'] === 'meupluggy' ? fhf_mp_creds($cid) : null;
    if ($conn['provider'] === 'meupluggy' && !$creds) throw new AppException('As credenciais do Meu Pluggy foram removidas. Cadastre-as de novo.');
    $item = fhf_pluggy('GET', '/items/' . rawurlencode($conn['item_id']), null, $creds);
    $status = (string)($item['status'] ?? '');
    $out = ['accounts' => 0, 'new' => 0, 'duplicates' => 0];
    foreach (fhf_pluggy('GET', '/accounts?itemId=' . rawurlencode($conn['item_id']), null, $creds)['results'] ?? [] as $a) {
        $isCard = ($a['type'] ?? '') === 'CREDIT';
        $local = fhf_conn_account($conn, (string)$a['id'], trim(($conn['connector_name'] ?: 'Banco') . ' · ' . ($a['name'] ?? ($isCard ? 'Cartão' : 'Conta'))),
            $isCard ? 'credit_card' : (($a['subtype'] ?? '') === 'SAVINGS_ACCOUNT' ? 'savings' : 'checking'), (string)($a['number'] ?? ''), $conn['connector_name']);
        $rows = [];
        for ($page = 1; $page <= 20; $page++) {
            $res = fhf_pluggy('GET', '/transactions?accountId=' . rawurlencode((string)$a['id']) . '&from=' . $from . '&to=' . today() . '&pageSize=500&page=' . $page, null, $creds);
            foreach ($res['results'] ?? [] as $t) {
                if (($t['status'] ?? 'POSTED') === 'PENDING') continue;
                $amt = round(abs((float)($t['amount'] ?? 0)), 2);
                if ($amt < 0.005) continue;
                $debit = $isCard ? ($t['type'] ?? '') !== 'CREDIT' : (($t['type'] ?? '') === 'DEBIT' || (!isset($t['type']) && (float)$t['amount'] < 0));
                $rows[] = ['external_id' => 'p:' . $t['id'], 'date' => substr((string)$t['date'], 0, 10), 'amount' => $debit ? -$amt : $amt, 'description' => mb_substr(trim((string)($t['description'] ?? 'Movimentação')), 0, 255),
                    'memo' => !empty($t['descriptionRaw']) && $t['descriptionRaw'] !== ($t['description'] ?? '') ? mb_substr((string)$t['descriptionRaw'], 0, 255) : null, 'doc_number' => null, 'balance' => isset($t['balance']) ? round((float)$t['balance'], 2) : null];
            }
            if ($page >= (int)($res['totalPages'] ?? 1)) break;
        }
        $r = fhf_import_statement($cid, (int)$local['id'], $conn['provider'] === 'meupluggy' ? 'meupluggy' : 'openfinance', ['bank_id' => null, 'account' => null, 'currency' => 'BRL', 'start' => $from, 'end' => today(),
            'balance' => isset($a['balance']) ? round((float)$a['balance'] * ($isCard ? -1 : 1), 2) : null, 'balance_date' => today(), 'transactions' => $rows], FHF_PROVIDERS[$conn['provider']]['name']);
        $out['accounts']++;
        $out['new'] += $r['new'];
        $out['duplicates'] += $r['duplicates'];
    }
    db_update('fh_fin_connections', (int)$conn['id'], ['status' => mb_substr($status, 0, 30), 'error_message' => in_array($status, ['LOGIN_ERROR', 'OUTDATED'], true) ? 'O banco pediu uma nova autorização. Clique em Reconectar.' : null,
        'consent_expires_at' => !empty($item['consentExpiresAt']) ? date('Y-m-d H:i:s', strtotime((string)$item['consentExpiresAt'])) : $conn['consent_expires_at']]);
    return $out;
}

/** Local account for a remote account of a connection (created on the first sync). */
function fhf_conn_account(array $conn, string $externalId, string $name, string $kind, string $number = '', ?string $bank = null, ?string $bankCode = null): array
{
    $cid = (int)$conn['customer_id'];
    $local = db_one('SELECT * FROM fh_fin_accounts WHERE customer_id = ? AND connection_id = ? AND external_id = ?', [$cid, $conn['id'], $externalId])
        ?: db_one('SELECT * FROM fh_fin_accounts WHERE customer_id = ? AND connection_id IS NULL AND external_id = ?', [$cid, $externalId]);
    if ($local) {
        if (!$local['connection_id']) db_update('fh_fin_accounts', (int)$local['id'], ['connection_id' => $conn['id'], 'active' => 1, 'updated_at' => now()]);
        return $local;
    }
    return db_find('fh_fin_accounts', db_insert('fh_fin_accounts', ['customer_id' => $cid, 'name' => mb_substr($name, 0, 120), 'kind' => $kind, 'bank_code' => $bankCode, 'bank_name' => $bank ? mb_substr($bank, 0, 120) : null,
        'account_number' => $number !== '' ? mb_substr($number, 0, 30) : null, 'opening_balance' => 0, 'connection_id' => $conn['id'], 'external_id' => $externalId, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]));
}

/* --------------------------------------------------------------- Banco Inter */

function fhf_inter_base(): string
{
    return rtrim((string)config('inter_api_url', 'https://cdpj.partners.bancointer.com.br'), '/');
}

/** Run $fn with the Inter certificate/key written to private temp files. */
function fhf_with_tls(array $creds, callable $fn)
{
    $dir = STORAGE_PATH . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $cert = tempnam($dir, 'ic');
    $key = tempnam($dir, 'ik');
    file_put_contents($cert, (string)$creds['cert']);
    file_put_contents($key, (string)$creds['key']);
    @chmod($cert, 0600);
    @chmod($key, 0600);
    try {
        return $fn(['cert' => $cert, 'key' => $key]);
    } finally {
        @unlink($cert);
        @unlink($key);
    }
}

function fhf_inter_token(array $creds, array $tls): string
{
    $res = fhf_http('POST', fhf_inter_base() . '/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $creds['client_id'], 'client_secret' => $creds['client_secret'], 'grant_type' => 'client_credentials', 'scope' => 'extrato.read']), $tls);
    if ($res['status'] >= 400 || empty($res['json']['access_token'])) {
        throw new AppException('Banco Inter recusou o acesso (HTTP ' . $res['status'] . '): ' . ($res['json']['title'] ?? $res['json']['error_description'] ?? $res['json']['message'] ?? 'confira Client ID, Client Secret, certificado e a permissão "Consulta de extrato e saldo".'));
    }
    return (string)$res['json']['access_token'];
}

function fhf_inter_get(string $path, array $creds, array $tls, string $token): array
{
    $h = ['Authorization: Bearer ' . $token];
    if (!empty($creds['account'])) $h[] = 'x-conta-corrente: ' . only_digits((string)$creds['account']);
    $res = fhf_http('GET', fhf_inter_base() . $path, $h, null, $tls);
    if ($res['status'] >= 400) throw new AppException('Banco Inter (HTTP ' . $res['status'] . '): ' . ($res['json']['title'] ?? $res['json']['detail'] ?? 'erro ao consultar o extrato') . '.');
    return $res['json'];
}

/** PEM text from an uploaded certificate/key (PEM or base64 DER). */
function fhf_pem(string $raw, string $kind): string
{
    $raw = trim(str_replace("\r", '', $raw));
    if (str_contains($raw, '-----BEGIN')) return $raw . "\n";
    $der = base64_decode($raw, true);
    if ($der === false || $der === '') throw new AppException($kind === 'cert' ? 'Certificado do Inter inválido: envie o arquivo .crt.' : 'Chave do Inter inválida: envie o arquivo .key.');
    $label = $kind === 'cert' ? 'CERTIFICATE' : 'PRIVATE KEY';
    return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
}

function fhf_inter_connect(int $cid, array $in, ?array $existing = null): array
{
    $old = $existing ? fhf_creds($existing) : [];
    $creds = ['client_id' => trim((string)($in['client_id'] ?? '')) ?: ($old['client_id'] ?? ''), 'client_secret' => trim((string)($in['client_secret'] ?? '')) ?: ($old['client_secret'] ?? ''),
        'cert' => !empty($in['cert']) ? fhf_pem((string)$in['cert'], 'cert') : ($old['cert'] ?? ''), 'key' => !empty($in['key']) ? fhf_pem((string)$in['key'], 'key') : ($old['key'] ?? ''),
        'account' => only_digits((string)($in['account'] ?? ($old['account'] ?? '')))];
    if (!$creds['client_id'] || !$creds['client_secret'] || !$creds['cert'] || !$creds['key']) throw new AppException('Informe Client ID, Client Secret, o certificado (.crt) e a chave (.key) gerados no Internet Banking do Inter.');
    $x509 = @openssl_x509_parse($creds['cert']);
    if (!$x509) throw new AppException('O arquivo .crt enviado não é um certificado válido.');
    if (!@openssl_x509_check_private_key($creds['cert'], $creds['key'])) throw new AppException('A chave (.key) não corresponde ao certificado (.crt). Envie os dois arquivos da mesma aplicação.');
    $validTo = isset($x509['validTo_time_t']) ? date('Y-m-d', $x509['validTo_time_t']) : null;
    $balance = fhf_with_tls($creds, function ($tls) use ($creds) {
        $token = fhf_inter_token($creds, $tls);
        return fhf_inter_get('/banking/v2/saldo', $creds, $tls, $token);
    });
    $d = ['label' => 'Banco Inter' . ($creds['account'] ? ' · ' . $creds['account'] : ''), 'connector_name' => 'Banco Inter', 'status' => 'UPDATED', 'error_message' => null,
        'consent_expires_at' => $validTo ? $validTo . ' 23:59:59' : null, 'credentials' => encrypt_secret(json_encode($creds)), 'updated_at' => now()];
    if ($existing) { db_update('fh_fin_connections', (int)$existing['id'], $d); $id = (int)$existing['id']; }
    else $id = db_insert('fh_fin_connections', $d + ['customer_id' => $cid, 'provider' => 'inter', 'created_at' => now()]);
    $conn = db_find('fh_fin_connections', $id);
    $acc = fhf_conn_account($conn, 'inter:' . ($creds['account'] ?: 'principal'), trim((string)($in['account_name'] ?? '')) ?: 'Banco Inter PJ', 'checking', $creds['account'], 'Banco Inter', '077');
    if (isset($balance['disponivel'])) db_update('fh_fin_accounts', (int)$acc['id'], ['stmt_balance' => round((float)$balance['disponivel'], 2), 'stmt_balance_date' => today()]);
    return fhf_conn_sync($conn, 90);
}

function fhf_sync_inter(array $conn, string $from): array
{
    $creds = fhf_creds($conn);
    $acc = fhf_conn_account($conn, 'inter:' . ($creds['account'] ?: 'principal'), 'Banco Inter PJ', 'checking', (string)($creds['account'] ?? ''), 'Banco Inter', '077');
    [$rows, $balance] = fhf_with_tls($creds, function ($tls) use ($creds, $from) {
        $token = fhf_inter_token($creds, $tls);
        $rows = [];
        // the statement endpoint answers at most ~90 days per call: walk in 30-day windows
        for ($start = $from; $start <= today(); $start = date('Y-m-d', strtotime($start . ' +30 days'))) {
            $end = min(today(), date('Y-m-d', strtotime($start . ' +29 days')));
            for ($page = 0; $page < 50; $page++) {
                $j = fhf_inter_get('/banking/v2/extrato/completo?' . http_build_query(['dataInicio' => $start, 'dataFim' => $end, 'pagina' => $page, 'tamanhoPagina' => 1000]), $creds, $tls, $token);
                foreach ($j['transacoes'] ?? [] as $t) {
                    $amt = round(abs((float)str_replace(',', '.', (string)($t['valor'] ?? 0))), 2);
                    if ($amt < 0.005) continue;
                    $date = substr((string)($t['dataTransacao'] ?? $t['dataInclusao'] ?? $t['dataEntrada'] ?? ''), 0, 10);
                    if (!fhf_date($date)) continue;
                    $desc = trim(preg_replace('/\s+/', ' ', ($t['titulo'] ?? '') . ' ' . (($t['descricao'] ?? '') !== ($t['titulo'] ?? '') ? ($t['descricao'] ?? '') : ''))) ?: (string)($t['tipoTransacao'] ?? 'Movimentação');
                    $id = (string)($t['idTransacao'] ?? '');
                    $rows[] = ['external_id' => $id !== '' ? 'i:' . $id : 'h:' . substr(sha1($date . '|' . $amt . '|' . $desc . '|' . count($rows)), 0, 24), 'date' => $date,
                        'amount' => strtoupper((string)($t['tipoOperacao'] ?? 'C')) === 'D' ? -$amt : $amt, 'description' => mb_substr($desc, 0, 255), 'memo' => !empty($t['tipoTransacao']) ? mb_substr((string)$t['tipoTransacao'], 0, 255) : null, 'doc_number' => null];
                }
                if (!empty($j['ultimaPagina']) || $page + 1 >= (int)($j['totalPaginas'] ?? 1)) break;
            }
        }
        return [$rows, fhf_inter_get('/banking/v2/saldo', $creds, $tls, $token)];
    });
    $r = fhf_import_statement((int)$conn['customer_id'], (int)$acc['id'], 'inter', ['bank_id' => '077', 'account' => null, 'currency' => 'BRL', 'start' => $from, 'end' => today(),
        'balance' => isset($balance['disponivel']) ? round((float)$balance['disponivel'], 2) : null, 'balance_date' => today(), 'transactions' => $rows], 'API Banco Inter');
    db_update('fh_fin_connections', (int)$conn['id'], ['status' => 'UPDATED']);
    return ['accounts' => 1, 'new' => $r['new'], 'duplicates' => $r['duplicates']];
}

/* -------------------------------------------------------------------- Asaas */

function fhf_asaas_base(string $env): string
{
    if ($u = config('asaas_fin_api_url')) return rtrim((string)$u, '/');
    return $env === 'sandbox' ? 'https://api-sandbox.asaas.com/v3' : 'https://api.asaas.com/v3';
}

function fhf_asaas_get(array $creds, string $path): array
{
    $res = fhf_http('GET', fhf_asaas_base((string)($creds['env'] ?? 'production')) . $path, ['access_token: ' . $creds['api_key']]);
    if ($res['status'] === 401) throw new AppException('O Asaas recusou a chave de API. Gere uma nova em Asaas → Integrações → Chave de API.');
    if ($res['status'] >= 400) throw new AppException('Asaas (HTTP ' . $res['status'] . '): ' . ($res['json']['errors'][0]['description'] ?? 'erro ao consultar o extrato') . '.');
    return $res['json'];
}

function fhf_asaas_connect(int $cid, array $in, ?array $existing = null): array
{
    $old = $existing ? fhf_creds($existing) : [];
    $key = trim((string)($in['api_key'] ?? ''));
    $creds = ['api_key' => $key !== '' && strpos($key, '•') === false ? $key : ($old['api_key'] ?? ''), 'env' => ($in['env'] ?? ($old['env'] ?? 'production')) === 'sandbox' ? 'sandbox' : 'production'];
    if (strlen($creds['api_key']) < 20) throw new AppException('Cole a chave de API da sua conta Asaas (Asaas → Integrações → Chave de API).');
    $balance = fhf_asaas_get($creds, '/finance/balance');
    $d = ['label' => 'Asaas' . ($creds['env'] === 'sandbox' ? ' (sandbox)' : ''), 'connector_name' => 'Asaas', 'status' => 'UPDATED', 'error_message' => null, 'credentials' => encrypt_secret(json_encode($creds)), 'updated_at' => now()];
    if ($existing) { db_update('fh_fin_connections', (int)$existing['id'], $d); $id = (int)$existing['id']; }
    else $id = db_insert('fh_fin_connections', $d + ['customer_id' => $cid, 'provider' => 'asaas', 'created_at' => now()]);
    $conn = db_find('fh_fin_connections', $id);
    $acc = fhf_conn_account($conn, 'asaas:' . $creds['env'], trim((string)($in['account_name'] ?? '')) ?: 'Conta Asaas', 'payment', '', 'Asaas', '461');
    if (isset($balance['balance'])) db_update('fh_fin_accounts', (int)$acc['id'], ['stmt_balance' => round((float)$balance['balance'], 2), 'stmt_balance_date' => today()]);
    return fhf_conn_sync($conn, 90);
}

function fhf_sync_asaas(array $conn, string $from): array
{
    $creds = fhf_creds($conn);
    $acc = fhf_conn_account($conn, 'asaas:' . ($creds['env'] ?? 'production'), 'Conta Asaas', 'payment', '', 'Asaas', '461');
    $rows = [];
    for ($offset = 0, $page = 0; $page < 100; $page++) {
        $j = fhf_asaas_get($creds, '/financialTransactions?' . http_build_query(['startDate' => $from, 'finishDate' => today(), 'offset' => $offset, 'limit' => 100]));
        $offset += max(1, count($j['data'] ?? []));
        foreach ($j['data'] ?? [] as $t) {
            $v = round((float)($t['value'] ?? 0), 2);
            if (abs($v) < 0.005 || empty($t['date'])) continue;
            $rows[] = ['external_id' => 'a:' . $t['id'], 'date' => substr((string)$t['date'], 0, 10), 'amount' => $v, 'description' => mb_substr(trim((string)($t['description'] ?? '')) ?: (string)($t['type'] ?? 'Movimentação'), 0, 255),
                'memo' => !empty($t['type']) ? mb_substr((string)$t['type'], 0, 255) : null, 'doc_number' => !empty($t['paymentId']) ? (string)$t['paymentId'] : null, 'balance' => isset($t['balance']) ? round((float)$t['balance'], 2) : null];
        }
        if (empty($j['hasMore'])) break;
    }
    $balance = fhf_asaas_get($creds, '/finance/balance');
    $r = fhf_import_statement((int)$conn['customer_id'], (int)$acc['id'], 'asaas', ['bank_id' => '461', 'account' => null, 'currency' => 'BRL', 'start' => $from, 'end' => today(),
        'balance' => isset($balance['balance']) ? round((float)$balance['balance'], 2) : null, 'balance_date' => today(), 'transactions' => $rows], 'API Asaas');
    db_update('fh_fin_connections', (int)$conn['id'], ['status' => 'UPDATED']);
    return ['accounts' => 1, 'new' => $r['new'], 'duplicates' => $r['duplicates']];
}

/* ------------------------------------------------------------------- common */

/** Pull new statement lines of a connection (default: since the last sync − 5 days, or 90 days). */
function fhf_conn_sync(array $conn, ?int $days = null): array
{
    fhf_bootstrap((int)$conn['customer_id']);
    $from = $days ? date('Y-m-d', strtotime("-$days days")) : ($conn['last_sync_at'] ? date('Y-m-d', strtotime($conn['last_sync_at'] . ' -5 days')) : date('Y-m-d', strtotime('-90 days')));
    try {
        $out = match ($conn['provider']) {
            'inter' => fhf_sync_inter($conn, $from),
            'asaas' => fhf_sync_asaas($conn, $from),
            'pluggy', 'meupluggy' => fhf_sync_pluggy($conn, $from),
            default => throw new AppException('Tipo de conexão desconhecido.'),
        };
        db_update('fh_fin_connections', (int)$conn['id'], ['last_sync_at' => now(), 'updated_at' => now()] + (in_array((string)db_value('SELECT status FROM fh_fin_connections WHERE id = ?', [$conn['id']]), ['LOGIN_ERROR', 'OUTDATED'], true) ? [] : ['error_message' => null]));
        db_exec('UPDATE fh_fin_accounts SET last_sync_at = ?, updated_at = ? WHERE connection_id = ?', [now(), now(), $conn['id']]);
    } catch (AppException $e) {
        db_update('fh_fin_connections', (int)$conn['id'], ['error_message' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
        throw $e;
    }
    return ['connection_id' => (int)$conn['id'], 'provider' => $conn['provider']] + $out;
}

function fhf_conn_delete(int $cid, int $id): void
{
    $conn = fhf_conn($cid, $id);
    if ($conn['provider'] === 'pluggy' && $conn['item_id']) {
        try { fhf_pluggy('DELETE', '/items/' . rawurlencode($conn['item_id'])); } catch (Throwable $e) { log_line('fiscalhub', 'pluggy delete failed', ['id' => $id, 'error' => $e->getMessage()]); }
    }
    // Meu Pluggy connections stay in the customer's own Meu Pluggy; we only forget them here.
    db_exec('UPDATE fh_fin_accounts SET connection_id = NULL, updated_at = ? WHERE connection_id = ?', [now(), $id]);
    db_exec('DELETE FROM fh_fin_connections WHERE id = ?', [$id]);
}

/** Cron: sync every connection once a day (Pluggy webhooks cover the platform account in between). */
function fhf_cron(): array
{
    $n = 0;
    $errors = [];
    foreach (db_all('SELECT * FROM fh_fin_connections WHERE last_sync_at IS NULL OR last_sync_at < ? ORDER BY last_sync_at LIMIT 40', [date('Y-m-d H:i:s', strtotime('-20 hours'))]) as $c) {
        if ($c['provider'] === 'pluggy' && !fhf_of_enabled()) continue;
        try { fhf_conn_sync($c); $n++; } catch (Throwable $e) { $errors[] = "#{$c['id']}: " . $e->getMessage(); }
    }
    return ['synced' => $n, 'errors' => $errors];
}
