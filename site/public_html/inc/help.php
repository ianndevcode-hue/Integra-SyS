<?php
declare(strict_types=1);

/**
 * Help center search and rule-based chatbot (FAQ retrieval + intents).
 */

function normalize_text(string $text): string
{
    $text = mb_strtolower($text);
    $text = strip_accents($text);
    return preg_replace('/[^a-z0-9 ]+/', ' ', $text);
}

function tokenize(string $text): array
{
    static $stop = ['a','o','e','de','da','do','das','dos','em','um','uma','para','por','com','como','que','qual','quais','se','no','na','nos','nas','eu','meu','minha','voces','voce','vcs','e','ou','os','as','ao','sao','ser','tem','ter','posso','pode','sobre','mais','muito','isso','esse','essa','ja','nao','sim'];
    $tokens = array_filter(explode(' ', normalize_text($text)), fn($t) => strlen($t) > 1 && !in_array($t, $stop, true));
    return array_values(array_unique($tokens));
}

function help_search(string $q, string $category = '', int $limit = 10): array
{
    $params = [];
    $sql = 'SELECT id, category, question, answer, keywords, views, helpful FROM help_articles WHERE published = 1';
    if ($category !== '') { $sql .= ' AND category = ?'; $params[] = $category; }
    $rows = db_all($sql . ' ORDER BY position, id', $params);
    if ($q === '') return array_slice($rows, 0, $limit * 5);

    $tokens = tokenize($q);
    if (!$tokens) return [];
    $scored = [];
    foreach ($rows as $row) {
        $question = normalize_text($row['question']);
        $keywords = normalize_text((string)$row['keywords']);
        $answer = normalize_text($row['answer']);
        $score = 0;
        foreach ($tokens as $t) {
            $stem = strlen($t) > 4 ? substr($t, 0, -1) : $t; // crude plural/gender tolerance
            if (strpos($question, $stem) !== false) $score += 3;
            if (strpos($keywords, $stem) !== false) $score += 2;
            if (strpos($answer, $stem) !== false) $score += 1;
        }
        if ($score > 0) $scored[] = $row + ['score' => $score];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

function chatbot_reply(string $message): array
{
    $n = normalize_text($message);
    $actions = [];

    $intents = [
        'greeting' => '/^(oi|ola|bom dia|boa tarde|boa noite|e ai|hey|opa)\b/',
        'human' => '/(humano|atendente|pessoa|falar com alguem|whatsapp|zap|telefone|ligar)/',
        'price' => '/(preco|valor|quanto custa|orcamento|investimento|custo)/',
        'schedule' => '/(agendar|agenda|reuniao|marcar|apresentacao|demonstracao)/',
        'ticket' => '/(chamado|ticket|protocolo|erro|bug|problema|nao funciona|travou|suporte)/',
        'hours' => '/(horario|funcionamento|abre|fecha|atendem)/',
        'diagnostic' => '/(diagnostico|avaliacao|analise gratuita)/',
    ];
    $intent = null;
    foreach ($intents as $name => $re) {
        if (preg_match($re, $n)) { $intent = $name; break; }
    }

    $wa = ['label' => 'Falar no WhatsApp', 'url' => COMPANY['whatsapp']];
    switch ($intent) {
        case 'greeting':
            return ['reply' => 'Olá! 👋 Sou o assistente virtual da Integra Code. Posso tirar dúvidas sobre nossos serviços, prazos, pagamentos e suporte. Como posso ajudar?',
                'actions' => [['label' => 'Ver soluções', 'url' => '/solucoes'], ['label' => 'Agendar conversa', 'url' => '/agendar'], ['label' => 'Abrir chamado', 'url' => '/suporte#chamado']]];
        case 'human':
            return ['reply' => 'Claro! Nossa equipe atende de ' . COMPANY['hours'] . '. Você pode falar direto conosco pelo WhatsApp ' . COMPANY['phone'] . ' ou pelo e-mail ' . COMPANY['email'] . '.',
                'actions' => [$wa, ['label' => 'Enviar e-mail', 'url' => 'mailto:' . COMPANY['email']]]];
        case 'price':
            return ['reply' => 'Cada projeto é feito sob medida, então o investimento depende do escopo. O primeiro passo é um diagnóstico gratuito: entendemos sua necessidade e enviamos uma proposta detalhada, sem compromisso.',
                'actions' => [['label' => 'Fazer diagnóstico online', 'url' => '/diagnostico'], ['label' => 'Agendar conversa', 'url' => '/agendar'], $wa]];
        case 'schedule':
            return ['reply' => 'Você pode escolher o melhor dia e horário na nossa agenda online. Atendemos de segunda a sexta, das 8h às 18h, por vídeo, telefone ou presencialmente.',
                'actions' => [['label' => 'Abrir agenda', 'url' => '/agendar']]];
        case 'ticket':
            $actions = [['label' => 'Abrir chamado', 'url' => '/suporte#chamado'], ['label' => 'Consultar protocolo', 'url' => '/suporte#consultar']];
            break;
        case 'hours':
            return ['reply' => 'Nosso horário de atendimento é ' . COMPANY['hours'] . '. Clientes com SLA contam com monitoramento 24/7.', 'actions' => [$wa]];
        case 'diagnostic':
            return ['reply' => 'Temos um diagnóstico online de 2 minutos que avalia a maturidade digital da sua empresa e indica por onde começar. Quer fazer agora?',
                'actions' => [['label' => 'Fazer diagnóstico', 'url' => '/diagnostico']]];
    }

    $results = help_search($message, '', 3);
    if ($results && $results[0]['score'] >= 3) {
        $top = $results[0];
        $related = array_map(fn($r) => ['label' => $r['question'], 'ask' => $r['question']], array_slice($results, 1, 2));
        return ['reply' => $top['answer'], 'source' => $top['question'], 'actions' => array_merge($actions, $related)];
    }
    if ($intent === 'ticket') {
        return ['reply' => 'Sinto muito pelo problema! Para que nossa equipe técnica analise, abra um chamado: você recebe um protocolo e acompanha a resposta pela Central de Ajuda.', 'actions' => $actions];
    }
    return ['reply' => 'Hmm, não encontrei uma resposta exata para isso. Posso te encaminhar para a nossa equipe ou você pode explorar a Central de Ajuda.',
        'actions' => [$wa, ['label' => 'Central de Ajuda', 'url' => '/suporte'], ['label' => 'Abrir chamado', 'url' => '/suporte#chamado']]];
}
