<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

$articles = db_all('SELECT id, category, question, answer FROM help_articles WHERE published = 1 ORDER BY position, id');
$counts = array_count_values(array_column($articles, 'category'));

page_start(['title' => 'Central de Ajuda', 'active' => '/suporte', 'description' => 'Central de Ajuda Integra Code: perguntas frequentes, abertura e acompanhamento de chamados, canais de atendimento e SLA.']);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Suporte</span></nav>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:18px">
      <span class="eyebrow" style="margin:0" data-reveal><span class="dot"></span> Central de Ajuda</span>
      <span class="status-pill" data-reveal><i></i> Todos os sistemas operacionais</span>
    </div>
    <h1 data-reveal>Como podemos <span class="grad-text">ajudar?</span></h1>
    <p class="lead" data-reveal>Busque nas perguntas frequentes, fale com a gente pelo WhatsApp ou abra um chamado com nossa equipe técnica.</p>
    <div class="help-search" data-reveal style="margin-top:28px">
      <?= icon('search') ?>
      <input type="search" id="help-q" placeholder="Ex.: segunda via de boleto, prazo, LGPD..." aria-label="Buscar na central de ajuda" autocomplete="off">
      <div class="help-results" id="help-results" role="listbox"></div>
    </div>
  </div>
</section>

<section class="section-sm" style="padding-top:0">
  <div class="container grid grid-4" data-stagger>
    <?php if (chat_enabled()): ?><button class="card spotlight help-cat" data-open-chat type="button" style="text-align:left"><div class="card-icon"><?= icon('chat') ?></div><h3>Assistente virtual<?= ai_feature('chat') ? ' com IA' : '' ?></h3><p>Respostas instantâneas, 24 horas por dia.</p></button>
    <?php else: ?><a class="card spotlight" href="mailto:<?= e(COMPANY['email']) ?>"><div class="card-icon"><?= icon('mail') ?></div><h3>E-mail</h3><p><?= e(COMPANY['email']) ?></p></a><?php endif; ?>
    <a class="card spotlight" href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener"><div class="card-icon"><?= icon('whatsapp') ?></div><h3>WhatsApp</h3><p><?= e(COMPANY['phone']) ?> · <?= e(COMPANY['hours']) ?></p></a>
    <a class="card spotlight" href="#chamado"><div class="card-icon"><?= icon('ticket') ?></div><h3>Abrir chamado</h3><p>Suporte técnico com protocolo e acompanhamento.</p></a>
    <a class="card spotlight" href="/cliente/"><div class="card-icon"><?= icon('user') ?></div><h3>Área do cliente</h3><p>Projetos, cobranças e chamados em um só lugar.</p></a>
  </div>
</section>

<section class="section bg-alt" id="faq">
  <div class="container">
    <div class="section-head"><span class="kicker" data-reveal>Base de conhecimento</span><h2 data-reveal>Perguntas <span class="grad-text">frequentes</span></h2></div>
    <div class="chip-tabs" role="tablist" aria-label="Categorias de ajuda" style="margin-bottom:24px" data-reveal>
      <button class="chip active" data-help-filter="" role="tab">Todas (<?= count($articles) ?>)</button>
      <?php foreach (help_categories() as $slug => [$ico, $label]): if (empty($counts[$slug])) continue; ?>
        <button class="chip" data-help-filter="<?= e($slug) ?>" role="tab"><?= e($label) ?> (<?= $counts[$slug] ?>)</button>
      <?php endforeach; ?>
    </div>
    <div class="accordion" id="faq-list">
      <?php foreach ($articles as $a): ?>
        <div class="acc-item" id="artigo-<?= (int)$a['id'] ?>" data-article-id="<?= (int)$a['id'] ?>" data-category="<?= e($a['category']) ?>">
          <button class="acc-btn" aria-expanded="false"><span><small class="muted" style="display:block;font-size:.76rem;font-weight:600"><?= e(help_categories()[$a['category']][1] ?? '') ?></small><?= e($a['question']) ?></span> <?= icon('chevron') ?></button>
          <div class="acc-panel"><div><div class="acc-content">
            <?= nl2br(e($a['answer'])) ?>
            <div class="acc-feedback">Esta resposta ajudou? <button type="button" data-vote="1">👍 Sim</button><button type="button" data-vote="0">👎 Não</button></div>
          </div></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="chamado">
  <div class="container contact-grid">
    <div data-reveal="left">
      <span class="kicker">Suporte técnico</span>
      <h2 style="font-size:clamp(1.8rem,3.6vw,2.6rem)">Abra um <span class="grad-text">chamado</span></h2>
      <p class="muted">Descreva o que está acontecendo com o máximo de detalhes (tela, mensagem de erro, horário). Você receberá um número de protocolo para acompanhar.</p>
      <div class="panel" style="margin-top:24px;overflow-x:auto">
        <b>Prazos de primeira resposta (SLA)</b>
        <table class="sla-table" style="margin-top:10px">
          <thead><tr><th>Prioridade</th><th>Exemplo</th><th>Prazo</th></tr></thead>
          <tbody>
            <tr><td><span class="badge" style="background:rgba(244,63,94,.15);color:#ff8fa3">Urgente</span></td><td>Sistema fora do ar</td><td>até 4h</td></tr>
            <tr><td><span class="badge in_progress">Alta</span></td><td>Função importante com erro</td><td>até 8h</td></tr>
            <tr><td><span class="badge open">Normal</span></td><td>Dúvidas e ajustes</td><td>até 24h</td></tr>
            <tr><td><span class="badge">Baixa</span></td><td>Sugestões de melhoria</td><td>até 48h</td></tr>
          </tbody>
        </table>
        <small class="muted">Horas úteis. Clientes com SLA contratado seguem os prazos do contrato.</small>
      </div>
    </div>
    <form class="form panel" id="ticket-form" data-api-form="/api/public/tickets" novalidate data-reveal="right">
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="form-row">
        <div class="field"><label for="t-name">Nome*</label><input id="t-name" name="name" required autocomplete="name"></div>
        <div class="field"><label for="t-email">E-mail*</label><input id="t-email" type="email" name="email" required autocomplete="email"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="t-cat">Categoria</label><select id="t-cat" name="category"><?php foreach (ticket_categories() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="t-pri">Prioridade</label><select id="t-pri" name="priority"><option value="low">Baixa</option><option value="normal" selected>Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></div>
      </div>
      <div class="field"><label for="t-subject">Assunto*</label><input id="t-subject" name="subject" required maxlength="200"></div>
      <div class="field"><label for="t-msg">Descrição*</label><textarea id="t-msg" name="message" required></textarea></div>
      <button class="btn btn-primary btn-block btn-lg">Abrir chamado <?= icon('send') ?></button>
      <div class="form-success"><div class="ok-ico"><?= icon('check') ?></div><h3>Chamado aberto!</h3><p class="muted">Seu protocolo é</p><p style="font-family:var(--font-display);font-size:2rem;font-weight:700" class="grad-text" id="ticket-protocol">—</p><p class="muted">Guarde este número. Você pode acompanhar a resposta logo abaixo, em "Consultar chamado".</p></div>
    </form>
  </div>
</section>

<section class="section bg-alt" id="consultar">
  <div class="container" style="max-width:820px">
    <div class="section-head center"><span class="kicker" data-reveal>Acompanhamento</span><h2 data-reveal>Consultar <span class="grad-text">chamado</span></h2><p data-reveal>Informe o protocolo e o e-mail usado na abertura.</p></div>
    <form class="form panel" id="lookup-form" novalidate data-reveal>
      <div class="form-row">
        <div class="field"><label for="l-protocol">Protocolo</label><input id="l-protocol" name="protocol" required placeholder="IC2609240001" style="text-transform:uppercase"></div>
        <div class="field"><label for="l-email">E-mail</label><input id="l-email" type="email" name="email" required></div>
      </div>
      <button class="btn btn-primary">Consultar <?= icon('search') ?></button>
    </form>
    <div id="ticket-view" class="panel" style="display:none;margin-top:20px" aria-live="polite"></div>
  </div>
</section>

<?php page_end(['assets/js/support.js']);
