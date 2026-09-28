<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';
require_once INC_PATH . '/nfse.php';
require_once INC_PATH . '/fiscalhub.php';

$plans = fh_plans();
$byCode = array_column($plans, null, 'code');
$code = (string)($_GET['plano'] ?? 'profissional');
$plan = $byCode[$code] ?? ($byCode['profissional'] ?? $plans[0]);
$cycle = ($_GET['ciclo'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
$customer = current_customer();
$full = $customer ? db_find('customers', (int)$customer['id']) : null;
$existing = $customer ? fh_subscription((int)$customer['id']) : null;
$hasActive = $existing && in_array($existing['status'], ['active', 'past_due'], true);
$sales = setting('fh_sales_enabled', '1') === '1';

page_start(['title' => 'Contratar o Integra Fiscal Hub', 'active' => '/fiscal-hub', 'description' => 'Contratação online do Integra Fiscal Hub: escolha o plano, aceite os termos e pague por PIX, boleto ou cartão.', 'head' => '<meta name="robots" content="noindex">']);
?>
<section class="page-hero fh-checkout-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <a href="/fiscal-hub">Integra Fiscal Hub</a> / <span>Contratar</span></nav>
    <h1 style="font-size:clamp(2rem,4vw,2.8rem)">Contratar o <span class="grad-text">Integra Fiscal Hub</span></h1>
    <p class="lead">Três passos: seus dados, aceite dos termos e pagamento. A emissão é liberada assim que o pagamento é confirmado — no plano Grátis, na hora.</p>
  </div>
</section>
<section class="section" style="padding-top:0">
  <div class="container fh-checkout">
    <?php if (!$sales): ?>
      <div class="auth-alert error">As contratações online estão pausadas no momento. <a href="/contato">Fale com a nossa equipe</a>.</div>
    <?php elseif ($hasActive): ?>
      <div class="card" style="padding:28px"><h2>Você já tem o Fiscal Hub 🎉</h2><p class="muted">Sua assinatura está <?= e(FH_SUB_STATUS[$existing['status']] ?? $existing['status']) ?>. Para mudar de plano, acesse a sua área.</p><a class="btn btn-primary" href="/cliente/fiscal/#/assinatura">Abrir o Fiscal Hub</a></div>
    <?php else: ?>
    <form class="fh-co-form" data-checkout novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="card fh-co-step"><h2><span class="fh-num">1</span> Plano</h2>
        <div class="fh-co-plans">
          <?php foreach ($plans as $p): ?>
            <label class="fh-co-plan <?= fh_is_free($p) ? 'free' : '' ?>"><input type="radio" name="plan" value="<?= e($p['code']) ?>" <?= $p['code'] === $plan['code'] ? 'checked' : '' ?> data-m="<?= e($p['price_monthly']) ?>" data-y="<?= e($p['price_yearly']) ?>" data-name="<?= e($p['name']) ?>" data-free="<?= fh_is_free($p) ? '1' : '0' ?>">
              <div><b><?= e($p['name']) ?></b><small><?= number_format($p['notes_limit'], 0, ',', '.') ?> notas/mês · <?= (int)$p['companies_limit'] ?> empresa(s)</small><span><?= fh_is_free($p) ? 'Grátis' : e(money($p['price_monthly'])) . '/mês' ?></span></div></label>
          <?php endforeach; ?>
        </div>
        <div class="fh-cycle" role="group" aria-label="Ciclo" data-paid-only><label><input type="radio" name="cycle" value="monthly" <?= $cycle === 'monthly' ? 'checked' : '' ?>> Mensal</label><label><input type="radio" name="cycle" value="yearly" <?= $cycle === 'yearly' ? 'checked' : '' ?>> Anual <em>2 meses grátis</em></label></div>
      </div>

      <div class="card fh-co-step"><h2><span class="fh-num">2</span> Seus dados</h2>
        <?php if ($customer): ?>
          <p class="muted" style="margin-top:0">Contratando como <b><?= e($full['name']) ?></b> (<?= e($full['email']) ?>).</p>
        <?php else: ?>
          <p class="muted" style="margin-top:0">Já tem conta na Área do Cliente? <a href="/entrar?next=<?= e(rawurlencode('/fiscal-hub-contratar?plano=' . $plan['code'])) ?>">Entre para continuar</a>.</p>
          <div class="form-row"><div class="field"><label for="co-name">Nome completo ou razão social *</label><input id="co-name" name="name" required autocomplete="name"></div>
            <div class="field"><label for="co-email">E-mail *</label><input id="co-email" name="email" type="email" required autocomplete="email"></div></div>
          <div class="form-row"><div class="field"><label for="co-pass">Crie uma senha *</label><input id="co-pass" name="password" type="password" required autocomplete="new-password" minlength="8"><small class="muted">Mínimo de 8 caracteres, com letras e números.</small></div>
            <div class="field"><label for="co-phone">WhatsApp</label><input id="co-phone" name="phone" autocomplete="tel"></div></div>
        <?php endif; ?>
        <div class="form-row"><div class="field"><label for="co-doc">CPF ou CNPJ para a cobrança *</label><input id="co-doc" name="document" required inputmode="numeric" value="<?= e($full['document'] ?? '') ?>"></div>
          <?php if ($customer): ?><div class="field"><label for="co-phone2">WhatsApp</label><input id="co-phone2" name="phone" value="<?= e($full['phone'] ?? '') ?>"></div><?php endif; ?></div>
      </div>

      <div class="card fh-co-step"><h2><span class="fh-num">3</span> Pagamento e aceite</h2>
        <div class="field" data-paid-only><label>Forma de pagamento</label>
          <div class="fh-co-pay">
            <?php foreach ([['UNDEFINED', 'Escolher na fatura', 'PIX, boleto ou cartão'], ['PIX', 'PIX', 'Liberação em minutos'], ['CREDIT_CARD', 'Cartão de crédito', 'Liberação imediata'], ['BOLETO', 'Boleto', 'Até 3 dias úteis']] as $i => [$v, $l, $h]): ?>
              <label class="fh-co-opt"><input type="radio" name="billing_type" value="<?= $v ?>" <?= $i === 0 ? 'checked' : '' ?>><div><b><?= e($l) ?></b><small><?= e($h) ?></small></div></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="fh-terms-box">
          <p data-free-only class="hidden"><b>Resumo dos termos:</b> plano gratuito com até <?= FH_FREE_NOTES ?> notas por mês para 1 empresa, sem cobrança e sem prazo; um plano grátis por CPF/CNPJ; você pode mudar para um plano pago quando quiser; você é responsável pelas informações fiscais das notas e pela guarda do seu certificado e senhas; dados protegidos conforme a LGPD.</p>
          <p data-paid-only><b>Resumo dos termos:</b> assinatura <span data-cycle-txt>mensal</span> renovada automaticamente, sem fidelidade; cancelamento a qualquer momento com acesso até o fim do período pago; a emissão é liberada após a confirmação do pagamento e bloqueada após <?= fh_grace_days() ?> dias de atraso (consulta e download continuam disponíveis); você é responsável pelas informações fiscais das notas e pela guarda do seu certificado e senhas; dados protegidos conforme a LGPD.</p>
          <label class="consent"><input type="checkbox" name="terms" value="1" required> <span>Li e aceito os <a href="/fiscal-hub-termos" target="_blank">Termos de Uso do Integra Fiscal Hub (versão <?= FH_TERMS_VERSION ?>)</a> e a <a href="/privacidade" target="_blank">Política de Privacidade</a>.</span></label>
          <div class="field" style="margin-top:12px"><label for="co-sign">Assinatura eletrônica: digite seu nome completo *</label><input id="co-sign" name="terms_name" required value="<?= e($full['name'] ?? '') ?>"><small class="muted">Registramos data, hora e IP do aceite.</small></div>
        </div>
        <div class="auth-alert error hidden" data-err role="alert"></div>
        <button class="btn btn-primary btn-lg btn-block" data-submit><span data-submit-txt>Contratar e pagar</span> <?= icon('arrow') ?></button>
      </div>
    </form>
    <aside class="card fh-co-summary">
      <span class="kicker">Resumo</span>
      <h3 data-sum-name>Plano <?= e($plan['name']) ?></h3>
      <div class="fh-co-total"><small>Total</small><b data-sum-price><?= e(money($cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'])) ?></b><span data-sum-cycle>/<?= $cycle === 'yearly' ? 'ano' : 'mês' ?></span></div>
      <ul class="check-list small-text">
        <li>Emissão pelo SIGISS de Marília e pelo Emissor Nacional</li><li>Financeiro: contas a pagar e a receber, fluxo de caixa e conciliação</li><li>Cálculo de ISS e retenções federais</li><li>PDF + XML e envio automático ao cliente</li><li>Downloads em lote e relatórios com IA</li><li>Suporte da equipe Integra Code</li>
      </ul>
      <p class="muted small-text fh-co-secure"><?= icon('lock') ?><span data-secure-txt>Pagamento processado pelo Asaas. Seus dados trafegam criptografados.</span></p>
    </aside>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var form = document.querySelector('[data-checkout]');
  if (!form) return;
  var fmt = function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var sync = function () {
    var p = form.querySelector('input[name=plan]:checked');
    var free = p.dataset.free === '1';
    var y = !free && form.querySelector('input[name=cycle]:checked').value === 'yearly';
    document.querySelector('[data-sum-name]').textContent = 'Plano ' + p.dataset.name;
    document.querySelector('[data-sum-price]').textContent = free ? 'Grátis' : fmt(y ? p.dataset.y : p.dataset.m);
    document.querySelector('[data-sum-cycle]').textContent = free ? '' : (y ? '/ano' : '/mês');
    document.querySelector('[data-cycle-txt]').textContent = y ? 'anual' : 'mensal';
    document.querySelectorAll('[data-paid-only]').forEach(function (x) { x.classList.toggle('hidden', free); });
    document.querySelectorAll('[data-free-only]').forEach(function (x) { x.classList.toggle('hidden', !free); });
    document.querySelector('[data-submit-txt]').textContent = free ? 'Ativar plano grátis' : 'Contratar e pagar';
    document.querySelector('[data-secure-txt]').textContent = free ? 'Sem cartão e sem cobrança. Seus dados trafegam criptografados.' : 'Pagamento processado pelo Asaas. Seus dados trafegam criptografados.';
  };
  form.addEventListener('change', sync);
  sync();
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = form.querySelector('[data-err]');
    var btn = form.querySelector('[data-submit]');
    err.classList.add('hidden');
    var data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });
    data.terms = form.terms.checked;
    if (!data.terms) { err.textContent = 'Para contratar, aceite os termos de uso.'; err.classList.remove('hidden'); return; }
    btn.disabled = true; btn.classList.add('loading');
    fetch('/api/fh/checkout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': data.csrf }, body: JSON.stringify(data) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.j.error || 'Não foi possível concluir. Tente novamente.');
        if (res.j.pay_url) { window.location.href = res.j.pay_url; return; }
        if (res.j.error) {
          err.innerHTML = 'Sua assinatura foi criada, mas não conseguimos gerar a cobrança agora (' + res.j.error.replace(/</g, '&lt;') + '). Nossa equipe foi avisada. <a href="' + (res.j.portal || '/cliente/fiscal/#/assinatura') + '">Abrir minha área</a> para tentar o pagamento novamente.';
          err.classList.remove('hidden'); btn.classList.remove('loading');
          return;
        }
        window.location.href = res.j.portal || '/cliente/fiscal/#/assinatura';
      })
      .catch(function (ex) { err.textContent = ex.message; err.classList.remove('hidden'); btn.disabled = false; btn.classList.remove('loading'); });
  });
})();
</script>
<?php page_end(); ?>
