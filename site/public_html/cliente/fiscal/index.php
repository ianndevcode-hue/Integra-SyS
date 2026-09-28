<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — customer app shell (/cliente/fiscal/). Single-page app built on the admin
 * UI kit (admin/js/core.js + admin/app.css), with every module versioned through an import map.
 */

require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$customer = current_customer();
if (!$customer) {
    header('Location: /entrar?next=' . rawurlencode('/cliente/fiscal/'));
    exit;
}
$root = APP_ROOT;
$v = fn(string $rel) => '/' . $rel . '?v=' . (@filemtime("$root/$rel") ?: '0');
$imports = ['/admin/js/core.js' => $v('admin/js/core.js')];
foreach (array_merge(glob("$root/assets/fiscal/*.js") ?: [], glob("$root/assets/fiscal/views/*.js") ?: []) as $file) {
    $rel = substr($file, strlen($root) + 1);
    $imports['/' . $rel] = $v($rel);
}
$boot = ['csrf' => csrf_token(), 'name' => $customer['trade_name'] ?: $customer['name'], 'email' => $customer['email']];
?>
<!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Integra Fiscal Hub · Emissor de NFS-e</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($v('admin/app.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($v('assets/fiscal/fiscal.css')) ?>">
<script type="importmap"><?= json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES) ?></script>
<script>window.FH_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;try{var t=localStorage.getItem('ic-admin-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
</head>
<body class="fh-body">
<div id="root"><div class="loading-box" style="min-height:100vh">Carregando o Fiscal Hub...</div></div>
<noscript><p style="padding:20px">O Fiscal Hub precisa de JavaScript habilitado.</p></noscript>
<script type="module" src="<?= htmlspecialchars($v('assets/fiscal/app.js')) ?>"></script>
</body>
</html>
