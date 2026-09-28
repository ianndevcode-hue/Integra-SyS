<?php
declare(strict_types=1);

/**
 * Pricing: price catalog (with Marília market references), quote simulator,
 * contracts (recurring monthly billing), and the AI pricing consultant.
 *
 * Contract rule: a quote can only be accepted with ≥1 "implantação", exactly 1 "suporte"
 * and ≥1 "mensalidade" item.
 */

const PRICE_CATEGORIES = [
    'implantacao' => ['Implantação', 'Configuração, migração inicial e treinamento. Obrigatória na contratação.', true],
    'mensalidade' => ['Mensalidade', 'Licença do Integra SYS ou manutenção/hospedagem do sistema. Obrigatória.', true],
    'suporte' => ['Suporte', 'Plano de suporte técnico. Obrigatório escolher um.', true],
    'licenca' => ['Licenças adicionais', 'Usuários, caixas/PDV, módulos e filiais extras.', false],
    'desenvolvimento' => ['Desenvolvimento', 'Projetos sob medida e horas técnicas.', false],
    'adicional' => ['Adicionais', 'Treinamentos, visitas, migrações, hospedagem e automações.', false],
];
const PRICE_BILLING = ['one_time' => 'Valor único', 'monthly' => 'Mensal', 'yearly' => 'Anual', 'hourly' => 'Por hora'];
const MARKET_FACTOR = 0.90; // Integra price = Marília average − 10%

function pricing_market_source(string $cat): string
{
    $s = [
        'implantacao' => 'Implantação de ERP em pequenas empresas: R$ 800–2.500 (casos simples) até R$ 20 mil com personalização. Fontes: cora.com.br, nomus.com.br, movimentosistemas.com.br (2026).',
        'mensalidade' => 'Mensalidade de ERP/SaaS para PMEs: R$ 180–500/mês (pequenos) e R$ 600–1.500/mês (serviços, mais usuários/módulos). Fontes: everflow.com.br, movimentosistemas.com.br, erpsuite.com.br (2026).',
        'suporte' => 'Contratos de suporte de TI para PMEs a partir de R$ 500/mês; remoto a partir de R$ 40/equipamento; avulso a partir de R$ 170. Fontes: utidainformatica.net, ocaradati.com.br (2025).',
        'licenca' => 'Usuários, PDVs e módulos adicionais de ERPs SaaS (tabelas públicas de fornecedores nacionais, 2025–2026).',
        'desenvolvimento' => 'Hora de desenvolvimento: R$ 40–160 (freelancer) e R$ 110–150 (full-stack em plataformas). Fontes: brfreelas.com.br, treinaweb.com.br (2025).',
        'adicional' => 'Treinamento, visita técnica e serviços gerenciados: tabelas de prestadores de TI do interior de SP (2025).',
    ];
    return 'Estimativa para Marília-SP (interior, abaixo das capitais) a partir de pesquisas de mercado. ' . ($s[$cat] ?? '');
}

/** [code, name, category, billing, unit, tier, min, avg, max, LC116 item, description, includes] */
function pricing_default_catalog(): array
{
    return [
        ['IMP-ESS', 'Implantação Essencial', 'implantacao', 'one_time', 'projeto', 'essencial', 800, 1800, 3500, '01.07', 'Para operações de até 3 usuários e 1 unidade.', "Configuração do sistema\nCadastro inicial (até 500 itens)\nTreinamento de 4 h\nAcompanhamento na 1ª semana"],
        ['IMP-PRO', 'Implantação Profissional', 'implantacao', 'one_time', 'projeto', 'profissional', 2500, 4500, 8000, '01.07', 'Até 10 usuários, migração de dados e configuração fiscal.', "Migração de dados de planilhas/sistema anterior\nConfiguração fiscal (NF-e/NFC-e/NFS-e)\nTreinamento de 8 h\nAcompanhamento de 30 dias"],
        ['IMP-ENT', 'Implantação Enterprise', 'implantacao', 'one_time', 'projeto', 'enterprise', 6000, 12000, 20000, '01.07', 'Multiunidade, integrações e migração completa.', "Migração completa com validação\nIntegrações (marketplaces, bancos, delivery)\nTreinamento de 16 h por equipe\nGerente de implantação dedicado"],
        ['MEN-SYS-ESS', 'Mensalidade Integra SYS Essencial', 'mensalidade', 'monthly', 'mês', 'essencial', 150, 300, 500, '01.05', 'Até 3 usuários: financeiro, vendas e estoque.', "Até 3 usuários\nFinanceiro, vendas e estoque\nEmissão de notas fiscais\nBackups diários"],
        ['MEN-SYS-PRO', 'Mensalidade Integra SYS Profissional', 'mensalidade', 'monthly', 'mês', 'profissional', 400, 750, 1200, '01.05', 'Até 10 usuários com todos os módulos e BI.', "Até 10 usuários\nTodos os módulos\nBI e relatórios\nIntegrações padrão"],
        ['MEN-SYS-ENT', 'Mensalidade Integra SYS Enterprise', 'mensalidade', 'monthly', 'mês', 'enterprise', 900, 1500, 3000, '01.05', 'Usuários ilimitados, multiempresa e API.', "Usuários ilimitados\nMultiempresa/filiais\nAPI e webhooks\nAmbiente dedicado"],
        ['MEN-SOB', 'Mensalidade de sistema sob medida', 'mensalidade', 'monthly', 'mês', null, 300, 800, 2000, '01.03', 'Hospedagem, monitoramento e manutenção corretiva do sistema desenvolvido.', "Servidor e domínio\nMonitoramento e backups\nCorreções e atualizações de segurança"],
        ['SUP-BAS', 'Suporte Básico', 'suporte', 'monthly', 'mês', 'basico', 150, 350, 600, '01.07', 'Remoto, horário comercial, primeira resposta em até 24 h.', "Atendimento remoto\nChamados pela Área do Cliente\nSLA de 24 h\nAté 5 chamados/mês"],
        ['SUP-PRO', 'Suporte Profissional', 'suporte', 'monthly', 'mês', 'profissional', 400, 750, 1200, '01.07', 'Chamados ilimitados, WhatsApp e primeira resposta em até 8 h.', "Chamados ilimitados\nWhatsApp e telefone\nSLA de 8 h\nAjustes pequenos inclusos (2 h/mês)"],
        ['SUP-PREM', 'Suporte Premium', 'suporte', 'monthly', 'mês', 'premium', 900, 1600, 2800, '01.07', 'SLA de 4 h, gerente de conta e visita presencial mensal em Marília.', "SLA de 4 h\nGerente de conta\n1 visita presencial/mês (Marília)\nHorário estendido"],
        ['LIC-USR', 'Usuário adicional', 'licenca', 'monthly', 'usuário/mês', null, 30, 60, 100, '01.05', 'Acesso para mais um usuário do Integra SYS.', ''],
        ['LIC-PDV', 'Caixa / PDV adicional', 'licenca', 'monthly', 'PDV/mês', null, 50, 90, 150, '01.05', 'Frente de caixa adicional.', ''],
        ['LIC-MOD', 'Módulo adicional', 'licenca', 'monthly', 'módulo/mês', null, 50, 120, 250, '01.05', 'Módulo extra (ex.: fiscal avançado, delivery, CRM).', ''],
        ['LIC-FIL', 'Filial / unidade adicional', 'licenca', 'monthly', 'filial/mês', null, 100, 200, 400, '01.05', 'Operação de mais uma loja/unidade.', ''],
        ['DEV-HORA', 'Hora técnica de desenvolvimento', 'desenvolvimento', 'hourly', 'hora', null, 90, 150, 250, '01.01', 'Customizações, relatórios e melhorias sob demanda.', ''],
        ['DEV-SITE', 'Site institucional', 'desenvolvimento', 'one_time', 'projeto', null, 1500, 3500, 8000, '01.08', 'Site responsivo, rápido e otimizado para o Google.', "Até 6 páginas\nFormulário e WhatsApp\nSEO básico\nPainel para editar conteúdo"],
        ['DEV-LOJA', 'Loja virtual (e-commerce)', 'desenvolvimento', 'one_time', 'projeto', null, 4000, 9000, 20000, '01.08', 'Loja com pagamento online, frete e integração ao estoque.', "Catálogo e carrinho\nPIX, cartão e boleto\nCálculo de frete\nIntegração com o estoque"],
        ['DEV-WEB', 'Sistema web sob medida (MVP)', 'desenvolvimento', 'one_time', 'projeto', null, 8000, 20000, 50000, '01.01', 'Primeira versão de um sistema sob medida.', "Levantamento de requisitos\nProtótipo navegável\nDesenvolvimento e testes\nImplantação"],
        ['DEV-APP', 'Aplicativo mobile (MVP)', 'desenvolvimento', 'one_time', 'projeto', null, 15000, 35000, 80000, '01.01', 'App Android e iOS com painel de gestão.', "Android e iOS\nPainel administrativo\nPublicação nas lojas"],
        ['DEV-BI', 'Dashboard de indicadores (BI)', 'desenvolvimento', 'one_time', 'projeto', null, 2000, 5000, 12000, '01.01', 'Painel gerencial com os indicadores do negócio.', ''],
        ['DEV-INT', 'Integração (iFood, marketplace, banco, API)', 'desenvolvimento', 'one_time', 'integração', null, 1500, 3500, 8000, '01.01', 'Conexão do sistema com plataformas externas.', ''],
        ['DEV-BOT', 'Chatbot WhatsApp com IA (implantação)', 'desenvolvimento', 'one_time', 'projeto', null, 1500, 3500, 7000, '01.01', 'Atendimento automático 24 h no WhatsApp.', ''],
        ['ADD-TRE', 'Treinamento adicional', 'adicional', 'hourly', 'hora', null, 100, 180, 300, '08.02', 'Treinamento extra da equipe (remoto ou presencial).', ''],
        ['ADD-VIS', 'Visita técnica presencial (Marília)', 'adicional', 'one_time', 'visita', null, 150, 250, 400, '01.07', 'Atendimento no local, até 2 h.', ''],
        ['ADD-MIG', 'Migração de dados avulsa', 'adicional', 'one_time', 'projeto', null, 500, 1500, 4000, '01.07', 'Importação de dados de outro sistema ou planilhas.', ''],
        ['ADD-HOS', 'Hospedagem e domínio gerenciados', 'adicional', 'monthly', 'mês', null, 40, 90, 200, '01.03', 'Servidor, domínio, SSL e e-mails.', ''],
        ['ADD-BKP', 'Backup em nuvem gerenciado', 'adicional', 'monthly', 'mês', null, 50, 120, 250, '01.03', 'Cópias automáticas com monitoramento.', ''],
        ['ADD-BOTM', 'Chatbot WhatsApp com IA (mensalidade)', 'adicional', 'monthly', 'mês', null, 150, 350, 800, '01.03', 'Operação, IA e manutenção do chatbot.', ''],
    ];
}

function pricing_round(float $v): float
{
    if ($v >= 1000) return round($v / 10) * 10;
    if ($v >= 100) return round($v);
    return round($v, 2);
}

function pricing_seed(): void
{
    if ((int)db_value('SELECT COUNT(*) FROM price_items')) return;
    foreach (pricing_default_catalog() as $i => [$code, $name, $cat, $billing, $unit, $tier, $min, $avg, $max, $lc, $desc, $inc]) {
        db_insert('price_items', [
            'code' => $code, 'name' => $name, 'category' => $cat, 'billing' => $billing, 'unit' => $unit, 'tier' => $tier,
            'price' => pricing_round($avg * MARKET_FACTOR), 'market_min' => $min, 'market_avg' => $avg, 'market_max' => $max,
            'market_source' => pricing_market_source($cat), 'market_updated_at' => '2026-09-27', 'max_discount' => $cat === 'implantacao' ? 20 : 15,
            'description' => $desc, 'includes' => $inc, 'service_code' => $lc, 'active' => 1, 'position' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function pricing_catalog(bool $onlyActive = false): array
{
    pricing_seed();
    return db_all('SELECT * FROM price_items' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY position, id');
}

/** LC 116 item ("01.07") → code for the configured NFS-e provider. */
function nfse_code_for(string $lc, string $provider): string
{
    $d = only_digits($lc);
    if (strlen($d) < 3) return '';
    $item = (int)substr($d, 0, strlen($d) - 2);
    $sub = substr($d, -2);
    return $provider === 'sigiss' ? $item . $sub : str_pad((string)$item, 2, '0', STR_PAD_LEFT) . $sub . '01';
}

/* ================================================================== QUOTE */

/**
 * Normalize items against the catalog and compute totals.
 * $items: [{item_id, qty, price?}]; returns [lines, totals, missing requirements, warnings]
 */
function pricing_calculate(array $items, float $setupDiscount = 0, float $monthlyDiscount = 0, int $installments = 1, int $months = 12): array
{
    $catalog = array_column(pricing_catalog(), null, 'id');
    $lines = [];
    $warn = [];
    foreach ($items as $it) {
        $p = $catalog[(int)($it['item_id'] ?? 0)] ?? null;
        if (!$p) continue;
        $qty = max(0.5, min(10000, round((float)str_replace(',', '.', (string)($it['qty'] ?? 1)), 2)));
        $price = isset($it['price']) && $it['price'] !== '' ? round((float)str_replace(',', '.', (string)$it['price']), 2) : (float)$p['price'];
        if ($price <= 0) $price = (float)$p['price'];
        $floor = (float)$p['price'] * (1 - (float)$p['max_discount'] / 100);
        if ($price < $floor) $warn[] = "{$p['name']}: preço abaixo do desconto máximo permitido ({$p['max_discount']}%).";
        if ($p['market_min'] && $price < (float)$p['market_min']) $warn[] = "{$p['name']}: preço abaixo do mínimo de mercado em Marília (" . money($p['market_min']) . ').';
        $recurring = in_array($p['billing'], ['monthly', 'yearly'], true);
        $lines[] = [
            'item_id' => (int)$p['id'], 'code' => $p['code'], 'name' => $p['name'], 'category' => $p['category'], 'billing' => $p['billing'], 'unit' => $p['unit'],
            'qty' => $qty, 'price' => $price, 'list_price' => (float)$p['price'], 'market_avg' => (float)$p['market_avg'], 'service_code' => $p['service_code'],
            'total' => round($qty * $price, 2), 'monthly' => $p['billing'] === 'yearly' ? round($qty * $price / 12, 2) : ($recurring ? round($qty * $price, 2) : 0),
            'recurring' => $recurring,
        ];
    }
    $setupGross = array_sum(array_map(fn($l) => $l['recurring'] ? 0 : $l['total'], $lines));
    $monthlyGross = array_sum(array_column($lines, 'monthly'));
    $setupDiscount = max(0, min(50, $setupDiscount));
    $monthlyDiscount = max(0, min(50, $monthlyDiscount));
    $setup = round($setupGross * (1 - $setupDiscount / 100), 2);
    $monthly = round($monthlyGross * (1 - $monthlyDiscount / 100), 2);
    $installments = max(1, min(12, $installments));
    $months = max(1, min(60, $months));
    $count = fn($c) => count(array_filter($lines, fn($l) => $l['category'] === $c));
    $missing = [];
    if ($count('implantacao') < 1) $missing[] = 'Escolha uma implantação.';
    if ($count('suporte') !== 1) $missing[] = $count('suporte') ? 'Escolha apenas um plano de suporte.' : 'Escolha um plano de suporte.';
    if ($count('mensalidade') < 1) $missing[] = 'Escolha uma mensalidade (plano do Integra SYS ou manutenção do sistema).';
    $marketMonthly = array_sum(array_map(fn($l) => $l['recurring'] ? $l['qty'] * $l['market_avg'] : 0, $lines));
    $marketSetup = array_sum(array_map(fn($l) => $l['recurring'] ? 0 : $l['qty'] * $l['market_avg'], $lines));
    return [
        'lines' => $lines, 'missing' => $missing, 'warnings' => array_values(array_unique($warn)),
        'totals' => [
            'setup_gross' => round($setupGross, 2), 'setup' => $setup, 'setup_discount' => $setupDiscount, 'installments' => $installments, 'installment_value' => round($setup / $installments, 2),
            'monthly_gross' => round($monthlyGross, 2), 'monthly' => $monthly, 'monthly_discount' => $monthlyDiscount,
            'support' => round(array_sum(array_map(fn($l) => $l['category'] === 'suporte' ? $l['monthly'] : 0, $lines)) * (1 - $monthlyDiscount / 100), 2),
            'first_year' => round($setup + $monthly * 12, 2), 'contract_months' => $months, 'contract_total' => round($setup + $monthly * $months, 2),
            'market_setup' => round($marketSetup, 2), 'market_monthly' => round($marketMonthly, 2),
            'savings_first_year' => round(($marketSetup + $marketMonthly * 12) - ($setup + $monthly * 12), 2),
        ],
    ];
}

function quote_number(string $prefix, string $table): string
{
    $year = date('Y');
    $n = 1 + (int)db_value("SELECT COUNT(*) FROM $table WHERE number LIKE ?", [$prefix . '-' . $year . '-%']);
    do { $num = sprintf('%s-%s-%04d', $prefix, $year, $n++); } while (db_value("SELECT id FROM $table WHERE number = ?", [$num]));
    return $num;
}

function quote_save(array $in, ?array $existing, array $user): array
{
    $calc = pricing_calculate((array)($in['items'] ?? []), (float)($in['setup_discount'] ?? 0), (float)($in['monthly_discount'] ?? 0), (int)($in['installments'] ?? 1), (int)($in['contract_months'] ?? 12));
    if (!$calc['lines']) throw new AppException('Adicione ao menos um item ao orçamento.');
    $cid = !empty($in['customer_id']) ? (int)$in['customer_id'] : null;
    $lid = !empty($in['lead_id']) ? (int)$in['lead_id'] : null;
    $client = $cid ? db_value('SELECT COALESCE(trade_name, name) FROM customers WHERE id = ?', [$cid]) : ($lid ? db_value('SELECT COALESCE(company, name) FROM leads WHERE id = ?', [$lid]) : null);
    $t = $calc['totals'];
    $data = [
        'title' => mb_substr(trim((string)($in['title'] ?? '')) ?: ('Orçamento' . ($client ? ' — ' . $client : '')), 0, 200),
        'customer_id' => $cid, 'lead_id' => $lid, 'items' => json_encode($calc['lines'], JSON_UNESCAPED_UNICODE),
        'setup_discount' => $t['setup_discount'], 'monthly_discount' => $t['monthly_discount'], 'setup_total' => $t['setup'], 'monthly_total' => $t['monthly'],
        'first_year_total' => $t['first_year'], 'installments' => $t['installments'], 'contract_months' => $t['contract_months'],
        'valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['valid_until'] ?? '')) ? $in['valid_until'] : date('Y-m-d', strtotime('+15 days')),
        'start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['start_date'] ?? '')) ? $in['start_date'] : null,
        'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 4000), 'updated_at' => now(),
    ];
    if ($existing) {
        if ($existing['status'] === 'accepted') throw new AppException('Orçamento aceito não pode ser alterado. Duplique-o para criar uma nova versão.');
        if (isset($in['status']) && in_array($in['status'], ['draft', 'sent', 'rejected', 'expired'], true)) $data['status'] = $in['status'];
        db_update('quotes', (int)$existing['id'], $data);
        $id = (int)$existing['id'];
    } else {
        $id = db_insert('quotes', $data + ['number' => quote_number('ORC', 'quotes'), 'status' => 'draft', 'created_by' => $user['name'] ?? null, 'created_at' => now()]);
        if ($lid) activity_add('lead', $lid, 'event', 'Orçamento criado: ' . money($t['setup']) . ' + ' . money($t['monthly']) . '/mês', null, $user);
        if ($cid) activity_add('customer', $cid, 'event', 'Orçamento criado: ' . money($t['setup']) . ' + ' . money($t['monthly']) . '/mês', null, $user);
    }
    return quote_get($id);
}

function quote_get(int $id): array
{
    $q = db_one('SELECT q.*, c.name AS customer_name, l.name AS lead_name, l.company AS lead_company FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN leads l ON l.id = q.lead_id WHERE q.id = ?', [$id]);
    if (!$q) throw new AppException('Orçamento não encontrado.');
    $q['items'] = json_decode((string)$q['items'], true) ?: [];
    $calc = pricing_calculate(array_map(fn($l) => ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'price' => $l['price']], $q['items']), (float)$q['setup_discount'], (float)$q['monthly_discount'], (int)$q['installments'], (int)$q['contract_months']);
    $q['missing'] = $calc['missing'];
    $q['totals'] = $calc['totals'];
    return $q;
}

/**
 * Accept a quote: creates the contract, optional project, receivables for the setup
 * (installments) and — optionally — the Asaas charge of the first setup installment.
 */
function quote_accept(int $id, array $opts, array $user): array
{
    $q = quote_get($id);
    if ($q['status'] === 'accepted') throw new AppException('Este orçamento já foi aceito.');
    if ($q['missing']) throw new AppException('Para contratar é obrigatório: ' . implode(' ', $q['missing']));
    $cid = (int)($q['customer_id'] ?: 0);
    if (!$cid && $q['lead_id']) {
        $lead = db_find('leads', (int)$q['lead_id']);
        if ($lead['email'] && ($ex = db_value('SELECT id FROM customers WHERE email = ?', [mb_strtolower($lead['email'])]))) $cid = (int)$ex;
        else {
            $cid = db_insert('customers', ['name' => $lead['company'] ?: $lead['name'], 'trade_name' => $lead['company'] ? $lead['name'] : null, 'email' => $lead['email'] ? mb_strtolower($lead['email']) : null, 'phone' => $lead['phone'], 'status' => 'onboarding', 'notes' => 'Criado a partir do orçamento ' . $q['number'], 'created_at' => now(), 'updated_at' => now()]);
        }
        db_update('leads', (int)$lead['id'], ['status' => 'won']);
        db_update('quotes', $id, ['customer_id' => $cid]);
    }
    if (!$cid) throw new AppException('Vincule o orçamento a um cliente ou lead antes de contratar.');
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($opts['start_date'] ?? '')) ? $opts['start_date'] : ($q['start_date'] ?: today());
    $billingDay = max(1, min(28, (int)($opts['billing_day'] ?? 10)));
    $firstBilling = date('Y-m-', strtotime($start)) . sprintf('%02d', $billingDay);
    if ($firstBilling < $start) $firstBilling = date('Y-m-', strtotime($start . ' +1 month')) . sprintf('%02d', $billingDay);
    $support = current(array_filter($q['items'], fn($l) => $l['category'] === 'suporte'));
    $mainCode = current(array_filter($q['items'], fn($l) => $l['category'] === 'mensalidade'))['service_code'] ?? '01.05';
    $recurring = array_values(array_filter($q['items'], fn($l) => $l['recurring']));

    $result = db_transaction(function () use ($q, $id, $cid, $start, $billingDay, $firstBilling, $support, $mainCode, $recurring, $opts, $user) {
        $contractId = db_insert('contracts', [
            'number' => quote_number('CTR', 'contracts'), 'customer_id' => $cid, 'quote_id' => $id, 'title' => mb_substr(preg_replace('/^Orçamento/u', 'Contrato', $q['title']), 0, 200),
            'status' => 'active', 'items' => json_encode($recurring, JSON_UNESCAPED_UNICODE), 'setup_amount' => $q['totals']['setup'], 'monthly_amount' => $q['totals']['monthly'],
            'support_plan' => $support['name'] ?? null, 'start_date' => $start, 'end_date' => date('Y-m-d', strtotime($start . ' +' . (int)$q['contract_months'] . ' months -1 day')),
            'billing_day' => $billingDay, 'next_billing_date' => $firstBilling, 'auto_charge' => !empty($opts['auto_charge']) ? 1 : 0,
            'billing_type' => in_array($opts['billing_type'] ?? '', ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD'], true) ? $opts['billing_type'] : 'UNDEFINED',
            'service_code' => $mainCode, 'notes' => $q['notes'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $projectId = null;
        if (!empty($opts['create_project'])) {
            $hours = array_sum(array_map(fn($l) => $l['billing'] === 'hourly' ? $l['qty'] : 0, $q['items']));
            $projectId = db_insert('projects', ['customer_id' => $cid, 'name' => mb_substr(preg_replace('/^Orçamento( — )?/u', 'Implantação — ', $q['title']), 0, 160), 'description' => "Contratado pelo orçamento {$q['number']}.\n" . implode("\n", array_map(fn($l) => '• ' . $l['name'] . ($l['qty'] != 1 ? ' × ' . $l['qty'] : ''), $q['items'])),
                'project_type' => 'Implantação', 'status' => 'approved', 'priority' => 'normal', 'start_date' => $start, 'budget' => $q['totals']['setup'], 'estimated_hours' => $hours ?: null,
                'hourly_rate' => (float)setting('default_hourly_rate', '0') ?: null, 'quote_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            create_project_stages($projectId);
        }
        $cat = (int)setting('pricing_setup_category', '0') ?: null;
        $entries = [];
        $n = (int)$q['totals']['installments'];
        for ($i = 0; $i < $n && $q['totals']['setup'] > 0; $i++) {
            $amount = $i === $n - 1 ? round($q['totals']['setup'] - $q['totals']['installment_value'] * ($n - 1), 2) : $q['totals']['installment_value'];
            $due = date('Y-m-d', strtotime($start . " +$i months"));
            $entries[] = db_insert('financial_entries', ['entry_type' => 'receivable', 'description' => "Implantação {$q['number']}" . ($n > 1 ? ' (' . ($i + 1) . "/$n)" : ''), 'category_id' => $cat, 'customer_id' => $cid, 'project_id' => $projectId, 'contract_id' => $contractId,
                'amount' => $amount, 'due_date' => max($due, today()), 'competence_date' => $due, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }
        db_update('quotes', $id, ['status' => 'accepted', 'accepted_at' => now(), 'contract_id' => $contractId, 'project_id' => $projectId, 'customer_id' => $cid]);
        db_update('customers', $cid, ['status' => 'onboarding', 'updated_at' => now()]);
        activity_add('customer', $cid, 'event', "Contrato ativado a partir do orçamento {$q['number']}: implantação " . money($q['totals']['setup']) . ' + ' . money($q['totals']['monthly']) . '/mês', null, $user);
        return ['contract_id' => $contractId, 'project_id' => $projectId, 'entries' => $entries];
    });
    $result['charge'] = null;
    if (!empty($opts['charge_setup']) && $result['entries'] && function_exists('create_charge')) {
        try {
            $e = db_find('financial_entries', $result['entries'][0]);
            $result['charge'] = create_charge(['customer_id' => $cid, 'amount' => $e['amount'], 'due_date' => max($e['due_date'], today()), 'billing_type' => $opts['billing_type'] ?? 'UNDEFINED', 'description' => $e['description'], 'entry_id' => $e['id'], 'project_id' => $result['project_id']]);
            db_update('charges', (int)$result['charge']['id'], ['contract_id' => $result['contract_id']]);
        } catch (Throwable $ex) {
            $result['charge_error'] = $ex->getMessage();
        }
    }
    audit('accept', 'quote', $id, $result);
    return $result;
}

/* ============================================================== CONTRACTS */

/**
 * Create the monthly receivable (and optionally the Asaas charge) for every active contract
 * whose next billing date is within $leadDays. Idempotent per contract/month.
 */
function contracts_bill_due(int $leadDays = 10, ?int $onlyId = null): array
{
    $until = date('Y-m-d', strtotime("+$leadDays days"));
    $sql = "SELECT * FROM contracts WHERE status = 'active' AND next_billing_date IS NOT NULL AND next_billing_date <= ?" . ($onlyId ? ' AND id = ?' : '');
    $out = ['entries' => 0, 'charges' => 0, 'errors' => []];
    foreach (db_all($sql, $onlyId ? [$until, $onlyId] : [$until]) as $c) {
        if ($c['end_date'] && $c['next_billing_date'] > $c['end_date'] && setting('contracts_auto_renew', '1') !== '1') continue;
        $ref = date('m/Y', strtotime($c['next_billing_date']));
        $desc = "Mensalidade {$c['number']} — $ref";
        if (!db_value('SELECT id FROM financial_entries WHERE contract_id = ? AND description = ?', [$c['id'], $desc])) {
            $entry = db_insert('financial_entries', ['entry_type' => 'receivable', 'description' => $desc, 'category_id' => (int)setting('pricing_monthly_category', '0') ?: null, 'customer_id' => $c['customer_id'], 'contract_id' => $c['id'],
                'amount' => $c['monthly_amount'], 'due_date' => $c['next_billing_date'], 'competence_date' => $c['next_billing_date'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $out['entries']++;
            if ((int)$c['auto_charge'] && function_exists('create_charge') && AsaasClient::isConfigured()) {
                try {
                    $ch = create_charge(['customer_id' => $c['customer_id'], 'amount' => $c['monthly_amount'], 'due_date' => max($c['next_billing_date'], today()), 'billing_type' => $c['billing_type'], 'description' => $desc, 'entry_id' => $entry]);
                    db_update('charges', (int)$ch['id'], ['contract_id' => $c['id']]);
                    $out['charges']++;
                } catch (Throwable $e) {
                    $out['errors'][] = $c['number'] . ': ' . $e->getMessage();
                }
            }
        }
        db_update('contracts', (int)$c['id'], ['next_billing_date' => date('Y-m-d', strtotime($c['next_billing_date'] . ' +1 month')), 'updated_at' => now()]);
    }
    return $out;
}

function contracts_mrr(): float
{
    return (float)db_value("SELECT COALESCE(SUM(monthly_amount),0) FROM contracts WHERE status = 'active'");
}

/* =============================================================== AI CHAT */

/** Optional live web search (Brave Search API). Returns [[title, url, snippet], ...]. */
function pricing_web_search(string $query): array
{
    $key = (string)setting('search_api_key', '');
    if ($key === '') return [];
    $ch = curl_init('https://api.search.brave.com/res/v1/web/search?' . http_build_query(['q' => $query, 'country' => 'BR', 'search_lang' => 'pt-br', 'count' => 8]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Subscription-Token: ' . $key]]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !$raw) { log_line('pricing', 'web search failed', ['status' => $status]); return []; }
    $json = json_decode($raw, true);
    return array_map(fn($r) => ['title' => mb_substr(strip_tags((string)($r['title'] ?? '')), 0, 160), 'url' => (string)($r['url'] ?? ''), 'snippet' => mb_substr(strip_tags((string)($r['description'] ?? '')), 0, 400)], array_slice($json['web']['results'] ?? [], 0, 8));
}

/** Closest catalog items for a question (simple keyword scoring). */
function pricing_match(string $q, int $limit = 4): array
{
    $words = array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower(strip_accents($q))), fn($w) => strlen($w) > 2);
    $scored = [];
    foreach (pricing_catalog(true) as $p) {
        $hay = mb_strtolower(strip_accents($p['name'] . ' ' . $p['description'] . ' ' . $p['includes'] . ' ' . $p['category']));
        $score = 0;
        foreach ($words as $w) if (strpos($hay, $w) !== false) $score += strlen($w);
        if ($score) $scored[] = [$score, $p];
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($s) => $s[1], array_slice($scored, 0, $limit));
}

/**
 * Answer "quanto cobrar por X?" with minimum / average / maximum for Marília and the suggested
 * Integra price (average − 10%). Uses the catalog references, optional live web search and the AI.
 */
function pricing_ask(string $question, array $history = []): array
{
    $question = trim(mb_substr($question, 0, 800));
    if ($question === '') throw new AppException('Escreva a pergunta.');
    $matches = pricing_match($question);
    $web = pricing_web_search($question . ' preço valor Marília SP') ?: [];
    if ($web && count($web) < 4) $web = array_merge($web, pricing_web_search($question . ' preço médio Brasil 2026'));
    $catalogText = implode("\n", array_map(fn($p) => "- {$p['name']} ({$p['unit']}, " . (PRICE_BILLING[$p['billing']] ?? $p['billing']) . "): mercado Marília mín " . money($p['market_min']) . ' / média ' . money($p['market_avg']) . ' / máx ' . money($p['market_max']) . '; preço Integra ' . money($p['price']), pricing_catalog(true)));
    $webText = $web ? implode("\n", array_map(fn($r, $i) => '[' . ($i + 1) . "] {$r['title']} — {$r['snippet']} ({$r['url']})", $web, array_keys($web))) : '(busca na web não configurada: use a tabela de referência e seu conhecimento do mercado brasileiro, deixando isso claro)';

    if (!ai_feature('admin')) {
        $m = $matches[0] ?? null;
        if (!$m) return ['answer' => "Não encontrei esse serviço na tabela de referência. Configure a IA (Cloudflare) em Configurações para estimativas de serviços fora do catálogo.", 'estimate' => null, 'sources' => [], 'ai' => false, 'web' => false];
        return ['answer' => "**{$m['name']}** ({$m['unit']})\n\nReferência de mercado em Marília: mínimo " . money($m['market_min']) . ', média ' . money($m['market_avg']) . ', máximo ' . money($m['market_max']) . ".\n\nPreço sugerido Integra Code (média −10%): **" . money($m['price']) . "**.\n\n" . $m['market_source'],
            'estimate' => ['service' => $m['name'], 'unit' => $m['unit'], 'billing' => $m['billing'], 'min' => (float)$m['market_min'], 'avg' => (float)$m['market_avg'], 'max' => (float)$m['market_max'], 'suggested' => (float)$m['price'], 'confidence' => 'média', 'item_id' => (int)$m['id']], 'sources' => [], 'ai' => false, 'web' => false];
    }
    $system = "Você é o consultor de precificação da Integra Code (software house em Marília-SP: sistemas sob medida, Integra SYS, sites, apps, suporte). "
        . "Responda em português do Brasil. Para o serviço perguntado, estime valores de MERCADO praticados em Marília-SP (cidade do interior, ~240 mil habitantes, preços em geral abaixo de São Paulo capital): mínimo, média e máximo. "
        . "O preço sugerido da Integra Code é sempre a média menos 10%. Use primeiro os resultados da web (quando houver) e a tabela de referência; se estimar por conhecimento próprio, diga isso e reduza a confiança. Não invente fontes. "
        . "Responda SOMENTE com JSON: {\"resposta\": \"texto em markdown curto: faixa de preço, o que costuma estar incluso, fatores que mudam o preço e recomendação\", \"servico\": \"nome\", \"unidade\": \"projeto|mês|hora|usuário...\", \"cobranca\": \"one_time|monthly|hourly\", \"minimo\": número, \"media\": número, \"maximo\": número, \"confianca\": \"baixa|média|alta\", \"fontes\": [\"url\"]}.\n\n"
        . "Tabela de referência atual (Marília):\n$catalogText";
    $msgs = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($history, -6) as $h) {
        if (in_array($h['role'] ?? '', ['user', 'assistant'], true) && is_string($h['content'] ?? null)) $msgs[] = ['role' => $h['role'], 'content' => mb_substr($h['content'], 0, 1500)];
    }
    $msgs[] = ['role' => 'user', 'content' => "Pergunta: $question\n\nResultados da busca na web:\n$webText"];
    $out = ai_chat($msgs, 'pricing_chat', 900, 0.3);
    $a = strpos($out, '{');
    $b = strrpos($out, '}');
    $json = $a !== false && $b !== false ? json_decode(substr($out, $a, $b - $a + 1), true) : null;
    if (!is_array($json)) return ['answer' => $out, 'estimate' => null, 'sources' => array_column($web, 'url'), 'ai' => true, 'web' => (bool)$web];
    $min = max(0, (float)($json['minimo'] ?? 0));
    $avg = max(0, (float)($json['media'] ?? 0));
    $max = max(0, (float)($json['maximo'] ?? 0));
    $vals = array_filter([$min, $avg, $max]);
    if ($vals) { sort($vals); $min = $min ?: $vals[0]; $max = $max ?: end($vals); $avg = $avg ?: array_sum($vals) / count($vals); }
    if ($min > $avg) $min = $avg;
    if ($max < $avg) $max = $avg;
    $billing = in_array($json['cobranca'] ?? '', ['one_time', 'monthly', 'hourly', 'yearly'], true) ? $json['cobranca'] : 'one_time';
    $allowed = array_column($web, 'url');
    $sources = array_values(array_filter((array)($json['fontes'] ?? []), fn($u) => is_string($u) && in_array($u, $allowed, true)));
    return [
        'answer' => (string)($json['resposta'] ?? ''),
        'estimate' => $avg > 0 ? ['service' => mb_substr((string)($json['servico'] ?? $question), 0, 160), 'unit' => mb_substr((string)($json['unidade'] ?? 'projeto'), 0, 30), 'billing' => $billing,
            'min' => round($min, 2), 'avg' => round($avg, 2), 'max' => round($max, 2), 'suggested' => pricing_round($avg * MARKET_FACTOR), 'confidence' => (string)($json['confianca'] ?? 'média'), 'item_id' => isset($matches[0]) ? (int)$matches[0]['id'] : null] : null,
        'sources' => $sources ?: array_slice($allowed, 0, 5), 'ai' => true, 'web' => (bool)$web,
    ];
}
