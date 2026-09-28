<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

if (!is_installed()) {
    header('Location: /install.php');
    exit;
}

$posts = db_all('SELECT slug, title, excerpt, category, cover_image, reading_minutes, published_at FROM posts WHERE published = 1 ORDER BY published_at DESC LIMIT 3');
$faq = db_all('SELECT id, question, answer FROM help_articles WHERE published = 1 ORDER BY helpful DESC, position LIMIT 5');

page_start(['active' => '/', 'description' => COMPANY['pitch'] . ' Sistemas web, apps, IA, integrações e automação sob medida para PMEs.']);
?>

<!-- ============================================================ HERO -->
<section class="hero" aria-label="Apresentação">
  <canvas class="hero-canvas" aria-hidden="true"></canvas>
  <div class="hero-grid-bg" aria-hidden="true"></div>
  <div class="hero-glow g1" aria-hidden="true"></div>
  <div class="hero-glow g2" aria-hidden="true"></div>
  <div class="hero-glow g3" aria-hidden="true"></div>

  <div class="container hero-inner">
    <div class="hero-copy">
      <span class="eyebrow" data-reveal><span class="dot"></span> Desenvolvimento sob medida para PMEs</span>
      <h1>
        <span class="split-line"><span style="--d:.05s">Tecnologia que</span></span>
        <span class="split-line"><span style="--d:.15s"><span class="grad-text">simplifica</span> o seu</span></span>
        <span class="split-line"><span style="--d:.25s">dia a dia</span></span>
      </h1>
      <p class="lead" data-reveal style="--d:.35s">
        <?= e(COMPANY['pitch']) ?> Criamos <span class="typed" aria-live="polite">sistemas web</span>
        que conectam, automatizam e fazem sua empresa crescer.
      </p>
      <div class="hero-ctas" data-reveal style="--d:.45s">
        <a href="/diagnostico" class="btn btn-primary btn-lg magnetic">Solicitar diagnóstico <?= icon('arrow') ?></a>
        <a href="/agendar" class="btn btn-ghost btn-lg magnetic"><?= icon('calendar') ?> Agendar apresentação</a>
      </div>
      <div class="hero-trust" data-reveal style="--d:.55s">
        <span><?= icon('shield') ?> Conformidade LGPD</span>
        <span><?= icon('clock') ?> Suporte com SLA</span>
        <span><?= icon('handshake') ?> Atendimento próximo</span>
      </div>
      <div class="hero-slides-nav" role="tablist" aria-label="Destaques" data-reveal style="--d:.65s">
        <button class="slide-tab" role="tab" data-word="sistemas web"><b>Gestão</b><span>Sistemas & BI</span><i class="bar"></i></button>
        <button class="slide-tab" role="tab" data-word="soluções com IA"><b>IA aplicada</b><span>Chatbots & previsões</span><i class="bar"></i></button>
        <button class="slide-tab" role="tab" data-word="integrações"><b>Integrações</b><span>iFood, ML, bancos</span><i class="bar"></i></button>
      </div>
    </div>

    <div class="hero-visual" aria-hidden="true" data-reveal="zoom" style="--d:.3s">
      <div class="hv-stage">
        <div class="hv-layer hero-mark-wrap" data-depth="-0.4"><?= brand_mark('hero-mark') ?></div>
        <div class="hv-layer hv-main glass" data-depth="0.4">
          <div class="hv-top"><i></i><i></i><i></i><span>integra-sys · painel</span></div>
          <div class="hv-panels">
            <div class="hv-panel">
              <div class="kpis">
                <div class="kpi"><small>Faturamento</small><strong>R$ 184k</strong> <em>▲ 12%</em></div>
                <div class="kpi"><small>Pedidos</small><strong>1.248</strong> <em>▲ 8%</em></div>
                <div class="kpi"><small>Ticket médio</small><strong>R$ 147</strong> <em>▲ 3%</em></div>
              </div>
              <div class="bars">
                <?php foreach ([42, 58, 50, 72, 64, 80, 70, 92, 84, 100] as $i => $hgt): ?><span style="--h:<?= $hgt ?>%;--i:<?= $i ?>"></span><?php endforeach; ?>
              </div>
            </div>
            <div class="hv-panel">
              <div class="chat-line user">Qual a previsão de vendas para dezembro?</div>
              <div class="chat-line bot">Com base nos últimos 3 anos, a previsão é de <b>+34%</b> em relação a novembro. Sugiro reforçar o estoque de 18 itens.</div>
              <div class="chat-line user">Gere o pedido de compra.</div>
              <div class="chat-line bot">Pronto! Pedido #4821 criado e enviado para aprovação. ✅
                <div class="mini-chart"><?php foreach ([30, 45, 40, 60, 55, 75, 90] as $hh): ?><i style="height:<?= $hh ?>%"></i><?php endforeach; ?></div>
              </div>
            </div>
            <div class="hv-panel">
              <div class="hub">
                <div class="hub-ring">
                  <?php foreach (['iFood', 'M. Livre', 'WhatsApp', 'PIX', 'Stone', 'NF-e'] as $k => $n): ?>
                    <div class="hub-node" style="--a:<?= $k * 60 ?>deg"><?= $n ?></div>
                  <?php endforeach; ?>
                </div>
                <div class="hub-core"><?= icon('plug') ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="hv-layer float-card glass hv-f1" data-depth="1.1">
          <div class="fc-ico"><?= icon('check') ?></div>
          <div><b>Pagamento recebido</b><small>PIX · R$ 2.450,00 conciliado</small></div>
        </div>
        <div class="hv-layer float-card glass alt hv-f2" data-depth="1.4">
          <div class="fc-ico"><?= icon('bolt') ?></div>
          <div><b>Automação executada</b><small>32 cobranças enviadas</small></div>
        </div>
      </div>
    </div>
  </div>
  <a href="#dificuldades" class="scroll-cue" aria-label="Rolar para o conteúdo"></a>
</section>

<!-- ============================================================ MARQUEE -->
<div class="marquee" aria-label="Tecnologias e integrações">
  <div class="marquee-track">
    <?php $techs = ['iFood', 'Mercado Livre', 'Stone', 'WhatsApp Business', 'PIX', 'Asaas', 'NF-e / NFS-e', 'Inteligência Artificial', 'Power BI', 'APIs REST', 'Cloud', 'LGPD'];
    foreach (array_merge($techs, $techs) as $t): ?><span><?= e($t) ?></span><?php endforeach; ?>
  </div>
</div>

<!-- ============================================================ PAINS -->
<section class="section" id="dificuldades">
  <div class="container">
    <div class="section-head center">
      <span class="kicker" data-reveal>Dificuldades que resolvemos</span>
      <h2 data-reveal>Sua empresa ainda sofre com <span class="grad-text">algum destes problemas?</span></h2>
      <p data-reveal>Se você se identificou com pelo menos um, a tecnologia certa pode devolver horas ao seu dia e dinheiro ao seu caixa.</p>
    </div>
    <div class="grid grid-3" data-stagger>
      <?php foreach (pains() as [$ico, $title, $text]): ?>
        <article class="card spotlight pain">
          <div class="card-icon"><?= icon($ico) ?></div>
          <div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ SOLUTIONS -->
<section class="section bg-alt" id="solucoes">
  <div class="container">
    <div class="section-head">
      <span class="kicker" data-reveal>Soluções</span>
      <h2 data-reveal>Tudo o que seu negócio precisa, <span class="grad-text">em um só parceiro</span></h2>
      <p data-reveal>Do diagnóstico à evolução contínua: desenvolvemos, integramos e cuidamos da sua tecnologia.</p>
    </div>
    <div class="grid grid-4" data-stagger="0.06">
      <?php foreach (services() as $slug => $s): ?>
        <a href="/solucoes#<?= e($slug) ?>" class="card spotlight" data-tilt="6">
          <div class="card-icon"><?= icon($s['icon']) ?></div>
          <h3><?= e($s['title']) ?></h3>
          <p><?= e($s['short']) ?></p>
          <span class="more">Saiba mais <?= icon('arrow') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ PARALLAX BAND -->
<section class="parallax-band" aria-label="Manifesto">
  <div class="band-art" data-parallax=".12" aria-hidden="true"></div>
  <div class="px-shape ring" data-parallax="-.25" style="width:220px;height:220px;left:6%;top:14%"></div>
  <div class="px-shape cube" data-parallax=".35" data-rotate=".08" style="width:110px;height:110px;right:10%;top:18%"></div>
  <div class="px-shape dotgrid" data-parallax="-.15" style="width:180px;height:140px;right:22%;bottom:10%"></div>
  <div class="px-shape cube" data-parallax="-.4" data-rotate="-.06" style="width:70px;height:70px;left:16%;bottom:14%"></div>
  <div class="px-shape code" data-parallax=".2" style="left:4%;bottom:30%">sync(<b>pedidos</b>, estoque)</div>
  <div class="px-shape code" data-parallax="-.3" style="right:4%;top:42%">await <b>conciliar</b>(extrato)</div>
  <div class="container">
    <h2 data-reveal>Seu negócio,<br><span class="grad-text">integrado.</span></h2>
    <p data-reveal>Vendas, estoque, financeiro e atendimento conversando entre si. Menos digitação, menos erros, mais decisões certas.</p>
  </div>
</section>

<!-- ============================================================ METHOD -->
<section class="section" id="como-trabalhamos">
  <div class="container">
    <div class="section-head center">
      <span class="kicker" data-reveal>Como trabalhamos</span>
      <h2 data-reveal>Um processo claro, <span class="grad-text">do início ao fim</span></h2>
      <p data-reveal>Transparência em cada etapa, com entregas frequentes para você acompanhar a evolução.</p>
    </div>
    <div class="timeline" data-timeline>
      <div class="timeline-line"><i></i></div>
      <?php foreach (methodology() as $i => [$n, $title, $text]): ?>
        <div class="step" data-reveal style="--d:<?= $i * .1 ?>s">
          <div class="step-num"><?= $n ?></div>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ SEGMENTS -->
<section class="section bg-alt" id="segmentos">
  <div class="container">
    <div class="section-head">
      <span class="kicker" data-reveal>Segmentos</span>
      <h2 data-reveal>Experiência em quem <span class="grad-text">faz a economia girar</span></h2>
    </div>
    <div class="seg-wrap" data-tabs>
      <div class="seg-list" role="tablist" aria-label="Segmentos atendidos">
        <?php $first = true; foreach (segments() as $slug => [$ico, $name]): ?>
          <button class="seg-btn <?= $first ? 'active' : '' ?>" role="tab" data-tab="<?= $slug ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= icon($ico) ?> <?= e($name) ?></button>
        <?php $first = false; endforeach; ?>
      </div>
      <div>
        <?php $first = true; foreach (segments() as $slug => [$ico, $name, $desc, $features]): ?>
          <div class="seg-panel <?= $first ? 'active' : '' ?>" data-panel="<?= $slug ?>" role="tabpanel">
            <div class="seg-detail">
              <?= icon($ico, 'big-ico') ?>
              <span class="tag tag-primary"><?= icon($ico) ?> <?= e($name) ?></span>
              <h3 style="margin-top:16px"><?= e($desc) ?></h3>
              <ul class="check-list" style="margin:24px 0 28px">
                <?php foreach ($features as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
              </ul>
              <a href="/segmentos#<?= $slug ?>" class="btn btn-ghost btn-sm">Ver solução para <?= e(mb_strtolower($name)) ?> <?= icon('arrow') ?></a>
            </div>
          </div>
        <?php $first = false; endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ AI -->
<section class="section" id="ia">
  <div class="container ai-wrap">
    <div>
      <span class="kicker" data-reveal>Inteligência Artificial</span>
      <h2 data-reveal style="font-size:clamp(2rem,4.4vw,3.2rem)">Pergunte ao seu negócio. <span class="grad-text">Ele responde.</span></h2>
      <p class="muted" data-reveal style="font-size:1.1rem">Com BI conversacional, você consulta vendas, estoque e financeiro em português, sem planilhas nem relatórios complicados. Experimente a demonstração ao lado.</p>
      <ul class="check-list" data-stagger style="margin:26px 0 32px">
        <li>Chatbots de atendimento 24h no WhatsApp</li>
        <li>Previsão de vendas e reposição de estoque</li>
        <li>Leitura automática de notas e documentos</li>
        <li>Alertas inteligentes de inadimplência</li>
      </ul>
      <a href="/diagnostico" class="btn btn-primary magnetic" data-reveal>Descobrir onde a IA ajuda minha empresa <?= icon('arrow') ?></a>
    </div>
    <div class="glass ai-demo" data-ai-demo data-reveal="right">
      <div class="hv-top"><i></i><i></i><i></i><span>BI conversacional · demonstração ilustrativa</span></div>
      <div class="ai-log" aria-live="polite"></div>
      <div class="ai-suggest">
        <button type="button">Quais clientes estão inadimplentes?</button>
        <button type="button">Qual produto devo repor?</button>
        <button type="button">Previsão de caixa para 30 dias</button>
      </div>
      <form class="chat-form" style="padding:0;border:0">
        <input placeholder="Pergunte algo sobre o negócio..." aria-label="Pergunta para a demonstração">
        <button aria-label="Perguntar"><?= icon('send') ?></button>
      </form>
    </div>
  </div>
</section>

<!-- ============================================================ INTEGRA SYS -->
<section class="section bg-alt" id="integra-sys">
  <div class="container ai-wrap">
    <div class="orbit" data-reveal="zoom" aria-hidden="true">
      <div class="orbit-ring">
        <?php foreach (sys_modules() as $k => [$ico, $name]): ?>
          <div class="orbit-item" style="--a:<?= $k * 45 ?>deg"><div><?= icon($ico) ?><?= e($name) ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="orbit-core">Integra<br>SYS</div>
    </div>
    <div>
      <span class="kicker" data-reveal>Produto</span>
      <h2 data-reveal style="font-size:clamp(2rem,4.4vw,3.2rem)">Conheça o <span class="grad-text">Integra SYS</span></h2>
      <p class="muted" data-reveal style="font-size:1.1rem">Sistema de gestão modular na nuvem para PMEs: financeiro, estoque, vendas, CRM, fiscal e BI em uma única plataforma — pronta para ser adaptada ao seu segmento.</p>
      <div class="grid grid-2" data-stagger style="margin:26px 0 30px">
        <?php foreach (array_slice(sys_modules(), 0, 4) as [$ico, $name, $desc]): ?>
          <div style="display:flex;gap:12px"><span class="card-icon" style="width:42px;height:42px;margin:0;flex:none"><?= icon($ico) ?></span><div><b><?= e($name) ?></b><br><small class="muted"><?= e($desc) ?></small></div></div>
        <?php endforeach; ?>
      </div>
      <a href="/integra-sys" class="btn btn-primary magnetic" data-reveal>Explorar módulos <?= icon('arrow') ?></a>
    </div>
  </div>
</section>

<!-- ============================================================ DIFFERENTIALS + STATS -->
<section class="section">
  <div class="container">
    <div class="section-head center">
      <span class="kicker" data-reveal>Diferenciais</span>
      <h2 data-reveal>Por que escolher a <span class="grad-text">Integra Code</span></h2>
    </div>
    <div class="grid grid-4" data-stagger>
      <?php foreach (differentials() as [$ico, $title, $text]): ?>
        <div class="card spotlight"><div class="card-icon"><?= icon($ico) ?></div><h3><?= e($title) ?></h3><p><?= e($text) ?></p></div>
      <?php endforeach; ?>
    </div>
    <div class="stats" style="margin-top:28px" data-stagger>
      <div class="stat"><strong class="grad-text" data-count="8">0</strong><span>áreas de solução</span></div>
      <div class="stat"><strong class="grad-text" data-count="5">0</strong><span>etapas de método</span></div>
      <div class="stat"><strong class="grad-text" data-count="24" data-suffix="/7">0</strong><span>monitoramento com SLA</span></div>
      <div class="stat"><strong class="grad-text" data-count="100" data-suffix="%">0</strong><span>adequado à LGPD</span></div>
    </div>
  </div>
</section>

<!-- ============================================================ BLOG -->
<?php if ($posts): ?>
<section class="section bg-alt">
  <div class="container">
    <div class="section-head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;max-width:none;flex-wrap:wrap">
      <div><span class="kicker" data-reveal>Blog</span><h2 data-reveal style="margin:0">Conteúdo para <span class="grad-text">decidir melhor</span></h2></div>
      <a href="/blog" class="btn btn-ghost" data-reveal>Ver todos os artigos <?= icon('arrow') ?></a>
    </div>
    <div class="grid grid-3" data-stagger>
      <?php foreach ($posts as $p): ?>
        <a class="card spotlight post-card" href="/blog/<?= e($p['slug']) ?>">
          <div class="post-cover"><?= $p['cover_image'] ? '<img src="' . e($p['cover_image']) . '" alt="" loading="lazy">' : post_art($p['slug']) ?></div>
          <div class="post-body">
            <div class="post-meta"><span class="tag tag-primary"><?= e($p['category']) ?></span><span><?= date('d/m/Y', strtotime($p['published_at'])) ?></span><span><?= (int)$p['reading_minutes'] ?> min</span></div>
            <h3><?= e($p['title']) ?></h3>
            <p><?= e($p['excerpt']) ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============================================================ FAQ -->
<section class="section">
  <div class="container" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:48px;align-items:start">
    <div>
      <span class="kicker" data-reveal>Perguntas frequentes</span>
      <h2 data-reveal style="font-size:clamp(2rem,4.4vw,3rem)">Ficou alguma <span class="grad-text">dúvida?</span></h2>
      <p class="muted" data-reveal>Reunimos as perguntas mais comuns. Não achou a sua? Nossa Central de Ajuda tem muito mais<?= chat_enabled() ? ' — ou fale com o assistente virtual' : '' ?>.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:24px" data-reveal>
        <a href="/suporte" class="btn btn-ghost"><?= icon('help') ?> Central de Ajuda</a>
        <?php if (chat_enabled()): ?><button class="btn btn-ghost" data-open-chat><?= icon('chat') ?> Falar com o assistente</button>
        <?php else: ?><a href="<?= e(COMPANY['whatsapp']) ?>" class="btn btn-ghost" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Falar no WhatsApp</a><?php endif; ?>
      </div>
    </div>
    <div class="accordion" data-single data-stagger>
      <?php foreach ($faq as $f): ?>
        <div class="acc-item" data-article-id="<?= (int)$f['id'] ?>">
          <button class="acc-btn" aria-expanded="false"><?= e($f['question']) ?> <?= icon('chevron') ?></button>
          <div class="acc-panel"><div><div class="acc-content"><?= nl2br(e($f['answer'])) ?></div></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============================================================ CTA -->
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="cta-band" data-reveal="zoom">
      <span class="kicker">Diagnóstico gratuito</span>
      <h2>Descubra em 2 minutos o que a tecnologia pode fazer pela sua empresa</h2>
      <p>Responda o diagnóstico online e receba um retrato da maturidade digital do seu negócio, com recomendações práticas e sem compromisso.</p>
      <div class="actions">
        <a href="/diagnostico" class="btn btn-primary btn-lg magnetic">Fazer diagnóstico agora <?= icon('arrow') ?></a>
        <a href="<?= e(COMPANY['whatsapp']) ?>" class="btn btn-ghost btn-lg" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Conversar no WhatsApp</a>
      </div>
    </div>
  </div>
</section>

<?php page_end(['assets/js/hero.js']);
