<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';
require_once INC_PATH . '/nfse.php';
require_once INC_PATH . '/fiscalhub.php';

page_start(['title' => 'Integra SYS', 'active' => '/integra-sys', 'description' => 'Integra SYS: sistema de gestão modular na nuvem para PMEs — financeiro, estoque, vendas, CRM, fiscal, BI e atendimento.']);
?>
<section class="page-hero">
  <div class="container ai-wrap">
    <div>
      <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Integra SYS</span></nav>
      <span class="eyebrow" data-reveal><span class="dot"></span> Sistema de gestão na nuvem</span>
      <h1 data-reveal>Toda a sua empresa em <span class="grad-text">um só sistema</span></h1>
      <p class="lead" data-reveal>O Integra SYS reúne financeiro, estoque, vendas, CRM, fiscal, BI e atendimento em uma plataforma modular. Você contrata só o que precisa e adapta ao seu segmento.</p>
      <div class="hero-ctas" data-reveal>
        <a href="#demo" class="btn btn-primary btn-lg magnetic">Solicitar demonstração <?= icon('arrow') ?></a>
        <a href="#modulos" class="btn btn-ghost btn-lg">Ver módulos</a>
      </div>
    </div>
    <div class="orbit" data-reveal="zoom" aria-hidden="true">
      <div class="orbit-ring">
        <?php foreach (sys_modules() as $k => [$ico, $name]): ?>
          <div class="orbit-item" style="--a:<?= $k * 45 ?>deg"><div><?= icon($ico) ?><?= e($name) ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="orbit-core">Integra<br>SYS</div>
    </div>
  </div>
</section>

<section class="section bg-alt" id="modulos">
  <div class="container">
    <div class="section-head center"><span class="kicker" data-reveal>Módulos</span><h2 data-reveal>Monte o sistema <span class="grad-text">do seu jeito</span></h2><p data-reveal>Comece com um módulo e ative os demais conforme sua empresa cresce. Tudo integrado, sem digitação dupla.</p></div>
    <div class="grid grid-4" data-stagger="0.06">
      <?php foreach (sys_modules() as [$ico, $name, $desc]): ?>
        <div class="card spotlight" data-tilt="6"><div class="card-icon"><?= icon($ico) ?></div><h3><?= e($name) ?></h3><p><?= e($desc) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" style="padding-bottom:0">
  <div class="container">
    <div class="card sys-free" data-reveal>
      <div><span class="kicker">Notas fiscais inclusas</span><h2 style="font-size:clamp(1.5rem,3vw,2.1rem);margin:6px 0 8px">Emita até <span class="grad-text"><?= FH_FREE_NOTES ?> notas fiscais por mês grátis</span></h2>
        <p class="muted" style="margin:0;max-width:64ch">O módulo fiscal do Integra SYS usa o <b>Integra Fiscal Hub</b>: NFS-e pela Prefeitura de Marília (SIGISS) e pelo Emissor Nacional, com contas a pagar e a receber, fluxo de caixa e conciliação bancária. O plano Grátis tem <?= FH_FREE_NOTES ?> notas por mês, sem cartão e sem prazo.</p></div>
      <div class="sys-free-cta"><a class="btn btn-primary btn-lg" href="/fiscal-hub-contratar?plano=<?= FH_FREE_PLAN ?>">Começar grátis <?= icon('arrow') ?></a><a class="btn btn-ghost" href="/fiscal-hub#planos">Ver todos os planos</a></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><span class="kicker" data-reveal>Por que o Integra SYS</span><h2 data-reveal>Feito para a <span class="grad-text">realidade da PME</span></h2></div>
    <div class="grid grid-3" data-stagger>
      <div class="card spotlight"><div class="card-icon"><?= icon('rocket') ?></div><h3>Implantação rápida</h3><p>Migração de dados e treinamento da equipe inclusos. Você começa a usar em dias, não em meses.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('phone') ?></div><h3>Acesse de qualquer lugar</h3><p>100% na nuvem e responsivo: computador, tablet ou celular, com a mesma experiência.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('puzzle') ?></div><h3>Personalizável</h3><p>Campos, relatórios e fluxos ajustados ao seu processo — o sistema se adapta a você.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('plug') ?></div><h3>Integrado</h3><p>Bancos, PIX, boletos, marketplaces, iFood, WhatsApp e emissão de notas fiscais.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('brain') ?></div><h3>IA embarcada</h3><p>Previsões, alertas inteligentes e BI conversacional para decidir com base em dados.</p></div>
      <div class="card spotlight"><div class="card-icon"><?= icon('shield') ?></div><h3>Seguro e com suporte</h3><p>Backups diários, controle de acesso, LGPD e suporte com SLA garantido.</p></div>
    </div>
  </div>
</section>

<section class="section bg-alt" id="demo">
  <div class="container contact-grid">
    <div data-reveal="left">
      <span class="kicker">Demonstração gratuita</span>
      <h2 style="font-size:clamp(1.8rem,3.6vw,2.6rem)">Veja o Integra SYS funcionando <span class="grad-text">com os seus processos</span></h2>
      <p class="muted">Preencha o formulário e agendamos uma apresentação personalizada para o seu segmento, sem compromisso.</p>
      <ul class="check-list" style="margin-top:22px">
        <li>Apresentação online de 30 a 45 minutos</li>
        <li>Simulação com exemplos do seu negócio</li>
        <li>Proposta com os módulos ideais para você</li>
      </ul>
    </div>
    <form class="form panel" data-api-form="/api/public/contact" novalidate data-reveal="right">
      <input type="hidden" name="source" value="sys-demo">
      <input type="hidden" name="subject" value="Demonstração Integra SYS">
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="form-row">
        <div class="field"><label for="d-name">Nome*</label><input id="d-name" name="name" required autocomplete="name"></div>
        <div class="field"><label for="d-company">Empresa</label><input id="d-company" name="company" autocomplete="organization"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="d-email">E-mail*</label><input id="d-email" type="email" name="email" required autocomplete="email"></div>
        <div class="field"><label for="d-phone">WhatsApp</label><input id="d-phone" name="phone" data-mask="phone" inputmode="tel" autocomplete="tel"></div>
      </div>
      <div class="field"><label for="d-msg">Quais módulos interessam?*</label><textarea id="d-msg" name="message" required placeholder="Ex.: financeiro e estoque, tenho 2 lojas e vendo no Mercado Livre..."></textarea></div>
      <label class="consent"><input type="checkbox" name="consent" required> <span>Concordo com a <a href="/privacidade" target="_blank">Política de Privacidade</a> e autorizo o contato.</span></label>
      <button class="btn btn-primary btn-block btn-lg">Solicitar demonstração <?= icon('send') ?></button>
      <div class="form-success"><div class="ok-ico"><?= icon('check') ?></div><h3>Solicitação enviada!</h3><p class="muted">Entraremos em contato em até 1 dia útil para agendar sua demonstração.</p></div>
    </form>
  </div>
</section>
<?php page_end();
