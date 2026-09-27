<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Soluções', 'active' => '/solucoes', 'description' => 'Sistemas web, aplicativos mobile, inteligência artificial, integrações, automação, consultoria, suporte com SLA e dashboards de BI.']);
$services = services();
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Soluções</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> 8 frentes de atuação</span>
    <h1 data-reveal>Soluções que <span class="grad-text">trabalham por você</span></h1>
    <p class="lead" data-reveal>Escolha por onde começar — ou combine várias. Cada solução é desenhada a partir do seu processo real, não de um modelo pronto.</p>
  </div>
</section>

<section class="section-sm">
  <div class="container side-layout">
    <nav class="side-nav" aria-label="Soluções">
      <?php foreach ($services as $slug => $s): ?>
        <a href="#<?= e($slug) ?>"><?= icon($s['icon']) ?> <?= e($s['title']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div>
      <?php foreach ($services as $slug => $s): ?>
        <article class="service-block" id="<?= e($slug) ?>" data-reveal>
          <div>
            <div class="card-icon"><?= icon($s['icon'], 'ico ico-lg') ?></div>
            <h2><?= e($s['title']) ?></h2>
            <p class="muted" style="font-size:1.05rem"><?= e($s['text']) ?></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px">
              <a href="/contato?assunto=<?= rawurlencode($s['title']) ?>" class="btn btn-primary btn-sm">Solicitar orçamento <?= icon('arrow') ?></a>
              <a href="/agendar" class="btn btn-ghost btn-sm"><?= icon('calendar') ?> Agendar conversa</a>
            </div>
          </div>
          <div class="panel" style="background:var(--surface-2)">
            <b style="display:block;margin-bottom:14px">O que entregamos</b>
            <ul class="check-list"><?php foreach ($s['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="parallax-band">
  <div class="band-art" data-parallax=".12" aria-hidden="true"></div>
  <div class="px-shape ring" data-parallax="-.3" style="width:260px;height:260px;right:6%;top:10%"></div>
  <div class="px-shape cube" data-parallax=".3" data-rotate=".1" style="width:90px;height:90px;left:10%;top:24%"></div>
  <div class="px-shape dotgrid" data-parallax="-.2" style="width:200px;height:120px;left:20%;bottom:8%"></div>
  <div class="container">
    <h2 data-reveal>Não sabe por onde <span class="grad-text">começar?</span></h2>
    <p data-reveal>Faça o diagnóstico online gratuito e descubra quais soluções trazem mais retorno para o seu momento.</p>
    <div class="center" style="margin-top:28px" data-reveal><a href="/diagnostico" class="btn btn-primary btn-lg magnetic">Fazer diagnóstico <?= icon('arrow') ?></a></div>
  </div>
</section>
<?php page_end();
