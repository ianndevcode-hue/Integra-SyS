<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';
require_once INC_PATH . '/nfse.php';
require_once INC_PATH . '/fiscalhub.php';

$all = fh_plans();
$freePlan = null;
foreach ($all as $p) if (fh_is_free($p)) { $freePlan = $p; break; }
$plans = array_values(array_filter($all, fn($p) => !fh_is_free($p)));
$sales = setting('fh_sales_enabled', '1') === '1';
$faq = [
    ['Funciona para empresas de Marília?', 'Sim. O Fiscal Hub emite direto no SIGISS da Prefeitura de Marília usando a sua inscrição municipal (CCM) e a senha do portal — sem certificado digital. Para municípios conveniados ao padrão nacional, emitimos pelo Emissor Nacional com o seu certificado A1.'],
    ['Preciso de certificado digital?', 'Para Marília (SIGISS), não. Para o Emissor Nacional, sim: um certificado A1 (e-CNPJ ou e-CPF). Você envia o arquivo .pfx uma vez e ele fica criptografado.'],
    ['Quais ramos de serviço são atendidos?', 'Todos os itens da lista de serviços (LC 116): saúde, tecnologia, consultoria, advocacia, contabilidade, construção civil (com dados da obra e deduções), eventos, educação, estética, manutenção, exportação de serviços e muito mais. O sistema trata ISS retido, imunidade, isenção, não incidência, exigibilidade suspensa, intermediário e retenções de PIS, COFINS, CSLL, IRRF e INSS.'],
    ['Como funciona a contratação?', 'Escolha o plano, aceite os termos e pague por PIX, boleto ou cartão. Assim que o pagamento é confirmado, o Fiscal Hub é liberado na sua Área do Cliente. Sem burocracia e sem fidelidade.'],
    ['Consigo baixar todas as notas para o meu contador?', 'Sim. Baixe o PDF e o XML de uma nota, das notas selecionadas ou do período inteiro em um arquivo ZIP, além de uma planilha com todos os valores e impostos.'],
    ['O plano grátis é grátis mesmo?', 'Sim. O plano Grátis emite até ' . FH_FREE_NOTES . ' notas por mês para 1 empresa, com o financeiro incluído, sem cartão de crédito e sem prazo para acabar. Quando precisar de mais notas, empresas ou recursos, mude de plano pela sua área.'],
    ['Posso cancelar quando quiser?', 'Pode. Você continua emitindo até o fim do período pago e mantém o acesso para consultar e baixar suas notas.'],
    ['O Fiscal Hub tem controle financeiro?', 'Tem. Contas a pagar e a receber (com parcelas e recorrências), fluxo de caixa com previsão, DRE e conciliação bancária. Cada nota emitida vira automaticamente uma conta a receber pelo valor líquido.'],
    ['Como o extrato do banco entra no sistema?', 'De graça, de três formas: pelo Open Finance com a sua conta Meu Pluggy, pela API oficial do Banco Inter PJ ou do Asaas, ou importando o arquivo OFX que qualquer banco gera. As movimentações são sugeridas para conciliação com as suas contas, e o sistema aprende as categorias que você usa.'],
    ['O que acontece se eu passar do limite de notas?', 'O sistema avisa quando você chega a 80% do limite. Ao atingir o limite, você pode fazer upgrade do plano ou falar com a nossa equipe.'],
];
page_start(['title' => 'Integra Fiscal Hub — emissor de nota fiscal de serviço', 'active' => '/fiscal-hub', 'description' => 'Emita NFS-e pela Prefeitura de Marília (SIGISS) e pelo Emissor Nacional em segundos. Cálculo de ISS e retenções, envio automático, downloads em lote e relatórios com IA. ' . ($freePlan ? 'Plano grátis com ' . $freePlan['notes_limit'] . ' notas por mês e p' : 'P') . 'lanos a partir de R$ ' . number_format($plans[0]['price_monthly'] ?? 0, 2, ',', '.') . '/mês.']);
?>
<section class="page-hero fh-hero">
  <div class="container ai-wrap">
    <div>
      <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Integra Fiscal Hub</span></nav>
      <span class="eyebrow" data-reveal><span class="dot"></span> Novo · Emissor de NFS-e</span>
      <h1 data-reveal>Nota fiscal de serviço <span class="grad-text">em segundos</span>, sem complicação</h1>
      <p class="lead" data-reveal>O Integra Fiscal Hub emite suas NFS-e pela Prefeitura de Marília (SIGISS) e pelo Emissor Nacional, calcula ISS e retenções, envia a nota ao seu cliente e organiza tudo para o contador — com relatórios por inteligência artificial.</p>
      <div class="hero-ctas" data-reveal>
        <a href="#planos" class="btn btn-primary btn-lg magnetic">Ver planos <?= icon('arrow') ?></a>
        <a href="#como-funciona" class="btn btn-ghost btn-lg">Como funciona</a>
      </div>
      <ul class="fh-hero-points" data-reveal><?php if ($freePlan): ?><li><?= icon('check') ?> <?= (int)$freePlan['notes_limit'] ?> notas grátis por mês</li><?php endif; ?><li><?= icon('check') ?> Contratação 100% online</li><li><?= icon('check') ?> PIX, boleto ou cartão</li><li><?= icon('check') ?> Sem fidelidade</li></ul>
    </div>
    <div class="fh-mock" data-reveal="zoom" aria-hidden="true">
      <div class="fh-mock-card">
        <div class="fh-mock-head"><b>NFS-e nº 1.284</b><span class="fh-pill ok">Emitida</span></div>
        <div class="fh-mock-row"><span>Cliente</span><b>Clínica Bem Estar Ltda</b></div>
        <div class="fh-mock-row"><span>Serviço</span><b>04.01 · Consulta médica</b></div>
        <div class="fh-mock-row"><span>Valor</span><b>R$ 1.500,00</b></div>
        <div class="fh-mock-row"><span>ISS (2%) · retido</span><b>R$ 30,00</b></div>
        <div class="fh-mock-row"><span>PIS/COFINS/CSLL/IRRF</span><b>R$ 69,75</b></div>
        <div class="fh-mock-total"><span>Líquido a receber</span><b>R$ 1.400,25</b></div>
        <div class="fh-mock-actions"><span><?= icon('file') ?> PDF</span><span><?= icon('mail') ?> Enviada ao cliente</span><span><?= icon('upload') ?> XML</span></div>
      </div>
      <div class="fh-mock-float"><?= icon('sparkles') ?> <span>IA: "Seu faturamento cresceu 18% no trimestre."</span></div>
    </div>
  </div>
</section>

<section class="section bg-alt" id="como-funciona">
  <div class="container">
    <div class="section-head center"><span class="kicker" data-reveal>Como funciona</span><h2 data-reveal>Da contratação à <span class="grad-text">primeira nota</span> em minutos</h2></div>
    <div class="grid grid-4" data-stagger="0.08">
      <?php foreach ([['cash', '1. Escolha o plano', 'Compare os 4 planos e escolha mensal ou anual (2 meses grátis).'], ['check', '2. Aceite e pague', 'Aceite os termos online e pague por PIX, boleto ou cartão pelo Asaas.'], ['lock', '3. Configure a empresa', 'Informe CNPJ, inscrição municipal e a senha do SIGISS ou o certificado A1.'], ['send', '4. Emita', 'Escolha o cliente e o serviço: o sistema calcula tudo e envia a nota.']] as [$ico, $t, $d]): ?>
        <div class="card spotlight"><div class="card-icon"><?= icon($ico) ?></div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><span class="kicker" data-reveal>Recursos</span><h2 data-reveal>Tudo o que um prestador de serviço <span class="grad-text">precisa</span></h2></div>
    <div class="grid grid-3" data-stagger>
      <?php foreach ([
          ['map', 'Marília e Emissor Nacional', 'SIGISS da Prefeitura de Marília (sem certificado) e Emissor Nacional (padrão NFS-e) para municípios conveniados.'],
          ['sheet', 'Impostos calculados', 'ISS, retenção pelo tomador ou intermediário, PIS, COFINS, CSLL, IRRF, INSS, descontos, deduções e valor líquido.'],
          ['layers', 'Todos os ramos', 'Construção civil (obra e deduções), eventos, exportação de serviços, imunidade, isenção, não incidência e exigibilidade suspensa.'],
          ['users', 'Clientes e serviços', 'Cadastro com busca automática de CNPJ e CEP, serviços com códigos da LC 116, NBS e alíquota prontos.'],
          ['mail', 'Envio automático', 'A nota vai para o e-mail do seu cliente com PDF e XML assim que é autorizada.'],
          ['upload', 'Downloads em lote', 'PDF e XML de uma nota, das selecionadas ou do período inteiro em ZIP, mais planilha para o contador.'],
          ['brain', 'Relatórios com IA', 'Faturamento, impostos, clientes e serviços, com análise por IA e alertas de limite do MEI e do Simples.'],
          ['repeat', 'Notas recorrentes', 'Mensalidades emitidas automaticamente todo mês, no dia que você escolher.'],
          ['cash', 'Financeiro completo', 'Contas a pagar e a receber, parcelas, recorrências, fluxo de caixa com previsão de 90 dias e DRE. Cada nota emitida já vira conta a receber.'],
          ['plug', 'Extrato e conciliação', 'Open Finance (Meu Pluggy), APIs do Banco Inter e do Asaas ou arquivo OFX de qualquer banco. Sugestões automáticas e regras que aprendem com você.'],
          ['shield', 'Seguro e em conformidade', 'XML validado no leiaute oficial, certificado e senhas criptografados, cancelamento e substituição de notas.'],
      ] as [$ico, $t, $d]): ?>
        <div class="card spotlight" data-tilt="6"><div class="card-icon"><?= icon($ico) ?></div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section bg-alt" id="planos">
  <div class="container">
    <div class="section-head center"><span class="kicker" data-reveal>Planos</span><h2 data-reveal>Preço justo, <span class="grad-text">10% abaixo da média</span> do mercado</h2>
      <p data-reveal>Pesquisamos os emissores de NFS-e usados em Marília e no Brasil e definimos nossos preços 10% abaixo da média de cada faixa.</p></div>
    <?php if ($freePlan): ?>
    <div class="card fh-free" data-reveal>
      <div class="fh-free-main"><span class="fh-free-tag">Grátis para sempre</span><h3>Plano <?= e($freePlan['name']) ?> — <?= (int)$freePlan['notes_limit'] ?> notas por mês</h3>
        <p class="muted">Sem cartão de crédito e sem prazo para acabar. Emissão no SIGISS de Marília e no Emissor Nacional, financeiro com contas a pagar e a receber, fluxo de caixa e conciliação bancária.</p></div>
      <div class="fh-free-cta"><div class="fh-price"><small>R$</small><b>0</b><span>/mês</span></div>
        <?php if ($sales): ?><a class="btn btn-primary btn-lg" href="/fiscal-hub-contratar?plano=<?= e($freePlan['code']) ?>">Começar grátis <?= icon('arrow') ?></a><?php endif; ?>
        <small class="muted">Precisa de mais? Mude de plano quando quiser.</small></div>
    </div>
    <?php endif; ?>
    <div class="fh-cycle" data-reveal role="group" aria-label="Ciclo de cobrança"><button type="button" class="active" data-cycle="monthly">Mensal</button><button type="button" data-cycle="yearly">Anual <em>2 meses grátis</em></button></div>
    <div class="fh-pricing" data-stagger="0.06">
      <?php foreach ($plans as $p): ?>
        <article class="card fh-price-card <?= $p['highlight'] ? 'hl' : '' ?>">
          <?php if ($p['highlight']): ?><span class="fh-ribbon">Mais escolhido</span><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <p class="muted"><?= e($p['tagline']) ?></p>
          <div class="fh-price" data-monthly="<?= e(number_format($p['price_monthly'], 2, ',', '.')) ?>" data-yearly="<?= e(number_format($p['price_yearly'], 2, ',', '.')) ?>">
            <small>R$</small><b><?= e(number_format($p['price_monthly'], 2, ',', '.')) ?></b><span>/mês</span>
          </div>
          <p class="fh-price-note" data-note-m>ou <?= e(money($p['price_yearly'])) ?> por ano</p>
          <p class="fh-price-note hidden" data-note-y>equivale a <?= e(money($p['price_yearly'] / 12)) ?>/mês</p>
          <p class="fh-market">Média de mercado: <s><?= e(money($p['market_avg'])) ?></s></p>
          <ul class="check-list"><?php foreach ($p['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <?php if ($sales): ?>
            <a class="btn <?= $p['highlight'] ? 'btn-primary' : 'btn-ghost' ?> btn-block" data-plan-link href="/fiscal-hub-contratar?plano=<?= e($p['code']) ?>">Contratar <?= e($p['name']) ?></a>
          <?php else: ?>
            <a class="btn btn-ghost btn-block" href="/contato">Fale com a equipe</a>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="center muted small fh-plans-note" style="margin-top:18px">Preços em reais. Contratação sem fidelidade, pagamento via Asaas (PIX, boleto ou cartão). Ao contratar você aceita os <a href="/fiscal-hub-termos">Termos de Uso do Integra Fiscal Hub</a>.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head"><span class="kicker" data-reveal>Compare</span><h2 data-reveal>O que cada plano <span class="grad-text">inclui</span></h2></div>
    <?php $cmp = $freePlan ? array_merge([$freePlan], $plans) : $plans; ?>
    <div class="fh-compare-wrap" data-reveal><table class="fh-compare">
      <thead><tr><th>Recurso</th><?php foreach ($cmp as $p): ?><th><?= e($p['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <tr><td>Notas por mês</td><?php foreach ($cmp as $p): ?><td><b><?= number_format($p['notes_limit'], 0, ',', '.') ?></b></td><?php endforeach; ?></tr>
        <tr><td>Empresas (CNPJ/CPF)</td><?php foreach ($cmp as $p): ?><td><?= (int)$p['companies_limit'] ?></td><?php endforeach; ?></tr>
        <tr><td>SIGISS Marília + Emissor Nacional</td><?php foreach ($cmp as $p): ?><td><?= icon('check') ?></td><?php endforeach; ?></tr>
        <tr><td>Retenções federais, obra, evento, exportação</td><?php foreach ($cmp as $p): ?><td><?= icon('check') ?></td><?php endforeach; ?></tr>
        <tr><td>Downloads PDF/XML em lote e planilha</td><?php foreach ($cmp as $p): ?><td><?= icon('check') ?></td><?php endforeach; ?></tr>
        <tr><td>Financeiro: contas, fluxo de caixa, DRE e conciliação</td><?php foreach ($cmp as $p): ?><td><?= icon('check') ?></td><?php endforeach; ?></tr>
        <tr><td>Análises com IA por mês</td><?php foreach ($cmp as $p): ?><td><?= (int)$p['flags']['ai_quota'] ?></td><?php endforeach; ?></tr>
        <tr><td>Notas recorrentes automáticas</td><?php foreach ($cmp as $p): ?><td><?= $p['flags']['recurring'] ? icon('check') : '—' ?></td><?php endforeach; ?></tr>
        <tr><td>Emissão em lote por planilha</td><?php foreach ($cmp as $p): ?><td><?= $p['flags']['batch'] ? icon('check') : '—' ?></td><?php endforeach; ?></tr>
        <tr><td>Suporte prioritário</td><?php foreach ($cmp as $p): ?><td><?= $p['flags']['priority'] ? icon('check') : '—' ?></td><?php endforeach; ?></tr>
        <tr><td>Preço mensal</td><?php foreach ($cmp as $p): ?><td><b><?= fh_is_free($p) ? 'Grátis' : e(money($p['price_monthly'])) ?></b></td><?php endforeach; ?></tr>
      </tbody></table></div>
  </div>
</section>

<section class="section bg-alt">
  <div class="container contact-grid">
    <div>
      <span class="kicker" data-reveal>Dúvidas frequentes</span>
      <h2 data-reveal style="font-size:clamp(2rem,4.4vw,3rem)">Perguntas sobre o <span class="grad-text">Fiscal Hub</span></h2>
      <p class="muted" data-reveal>Não encontrou sua resposta? Fale com a nossa equipe.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:24px" data-reveal><a href="<?= e(COMPANY['whatsapp']) ?>" class="btn btn-ghost" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Falar no WhatsApp</a><a href="/fiscal-hub-termos" class="btn btn-ghost"><?= icon('file') ?> Termos de uso</a></div>
    </div>
    <div class="accordion" data-single data-stagger>
      <?php foreach ($faq as [$q, $a]): ?>
        <div class="acc-item"><button class="acc-btn" aria-expanded="false"><?= e($q) ?> <?= icon('chevron') ?></button><div class="acc-panel"><div><div class="acc-content"><?= e($a) ?></div></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" style="padding-top:clamp(40px,6vw,70px)">
  <div class="container">
    <div class="cta-band" data-reveal="zoom">
      <span class="kicker">Integra Fiscal Hub</span>
      <h2>Pare de perder tempo no portal da prefeitura</h2>
      <p>Contrate agora, configure em minutos e emita sua próxima nota em segundos.</p>
      <div class="actions"><a href="#planos" class="btn btn-primary btn-lg magnetic">Escolher meu plano <?= icon('arrow') ?></a><a href="/agendar" class="btn btn-ghost btn-lg">Agendar demonstração</a></div>
    </div>
  </div>
</section>
<script>
document.querySelectorAll('[data-cycle]').forEach(function (b) {
  b.addEventListener('click', function () {
    var y = b.dataset.cycle === 'yearly';
    document.querySelectorAll('[data-cycle]').forEach(function (x) { x.classList.toggle('active', x === b); });
    document.querySelectorAll('.fh-price').forEach(function (p) { p.querySelector('b').textContent = y ? p.dataset.yearly : p.dataset.monthly; p.querySelector('span').textContent = y ? '/ano' : '/mês'; });
    document.querySelectorAll('[data-note-m]').forEach(function (n) { n.classList.toggle('hidden', y); });
    document.querySelectorAll('[data-note-y]').forEach(function (n) { n.classList.toggle('hidden', !y); });
    document.querySelectorAll('[data-plan-link]').forEach(function (a) { a.href = a.href.replace(/&ciclo=\w+/, '') + (y ? '&ciclo=yearly' : ''); });
  });
});
</script>
<?php page_end(); ?>
