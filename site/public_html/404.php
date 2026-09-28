<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/layout/site.php';

if (!headers_sent()) http_response_code(404);
page_start(['title' => 'Página não encontrada', 'active' => '']);
?>
<section class="page-hero" style="min-height:80svh;display:flex;align-items:center">
  <div class="container center">
    <p class="grad-text" style="font-family:var(--font-display);font-size:clamp(6rem,22vw,12rem);font-weight:700;line-height:1;margin:0">404</p>
    <h1 style="margin-inline:auto">Ops! Esta página se desconectou.</h1>
    <p class="lead" style="margin-inline:auto">O endereço pode ter mudado ou não existe mais. Que tal voltar ao início ou buscar na central de ajuda?</p>
    <div class="hero-ctas" style="justify-content:center;margin-top:26px">
      <a href="/" class="btn btn-primary btn-lg magnetic">Voltar ao início <?= icon('arrow') ?></a>
      <a href="/suporte" class="btn btn-ghost btn-lg"><?= icon('help') ?> Central de ajuda</a>
    </div>
  </div>
</section>
<?php page_end();
