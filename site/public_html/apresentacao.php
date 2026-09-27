<?php
declare(strict_types=1);

/**
 * Presentation viewer (16:9 web deck + print/PDF). Access:
 *   /apresentacao?t=TOKEN   → public when the deck is shared (views are counted), otherwise staff only
 *   /apresentacao?id=ID     → staff only (editor preview)
 *   &print=1                → every slide stacked for "Salvar como PDF"
 */

require __DIR__ . '/inc/bootstrap.php';
require_once INC_PATH . '/content.php';
require_once INC_PATH . '/icons.php';

header('X-Robots-Tag: noindex, nofollow');
$staff = current_user();
$deck = null;
if (!empty($_GET['t']) && preg_match('/^[a-f0-9]{32}$/', (string)$_GET['t'])) {
    $deck = db_one('SELECT * FROM presentations WHERE share_token = ?', [$_GET['t']]);
    if ($deck && !(int)$deck['shared'] && !$staff) $deck = null;
} elseif (!empty($_GET['id']) && $staff && can('customers', $staff)) {
    $deck = db_find('presentations', (int)$_GET['id']);
}
if (!$deck) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Apresentação indisponível</title><body style="font-family:system-ui;background:#050a16;color:#e9edf5;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center"><div><h1 style="font-size:1.4rem">Apresentação indisponível</h1><p style="color:#8591a8">O link pode ter expirado ou o compartilhamento foi desativado.</p></div>';
    exit;
}
if (!$staff && !isset($_GET['print']) && !isset($_GET['embed'])) {
    db_exec('UPDATE presentations SET views = views + 1, last_viewed_at = ? WHERE id = ?', [now(), $deck['id']]);
}
$slides = json_decode((string)$deck['slides'], true) ?: [];
$theme = in_array($deck['theme'], ['dark', 'light', 'brand'], true) ? $deck['theme'] : 'dark';
$print = isset($_GET['print']);
$embed = isset($_GET['embed']);
$clientName = '';
foreach ($slides as $s) if (($s['layout'] ?? '') === 'cover' && !empty($s['client'])) { $clientName = (string)$s['client']; break; }
$total = count($slides);

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** Allow **bold** highlight → gradient text. */
$rich = fn($v) => preg_replace('/\*\*(.+?)\*\*/u', '<em class="hl">$1</em>', $h($v));
$list = fn($v) => is_array($v) ? array_values(array_filter($v, fn($x) => $x !== '' && $x !== null)) : [];

function deck_chrome(int $n, int $total, string $client, callable $h): string
{
    return '<header class="sl-head"><span class="sl-brand">' . brand_mark('sl-mark') . brand_wordmark('sl-wm') . '</span></header>'
        . '<footer class="sl-foot"><span>' . $h(COMPANY['domain']) . '</span>' . ($client !== '' ? '<span>' . $h($client) . '</span>' : '<span></span>') . '<span class="sl-num">' . sprintf('%02d', $n) . ' / ' . sprintf('%02d', $total) . '</span></footer>';
}

function deck_slide_html(array $s, int $n, int $total, string $client, callable $h, callable $rich, callable $list): string
{
    $layout = $s['layout'] ?? 'bullets';
    $title = '<h2 class="sl-title">' . $rich($s['title'] ?? '') . '</h2>' . (!empty($s['lead']) ? '<p class="sl-lead">' . $rich($s['lead']) . '</p>' : '');
    $chrome = deck_chrome($n, $total, $client, $h);
    switch ($layout) {
        case 'cover':
            return '<div class="cover-art" aria-hidden="true">' . brand_mark('cover-mark') . '</div>'
                . '<div class="cover-body">' . (!empty($s['eyebrow']) ? '<span class="eyebrow">' . $h($s['eyebrow']) . '</span>' : '')
                . '<h1>' . $rich($s['title'] ?? '') . '</h1>' . (!empty($s['subtitle']) ? '<p class="cover-sub">' . $rich($s['subtitle']) . '</p>' : '')
                . '<div class="cover-meta">' . (!empty($s['client']) ? '<div><small>Preparado para</small><b>' . $h($s['client']) . '</b></div>' : '') . (!empty($s['date']) ? '<div><small>Data</small><b>' . $h($s['date']) . '</b></div>' : '') . '</div></div>'
                . '<div class="cover-foot">' . brand_mark('sl-mark') . brand_wordmark('cover-wm') . '<span>' . $h(COMPANY['domain']) . '</span></div>';
        case 'agenda':
            $items = $list($s['items'] ?? []);
            return $chrome . $title . '<ol class="agenda ' . (count($items) > 5 ? 'two' : '') . '">' . implode('', array_map(fn($i, $k) => '<li><span class="ag-n">' . sprintf('%02d', $k + 1) . '</span><span>' . $h($i) . '</span></li>', $items, array_keys($items))) . '</ol>';
        case 'section':
            return $chrome . '<div class="section-body"><span class="sec-n">' . $h($s['number'] ?? '') . '</span><h2>' . $rich($s['title'] ?? '') . '</h2>' . (!empty($s['subtitle']) ? '<p>' . $rich($s['subtitle']) . '</p>' : '') . '</div>';
        case 'bullets':
            $items = $list($s['items'] ?? []);
            $style = $s['style'] ?? '';
            $ico = $style === 'pain' ? 'x' : ($style === 'benefit' ? 'check' : null);
            $cols = count($items) === 1 ? 'c1' : (count($items) <= 2 ? 'c2' : (count($items) === 4 ? 'c2' : (count($items) <= 6 ? 'c3' : 'c4')));
            return $chrome . $title . '<div class="cards ' . $cols . ' ' . $h($style) . '">' . implode('', array_map(function ($i, $k) use ($h, $rich, $ico) {
                $t = is_array($i) ? ($i['title'] ?? '') : $i;
                $x = is_array($i) ? ($i['text'] ?? '') : '';
                $pts = is_array($i) && !empty($i['points']) && is_array($i['points']) ? '<ul class="points">' . implode('', array_map(fn($pt) => '<li>' . icon('check') . $h($pt) . '</li>', array_slice($i['points'], 0, 8))) . '</ul>' : '';
                return '<article class="card"><span class="card-ico">' . ($ico ? icon($ico) : sprintf('%02d', $k + 1)) . '</span><h3>' . $rich($t) . '</h3>' . ($x !== '' ? '<p>' . $rich($x) . '</p>' : '') . $pts . '</article>';
            }, $items, array_keys($items))) . '</div>';
        case 'kpis':
            $items = $list($s['items'] ?? []);
            return $chrome . $title . '<div class="kpis k' . min(6, max(1, count($items))) . '">' . implode('', array_map(fn($k) => '<div class="kpi ' . $h($k['trend'] ?? '') . '"><b>' . $h($k['value'] ?? '') . '</b><span>' . $h($k['label'] ?? '') . '</span>' . (!empty($k['note']) ? '<small>' . $h($k['note']) . '</small>' : '') . '</div>', $items)) . '</div>';
        case 'chart':
            $chart = $s['chart'] ?? [];
            return $chrome . $title . '<div class="chart-wrap ' . (!empty($s['insight']) ? 'with-note' : '') . '"><div class="chart-box"><canvas data-chart="' . $h(json_encode($chart, JSON_UNESCAPED_UNICODE)) . '"></canvas></div>'
                . (!empty($s['insight']) ? '<aside class="chart-note">' . icon('sparkles') . '<p>' . $rich($s['insight']) . '</p></aside>' : '') . '</div>';
        case 'timeline':
            $items = $list($s['items'] ?? []);
            return $chrome . $title . '<ol class="tl n' . min(7, count($items)) . '">' . implode('', array_map(fn($i) => '<li class="' . $h($i['status'] ?? 'next') . '"><span class="tl-dot">' . (($i['status'] ?? '') === 'done' ? icon('check') : '') . '</span><small>' . $h($i['date'] ?? '') . '</small><h3>' . $h($i['title'] ?? '') . '</h3>' . (!empty($i['text']) ? '<p>' . $h($i['text']) . '</p>' : '') . '</li>', $items)) . '</ol>';
        case 'comparison':
            $side = fn($items, $ico) => '<ul>' . implode('', array_map(fn($x) => '<li>' . icon($ico) . '<span>' . $rich(is_array($x) ? ($x['title'] ?? '') : $x) . '</span></li>', $list($items))) . '</ul>';
            if (($s['style'] ?? '') === 'analysis') {
                return $chrome . $title . '<div class="compare analysis"><div class="cmp-col good"><h3>' . icon('check') . $h($s['left_title'] ?? 'Destaques') . '</h3>' . $side($s['left'] ?? [], 'check') . '</div><div class="cmp-col warn"><h3>' . icon('bolt') . $h($s['right_title'] ?? 'Atenção') . '</h3>' . $side($s['right'] ?? [], 'arrow') . '</div></div>';
            }
            return $chrome . $title . '<div class="compare"><div class="cmp-col before"><h3>' . $h($s['left_title'] ?? 'Antes') . '</h3>' . $side($s['left'] ?? [], 'x') . '</div><div class="cmp-arrow">' . icon('arrow') . '</div><div class="cmp-col after"><h3>' . $h($s['right_title'] ?? 'Depois') . '</h3>' . $side($s['right'] ?? [], 'check') . '</div></div>';
        case 'pricing':
            $items = $list($s['items'] ?? []);
            return $chrome . $title . '<div class="pricing"><table><tbody>' . implode('', array_map(fn($i) => '<tr><td><b>' . $h($i['description'] ?? '') . '</b>' . (!empty($i['detail']) ? '<small>' . $h($i['detail']) . '</small>' : '') . '</td><td class="amt">' . (is_numeric($i['amount'] ?? null) ? money($i['amount']) : $h($i['amount'] ?? '')) . '</td></tr>', $items)) . '</tbody></table>'
                . '<aside class="total"><small>Investimento total</small><b>' . $h($s['total'] ?? '') . '</b><ul>' . implode('', array_map(fn($t) => '<li>' . icon('check') . $h($t) . '</li>', $list($s['terms'] ?? []))) . '</ul></aside></div>';
        case 'quote':
            return $chrome . '<blockquote class="quote"><span class="q-mark">“</span><p>' . $rich($s['text'] ?? '') . '</p>' . (!empty($s['author']) ? '<cite>— ' . $h($s['author']) . '</cite>' : '') . '</blockquote>';
        case 'about':
            return $chrome . '<div class="about"><div class="about-l"><h2 class="sl-title">' . $rich($s['title'] ?? '') . '</h2><p class="sl-lead">' . $rich($s['lead'] ?? '') . '</p><div class="stats">'
                . implode('', array_map(fn($x) => '<div><b>' . $h($x['value'] ?? '') . '</b><span>' . $h($x['label'] ?? '') . '</span></div>', $list($s['stats'] ?? []))) . '</div></div>'
                . '<ul class="about-r">' . implode('', array_map(fn($i) => '<li>' . icon('check') . '<div><b>' . $h($i['title'] ?? '') . '</b><span>' . $h($i['text'] ?? '') . '</span></div></li>', $list($s['items'] ?? []))) . '</ul></div>';
        case 'table':
            $cols = $list($s['columns'] ?? []);
            return $chrome . $title . '<div class="dtable"><table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . $h($c) . '</th>', $cols)) . '</tr></thead><tbody>'
                . implode('', array_map(fn($r) => '<tr>' . implode('', array_map(fn($c) => '<td>' . $h($c) . '</td>', (array)$r)) . '</tr>', $list($s['rows'] ?? []))) . '</tbody></table></div>';
        case 'closing':
            $c = $s['contact'] ?? [];
            return '<div class="cover-art" aria-hidden="true">' . brand_mark('cover-mark') . '</div><div class="closing">'
                . '<h1>' . $rich($s['title'] ?? 'Obrigado!') . '</h1>' . (!empty($s['lead']) ? '<p class="cover-sub">' . $rich($s['lead']) . '</p>' : '')
                . '<ol class="steps">' . implode('', array_map(fn($x, $k) => '<li><span>' . ($k + 1) . '</span>' . $h($x) . '</li>', $list($s['steps'] ?? []), array_keys($list($s['steps'] ?? [])))) . '</ol>'
                . '<div class="contact">' . (!empty($c['phone']) ? '<span>' . icon('whatsapp') . $h($c['phone']) . '</span>' : '') . (!empty($c['email']) ? '<span>' . icon('mail') . $h($c['email']) . '</span>' : '') . (!empty($c['site']) ? '<span>' . icon('globe') . $h($c['site']) . '</span>' : '') . '</div></div>'
                . '<div class="cover-foot">' . brand_mark('sl-mark') . brand_wordmark('cover-wm') . '<span>' . $h(COMPANY['domain']) . '</span></div>';
    }
    return $chrome . $title;
}
$css = '/assets/css/deck.css?v=' . @filemtime(__DIR__ . '/assets/css/deck.css');
$js = '/assets/js/deck.js?v=' . @filemtime(__DIR__ . '/assets/js/deck.js');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h($deck['title']) ?> · Integra Code</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $h($css) ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<script src="<?= $h($js) ?>" defer></script>
</head>
<body class="deck theme-<?= $h($theme) ?> <?= $print ? 'is-print' : '' ?>" data-auto-print="<?= isset($_GET['auto']) ? '1' : '0' ?>">
<?= brand_symbols() ?>
<main class="stage" aria-live="polite">
  <?php foreach ($slides as $i => $s): ?>
    <section class="slide l-<?= $h($s['layout'] ?? 'bullets') ?> <?= $i === 0 ? 'active' : '' ?>" id="s<?= $i + 1 ?>" data-i="<?= $i ?>" aria-label="Slide <?= $i + 1 ?> de <?= $total ?>">
      <div class="bg" aria-hidden="true"><i class="g1"></i><i class="g2"></i><i class="grid"></i></div>
      <div class="inner"><?= deck_slide_html($s, $i + 1, $total, $clientName, $h, $rich, $list) ?></div>
    </section>
  <?php endforeach; ?>
</main>
<?php if (!$print && !$embed): ?>
<nav class="controls" aria-label="Navegação da apresentação">
  <button type="button" data-prev aria-label="Slide anterior">‹</button>
  <span data-counter>1 / <?= $total ?></span>
  <button type="button" data-next aria-label="Próximo slide">›</button>
  <button type="button" data-full aria-label="Tela cheia" title="Tela cheia (F)">⛶</button>
  <a href="?<?= $h(http_build_query(array_merge(array_intersect_key($_GET, ['t' => 1, 'id' => 1]), ['print' => 1, 'auto' => 1]))) ?>" target="_blank" rel="noopener" title="Baixar PDF">PDF</a>
  <?php if ($staff): ?><a href="/admin/#/presentations/<?= (int)$deck['id'] ?>" title="Editar" target="_top">Editar</a><?php endif; ?>
</nav>
<div class="progress" aria-hidden="true"><i data-bar></i></div>
<?php endif; ?>
</body>
</html>
