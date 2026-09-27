<?php
declare(strict_types=1);

/**
 * AI presentations with the Integra Code identity.
 * Pipeline: collect real facts (DB) → build a copy template with data-driven fallback texts →
 * optionally ask Workers AI to rewrite the copy (JSON) → assemble slides where every number comes from the DB.
 * Rendered by /apresentacao.php (web deck + print/PDF).
 */

require_once INC_PATH . '/reports.php';
require_once INC_PATH . '/content.php';

const DECK_KINDS = [
    'proposal' => ['Proposta comercial', 'Para novos clientes e leads: cenário, solução, método, investimento e próximos passos.'],
    'results' => ['Relatório de resultados', 'Para clientes atuais: entregas, indicadores, suporte e próximos passos do período.'],
    'qbr' => ['Revisão estratégica (QBR)', 'Para clientes atuais: resultados do trimestre, financeiro, oportunidades e plano.'],
    'kickoff' => ['Kickoff de projeto', 'Objetivos, escopo, cronograma, responsabilidades e comunicação do projeto.'],
    'institutional' => ['Apresentação institucional', 'Quem é a Integra Code, soluções, segmentos, método e diferenciais.'],
    'report' => ['Apresentação de relatório', 'Transforma qualquer relatório do painel em slides executivos com análise.'],
];

const DECK_LAYOUTS = ['cover', 'agenda', 'section', 'bullets', 'kpis', 'chart', 'timeline', 'comparison', 'pricing', 'quote', 'about', 'table', 'closing'];

function deck_date(?string $d = null): string
{
    $m = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $t = $d ? strtotime($d) : time();
    return (int)date('j', $t) . ' de ' . $m[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}

function deck_period_label(string $s, string $e): string
{
    return date('d/m/Y', strtotime($s)) . ' a ' . date('d/m/Y', strtotime($e));
}

/* ================================================================== FACTS */

function deck_customer_facts(int $cid, string $start, string $end): array
{
    $c = db_find('customers', $cid);
    if (!$c) throw new AppException('Cliente não encontrado.');
    $range = [$start . ' 00:00:00', $end . ' 23:59:59'];
    $projects = db_all("SELECT * FROM projects WHERE customer_id = ? AND status != 'canceled' ORDER BY status = 'done', updated_at DESC", [$cid]);
    foreach ($projects as &$p) {
        $st = db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$p['id']]);
        $tk = db_all('SELECT * FROM project_tasks WHERE project_id = ?', [$p['id']]);
        $p['stages'] = $st;
        $p['progress'] = project_progress($p['status'], $st, $tk);
        $p['tasks_done'] = count(array_filter($tk, fn($t) => (int)$t['done']));
        $p['tasks_total'] = count($tk);
        $cur = null;
        foreach ($st as $s) if (in_array($s['status'], STAGE_CURRENT, true)) { $cur = $s; break; }
        $p['current_stage'] = $cur['name'] ?? null;
    }
    unset($p);
    $delivered = db_all("SELECT s.name, s.completed_at, p.name AS project FROM project_stages s JOIN projects p ON p.id = s.project_id WHERE p.customer_id = ? AND s.status = 'done' AND s.completed_at BETWEEN ? AND ? ORDER BY s.completed_at", array_merge([$cid], $range));
    $tasksDone = (int)db_value('SELECT COUNT(*) FROM project_tasks k JOIN projects p ON p.id = k.project_id WHERE p.customer_id = ? AND k.done = 1', [$cid]);
    $tickets = db_all('SELECT status, created_at, first_response_at, resolved_at, sla_due_at, satisfaction, category FROM tickets WHERE customer_id = ? AND created_at BETWEEN ? AND ?', array_merge([$cid], $range));
    $withFirst = array_filter($tickets, fn($t) => $t['first_response_at']);
    $slaOk = count(array_filter($withFirst, fn($t) => !$t['sla_due_at'] || $t['first_response_at'] <= $t['sla_due_at']));
    $rated = array_filter($tickets, fn($t) => $t['satisfaction'] !== null);
    $resolved = array_filter($tickets, fn($t) => $t['resolved_at']);
    $paid = (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries WHERE customer_id = ? AND entry_type = 'receivable' AND status = 'paid' AND paid_at BETWEEN ? AND ?", [$cid, $start, $end]);
    $open = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE customer_id = ? AND entry_type = 'receivable' AND status = 'open'", [$cid]);
    $months = rpt_months($start, $end);
    $tmonth = array_fill_keys($months, ['opened' => 0, 'resolved' => 0]);
    foreach ($tickets as $t) {
        $m = substr($t['created_at'], 0, 7);
        if (isset($tmonth[$m])) $tmonth[$m]['opened']++;
        if ($t['resolved_at'] && isset($tmonth[substr($t['resolved_at'], 0, 7)])) $tmonth[substr($t['resolved_at'], 0, 7)]['resolved']++;
    }
    $invoices = db_all("SELECT nfse_number, amount, issued_at FROM nfse_invoices WHERE customer_id = ? AND status = 'authorized' AND issued_at BETWEEN ? AND ? ORDER BY issued_at", array_merge([$cid], $range));
    $payments = db_all("SELECT description, paid_amount, paid_at FROM financial_entries WHERE customer_id = ? AND entry_type = 'receivable' AND status = 'paid' AND paid_at BETWEEN ? AND ? ORDER BY paid_at", [$cid, $start, $end]);
    return [
        'customer' => $c, 'name' => $c['trade_name'] ?: $c['name'], 'projects' => $projects, 'delivered' => $delivered, 'tasks_done' => $tasksDone,
        'tickets_total' => count($tickets), 'tickets_resolved' => count($resolved), 'sla_pct' => $withFirst ? round($slaOk / count($withFirst) * 100) : null,
        'csat' => $rated ? round(array_sum(array_column($rated, 'satisfaction')) / count($rated), 1) : null, 'ticket_months' => $tmonth,
        'first_response_h' => $withFirst ? round(array_sum(array_map(fn($t) => (strtotime($t['first_response_at']) - strtotime($t['created_at'])) / 3600, $withFirst)) / count($withFirst), 1) : null,
        'paid' => $paid, 'open' => $open, 'invoices' => $invoices, 'payments' => $payments, 'since' => $c['created_at'], 'segment' => $c['segment'],
    ];
}

function deck_facts_text(array $f, string $start, string $end): string
{
    $l = ["Cliente: {$f['name']}" . ($f['segment'] ? " (segmento: {$f['segment']})" : '') . ', cliente desde ' . date('m/Y', strtotime($f['since'])), 'Período analisado: ' . deck_period_label($start, $end)];
    foreach ($f['projects'] as $p) $l[] = "Projeto \"{$p['name']}\" — situação: " . status_label('project', $p['status']) . ", {$p['progress']}% concluído" . ($p['current_stage'] ? ", etapa atual: {$p['current_stage']}" : '') . ($p['description'] ? '. Escopo: ' . mb_substr(preg_replace('/\s+/', ' ', $p['description']), 0, 300) : '');
    if ($f['delivered']) $l[] = 'Etapas entregues no período: ' . implode('; ', array_map(fn($d) => $d['name'] . ' (' . $d['project'] . ')', $f['delivered']));
    $l[] = "Chamados no período: {$f['tickets_total']} abertos, {$f['tickets_resolved']} resolvidos" . ($f['sla_pct'] !== null ? ", SLA de 1ª resposta {$f['sla_pct']}%" : '') . ($f['csat'] !== null ? ", satisfação {$f['csat']}/5" : '');
    $l[] = 'Pagamentos no período: ' . money($f['paid']) . '; em aberto: ' . money($f['open']);
    return implode("\n", $l);
}

/* ============================================================== AI COPY */

/** Merge AI JSON onto the fallback copy, keeping types (string / list of strings / list of objects). */
function deck_merge_copy(array $fallback, array $ai): array
{
    foreach ($fallback as $k => $v) {
        if (!array_key_exists($k, $ai)) continue;
        $a = $ai[$k];
        if (is_string($v) && is_string($a) && trim($a) !== '') $fallback[$k] = mb_substr(trim(strip_tags($a)), 0, 400);
        elseif (is_array($v) && is_array($a) && $a) {
            $isObj = isset($v[0]) && is_array($v[0]);
            $clean = [];
            foreach (array_slice($a, 0, 8) as $item) {
                if ($isObj && is_array($item)) {
                    $row = [];
                    foreach ($v[0] as $kk => $_) $row[$kk] = mb_substr(trim(strip_tags((string)($item[$kk] ?? ''))), 0, 260);
                    if (implode('', $row) !== '') $clean[] = $row;
                } elseif (!$isObj && is_string($item) && trim($item) !== '') $clean[] = mb_substr(trim(strip_tags($item)), 0, 260);
            }
            if ($clean) $fallback[$k] = $clean;
        }
    }
    return $fallback;
}

function deck_extract_json(string $text): ?array
{
    $text = preg_replace('/^```(?:json)?|```$/m', '', trim($text));
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false) return null;
    $json = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($json) ? $json : null;
}

/** Ask the model to rewrite the copy. Returns [copy, usedAi]. */
function deck_ai_copy(array $fallback, string $facts, string $kindLabel, array $opts): array
{
    if (!ai_feature('admin')) return [$fallback, false];
    $tone = ['formal' => 'formal e corporativo', 'consultivo' => 'consultivo, próximo e confiante', 'inspirador' => 'inspirador e orientado a resultados'][$opts['tone'] ?? 'consultivo'] ?? 'consultivo, próximo e confiante';
    $system = "Você é redator sênior de apresentações corporativas da Integra Code (desenvolvimento de sistemas sob medida, IA aplicada e suporte para PMEs). "
        . "Escreva em português do Brasil, tom $tone, frases curtas e concretas, sem clichês e sem emojis. "
        . "NUNCA invente números, percentuais, prazos, clientes ou resultados que não estejam nos fatos. Pode usar ganhos qualitativos. "
        . "Responda SOMENTE com um objeto JSON válido, com exatamente as mesmas chaves do modelo e listas de tamanho parecido. Títulos com no máximo 8 palavras; textos de itens com no máximo 22 palavras.\n\n"
        . "Sobre a empresa:\n" . ai_company_context();
    $user = "Tipo de apresentação: $kindLabel\n\nFatos (use apenas estes dados):\n$facts\n"
        . (!empty($opts['instructions']) ? "\nOrientações do vendedor/gestor: " . mb_substr((string)$opts['instructions'], 0, 1200) . "\n" : '')
        . "\nModelo JSON (reescreva os textos para este cliente, mantendo as chaves):\n" . json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    try {
        $out = ai_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'presentation', 2200, 0.5);
        $json = deck_extract_json($out);
        return $json ? [deck_merge_copy($fallback, $json), true] : [$fallback, false];
    } catch (Throwable $e) {
        log_line('ai', 'presentation copy failed', ['error' => $e->getMessage()]);
        return [$fallback, false];
    }
}

/* ============================================================= BUILDERS */

function deck_about_slide(): array
{
    return ['layout' => 'about', 'title' => 'Quem é a Integra Code',
        'lead' => COMPANY['about'],
        'items' => array_map(fn($d) => ['title' => $d[1], 'text' => $d[2]], differentials()),
        'stats' => [['value' => (string)count(methodology()), 'label' => 'etapas em um método transparente'], ['value' => (string)count(segments()), 'label' => 'segmentos com experiência'], ['value' => (string)count(sys_modules()), 'label' => 'módulos no Integra SYS'], ['value' => '100%', 'label' => 'desenvolvimento sob medida']]];
}

function deck_closing(string $title, string $lead, array $steps): array
{
    return ['layout' => 'closing', 'title' => $title, 'lead' => $lead, 'steps' => $steps,
        'contact' => ['phone' => COMPANY['phone'], 'email' => COMPANY['email'], 'site' => COMPANY['domain'], 'whatsapp' => COMPANY['whatsapp']]];
}

/** Returns [facts, fallbackCopy, assembler(copy): slides, title, customerId, leadId, projectId] */
function deck_blueprint(string $kind, array $in): array
{
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['start'] ?? '')) ? $in['start'] : date('Y-m-d', strtotime('-90 days'));
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['end'] ?? '')) ? $in['end'] : today();
    switch ($kind) {
        case 'proposal': return deck_bp_proposal($in);
        case 'results': return deck_bp_results($in, $start, $end, false);
        case 'qbr': return deck_bp_results($in, $start, $end, true);
        case 'kickoff': return deck_bp_kickoff($in);
        case 'institutional': return deck_bp_institutional($in);
        case 'report': return deck_bp_report($in);
    }
    throw new AppException('Tipo de apresentação inválido.');
}

function deck_bp_proposal(array $in): array
{
    $lead = !empty($in['lead_id']) ? db_find('leads', (int)$in['lead_id']) : null;
    $cust = !empty($in['customer_id']) ? db_find('customers', (int)$in['customer_id']) : null;
    $client = trim((string)($in['client_name'] ?? '')) ?: ($lead ? ($lead['company'] ?: $lead['name']) : ($cust ? ($cust['trade_name'] ?: $cust['name']) : 'sua empresa'));
    $svc = services();
    $segKey = (string)($in['segment'] ?? '');
    $seg = segments()[$segKey] ?? null;
    $chosen = array_values(array_filter((array)($in['services'] ?? []), fn($k) => isset($svc[$k])));
    if (!$chosen) $chosen = array_slice(array_keys($svc), 0, 3);
    $payload = $lead && $lead['payload'] ? json_decode((string)$lead['payload'], true) : null;
    $facts = ["Cliente/prospect: $client"];
    if ($seg) $facts[] = 'Segmento: ' . $seg[1] . ' — necessidades típicas: ' . implode(', ', $seg[3]);
    if ($lead) $facts[] = 'Contato: ' . $lead['name'] . ($lead['subject'] ? '. Assunto: ' . $lead['subject'] : '') . ($lead['message'] ? '. Mensagem: ' . mb_substr($lead['message'], 0, 800) : '');
    if (is_array($payload) && !empty($payload['answers'])) {
        $facts[] = 'Diagnóstico digital (maturidade ' . ($payload['score'] ?? '?') . '%): ' . implode('; ', array_map(fn($a) => $a['question'] . ' → ' . ($a['answer'] ?? ''), array_slice($payload['answers'], 0, 10)));
    }
    $facts[] = 'Soluções propostas: ' . implode('; ', array_map(fn($k) => $svc[$k]['title'] . ' (' . implode(', ', $svc[$k]['items']) . ')', $chosen));
    $weeks = max(2, min(52, (int)($in['weeks'] ?? 8)));
    $facts[] = "Prazo estimado: $weeks semanas";
    $items = deck_parse_items((string)($in['investment'] ?? ''));
    $quote = null;
    $monthlyItems = [];
    if (!empty($in['quote_id']) && function_exists('quote_get')) {
        $quote = quote_get((int)$in['quote_id']);
        $sd = 1 - (float)$quote['setup_discount'] / 100;
        $md = 1 - (float)$quote['monthly_discount'] / 100;
        $items = array_values(array_map(fn($l) => ['description' => $l['name'], 'detail' => ($l['qty'] != 1 ? rtrim(rtrim(number_format($l['qty'], 2, ',', ''), '0'), ',') . ' ' . $l['unit'] . ' × ' . money($l['price']) : $l['unit']), 'amount' => round($l['total'] * $sd, 2)], array_filter($quote['items'], fn($l) => !$l['recurring'])));
        $monthlyItems = array_values(array_map(fn($l) => ['description' => $l['name'], 'detail' => ($l['qty'] != 1 ? rtrim(rtrim(number_format($l['qty'], 2, ',', ''), '0'), ',') . ' ' . $l['unit'] : $l['unit']), 'amount' => round($l['monthly'] * $md, 2)], array_filter($quote['items'], fn($l) => $l['recurring'])));
        $facts[] = "Orçamento {$quote['number']}: implantação " . money($quote['totals']['setup']) . ($quote['totals']['installments'] > 1 ? " em {$quote['totals']['installments']}x" : '') . ', mensalidade ' . money($quote['totals']['monthly']) . "/mês, fidelidade de {$quote['contract_months']} meses. Itens: " . implode('; ', array_map(fn($l) => $l['name'], $quote['items']));
        $chosenFromQuote = [];
        foreach ($quote['items'] as $l) {
            if (in_array($l['category'], ['mensalidade', 'licenca'], true)) $chosenFromQuote[] = 'integra-sys';
            if ($l['category'] === 'desenvolvimento') $chosenFromQuote[] = str_contains($l['code'], 'APP') ? 'aplicativos-mobile' : 'sistemas-web';
        }
        $chosen = array_values(array_unique(array_filter(array_merge($chosen, $chosenFromQuote), fn($k) => isset($svc[$k])))) ?: $chosen;
    }
    if ($items && !$quote) $facts[] = 'Investimento: ' . implode('; ', array_map(fn($i) => $i['description'] . ' ' . money($i['amount']), $items));

    $allPains = pains();
    $pains = array_map(fn($i) => ['title' => $allPains[$i][1], 'text' => $allPains[$i][2]], [0, 1, 4, 2]);
    if ($lead && trim((string)$lead['message']) !== '') array_unshift($pains, ['title' => 'O que você nos contou', 'text' => '“' . mb_strimwidth(preg_replace('/\s+/', ' ', trim($lead['message'])), 0, 150, '…') . '”']);
    $pains = array_slice($pains, 0, 4);
    $fallback = [
        'cover_title' => 'Proposta de solução para ' . $client,
        'cover_subtitle' => 'Tecnologia sob medida para organizar processos, ganhar tempo e decidir com dados.',
        'context_lead' => 'O que ouvimos e observamos sobre o momento atual de ' . $client . '.',
        'challenges' => $pains,
        'solution_lead' => 'Uma solução desenhada para a sua operação, não o contrário.',
        'solution_items' => array_map(fn($k) => ['title' => $svc[$k]['title'], 'text' => $svc[$k]['short']], $chosen),
        'before' => ['Informações espalhadas em planilhas e mensagens', 'Retrabalho e digitação duplicada', 'Decisões sem números atualizados', 'Dependência de pessoas-chave'],
        'after' => ['Dados centralizados e acessíveis de qualquer lugar', 'Processos automatizados e rastreáveis', 'Painéis e relatórios em tempo real', 'Operação padronizada e escalável'],
        'benefits' => [['title' => 'Mais tempo para o que importa', 'text' => 'Automação das tarefas repetitivas libera a equipe para vender e atender.'], ['title' => 'Controle e previsibilidade', 'text' => 'Indicadores claros para planejar compras, caixa e metas.'], ['title' => 'Experiência melhor para o cliente', 'text' => 'Atendimento mais rápido, com histórico e acompanhamento.']],
        'next_steps' => ['Validação desta proposta', 'Assinatura e agendamento do kickoff', 'Diagnóstico detalhado dos processos', 'Primeira entrega para validação'],
        'closing_title' => 'Vamos construir isso juntos?',
    ];
    $assemble = function (array $c) use ($client, $weeks, $items, $in, $chosen, $seg, $quote, $monthlyItems) {
        $method = methodology();
        $per = max(1, (int)round($weeks / count($method)));
        $slides = [
            ['layout' => 'cover', 'eyebrow' => 'Proposta comercial', 'title' => $c['cover_title'], 'subtitle' => $c['cover_subtitle'], 'client' => $client, 'date' => deck_date()],
            ['layout' => 'agenda', 'title' => 'Agenda', 'items' => ['Quem somos', 'Cenário e desafios', 'Solução proposta', 'Antes e depois', 'Como vamos trabalhar', 'Investimento', 'Próximos passos']],
            deck_about_slide(),
            ['layout' => 'bullets', 'title' => 'Cenário e desafios', 'lead' => $c['context_lead'], 'items' => $c['challenges'], 'style' => 'pain'],
            ['layout' => 'bullets', 'title' => 'Solução proposta', 'lead' => $c['solution_lead'], 'items' => array_map(fn($it, $k) => $it + (count($c['solution_items']) <= 3 && isset($chosen[$k]) ? ['points' => array_merge(services()[$chosen[$k]]['items'], $k === 0 && $seg ? array_slice($seg[3], 0, 2) : [])] : []), $c['solution_items'], array_keys($c['solution_items']))],
            ['layout' => 'comparison', 'title' => 'Antes e depois', 'left_title' => 'Hoje', 'left' => $c['before'], 'right_title' => 'Com a Integra Code', 'right' => $c['after']],
            ['layout' => 'bullets', 'title' => 'Resultados esperados', 'lead' => 'Ganhos que buscamos juntos desde a primeira entrega.', 'items' => $c['benefits'], 'style' => 'benefit'],
            ['layout' => 'timeline', 'title' => 'Como vamos trabalhar', 'lead' => "Entregas frequentes em cerca de $weeks semanas, com validação em cada etapa.", 'items' => array_map(fn($m, $i) => ['title' => $m[1], 'date' => 'Semana ' . ($i * $per + 1) . ($i === count($method) - 1 ? '+' : '–' . min($weeks, ($i + 1) * $per)), 'text' => $m[2], 'status' => 'next'], $method, array_keys($method))],
        ];
        if ($quote) {
            $t = $quote['totals'];
            $slides[] = ['layout' => 'pricing', 'title' => 'Investimento de implantação', 'lead' => 'Valor único para colocar tudo em funcionamento.', 'items' => $items, 'total' => money($t['setup']),
                'terms' => array_values(array_filter([$t['installments'] > 1 ? "Em {$t['installments']}x de " . money($t['installment_value']) : 'Pagamento na assinatura', $t['setup_discount'] > 0 ? "Inclui {$t['setup_discount']}% de desconto" : null, 'Nota fiscal de serviço a cada pagamento']))];
            $slides[] = ['layout' => 'pricing', 'title' => 'Mensalidade', 'lead' => 'Plano, suporte e licenças — tudo em um valor mensal.', 'items' => $monthlyItems, 'total' => money($t['monthly']) . '/mês',
                'terms' => array_values(array_filter(["Contrato de {$t['contract_months']} meses", 'Primeiro ano completo: ' . money($t['first_year']), $t['monthly_discount'] > 0 ? "Inclui {$t['monthly_discount']}% de desconto" : null, 'Proposta válida até ' . date('d/m/Y', strtotime($quote['valid_until'] ?: '+15 days'))]))];
        } else {
            $slides[] = $items
                ? ['layout' => 'pricing', 'title' => 'Investimento', 'lead' => 'Valores para o escopo apresentado.', 'items' => $items, 'total' => money(array_sum(array_column($items, 'amount'))), 'terms' => deck_lines((string)($in['terms'] ?? '')) ?: ['Pagamento via PIX ou boleto', 'Nota fiscal de serviço emitida a cada pagamento', 'Proposta válida por 15 dias']]
                : ['layout' => 'quote', 'text' => 'O investimento é definido após o diagnóstico detalhado, com escopo, prazos e valores transparentes antes de qualquer compromisso.', 'author' => 'Integra Code'];
        }
        $slides[] = deck_closing($c['closing_title'], 'Próximos passos para começar:', $c['next_steps']);
        return $slides;
    };
    return [implode("\n", $facts), $fallback, $assemble, 'Proposta — ' . $client, $cust['id'] ?? null, $lead['id'] ?? null, null];
}

function deck_bp_results(array $in, string $start, string $end, bool $qbr): array
{
    $cid = (int)($in['customer_id'] ?? 0);
    if (!$cid) throw new AppException('Escolha o cliente.');
    $f = deck_customer_facts($cid, $start, $end);
    $facts = deck_facts_text($f, $start, $end);
    $main = $f['projects'][0] ?? null;
    $pending = [];
    foreach ($f['projects'] as $p) foreach ($p['stages'] as $s) if (!in_array($s['status'], STAGE_FINISHED, true)) $pending[] = $s['name'] . ' — ' . $p['name'];
    $svc = services();
    $fallback = [
        'cover_subtitle' => ($qbr ? 'Revisão estratégica' : 'Resultados') . ' do período de ' . deck_period_label($start, $end) . '.',
        'summary_lead' => 'Os principais números da nossa parceria no período.',
        'deliveries_lead' => $f['delivered'] ? 'Entregas concluídas e validadas no período.' : 'Evolução dos projetos no período.',
        'highlights' => array_values(array_filter([
            $f['delivered'] ? ['title' => count($f['delivered']) . ' etapa(s) entregue(s)', 'text' => 'Avanços concluídos e validados dentro do período.'] : null,
            $f['tickets_total'] ? ['title' => 'Suporte ativo', 'text' => $f['tickets_resolved'] . ' chamado(s) resolvido(s)' . ($f['sla_pct'] !== null ? ", {$f['sla_pct']}% dentro do prazo de resposta." : '.')] : null,
            $main ? ['title' => $main['name'], 'text' => $main['progress'] . '% concluído' . ($main['current_stage'] ? ', na etapa ' . $main['current_stage'] . '.' : '.')] : null,
            ['title' => 'Parceria contínua', 'text' => 'Acompanhamento próximo, com evolução constante das soluções.'],
        ])),
        'next_lead' => 'O que vem a seguir.',
        'next_steps' => array_slice($pending, 0, 5) ?: ['Planejar as próximas evoluções', 'Revisar prioridades com a equipe', 'Agendar a próxima reunião de acompanhamento'],
        'opportunities' => array_map(fn($m) => ['title' => $m[1], 'text' => $m[2]], array_slice(sys_modules(), 0, 3)),
        'closing_title' => 'Obrigado pela parceria!',
    ];
    $assemble = function (array $c) use ($f, $start, $end, $qbr, $main) {
        $kpis = array_values(array_filter([
            $f['projects'] ? ['label' => 'Projetos acompanhados', 'value' => (string)count($f['projects']), 'note' => count(array_filter($f['projects'], fn($p) => in_array($p['status'], PROJECT_RUNNING, true))) . ' em execução'] : null,
            $f['projects'] ? ['label' => 'Etapas entregues', 'value' => (string)count($f['delivered']), 'note' => 'no período'] : null,
            $f['tasks_done'] ? ['label' => 'Tarefas concluídas', 'value' => (string)$f['tasks_done'], 'note' => 'nos projetos'] : null,
            $f['paid'] > 0 ? ['label' => 'Investimento no período', 'value' => money($f['paid']), 'note' => 'pagamentos confirmados'] : null,
            ['label' => 'Chamados resolvidos', 'value' => (string)$f['tickets_resolved'], 'note' => $f['tickets_total'] . ' abertos no período'],
            $f['sla_pct'] !== null ? ['label' => 'Respostas no prazo', 'value' => $f['sla_pct'] . '%', 'note' => 'SLA de 1ª resposta'] : null,
            $f['csat'] !== null ? ['label' => 'Satisfação', 'value' => number_format($f['csat'], 1, ',', '') . '/5', 'note' => 'avaliação dos atendimentos'] : null,
            $f['first_response_h'] !== null ? ['label' => '1ª resposta média', 'value' => number_format($f['first_response_h'], 1, ',', '') . ' h', 'note' => 'tempo médio'] : null,
        ]));
        $slides = [
            ['layout' => 'cover', 'eyebrow' => $qbr ? 'Revisão estratégica' : 'Relatório de resultados', 'title' => $f['name'], 'subtitle' => $c['cover_subtitle'], 'client' => $f['name'], 'date' => deck_date()],
            ['layout' => 'agenda', 'title' => 'Agenda', 'items' => array_values(array_filter(['Resumo do período', $f['projects'] ? 'Projetos e entregas' : null, $f['tickets_total'] ? 'Suporte e atendimento' : null, $qbr && ($f['payments'] || $f['open'] > 0) ? 'Resumo financeiro' : null, 'Destaques', $qbr ? 'Oportunidades' : null, 'Próximos passos']))],
            ['layout' => 'kpis', 'title' => 'Resumo do período', 'lead' => $c['summary_lead'], 'items' => array_slice($kpis, 0, 6)],
        ];
        if ($main) {
            $slides[] = ['layout' => 'timeline', 'title' => $main['name'], 'lead' => $main['progress'] . '% concluído · ' . status_label('project', $main['status']), 'items' => array_map(fn($s) => ['title' => $s['name'], 'date' => $s['completed_at'] ? 'Concluída em ' . date('d/m', strtotime($s['completed_at'])) : ($s['due_date'] ? 'Prazo ' . date('d/m', strtotime($s['due_date'])) : status_label('stage', $s['status'])), 'text' => '', 'status' => in_array($s['status'], STAGE_FINISHED, true) ? 'done' : (in_array($s['status'], STAGE_CURRENT, true) ? 'current' : 'next')], array_slice($main['stages'], 0, 7))];
        }
        if (count($f['projects']) > 1) {
            $slides[] = ['layout' => 'chart', 'title' => 'Progresso dos projetos', 'lead' => 'Percentual concluído de cada projeto.', 'chart' => ['type' => 'hbar', 'labels' => array_column($f['projects'], 'name'), 'datasets' => [['label' => 'Concluído', 'data' => array_column($f['projects'], 'progress'), 'color' => '#00CF81']], 'format' => 'pct'], 'insight' => ''];
        }
        if ($f['delivered']) {
            $slides[] = ['layout' => 'bullets', 'title' => 'Entregas do período', 'lead' => $c['deliveries_lead'], 'items' => array_map(fn($d) => ['title' => $d['name'], 'text' => $d['project'] . ' · concluída em ' . date('d/m/Y', strtotime($d['completed_at']))], array_slice($f['delivered'], 0, 6))];
        }
        if ($f['tickets_total']) {
            $slides[] = ['layout' => 'chart', 'title' => 'Suporte e atendimento', 'lead' => $f['tickets_total'] . ' chamado(s) no período' . ($f['csat'] !== null ? ' · satisfação ' . number_format($f['csat'], 1, ',', '') . '/5' : ''),
                'chart' => ['type' => 'bar', 'labels' => array_map('rpt_month_label', array_keys($f['ticket_months'])), 'datasets' => [['label' => 'Abertos', 'data' => array_column($f['ticket_months'], 'opened'), 'color' => '#0066FE'], ['label' => 'Resolvidos', 'data' => array_column($f['ticket_months'], 'resolved'), 'color' => '#00CF81']], 'format' => 'int'],
                'insight' => $f['sla_pct'] !== null ? "{$f['sla_pct']}% das primeiras respostas dentro do prazo combinado." : ''];
        }
        if ($qbr && ($f['payments'] || $f['open'] > 0)) {
            $rows = array_map(fn($p) => [date('d/m/Y', strtotime($p['paid_at'])), $p['description'], money($p['paid_amount'])], array_slice($f['payments'], -8));
            $slides[] = ['layout' => 'table', 'title' => 'Resumo financeiro', 'lead' => 'Investimento realizado no período: ' . money($f['paid']) . ($f['open'] > 0 ? ' · em aberto: ' . money($f['open']) : ''), 'columns' => ['Data', 'Descrição', 'Valor'], 'rows' => $rows ?: [['—', 'Nenhum pagamento no período', '—']]];
        }
        $slides[] = ['layout' => 'bullets', 'title' => 'Destaques', 'lead' => 'O que mais marcou o período.', 'items' => $c['highlights'], 'style' => 'benefit'];
        if ($qbr) $slides[] = ['layout' => 'bullets', 'title' => 'Oportunidades para evoluir', 'lead' => 'Sugestões para o próximo trimestre.', 'items' => $c['opportunities']];
        $slides[] = deck_closing($c['closing_title'], $c['next_lead'], $c['next_steps']);
        return $slides;
    };
    return [$facts, $fallback, $assemble, ($qbr ? 'Revisão estratégica — ' : 'Resultados — ') . $f['name'], $cid, null, $main['id'] ?? null];
}

function deck_bp_kickoff(array $in): array
{
    $pid = (int)($in['project_id'] ?? 0);
    $p = $pid ? db_one('SELECT p.*, c.name AS customer_name, c.trade_name FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]) : null;
    if (!$p) throw new AppException('Escolha o projeto.');
    $stages = db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$pid]);
    $tasks = db_all('SELECT stage_id, title FROM project_tasks WHERE project_id = ? AND client_visible = 1 ORDER BY position', [$pid]);
    $client = $p['trade_name'] ?: ($p['customer_name'] ?: 'Cliente');
    $facts = "Projeto: {$p['name']} para $client. Tipo: " . ($p['project_type'] ?: '—') . '. Responsável: ' . ($p['manager'] ?: '—') . '. Início: ' . ($p['start_date'] ? date('d/m/Y', strtotime($p['start_date'])) : 'a definir') . '. Prazo: ' . ($p['due_date'] ? date('d/m/Y', strtotime($p['due_date'])) : 'a definir')
        . "\nEscopo/descrição: " . mb_substr((string)$p['description'], 0, 1500) . "\nEtapas: " . implode('; ', array_map(fn($s) => $s['name'] . ' (tarefas: ' . implode(', ', array_column(array_filter($tasks, fn($t) => (int)$t['stage_id'] === (int)$s['id']), 'title')) . ')', $stages));
    $fallback = [
        'cover_subtitle' => 'Alinhamento de objetivos, escopo, cronograma e forma de trabalho.',
        'objectives' => [['title' => 'Organizar a operação', 'text' => 'Centralizar informações e processos em uma solução única.'], ['title' => 'Ganhar produtividade', 'text' => 'Automatizar tarefas repetitivas e reduzir retrabalho.'], ['title' => 'Decidir com dados', 'text' => 'Ter indicadores confiáveis e atualizados.']],
        'scope_lead' => 'O que está incluído nesta fase do projeto.',
        'scope' => array_slice(array_map(fn($t) => ['title' => $t['title'], 'text' => ''], $tasks), 0, 6) ?: [['title' => $p['name'], 'text' => mb_substr((string)$p['description'], 0, 200)]],
        'integra_roles' => ['Conduzir as etapas e o cronograma', 'Desenvolver, testar e implantar', 'Treinar a equipe', 'Dar suporte pelos canais oficiais'],
        'client_roles' => ['Indicar um ponto focal com poder de decisão', 'Fornecer informações e acessos necessários', 'Validar as entregas de cada etapa', 'Participar das reuniões de acompanhamento'],
        'communication' => [['title' => 'Área do Cliente', 'text' => 'Acompanhamento das etapas, aprovações, arquivos e faturas em um só lugar.'], ['title' => 'Chamados com protocolo', 'text' => 'Dúvidas e ajustes registrados, com prazo de resposta.'], ['title' => 'Reuniões de acompanhamento', 'text' => 'Encontros curtos e periódicos para revisar avanços e prioridades.'], ['title' => 'WhatsApp', 'text' => 'Canal rápido para recados do dia a dia: ' . COMPANY['phone'] . '.']],
        'next_steps' => ['Confirmar o ponto focal e os acessos', 'Agendar a primeira reunião de diagnóstico', 'Liberar o acesso à Área do Cliente'],
        'closing_title' => 'Vamos começar!',
    ];
    $assemble = function (array $c) use ($p, $client, $stages) {
        return [
            ['layout' => 'cover', 'eyebrow' => 'Kickoff do projeto', 'title' => $p['name'], 'subtitle' => $c['cover_subtitle'], 'client' => $client, 'date' => deck_date()],
            ['layout' => 'agenda', 'title' => 'Agenda', 'items' => ['Objetivos', 'Escopo', 'Cronograma', 'Papéis e responsabilidades', 'Comunicação', 'Próximos passos']],
            ['layout' => 'bullets', 'title' => 'Objetivos do projeto', 'lead' => 'Onde queremos chegar juntos.', 'items' => $c['objectives'], 'style' => 'benefit'],
            ['layout' => 'bullets', 'title' => 'Escopo', 'lead' => $c['scope_lead'], 'items' => $c['scope']],
            ['layout' => 'timeline', 'title' => 'Cronograma', 'lead' => ($p['start_date'] ? 'Início em ' . date('d/m/Y', strtotime($p['start_date'])) : 'Início após o kickoff') . ($p['due_date'] ? ' · entrega prevista em ' . date('d/m/Y', strtotime($p['due_date'])) : ''),
                'items' => array_map(fn($s) => ['title' => $s['name'], 'date' => $s['due_date'] ? 'Até ' . date('d/m', strtotime($s['due_date'])) : '', 'text' => '', 'status' => $s['status'] === 'done' ? 'done' : ($s['status'] === 'in_progress' ? 'current' : 'next')], array_slice($stages, 0, 7))],
            ['layout' => 'comparison', 'title' => 'Papéis e responsabilidades', 'left_title' => 'Integra Code', 'left' => $c['integra_roles'], 'right_title' => $client, 'right' => $c['client_roles']],
            ['layout' => 'bullets', 'title' => 'Como vamos nos comunicar', 'lead' => 'Canais e ritos do projeto.', 'items' => $c['communication']],
            deck_closing($c['closing_title'], 'Próximos passos:', $c['next_steps']),
        ];
    };
    return [$facts, $fallback, $assemble, 'Kickoff — ' . $p['name'], $p['customer_id'] ? (int)$p['customer_id'] : null, null, $pid];
}

function deck_bp_institutional(array $in): array
{
    $client = trim((string)($in['client_name'] ?? ''));
    $facts = 'Apresentação institucional' . ($client ? " para $client" : '') . '.';
    $fallback = [
        'cover_title' => COMPANY['tagline'],
        'cover_subtitle' => COMPANY['pitch'],
        'pains_lead' => 'Dores comuns que resolvemos todos os dias.',
        'closing_title' => 'Vamos conversar sobre o seu negócio?',
        'next_steps' => ['Diagnóstico gratuito em ' . COMPANY['domain'] . '/diagnostico', 'Reunião para entender seus processos', 'Proposta sob medida'],
    ];
    $assemble = function (array $c) use ($client) {
        return [
            ['layout' => 'cover', 'eyebrow' => 'Apresentação institucional', 'title' => $c['cover_title'], 'subtitle' => $c['cover_subtitle'], 'client' => $client, 'date' => deck_date()],
            deck_about_slide(),
            ['layout' => 'bullets', 'title' => 'Problemas que resolvemos', 'lead' => $c['pains_lead'], 'items' => array_map(fn($p) => ['title' => $p[1], 'text' => $p[2]], array_slice(pains(), 0, 6)), 'style' => 'pain'],
            ['layout' => 'bullets', 'title' => 'Soluções', 'lead' => 'Frentes de atuação da Integra Code.', 'items' => array_map(fn($s) => ['title' => $s['title'], 'text' => $s['short']], array_slice(array_values(services()), 0, 6))],
            ['layout' => 'bullets', 'title' => 'Segmentos', 'lead' => 'Experiência prática em operações como a sua.', 'items' => array_map(fn($s) => ['title' => $s[1], 'text' => $s[2]], array_slice(array_values(segments()), 0, 8))],
            ['layout' => 'bullets', 'title' => 'Integra SYS', 'lead' => 'Nosso sistema de gestão modular.', 'items' => array_map(fn($m) => ['title' => $m[1], 'text' => $m[2]], sys_modules())],
            ['layout' => 'timeline', 'title' => 'Nosso método', 'lead' => 'Transparência do diagnóstico à evolução contínua.', 'items' => array_map(fn($m) => ['title' => $m[1], 'date' => $m[0], 'text' => $m[2], 'status' => 'next'], methodology())],
            deck_closing($c['closing_title'], 'Como começar:', $c['next_steps']),
        ];
    };
    return [$facts, $fallback, $assemble, 'Institucional' . ($client ? ' — ' . $client : ' — Integra Code'), null, null, null];
}

function deck_bp_report(array $in): array
{
    $key = (string)($in['report'] ?? '');
    $r = $key === 'builder' ? report_build((array)($in['config'] ?? []), current_user()) : report_run($key, $in);
    $facts = report_to_text($r, 15);
    $ins = report_insights($r);
    $parsed = deck_parse_insight($ins['text']);
    $fallback = ['cover_subtitle' => $r['subtitle'], 'summary' => $parsed['summary'], 'positives' => $parsed['positives'] ?: ['Indicadores acompanhados de forma consistente'], 'attention' => $parsed['attention'] ?: ['Acompanhar a evolução no próximo período'], 'actions' => $parsed['actions'] ?: ['Definir responsáveis para as ações prioritárias', 'Revisar os números no próximo fechamento']];
    $fmt = fn($k) => deck_fmt_value($k['value'], $k['format']);
    $assemble = function (array $c) use ($r, $fmt) {
        $slides = [
            ['layout' => 'cover', 'eyebrow' => 'Relatório gerencial', 'title' => $r['title'], 'subtitle' => $c['cover_subtitle'], 'client' => 'Integra Code', 'date' => deck_date()],
            ['layout' => 'kpis', 'title' => 'Indicadores', 'lead' => $c['summary'], 'items' => array_map(fn($k) => ['label' => $k['label'], 'value' => $fmt($k), 'note' => $k['delta'] !== null ? (($k['delta'] >= 0 ? '▲ ' : '▼ ') . number_format(abs($k['delta']), 1, ',', '.') . ($k['format'] === 'pct' ? ' p.p.' : '%') . ' vs anterior') : ($k['hint'] ?? ''), 'trend' => $k['delta'] === null ? '' : ((($k['delta'] >= 0) === ($k['good'] === 'up')) ? 'up' : 'down')], array_slice($r['kpis'], 0, 6))],
        ];
        foreach (array_slice($r['charts'], 0, 3) as $ch) $slides[] = ['layout' => 'chart', 'title' => $ch['title'], 'lead' => '', 'chart' => ['type' => $ch['type'], 'labels' => array_slice($ch['labels'], 0, 24), 'datasets' => array_map(fn($d) => $d + ['data' => array_slice($d['data'], 0, 24)], $ch['datasets']), 'format' => $ch['format']], 'insight' => ''];
        $slides[] = ['layout' => 'comparison', 'style' => 'analysis', 'title' => 'Análise', 'left_title' => 'Destaques', 'left' => $c['positives'], 'right_title' => 'Pontos de atenção', 'right' => $c['attention']];
        if ($r['tables'] && $r['tables'][0]['rows']) {
            $t = $r['tables'][0];
            $cols = array_slice($t['columns'], 0, 6);
            $slides[] = ['layout' => 'table', 'title' => $t['title'], 'lead' => '', 'columns' => array_column($cols, 'label'), 'rows' => array_map(fn($row) => array_map(fn($col) => deck_fmt_value($row[$col['key']] ?? null, $col['format']), $cols), array_slice($t['rows'], 0, 9))];
        }
        $slides[] = deck_closing('Plano de ação', 'Recomendações para o próximo período:', $c['actions']);
        return $slides;
    };
    return [$facts, $fallback, $assemble, $r['title'] . ' — ' . date('m/Y'), null, null, null];
}

/* ================================================================ HELPERS */

function deck_fmt_value($v, string $f): string
{
    if ($v === null || $v === '') return '—';
    return match ($f) {
        'money' => money($v), 'pct' => number_format((float)$v, 1, ',', '.') . '%', 'hours' => number_format((float)$v, 1, ',', '.') . ' h', 'days' => (int)$v . ' d',
        'int' => number_format((float)$v, 0, ',', '.'), 'decimal' => number_format((float)$v, 2, ',', '.'), 'date' => date('d/m/Y', strtotime((string)$v)), default => (string)$v,
    };
}

/** "Descrição | 1.500,00" per line → [{description, detail, amount}] */
function deck_parse_items(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if ($parts[0] === '') continue;
        $amount = (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d.,-]/', '', end($parts)));
        $out[] = ['description' => mb_substr($parts[0], 0, 120), 'detail' => count($parts) > 2 ? mb_substr($parts[1], 0, 160) : '', 'amount' => $amount];
    }
    return array_slice($out, 0, 10);
}

function deck_lines(string $raw): array
{
    return array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 200), preg_split('/\r?\n/', $raw))));
}

/** Split the insight text (bold headers + bullets) into sections for slides. */
function deck_parse_insight(string $text): array
{
    $sec = 'summary';
    $out = ['summary' => '', 'positives' => [], 'attention' => [], 'actions' => []];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $l = trim($line);
        if ($l === '') continue;
        $h = mb_strtolower(trim($l, '*# :'));
        if (preg_match('/^\*\*|^#/', $l) || mb_strlen($h) < 40 && !str_starts_with($l, '-')) {
            if (str_contains($h, 'positiv') || str_contains($h, 'destaque')) { $sec = 'positives'; continue; }
            if (str_contains($h, 'aten') || str_contains($h, 'risco')) { $sec = 'attention'; continue; }
            if (str_contains($h, 'recomend') || str_contains($h, 'próximos') || str_contains($h, 'ação') || str_contains($h, 'passos')) { $sec = 'actions'; continue; }
            if (str_contains($h, 'resumo')) { $sec = 'summary'; continue; }
        }
        $clean = trim(preg_replace('/^[-•*]\s*|\*\*/', '', $l));
        if ($sec === 'summary') $out['summary'] = trim($out['summary'] . ' ' . $clean);
        elseif (count($out[$sec]) < 6) $out[$sec][] = mb_substr($clean, 0, 200);
    }
    $out['summary'] = mb_substr($out['summary'], 0, 300);
    return $out;
}

/* ================================================================ SERVICE */

function presentation_generate(string $kind, array $in, array $user): array
{
    if (!isset(DECK_KINDS[$kind])) throw new AppException('Tipo de apresentação inválido.');
    [$facts, $fallback, $assemble, $title, $cid, $lid, $pid] = deck_blueprint($kind, $in);
    [$copy, $usedAi] = !empty($in['use_ai']) || !array_key_exists('use_ai', $in) ? deck_ai_copy($fallback, $facts, DECK_KINDS[$kind][0], $in) : [$fallback, false];
    $slides = $assemble($copy);
    $id = db_insert('presentations', [
        'title' => mb_substr(trim((string)($in['title'] ?? '')) ?: $title, 0, 200), 'kind' => $kind,
        'customer_id' => $cid, 'lead_id' => $lid, 'project_id' => $pid,
        'theme' => in_array($in['theme'] ?? '', ['dark', 'light', 'brand'], true) ? $in['theme'] : 'dark',
        'slides' => json_encode($slides, JSON_UNESCAPED_UNICODE), 'brief' => mb_substr(json_encode(array_intersect_key($in, array_flip(['lead_id', 'customer_id', 'project_id', 'quote_id', 'start', 'end', 'services', 'segment', 'weeks', 'tone', 'instructions', 'report', 'client_name'])), JSON_UNESCAPED_UNICODE), 0, 4000),
        'share_token' => bin2hex(random_bytes(16)), 'shared' => 0, 'views' => 0, 'ai_generated' => $usedAi ? 1 : 0,
        'created_by' => $user['name'] ?? null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($lid) activity_add('lead', $lid, 'event', 'Apresentação criada: ' . DECK_KINDS[$kind][0], null, $user);
    if ($cid) activity_add('customer', $cid, 'event', 'Apresentação criada: ' . DECK_KINDS[$kind][0], null, $user);
    audit('create', 'presentation', $id, ['kind' => $kind, 'ai' => $usedAi]);
    return ['id' => $id, 'ai' => $usedAi];
}

/** Validate/sanitize slides coming from the editor. */
function presentation_clean_slides($slides): array
{
    if (!is_array($slides)) throw new AppException('Slides inválidos.');
    $clean = function ($v, int $depth = 0) use (&$clean) {
        if ($depth > 5) return null;
        if (is_array($v)) { $o = []; foreach (array_slice($v, 0, 60, true) as $k => $x) $o[is_int($k) ? $k : mb_substr((string)$k, 0, 40)] = $clean($x, $depth + 1); return $o; }
        if (is_numeric($v) && !is_string($v)) return $v;
        return mb_substr(strip_tags((string)$v), 0, 2000);
    };
    $out = [];
    foreach (array_slice(array_values($slides), 0, 60) as $s) {
        if (!is_array($s) || !in_array($s['layout'] ?? '', DECK_LAYOUTS, true)) continue;
        $out[] = $clean($s);
    }
    if (!$out) throw new AppException('A apresentação precisa de pelo menos um slide.');
    return $out;
}

/** Rewrite one slide's texts with AI following an instruction (numbers preserved by the prompt + merge). */
function presentation_ai_slide(array $slide, string $instruction, string $context): array
{
    if (!ai_feature('admin')) throw new AppException('A IA não está configurada. Configure a Cloudflare em Configurações → Inteligência Artificial.');
    $out = ai_chat([
        ['role' => 'system', 'content' => 'Você edita slides corporativos da Integra Code em português do Brasil. Reescreva SOMENTE os textos do slide seguindo a instrução. Mantenha o mesmo JSON (mesmas chaves e "layout"), não altere números nem dados de gráficos/tabelas. Responda apenas com o JSON do slide.'],
        ['role' => 'user', 'content' => "Contexto da apresentação: $context\nInstrução: " . mb_substr($instruction, 0, 800) . "\nSlide:\n" . json_encode($slide, JSON_UNESCAPED_UNICODE)],
    ], 'presentation_slide', 1400, 0.5);
    $json = deck_extract_json($out);
    if (!$json) throw new AppException('A IA não devolveu um slide válido. Tente reformular a instrução.');
    $merged = $slide;
    foreach ($slide as $k => $v) {
        if (in_array($k, ['layout', 'chart', 'columns', 'rows', 'contact', 'stats', 'total'], true) || !array_key_exists($k, $json)) continue;
        if (is_string($v) && is_string($json[$k])) $merged[$k] = mb_substr(strip_tags($json[$k]), 0, 600);
        if (is_array($v) && is_array($json[$k])) $merged[$k] = deck_merge_copy([$k => $v], [$k => $json[$k]])[$k];
    }
    return $merged;
}
