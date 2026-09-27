<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Sobre nós', 'active' => '/sobre', 'description' => COMPANY['about']]);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Sobre</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> Quem somos</span>
    <h1 data-reveal>Transformamos necessidades em <span class="grad-text">sistemas inteligentes</span></h1>
    <p class="lead" data-reveal><?= e(COMPANY['about']) ?></p>
  </div>
</section>

<section class="section-sm">
  <div class="container grid grid-2" style="align-items:center;gap:48px">
    <div data-reveal="left">
      <span class="kicker">Nossa história</span>
      <h2 style="font-size:clamp(1.8rem,3.6vw,2.6rem)">Tecnologia de verdade para quem move a economia</h2>
      <p class="muted">A Integra Code nasceu para aproximar pequenas e médias empresas da tecnologia que antes parecia exclusiva das grandes. Vimos de perto negócios perdendo tempo e dinheiro com planilhas, retrabalho e sistemas que não conversam — e decidimos resolver isso com soluções sob medida, práticas e acessíveis.</p>
      <p class="muted">Hoje desenvolvemos sistemas web, aplicativos, integrações e automações com inteligência artificial, sempre com atendimento próximo: você fala direto com quem constrói a sua solução.</p>
    </div>
    <div class="grid grid-2" data-stagger>
      <div class="stat"><strong class="grad-text" data-count="8">0</strong><span>áreas de atuação</span></div>
      <div class="stat"><strong class="grad-text" data-count="8">0</strong><span>segmentos atendidos</span></div>
      <div class="stat"><strong class="grad-text" data-count="24" data-suffix="/7">0</strong><span>monitoramento</span></div>
      <div class="stat"><strong class="grad-text" data-count="100" data-suffix="%">0</strong><span>foco em PMEs</span></div>
    </div>
  </div>
</section>

<section class="section bg-alt">
  <div class="container">
    <div class="section-head center"><span class="kicker" data-reveal>Propósito</span><h2 data-reveal>Missão, visão e <span class="grad-text">valores</span></h2></div>
    <div class="grid grid-3 values" data-stagger>
      <div class="card spotlight"><div class="card-icon"><?= icon('target') ?></div><h3>Missão</h3><p>Simplificar o dia a dia das empresas com tecnologia sob medida, que gera resultado mensurável e é fácil de usar.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('eye') ?></div><h3>Visão</h3><p>Ser a parceira de tecnologia de referência para PMEs brasileiras, reconhecida pela proximidade e pela qualidade das entregas.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('heart') ?></div><h3>Valores</h3><p>Transparência, compromisso com prazos, segurança dos dados, aprendizado contínuo e respeito ao negócio de cada cliente.</p></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><span class="kicker" data-reveal>Diferenciais</span><h2 data-reveal>O que nos torna <span class="grad-text">diferentes</span></h2></div>
    <div class="grid grid-4" data-stagger>
      <?php foreach (differentials() as [$ico, $title, $text]): ?>
        <div class="card spotlight" data-tilt="5"><div class="card-icon"><?= icon($ico) ?></div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section bg-alt">
  <div class="container">
    <div class="section-head center"><span class="kicker" data-reveal>Método</span><h2 data-reveal>Como transformamos ideias <span class="grad-text">em sistemas</span></h2></div>
    <div class="timeline" data-timeline>
      <div class="timeline-line"><i></i></div>
      <?php foreach (methodology() as $i => [$n, $title, $text]): ?>
        <div class="step" data-reveal style="--d:<?= $i * .1 ?>s"><div class="step-num"><?= $n ?></div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container grid grid-2" style="gap:40px;align-items:start">
    <div data-reveal="left">
      <span class="kicker">Segurança & LGPD</span>
      <h2 style="font-size:clamp(1.8rem,3.6vw,2.6rem)">Seus dados tratados com <span class="grad-text">responsabilidade</span></h2>
      <p class="muted">Segurança não é opcional. Todos os nossos sistemas são construídos com boas práticas desde a primeira linha de código.</p>
    </div>
    <ul class="check-list panel" data-reveal="right">
      <li>Conexões criptografadas (HTTPS) e senhas armazenadas com hash seguro</li>
      <li>Backups automáticos diários com testes de restauração</li>
      <li>Controle de acesso por perfil e registro de auditoria</li>
      <li>Tratamento de dados pessoais conforme a LGPD</li>
      <li>Monitoramento contínuo e atualizações de segurança</li>
    </ul>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="cta-band" data-reveal="zoom">
      <span class="kicker">Vamos conversar?</span>
      <h2>Conte o desafio da sua empresa. A gente ajuda a resolver.</h2>
      <p>CNPJ <?= e(COMPANY['cnpj']) ?> · <?= e(COMPANY['hours']) ?></p>
      <div class="actions">
        <a href="/agendar" class="btn btn-primary btn-lg magnetic">Agendar conversa <?= icon('arrow') ?></a>
        <a href="/contato" class="btn btn-ghost btn-lg"><?= icon('mail') ?> Enviar mensagem</a>
      </div>
    </div>
  </div>
</section>
<?php page_end();
