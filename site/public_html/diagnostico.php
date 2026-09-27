<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Diagnóstico gratuito', 'active' => '/diagnostico', 'description' => 'Descubra em 2 minutos a maturidade digital da sua empresa e receba recomendações práticas da Integra Code.']);
?>
<section class="page-hero" style="padding-bottom:30px">
  <div class="container center">
    <span class="eyebrow" data-reveal><span class="dot"></span> 2 minutos · gratuito</span>
    <h1 data-reveal style="margin-inline:auto">Diagnóstico de <span class="grad-text">maturidade digital</span></h1>
    <p class="lead" data-reveal style="margin-inline:auto">Responda 8 perguntas rápidas e veja onde a tecnologia pode economizar tempo e dinheiro na sua empresa.</p>
  </div>
</section>

<section class="section-sm" style="padding-top:10px">
  <div class="container">
    <div class="quiz panel" id="quiz" data-reveal>
      <div class="quiz-progress" aria-hidden="true"><i></i></div>
      <div data-quiz-steps></div>

      <div class="quiz-step" data-result>
        <svg width="0" height="0" style="position:absolute"><defs><linearGradient id="gaugeGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0066fe"/><stop offset="1" stop-color="#00cf81"/></linearGradient></defs></svg>
        <div class="gauge"><svg viewBox="0 0 220 220"><circle class="track" cx="110" cy="110" r="92"/><circle class="val" cx="110" cy="110" r="92"/></svg><strong data-score>0%</strong></div>
        <div class="center"><span class="tag tag-primary" data-level>—</span><h2 style="margin-top:14px" data-level-title>—</h2><p class="muted" data-level-text></p></div>
        <div class="panel" style="margin:24px 0;background:var(--surface-2)"><b>Recomendações para você</b><ul class="check-list" style="margin-top:12px" data-recs></ul></div>

        <div class="panel ai-report hidden" data-ai-report style="margin:0 0 24px;border-color:rgba(0,207,129,.35);background:linear-gradient(160deg,rgba(0,102,254,.08),rgba(0,207,129,.05))">
          <b style="display:flex;align-items:center;gap:8px"><?= icon('sparkles') ?> Análise personalizada com IA</b>
          <div data-ai-text class="muted" style="margin-top:10px;white-space:pre-wrap"></div>
        </div>
        <form class="form" data-api-form="/api/public/diagnostic" id="diag-form" novalidate>
          <p style="margin:0"><b>Receba o relatório completo</b> com um plano de ação e estimativa de investimento:</p>
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="form-row">
            <div class="field"><label for="q-name">Nome*</label><input id="q-name" name="name" required autocomplete="name"></div>
            <div class="field"><label for="q-company">Empresa</label><input id="q-company" name="company" autocomplete="organization"></div>
          </div>
          <div class="form-row">
            <div class="field"><label for="q-email">E-mail*</label><input id="q-email" type="email" name="email" required autocomplete="email"></div>
            <div class="field"><label for="q-phone">WhatsApp</label><input id="q-phone" name="phone" data-mask="phone" inputmode="tel"></div>
          </div>
          <label class="consent"><input type="checkbox" name="consent" required> <span>Concordo com a <a href="/privacidade" target="_blank">Política de Privacidade</a>.</span></label>
          <button class="btn btn-primary btn-block btn-lg">Quero meu relatório <?= icon('send') ?></button>
          <div class="form-success"><div class="ok-ico"><?= icon('check') ?></div><h3>Relatório solicitado!</h3><p class="muted">Nossa equipe vai analisar suas respostas e enviar o plano de ação em até 1 dia útil.</p><a href="/agendar" class="btn btn-ghost">Adiantar e agendar uma conversa</a></div>
        </form>
        <div class="center" style="margin-top:14px"><button type="button" class="btn btn-link" data-restart>Refazer diagnóstico</button></div>
      </div>
    </div>
  </div>
</section>
<?php page_end(['assets/js/diagnostic.js']);
