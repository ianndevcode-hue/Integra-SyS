<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

$subject = mb_substr(trim((string)($_GET['assunto'] ?? '')), 0, 160);
page_start(['title' => 'Contato', 'active' => '/contato', 'description' => 'Fale com a Integra Code: WhatsApp ' . COMPANY['phone'] . ', e-mail ' . COMPANY['email'] . '.']);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Contato</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> Fale com a gente</span>
    <h1 data-reveal>Vamos tirar sua ideia <span class="grad-text">do papel?</span></h1>
    <p class="lead" data-reveal>Conte um pouco sobre o seu desafio. Respondemos em até 1 dia útil.</p>
  </div>
</section>

<section class="section-sm" style="padding-top:0">
  <div class="container contact-grid">
    <div class="grid" data-stagger>
      <a class="contact-item" href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener"><span class="card-icon"><?= icon('whatsapp') ?></span><span><small>WhatsApp</small><b><?= e(COMPANY['phone']) ?></b></span></a>
      <a class="contact-item" href="mailto:<?= e(COMPANY['email']) ?>"><span class="card-icon"><?= icon('mail') ?></span><span><small>E-mail</small><b><?= e(COMPANY['email']) ?></b></span></a>
      <div class="contact-item"><span class="card-icon"><?= icon('clock') ?></span><span><small>Horário</small><b><?= e(COMPANY['hours']) ?></b></span></div>
      <a class="contact-item" href="/agendar"><span class="card-icon"><?= icon('calendar') ?></span><span><small>Prefere conversar?</small><b>Agende uma reunião online</b></span></a>
      <a class="contact-item" href="/suporte#chamado"><span class="card-icon"><?= icon('ticket') ?></span><span><small>Já é cliente?</small><b>Abra um chamado de suporte</b></span></a>
      <div class="contact-item"><span class="card-icon"><?= icon('file') ?></span><span><small>CNPJ</small><b><?= e(COMPANY['cnpj']) ?></b></span></div>
    </div>
    <form class="form panel" data-api-form="/api/public/contact" novalidate data-reveal="right">
      <input type="hidden" name="source" value="contact">
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="form-row">
        <div class="field"><label for="c-name">Nome*</label><input id="c-name" name="name" required autocomplete="name"></div>
        <div class="field"><label for="c-company">Empresa</label><input id="c-company" name="company" autocomplete="organization"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="c-email">E-mail*</label><input id="c-email" type="email" name="email" required autocomplete="email"></div>
        <div class="field"><label for="c-phone">WhatsApp</label><input id="c-phone" name="phone" data-mask="phone" inputmode="tel" autocomplete="tel"></div>
      </div>
      <div class="field"><label for="c-subject">Assunto</label>
        <input id="c-subject" name="subject" list="subjects" value="<?= e($subject) ?>" placeholder="Selecione ou digite">
        <datalist id="subjects"><?php foreach (services() as $s): ?><option value="<?= e($s['title']) ?>"><?php endforeach; ?><option value="Integra SYS"><option value="Parceria"></datalist>
      </div>
      <div class="field"><label for="c-msg">Mensagem*</label><textarea id="c-msg" name="message" required placeholder="Conte o que você precisa..."></textarea></div>
      <label class="consent"><input type="checkbox" name="consent" required> <span>Concordo com a <a href="/privacidade" target="_blank">Política de Privacidade</a> e autorizo o contato da Integra Code.</span></label>
      <button class="btn btn-primary btn-block btn-lg">Enviar mensagem <?= icon('send') ?></button>
      <div class="form-success"><div class="ok-ico"><?= icon('check') ?></div><h3>Mensagem enviada!</h3><p class="muted">Obrigado pelo contato. Retornaremos em até 1 dia útil. Se preferir, fale agora pelo <a href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener" style="color:var(--primary)">WhatsApp</a>.</p></div>
    </form>
  </div>
</section>
<?php page_end();
