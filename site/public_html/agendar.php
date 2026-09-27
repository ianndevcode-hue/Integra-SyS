<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Agendar apresentação', 'active' => '/agendar', 'description' => 'Agende uma conversa gratuita com a Integra Code: escolha o dia e o horário ideais.']);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Agendar</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> Sem compromisso</span>
    <h1 data-reveal>Agende sua <span class="grad-text">conversa gratuita</span></h1>
    <p class="lead" data-reveal>Escolha o melhor dia e horário. Em 30 a 60 minutos entendemos seu cenário e mostramos caminhos possíveis.</p>
  </div>
</section>

<section class="section-sm" style="padding-top:0">
  <div class="container sched" id="scheduler">
    <div class="panel" data-reveal="left">
      <div class="cal-head">
        <button class="icon-btn" type="button" data-cal-prev aria-label="Mês anterior"><span style="transform:rotate(90deg);display:grid"><?= icon('chevron') ?></span></button>
        <h3 data-cal-title aria-live="polite">—</h3>
        <button class="icon-btn" type="button" data-cal-next aria-label="Próximo mês"><span style="transform:rotate(-90deg);display:grid"><?= icon('chevron') ?></span></button>
      </div>
      <div class="cal-grid" data-cal-grid role="grid"></div>
      <div style="margin-top:22px">
        <b data-slot-title>Selecione um dia útil</b>
        <div class="slots" data-slots></div>
      </div>
      <p class="muted" style="font-size:.86rem;margin:0"><?= icon('clock', 'ico') ?> Horário de Brasília · <?= e(COMPANY['hours']) ?></p>
    </div>

    <form class="form panel" id="appointment-form" data-api-form="/api/public/appointments" novalidate data-reveal="right">
      <input type="hidden" name="date">
      <input type="hidden" name="time">
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="tag tag-primary" data-selected-label style="justify-self:start"><?= icon('calendar') ?> Nenhum horário selecionado</div>
      <div class="form-row">
        <div class="field"><label for="a-name">Nome*</label><input id="a-name" name="name" required autocomplete="name"></div>
        <div class="field"><label for="a-company">Empresa</label><input id="a-company" name="company" autocomplete="organization"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="a-email">E-mail*</label><input id="a-email" type="email" name="email" required autocomplete="email"></div>
        <div class="field"><label for="a-phone">WhatsApp*</label><input id="a-phone" name="phone" required data-mask="phone" inputmode="tel" autocomplete="tel"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="a-topic">Assunto</label>
          <select id="a-topic" name="topic">
            <option>Diagnóstico gratuito</option>
            <option>Apresentação Integra SYS</option>
            <?php foreach (services() as $s): ?><option><?= e($s['title']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="a-type">Formato</label>
          <select id="a-type" name="meeting_type"><option value="online">Vídeo (Google Meet)</option><option value="telefone">Telefone / WhatsApp</option><option value="presencial">Presencial</option></select>
        </div>
      </div>
      <div class="field"><label for="a-notes">Quer adiantar algo?</label><textarea id="a-notes" name="notes" style="min-height:90px" placeholder="Opcional"></textarea></div>
      <button class="btn btn-primary btn-block btn-lg">Confirmar agendamento <?= icon('check') ?></button>
      <div class="form-success">
        <div class="ok-ico"><?= icon('calendar') ?></div>
        <h3>Agendamento confirmado!</h3>
        <p class="muted" data-confirm-text></p>
        <button type="button" class="btn btn-ghost" data-ics><?= icon('calendar') ?> Adicionar à agenda (.ics)</button>
      </div>
    </form>
  </div>
</section>
<?php page_end(['assets/js/scheduler.js']);
