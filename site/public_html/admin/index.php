<?php
declare(strict_types=1);

/**
 * Admin SPA shell. Every JS module and the stylesheet get a ?v=<mtime> version through an
 * import map, so a deploy is picked up immediately even though static files are cached for 30 days.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$dir = __DIR__;
$v = fn(string $rel) => '/admin/' . $rel . '?v=' . (@filemtime("$dir/$rel") ?: '0');
$imports = [];
foreach (array_merge(glob("$dir/js/*.js") ?: [], glob("$dir/js/views/*.js") ?: []) as $file) {
    $rel = substr($file, strlen($dir) + 1);
    $imports['/admin/' . $rel] = $v($rel);
}
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Painel · Integra Code</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($v('app.css')) ?>">
<script type="importmap"><?= json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES) ?></script>
<script>try{var t=localStorage.getItem('ic-admin-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
</head>
<body>
<div id="root"><div class="loading-box" style="min-height:100vh">Carregando painel...</div></div>
<noscript><p style="padding:20px">O painel precisa de JavaScript habilitado.</p></noscript>
<script type="module" src="<?= htmlspecialchars($v('js/app.js')) ?>"></script>
</body>
</html>
