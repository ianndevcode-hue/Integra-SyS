<?php
declare(strict_types=1);

/**
 * Reports: a catalog of ready-made management reports, a whitelisted report builder
 * (dataset × dimension × metric) and executive insights (AI with a rule-based fallback).
 *
 * Every report returns the same shape so the admin renders them generically:
 *   ['title','subtitle','period'=>[start,end],'kpis'=>[...],'charts'=>[...],'tables'=>[...],'highlights'=>[...]]
 * KPI:   ['label','value','format'=>money|int|pct|hours|days|decimal|text,'delta'=>?float (%),'good'=>up|down,'hint']
 * Chart: ['id','title','type'=>bar|hbar|line|doughnut|stacked,'labels'=>[],'datasets'=>[['label','data','color']],'format']
 * Table: ['title','columns'=>[['key','label','format','align']],'rows'=>[],'footer'=>?row]
 */

const REPORT_COLORS = ['#0066FE', '#00CF81', '#6D45F6', '#2FD4EE', '#F59E0B', '#F43F5E', '#94A3B8', '#22C55E', '#A78BFA', '#FB7185'];

function report_catalog(): array
{
    return [
        ['financial_overview', 'Financeiro executivo', 'Receita, despesas, lucro, margem, caixa e fôlego financeiro com comparação ao período anterior.', 'pie', 'finance', 'Financeiro'],
        ['dre', 'DRE comparativa', 'Demonstrativo de resultado por grupo e categoria, período atual × anterior, com variação.', 'flow', 'finance', 'Financeiro'],
        ['cashflow_forecast', 'Fluxo de caixa projetado', 'Saldo previsto dia a dia para as próximas semanas, com o ponto de menor caixa.', 'trendUp', 'finance', 'Financeiro'],
        ['budget_vs_actual', 'Orçamento × realizado', 'Quanto foi planejado e quanto foi gasto/recebido em cada categoria, mês a mês.', 'target', 'finance', 'Financeiro'],
        ['revenue_by_customer', 'Receita por cliente (curva ABC)', 'Ranking de faturamento, participação e classificação A/B/C da carteira.', 'users', 'finance', 'Financeiro'],
        ['expenses_breakdown', 'Despesas por categoria e fornecedor', 'Para onde vai o dinheiro: categorias, fornecedores e tendência mensal.', 'trendDown', 'finance', 'Financeiro'],
        ['receivables_aging', 'Contas a receber (aging)', 'Títulos a vencer e vencidos por faixa de atraso e por cliente.', 'clock', 'finance', 'Financeiro'],
        ['payables_aging', 'Contas a pagar (aging)', 'Compromissos a vencer e vencidos por faixa, categoria e fornecedor.', 'wallet', 'finance', 'Financeiro'],
        ['delinquency', 'Inadimplência', 'Clientes com pagamentos atrasados, dias em atraso e taxa de recuperação.', 'alert', 'finance', 'Financeiro'],
        ['project_profitability', 'Rentabilidade por projeto', 'Contrato, recebido, custos diretos, horas e margem de cada projeto.', 'money', 'projects', 'Projetos'],
        ['projects_portfolio', 'Carteira de projetos', 'Situação, saúde, atrasos, progresso e aprovações pendentes.', 'kanban', 'projects', 'Projetos'],
        ['timesheet', 'Horas trabalhadas', 'Horas por projeto, por pessoa, faturáveis e valor estimado.', 'clock', 'projects', 'Projetos'],
        ['sales_funnel', 'Funil comercial', 'Leads por etapa, conversão, origem, valor em negociação e motivos de perda.', 'target', 'leads', 'Comercial'],
        ['customers_portfolio', 'Carteira de clientes', 'Base ativa, novos clientes, situação, segmentos e clientes sem movimento.', 'users', 'customers', 'Comercial'],
        ['support_performance', 'Suporte e SLA', 'Volume, SLA cumprido, 1ª resposta, resolução, satisfação e produtividade.', 'ticket', 'tickets', 'Operação'],
        ['recurring_revenue', 'Receita recorrente (MRR)', 'MRR, ARR, novos contratos, cancelamentos (churn), evolução mensal e mensalidades por plano de suporte.', 'refresh', 'finance', 'Comercial'],
        ['quotes_pipeline', 'Orçamentos e conversão', 'Orçamentos criados, aceitos e perdidos, ticket médio, descontos concedidos e itens mais vendidos.', 'file', 'customers', 'Comercial'],
        ['price_table', 'Tabela de preços × mercado', 'Preços Integra comparados ao mínimo, média e máximo de Marília, por categoria.', 'tag', 'customers', 'Comercial'],
        ['nfse_report', 'Notas fiscais e tributos', 'NFS-e por mês e tomador com ISS, PIS, COFINS, CSLL, IRRF, INSS, retenções e valor líquido.', 'file', 'finance', 'Fiscal'],
    ];
}

/* ---------------------------------------------------------------- helpers */

function rpt_period(array $p): array
{
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['start'] ?? '')) ? $p['start'] : date('Y-01-01');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['end'] ?? '')) ? $p['end'] : date('Y-m-d');
    if ($end < $start) [$start, $end] = [$end, $start];
    $days = (int)round((strtotime($end) - strtotime($start)) / 86400) + 1;
    $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
    $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
    return [$start, $end, $prevStart, $prevEnd];
}

function rpt_months(string $start, string $end): array
{
    $out = [];
    $m = substr($start, 0, 7);
    $last = substr($end, 0, 7);
    for ($i = 0; $m <= $last && $i < 60; $i++) {
        $out[] = $m;
        $m = date('Y-m', strtotime($m . '-01 +1 month'));
    }
    return $out;
}

function rpt_month_label(string $ym): string
{
    $n = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];
    return ($n[substr($ym, 5, 2)] ?? substr($ym, 5, 2)) . '/' . substr($ym, 2, 2);
}

function rpt_delta(float $now, float $before): ?float
{
    if (abs($before) < 0.005) return abs($now) < 0.005 ? 0.0 : null;
    return round(($now - $before) / abs($before) * 100, 1);
}

function rpt_kpi(string $label, $value, string $format = 'money', ?float $delta = null, string $good = 'up', string $hint = ''): array
{
    return ['label' => $label, 'value' => $value, 'format' => $format, 'delta' => $delta, 'good' => $good, 'hint' => $hint];
}

function rpt_chart(string $id, string $title, string $type, array $labels, array $datasets, string $format = 'money'): array
{
    foreach ($datasets as $i => &$d) {
        $d['color'] = $d['color'] ?? REPORT_COLORS[$i % count(REPORT_COLORS)];
        $d['data'] = array_map(fn($v) => round((float)$v, 2), array_values($d['data']));
    }
    unset($d);
    return ['id' => $id, 'title' => $title, 'type' => $type, 'labels' => array_values($labels), 'datasets' => $datasets, 'format' => $format];
}

function rpt_col(string $key, string $label, string $format = 'text', string $align = ''): array
{
    return ['key' => $key, 'label' => $label, 'format' => $format, 'align' => $align ?: (in_array($format, ['money', 'int', 'pct', 'hours', 'days', 'decimal'], true) ? 'right' : 'left')];
}

/** Days between two date expressions (portable). */
function sql_days(string $from, string $to): string
{
    return db_driver() === 'sqlite' ? "(julianday($to) - julianday($from))" : "DATEDIFF($to, $from)";
}

function sql_hours(string $from, string $to): string
{
    return db_driver() === 'sqlite' ? "((julianday($to) - julianday($from)) * 24)" : "(TIMESTAMPDIFF(MINUTE, $from, $to) / 60)";
}

/** Paid receivables/payables by month (cash basis, same rule as the DRE). */
function rpt_cash_by_month(string $start, string $end): array
{
    $rows = db_all("SELECT SUBSTR(e.paid_at, 1, 7) AS m, e.entry_type, COALESCE(c.dre_group, CASE WHEN e.entry_type='receivable' THEN 'other_income' ELSE 'expense' END) AS grp, SUM(e.paid_amount) AS total
        FROM financial_entries e LEFT JOIN categories c ON c.id = e.category_id
        WHERE e.status = 'paid' AND e.paid_at BETWEEN ? AND ? GROUP BY SUBSTR(e.paid_at, 1, 7), e.entry_type, grp", [$start, $end]);
    $out = [];
    foreach (rpt_months($start, $end) as $m) $out[$m] = ['revenue' => 0.0, 'expenses' => 0.0, 'investment' => 0.0, 'distribution' => 0.0];
    foreach ($rows as $r) {
        if (!isset($out[$r['m']])) continue;
        $v = (float)$r['total'];
        if (in_array($r['grp'], ['revenue', 'other_income'], true)) $out[$r['m']]['revenue'] += $v;
        elseif ($r['grp'] === 'investment') $out[$r['m']]['investment'] += $v;
        elseif ($r['grp'] === 'distribution') $out[$r['m']]['distribution'] += $v;
        else $out[$r['m']]['expenses'] += $v;
    }
    foreach ($out as &$o) $o['profit'] = $o['revenue'] - $o['expenses'];
    unset($o);
    return $out;
}

/* ---------------------------------------------------------------- runner */

function report_run(string $key, array $p): array
{
    $cat = array_column(report_catalog(), null, 0);
    if (!isset($cat[$key])) throw new AppException('Relatório não encontrado.');
    $fn = 'rpt_' . $key;
    [$start, $end] = rpt_period($p);
    $res = $fn($p);
    $res += ['kpis' => [], 'charts' => [], 'tables' => [], 'highlights' => []];
    $res['kpis'] = array_values(array_filter($res['kpis']));
    $res['key'] = $key;
    $res['title'] = $res['title'] ?? $cat[$key][1];
    $res['description'] = $cat[$key][2];
    $res['period'] = $res['period'] ?? [$start, $end];
    $res['subtitle'] = $res['subtitle'] ?? ('Período: ' . date('d/m/Y', strtotime($res['period'][0])) . ' a ' . date('d/m/Y', strtotime($res['period'][1])));
    $res['generated_at'] = now();
    return $res;
}

/* ================================================================ FINANCE */

function rpt_financial_overview(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $now = pnl($s, $e);
    $prev = pnl($ps, $pe);
    $rev = $now['groups']['revenue'] + $now['groups']['other_income'];
    $revPrev = $prev['groups']['revenue'] + $prev['groups']['other_income'];
    $exp = $now['groups']['tax'] + $now['groups']['cost'] + $now['groups']['expense'];
    $expPrev = $prev['groups']['tax'] + $prev['groups']['cost'] + $prev['groups']['expense'];
    $cash = cash_realized_until(date('Y-m-d', strtotime('+1 day')));
    $avgBurn = (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries WHERE entry_type='payable' AND status='paid' AND paid_at >= ?", [date('Y-m-d', strtotime('-90 days'))]) / 3;
    $recOpen = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='open'");
    $recLate = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='open' AND due_date < ?", [today()]);
    $payOpen = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='payable' AND status='open'");
    $payingCustomers = (int)db_value("SELECT COUNT(DISTINCT customer_id) FROM financial_entries WHERE entry_type='receivable' AND status='paid' AND customer_id IS NOT NULL AND paid_at BETWEEN ? AND ?", [$s, $e]);
    $months = rpt_cash_by_month($s, $e);
    $goal = (float)setting('goal_revenue_month', '0');

    $kpis = [
        rpt_kpi('Receita realizada', round($rev, 2), 'money', rpt_delta($rev, $revPrev)),
        rpt_kpi('Despesas (custos + impostos + operacionais)', round($exp, 2), 'money', rpt_delta($exp, $expPrev), 'down'),
        rpt_kpi('Lucro líquido', $now['net_profit'], 'money', rpt_delta($now['net_profit'], $prev['net_profit'])),
        rpt_kpi('Margem líquida', $now['net_margin'], 'pct', $prev['net_margin'] ? round($now['net_margin'] - $prev['net_margin'], 1) : null, 'up', 'variação em pontos percentuais'),
        rpt_kpi('Saldo em caixa hoje', round($cash, 2), 'money'),
        rpt_kpi('Fôlego de caixa', $avgBurn > 0 ? round($cash / $avgBurn, 1) : null, 'decimal', null, 'up', 'meses de despesas cobertos pelo caixa (média 90 dias)'),
        rpt_kpi('A receber em aberto', round($recOpen, 2), 'money', null, 'up', $recLate ? 'vencido: ' . money($recLate) : 'nada vencido'),
        rpt_kpi('Ticket médio por cliente', $payingCustomers ? round($now['groups']['revenue'] / $payingCustomers, 2) : 0, 'money', null, 'up', $payingCustomers . ' cliente(s) pagantes no período'),
    ];
    if ($goal > 0) {
        $monthRev = pnl(date('Y-m-01'), date('Y-m-t'));
        $kpis[] = rpt_kpi('Meta de receita do mês', round(($monthRev['groups']['revenue'] + $monthRev['groups']['other_income']) / $goal * 100, 1), 'pct', null, 'up', 'meta: ' . money($goal));
    }
    $expGroups = ['tax' => 'Impostos', 'cost' => 'Custos diretos', 'expense' => 'Despesas operacionais', 'investment' => 'Investimentos', 'distribution' => 'Distribuição de lucros'];
    $hl = [];
    if ($rev > 0) $hl[] = 'A margem líquida do período foi de ' . number_format($now['net_margin'], 1, ',', '.') . '%.';
    if ($avgBurn > 0) $hl[] = 'O caixa atual cobre cerca de ' . number_format($cash / $avgBurn, 1, ',', '.') . ' meses de despesas.';
    if ($recLate > 0) $hl[] = 'Há ' . money($recLate) . ' em recebíveis vencidos que merecem cobrança ativa.';
    return [
        'kpis' => $kpis,
        'charts' => [
            rpt_chart('monthly', 'Receitas × despesas × lucro por mês', 'bar', array_map('rpt_month_label', array_keys($months)), [
                ['label' => 'Receitas', 'data' => array_column($months, 'revenue'), 'color' => '#00CF81'],
                ['label' => 'Despesas', 'data' => array_column($months, 'expenses'), 'color' => '#F43F5E'],
                ['label' => 'Lucro', 'data' => array_column($months, 'profit'), 'color' => '#0066FE', 'type' => 'line'],
            ]),
            rpt_chart('exp_groups', 'Composição das saídas', 'doughnut', array_values($expGroups), [['label' => 'Saídas', 'data' => array_map(fn($g) => $now['groups'][$g], array_keys($expGroups))]]),
        ],
        'tables' => [[
            'title' => 'Resultado mês a mês',
            'columns' => [rpt_col('month', 'Mês'), rpt_col('revenue', 'Receitas', 'money'), rpt_col('expenses', 'Despesas', 'money'), rpt_col('profit', 'Resultado', 'money'), rpt_col('margin', 'Margem', 'pct')],
            'rows' => array_map(fn($m, $v) => ['month' => rpt_month_label($m), 'revenue' => round($v['revenue'], 2), 'expenses' => round($v['expenses'], 2), 'profit' => round($v['profit'], 2), 'margin' => $v['revenue'] > 0 ? round($v['profit'] / $v['revenue'] * 100, 1) : null], array_keys($months), $months),
            'footer' => ['month' => 'Total', 'revenue' => round($rev, 2), 'expenses' => round($exp, 2), 'profit' => round($rev - $exp, 2), 'margin' => $rev > 0 ? round(($rev - $exp) / $rev * 100, 1) : null],
        ]],
        'highlights' => $hl,
        'extra' => ['payable_open' => $payOpen],
    ];
}

function rpt_dre(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $now = pnl($s, $e);
    $prev = pnl($ps, $pe);
    $lines = [
        ['Receita bruta', $now['groups']['revenue'], $prev['groups']['revenue']],
        ['(−) Impostos', -$now['groups']['tax'], -$prev['groups']['tax']],
        ['= Receita líquida', $now['net_revenue'], $prev['net_revenue']],
        ['(−) Custos diretos', -$now['groups']['cost'], -$prev['groups']['cost']],
        ['= Lucro bruto', $now['gross_profit'], $prev['gross_profit']],
        ['(−) Despesas operacionais', -$now['groups']['expense'], -$prev['groups']['expense']],
        ['= Resultado operacional', $now['operating_profit'], $prev['operating_profit']],
        ['(+) Outras receitas', $now['groups']['other_income'], $prev['groups']['other_income']],
        ['= Lucro líquido', $now['net_profit'], $prev['net_profit']],
        ['Investimentos', -$now['groups']['investment'], -$prev['groups']['investment']],
        ['Distribuição aos sócios', -$now['groups']['distribution'], -$prev['groups']['distribution']],
    ];
    $prevCat = [];
    foreach ($prev['by_category'] as $c) $prevCat[$c['type'] . '|' . $c['category']] = $c['total'];
    $groupNames = ['revenue' => 'Receita', 'other_income' => 'Outras receitas', 'tax' => 'Impostos', 'cost' => 'Custos', 'expense' => 'Despesas', 'investment' => 'Investimentos', 'distribution' => 'Distribuição'];
    return [
        'kpis' => [
            rpt_kpi('Receita líquida', $now['net_revenue'], 'money', rpt_delta($now['net_revenue'], $prev['net_revenue'])),
            rpt_kpi('Lucro bruto', $now['gross_profit'], 'money', rpt_delta($now['gross_profit'], $prev['gross_profit'])),
            rpt_kpi('Margem bruta', $now['gross_margin'], 'pct', round($now['gross_margin'] - $prev['gross_margin'], 1)),
            rpt_kpi('Lucro líquido', $now['net_profit'], 'money', rpt_delta($now['net_profit'], $prev['net_profit'])),
        ],
        'charts' => [rpt_chart('waterfall', 'Da receita ao lucro', 'bar', ['Receita', 'Impostos', 'Custos', 'Despesas', 'Outras receitas', 'Lucro líquido'], [
            ['label' => 'Período atual', 'data' => [$now['groups']['revenue'], -$now['groups']['tax'], -$now['groups']['cost'], -$now['groups']['expense'], $now['groups']['other_income'], $now['net_profit']], 'color' => '#0066FE'],
            ['label' => 'Período anterior', 'data' => [$prev['groups']['revenue'], -$prev['groups']['tax'], -$prev['groups']['cost'], -$prev['groups']['expense'], $prev['groups']['other_income'], $prev['net_profit']], 'color' => '#94A3B8'],
        ])],
        'tables' => [
            ['title' => 'DRE — atual × anterior (' . date('d/m/Y', strtotime($ps)) . ' a ' . date('d/m/Y', strtotime($pe)) . ')',
                'columns' => [rpt_col('line', 'Linha'), rpt_col('now', 'Atual', 'money'), rpt_col('prev', 'Anterior', 'money'), rpt_col('delta', 'Variação', 'pct'), rpt_col('share', '% receita', 'pct')],
                'rows' => array_map(fn($l) => ['line' => $l[0], 'now' => round($l[1], 2), 'prev' => round($l[2], 2), 'delta' => rpt_delta($l[1], $l[2]), 'share' => $now['groups']['revenue'] > 0 ? round($l[1] / $now['groups']['revenue'] * 100, 1) : null, '_strong' => strpos($l[0], '=') === 0], $lines)],
            ['title' => 'Por categoria',
                'columns' => [rpt_col('group', 'Grupo'), rpt_col('category', 'Categoria'), rpt_col('now', 'Atual', 'money'), rpt_col('prev', 'Anterior', 'money'), rpt_col('delta', 'Variação', 'pct')],
                'rows' => array_map(fn($c) => ['group' => $groupNames[$c['group']] ?? $c['group'], 'category' => $c['category'], 'now' => $c['total'], 'prev' => $prevCat[$c['type'] . '|' . $c['category']] ?? 0, 'delta' => rpt_delta($c['total'], (float)($prevCat[$c['type'] . '|' . $c['category']] ?? 0))], $now['by_category'])],
        ],
    ];
}

function rpt_cashflow_forecast(array $p): array
{
    $days = max(14, min(180, (int)($p['days'] ?? 90)));
    $f = cashflow_projection($days);
    $min = null;
    foreach ($f['series'] as $d) if ($min === null || $d['balance'] < $min['balance']) $min = $d;
    $weeks = [];
    foreach ($f['series'] as $d) {
        $w = date('o-\SW', strtotime($d['date']));
        $weeks[$w] = $weeks[$w] ?? ['start' => $d['date'], 'in' => 0, 'out' => 0, 'balance' => 0];
        $weeks[$w]['in'] += $d['in'];
        $weeks[$w]['out'] += $d['out'];
        $weeks[$w]['balance'] = $d['balance'];
    }
    $in = array_sum(array_column($f['series'], 'in'));
    $out = array_sum(array_column($f['series'], 'out'));
    $hl = [];
    if ($min && $min['balance'] < 0) $hl[] = 'Atenção: o caixa fica negativo em ' . date('d/m/Y', strtotime($min['date'])) . ' (' . money($min['balance']) . '). Antecipe recebimentos ou renegocie pagamentos.';
    elseif ($min) $hl[] = 'O menor saldo previsto é ' . money($min['balance']) . ' em ' . date('d/m/Y', strtotime($min['date'])) . '.';
    if ($f['overdue_receivable'] > 0) $hl[] = money($f['overdue_receivable']) . ' em recebíveis vencidos não entram na projeção até serem cobrados.';
    return [
        'period' => [today(), date('Y-m-d', strtotime("+$days days"))],
        'subtitle' => "Próximos $days dias, a partir do saldo realizado hoje",
        'kpis' => [
            rpt_kpi('Saldo hoje', $f['current_balance']),
            rpt_kpi('Entradas previstas', round($in, 2)),
            rpt_kpi('Saídas previstas', round($out, 2), 'money', null, 'down'),
            rpt_kpi('Saldo ao final', round(end($f['series'])['balance'], 2)),
            rpt_kpi('Menor saldo no período', $min ? $min['balance'] : 0, 'money', null, 'up', $min ? 'em ' . date('d/m/Y', strtotime($min['date'])) : ''),
            rpt_kpi('Recebíveis vencidos (fora da projeção)', $f['overdue_receivable'], 'money', null, 'down'),
        ],
        'charts' => [
            rpt_chart('balance', 'Saldo projetado', 'line', array_map(fn($d) => date('d/m', strtotime($d['date'])), $f['series']), [['label' => 'Saldo', 'data' => array_column($f['series'], 'balance'), 'color' => '#0066FE']]),
            rpt_chart('weekly', 'Entradas e saídas por semana', 'bar', array_map(fn($w) => date('d/m', strtotime($w['start'])), array_values($weeks)), [
                ['label' => 'Entradas', 'data' => array_column(array_values($weeks), 'in'), 'color' => '#00CF81'],
                ['label' => 'Saídas', 'data' => array_column(array_values($weeks), 'out'), 'color' => '#F43F5E'],
            ]),
        ],
        'tables' => [[
            'title' => 'Semana a semana',
            'columns' => [rpt_col('week', 'Semana de'), rpt_col('in', 'Entradas', 'money'), rpt_col('out', 'Saídas', 'money'), rpt_col('net', 'Líquido', 'money'), rpt_col('balance', 'Saldo ao fim', 'money')],
            'rows' => array_map(fn($w) => ['week' => date('d/m/Y', strtotime($w['start'])), 'in' => round($w['in'], 2), 'out' => round($w['out'], 2), 'net' => round($w['in'] - $w['out'], 2), 'balance' => round($w['balance'], 2)], array_values($weeks)),
        ]],
        'highlights' => $hl,
    ];
}

function rpt_budget_vs_actual(array $p): array
{
    [$s, $e] = rpt_period($p);
    $months = rpt_months($s, $e);
    $budget = db_all('SELECT b.category_id, b.month, b.amount, c.name, c.entry_type FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.month BETWEEN ? AND ?', [$months[0], end($months)]);
    $actual = db_all("SELECT category_id, SUBSTR(paid_at, 1, 7) AS m, SUM(paid_amount) AS total FROM financial_entries WHERE status = 'paid' AND paid_at BETWEEN ? AND ? AND category_id IS NOT NULL GROUP BY category_id, SUBSTR(paid_at, 1, 7)", [$s, $e]);
    $cats = [];
    foreach (db_all('SELECT id, name, entry_type FROM categories ORDER BY entry_type DESC, name') as $c) $cats[$c['id']] = ['name' => $c['name'], 'type' => $c['entry_type'], 'budget' => 0.0, 'actual' => 0.0];
    $byMonth = array_fill_keys($months, ['budget_in' => 0.0, 'actual_in' => 0.0, 'budget_out' => 0.0, 'actual_out' => 0.0]);
    foreach ($budget as $b) {
        if (!isset($cats[$b['category_id']])) continue;
        $cats[$b['category_id']]['budget'] += (float)$b['amount'];
        if (isset($byMonth[$b['month']])) $byMonth[$b['month']][$b['entry_type'] === 'receivable' ? 'budget_in' : 'budget_out'] += (float)$b['amount'];
    }
    foreach ($actual as $a) {
        if (!isset($cats[$a['category_id']])) continue;
        $cats[$a['category_id']]['actual'] += (float)$a['total'];
        if (isset($byMonth[$a['m']])) $byMonth[$a['m']][$cats[$a['category_id']]['type'] === 'receivable' ? 'actual_in' : 'actual_out'] += (float)$a['total'];
    }
    $rows = [];
    foreach ($cats as $c) {
        if ($c['budget'] == 0 && $c['actual'] == 0) continue;
        $diff = $c['actual'] - $c['budget'];
        $rows[] = ['category' => $c['name'], 'type' => $c['type'] === 'receivable' ? 'Receita' : 'Despesa', 'budget' => round($c['budget'], 2), 'actual' => round($c['actual'], 2), 'diff' => round($diff, 2), 'pct' => $c['budget'] > 0 ? round($c['actual'] / $c['budget'] * 100, 1) : null,
            '_bad' => $c['type'] === 'payable' ? $c['actual'] > $c['budget'] * 1.05 && $c['budget'] > 0 : $c['actual'] < $c['budget'] * 0.95];
    }
    $bOut = array_sum(array_column($byMonth, 'budget_out'));
    $aOut = array_sum(array_column($byMonth, 'actual_out'));
    $bIn = array_sum(array_column($byMonth, 'budget_in'));
    $aIn = array_sum(array_column($byMonth, 'actual_in'));
    $over = array_filter($rows, fn($r) => $r['_bad'] && $r['type'] === 'Despesa');
    return [
        'kpis' => [
            rpt_kpi('Receita orçada', round($bIn, 2)), rpt_kpi('Receita realizada', round($aIn, 2), 'money', $bIn > 0 ? round(($aIn - $bIn) / $bIn * 100, 1) : null),
            rpt_kpi('Despesa orçada', round($bOut, 2), 'money', null, 'down'), rpt_kpi('Despesa realizada', round($aOut, 2), 'money', $bOut > 0 ? round(($aOut - $bOut) / $bOut * 100, 1) : null, 'down'),
        ],
        'charts' => [rpt_chart('bva', 'Despesas: orçado × realizado', 'bar', array_map('rpt_month_label', $months), [
            ['label' => 'Orçado', 'data' => array_column($byMonth, 'budget_out'), 'color' => '#94A3B8'],
            ['label' => 'Realizado', 'data' => array_column($byMonth, 'actual_out'), 'color' => '#F43F5E'],
        ]), rpt_chart('bva_in', 'Receitas: orçado × realizado', 'bar', array_map('rpt_month_label', $months), [
            ['label' => 'Orçado', 'data' => array_column($byMonth, 'budget_in'), 'color' => '#94A3B8'],
            ['label' => 'Realizado', 'data' => array_column($byMonth, 'actual_in'), 'color' => '#00CF81'],
        ])],
        'tables' => [['title' => 'Por categoria', 'columns' => [rpt_col('category', 'Categoria'), rpt_col('type', 'Tipo'), rpt_col('budget', 'Orçado', 'money'), rpt_col('actual', 'Realizado', 'money'), rpt_col('diff', 'Diferença', 'money'), rpt_col('pct', '% do orçado', 'pct')], 'rows' => $rows]],
        'highlights' => $budget ? array_values(array_map(fn($r) => "{$r['category']} passou do orçamento em " . money($r['diff']) . '.', array_slice($over, 0, 4))) : ['Nenhum orçamento cadastrado para o período. Cadastre em Financeiro → Orçamento.'],
    ];
}

function rpt_revenue_by_customer(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $rows = db_all("SELECT COALESCE(cu.name, 'Sem cliente vinculado') AS customer, e.customer_id, SUM(e.paid_amount) AS total, COUNT(*) AS n
        FROM financial_entries e LEFT JOIN customers cu ON cu.id = e.customer_id
        WHERE e.entry_type = 'receivable' AND e.status = 'paid' AND e.paid_at BETWEEN ? AND ? GROUP BY e.customer_id, cu.name ORDER BY total DESC", [$s, $e]);
    $prev = [];
    foreach (db_all("SELECT customer_id, SUM(paid_amount) AS total FROM financial_entries WHERE entry_type = 'receivable' AND status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY customer_id", [$ps, $pe]) as $r) $prev[(int)$r['customer_id']] = (float)$r['total'];
    $total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
    $cum = 0;
    $out = [];
    $counts = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($rows as $i => $r) {
        $share = $total > 0 ? (float)$r['total'] / $total * 100 : 0;
        $cum += $share;
        $class = $cum - $share < 80 ? 'A' : ($cum - $share < 95 ? 'B' : 'C');
        $counts[$class]++;
        $out[] = ['rank' => $i + 1, 'customer' => $r['customer'], 'total' => round((float)$r['total'], 2), 'n' => (int)$r['n'], 'share' => round($share, 1), 'cum' => round($cum, 1), 'class' => $class, 'delta' => rpt_delta((float)$r['total'], $prev[(int)$r['customer_id']] ?? 0)];
    }
    $top = array_slice($out, 0, 10);
    return [
        'kpis' => [
            rpt_kpi('Faturamento recebido', round($total, 2), 'money', rpt_delta($total, array_sum($prev))),
            rpt_kpi('Clientes pagantes', count($rows), 'int'),
            rpt_kpi('Concentração no maior cliente', $out ? $out[0]['share'] : 0, 'pct', null, 'down'),
            rpt_kpi('Clientes classe A (80% da receita)', $counts['A'], 'int'),
        ],
        'charts' => [
            rpt_chart('top', 'Top 10 clientes por receita', 'hbar', array_column($top, 'customer'), [['label' => 'Receita', 'data' => array_column($top, 'total'), 'color' => '#0066FE']]),
            rpt_chart('abc', 'Curva ABC (receita acumulada)', 'line', array_column($out, 'rank'), [['label' => '% acumulado', 'data' => array_column($out, 'cum'), 'color' => '#00CF81']], 'pct'),
        ],
        'tables' => [['title' => 'Ranking de clientes', 'columns' => [rpt_col('rank', '#', 'int'), rpt_col('customer', 'Cliente'), rpt_col('total', 'Receita', 'money'), rpt_col('n', 'Pagamentos', 'int'), rpt_col('share', 'Participação', 'pct'), rpt_col('cum', 'Acumulado', 'pct'), rpt_col('class', 'Classe'), rpt_col('delta', 'Var. vs anterior', 'pct')], 'rows' => $out]],
        'highlights' => $out && $out[0]['share'] > 35 ? ['Dependência alta: ' . $out[0]['customer'] . ' representa ' . number_format($out[0]['share'], 1, ',', '.') . '% da receita. Diversificar a carteira reduz risco.'] : [],
    ];
}

function rpt_expenses_breakdown(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $byCat = db_all("SELECT COALESCE(c.name, 'Sem categoria') AS category, COALESCE(c.color, '#94a3b8') AS color, SUM(e.paid_amount) AS total, COUNT(*) AS n
        FROM financial_entries e LEFT JOIN categories c ON c.id = e.category_id
        WHERE e.entry_type = 'payable' AND e.status = 'paid' AND e.paid_at BETWEEN ? AND ? GROUP BY c.name, c.color ORDER BY total DESC", [$s, $e]);
    $bySup = db_all("SELECT COALESCE(NULLIF(supplier, ''), '(não informado)') AS supplier, SUM(paid_amount) AS total, COUNT(*) AS n FROM financial_entries
        WHERE entry_type = 'payable' AND status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(supplier, ''), '(não informado)') ORDER BY total DESC LIMIT 15", [$s, $e]);
    $months = rpt_cash_by_month($s, $e);
    $total = array_sum(array_map(fn($r) => (float)$r['total'], $byCat));
    $prevTotal = (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries WHERE entry_type='payable' AND status='paid' AND paid_at BETWEEN ? AND ?", [$ps, $pe]);
    $nMonths = max(1, count($months));
    return [
        'kpis' => [
            rpt_kpi('Total pago', round($total, 2), 'money', rpt_delta($total, $prevTotal), 'down'),
            rpt_kpi('Média mensal', round($total / $nMonths, 2), 'money', null, 'down'),
            rpt_kpi('Maior categoria', $byCat ? $byCat[0]['category'] : '—', 'text', null, 'up', $byCat ? money($byCat[0]['total']) : ''),
            rpt_kpi('Fornecedores diferentes', count($bySup), 'int'),
        ],
        'charts' => [
            rpt_chart('cats', 'Despesas por categoria', 'doughnut', array_column($byCat, 'category'), [['label' => 'Pago', 'data' => array_column($byCat, 'total')]]),
            rpt_chart('trend', 'Tendência mensal de despesas', 'line', array_map('rpt_month_label', array_keys($months)), [['label' => 'Despesas', 'data' => array_column($months, 'expenses'), 'color' => '#F43F5E']]),
            rpt_chart('sup', 'Principais fornecedores', 'hbar', array_column($bySup, 'supplier'), [['label' => 'Pago', 'data' => array_column($bySup, 'total'), 'color' => '#6D45F6']]),
        ],
        'tables' => [
            ['title' => 'Por categoria', 'columns' => [rpt_col('category', 'Categoria'), rpt_col('total', 'Total', 'money'), rpt_col('n', 'Lançamentos', 'int'), rpt_col('share', 'Participação', 'pct')],
                'rows' => array_map(fn($r) => ['category' => $r['category'], 'total' => round((float)$r['total'], 2), 'n' => (int)$r['n'], 'share' => $total > 0 ? round($r['total'] / $total * 100, 1) : 0], $byCat)],
            ['title' => 'Por fornecedor', 'columns' => [rpt_col('supplier', 'Fornecedor'), rpt_col('total', 'Total', 'money'), rpt_col('n', 'Pagamentos', 'int')],
                'rows' => array_map(fn($r) => ['supplier' => $r['supplier'], 'total' => round((float)$r['total'], 2), 'n' => (int)$r['n']], $bySup)],
        ],
    ];
}

function rpt_aging(string $type): array
{
    $today = today();
    $rows = db_all("SELECT e.id, e.description, e.amount, e.due_date, e.supplier, COALESCE(cu.name, e.supplier, '—') AS party, COALESCE(c.name, 'Sem categoria') AS category
        FROM financial_entries e LEFT JOIN customers cu ON cu.id = e.customer_id LEFT JOIN categories c ON c.id = e.category_id
        WHERE e.entry_type = ? AND e.status = 'open' ORDER BY e.due_date", [$type]);
    $buckets = ['A vencer (30d)' => 0.0, 'A vencer (+30d)' => 0.0, '1–30 dias' => 0.0, '31–60 dias' => 0.0, '61–90 dias' => 0.0, '+90 dias' => 0.0];
    $party = [];
    $list = [];
    foreach ($rows as $r) {
        $days = (int)floor((strtotime($today) - strtotime($r['due_date'])) / 86400);
        $b = $days <= 0 ? ($days > -30 ? 'A vencer (30d)' : 'A vencer (+30d)') : ($days <= 30 ? '1–30 dias' : ($days <= 60 ? '31–60 dias' : ($days <= 90 ? '61–90 dias' : '+90 dias')));
        $buckets[$b] += (float)$r['amount'];
        $k = $r['party'];
        $party[$k] = $party[$k] ?? ['party' => $k, 'current' => 0.0, 'overdue' => 0.0, 'n' => 0, 'oldest' => null];
        $party[$k][$days > 0 ? 'overdue' : 'current'] += (float)$r['amount'];
        $party[$k]['n']++;
        if ($days > 0) $party[$k]['oldest'] = max($party[$k]['oldest'] ?? 0, $days);
        $list[] = ['description' => $r['description'], 'party' => $r['party'], 'category' => $r['category'], 'due_date' => $r['due_date'], 'days' => max(0, $days), 'amount' => round((float)$r['amount'], 2), 'bucket' => $b, '_bad' => $days > 0];
    }
    usort($party, fn($a, $b) => $b['overdue'] <=> $a['overdue'] ?: $b['current'] <=> $a['current']);
    $total = array_sum($buckets);
    $overdue = $buckets['1–30 dias'] + $buckets['31–60 dias'] + $buckets['61–90 dias'] + $buckets['+90 dias'];
    return [$buckets, array_values($party), $list, $total, $overdue];
}

function rpt_receivables_aging(array $p): array
{
    [$buckets, $party, $list, $total, $overdue] = rpt_aging('receivable');
    return [
        'period' => [today(), today()], 'subtitle' => 'Posição em ' . date('d/m/Y'),
        'kpis' => [rpt_kpi('Total em aberto', round($total, 2)), rpt_kpi('Vencido', round($overdue, 2), 'money', null, 'down'), rpt_kpi('% vencido', $total > 0 ? round($overdue / $total * 100, 1) : 0, 'pct', null, 'down'), rpt_kpi('Títulos em aberto', count($list), 'int')],
        'charts' => [rpt_chart('aging', 'Distribuição por faixa', 'bar', array_keys($buckets), [['label' => 'Valor', 'data' => array_values($buckets), 'color' => '#0066FE']])],
        'tables' => [
            ['title' => 'Por cliente', 'columns' => [rpt_col('party', 'Cliente'), rpt_col('current', 'A vencer', 'money'), rpt_col('overdue', 'Vencido', 'money'), rpt_col('oldest', 'Maior atraso', 'days'), rpt_col('n', 'Títulos', 'int')], 'rows' => array_map(fn($r) => $r + ['_bad' => $r['overdue'] > 0], $party)],
            ['title' => 'Títulos', 'columns' => [rpt_col('description', 'Descrição'), rpt_col('party', 'Cliente'), rpt_col('due_date', 'Vencimento', 'date'), rpt_col('days', 'Dias em atraso', 'days'), rpt_col('amount', 'Valor', 'money'), rpt_col('bucket', 'Faixa')], 'rows' => $list],
        ],
    ];
}

function rpt_payables_aging(array $p): array
{
    [$buckets, $party, $list, $total, $overdue] = rpt_aging('payable');
    $next7 = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='payable' AND status='open' AND due_date BETWEEN ? AND ?", [today(), date('Y-m-d', strtotime('+7 days'))]);
    return [
        'period' => [today(), today()], 'subtitle' => 'Posição em ' . date('d/m/Y'),
        'kpis' => [rpt_kpi('Total a pagar', round($total, 2), 'money', null, 'down'), rpt_kpi('Vencido', round($overdue, 2), 'money', null, 'down'), rpt_kpi('Vence em 7 dias', round($next7, 2), 'money', null, 'down'), rpt_kpi('Compromissos', count($list), 'int')],
        'charts' => [rpt_chart('aging', 'Distribuição por faixa', 'bar', array_keys($buckets), [['label' => 'Valor', 'data' => array_values($buckets), 'color' => '#F43F5E']])],
        'tables' => [
            ['title' => 'Por fornecedor / favorecido', 'columns' => [rpt_col('party', 'Fornecedor'), rpt_col('current', 'A vencer', 'money'), rpt_col('overdue', 'Vencido', 'money'), rpt_col('oldest', 'Maior atraso', 'days'), rpt_col('n', 'Títulos', 'int')], 'rows' => $party],
            ['title' => 'Compromissos', 'columns' => [rpt_col('description', 'Descrição'), rpt_col('party', 'Fornecedor'), rpt_col('category', 'Categoria'), rpt_col('due_date', 'Vencimento', 'date'), rpt_col('days', 'Dias em atraso', 'days'), rpt_col('amount', 'Valor', 'money')], 'rows' => $list],
        ],
    ];
}

function rpt_delinquency(array $p): array
{
    [$s, $e] = rpt_period($p);
    $today = today();
    $rows = db_all("SELECT COALESCE(cu.name, '—') AS customer, cu.email, cu.phone, COUNT(*) AS n, SUM(e.amount) AS total, MIN(e.due_date) AS oldest
        FROM financial_entries e LEFT JOIN customers cu ON cu.id = e.customer_id
        WHERE e.entry_type = 'receivable' AND e.status = 'open' AND e.due_date < ? GROUP BY e.customer_id, cu.name, cu.email, cu.phone ORDER BY total DESC", [$today]);
    $charges = db_all("SELECT c.description, c.amount, c.due_date, c.status, cu.name AS customer FROM charges c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE c.status = 'OVERDUE' ORDER BY c.due_date LIMIT 50");
    $dueInPeriod = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status IN ('open','paid') AND due_date BETWEEN ? AND ?", [$s, min($e, $today)]);
    $paidLate = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='paid' AND due_date BETWEEN ? AND ? AND paid_at > due_date", [$s, min($e, $today)]);
    $stillOpen = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='open' AND due_date BETWEEN ? AND ?", [$s, date('Y-m-d', strtotime('-1 day'))]);
    $total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
    return [
        'kpis' => [
            rpt_kpi('Valor em atraso (hoje)', round($total, 2), 'money', null, 'down'),
            rpt_kpi('Clientes inadimplentes', count($rows), 'int', null, 'down'),
            rpt_kpi('Taxa de inadimplência do período', $dueInPeriod > 0 ? round($stillOpen / $dueInPeriod * 100, 1) : 0, 'pct', null, 'down', 'vencido e não pago ÷ total que venceu no período'),
            rpt_kpi('Recuperado com atraso', round($paidLate, 2), 'money', null, 'up', 'pago após o vencimento no período'),
        ],
        'charts' => [rpt_chart('top', 'Maiores atrasos por cliente', 'hbar', array_column(array_slice($rows, 0, 10), 'customer'), [['label' => 'Em atraso', 'data' => array_column(array_slice($rows, 0, 10), 'total'), 'color' => '#F43F5E']])],
        'tables' => [
            ['title' => 'Clientes com títulos vencidos', 'columns' => [rpt_col('customer', 'Cliente'), rpt_col('phone', 'Telefone'), rpt_col('n', 'Títulos', 'int'), rpt_col('total', 'Em atraso', 'money'), rpt_col('days', 'Dias (mais antigo)', 'days')],
                'rows' => array_map(fn($r) => ['customer' => $r['customer'], 'phone' => $r['phone'], 'n' => (int)$r['n'], 'total' => round((float)$r['total'], 2), 'days' => (int)floor((strtotime($today) - strtotime($r['oldest'])) / 86400), '_bad' => true], $rows)],
            ['title' => 'Cobranças Asaas vencidas', 'columns' => [rpt_col('customer', 'Cliente'), rpt_col('description', 'Descrição'), rpt_col('due_date', 'Vencimento', 'date'), rpt_col('amount', 'Valor', 'money')], 'rows' => $charges],
        ],
    ];
}

/* =============================================================== PROJECTS */

function rpt_project_profitability(array $p): array
{
    $rate = (float)setting('cost_per_hour', '0');
    $where = "p.status != 'canceled'";
    $params = [];
    if (!empty($p['customer_id'])) { $where .= ' AND p.customer_id = ?'; $params[] = (int)$p['customer_id']; }
    $rows = db_all("SELECT p.id, p.name, p.status, p.budget, p.estimated_hours, p.hourly_rate, cu.name AS customer,
        (SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries f WHERE f.project_id = p.id AND f.entry_type='receivable' AND f.status='paid') AS received,
        (SELECT COALESCE(SUM(amount),0) FROM financial_entries f WHERE f.project_id = p.id AND f.entry_type='receivable' AND f.status='open') AS to_receive,
        (SELECT COALESCE(SUM(CASE WHEN status='paid' THEN paid_amount ELSE amount END),0) FROM financial_entries f WHERE f.project_id = p.id AND f.entry_type='payable' AND f.status != 'canceled') AS costs,
        (SELECT COALESCE(SUM(minutes),0) FROM time_entries t WHERE t.project_id = p.id) AS minutes
        FROM projects p LEFT JOIN customers cu ON cu.id = p.customer_id WHERE $where ORDER BY p.created_at DESC LIMIT 200", $params);
    $out = [];
    $tot = ['contract' => 0, 'received' => 0, 'costs' => 0, 'hours' => 0, 'margin' => 0];
    foreach ($rows as $r) {
        $hours = (int)$r['minutes'] / 60;
        $revenue = max((float)$r['budget'], (float)$r['received'] + (float)$r['to_receive']);
        $labor = $hours * $rate;
        $margin = $revenue - (float)$r['costs'] - $labor;
        $out[] = ['project' => $r['name'], 'customer' => $r['customer'] ?: '—', 'status' => status_label('project', $r['status']), 'contract' => round($revenue, 2), 'received' => round((float)$r['received'], 2),
            'costs' => round((float)$r['costs'], 2), 'hours' => round($hours, 1), 'labor' => round($labor, 2), 'margin' => round($margin, 2), 'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 1) : null,
            'rate' => $hours > 0 ? round($revenue / $hours, 2) : null, '_bad' => $margin < 0];
        $tot['contract'] += $revenue; $tot['received'] += (float)$r['received']; $tot['costs'] += (float)$r['costs'] + $labor; $tot['hours'] += $hours; $tot['margin'] += $margin;
    }
    usort($out, fn($a, $b) => $b['margin'] <=> $a['margin']);
    $top = array_slice($out, 0, 12);
    return [
        'subtitle' => 'Todos os projetos ativos e concluídos' . ($rate > 0 ? ' · custo/hora da equipe: ' . money($rate) : ' · defina o custo/hora em Configurações para incluir mão de obra'),
        'kpis' => [rpt_kpi('Valor contratado', round($tot['contract'], 2)), rpt_kpi('Recebido', round($tot['received'], 2)), rpt_kpi('Custos (diretos + horas)', round($tot['costs'], 2), 'money', null, 'down'), rpt_kpi('Margem total', round($tot['margin'], 2), 'money'), rpt_kpi('Margem média', $tot['contract'] > 0 ? round($tot['margin'] / $tot['contract'] * 100, 1) : 0, 'pct'), rpt_kpi('Horas apontadas', round($tot['hours'], 1), 'hours')],
        'charts' => [rpt_chart('margin', 'Margem por projeto', 'hbar', array_column($top, 'project'), [['label' => 'Margem', 'data' => array_column($top, 'margin'), 'color' => '#00CF81'], ['label' => 'Custos', 'data' => array_map(fn($r) => $r['costs'] + $r['labor'], $top), 'color' => '#F43F5E']])],
        'tables' => [['title' => 'Projetos', 'columns' => [rpt_col('project', 'Projeto'), rpt_col('customer', 'Cliente'), rpt_col('status', 'Situação'), rpt_col('contract', 'Contrato', 'money'), rpt_col('received', 'Recebido', 'money'), rpt_col('costs', 'Custos diretos', 'money'), rpt_col('hours', 'Horas', 'hours'), rpt_col('margin', 'Margem', 'money'), rpt_col('margin_pct', 'Margem %', 'pct'), rpt_col('rate', 'R$/hora efetivo', 'money')], 'rows' => $out]],
        'highlights' => array_values(array_map(fn($r) => "{$r['project']} está com margem negativa (" . money($r['margin']) . ').', array_slice(array_filter($out, fn($r) => $r['margin'] < 0), 0, 3))),
    ];
}

function rpt_projects_portfolio(array $p): array
{
    $res = resources()['projects'];
    $rows = array_map($res['transform'], db_all('SELECT ' . $res['select'] . ' FROM ' . $res['from'] . " WHERE t.status != 'canceled' ORDER BY t.due_date IS NULL, t.due_date LIMIT 300"));
    $byStatus = [];
    $health = ['on_track' => 0, 'attention' => 0, 'at_risk' => 0, 'idle' => 0, 'done' => 0];
    foreach ($rows as $r) {
        $byStatus[status_label('project', $r['status'])] = ($byStatus[status_label('project', $r['status'])] ?? 0) + 1;
        $health[$r['health']]++;
    }
    $running = array_filter($rows, fn($r) => in_array($r['status'], PROJECT_RUNNING, true));
    $late = array_filter($running, fn($r) => $r['late']);
    $doneInPeriod = db_all("SELECT p.id, p.due_date, p.updated_at FROM projects p WHERE p.status = 'done' AND p.updated_at >= ?", [date('Y-m-d', strtotime('-365 days'))]);
    $onTime = count(array_filter($doneInPeriod, fn($r) => !$r['due_date'] || substr($r['updated_at'], 0, 10) <= $r['due_date']));
    $hl = ['on_track' => 'No prazo', 'attention' => 'Atenção', 'at_risk' => 'Em risco', 'idle' => 'Parado/proposta', 'done' => 'Concluído'];
    return [
        'subtitle' => 'Posição em ' . date('d/m/Y'),
        'kpis' => [
            rpt_kpi('Projetos em execução', count($running), 'int'),
            rpt_kpi('Atrasados', count($late), 'int', null, 'down'),
            rpt_kpi('Progresso médio', $running ? round(array_sum(array_column($running, 'progress')) / count($running), 0) : 0, 'pct'),
            rpt_kpi('Entregues no prazo (12 meses)', $doneInPeriod ? round($onTime / count($doneInPeriod) * 100, 0) : 0, 'pct', null, 'up', count($doneInPeriod) . ' projeto(s) concluídos'),
            rpt_kpi('Aprovações aguardando cliente', array_sum(array_column($rows, 'approvals_pending')), 'int', null, 'down'),
            rpt_kpi('Carteira contratada', round(array_sum(array_map(fn($r) => (float)$r['budget'], $running)), 2)),
        ],
        'charts' => [
            rpt_chart('status', 'Projetos por situação', 'doughnut', array_keys($byStatus), [['label' => 'Projetos', 'data' => array_values($byStatus)]], 'int'),
            rpt_chart('health', 'Saúde da carteira', 'bar', array_map(fn($k) => $hl[$k], array_keys($health)), [['label' => 'Projetos', 'data' => array_values($health), 'color' => '#6D45F6']], 'int'),
        ],
        'tables' => [['title' => 'Projetos em execução', 'columns' => [rpt_col('name', 'Projeto'), rpt_col('customer_name', 'Cliente'), rpt_col('status_label', 'Situação'), rpt_col('current_stage', 'Etapa atual'), rpt_col('progress', 'Progresso', 'pct'), rpt_col('due_date', 'Prazo', 'date'), rpt_col('hours_logged', 'Horas', 'hours'), rpt_col('health_label', 'Saúde')],
            'rows' => array_values(array_map(fn($r) => $r + ['status_label' => status_label('project', $r['status']), 'health_label' => $hl[$r['health']], '_bad' => $r['health'] === 'at_risk'], $running))]],
        'highlights' => array_values(array_map(fn($r) => "{$r['name']} está atrasado (prazo " . date('d/m/Y', strtotime($r['due_date'])) . ", {$r['progress']}% concluído).", array_slice($late, 0, 4))),
    ];
}

function rpt_timesheet(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $rate = (float)setting('cost_per_hour', '0');
    $byProject = db_all("SELECT pr.name AS project, SUM(t.minutes) AS minutes, SUM(CASE WHEN t.billable = 1 THEN t.minutes ELSE 0 END) AS billable, pr.hourly_rate
        FROM time_entries t JOIN projects pr ON pr.id = t.project_id WHERE t.work_date BETWEEN ? AND ? GROUP BY pr.id, pr.name, pr.hourly_rate ORDER BY minutes DESC", [$s, $e]);
    $byUser = db_all("SELECT COALESCE(user_name, '—') AS person, SUM(minutes) AS minutes, COUNT(DISTINCT project_id) AS projects FROM time_entries WHERE work_date BETWEEN ? AND ? GROUP BY user_name ORDER BY minutes DESC", [$s, $e]);
    $byMonth = [];
    foreach (db_all('SELECT SUBSTR(work_date, 1, 7) AS m, SUM(minutes) AS minutes FROM time_entries WHERE work_date BETWEEN ? AND ? GROUP BY SUBSTR(work_date, 1, 7)', [$s, $e]) as $r) $byMonth[$r['m']] = $r['minutes'] / 60;
    $months = rpt_months($s, $e);
    $total = array_sum(array_column($byProject, 'minutes')) / 60;
    $billable = array_sum(array_column($byProject, 'billable')) / 60;
    $prev = (float)db_value('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE work_date BETWEEN ? AND ?', [$ps, $pe]) / 60;
    $value = array_sum(array_map(fn($r) => ($r['billable'] / 60) * (float)($r['hourly_rate'] ?: 0), $byProject));
    return [
        'kpis' => [rpt_kpi('Horas apontadas', round($total, 1), 'hours', rpt_delta($total, $prev)), rpt_kpi('Horas faturáveis', round($billable, 1), 'hours'), rpt_kpi('% faturável', $total > 0 ? round($billable / $total * 100, 1) : 0, 'pct'), rpt_kpi('Valor das horas faturáveis', round($value, 2), 'money', null, 'up', 'pelo valor-hora de cada projeto'), $rate > 0 ? rpt_kpi('Custo da equipe', round($total * $rate, 2), 'money', null, 'down') : null],
        'charts' => [
            rpt_chart('proj', 'Horas por projeto', 'hbar', array_column(array_slice($byProject, 0, 12), 'project'), [['label' => 'Horas', 'data' => array_map(fn($r) => $r['minutes'] / 60, array_slice($byProject, 0, 12)), 'color' => '#0066FE']], 'hours'),
            rpt_chart('month', 'Horas por mês', 'bar', array_map('rpt_month_label', $months), [['label' => 'Horas', 'data' => array_map(fn($m) => $byMonth[$m] ?? 0, $months), 'color' => '#00CF81']], 'hours'),
        ],
        'tables' => [
            ['title' => 'Por projeto', 'columns' => [rpt_col('project', 'Projeto'), rpt_col('hours', 'Horas', 'hours'), rpt_col('billable', 'Faturáveis', 'hours'), rpt_col('value', 'Valor', 'money')], 'rows' => array_map(fn($r) => ['project' => $r['project'], 'hours' => round($r['minutes'] / 60, 1), 'billable' => round($r['billable'] / 60, 1), 'value' => round($r['billable'] / 60 * (float)($r['hourly_rate'] ?: 0), 2)], $byProject)],
            ['title' => 'Por pessoa', 'columns' => [rpt_col('person', 'Pessoa'), rpt_col('hours', 'Horas', 'hours'), rpt_col('projects', 'Projetos', 'int')], 'rows' => array_map(fn($r) => ['person' => $r['person'], 'hours' => round($r['minutes'] / 60, 1), 'projects' => (int)$r['projects']], $byUser)],
        ],
    ];
}

/* ============================================================= COMMERCIAL */

function rpt_sales_funnel(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $stages = status_sets()['lead'];
    $rows = db_all('SELECT status, source, estimated_value, lost_reason, created_at FROM leads WHERE created_at BETWEEN ? AND ?', [$s . ' 00:00:00', $e . ' 23:59:59']);
    $prevCount = (int)db_value('SELECT COUNT(*) FROM leads WHERE created_at BETWEEN ? AND ?', [$ps . ' 00:00:00', $pe . ' 23:59:59']);
    $byStage = array_fill_keys(array_keys($stages), ['n' => 0, 'value' => 0.0]);
    $bySource = [];
    $lost = [];
    foreach ($rows as $r) {
        $byStage[$r['status']]['n']++;
        $byStage[$r['status']]['value'] += (float)$r['estimated_value'];
        $bySource[$r['source']] = $bySource[$r['source']] ?? ['source' => $r['source'], 'n' => 0, 'won' => 0];
        $bySource[$r['source']]['n']++;
        if ($r['status'] === 'won') $bySource[$r['source']]['won']++;
        if ($r['status'] === 'lost') { $k = trim((string)$r['lost_reason']) ?: 'Não informado'; $lost[$k] = ($lost[$k] ?? 0) + 1; }
    }
    $sourceNames = ['contact' => 'Formulário de contato', 'diagnostic' => 'Diagnóstico', 'sys-demo' => 'Demo Integra SYS', 'chat' => 'Chat', 'manual' => 'Manual', 'whatsapp' => 'WhatsApp', 'indicacao' => 'Indicação', 'instagram' => 'Instagram', 'google' => 'Google', 'evento' => 'Evento'];
    $total = count($rows);
    $won = $byStage['won']['n'];
    $closed = $won + $byStage['lost']['n'];
    $open = array_filter($rows, fn($r) => !in_array($r['status'], ['won', 'lost'], true));
    $order = ['new', 'contacted', 'meeting', 'proposal', 'negotiation', 'won'];
    $reached = [];
    $rank = array_flip($order);
    foreach ($order as $st) $reached[$st] = count(array_filter($rows, fn($r) => isset($rank[$r['status']]) && $rank[$r['status']] >= $rank[$st]));
    arsort($lost);
    return [
        'kpis' => [
            rpt_kpi('Leads no período', $total, 'int', rpt_delta($total, $prevCount)),
            rpt_kpi('Taxa de conversão', $total ? round($won / $total * 100, 1) : 0, 'pct'),
            rpt_kpi('Taxa de ganho (fechados)', $closed ? round($won / $closed * 100, 1) : 0, 'pct'),
            rpt_kpi('Em negociação (valor)', round(array_sum(array_map(fn($r) => (float)$r['estimated_value'], $open)), 2)),
            rpt_kpi('Valor ganho', round($byStage['won']['value'], 2)),
            rpt_kpi('Ticket médio ganho', $won ? round($byStage['won']['value'] / $won, 2) : 0),
        ],
        'charts' => [
            rpt_chart('funnel', 'Funil (leads que chegaram a cada etapa)', 'hbar', array_map(fn($k) => $stages[$k], $order), [['label' => 'Leads', 'data' => array_values($reached), 'color' => '#0066FE']], 'int'),
            rpt_chart('source', 'Leads por origem', 'doughnut', array_map(fn($r) => $sourceNames[$r['source']] ?? $r['source'], array_values($bySource)), [['label' => 'Leads', 'data' => array_column(array_values($bySource), 'n')]], 'int'),
            rpt_chart('lost', 'Motivos de perda', 'hbar', array_keys($lost), [['label' => 'Perdidos', 'data' => array_values($lost), 'color' => '#F43F5E']], 'int'),
        ],
        'tables' => [
            ['title' => 'Por etapa', 'columns' => [rpt_col('stage', 'Etapa'), rpt_col('n', 'Leads', 'int'), rpt_col('value', 'Valor estimado', 'money')], 'rows' => array_map(fn($k, $v) => ['stage' => $stages[$k], 'n' => $v['n'], 'value' => round($v['value'], 2)], array_keys($byStage), $byStage)],
            ['title' => 'Por origem', 'columns' => [rpt_col('source', 'Origem'), rpt_col('n', 'Leads', 'int'), rpt_col('won', 'Ganhos', 'int'), rpt_col('rate', 'Conversão', 'pct')], 'rows' => array_map(fn($r) => ['source' => $sourceNames[$r['source']] ?? $r['source'], 'n' => $r['n'], 'won' => $r['won'], 'rate' => $r['n'] ? round($r['won'] / $r['n'] * 100, 1) : 0], array_values($bySource))],
        ],
    ];
}

function rpt_customers_portfolio(array $p): array
{
    [$s, $e] = rpt_period($p);
    $byStatus = db_all('SELECT status, COUNT(*) AS n FROM customers GROUP BY status');
    $bySegment = db_all("SELECT COALESCE(NULLIF(segment, ''), 'Não informado') AS segment, COUNT(*) AS n FROM customers WHERE status != 'inactive' GROUP BY COALESCE(NULLIF(segment, ''), 'Não informado') ORDER BY n DESC");
    $new = (int)db_value('SELECT COUNT(*) FROM customers WHERE created_at BETWEEN ? AND ?', [$s . ' 00:00:00', $e . ' 23:59:59']);
    $active = (int)db_value("SELECT COUNT(*) FROM customers WHERE status IN ('active','onboarding')");
    $ltv = db_all("SELECT cu.name, cu.status, cu.created_at, COALESCE(SUM(CASE WHEN f.entry_type='receivable' AND f.status='paid' THEN f.paid_amount END),0) AS ltv, MAX(CASE WHEN f.entry_type='receivable' AND f.status='paid' THEN f.paid_at END) AS last_payment,
        (SELECT COUNT(*) FROM projects pr WHERE pr.customer_id = cu.id) AS projects, (SELECT COUNT(*) FROM tickets t WHERE t.customer_id = cu.id) AS tickets
        FROM customers cu LEFT JOIN financial_entries f ON f.customer_id = cu.id GROUP BY cu.id, cu.name, cu.status, cu.created_at ORDER BY ltv DESC LIMIT 200");
    $idle = array_filter($ltv, fn($r) => in_array($r['status'], ['active', 'onboarding'], true) && (!$r['last_payment'] || $r['last_payment'] < date('Y-m-d', strtotime('-90 days'))));
    $paying = array_filter($ltv, fn($r) => (float)$r['ltv'] > 0);
    return [
        'kpis' => [
            rpt_kpi('Clientes ativos', $active, 'int'), rpt_kpi('Novos no período', $new, 'int'),
            rpt_kpi('LTV médio', $paying ? round(array_sum(array_map(fn($r) => (float)$r['ltv'], $paying)) / count($paying), 2) : 0, 'money', null, 'up', 'receita total por cliente pagante'),
            rpt_kpi('Ativos sem pagamento há 90 dias', count($idle), 'int', null, 'down'),
            rpt_kpi('Inadimplentes', (int)db_value("SELECT COUNT(*) FROM customers WHERE status = 'delinquent'"), 'int', null, 'down'),
        ],
        'charts' => [
            rpt_chart('status', 'Base por situação', 'doughnut', array_map(fn($r) => status_label('customer', $r['status']), $byStatus), [['label' => 'Clientes', 'data' => array_column($byStatus, 'n')]], 'int'),
            rpt_chart('segment', 'Clientes por segmento', 'hbar', array_column($bySegment, 'segment'), [['label' => 'Clientes', 'data' => array_column($bySegment, 'n'), 'color' => '#6D45F6']], 'int'),
        ],
        'tables' => [
            ['title' => 'Clientes por valor (LTV)', 'columns' => [rpt_col('name', 'Cliente'), rpt_col('status_label', 'Situação'), rpt_col('ltv', 'Receita total', 'money'), rpt_col('last_payment', 'Último pagamento', 'date'), rpt_col('projects', 'Projetos', 'int'), rpt_col('tickets', 'Chamados', 'int'), rpt_col('since', 'Cliente desde', 'date')],
                'rows' => array_map(fn($r) => ['name' => $r['name'], 'status_label' => status_label('customer', $r['status']), 'ltv' => round((float)$r['ltv'], 2), 'last_payment' => $r['last_payment'], 'projects' => (int)$r['projects'], 'tickets' => (int)$r['tickets'], 'since' => substr($r['created_at'], 0, 10)], $ltv)],
            ['title' => 'Ativos sem pagamento há mais de 90 dias', 'columns' => [rpt_col('name', 'Cliente'), rpt_col('last_payment', 'Último pagamento', 'date'), rpt_col('ltv', 'Receita total', 'money')], 'rows' => array_values(array_map(fn($r) => ['name' => $r['name'], 'last_payment' => $r['last_payment'], 'ltv' => round((float)$r['ltv'], 2)], $idle))],
        ],
    ];
}

/* ============================================================== OPERATION */

function rpt_support_performance(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $range = [$s . ' 00:00:00', $e . ' 23:59:59'];
    $opened = (int)db_value('SELECT COUNT(*) FROM tickets WHERE created_at BETWEEN ? AND ?', $range);
    $prevOpened = (int)db_value('SELECT COUNT(*) FROM tickets WHERE created_at BETWEEN ? AND ?', [$ps . ' 00:00:00', $pe . ' 23:59:59']);
    $resolved = db_all('SELECT created_at, resolved_at, first_response_at, sla_due_at FROM tickets WHERE resolved_at BETWEEN ? AND ?', $range);
    $withFirst = db_all('SELECT created_at, first_response_at, sla_due_at FROM tickets WHERE created_at BETWEEN ? AND ? AND first_response_at IS NOT NULL', $range);
    $slaMet = count(array_filter($withFirst, fn($r) => !$r['sla_due_at'] || $r['first_response_at'] <= $r['sla_due_at']));
    $avg = fn(array $rows, string $a, string $b) => $rows ? array_sum(array_map(fn($r) => (strtotime($r[$b]) - strtotime($r[$a])) / 3600, $rows)) / count($rows) : 0;
    $csat = db_all('SELECT satisfaction, COUNT(*) AS n FROM tickets WHERE satisfaction IS NOT NULL AND updated_at BETWEEN ? AND ? GROUP BY satisfaction', $range);
    $csatN = array_sum(array_column($csat, 'n'));
    $csatAvg = $csatN ? array_sum(array_map(fn($r) => $r['satisfaction'] * $r['n'], $csat)) / $csatN : 0;
    $byCat = db_all('SELECT category, COUNT(*) AS n FROM tickets WHERE created_at BETWEEN ? AND ? GROUP BY category ORDER BY n DESC', $range);
    $byAgent = db_all("SELECT COALESCE(u.name, 'Sem responsável') AS agent, COUNT(*) AS n, SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS done, AVG(t.satisfaction) AS csat
        FROM tickets t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.created_at BETWEEN ? AND ? GROUP BY u.name ORDER BY n DESC", $range);
    $byMonth = [];
    foreach (db_all('SELECT SUBSTR(created_at, 1, 7) AS m, COUNT(*) AS n FROM tickets WHERE created_at BETWEEN ? AND ? GROUP BY SUBSTR(created_at, 1, 7)', $range) as $r) $byMonth[$r['m']]['opened'] = (int)$r['n'];
    foreach (db_all('SELECT SUBSTR(resolved_at, 1, 7) AS m, COUNT(*) AS n FROM tickets WHERE resolved_at BETWEEN ? AND ? GROUP BY SUBSTR(resolved_at, 1, 7)', $range) as $r) $byMonth[$r['m']]['resolved'] = (int)$r['n'];
    $months = rpt_months($s, $e);
    $cats = ticket_categories();
    $backlog = (int)db_value('SELECT COUNT(*) FROM tickets WHERE status IN ' . sql_in(TICKET_OPEN));
    return [
        'kpis' => [
            rpt_kpi('Chamados abertos', $opened, 'int', rpt_delta($opened, $prevOpened), 'down'),
            rpt_kpi('Resolvidos', count($resolved), 'int'),
            rpt_kpi('SLA de 1ª resposta cumprido', $withFirst ? round($slaMet / count($withFirst) * 100, 1) : 0, 'pct'),
            rpt_kpi('Tempo médio de 1ª resposta', round($avg($withFirst, 'created_at', 'first_response_at'), 1), 'hours', null, 'down'),
            rpt_kpi('Tempo médio de resolução', round($avg($resolved, 'created_at', 'resolved_at'), 1), 'hours', null, 'down'),
            rpt_kpi('Satisfação (CSAT)', round($csatAvg, 2), 'decimal', null, 'up', $csatN . ' avaliação(ões), de 1 a 5'),
            rpt_kpi('Fila atual (backlog)', $backlog, 'int', null, 'down'),
        ],
        'charts' => [
            rpt_chart('month', 'Abertos × resolvidos por mês', 'bar', array_map('rpt_month_label', $months), [
                ['label' => 'Abertos', 'data' => array_map(fn($m) => $byMonth[$m]['opened'] ?? 0, $months), 'color' => '#0066FE'],
                ['label' => 'Resolvidos', 'data' => array_map(fn($m) => $byMonth[$m]['resolved'] ?? 0, $months), 'color' => '#00CF81'],
            ], 'int'),
            rpt_chart('cat', 'Por categoria', 'doughnut', array_map(fn($r) => $cats[$r['category']] ?? $r['category'], $byCat), [['label' => 'Chamados', 'data' => array_column($byCat, 'n')]], 'int'),
            rpt_chart('csat', 'Distribuição das notas', 'bar', ['1 ★', '2 ★', '3 ★', '4 ★', '5 ★'], [['label' => 'Avaliações', 'data' => array_map(fn($i) => (int)(array_column($csat, 'n', 'satisfaction')[$i] ?? 0), [1, 2, 3, 4, 5]), 'color' => '#F59E0B']], 'int'),
        ],
        'tables' => [['title' => 'Por responsável', 'columns' => [rpt_col('agent', 'Responsável'), rpt_col('n', 'Recebidos', 'int'), rpt_col('done', 'Resolvidos', 'int'), rpt_col('rate', 'Resolução', 'pct'), rpt_col('csat', 'CSAT', 'decimal')],
            'rows' => array_map(fn($r) => ['agent' => $r['agent'], 'n' => (int)$r['n'], 'done' => (int)$r['done'], 'rate' => $r['n'] ? round($r['done'] / $r['n'] * 100, 1) : 0, 'csat' => $r['csat'] !== null ? round((float)$r['csat'], 2) : null], $byAgent)]],
    ];
}

function rpt_nfse_report(array $p): array
{
    [$s, $e] = rpt_period($p);
    $range = [$s . ' 00:00:00', $e . ' 23:59:59'];
    $rows = db_all("SELECT SUBSTR(issued_at, 1, 7) AS m, status, COUNT(*) AS n, SUM(amount) AS amount, SUM(iss_amount) AS iss,
        SUM(COALESCE(pis_amount,0) + COALESCE(cofins_amount,0)) AS pc, SUM(COALESCE(csll_amount,0)) AS csll, SUM(COALESCE(irrf_amount,0) + COALESCE(inss_amount,0)) AS ir,
        SUM(CASE WHEN iss_withheld = 1 THEN iss_amount ELSE 0 END + CASE WHEN pis_withheld = 1 THEN pis_amount ELSE 0 END + CASE WHEN cofins_withheld = 1 THEN cofins_amount ELSE 0 END + CASE WHEN csll_withheld = 1 THEN csll_amount ELSE 0 END + CASE WHEN irrf_withheld = 1 THEN irrf_amount ELSE 0 END + CASE WHEN inss_withheld = 1 THEN inss_amount ELSE 0 END) AS withheld,
        SUM(COALESCE(net_amount, amount)) AS net
        FROM nfse_invoices WHERE issued_at BETWEEN ? AND ? AND status IN ('authorized','canceled') GROUP BY SUBSTR(issued_at, 1, 7), status", $range);
    $byCustomer = db_all("SELECT COALESCE(toma_name, '—') AS customer, COUNT(*) AS n, SUM(amount) AS amount FROM nfse_invoices WHERE issued_at BETWEEN ? AND ? AND status = 'authorized' GROUP BY toma_name ORDER BY amount DESC LIMIT 20", $range);
    $months = rpt_months($s, $e);
    $m = array_fill_keys($months, ['n' => 0, 'amount' => 0.0, 'iss' => 0.0, 'pc' => 0.0, 'csll' => 0.0, 'ir' => 0.0, 'withheld' => 0.0, 'net' => 0.0, 'canceled' => 0]);
    foreach ($rows as $r) {
        if (!isset($m[$r['m']])) continue;
        if ($r['status'] === 'canceled') { $m[$r['m']]['canceled'] += (int)$r['n']; continue; }
        $m[$r['m']]['n'] += (int)$r['n'];
        $m[$r['m']]['amount'] += (float)$r['amount'];
        foreach (['iss', 'pc', 'csll', 'ir', 'withheld', 'net'] as $k) $m[$r['m']][$k] += (float)$r[$k];
    }
    $sum = fn($k) => round(array_sum(array_column($m, $k)), 2);
    return [
        'kpis' => [rpt_kpi('Notas autorizadas', array_sum(array_column($m, 'n')), 'int'), rpt_kpi('Valor faturado', $sum('amount')), rpt_kpi('ISS', $sum('iss'), 'money', null, 'down'),
            rpt_kpi('PIS + COFINS', $sum('pc'), 'money', null, 'down'), rpt_kpi('CSLL', $sum('csll'), 'money', null, 'down'), rpt_kpi('IRRF + INSS', $sum('ir'), 'money', null, 'down'),
            rpt_kpi('Retido pelos tomadores', $sum('withheld'), 'money', null, 'down', 'impostos descontados na fonte'), rpt_kpi('Valor líquido recebível', $sum('net'), 'money'), rpt_kpi('Canceladas', array_sum(array_column($m, 'canceled')), 'int', null, 'down')],
        'charts' => [rpt_chart('month', 'Faturamento com nota por mês', 'bar', array_map('rpt_month_label', $months), [['label' => 'Valor', 'data' => array_column($m, 'amount'), 'color' => '#0066FE'], ['label' => 'ISS', 'data' => array_column($m, 'iss'), 'color' => '#F59E0B'], ['label' => 'PIS + COFINS', 'data' => array_column($m, 'pc'), 'color' => '#6D45F6'], ['label' => 'CSLL + IRRF + INSS', 'data' => array_map(fn($v) => $v['csll'] + $v['ir'], $m), 'color' => '#F43F5E']])],
        'tables' => [
            ['title' => 'Por mês', 'columns' => [rpt_col('month', 'Mês'), rpt_col('n', 'Notas', 'int'), rpt_col('amount', 'Valor', 'money'), rpt_col('iss', 'ISS', 'money'), rpt_col('pc', 'PIS + COFINS', 'money'), rpt_col('csll', 'CSLL', 'money'), rpt_col('ir', 'IRRF + INSS', 'money'), rpt_col('withheld', 'Retido', 'money'), rpt_col('net', 'Líquido', 'money'), rpt_col('canceled', 'Canceladas', 'int')], 'rows' => array_map(fn($k, $v) => ['month' => rpt_month_label($k)] + $v, array_keys($m), $m)],
            ['title' => 'Por tomador', 'columns' => [rpt_col('customer', 'Tomador'), rpt_col('n', 'Notas', 'int'), rpt_col('amount', 'Valor', 'money')], 'rows' => $byCustomer],
        ],
    ];
}

/* ============================================================== COMMERCIAL */

function rpt_recurring_revenue(array $p): array
{
    [$s, $e] = rpt_period($p);
    $all = db_all('SELECT k.*, c.name AS customer FROM contracts k JOIN customers c ON c.id = k.customer_id ORDER BY k.monthly_amount DESC');
    $active = array_values(array_filter($all, fn($k) => $k['status'] === 'active'));
    $mrr = array_sum(array_map(fn($k) => (float)$k['monthly_amount'], $active));
    $new = array_filter($all, fn($k) => substr((string)$k['created_at'], 0, 10) >= $s && substr((string)$k['created_at'], 0, 10) <= $e);
    $lost = array_filter($all, fn($k) => $k['canceled_at'] && substr((string)$k['canceled_at'], 0, 10) >= $s && substr((string)$k['canceled_at'], 0, 10) <= $e);
    $newMrr = array_sum(array_map(fn($k) => (float)$k['monthly_amount'], $new));
    $lostMrr = array_sum(array_map(fn($k) => (float)$k['monthly_amount'], $lost));
    $startMrr = array_sum(array_map(fn($k) => substr((string)$k['start_date'], 0, 10) < $s && (!$k['canceled_at'] || substr((string)$k['canceled_at'], 0, 10) >= $s) ? (float)$k['monthly_amount'] : 0, $all));
    $billed = db_one("SELECT COALESCE(SUM(amount),0) AS billed, COALESCE(SUM(CASE WHEN status='paid' THEN paid_amount ELSE 0 END),0) AS paid, COALESCE(SUM(CASE WHEN status='open' AND due_date < ? THEN amount ELSE 0 END),0) AS late
        FROM financial_entries WHERE contract_id IS NOT NULL AND entry_type = 'receivable' AND description LIKE 'Mensalidade %' AND due_date BETWEEN ? AND ?", [today(), $s, $e]);
    $months = rpt_months($s, $e);
    $evo = [];
    foreach ($months as $m) {
        $end = date('Y-m-t', strtotime($m . '-01'));
        $evo[$m] = array_sum(array_map(fn($k) => substr((string)$k['start_date'], 0, 10) <= $end && (!$k['canceled_at'] || substr((string)$k['canceled_at'], 0, 10) > $end) && $k['status'] !== 'ended' ? (float)$k['monthly_amount'] : 0, $all));
    }
    $byPlan = [];
    foreach ($active as $k) {
        $plan = $k['support_plan'] ?: 'Sem plano de suporte';
        $byPlan[$plan] = $byPlan[$plan] ?? ['plan' => $plan, 'n' => 0, 'mrr' => 0.0];
        $byPlan[$plan]['n']++;
        $byPlan[$plan]['mrr'] += (float)$k['monthly_amount'];
    }
    $churn = $startMrr > 0 ? round($lostMrr / $startMrr * 100, 1) : 0;
    $hl = [];
    if ($mrr > 0) $hl[] = 'A receita recorrente atual é de ' . money($mrr) . ' por mês (' . money($mrr * 12) . ' por ano).';
    if ($lostMrr > 0) $hl[] = 'Cancelamentos no período retiraram ' . money($lostMrr) . ' do MRR (churn de ' . number_format($churn, 1, ',', '.') . '%).';
    if ((float)$billed['late'] > 0) $hl[] = 'Há ' . money((float)$billed['late']) . ' em mensalidades vencidas e não pagas.';
    $expiring = array_filter($active, fn($k) => $k['end_date'] && $k['end_date'] <= date('Y-m-d', strtotime('+60 days')));
    if ($expiring) $hl[] = count($expiring) . ' contrato(s) terminam a vigência nos próximos 60 dias — hora de renovar.';
    return [
        'kpis' => [
            rpt_kpi('MRR atual', round($mrr, 2)), rpt_kpi('ARR (MRR × 12)', round($mrr * 12, 2)), rpt_kpi('Contratos ativos', count($active), 'int'),
            rpt_kpi('Ticket médio mensal', $active ? round($mrr / count($active), 2) : 0), rpt_kpi('Novo MRR no período', round($newMrr, 2), 'money', null, 'up', count($new) . ' contrato(s) novo(s)'),
            rpt_kpi('MRR cancelado', round($lostMrr, 2), 'money', null, 'down', count($lost) . ' cancelamento(s)'), rpt_kpi('Churn de receita', $churn, 'pct', null, 'down'),
            rpt_kpi('Mensalidades faturadas', round((float)$billed['billed'], 2), 'money', null, 'up', 'recebido: ' . money((float)$billed['paid'])),
        ],
        'charts' => [
            rpt_chart('evo', 'Evolução do MRR', 'line', array_map('rpt_month_label', $months), [['label' => 'MRR', 'data' => array_values($evo), 'color' => '#00CF81']]),
            rpt_chart('plans', 'MRR por plano de suporte', 'doughnut', array_keys($byPlan), [['label' => 'MRR', 'data' => array_column($byPlan, 'mrr')]]),
        ],
        'tables' => [
            ['title' => 'Contratos', 'columns' => [rpt_col('number', 'Contrato'), rpt_col('customer', 'Cliente'), rpt_col('plan', 'Suporte'), rpt_col('monthly', 'Mensalidade', 'money'), rpt_col('start', 'Início', 'date'), rpt_col('end', 'Fim da vigência', 'date'), rpt_col('status', 'Situação')],
                'rows' => array_map(fn($k) => ['number' => $k['number'], 'customer' => $k['customer'], 'plan' => $k['support_plan'] ?: '—', 'monthly' => (float)$k['monthly_amount'], 'start' => $k['start_date'], 'end' => $k['end_date'], 'status' => ['active' => 'Ativo', 'paused' => 'Pausado', 'canceled' => 'Cancelado', 'ended' => 'Encerrado'][$k['status']] ?? $k['status'], '_bad' => $k['status'] === 'canceled'], $all),
                'footer' => ['number' => 'MRR ativo', 'monthly' => round($mrr, 2)]],
            ['title' => 'Por plano de suporte', 'columns' => [rpt_col('plan', 'Plano'), rpt_col('n', 'Contratos', 'int'), rpt_col('mrr', 'MRR', 'money')], 'rows' => array_values($byPlan)],
        ],
        'highlights' => $hl,
    ];
}

function rpt_quotes_pipeline(array $p): array
{
    [$s, $e, $ps, $pe] = rpt_period($p);
    $range = [$s . ' 00:00:00', $e . ' 23:59:59'];
    $rows = db_all('SELECT q.*, COALESCE(c.name, l.company, l.name) AS client FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN leads l ON l.id = q.lead_id WHERE q.created_at BETWEEN ? AND ? ORDER BY q.created_at DESC', $range);
    $prevN = (int)db_value('SELECT COUNT(*) FROM quotes WHERE created_at BETWEEN ? AND ?', [$ps . ' 00:00:00', $pe . ' 23:59:59']);
    $acc = array_filter($rows, fn($q) => $q['status'] === 'accepted');
    $rej = array_filter($rows, fn($q) => $q['status'] === 'rejected');
    $decided = count($acc) + count($rej);
    $sum = fn($list, $k) => array_sum(array_map(fn($q) => (float)$q[$k], $list));
    $months = rpt_months($s, $e);
    $byMonth = array_fill_keys($months, ['created' => 0.0, 'accepted' => 0.0]);
    foreach ($rows as $q) {
        $m = substr((string)$q['created_at'], 0, 7);
        if (!isset($byMonth[$m])) continue;
        $byMonth[$m]['created'] += (float)$q['first_year_total'];
        if ($q['status'] === 'accepted') $byMonth[$m]['accepted'] += (float)$q['first_year_total'];
    }
    $items = [];
    foreach ($acc ?: $rows as $q) {
        foreach (json_decode((string)$q['items'], true) ?: [] as $l) {
            $items[$l['name']] = $items[$l['name']] ?? ['item' => $l['name'], 'n' => 0, 'value' => 0.0];
            $items[$l['name']]['n']++;
            $items[$l['name']]['value'] += (float)$l['total'];
        }
    }
    usort($items, fn($a, $b) => $b['n'] <=> $a['n'] ?: $b['value'] <=> $a['value']);
    $statusNames = ['draft' => 'Rascunho', 'sent' => 'Enviado', 'accepted' => 'Aceito', 'rejected' => 'Recusado', 'expired' => 'Expirado'];
    $byStatus = [];
    foreach ($rows as $q) {
        $st = $statusNames[$q['status']] ?? $q['status'];
        $byStatus[$st] = $byStatus[$st] ?? ['status' => $st, 'n' => 0, 'value' => 0.0];
        $byStatus[$st]['n']++;
        $byStatus[$st]['value'] += (float)$q['first_year_total'];
    }
    $avgDisc = $rows ? array_sum(array_map(fn($q) => (float)$q['setup_discount'], $rows)) / count($rows) : 0;
    $conv = $decided ? round(count($acc) / $decided * 100, 1) : 0;
    $hl = [];
    if ($decided) $hl[] = 'Taxa de conversão de ' . number_format($conv, 1, ',', '.') . '% entre os orçamentos decididos.';
    $stale = array_filter($rows, fn($q) => in_array($q['status'], ['draft', 'sent'], true) && $q['valid_until'] && $q['valid_until'] < today());
    if ($stale) $hl[] = count($stale) . ' orçamento(s) passaram da validade sem resposta — faça follow-up ou renove a proposta.';
    if ($avgDisc > 10) $hl[] = 'O desconto médio na implantação está em ' . number_format($avgDisc, 1, ',', '.') . '% — acima de 10%, atenção à margem.';
    return [
        'kpis' => [
            rpt_kpi('Orçamentos criados', count($rows), 'int', rpt_delta(count($rows), $prevN)), rpt_kpi('Valor orçado (1º ano)', round($sum($rows, 'first_year_total'), 2)),
            rpt_kpi('Aceitos', count($acc), 'int', null, 'up', money($sum($acc, 'first_year_total')) . ' no 1º ano'), rpt_kpi('Conversão', $conv, 'pct'),
            rpt_kpi('MRR conquistado', round($sum($acc, 'monthly_total'), 2)), rpt_kpi('Implantações vendidas', round($sum($acc, 'setup_total'), 2)),
            rpt_kpi('Ticket médio (1º ano)', $rows ? round($sum($rows, 'first_year_total') / count($rows), 2) : 0), rpt_kpi('Desconto médio na implantação', round($avgDisc, 1), 'pct', null, 'down'),
        ],
        'charts' => [
            rpt_chart('month', 'Orçado × aceito por mês (valor do 1º ano)', 'bar', array_map('rpt_month_label', $months), [['label' => 'Orçado', 'data' => array_column($byMonth, 'created'), 'color' => '#0066FE'], ['label' => 'Aceito', 'data' => array_column($byMonth, 'accepted'), 'color' => '#00CF81']]),
            rpt_chart('status', 'Orçamentos por situação', 'doughnut', array_keys($byStatus), [['label' => 'Orçamentos', 'data' => array_column($byStatus, 'n')]], 'int'),
        ],
        'tables' => [
            ['title' => $acc ? 'Itens mais vendidos' : 'Itens mais orçados', 'columns' => [rpt_col('item', 'Item'), rpt_col('n', 'Vezes', 'int'), rpt_col('value', 'Valor', 'money')], 'rows' => array_slice($items, 0, 15)],
            ['title' => 'Orçamentos do período', 'columns' => [rpt_col('number', 'Número'), rpt_col('client', 'Cliente / lead'), rpt_col('setup', 'Implantação', 'money'), rpt_col('monthly', 'Mensalidade', 'money'), rpt_col('discount', 'Desc. impl.', 'pct'), rpt_col('status', 'Situação'), rpt_col('date', 'Criado em', 'date')],
                'rows' => array_map(fn($q) => ['number' => $q['number'], 'client' => $q['client'] ?: 'Avulso', 'setup' => (float)$q['setup_total'], 'monthly' => (float)$q['monthly_total'], 'discount' => (float)$q['setup_discount'], 'status' => $statusNames[$q['status']] ?? $q['status'], 'date' => substr((string)$q['created_at'], 0, 10), '_bad' => $q['status'] === 'rejected'], $rows)],
        ],
        'highlights' => $hl,
    ];
}

function rpt_price_table(array $p): array
{
    $items = pricing_catalog(true);
    $diff = fn($it) => (float)$it['market_avg'] > 0 ? round(((float)$it['price'] / (float)$it['market_avg'] - 1) * 100, 1) : null;
    $withAvg = array_filter($items, fn($it) => (float)$it['market_avg'] > 0);
    $avgDiff = $withAvg ? array_sum(array_map($diff, $withAvg)) / count($withAvg) : 0;
    $belowMin = array_filter($items, fn($it) => (float)$it['market_min'] > 0 && (float)$it['price'] < (float)$it['market_min']);
    $aboveAvg = array_filter($items, fn($it) => (float)$it['market_avg'] > 0 && (float)$it['price'] > (float)$it['market_avg']);
    $cats = [];
    foreach ($items as $it) {
        $c = PRICE_CATEGORIES[$it['category']][0] ?? $it['category'];
        $cats[$c] = $cats[$c] ?? ['n' => 0, 'diff' => 0.0];
        $cats[$c]['n']++;
        $cats[$c]['diff'] += (float)($diff($it) ?? 0);
    }
    $hl = ['Regra de precificação: preço Integra = média de mercado em Marília − ' . round((1 - MARKET_FACTOR) * 100) . '%.'];
    if ($belowMin) $hl[] = count($belowMin) . ' item(ns) abaixo do mínimo de mercado — revise se cobrem o custo.';
    if ($aboveAvg) $hl[] = count($aboveAvg) . ' item(ns) acima da média de mercado.';
    $oldest = min(array_map(fn($it) => (string)$it['market_updated_at'] ?: '9999', $items) ?: ['9999']);
    if ($oldest !== '9999' && $oldest < date('Y-m-d', strtotime('-6 months'))) $hl[] = 'A pesquisa de mercado mais antiga é de ' . date('d/m/Y', strtotime($oldest)) . '. Atualize a tabela (Consultor IA ajuda).';
    return [
        'subtitle' => 'Tabela vigente em ' . date('d/m/Y'),
        'period' => [today(), today()],
        'kpis' => [rpt_kpi('Itens ativos', count($items), 'int'), rpt_kpi('Diferença média vs. mercado', round($avgDiff, 1), 'pct', null, 'down', 'negativo = abaixo da média'),
            rpt_kpi('Abaixo do mínimo de mercado', count($belowMin), 'int', null, 'down'), rpt_kpi('Acima da média', count($aboveAvg), 'int', null, 'down')],
        'charts' => [rpt_chart('cats', 'Diferença média vs. mercado por categoria (%)', 'hbar', array_keys($cats), [['label' => '% vs. média', 'data' => array_map(fn($c) => $c['n'] ? $c['diff'] / $c['n'] : 0, $cats), 'color' => '#00CF81']], 'pct')],
        'tables' => [['title' => 'Preços por item', 'columns' => [rpt_col('category', 'Categoria'), rpt_col('name', 'Item'), rpt_col('billing', 'Cobrança'), rpt_col('min', 'Mín. mercado', 'money'), rpt_col('avg', 'Média Marília', 'money'), rpt_col('max', 'Máx. mercado', 'money'), rpt_col('price', 'Preço Integra', 'money'), rpt_col('diff', 'vs. média', 'pct')],
            'rows' => array_map(fn($it) => ['category' => PRICE_CATEGORIES[$it['category']][0] ?? $it['category'], 'name' => $it['name'], 'billing' => PRICE_BILLING[$it['billing']] ?? $it['billing'], 'min' => (float)$it['market_min'], 'avg' => (float)$it['market_avg'], 'max' => (float)$it['market_max'], 'price' => (float)$it['price'], 'diff' => $diff($it), '_bad' => (float)$it['market_min'] > 0 && (float)$it['price'] < (float)$it['market_min']], $items)]],
        'highlights' => $hl,
    ];
}

/* ================================================================ BUILDER */

/** Whitelisted datasets for the report builder: from, date column, dimensions and metrics. */
function report_datasets(): array
{
    $hours = sql_hours('t.created_at', 't.first_response_at');
    return [
        'entries' => ['label' => 'Lançamentos financeiros', 'area' => 'finance',
            'from' => 'financial_entries t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN projects p ON p.id = t.project_id',
            'dates' => ['due_date' => 'Vencimento', 'paid_at' => 'Pagamento', 'competence_date' => 'Competência'],
            'dims' => ['entry_type' => ['Tipo', 't.entry_type'], 'status' => ['Situação', 't.status'], 'category' => ['Categoria', "COALESCE(c.name, 'Sem categoria')"], 'dre_group' => ['Grupo DRE', "COALESCE(c.dre_group, '—')"],
                'customer' => ['Cliente', "COALESCE(cu.name, '—')"], 'project' => ['Projeto', "COALESCE(p.name, '—')"], 'supplier' => ['Fornecedor', "COALESCE(NULLIF(t.supplier, ''), '—')"], 'payment_method' => ['Forma de pagamento', "COALESCE(t.payment_method, '—')"],
                'month' => ['Mês', 'SUBSTR({date}, 1, 7)'], 'year' => ['Ano', 'SUBSTR({date}, 1, 4)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'amount' => ['Valor (soma)', 'SUM(t.amount)', 'money'], 'paid' => ['Valor pago (soma)', 'SUM(COALESCE(t.paid_amount, 0))', 'money'], 'avg' => ['Valor médio', 'AVG(t.amount)', 'money']],
            'filters' => ['entry_type' => ['receivable' => 'A receber', 'payable' => 'A pagar'], 'status' => ['open' => 'Em aberto', 'paid' => 'Pago', 'canceled' => 'Cancelado']]],
        'charges' => ['label' => 'Cobranças (Asaas)', 'area' => 'charges', 'from' => 'charges t LEFT JOIN customers cu ON cu.id = t.customer_id',
            'dates' => ['due_date' => 'Vencimento', 'paid_at' => 'Pagamento', 'created_at' => 'Emissão'],
            'dims' => ['status' => ['Situação', 't.status'], 'billing_type' => ['Forma', 't.billing_type'], 'customer' => ['Cliente', "COALESCE(cu.name, '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'amount' => ['Valor (soma)', 'SUM(t.amount)', 'money'], 'net' => ['Valor líquido', 'SUM(COALESCE(t.net_amount, 0))', 'money']]],
        'projects' => ['label' => 'Projetos', 'area' => 'projects', 'from' => 'projects t LEFT JOIN customers cu ON cu.id = t.customer_id',
            'dates' => ['created_at' => 'Criação', 'start_date' => 'Início', 'due_date' => 'Prazo'],
            'dims' => ['status' => ['Situação', 't.status'], 'priority' => ['Prioridade', 't.priority'], 'project_type' => ['Tipo', "COALESCE(t.project_type, '—')"], 'manager' => ['Responsável', "COALESCE(t.manager, '—')"], 'customer' => ['Cliente', "COALESCE(cu.name, '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'budget' => ['Valor contratado', 'SUM(t.budget)', 'money'], 'hours' => ['Horas estimadas', 'SUM(COALESCE(t.estimated_hours, 0))', 'hours']]],
        'tickets' => ['label' => 'Chamados', 'area' => 'tickets', 'from' => 'tickets t LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN users u ON u.id = t.assigned_to',
            'dates' => ['created_at' => 'Abertura', 'resolved_at' => 'Resolução'],
            'dims' => ['status' => ['Situação', 't.status'], 'priority' => ['Prioridade', 't.priority'], 'category' => ['Categoria', 't.category'], 'source' => ['Canal', 't.source'], 'assignee' => ['Responsável', "COALESCE(u.name, 'Sem responsável')"], 'customer' => ['Cliente', "COALESCE(cu.name, t.name)"], 'satisfaction' => ['Nota (CSAT)', "COALESCE(CAST(t.satisfaction AS CHAR), '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'csat' => ['CSAT médio', 'AVG(t.satisfaction)', 'decimal'], 'first_response' => ['1ª resposta média (h)', "AVG(CASE WHEN t.first_response_at IS NOT NULL THEN $hours END)", 'hours']]],
        'leads' => ['label' => 'Leads', 'area' => 'leads', 'from' => 'leads t',
            'dates' => ['created_at' => 'Entrada', 'next_action_at' => 'Próximo contato'],
            'dims' => ['status' => ['Etapa', 't.status'], 'source' => ['Origem', 't.source'], 'owner' => ['Responsável', "COALESCE(t.owner, '—')"], 'lost_reason' => ['Motivo de perda', "COALESCE(NULLIF(t.lost_reason, ''), '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'value' => ['Valor estimado', 'SUM(COALESCE(t.estimated_value, 0))', 'money']]],
        'customers' => ['label' => 'Clientes', 'area' => 'customers', 'from' => 'customers t',
            'dates' => ['created_at' => 'Cadastro'],
            'dims' => ['status' => ['Situação', 't.status'], 'segment' => ['Segmento', "COALESCE(NULLIF(t.segment, ''), '—')"], 'city' => ['Cidade', "COALESCE(NULLIF(t.city, ''), '—')"], 'state' => ['UF', "COALESCE(t.state, '—')"], 'owner' => ['Responsável', "COALESCE(t.owner, '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int']]],
        'time' => ['label' => 'Horas apontadas', 'area' => 'projects', 'from' => 'time_entries t LEFT JOIN projects p ON p.id = t.project_id',
            'dates' => ['work_date' => 'Data do trabalho'],
            'dims' => ['project' => ['Projeto', "COALESCE(p.name, '—')"], 'person' => ['Pessoa', "COALESCE(t.user_name, '—')"], 'billable' => ['Faturável', "CASE WHEN t.billable = 1 THEN 'Sim' ELSE 'Não' END"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['hours' => ['Horas', 'SUM(t.minutes) / 60.0', 'hours'], 'count' => ['Apontamentos', 'COUNT(*)', 'int']]],
        'nfse' => ['label' => 'Notas fiscais', 'area' => 'finance', 'from' => 'nfse_invoices t',
            'dates' => ['issued_at' => 'Emissão', 'created_at' => 'Criação'],
            'dims' => ['status' => ['Situação', 't.status'], 'customer' => ['Tomador', "COALESCE(t.toma_name, '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'amount' => ['Valor', 'SUM(t.amount)', 'money'], 'iss' => ['ISS', 'SUM(t.iss_amount)', 'money'], 'pis' => ['PIS', 'SUM(COALESCE(t.pis_amount, 0))', 'money'], 'cofins' => ['COFINS', 'SUM(COALESCE(t.cofins_amount, 0))', 'money'],
                'csll' => ['CSLL', 'SUM(COALESCE(t.csll_amount, 0))', 'money'], 'irrf' => ['IRRF', 'SUM(COALESCE(t.irrf_amount, 0))', 'money'], 'net' => ['Valor líquido', 'SUM(COALESCE(t.net_amount, t.amount))', 'money']]],
        'quotes' => ['label' => 'Orçamentos', 'area' => 'customers', 'from' => 'quotes t LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN leads l ON l.id = t.lead_id',
            'dates' => ['created_at' => 'Criação', 'accepted_at' => 'Aceite', 'valid_until' => 'Validade'],
            'dims' => ['status' => ['Situação', 't.status'], 'client' => ['Cliente / lead', "COALESCE(cu.name, l.company, l.name, 'Avulso')"], 'created_by' => ['Criado por', "COALESCE(t.created_by, '—')"], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'setup' => ['Implantação', 'SUM(t.setup_total)', 'money'], 'monthly' => ['Mensalidade', 'SUM(t.monthly_total)', 'money'], 'first_year' => ['Valor 1º ano', 'SUM(t.first_year_total)', 'money'], 'discount' => ['Desconto médio implantação (%)', 'AVG(t.setup_discount)', 'decimal']]],
        'contracts' => ['label' => 'Contratos', 'area' => 'customers', 'from' => 'contracts t LEFT JOIN customers cu ON cu.id = t.customer_id',
            'dates' => ['start_date' => 'Início', 'created_at' => 'Criação', 'end_date' => 'Fim da vigência'],
            'dims' => ['status' => ['Situação', 't.status'], 'customer' => ['Cliente', "COALESCE(cu.name, '—')"], 'support_plan' => ['Plano de suporte', "COALESCE(t.support_plan, '—')"], 'billing_type' => ['Forma de pagamento', 't.billing_type'], 'month' => ['Mês', 'SUBSTR({date}, 1, 7)']],
            'metrics' => ['count' => ['Quantidade', 'COUNT(*)', 'int'], 'mrr' => ['Mensalidade (soma)', 'SUM(t.monthly_amount)', 'money'], 'setup' => ['Implantação (soma)', 'SUM(t.setup_amount)', 'money']]],
    ];
}

/** Public shape of the datasets (labels only) for the builder UI. */
function report_datasets_meta(array $user): array
{
    $out = [];
    foreach (report_datasets() as $k => $d) {
        if (!can($d['area'], $user)) continue;
        $out[$k] = ['label' => $d['label'], 'dates' => $d['dates'], 'dims' => array_map(fn($x) => $x[0], $d['dims']), 'metrics' => array_map(fn($x) => $x[0], $d['metrics']), 'filters' => $d['filters'] ?? []];
    }
    return $out;
}

/**
 * Run a builder configuration: {dataset, dim, dim2?, metric, date_field, start, end, filters{}, chart, sort, limit, title}.
 * Only whitelisted expressions reach SQL; user values are always bound parameters.
 */
function report_build(array $cfg, array $user): array
{
    $ds = report_datasets()[$cfg['dataset'] ?? ''] ?? null;
    if (!$ds) throw new AppException('Escolha a base de dados do relatório.');
    if (!can($ds['area'], $user)) throw new AppException('Você não tem acesso a esta base.');
    $dateField = isset($ds['dates'][$cfg['date_field'] ?? '']) ? $cfg['date_field'] : array_key_first($ds['dates']);
    $dateCol = "t.$dateField";
    $dim = $ds['dims'][$cfg['dim'] ?? ''] ?? null;
    if (!$dim) throw new AppException('Escolha como agrupar (dimensão).');
    $dim2 = !empty($cfg['dim2']) && ($cfg['dim2'] !== $cfg['dim']) ? ($ds['dims'][$cfg['dim2']] ?? null) : null;
    $metric = $ds['metrics'][$cfg['metric'] ?? ''] ?? $ds['metrics']['count'];
    [$start, $end] = rpt_period($cfg);
    $dimSql = str_replace('{date}', $dateCol, $dim[1]);
    $dim2Sql = $dim2 ? str_replace('{date}', $dateCol, $dim2[1]) : null;
    $where = ["$dateCol BETWEEN ? AND ?"];
    $params = [$start, strlen($end) === 10 && in_array($dateField, ['created_at', 'issued_at', 'resolved_at', 'next_action_at', 'accepted_at', 'paid_at'], true) ? $end . ' 23:59:59' : $end];
    foreach (($cfg['filters'] ?? []) as $k => $v) {
        if ($v === '' || $v === null || !isset($ds['dims'][$k])) continue;
        $where[] = str_replace('{date}', $dateCol, $ds['dims'][$k][1]) . ' = ?';
        $params[] = (string)$v;
    }
    $limit = max(1, min(500, (int)($cfg['limit'] ?? 50)));
    $groupSql = $dimSql . ($dim2Sql ? ", $dim2Sql" : '');
    $sql = "SELECT $dimSql AS d1" . ($dim2Sql ? ", $dim2Sql AS d2" : '') . ", {$metric[1]} AS v FROM {$ds['from']} WHERE " . implode(' AND ', $where) . " GROUP BY $groupSql";
    $rows = db_all($sql, $params);
    $statusSet = ['projects' => 'project', 'tickets' => 'ticket', 'leads' => 'lead', 'customers' => 'customer'][$cfg['dataset']] ?? null;
    $nice = function (string $key, $v) use ($statusSet, $cfg) {
        $v = (string)$v;
        if ($key === 'month') return rpt_month_label($v);
        if ($key === 'status' && $statusSet) return status_label($statusSet, $v);
        if ($key === 'status' && $cfg['dataset'] === 'entries') return ['open' => 'Em aberto', 'paid' => 'Pago', 'canceled' => 'Cancelado'][$v] ?? $v;
        if ($key === 'entry_type') return ['receivable' => 'A receber', 'payable' => 'A pagar'][$v] ?? $v;
        if ($key === 'priority') return status_label('priority', $v);
        if ($key === 'category' && $cfg['dataset'] === 'tickets') return ticket_categories()[$v] ?? $v;
        return $v;
    };
    $isTime = in_array($cfg['dim'], ['month', 'year'], true);
    if (!$dim2) {
        $data = array_map(fn($r) => ['label' => $nice($cfg['dim'], $r['d1']), 'raw' => (string)$r['d1'], 'value' => round((float)$r['v'], 2)], $rows);
        usort($data, fn($a, $b) => $isTime || ($cfg['sort'] ?? '') === 'label' ? strcmp($a['raw'], $b['raw']) : $b['value'] <=> $a['value']);
        $data = array_slice($data, 0, $limit);
        $total = array_sum(array_column($data, 'value'));
        $chart = rpt_chart('builder', $cfg['title'] ?? $metric[0], $cfg['chart'] ?? 'bar', array_column($data, 'label'), [['label' => $metric[0], 'data' => array_column($data, 'value'), 'color' => '#0066FE']], $metric[2]);
        $table = ['title' => 'Dados', 'columns' => [rpt_col('label', $dim[0]), rpt_col('value', $metric[0], $metric[2]), rpt_col('share', 'Participação', 'pct')],
            'rows' => array_map(fn($d) => ['label' => $d['label'], 'value' => $d['value'], 'share' => $total != 0 && in_array($metric[2], ['int', 'money', 'hours'], true) ? round($d['value'] / $total * 100, 1) : null], $data),
            'footer' => in_array($metric[2], ['int', 'money', 'hours'], true) ? ['label' => 'Total', 'value' => round($total, 2), 'share' => 100] : null];
    } else {
        $d1 = [];
        $d2 = [];
        $matrix = [];
        foreach ($rows as $r) {
            $a = (string)$r['d1'];
            $b = (string)$r['d2'];
            $d1[$a] = ($d1[$a] ?? 0) + (float)$r['v'];
            $d2[$b] = ($d2[$b] ?? 0) + (float)$r['v'];
            $matrix[$a][$b] = round((float)$r['v'], 2);
        }
        if ($isTime) ksort($d1); else arsort($d1);
        arsort($d2);
        $d1 = array_slice($d1, 0, $limit, true);
        $d2 = array_slice($d2, 0, 10, true);
        $datasets = [];
        foreach (array_keys($d2) as $b) $datasets[] = ['label' => $nice($cfg['dim2'], $b), 'data' => array_map(fn($a) => $matrix[$a][$b] ?? 0, array_keys($d1))];
        $chart = rpt_chart('builder', $cfg['title'] ?? $metric[0], ($cfg['chart'] ?? 'bar') === 'doughnut' ? 'stacked' : ($cfg['chart'] ?? 'stacked'), array_map(fn($a) => $nice($cfg['dim'], $a), array_keys($d1)), $datasets, $metric[2]);
        $cols = [rpt_col('label', $dim[0])];
        foreach (array_keys($d2) as $i => $b) $cols[] = rpt_col('c' . $i, $nice($cfg['dim2'], $b), $metric[2]);
        $cols[] = rpt_col('total', 'Total', $metric[2]);
        $trows = [];
        foreach (array_keys($d1) as $a) {
            $row = ['label' => $nice($cfg['dim'], $a)];
            foreach (array_keys($d2) as $i => $b) $row['c' . $i] = $matrix[$a][$b] ?? 0;
            $row['total'] = round($d1[$a], 2);
            $trows[] = $row;
        }
        $table = ['title' => 'Dados', 'columns' => $cols, 'rows' => $trows];
    }
    $values = $dim2 ? array_values($d1) : array_column($data, 'value');
    $kpis = [rpt_kpi(in_array($metric[2], ['decimal'], true) ? $metric[0] . ' (média)' : $metric[0] . ' (total)', in_array($metric[2], ['decimal'], true) ? ($values ? round(array_sum($values) / count($values), 2) : 0) : round(array_sum($values), 2), $metric[2]), rpt_kpi('Grupos', count($values), 'int')];
    if ($values) $kpis[] = rpt_kpi('Maior valor', round(max($values), 2), $metric[2]);
    return [
        'key' => 'builder', 'title' => $cfg['title'] ?? ($metric[0] . ' por ' . mb_strtolower($dim[0])), 'description' => $ds['label'] . ' · ' . $metric[0] . ' por ' . mb_strtolower($dim[0]) . ($dim2 ? ' e ' . mb_strtolower($dim2[0]) : ''),
        'subtitle' => $ds['label'] . ' · ' . $ds['dates'][$dateField] . ' de ' . date('d/m/Y', strtotime($start)) . ' a ' . date('d/m/Y', strtotime($end)),
        'period' => [$start, $end], 'kpis' => $kpis, 'charts' => [$chart], 'tables' => [$table], 'highlights' => [], 'generated_at' => now(),
    ];
}

/* ================================================================ INSIGHTS */

function report_to_text(array $r, int $maxRows = 12): string
{
    $fmt = function ($v, string $f) {
        if ($v === null || $v === '') return '—';
        return match ($f) { 'money' => money($v), 'pct' => number_format((float)$v, 1, ',', '.') . '%', 'hours' => number_format((float)$v, 1, ',', '.') . ' h', 'days' => $v . ' dias', 'int' => (string)(int)$v, 'decimal' => number_format((float)$v, 2, ',', '.'), 'date' => $v ? date('d/m/Y', strtotime((string)$v)) : '—', default => (string)$v };
    };
    $lines = ['Relatório: ' . $r['title'], $r['subtitle'] ?? ''];
    foreach ($r['kpis'] as $k) {
        if (!$k) continue;
        $lines[] = '- ' . $k['label'] . ': ' . $fmt($k['value'], $k['format']) . ($k['delta'] !== null ? ' (variação ' . ($k['delta'] >= 0 ? '+' : '') . number_format($k['delta'], 1, ',', '.') . ($k['format'] === 'pct' ? ' p.p.' : '%') . ')' : '') . ($k['hint'] ? ' — ' . $k['hint'] : '');
    }
    foreach ($r['tables'] as $t) {
        if (!$t['rows']) continue;
        $lines[] = "\nTabela: " . $t['title'];
        $lines[] = implode(' | ', array_column($t['columns'], 'label'));
        foreach (array_slice($t['rows'], 0, $maxRows) as $row) $lines[] = implode(' | ', array_map(fn($c) => $fmt($row[$c['key']] ?? null, $c['format']), $t['columns']));
        if (count($t['rows']) > $maxRows) $lines[] = '... (+' . (count($t['rows']) - $maxRows) . ' linhas)';
    }
    foreach ($r['highlights'] as $h) $lines[] = 'Observação: ' . $h;
    return mb_substr(implode("\n", $lines), 0, 7000);
}

/** Rule-based executive notes when AI is unavailable. */
function report_rule_insights(array $r): string
{
    $out = ['**Resumo**', ($r['description'] ?? '') . ' ' . ($r['subtitle'] ?? '')];
    $good = [];
    $bad = [];
    foreach ($r['kpis'] as $k) {
        if (!$k || $k['delta'] === null) continue;
        $up = $k['delta'] > 0;
        $isGood = ($k['good'] === 'up') === $up;
        if (abs($k['delta']) < 2) continue;
        $txt = $k['label'] . ($up ? ' subiu ' : ' caiu ') . number_format(abs($k['delta']), 1, ',', '.') . ($k['format'] === 'pct' ? ' p.p.' : '%') . ' em relação ao período anterior.';
        $isGood ? $good[] = $txt : $bad[] = $txt;
    }
    if ($good) { $out[] = "\n**Destaques positivos**"; foreach ($good as $g) $out[] = '- ' . $g; }
    if ($bad || $r['highlights']) { $out[] = "\n**Pontos de atenção**"; foreach (array_merge($bad, $r['highlights']) as $b) $out[] = '- ' . $b; }
    $out[] = "\n**Próximos passos sugeridos**";
    $out[] = '- Revisar os itens de maior valor da tabela principal e definir um responsável para cada ação.';
    $out[] = '- Acompanhar este relatório mensalmente para confirmar a tendência.';
    return implode("\n", $out);
}

function report_insights(array $r): array
{
    if (!ai_feature('admin')) return ['text' => report_rule_insights($r), 'ai' => false];
    $text = ai_chat([
        ['role' => 'system', 'content' => "Você é o analista de gestão da Integra Code (empresa de software). Escreva em português do Brasil, tom executivo e objetivo, para os sócios. Use somente os números fornecidos — nunca invente dados. Formato: **Resumo** (2-3 frases), **Destaques positivos** (bullets), **Pontos de atenção** (bullets), **Recomendações** (3 a 5 ações práticas com responsável sugerido). Use '- ' para bullets e **negrito** para títulos. Máximo de 260 palavras."],
        ['role' => 'user', 'content' => report_to_text($r)],
    ], 'report_insight', 900, 0.3);
    return ['text' => $text, 'ai' => true];
}
