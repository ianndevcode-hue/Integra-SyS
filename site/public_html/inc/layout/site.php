<?php
declare(strict_types=1);

/**
 * Public site layout. Usage:
 *   require __DIR__ . '/inc/layout/site.php';
 *   page_start(['title' => ..., 'description' => ..., 'active' => '/sobre']);
 *   ...markup...
 *   page_end();
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once INC_PATH . '/content.php';
require_once INC_PATH . '/icons.php';
require_once INC_PATH . '/ai.php';

function asset(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/') . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function base_url(): string
{
    return rtrim((string)config('app_url', 'https://' . COMPANY['domain']), '/');
}

function page_start(array $meta = []): void
{
    $title = isset($meta['title']) ? $meta['title'] . ' · ' . COMPANY['name'] : COMPANY['name'] . ' · ' . COMPANY['tagline'];
    $description = $meta['description'] ?? COMPANY['about'];
    $active = $meta['active'] ?? '/';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $canonical = base_url() . ($path === '/' ? '/' : rtrim($path, '/'));
    $GLOBALS['page_meta'] = $meta;
    ?><!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#050a16">
<meta property="og:type" content="<?= e($meta['og_type'] ?? 'website') ?>">
<meta property="og:site_name" content="<?= e(COMPANY['name']) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e(base_url()) ?>/assets/img/og.jpg">
<meta property="og:locale" content="pt_BR">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/site.css') ?>">
<script>document.documentElement.classList.add('js');try{var t=localStorage.getItem('ic-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => COMPANY['name'],
    'url' => base_url(),
    'logo' => base_url() . '/assets/img/logo.png',
    'email' => COMPANY['email'],
    'telephone' => '+' . COMPANY['phone_raw'],
    'taxID' => COMPANY['cnpj'],
    'slogan' => COMPANY['tagline'],
    'contactPoint' => [['@type' => 'ContactPoint', 'telephone' => '+' . COMPANY['phone_raw'], 'contactType' => 'customer service', 'areaServed' => 'BR', 'availableLanguage' => 'Portuguese']],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?= $meta['head'] ?? '' ?>
</head>
<body class="<?= e($meta['body_class'] ?? '') ?>">
<?= brand_symbols() ?>
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<div class="scroll-progress" aria-hidden="true"></div>
<div class="cursor-glow" aria-hidden="true"></div>

<header class="site-header" id="topo">
  <div class="container header-inner">
    <a href="/" class="brand" aria-label="<?= e(COMPANY['name']) ?> - página inicial">
      <?= brand_mark() ?>
      <?= brand_wordmark() ?>
    </a>
    <nav class="main-nav" aria-label="Navegação principal">
      <ul>
        <?php foreach (nav_items() as [$href, $label]): ?>
          <li><a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="header-actions">
      <button class="icon-btn theme-toggle" type="button" aria-label="Alternar tema claro/escuro">
        <?= icon('moon', 'ico moon') ?>
        <?= icon('sun', 'ico sun') ?>
      </button>
      <a href="/entrar" class="icon-btn" aria-label="Entrar / Área do cliente" title="Entrar / Área do cliente"><?= icon('user') ?></a>
      <a href="/agendar" class="btn btn-primary btn-sm btn-cta magnetic">Agendar conversa <?= icon('arrow') ?></a>
      <button class="icon-btn nav-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="drawer"><?= icon('menu') ?></button>
    </div>
  </div>
</header>

<div class="drawer" id="drawer" aria-hidden="true">
  <div class="drawer-backdrop" data-close-drawer></div>
  <aside class="drawer-panel" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="drawer-head">
      <a href="/" class="brand" aria-label="Integra Code"><?= brand_mark() ?><?= brand_wordmark() ?></a>
      <button class="icon-btn" type="button" data-close-drawer aria-label="Fechar menu"><?= icon('x') ?></button>
    </div>
    <nav aria-label="Navegação móvel">
      <?php foreach (array_merge(nav_items(), [['/agendar', 'Agendar'], ['/diagnostico', 'Diagnóstico gratuito'], ['/entrar', 'Entrar / Área do cliente']]) as $i => [$href, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>" style="transition-delay: <?= 0.05 + $i * 0.035 ?>s"><?= e($label) ?> <?= icon('arrow') ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="drawer-foot">
      <a class="btn btn-primary btn-block" href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp</a>
      <p class="muted" style="font-size:.85rem;margin:0"><?= e(COMPANY['hours']) ?></p>
    </div>
  </aside>
</div>

<main id="conteudo">
<?php
}

function page_end(array $scripts = []): void
{
    $posts = [];
    ?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a href="/" class="brand" aria-label="Integra Code - início"><?= brand_mark() ?><?= brand_wordmark() ?></a>
        <p style="margin-top:16px;max-width:320px"><?= e(COMPANY['about']) ?></p>
        <div class="socials">
          <a class="icon-btn" href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('whatsapp') ?></a>
          <a class="icon-btn" href="mailto:<?= e(COMPANY['email']) ?>" aria-label="E-mail"><?= icon('mail') ?></a>
          <a class="icon-btn" href="/suporte" aria-label="Central de ajuda"><?= icon('help') ?></a>
        </div>
      </div>
      <div>
        <h4>Empresa</h4>
        <ul>
          <li><a href="/sobre">Sobre nós</a></li>
          <li><a href="/segmentos">Segmentos</a></li>
          <li><a href="/integra-sys">Integra SYS</a></li>
          <li><a href="/fiscal-hub">Integra Fiscal Hub</a></li>
          <li><a href="/blog">Blog</a></li>
          <li><a href="/privacidade">Privacidade (LGPD)</a></li>
        </ul>
      </div>
      <div>
        <h4>Atendimento</h4>
        <ul>
          <li><a href="/suporte">Central de ajuda</a></li>
          <li><a href="/suporte#chamado">Abrir chamado</a></li>
          <li><a href="/agendar">Agendar apresentação</a></li>
          <li><a href="/diagnostico">Diagnóstico gratuito</a></li>
          <li><a href="/cliente/">Área do cliente</a></li>
        </ul>
      </div>
      <div>
        <h4>Contato</h4>
        <ul>
          <li><a href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener"><?= e(COMPANY['phone']) ?></a></li>
          <li><a href="mailto:<?= e(COMPANY['email']) ?>"><?= e(COMPANY['email']) ?></a></li>
          <li><p style="margin:0"><?= e(COMPANY['hours']) ?></p></li>
        </ul>
        <form class="newsletter" data-api-form="/api/public/newsletter" data-reset novalidate>
          <input type="email" name="email" placeholder="Seu e-mail para novidades" aria-label="E-mail para newsletter" required>
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button class="btn btn-primary btn-sm" aria-label="Inscrever"><?= icon('send') ?></button>
        </form>
      </div>
    </div>
    <div class="footer-word" aria-hidden="true">INTEGRA</div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= e(COMPANY['name']) ?> · CNPJ <?= e(COMPANY['cnpj']) ?></span>
      <span><?= e(COMPANY['tagline']) ?></span>
    </div>
  </div>
</footer>

<div class="fab-stack">
  <button class="icon-btn to-top" type="button" aria-label="Voltar ao topo"><?= icon('chevron') ?></button>
  <a class="fab fab-wa" href="<?= e(COMPANY['whatsapp']) ?>?text=<?= rawurlencode('Olá! Vim pelo site da Integra Code.') ?>" target="_blank" rel="noopener" aria-label="Conversar no WhatsApp"><?= icon('whatsapp') ?></a>
  <?php if (chat_enabled()): ?>  <button class="fab fab-chat" type="button" aria-label="Abrir assistente virtual" aria-controls="chat-box" aria-expanded="false"><?= icon('chat') ?><span class="badge-dot"></span></button><?php endif; ?>
</div>

<?php if (chat_enabled()): ?>
<section class="chat-box" id="chat-box" aria-label="Assistente virtual" role="dialog">
  <header class="chat-head">
    <div class="avatar"><?= icon('sparkles') ?></div>
    <div><b>Assistente Integra</b><small>● Online agora<?= ai_feature('chat') ? ' · respostas com IA' : '' ?></small></div>
    <button class="icon-btn" type="button" data-close-chat aria-label="Fechar assistente"><?= icon('x') ?></button>
  </header>
  <div class="chat-body" aria-live="polite"></div>
  <form class="chat-form" autocomplete="off">
    <input name="message" placeholder="Digite sua dúvida..." aria-label="Mensagem" maxlength="500">
    <button aria-label="Enviar"><?= icon('send') ?></button>
  </form>
</section>
<?php endif; ?>

<div class="cookie-bar" role="region" aria-label="Aviso de cookies">
  Usamos apenas cookies essenciais para o funcionamento do site e do atendimento. Saiba mais na nossa <a href="/privacidade">Política de Privacidade</a>.
  <div class="actions"><button class="btn btn-primary btn-sm" data-cookie-ok>Entendi</button></div>
</div>
<div class="toast-wrap" aria-live="polite"></div>

<script>window.IC = <?= json_encode(['whatsapp' => COMPANY['whatsapp'], 'phone' => COMPANY['phone'], 'ai' => ['chat' => ai_feature('chat'), 'diagnostic' => ai_feature('diagnostic')]], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= asset('assets/js/site.js') ?>" defer></script>
<?php foreach ($scripts as $s): ?><script src="<?= asset($s) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Decorative cover art for blog posts without an image (deterministic by seed). */
function post_art(string $seed): string
{
    $h = crc32($seed);
    $hue = [216, 158, 255, 200, 228][$h % 5];
    $x = 20 + ($h >> 3) % 60;
    $y = 20 + ($h >> 7) % 60;
    return '<div class="art" style="background:radial-gradient(circle at ' . $x . '% ' . $y . '%, hsla(' . $hue . ',95%,58%,.55), transparent 55%),radial-gradient(circle at ' . (100 - $x) . '% ' . (100 - $y) . '%, hsla(' . (($hue + 200) % 360) . ',80%,60%,.35), transparent 50%),linear-gradient(135deg,#0f1628,#161f36)"></div>'
        . '<svg class="art" viewBox="0 0 400 225" preserveAspectRatio="none" aria-hidden="true" style="opacity:.25"><path d="M0 ' . (150 + $h % 40) . ' C 100 ' . (80 + $h % 60) . ', 220 ' . (200 - $h % 50) . ', 400 ' . (90 + $h % 70) . '" stroke="#fff" fill="none" stroke-width="1.5"/><path d="M0 ' . (180 + $h % 30) . ' C 120 ' . (120 + $h % 40) . ', 260 ' . (220 - $h % 30) . ', 400 ' . (130 + $h % 50) . '" stroke="#00cf81" fill="none" stroke-width="1"/></svg>';
}
