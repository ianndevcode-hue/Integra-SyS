<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Segmentos', 'active' => '/segmentos', 'description' => 'Soluções para supermercados, farmácias, restaurantes, varejo, logística, agronegócio, clínicas e prestadores de serviço.']);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Segmentos</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> Soluções por setor</span>
    <h1 data-reveal>Tecnologia que entende <span class="grad-text">o seu setor</span></h1>
    <p class="lead" data-reveal>Cada segmento tem suas regras, rotinas e integrações. Passe o mouse (ou toque) nos cartões para ver o que entregamos em cada um.</p>
  </div>
</section>

<section class="section-sm">
  <div class="container grid grid-4" data-stagger="0.06">
    <?php foreach (segments() as $slug => [$ico, $name, $desc, $features]): ?>
      <div class="flip" id="<?= e($slug) ?>" tabindex="0" aria-label="<?= e($name) ?>">
        <div class="flip-inner">
          <div class="flip-face card">
            <div class="card-icon"><?= icon($ico, 'ico ico-lg') ?></div>
            <h3><?= e($name) ?></h3>
            <p><?= e($desc) ?></p>
            <span class="more" style="margin-top:auto">Ver recursos <?= icon('arrow') ?></span>
          </div>
          <div class="flip-face card flip-back">
            <h3><?= e($name) ?></h3>
            <ul class="check-list" style="margin:10px 0 18px;font-size:.92rem"><?php foreach ($features as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
            <a href="/contato?assunto=<?= rawurlencode('Solução para ' . $name) ?>" class="btn btn-primary btn-sm" style="margin-top:auto">Quero para meu negócio</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="cta-band" data-reveal="zoom">
      <span class="kicker">Outro segmento?</span>
      <h2>Não encontrou o seu setor? Também atendemos.</h2>
      <p>Nosso processo começa entendendo o seu negócio. Se tem processo, tem como simplificar.</p>
      <div class="actions">
        <a href="/agendar" class="btn btn-primary btn-lg magnetic">Agendar conversa <?= icon('arrow') ?></a>
        <a href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-lg"><?= icon('whatsapp') ?> WhatsApp</a>
      </div>
    </div>
  </div>
</section>
<?php page_end();
