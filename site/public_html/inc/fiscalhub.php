<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — NFS-e emission SaaS sold on the site to customers.
 *
 * This file: plans (priced from market research: Marília-integrated systems first, then a broad
 * national average, minus 10%), online checkout (terms + Asaas charge), subscription lifecycle
 * (payment → access, renewals, grace period, free months) and plan limits.
 * Emission engine: inc/fiscalhub_engine.php · documents/reports: inc/fiscalhub_docs.php
 */

require_once INC_PATH . '/nfse.php';
require_once INC_PATH . '/fiscalhub_engine.php';
require_once INC_PATH . '/fiscalhub_docs.php';
require_once INC_PATH . '/fiscalhub_finance.php';

const FH_NAME = 'Integra Fiscal Hub';
const FH_TERMS_VERSION = '2026.09';
const FH_MARKET_FACTOR = 0.90;
const FH_MARKET_DATE = '2026-09-27';
/** Free plan: 15 notes a month, no charge, renewed automatically (one per CPF/CNPJ). */
const FH_FREE_PLAN = 'gratis';
const FH_FREE_NOTES = 15;

/**
 * Market references used to price each tier (monthly price in BRL). Marília: Actana ERP is
 * homologated with the Marília SIGISS; no Marília-based company publishes NFS-e plan prices, so the
 * average also includes nationally sold NFS-e emitters of the same size.
 */
function fh_market_refs(): array
{
    return [
        'essencial' => [
            ['Actana ERP — Econômico (30 NFS-e/mês, integrado à Prefeitura de Marília)', 69.90, 'https://actana.com.br/planos-e-precos'],
            ['Actana ERP — FIT (5 NFS-e/mês, integrado à Prefeitura de Marília)', 49.90, 'https://actana.com.br/planos-e-precos'],
            ['Emitte — Prata (MEI, notas ilimitadas)', 59.90, 'https://emitte.com.br/preco/'],
            ['Meu Emissor NF-e — mensal', 37.90, 'https://meuemissornfe.com.br/precos/'],
            ['ClickNotas — mensal', 89.90, 'https://clicknotas.com.br/planos/'],
            ['MandaNotas — 100 notas/mês', 19.00, 'https://mandanotas.com.br/guias/planos-precos-mandanotas/'],
        ],
        'profissional' => [
            ['Actana ERP — Premium (80 NFS-e/mês, 2 empresas, integrado à Prefeitura de Marília)', 99.90, 'https://actana.com.br/planos-e-precos'],
            ['Emitte — Ouro', 79.90, 'https://emitte.com.br/preco/'],
            ['NFE.io — Inicial (100 notas/mês, R$ 1.075/ano)', 89.58, 'https://nfe.io/precos/emissao-nfse/'],
            ['Notafly — Starter (300 NFS-e/mês)', 99.00, 'https://notafly.com.br/'],
            ['eNotas — Básico (50 notas/mês)', 137.00, 'https://enotass.com.br/notas'],
            ['MandaNotas — 500 notas/mês', 59.00, 'https://mandanotas.com.br/guias/planos-precos-mandanotas/'],
        ],
        'business' => [
            ['Actana ERP — Master (800 NFS-e/mês, 3 empresas, integrado à Prefeitura de Marília)', 189.90, 'https://actana.com.br/planos-e-precos'],
            ['Emitte — Platina', 119.90, 'https://emitte.com.br/preco/'],
            ['NFE.io — Base (250 notas/mês)', 190.00, 'https://nfe.io/precos/emissao-nfse/'],
            ['NFE.io — Crescimento (500 notas/mês)', 265.00, 'https://nfe.io/precos/emissao-nfse/'],
            ['eNotas — Plus (500 notas/mês)', 247.00, 'https://enotass.com.br/notas'],
            ['Notafly — Pro (1.000 NFS-e/mês, até 10 CNPJs)', 199.00, 'https://notafly.com.br/'],
            ['MandaNotas — 2.500 notas/mês', 99.00, 'https://mandanotas.com.br/guias/planos-precos-mandanotas/'],
        ],
        'enterprise' => [
            ['eNotas — Pro (notas ilimitadas)', 347.00, 'https://enotass.com.br/notas'],
            ['NFE.io — Escala (1.000 notas/mês)', 375.00, 'https://nfe.io/precos/emissao-nfse/'],
            ['Notafly — Pro (até 10 CNPJs)', 199.00, 'https://notafly.com.br/'],
            ['MandaNotas — 5.000 notas/mês', 149.00, 'https://mandanotas.com.br/guias/planos-precos-mandanotas/'],
        ],
    ];
}

/** Average − 10%, rounded down to a price ending in ,90. */
function fh_price_from_avg(float $avg): float
{
    $p = $avg * FH_MARKET_FACTOR;
    $c = floor($p) + 0.90;
    if ($c > $p + 0.0001) $c -= 1;
    return round($c, 2);
}

function fh_default_plans(): array
{
    $refs = fh_market_refs();
    $avg = fn($k) => round(array_sum(array_column($refs[$k], 1)) / count($refs[$k]), 2);
    $base = ['Emissão pela Prefeitura de Marília (SIGISS) e pelo Emissor Nacional', 'Financeiro: contas a pagar e a receber, fluxo de caixa e DRE', 'Extrato bancário (OFX, Open Finance e APIs dos bancos) e conciliação', 'Cadastro ilimitado de clientes (tomadores) e serviços', 'Cálculo automático de ISS, retenções federais e valor líquido',
        'PDF e XML: download individual, múltiplo ou do período inteiro (ZIP)', 'Envio automático da nota por e-mail ao cliente', 'Cancelamento e substituição de notas'];
    $plans = [
        ['essencial', 'Essencial', 'Para MEI, autônomos e profissionais liberais', 30, 1, ['ai_quota' => 3, 'recurring' => false, 'batch' => false, 'priority' => false, 'open_finance' => false],
            array_merge(['1 empresa (CNPJ ou CPF)', 'Até 30 notas por mês'], $base, ['Relatórios mensais + 3 análises com IA por mês', 'Suporte por chamado'])],
        ['profissional', 'Profissional', 'Para prestadores de serviço em crescimento', 150, 1, ['ai_quota' => 20, 'recurring' => true, 'batch' => false, 'priority' => false, 'open_finance' => true],
            array_merge(['1 empresa (CNPJ ou CPF)', 'Até 150 notas por mês'], $base, ['Notas recorrentes automáticas (mensalidades)', 'Relatórios com IA (20 análises/mês)', 'Painel de impostos e retenções', 'Suporte por chamado e WhatsApp'])],
        ['business', 'Business', 'Para clínicas, escritórios e empresas com volume', 500, 3, ['ai_quota' => 60, 'recurring' => true, 'batch' => true, 'priority' => true, 'open_finance' => true],
            array_merge(['Até 3 empresas', 'Até 500 notas por mês'], $base, ['Emissão em lote por planilha (CSV)', 'Notas recorrentes automáticas', 'Relatórios avançados com IA (60 análises/mês)', 'Exportação contábil (CSV/Excel)', 'Suporte prioritário'])],
        ['enterprise', 'Enterprise', 'Para contadores e grupos de empresas', 2000, 10, ['ai_quota' => 200, 'recurring' => true, 'batch' => true, 'priority' => true, 'open_finance' => true],
            array_merge(['Até 10 empresas', 'Até 2.000 notas por mês'], $base, ['Tudo do Business', 'Onboarding assistido (cadastro de clientes e serviços)', 'Relatórios com IA (200 análises/mês)', 'Atendimento com SLA de 4 horas'])],
    ];
    $out = [[
        'code' => FH_FREE_PLAN, 'name' => 'Grátis', 'tagline' => 'Para começar: ' . FH_FREE_NOTES . ' notas por mês sem pagar nada', 'price_monthly' => 0, 'price_yearly' => 0, 'market_avg' => null,
        'notes_limit' => FH_FREE_NOTES, 'companies_limit' => 1, 'flags' => json_encode(['ai_quota' => 1, 'recurring' => false, 'batch' => false, 'priority' => false, 'open_finance' => false]),
        'features' => json_encode(['1 empresa (CNPJ ou CPF)', 'Até ' . FH_FREE_NOTES . ' notas por mês, grátis para sempre', 'Emissão pela Prefeitura de Marília (SIGISS) e pelo Emissor Nacional',
            'Financeiro: contas a pagar e a receber, fluxo de caixa e DRE', 'Extrato bancário (OFX, Open Finance e APIs dos bancos) e conciliação', 'Cálculo automático de ISS, retenções federais e valor líquido',
            'PDF e XML: download individual, múltiplo ou do período inteiro (ZIP)', 'Envio automático da nota por e-mail ao cliente', '1 análise com IA por mês', 'Suporte pela central de ajuda'], JSON_UNESCAPED_UNICODE),
        'highlight' => 0, 'active' => 1, 'position' => 0,
    ]];
    foreach ($plans as $i => [$code, $name, $tagline, $notes, $companies, $flags, $features]) {
        $a = $avg($code);
        $price = fh_price_from_avg($a);
        $out[] = ['code' => $code, 'name' => $name, 'tagline' => $tagline, 'price_monthly' => $price, 'price_yearly' => round($price * 10, 2), 'market_avg' => $a,
            'notes_limit' => $notes, 'companies_limit' => $companies, 'features' => json_encode($features, JSON_UNESCAPED_UNICODE), 'flags' => json_encode($flags),
            'highlight' => $code === 'profissional' ? 1 : 0, 'active' => 1, 'position' => $i + 1];
    }
    return $out;
}

function fh_plans_seed(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if ((int)db_value('SELECT COUNT(*) FROM fh_plans') === 0) {
        foreach (fh_default_plans() as $p) db_insert('fh_plans', $p + ['created_at' => now(), 'updated_at' => now()]);
        set_setting('fh_free_plan_seeded', '1');
        return;
    }
    // installs created before the free plan existed get it once (the team may deactivate it later)
    if (setting('fh_free_plan_seeded') !== '1') {
        if (!db_value('SELECT id FROM fh_plans WHERE code = ?', [FH_FREE_PLAN])) db_insert('fh_plans', fh_default_plans()[0] + ['created_at' => now(), 'updated_at' => now()]);
        set_setting('fh_free_plan_seeded', '1');
    }
}

function fh_plan_row(array $p): array
{
    $p['features'] = json_decode((string)$p['features'], true) ?: [];
    $p['flags'] = (json_decode((string)$p['flags'], true) ?: []) + ['ai_quota' => 0, 'recurring' => false, 'batch' => false, 'priority' => false, 'open_finance' => false];
    foreach (['price_monthly', 'price_yearly', 'market_avg'] as $k) $p[$k] = (float)$p[$k];
    foreach (['notes_limit', 'companies_limit', 'highlight', 'active', 'position', 'id'] as $k) $p[$k] = (int)$p[$k];
    return $p;
}

function fh_plans(bool $activeOnly = true): array
{
    fh_plans_seed();
    return array_map('fh_plan_row', db_all('SELECT * FROM fh_plans' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY position, id'));
}

function fh_plan(string $code): ?array
{
    fh_plans_seed();
    $p = db_one('SELECT * FROM fh_plans WHERE code = ?', [$code]);
    return $p ? fh_plan_row($p) : null;
}

/** Subscription (price) or plan (price_monthly) with nothing to charge. */
function fh_is_free(?array $x): bool
{
    if (!$x) return false;
    return array_key_exists('price', $x) ? (float)$x['price'] <= 0 : (float)($x['price_monthly'] ?? 1) <= 0;
}

/* ============================================================ SUBSCRIPTIONS */

const FH_SUB_STATUS = ['pending' => 'Aguardando pagamento', 'active' => 'Ativa', 'past_due' => 'Em atraso', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada'];

/** Current subscription of a customer (latest that is not an abandoned pending one when there is a better one). */
function fh_subscription(int $customerId): ?array
{
    $rows = db_all("SELECT * FROM fh_subscriptions WHERE customer_id = ? ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'past_due' THEN 1 WHEN 'pending' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END, id DESC LIMIT 1", [$customerId]);
    return $rows[0] ?? null;
}

function fh_event(int $subId, string $kind, string $description, array $extra = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    db_insert('fh_sub_events', ['subscription_id' => $subId, 'kind' => $kind, 'description' => mb_substr($description, 0, 255),
        'amount' => $extra['amount'] ?? null, 'months' => $extra['months'] ?? null, 'charge_id' => $extra['charge_id'] ?? null,
        'user_name' => $extra['user'] ?? ($user['name'] ?? null), 'created_at' => now()]);
}

function fh_grace_days(): int
{
    return max(0, min(30, (int)setting('fh_grace_days', '5')));
}

/** Notes emitted (authorized, incl. later canceled) by a customer in a month. */
function fh_notes_used(int $customerId, ?string $month = null): int
{
    $month = $month ?: date('Y-m');
    return (int)db_value("SELECT COUNT(*) FROM fh_invoices WHERE customer_id = ? AND issued_at >= ? AND issued_at < ? AND status IN ('authorized','canceled','processing')",
        [$customerId, $month . '-01 00:00:00', date('Y-m-01', strtotime($month . '-01 +1 month')) . ' 00:00:00']);
}

function fh_ai_used(int $customerId): int
{
    return (int)db_value("SELECT COUNT(*) FROM ai_logs WHERE feature = ? AND created_at >= ?", ['fh_report_' . $customerId, date('Y-m-01 00:00:00')]);
}

/**
 * Access summary for the portal and every API call.
 * state: none | pending | active | grace | expired | suspended | canceled
 */
function fh_access(int $customerId): array
{
    $sub = fh_subscription($customerId);
    $out = ['sub' => $sub, 'plan' => null, 'state' => 'none', 'can_view' => false, 'can_emit' => false, 'message' => null];
    if (!$sub) return $out;
    $plan = fh_plan($sub['plan_code']);
    $out['plan'] = $plan;
    $out['can_view'] = true;
    $today = today();
    $paidUntil = $sub['paid_until'];
    $graceEnd = $paidUntil ? date('Y-m-d', strtotime($paidUntil . ' +' . fh_grace_days() . ' days')) : null;
    if ($sub['status'] === 'pending') {
        $out['state'] = 'pending';
        $out['message'] = 'Pagamento pendente. Assim que o pagamento for confirmado, a emissão é liberada automaticamente.';
    } elseif ($sub['status'] === 'suspended') {
        $out['state'] = 'suspended';
        $out['message'] = 'Assinatura suspensa. Fale com a Integra Code para reativar. Suas notas continuam disponíveis para consulta e download.';
    } elseif ((float)$sub['price'] <= 0 && in_array($sub['status'], ['active', 'past_due'], true)) {
        $out['state'] = 'active'; // free plan (or 100% discount): no payment to wait for
    } elseif ($paidUntil && $today <= $paidUntil) {
        $out['state'] = $sub['status'] === 'canceled' ? 'canceled_active' : 'active';
    } elseif ($graceEnd && $today <= $graceEnd && $sub['status'] !== 'canceled') {
        $out['state'] = 'grace';
        $out['message'] = 'Sua mensalidade venceu em ' . date('d/m/Y', strtotime($paidUntil)) . '. A emissão continua liberada até ' . date('d/m/Y', strtotime($graceEnd)) . '.';
    } else {
        $out['state'] = $sub['status'] === 'canceled' ? 'canceled' : 'expired';
        $out['message'] = $sub['status'] === 'canceled' ? 'Assinatura cancelada. Suas notas continuam disponíveis para consulta e download.' : 'Assinatura em atraso: regularize o pagamento para voltar a emitir. Consulta e download continuam liberados.';
    }
    $out['can_emit'] = in_array($out['state'], ['active', 'grace', 'canceled_active'], true);
    $limit = ($plan['notes_limit'] ?? 0) + (int)$sub['bonus_notes'];
    $used = fh_notes_used($customerId);
    $out['usage'] = ['notes_used' => $used, 'notes_limit' => $limit, 'companies' => (int)db_value('SELECT COUNT(*) FROM fh_emitters WHERE customer_id = ? AND active = 1', [$customerId]),
        'companies_limit' => $plan['companies_limit'] ?? 1, 'ai_used' => fh_ai_used($customerId), 'ai_limit' => $plan['flags']['ai_quota'] ?? 0];
    $out['grace_until'] = $graceEnd;
    return $out;
}

/** Throws when the customer cannot emit now (subscription + monthly limit). */
function fh_require_emit(int $customerId, int $count = 1): array
{
    $a = fh_access($customerId);
    if (!$a['sub']) throw new AppException('Contrate um plano do ' . FH_NAME . ' para emitir notas.');
    if (!$a['can_emit']) throw new AppException($a['message'] ?: 'A emissão está bloqueada para esta assinatura.');
    if ($a['usage']['notes_used'] + $count > $a['usage']['notes_limit']) {
        throw new AppException('Você atingiu o limite de ' . $a['usage']['notes_limit'] . ' notas do plano ' . ($a['plan']['name'] ?? '') . ' neste mês. Faça upgrade do plano ou fale com a nossa equipe.');
    }
    return $a;
}

function fh_require_flag(int $customerId, string $flag): void
{
    $a = fh_access($customerId);
    if (empty($a['plan']['flags'][$flag])) {
        $names = ['recurring' => 'Notas recorrentes', 'batch' => 'Emissão em lote', 'open_finance' => 'A conexão bancária por Open Finance'];
        throw new AppException(($names[$flag] ?? 'Este recurso') . ' não está incluído no seu plano. Faça upgrade para liberar.');
    }
}

/* ================================================================ CHECKOUT */

function fh_valid_doc(string $doc): bool
{
    $d = only_digits($doc);
    if (strlen($d) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $d)) return false;
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) $sum += (int)$d[$i] * (($t + 1) - $i);
            if ((int)$d[$t] !== ((10 * $sum) % 11) % 10) return false;
        }
        return true;
    }
    if (strlen($d) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $d)) return false;
        $calc = function (string $base) {
            $w = strlen($base) === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $s = 0;
            foreach (str_split($base) as $i => $c) $s += (int)$c * $w[$i];
            $r = $s % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        return $calc(substr($d, 0, 12)) === (int)$d[12] && $calc(substr($d, 0, 13)) === (int)$d[13];
    }
    return false;
}

function fh_period_label(string $cycle): string
{
    return $cycle === 'yearly' ? 'anual' : 'mensal';
}

/**
 * Public checkout. Creates the customer account when needed (and logs it in), the pending
 * subscription with the signed acceptance of the terms, and the first Asaas charge.
 * @return array{subscription:array, pay_url:?string, error:?string}
 */
function fh_checkout(array $in, ?array $loggedCustomer): array
{
    if (setting('fh_sales_enabled', '1') !== '1') throw new AppException('As contratações online estão temporariamente pausadas. Fale com a nossa equipe.');
    $plan = fh_plan((string)($in['plan'] ?? ''));
    if (!$plan || !$plan['active']) throw new AppException('Escolha um plano válido.');
    $free = fh_is_free($plan);
    $cycle = $free ? 'monthly' : (($in['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly');
    $billing = in_array($in['billing_type'] ?? '', BILLING_TYPES, true) ? $in['billing_type'] : 'UNDEFINED';
    if (empty($in['terms'])) throw new AppException('Para contratar, leia e aceite os Termos de Uso do ' . FH_NAME . '.');
    $signName = trim((string)($in['terms_name'] ?? ''));
    if (mb_strlen($signName) < 5) throw new AppException('Digite seu nome completo para assinar o aceite dos termos.');
    $doc = only_digits((string)($in['document'] ?? ''));
    if (!fh_valid_doc($doc)) throw new AppException($free ? 'Informe um CPF ou CNPJ válido.' : 'Informe um CPF ou CNPJ válido para a cobrança.');
    if ($free && db_value("SELECT s.id FROM fh_subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.price <= 0 AND s.status IN ('active', 'past_due') AND c.document = ?" . ($loggedCustomer ? ' AND c.id != ?' : ''), $loggedCustomer ? [$doc, (int)$loggedCustomer['id']] : [$doc])) {
        throw new AppException('Este CPF/CNPJ já tem um plano grátis ativo em outra conta. Entre com essa conta ou escolha um plano pago.');
    }
    $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 30);

    if ($loggedCustomer) {
        $customer = db_find('customers', (int)$loggedCustomer['id']);
        $upd = ['updated_at' => now()];
        if (!only_digits((string)$customer['document'])) $upd['document'] = $doc;
        if (!$customer['phone'] && $phone) $upd['phone'] = $phone;
        db_update('customers', (int)$customer['id'], $upd);
        $customer = array_merge($customer, $upd);
    } else {
        require_once INC_PATH . '/account.php';
        $name = trim((string)($in['name'] ?? ''));
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        if (mb_strlen($name) < 3) throw new AppException('Informe seu nome ou a razão social.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppException('Informe um e-mail válido.');
        if ($problem = password_problem($password)) throw new AppException($problem);
        if (db_value('SELECT id FROM users WHERE email = ?', [$email]) || db_value('SELECT id FROM customers WHERE email = ?', [$email])) {
            throw new AppException('Este e-mail já tem cadastro. Entre na sua conta para concluir a contratação.');
        }
        $id = db_insert('customers', ['name' => mb_substr($name, 0, 160), 'document' => $doc, 'email' => $email, 'phone' => $phone ?: null, 'status' => 'active',
            'portal_enabled' => 1, 'portal_password_hash' => password_hash($password, PASSWORD_DEFAULT), 'tags' => 'fiscal-hub',
            'notes' => 'Conta criada na contratação online do ' . FH_NAME . '.', 'created_at' => now(), 'updated_at' => now()]);
        $customer = db_find('customers', $id);
        login_customer($customer);
        try { send_verification_email($customer); } catch (Throwable $e) { /* optional */ }
    }
    $current = fh_subscription((int)$customer['id']);
    if ($current && in_array($current['status'], ['active', 'past_due'], true)) {
        throw new AppException('Você já tem uma assinatura do ' . FH_NAME . '. Para mudar de plano, acesse Área do Cliente → Fiscal Hub → Assinatura.');
    }
    if ($current && $current['status'] === 'pending') {
        db_update('fh_subscriptions', (int)$current['id'], ['status' => 'canceled', 'canceled_at' => now(), 'cancel_reason' => 'Substituída por nova contratação', 'updated_at' => now()]);
    }
    $price = $cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
    $subId = db_insert('fh_subscriptions', [
        'customer_id' => $customer['id'], 'plan_code' => $plan['code'], 'cycle' => $cycle, 'price' => $price, 'status' => 'pending', 'billing_type' => $billing,
        'terms_version' => FH_TERMS_VERSION, 'terms_accepted_at' => now(), 'terms_ip' => client_ip(), 'terms_name' => mb_substr($signName, 0, 160),
        'next_charge_date' => $free ? null : today(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    fh_event($subId, 'created', "Contratação online: plano {$plan['name']} (" . ($free ? 'grátis)' : fh_period_label($cycle) . ') — ' . money($price)) . '. Termos v' . FH_TERMS_VERSION . " aceitos por $signName (IP " . client_ip() . ').', ['amount' => $price, 'user' => $signName]);
    db_update('customers', (int)$customer['id'], ['tags' => trim(implode(',', array_unique(array_filter(array_merge(explode(',', (string)($customer['tags'] ?? '')), ['fiscal-hub'])))), ','), 'updated_at' => now()]);
    $sub = db_find('fh_subscriptions', $subId);
    $payUrl = null;
    $error = null;
    if ($free) {
        fh_extend($sub, 1, 'free', 'Plano Grátis ativado (' . FH_FREE_NOTES . ' notas por mês, sem cobrança).', ['user' => $signName]);
        return ['subscription' => db_find('fh_subscriptions', $subId), 'pay_url' => null, 'error' => null, 'free' => true];
    }
    try {
        $charge = fh_create_charge($sub, today());
        $payUrl = $charge['invoice_url'] ?? null;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        log_line('fiscalhub', 'checkout charge failed', ['sub' => $subId, 'error' => $error]);
    }
    if (function_exists('mail_team')) {
        mail_team('🧾 Nova contratação do ' . FH_NAME . ': ' . $customer['name'], mail_template('Nova assinatura (aguardando pagamento)', mail_details(['Cliente' => $customer['name'], 'E-mail' => $customer['email'], 'Plano' => $plan['name'] . ' (' . fh_period_label($cycle) . ')', 'Valor' => money($price), 'Cobrança' => $error ? 'NÃO gerada: ' . $error : 'gerada no Asaas']), ['label' => 'Abrir no painel', 'url' => app_link('/admin/#/fiscal-hub/subscriptions/' . $subId)]), ['event' => 'fh_checkout_team']);
    }
    return ['subscription' => db_find('fh_subscriptions', $subId), 'pay_url' => $payUrl, 'error' => $error];
}

/** Asaas charge for the next period of a subscription (links charge + receivable entry). */
function fh_create_charge(array $sub, string $dueDate): array
{
    if (fh_is_free($sub)) throw new AppException('O plano grátis não tem cobrança.');
    $plan = fh_plan($sub['plan_code']);
    $months = $sub['cycle'] === 'yearly' ? 12 : 1;
    $start = $sub['paid_until'] && $sub['paid_until'] >= today() ? date('Y-m-d', strtotime($sub['paid_until'] . ' +1 day')) : today();
    $end = date('Y-m-d', strtotime($start . " +$months months -1 day"));
    $charge = create_charge([
        'customer_id' => $sub['customer_id'], 'amount' => $sub['price'], 'due_date' => max($dueDate, today()), 'billing_type' => $sub['billing_type'] ?: 'UNDEFINED',
        'description' => FH_NAME . ' — plano ' . ($plan['name'] ?? $sub['plan_code']) . ' (' . fh_period_label($sub['cycle']) . ') — ' . date('d/m/Y', strtotime($start)) . ' a ' . date('d/m/Y', strtotime($end)),
        'category_id' => (int)setting('fh_revenue_category', '0') ?: null, 'create_entry' => true,
    ]);
    db_update('charges', (int)$charge['id'], ['fh_subscription_id' => $sub['id']]);
    fh_event((int)$sub['id'], 'charge', 'Cobrança gerada: ' . money($sub['price']) . ' com vencimento em ' . date('d/m/Y', strtotime($charge['due_date'])) . '.', ['amount' => $sub['price'], 'charge_id' => $charge['id'], 'user' => 'sistema']);
    return db_find('charges', (int)$charge['id']);
}

/** Open (unpaid) charge of a subscription, if any. */
function fh_open_charge(int $subId): ?array
{
    return db_one("SELECT * FROM charges WHERE fh_subscription_id = ? AND status IN ('PENDING','OVERDUE','AWAITING_RISK_ANALYSIS','CREATING') ORDER BY id DESC LIMIT 1", [$subId]);
}

/**
 * Called from apply_payment_update() for every charge change. Extends the subscription when a
 * Fiscal Hub charge is paid (idempotent per charge).
 */
function fh_charge_updated(array $charge, bool $isPaid): void
{
    if (empty($charge['fh_subscription_id']) || !$isPaid) return;
    if (db_value("SELECT id FROM fh_sub_events WHERE charge_id = ? AND kind = 'payment'", [$charge['id']])) return;
    $sub = db_find('fh_subscriptions', (int)$charge['fh_subscription_id']);
    if (!$sub) return;
    $months = $sub['cycle'] === 'yearly' ? 12 : 1;
    fh_extend($sub, $months, 'payment', 'Pagamento confirmado: ' . money($charge['amount']) . '.', ['charge_id' => $charge['id'], 'amount' => $charge['amount'], 'user' => 'Asaas']);
}

/** Extend access by N months (payment, manual activation or free months). */
function fh_extend(array $sub, int $months, string $kind, string $description, array $extra = []): array
{
    $from = $sub['paid_until'] && $sub['paid_until'] >= date('Y-m-d', strtotime('-1 day')) ? $sub['paid_until'] : date('Y-m-d', strtotime('-1 day'));
    $until = date('Y-m-d', strtotime($from . " +$months months"));
    $firstActivation = !$sub['started_at'];
    $status = in_array($sub['status'], ['canceled'], true) && $kind !== 'payment' ? 'canceled' : 'active';
    db_update('fh_subscriptions', (int)$sub['id'], ['status' => $status, 'paid_until' => $until, 'next_charge_date' => fh_is_free($sub) ? null : date('Y-m-d', strtotime($until . ' +1 day')),
        'started_at' => $sub['started_at'] ?: now(), 'updated_at' => now()]);
    fh_event((int)$sub['id'], $kind, $description . ' Acesso liberado até ' . date('d/m/Y', strtotime($until)) . '.', $extra + ['months' => $months]);
    $customer = db_find('customers', (int)$sub['customer_id']);
    if ($firstActivation && $customer) {
        if (!$customer['email_verified_at'] && $kind === 'payment') db_update('customers', (int)$customer['id'], ['email_verified_at' => now()]);
        if (!empty($customer['email']) && function_exists('mail_queue')) {
            mail_queue($customer['email'], FH_NAME . ' liberado! Vamos emitir sua primeira nota', mail_template('Seu ' . FH_NAME . ' está ativo 🎉',
                '<p>Olá! ' . ($kind === 'payment' ? 'Recebemos seu pagamento e ' : '') . 'o seu emissor de notas fiscais de serviço já está liberado.</p><p><b>Próximos passos (5 minutos):</b></p><ol><li>Cadastre sua empresa: CNPJ, inscrição municipal e regime tributário.</li><li>Para Marília, informe a senha do SIGISS. Para o Emissor Nacional, envie o certificado digital A1 (.pfx).</li><li>Cadastre seus serviços e clientes, ou emita direto que o sistema guarda para você.</li></ol>',
                ['label' => 'Abrir o Fiscal Hub', 'url' => app_link('/cliente/fiscal/')], 'Dúvidas? Responda este e-mail ou abra um chamado na Área do Cliente.'), ['event' => 'fh_activated', 'name' => $customer['name']]);
        }
    }
    return db_find('fh_subscriptions', (int)$sub['id']);
}

/**
 * Daily billing: renewal charges (lead days before the period ends) and status updates.
 */
function fh_billing_run(): array
{
    $lead = max(0, min(30, (int)setting('fh_lead_days', '7')));
    $out = ['charges' => 0, 'past_due' => 0, 'errors' => []];
    $limitDate = date('Y-m-d', strtotime("+$lead days"));
    foreach (db_all("SELECT * FROM fh_subscriptions WHERE status IN ('active','past_due') AND price <= 0 AND (paid_until IS NULL OR paid_until < ?)", [$limitDate]) as $sub) {
        fh_extend($sub, 1, 'free_renewal', 'Plano grátis renovado automaticamente.', ['user' => 'sistema']);
        $out['free_renewed'] = ($out['free_renewed'] ?? 0) + 1;
    }
    foreach (db_all("SELECT * FROM fh_subscriptions WHERE status IN ('active','past_due') AND price > 0 AND next_charge_date IS NOT NULL AND next_charge_date <= ?", [$limitDate]) as $sub) {
        if (fh_open_charge((int)$sub['id'])) continue;
        if (db_value('SELECT COUNT(*) FROM charges WHERE fh_subscription_id = ? AND due_date >= ?', [$sub['id'], $sub['next_charge_date']])) continue;
        try {
            fh_create_charge($sub, $sub['next_charge_date']);
            $out['charges']++;
        } catch (Throwable $e) {
            $out['errors'][] = "#{$sub['id']}: " . $e->getMessage();
        }
    }
    $graceLimit = date('Y-m-d', strtotime('-' . fh_grace_days() . ' days'));
    foreach (db_all("SELECT * FROM fh_subscriptions WHERE status = 'active' AND price > 0 AND paid_until IS NOT NULL AND paid_until < ?", [$graceLimit]) as $sub) {
        db_update('fh_subscriptions', (int)$sub['id'], ['status' => 'past_due', 'updated_at' => now()]);
        fh_event((int)$sub['id'], 'status', 'Assinatura em atraso: a emissão foi bloqueada até a regularização.', ['user' => 'sistema']);
        $out['past_due']++;
        $c = db_find('customers', (int)$sub['customer_id']);
        if ($c && !empty($c['email']) && function_exists('mail_queue')) {
            $open = fh_open_charge((int)$sub['id']);
            mail_queue($c['email'], FH_NAME . ': mensalidade em atraso', mail_template('Sua emissão de notas foi pausada',
                '<p>Não identificamos o pagamento da sua assinatura do ' . FH_NAME . '. A emissão de novas notas foi pausada, mas todas as notas continuam disponíveis para consulta e download.</p><p>Assim que o pagamento for confirmado, a emissão é liberada automaticamente.</p>',
                $open && $open['invoice_url'] ? ['label' => 'Pagar agora', 'url' => $open['invoice_url']] : ['label' => 'Abrir Área do Cliente', 'url' => app_link('/cliente/fiscal/#/assinatura')]), ['event' => 'fh_past_due', 'name' => $c['name']]);
        }
    }
    return $out;
}

/* ================================================================== ADMIN */

function fh_admin_free_months(int $subId, int $months, string $reason, bool $cancelOpenCharge): array
{
    $sub = db_find('fh_subscriptions', $subId);
    if (!$sub) throw new AppException('Assinatura não encontrada.');
    if ($months < 1 || $months > 36) throw new AppException('Informe de 1 a 36 meses.');
    $note = '';
    if ($cancelOpenCharge && ($open = fh_open_charge($subId))) {
        try {
            cancel_charge($open);
            $note = ' Cobrança em aberto cancelada.';
        } catch (Throwable $e) {
            $note = ' (não foi possível cancelar a cobrança em aberto: ' . $e->getMessage() . ')';
        }
    }
    return fh_extend($sub, $months, 'free_months', "Cortesia: $months " . ($months === 1 ? 'mês grátis' : 'meses grátis') . ($reason !== '' ? " — $reason." : '.') . $note);
}

function fh_admin_create(array $in): array
{
    $customer = db_find('customers', (int)($in['customer_id'] ?? 0));
    if (!$customer) throw new AppException('Selecione o cliente.');
    $plan = fh_plan((string)($in['plan_code'] ?? ''));
    if (!$plan) throw new AppException('Escolha o plano.');
    if (($cur = fh_subscription((int)$customer['id'])) && in_array($cur['status'], ['active', 'past_due', 'pending'], true)) throw new AppException('Este cliente já tem uma assinatura (' . (FH_SUB_STATUS[$cur['status']] ?? $cur['status']) . ').');
    $cycle = ($in['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $price = isset($in['price']) && $in['price'] !== '' ? round((float)$in['price'], 2) : ($cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly']);
    $id = db_insert('fh_subscriptions', ['customer_id' => $customer['id'], 'plan_code' => $plan['code'], 'cycle' => $cycle, 'price' => $price, 'status' => 'pending',
        'billing_type' => 'UNDEFINED', 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 2000), 'created_at' => now(), 'updated_at' => now()]);
    fh_event($id, 'created', "Assinatura criada pela equipe: plano {$plan['name']} (" . fh_period_label($cycle) . ') — ' . money($price) . '.');
    db_update('customers', (int)$customer['id'], ['portal_enabled' => 1, 'updated_at' => now()]);
    $sub = db_find('fh_subscriptions', $id);
    $free = (int)($in['free_months'] ?? 0);
    if (fh_is_free($sub)) $sub = fh_extend($sub, 1, 'free', 'Plano sem cobrança ativado pela equipe.');
    elseif ($free > 0) $sub = fh_admin_free_months($id, $free, (string)($in['reason'] ?? 'cortesia na ativação'), false);
    elseif (!empty($in['charge_now'])) fh_create_charge($sub, today());
    return $sub;
}

function fh_admin_update(int $subId, array $in): array
{
    $sub = db_find('fh_subscriptions', $subId);
    if (!$sub) throw new AppException('Assinatura não encontrada.');
    $upd = [];
    $log = [];
    if (!empty($in['plan_code']) && $in['plan_code'] !== $sub['plan_code']) {
        $plan = fh_plan((string)$in['plan_code']);
        if (!$plan) throw new AppException('Plano inválido.');
        $upd['plan_code'] = $plan['code'];
        $log[] = 'plano → ' . $plan['name'];
        if (!isset($in['price']) || $in['price'] === '') $upd['price'] = ($in['cycle'] ?? $sub['cycle']) === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
    }
    if (!empty($in['cycle']) && in_array($in['cycle'], ['monthly', 'yearly'], true) && $in['cycle'] !== $sub['cycle']) { $upd['cycle'] = $in['cycle']; $log[] = 'ciclo → ' . fh_period_label($in['cycle']); }
    if (isset($in['price']) && $in['price'] !== '' && round((float)$in['price'], 2) != (float)$sub['price']) { $upd['price'] = round(max(0, (float)$in['price']), 2); $log[] = 'valor → ' . money($upd['price']); }
    if (isset($in['bonus_notes']) && (int)$in['bonus_notes'] !== (int)$sub['bonus_notes']) { $upd['bonus_notes'] = max(0, (int)$in['bonus_notes']); $log[] = 'notas extras/mês → ' . $upd['bonus_notes']; }
    if (isset($in['paid_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['paid_until']) && $in['paid_until'] !== $sub['paid_until']) {
        $upd['paid_until'] = $in['paid_until'];
        $upd['next_charge_date'] = date('Y-m-d', strtotime($in['paid_until'] . ' +1 day'));
        $log[] = 'acesso até ' . date('d/m/Y', strtotime($in['paid_until']));
    }
    if (isset($in['status']) && isset(FH_SUB_STATUS[$in['status']]) && $in['status'] !== $sub['status']) {
        $upd['status'] = $in['status'];
        if ($in['status'] === 'canceled') { $upd['canceled_at'] = now(); $upd['cancel_reason'] = mb_substr((string)($in['reason'] ?? 'Cancelada pela equipe'), 0, 255); }
        $log[] = 'situação → ' . FH_SUB_STATUS[$in['status']];
    }
    if (array_key_exists('notes', $in)) $upd['notes'] = mb_substr((string)$in['notes'], 0, 2000);
    if ($upd) db_update('fh_subscriptions', $subId, $upd + ['updated_at' => now()]);
    if ($log) fh_event($subId, 'change', 'Alteração: ' . implode('; ', $log) . (!empty($in['reason']) ? ' — ' . $in['reason'] : '') . '.');
    return db_find('fh_subscriptions', $subId);
}

/** Customer-side plan change: applies on the next renewal (immediately when still unpaid). */
function fh_customer_change_plan(int $customerId, string $code, string $cycle): array
{
    $sub = fh_subscription($customerId);
    if (!$sub || in_array($sub['status'], ['canceled', 'suspended'], true)) throw new AppException('Nenhuma assinatura ativa para alterar.');
    $plan = fh_plan($code);
    if (!$plan || !$plan['active']) throw new AppException('Plano inválido.');
    $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
    $price = $cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
    $companies = (int)db_value('SELECT COUNT(*) FROM fh_emitters WHERE customer_id = ? AND active = 1', [$customerId]);
    if ($companies > $plan['companies_limit']) throw new AppException("O plano {$plan['name']} permite {$plan['companies_limit']} empresa(s) e você tem $companies cadastrada(s). Desative empresas antes de mudar.");
    if (fh_is_free($plan) && !fh_is_free($sub) && db_value("SELECT s.id FROM fh_subscriptions s JOIN customers c ON c.id = s.customer_id JOIN customers me ON me.id = ? WHERE s.price <= 0 AND s.status IN ('active', 'past_due') AND c.document = me.document AND c.id != me.id", [$customerId])) {
        throw new AppException('Este CPF/CNPJ já tem um plano grátis ativo em outra conta.');
    }
    if (fh_is_free($sub) && !fh_is_free($plan) && $sub['status'] !== 'pending') {
        // upgrade from the free plan: bill right away; access continues meanwhile
        db_update('fh_subscriptions', (int)$sub['id'], ['plan_code' => $plan['code'], 'cycle' => $cycle, 'price' => $price, 'paid_until' => today(), 'next_charge_date' => today(), 'updated_at' => now()]);
        fh_event((int)$sub['id'], 'change', "Cliente passou do plano grátis para o {$plan['name']} (" . fh_period_label($cycle) . ') — ' . money($price) . '.');
        try {
            $charge = fh_create_charge(db_find('fh_subscriptions', (int)$sub['id']), today());
        } catch (Throwable $e) {
            log_line('fiscalhub', 'upgrade charge failed', ['sub' => $sub['id'], 'error' => $e->getMessage()]);
            return ['subscription' => db_find('fh_subscriptions', (int)$sub['id']), 'pay_url' => null, 'immediate' => true, 'error' => 'Plano alterado, mas não conseguimos gerar a fatura agora (' . $e->getMessage() . '). Use "Gerar pagamento" em instantes.'];
        }
        if (function_exists('mail_team')) mail_team('⬆️ Upgrade do plano grátis no ' . FH_NAME, mail_template('Upgrade de plano', mail_details(['Cliente' => (string)db_value('SELECT name FROM customers WHERE id = ?', [$customerId]), 'Novo plano' => $plan['name'] . ' (' . fh_period_label($cycle) . ')', 'Valor' => money($price)]), ['label' => 'Abrir', 'url' => app_link('/admin/#/fiscal-hub/subscriptions/' . $sub['id'])]), ['event' => 'fh_plan_team']);
        return ['subscription' => db_find('fh_subscriptions', (int)$sub['id']), 'pay_url' => $charge['invoice_url'] ?? null, 'immediate' => true];
    }
    if ($sub['status'] === 'pending') {
        if ($open = fh_open_charge((int)$sub['id'])) { try { cancel_charge($open); } catch (Throwable $e) { /* keep going */ } }
        db_update('fh_subscriptions', (int)$sub['id'], ['plan_code' => $plan['code'], 'cycle' => $cycle, 'price' => $price, 'updated_at' => now()]);
        fh_event((int)$sub['id'], 'change', "Cliente trocou para o plano {$plan['name']} (" . fh_period_label($cycle) . ') antes do pagamento.');
        $charge = fh_create_charge(db_find('fh_subscriptions', (int)$sub['id']), today());
        return ['subscription' => db_find('fh_subscriptions', (int)$sub['id']), 'pay_url' => $charge['invoice_url'] ?? null, 'immediate' => true];
    }
    if (fh_is_free($plan)) {
        // downgrade to the free plan: no more renewals to charge
        if ($open = fh_open_charge((int)$sub['id'])) { try { cancel_charge($open); } catch (Throwable $e) { /* keep going */ } }
        db_update('fh_subscriptions', (int)$sub['id'], ['plan_code' => $plan['code'], 'cycle' => 'monthly', 'price' => 0, 'next_charge_date' => null, 'status' => 'active', 'updated_at' => now()]);
        fh_event((int)$sub['id'], 'change', 'Cliente mudou para o plano Grátis (' . FH_FREE_NOTES . ' notas por mês, sem cobrança).');
        return ['subscription' => db_find('fh_subscriptions', (int)$sub['id']), 'pay_url' => null, 'immediate' => true];
    }
    db_update('fh_subscriptions', (int)$sub['id'], ['plan_code' => $plan['code'], 'cycle' => $cycle, 'price' => $price, 'updated_at' => now()]);
    $up = $price > (float)$sub['price'];
    fh_event((int)$sub['id'], 'change', "Cliente alterou para o plano {$plan['name']} (" . fh_period_label($cycle) . ') — ' . money($price) . ' a partir da próxima renovação.');
    if (function_exists('mail_team')) mail_team(($up ? '⬆️ Upgrade' : '⬇️ Alteração') . ' no ' . FH_NAME, mail_template('Alteração de plano', mail_details(['Cliente' => (string)db_value('SELECT name FROM customers WHERE id = ?', [$customerId]), 'Novo plano' => $plan['name'] . ' (' . fh_period_label($cycle) . ')', 'Valor' => money($price)]), ['label' => 'Abrir', 'url' => app_link('/admin/#/fiscal-hub/subscriptions/' . $sub['id'])]), ['event' => 'fh_plan_team']);
    return ['subscription' => db_find('fh_subscriptions', (int)$sub['id']), 'pay_url' => null, 'immediate' => false];
}

function fh_customer_cancel(int $customerId, string $reason): array
{
    $sub = fh_subscription($customerId);
    if (!$sub || $sub['status'] === 'canceled') throw new AppException('Nenhuma assinatura ativa.');
    if ($open = fh_open_charge((int)$sub['id'])) { try { cancel_charge($open); } catch (Throwable $e) { /* keep going */ } }
    db_update('fh_subscriptions', (int)$sub['id'], ['status' => 'canceled', 'canceled_at' => now(), 'cancel_reason' => mb_substr($reason ?: 'Cancelada pelo cliente', 0, 255), 'next_charge_date' => null, 'updated_at' => now()]);
    fh_event((int)$sub['id'], 'status', 'Cancelada pelo cliente' . ($reason ? ": $reason" : '') . '. Acesso mantido até ' . ($sub['paid_until'] ? date('d/m/Y', strtotime($sub['paid_until'])) : 'hoje') . '.', ['user' => 'cliente']);
    if (function_exists('mail_team')) mail_team('❌ Cancelamento no ' . FH_NAME, mail_template('Assinatura cancelada pelo cliente', mail_details(['Cliente' => (string)db_value('SELECT name FROM customers WHERE id = ?', [$customerId]), 'Motivo' => $reason ?: '—']), ['label' => 'Abrir', 'url' => app_link('/admin/#/fiscal-hub/subscriptions/' . $sub['id'])]), ['event' => 'fh_cancel_team']);
    return db_find('fh_subscriptions', (int)$sub['id']);
}

function fh_mrr(): float
{
    $sum = 0.0;
    foreach (db_all("SELECT price, cycle FROM fh_subscriptions WHERE status IN ('active','past_due')") as $s) $sum += $s['cycle'] === 'yearly' ? (float)$s['price'] / 12 : (float)$s['price'];
    return round($sum, 2);
}

function fh_admin_dashboard(): array
{
    $byStatus = [];
    foreach (db_all('SELECT status, COUNT(*) AS n FROM fh_subscriptions GROUP BY status') as $r) $byStatus[$r['status']] = (int)$r['n'];
    $month = date('Y-m-01 00:00:00');
    $inv = db_one("SELECT COUNT(*) AS total, SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END) AS authorized, SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,
        COALESCE(SUM(CASE WHEN status='authorized' THEN amount END),0) AS amount FROM fh_invoices WHERE created_at >= ?", [$month]);
    $byPlan = db_all("SELECT plan_code, COUNT(*) AS n, SUM(CASE WHEN cycle='yearly' THEN price/12 ELSE price END) AS mrr FROM fh_subscriptions WHERE status IN ('active','past_due') GROUP BY plan_code");
    $expiring = db_all("SELECT e.id, e.legal_name, e.cert_valid_to, c.name AS customer_name, e.customer_id FROM fh_emitters e JOIN customers c ON c.id = e.customer_id WHERE e.provider = 'nacional' AND e.cert_valid_to IS NOT NULL AND e.cert_valid_to <= ? ORDER BY e.cert_valid_to LIMIT 20", [date('Y-m-d', strtotime('+30 days'))]);
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("first day of -$i months"));
        $months[] = ['month' => $m, 'notes' => (int)db_value("SELECT COUNT(*) FROM fh_invoices WHERE status IN ('authorized','canceled') AND issued_at >= ? AND issued_at < ?", [$m . '-01 00:00:00', date('Y-m-01', strtotime($m . '-01 +1 month')) . ' 00:00:00']),
            'revenue' => (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries e JOIN charges c ON c.entry_id = e.id WHERE c.fh_subscription_id IS NOT NULL AND e.status = 'paid' AND e.paid_at >= ? AND e.paid_at < ?", [$m . '-01', date('Y-m-01', strtotime($m . '-01 +1 month'))])];
    }
    return ['by_status' => $byStatus, 'mrr' => fh_mrr(), 'arr' => round(fh_mrr() * 12, 2), 'invoices_month' => $inv, 'by_plan' => $byPlan, 'cert_expiring' => $expiring, 'months' => $months,
        'events' => db_all('SELECT ev.*, s.customer_id, c.name AS customer_name FROM fh_sub_events ev JOIN fh_subscriptions s ON s.id = ev.subscription_id JOIN customers c ON c.id = s.customer_id ORDER BY ev.id DESC LIMIT 15'),
        'emitters' => (int)db_value('SELECT COUNT(*) FROM fh_emitters WHERE active = 1')];
}
