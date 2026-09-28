<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — automatic recurring payables/receivables (rent, salaries, subscriptions,
 * monthly fees...). A template (fh_fin_recurring) creates each entry on its own, "lead_days" before
 * the due date, with no fixed number of repetitions (optionally until an end date or N times).
 * Generation runs from the cron and lazily whenever the finance screens are opened, so it works
 * even where no cron is configured. Each step advances next_due with a conditional UPDATE, so two
 * runs at the same time can never create the same entry twice.
 * Loaded by inc/fiscalhub_finance.php.
 */

/** frequency => [label, months, days] */
const FHF_FREQ = [
    'weekly' => ['Semanal', 0, 7], 'biweekly' => ['Quinzenal', 0, 14], 'monthly' => ['Mensal', 1, 0], 'bimonthly' => ['Bimestral', 2, 0],
    'quarterly' => ['Trimestral', 3, 0], 'semiannual' => ['Semestral', 6, 0], 'yearly' => ['Anual', 12, 0],
];
const FHF_REC_MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

function fhf_rec(int $cid, int $id): array
{
    $r = db_one('SELECT * FROM fh_fin_recurring WHERE id = ? AND customer_id = ?', [$id, $cid]);
    if (!$r) throw new AppException('Recorrência não encontrada.');
    return $r;
}

/** Next due date after $date for the template frequency (month-based ones keep the chosen day, 31 → last day). */
function fhf_rec_next(string $date, string $freq, ?int $day): string
{
    [, $months, $days] = FHF_FREQ[$freq] ?? FHF_FREQ['monthly'];
    return $months ? fhf_add_months($date, $months, $day) : date('Y-m-d', strtotime($date . " +$days days"));
}

/** True when $due is past the end date or the maximum number of occurrences was reached. */
function fhf_rec_ended(array $r, string $due, int $generated): bool
{
    return ($r['end_date'] && $due > $r['end_date']) || ($r['max_occurrences'] && $generated >= (int)$r['max_occurrences']);
}

/** The next $n due dates still to be generated. */
function fhf_rec_schedule(array $r, int $n = 6): array
{
    $out = [];
    $due = (string)$r['next_due'];
    $g = (int)$r['generated_count'];
    while (count($out) < $n && !fhf_rec_ended($r, $due, $g)) {
        $out[] = $due;
        $due = fhf_rec_next($due, (string)$r['frequency'], $r['day_of_month'] ? (int)$r['day_of_month'] : null);
        $g++;
    }
    return $out;
}

/** "Aluguel {mes_ano}" → "Aluguel setembro/2026" (reference month = due date). */
function fhf_rec_text(string $text, string $due): string
{
    $m = (int)substr($due, 5, 2);
    $y = substr($due, 0, 4);
    return strtr($text, ['{mes}' => FHF_REC_MONTHS[$m - 1], '{ano}' => $y, '{mes_ano}' => FHF_REC_MONTHS[$m - 1] . '/' . $y, '{mm/aaaa}' => sprintf('%02d/%s', $m, $y), '{vencimento}' => date('d/m/Y', strtotime($due))]);
}

function fhf_rec_public(array $r, bool $full = true): array
{
    $r['amount'] = (float)$r['amount'];
    $r['frequency_label'] = FHF_FREQ[$r['frequency']][0] ?? $r['frequency'];
    $r['ended'] = fhf_rec_ended($r, (string)$r['next_due'], (int)$r['generated_count']);
    if ($full) {
        $r['schedule'] = fhf_rec_schedule($r, 6);
        $r['preview'] = fhf_rec_text((string)$r['description'], $r['schedule'][0] ?? (string)$r['next_due']);
        $st = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) AS open, COALESCE(SUM(CASE WHEN status = 'open' AND due_date < ? THEN 1 ELSE 0 END), 0) AS overdue,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN paid_amount ELSE 0 END), 0) AS paid FROM fh_fin_entries WHERE recurring_id = ?", [today(), $r['id']]);
        $r['entries'] = (int)$st['n'];
        $r['open'] = (int)$st['open'];
        $r['overdue'] = (int)$st['overdue'];
        $r['paid_total'] = round((float)$st['paid'], 2);
        // upcoming due dates: entries already created and still open, then the ones still to be created
        $r['open_dues'] = array_column(db_all("SELECT due_date FROM fh_fin_entries WHERE recurring_id = ? AND status = 'open' ORDER BY due_date LIMIT 6", [$r['id']]), 'due_date');
        $r['upcoming'] = array_slice(array_values(array_unique(array_merge($r['open_dues'], $r['schedule']))), 0, 6);
        if ($r['upcoming']) $r['preview'] = fhf_rec_text((string)$r['description'], $r['upcoming'][0]);
        $r['category_name'] = $r['category_id'] ? db_value('SELECT name FROM fh_fin_categories WHERE id = ?', [$r['category_id']]) : null;
        $r['account_name'] = $r['account_id'] ? db_value('SELECT name FROM fh_fin_accounts WHERE id = ?', [$r['account_id']]) : null;
    }
    return $r;
}

function fhf_rec_list(int $cid, array $q = []): array
{
    fhf_rec_run_customer($cid);
    $w = ['customer_id = ?'];
    $p = [$cid];
    if (in_array($q['kind'] ?? '', ['receivable', 'payable'], true)) { $w[] = 'kind = ?'; $p[] = $q['kind']; }
    $rows = array_map('fhf_rec_public', db_all('SELECT * FROM fh_fin_recurring WHERE ' . implode(' AND ', $w) . ' ORDER BY active DESC, next_due, id', $p));
    $monthly = 0.0;
    foreach ($rows as $r) {
        if (!(int)$r['active'] || $r['ended']) continue;
        [, $months, $days] = FHF_FREQ[$r['frequency']];
        $monthly += $months ? $r['amount'] / $months : $r['amount'] * 30 / $days;
    }
    return ['data' => $rows, 'frequencies' => array_map(fn($f) => $f[0], FHF_FREQ), 'monthly_total' => round($monthly, 2)];
}

/** Validated template fields. */
function fhf_rec_fields(int $cid, array $in, ?array $existing): array
{
    // description, amount, category, account, party, method... share the entry rules
    $probe = array_intersect_key($in, array_flip(['description', 'amount', 'category_id', 'account_id', 'emitter_id', 'taker_id', 'party_name', 'party_document', 'cost_center', 'notes', 'payment_method']));
    if (!$existing) $probe += ['kind' => $in['kind'] ?? 'receivable', 'due_date' => today()];
    $d = fhf_entry_fields($cid, $probe, $existing ? ['kind' => $existing['kind']] + $existing : null);
    unset($d['due_date'], $d['document_number'], $d['tags']);
    if (!$existing) $d['kind'] = ($in['kind'] ?? '') === 'payable' ? 'payable' : 'receivable';
    if (array_key_exists('frequency', $in) || !$existing) {
        $d['frequency'] = isset(FHF_FREQ[$in['frequency'] ?? '']) ? $in['frequency'] : 'monthly';
    }
    $freq = $d['frequency'] ?? $existing['frequency'];
    if (!$existing || array_key_exists('start_date', $in)) {
        $start = fhf_date($in['start_date'] ?? '');
        if (!$start) throw new AppException('Informe o primeiro vencimento.');
        if (!$existing || (int)$existing['generated_count'] === 0) $d['next_due'] = $start;
        $d['start_date'] = $start;
    }
    if (array_key_exists('end_date', $in)) $d['end_date'] = fhf_date($in['end_date']);
    if (array_key_exists('max_occurrences', $in)) $d['max_occurrences'] = (int)$in['max_occurrences'] > 0 ? min(1000, (int)$in['max_occurrences']) : null;
    $start = $d['start_date'] ?? $existing['start_date'];
    if (!empty($d['end_date']) && $d['end_date'] < $start) throw new AppException('A data de término é anterior ao primeiro vencimento.');
    $d['day_of_month'] = FHF_FREQ[$freq][1] ? (int)substr($start, 8, 2) : null;
    if (array_key_exists('lead_days', $in) || !$existing) $d['lead_days'] = max(0, min(90, (int)($in['lead_days'] ?? 30)));
    if (array_key_exists('auto_settle', $in)) $d['auto_settle'] = !empty($in['auto_settle']) ? 1 : 0;
    if (!empty($d['auto_settle'] ?? $existing['auto_settle'] ?? 0) && empty($d['account_id'] ?? $existing['account_id'] ?? null) && !fhf_default_account($cid)) {
        throw new AppException('Para a baixa automática, escolha a conta onde o valor cai ou sai.');
    }
    if (array_key_exists('active', $in)) $d['active'] = !empty($in['active']) ? 1 : 0;
    return $d;
}

function fhf_rec_save(int $cid, array $in, ?array $existing): array
{
    $d = fhf_rec_fields($cid, $in, $existing);
    if ($existing) {
        db_update('fh_fin_recurring', (int)$existing['id'], $d + ['updated_at' => now()]);
        $r = fhf_rec($cid, (int)$existing['id']);
        if (!empty($in['apply_open'])) {
            // the open entries already generated follow the new amount, text, category, account...
            $share = array_intersect_key($d, array_flip(['amount', 'category_id', 'account_id', 'party_name', 'party_document', 'taker_id', 'cost_center', 'payment_method', 'notes']));
            foreach (db_all("SELECT id, due_date FROM fh_fin_entries WHERE recurring_id = ? AND status = 'open' AND transaction_id IS NULL", [$r['id']]) as $e) {
                db_update('fh_fin_entries', (int)$e['id'], $share + (isset($d['description']) ? ['description' => mb_substr(fhf_rec_text((string)$d['description'], $e['due_date']), 0, 255)] : []) + ['updated_at' => now()]);
            }
        }
    } else {
        $id = db_insert('fh_fin_recurring', $d + ['customer_id' => $cid, 'generated_count' => 0, 'series_key' => null, 'active' => $d['active'] ?? 1, 'created_at' => now(), 'updated_at' => now()]);
        db_update('fh_fin_recurring', $id, ['series_key' => 'ar-' . $id]);
        $r = fhf_rec($cid, $id);
    }
    fhf_rec_sync($r);
    return fhf_rec_public(fhf_rec($cid, (int)$r['id']));
}

/**
 * Create the entries that are due within lead_days (or just the next one with $force).
 * @return int entries created
 */
function fhf_rec_generate(array $r, bool $force = false): int
{
    if (!(int)$r['active'] && !$force) return 0;
    $limit = date('Y-m-d', strtotime(today() . ' +' . (int)$r['lead_days'] . ' days'));
    $made = 0;
    for ($guard = 0; $guard < 120; $guard++) {
        $r = db_one('SELECT * FROM fh_fin_recurring WHERE id = ?', [$r['id']]);
        $due = (string)$r['next_due'];
        if (fhf_rec_ended($r, $due, (int)$r['generated_count'])) break;
        if ($due > $limit && !($force && $made === 0)) break;
        $next = fhf_rec_next($due, (string)$r['frequency'], $r['day_of_month'] ? (int)$r['day_of_month'] : null);
        $created = db_transaction(function () use ($r, $due, $next) {
            // claim this due date: only one concurrent run gets affected rows = 1
            $claimed = db_exec('UPDATE fh_fin_recurring SET next_due = ?, generated_count = generated_count + 1, last_generated = ?, updated_at = ? WHERE id = ? AND next_due = ?', [$next, $due, now(), $r['id'], $due]);
            if (!$claimed) return false;
            // never two entries of the same recurrence on the same due date (e.g. after the dates were edited)
            if (db_value('SELECT id FROM fh_fin_entries WHERE recurring_id = ? AND due_date = ?', [$r['id'], $due])) return false;
            db_insert('fh_fin_entries', [
                'customer_id' => $r['customer_id'], 'emitter_id' => $r['emitter_id'], 'kind' => $r['kind'], 'description' => mb_substr(fhf_rec_text((string)$r['description'], $due), 0, 255),
                'category_id' => $r['category_id'], 'account_id' => $r['account_id'], 'party_name' => $r['party_name'], 'party_document' => $r['party_document'], 'taker_id' => $r['taker_id'],
                'amount' => $r['amount'], 'due_date' => $due, 'competence_date' => $due, 'status' => 'open', 'payment_method' => $r['payment_method'], 'cost_center' => $r['cost_center'], 'notes' => $r['notes'],
                'series_key' => $r['series_key'], 'recurrence' => $r['frequency'], 'recurring_id' => $r['id'], 'origin' => 'recurring', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return true;
        });
        if ($created) $made++;
    }
    return $made;
}

/** Generate what is due and settle what reached the due date (used right after a change). */
function fhf_rec_sync(array $r, bool $force = false): int
{
    $made = fhf_rec_generate($r, $force);
    $r = db_one('SELECT * FROM fh_fin_recurring WHERE id = ?', [$r['id']]);
    if ((int)$r['active']) fhf_rec_settle($r);
    return $made;
}

/** Automatic settlement (débito automático, cartão, salário...) of the entries that reached the due date. */
function fhf_rec_settle(array $r): int
{
    if (!(int)$r['auto_settle']) return 0;
    $n = 0;
    foreach (db_all("SELECT id, due_date FROM fh_fin_entries WHERE recurring_id = ? AND status = 'open' AND due_date <= ?", [$r['id'], today()]) as $e) {
        try {
            fhf_entry_pay((int)$r['customer_id'], (int)$e['id'], ['paid_at' => $e['due_date'], 'account_id' => $r['account_id'] ?: null]);
            $n++;
        } catch (Throwable $ex) {
            log_line('fiscalhub', 'recurring auto settle failed', ['recurring' => $r['id'], 'entry' => $e['id'], 'error' => $ex->getMessage()]);
        }
    }
    return $n;
}

/** Everything due for one customer (called when the finance screens load). */
function fhf_rec_run_customer(int $cid): array
{
    $made = $settled = 0;
    $horizon = date('Y-m-d', strtotime(today() . ' +90 days'));
    foreach (db_all('SELECT * FROM fh_fin_recurring WHERE customer_id = ? AND active = 1 AND next_due <= ?', [$cid, $horizon]) as $r) $made += fhf_rec_generate($r);
    foreach (db_all("SELECT DISTINCT r.* FROM fh_fin_recurring r JOIN fh_fin_entries e ON e.recurring_id = r.id AND e.status = 'open' AND e.due_date <= ? WHERE r.customer_id = ? AND r.auto_settle = 1 AND r.active = 1", [today(), $cid]) as $r) $settled += fhf_rec_settle($r);
    return ['created' => $made, 'settled' => $settled];
}

/** Cron: every customer. */
function fhf_rec_run_all(): array
{
    $out = ['created' => 0, 'settled' => 0, 'errors' => []];
    $horizon = date('Y-m-d', strtotime(today() . ' +90 days'));
    $cids = array_unique(array_merge(
        array_column(db_all('SELECT DISTINCT customer_id FROM fh_fin_recurring WHERE active = 1 AND next_due <= ?', [$horizon]), 'customer_id'),
        array_column(db_all("SELECT DISTINCT r.customer_id FROM fh_fin_recurring r JOIN fh_fin_entries e ON e.recurring_id = r.id AND e.status = 'open' AND e.due_date <= ? WHERE r.auto_settle = 1 AND r.active = 1", [today()]), 'customer_id')
    ));
    foreach ($cids as $cid) {
        if (!fh_access((int)$cid)['can_emit']) continue; // read-only subscription: nothing new is created
        try {
            $r = fhf_rec_run_customer((int)$cid);
            $out['created'] += $r['created'];
            $out['settled'] += $r['settled'];
        } catch (Throwable $e) {
            $out['errors'][] = "#$cid: " . $e->getMessage();
        }
    }
    return $out;
}

/** Remove a template; with $deleteOpen the open entries still to come are removed too (paid history stays). */
function fhf_rec_delete(int $cid, int $id, bool $deleteOpen): int
{
    $r = fhf_rec($cid, $id);
    $n = 0;
    db_transaction(function () use ($r, $deleteOpen, &$n) {
        if ($deleteOpen) $n = db_exec("DELETE FROM fh_fin_entries WHERE recurring_id = ? AND status = 'open' AND transaction_id IS NULL AND due_date >= ?", [$r['id'], today()]);
        db_exec('UPDATE fh_fin_entries SET recurring_id = NULL WHERE recurring_id = ?', [$r['id']]);
        db_exec('DELETE FROM fh_fin_recurring WHERE id = ?', [$r['id']]);
    });
    return $n;
}

/** Preview for the form: next due dates and the description of the first one. */
function fhf_rec_preview(array $in): array
{
    $start = fhf_date($in['start_date'] ?? '', today());
    $freq = isset(FHF_FREQ[$in['frequency'] ?? '']) ? $in['frequency'] : 'monthly';
    $r = ['next_due' => $start, 'frequency' => $freq, 'day_of_month' => FHF_FREQ[$freq][1] ? (int)substr($start, 8, 2) : null, 'generated_count' => 0,
        'end_date' => fhf_date($in['end_date'] ?? ''), 'max_occurrences' => (int)($in['max_occurrences'] ?? 0) ?: null];
    $sched = fhf_rec_schedule($r, 6);
    $lead = max(0, min(90, (int)($in['lead_days'] ?? 30)));
    return ['schedule' => $sched, 'text' => fhf_rec_text((string)($in['description'] ?? ''), $sched[0] ?? $start),
        'first_created' => $sched ? (date('Y-m-d', strtotime($sched[0] . " -$lead days")) <= today() ? today() : date('Y-m-d', strtotime($sched[0] . " -$lead days"))) : null];
}
