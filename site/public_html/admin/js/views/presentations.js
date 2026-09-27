import { api, state, $, $$, esc, icon, datetime, relative, toast, toastError, formModal, confirmDialog, modal, emptyState, lookups, copyText, debounce, today } from '../core.js';

const KIND_ICON = { proposal: 'send', results: 'trendUp', qbr: 'pie', kickoff: 'play', institutional: 'globe', report: 'flow' };
const LAYOUTS = {
  cover: ['Capa', [['eyebrow', 'Selo (ex.: Proposta comercial)'], ['title', 'Título'], ['subtitle', 'Subtítulo', 'textarea'], ['client', 'Preparado para'], ['date', 'Data']]],
  agenda: ['Agenda', [['title', 'Título'], ['items', 'Itens (um por linha)', 'lines']]],
  section: ['Divisória', [['number', 'Número (ex.: 02)'], ['title', 'Título'], ['subtitle', 'Subtítulo']]],
  bullets: ['Cartões / tópicos', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['style', 'Estilo', 'select', [['', 'Numerado'], ['benefit', 'Benefícios (✓ verde)'], ['pain', 'Problemas (✗ vermelho)']]], ['items', 'Cartões', 'objects', [['title', 'Título'], ['text', 'Texto', 'textarea']]]]],
  kpis: ['Indicadores', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['items', 'Indicadores', 'objects', [['value', 'Valor'], ['label', 'Rótulo'], ['note', 'Observação']]]]],
  chart: ['Gráfico', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['insight', 'Destaque ao lado do gráfico', 'textarea']]],
  timeline: ['Linha do tempo', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['items', 'Etapas', 'objects', [['title', 'Etapa'], ['date', 'Data / período'], ['text', 'Descrição', 'textarea'], ['status', 'Situação', 'select', [['next', 'Próxima'], ['current', 'Atual'], ['done', 'Concluída']]]]]]],
  comparison: ['Comparação', [['title', 'Título'], ['left_title', 'Título da esquerda'], ['left', 'Itens da esquerda (um por linha)', 'lines'], ['right_title', 'Título da direita'], ['right', 'Itens da direita (um por linha)', 'lines']]],
  pricing: ['Investimento', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['items', 'Itens', 'objects', [['description', 'Descrição'], ['detail', 'Detalhe'], ['amount', 'Valor (R$)']]], ['total', 'Total (texto)'], ['terms', 'Condições (uma por linha)', 'lines']]],
  quote: ['Citação / mensagem', [['text', 'Mensagem', 'textarea'], ['author', 'Autor']]],
  about: ['Sobre a Integra Code', [['title', 'Título'], ['lead', 'Texto', 'textarea'], ['stats', 'Números', 'objects', [['value', 'Valor'], ['label', 'Rótulo']]], ['items', 'Diferenciais', 'objects', [['title', 'Título'], ['text', 'Texto', 'textarea']]]]],
  table: ['Tabela', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['columns', 'Colunas (uma por linha)', 'lines'], ['rows', 'Linhas (uma por linha, células separadas por |)', 'rows']]],
  closing: ['Encerramento', [['title', 'Título'], ['lead', 'Texto de apoio', 'textarea'], ['steps', 'Próximos passos (um por linha)', 'lines']]],
};
const TEMPLATES = {
  cover: { layout: 'cover', eyebrow: 'Integra Code', title: 'Novo título', subtitle: '', client: '', date: '' },
  agenda: { layout: 'agenda', title: 'Agenda', items: ['Tópico 1', 'Tópico 2', 'Tópico 3'] },
  section: { layout: 'section', number: '01', title: 'Nova seção', subtitle: '' },
  bullets: { layout: 'bullets', title: 'Título', lead: '', items: [{ title: 'Ponto 1', text: '' }, { title: 'Ponto 2', text: '' }, { title: 'Ponto 3', text: '' }] },
  kpis: { layout: 'kpis', title: 'Indicadores', lead: '', items: [{ value: '0', label: 'Indicador', note: '' }, { value: '0', label: 'Indicador', note: '' }, { value: '0', label: 'Indicador', note: '' }] },
  timeline: { layout: 'timeline', title: 'Cronograma', lead: '', items: [{ title: 'Etapa 1', date: '', text: '', status: 'done' }, { title: 'Etapa 2', date: '', text: '', status: 'current' }, { title: 'Etapa 3', date: '', text: '', status: 'next' }] },
  comparison: { layout: 'comparison', title: 'Antes e depois', left_title: 'Hoje', left: ['Item'], right_title: 'Com a Integra Code', right: ['Item'] },
  pricing: { layout: 'pricing', title: 'Investimento', lead: '', items: [{ description: 'Item', detail: '', amount: 0 }], total: 'R$ 0,00', terms: ['Condição'] },
  quote: { layout: 'quote', text: 'Mensagem de destaque.', author: 'Integra Code' },
  table: { layout: 'table', title: 'Tabela', lead: '', columns: ['Coluna 1', 'Coluna 2'], rows: [['A', 'B']] },
  closing: { layout: 'closing', title: 'Obrigado!', lead: 'Próximos passos:', steps: ['Passo 1'], contact: { phone: '(14) 99853-7913', email: 'dev@integra-code.tech', site: 'integra-code.tech' } },
};

export async function render(el, ctx) {
  return ctx.id ? renderEditor(el, ctx) : renderList(el, ctx);
}

/* ================================================================ LIST */
async function renderList(el, ctx) {
  const d = await api('/presentations');
  el.innerHTML = `
    <div class="page-head"><div><h2>Apresentações</h2><p>Propostas, resultados, revisões e kickoffs com a identidade Integra Code${d.ai ? ', escritos com IA' : ''} e números reais do sistema.</p></div>
      <div class="page-actions"><a class="btn" href="#/reports">${icon('pie')} Relatórios</a><button class="btn btn-primary" data-new>${icon('sparkles')} Nova apresentação</button></div></div>
    ${!d.ai ? `<div class="alert alert-info" style="margin-bottom:14px">${icon('sparkles')} A IA não está configurada: as apresentações são montadas com textos automáticos a partir dos dados. Configure a Cloudflare em <a href="#/settings">Configurações</a> para textos personalizados por IA.</div>` : ''}
    <div class="kind-strip">${Object.entries(d.kinds).map(([k, v]) => `<button class="kind-tile" data-kind="${k}">${icon(KIND_ICON[k])}<b>${esc(v.label)}</b><small>${esc(v.description)}</small></button>`).join('')}</div>
    ${d.data.length ? `<div class="deck-grid">${d.data.map((p) => `
      <article class="deck-card" data-id="${p.id}">
        <a class="deck-thumb t-${esc(p.theme)}" href="#/presentations/${p.id}"><span>${icon(KIND_ICON[p.kind] || 'play')}</span><b>${esc(p.title)}</b></a>
        <div class="deck-meta"><span class="badge ${p.ai_generated == 1 ? 'violet' : ''}">${esc(d.kinds[p.kind]?.label || p.kind)}${p.ai_generated == 1 ? ' · IA' : ''}</span>
          <small class="muted">${esc(p.customer_name || p.lead_name || p.project_name || '')}${p.customer_name || p.lead_name || p.project_name ? ' · ' : ''}${relative(p.updated_at)}</small>
          <small class="muted">${Number(p.shared) ? `${icon('eye')} ${p.views} visualização(ões)${p.last_viewed_at ? ' · última ' + relative(p.last_viewed_at) : ''}` : `${icon('lock')} Privada`}</small></div>
        <div class="deck-actions">
          <a class="btn btn-xs btn-primary" href="#/presentations/${p.id}">${icon('edit')} Editar</a>
          <a class="btn btn-xs" href="/apresentacao?id=${p.id}" target="_blank" rel="noopener">${icon('play')} Apresentar</a>
          ${Number(p.shared) ? `<button class="btn btn-xs" data-copy="${esc(location.origin + '/apresentacao?t=' + p.share_token)}">${icon('link')} Link</button>` : ''}
          <button class="btn btn-xs btn-ghost" data-dup title="Duplicar">${icon('copy')}</button>
          <button class="btn btn-xs btn-ghost" data-del title="Excluir">${icon('trash')}</button>
        </div>
      </article>`).join('')}</div>` : emptyState('Nenhuma apresentação ainda. Escolha um tipo acima para criar a primeira.', 'play')}`;
  $$('[data-kind]', el).forEach((b) => b.addEventListener('click', () => wizard(b.dataset.kind, d)));
  $('[data-new]', el).addEventListener('click', () => wizard('proposal', d));
  $$('[data-copy]', el).forEach((b) => b.addEventListener('click', () => copyText(b.dataset.copy)));
  $$('.deck-card', el).forEach((c) => {
    const id = c.dataset.id;
    $('[data-dup]', c).addEventListener('click', async () => { const r = await api(`/presentations/${id}/duplicate`, { method: 'POST' }); toast('Apresentação duplicada.'); location.hash = '#/presentations/' + r.id; });
    $('[data-del]', c).addEventListener('click', async () => { if (!await confirmDialog('Excluir esta apresentação? O link compartilhado deixa de funcionar.', { danger: true })) return; await api('/presentations/' + id, { method: 'DELETE' }); renderList(el, ctx); });
  });
  const q = ctx.query;
  if (q.new) wizard(q.new, d, q);
}

/** Wizard for the chosen kind. `pre` can preset customer_id / lead_id / project_id. */
export async function wizard(kind, d, pre = {}) {
  d = d || await api('/presentations');
  const lk = await lookups();
  const leads = kind === 'proposal' ? (await api('/leads', { query: { per_page: 200, sort: 'created_at', dir: 'desc' } })).data : [];
  const q = new Date(); const past = (m) => { const x = new Date(q.getFullYear(), q.getMonth() - m, 1); return new Date(x.getTime() - x.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const common = [
    { name: 'theme', label: 'Tema visual', type: 'select', empty: false, options: [{ value: 'dark', label: 'Escuro (padrão da marca)' }, { value: 'brand', label: 'Azul Integra Code' }, { value: 'light', label: 'Claro (impressão)' }], default: kind === 'results' ? 'brand' : 'dark' },
    { name: 'tone', label: 'Tom do texto', type: 'select', empty: false, options: [{ value: 'consultivo', label: 'Consultivo e próximo' }, { value: 'formal', label: 'Formal e corporativo' }, { value: 'inspirador', label: 'Inspirador, foco em resultados' }] },
    { name: 'instructions', label: d.ai ? 'Orientações para a IA (opcional)' : 'Observações (opcional)', type: 'textarea', span: 2, placeholder: 'Ex.: destacar a integração com o iFood, citar o prazo de implantação, evitar termos técnicos...' },
    d.ai ? { name: 'use_ai', label: 'Escrever os textos com IA (os números sempre vêm do sistema)', type: 'checkbox', span: 2, default: 1 } : null,
  ];
  const byKind = {
    proposal: [
      { name: 'lead_id', label: 'Lead (opcional)', type: 'select', options: leads.map((l) => ({ value: l.id, label: `${l.name}${l.company ? ' — ' + l.company : ''}` })), empty: 'Nenhum' },
      { name: 'customer_id', label: 'ou cliente existente', type: 'select', options: lk.customers.map((c) => ({ value: c.id, label: c.name })), empty: 'Nenhum' },
      { name: 'client_name', label: 'Nome exibido do cliente', placeholder: 'Preenchido pelo lead/cliente se vazio' },
      { name: 'segment', label: 'Segmento', type: 'select', options: Object.entries(d.segments).map(([k, v]) => ({ value: k, label: v })), empty: 'Geral' },
      { name: '_services', type: 'html', span: 2, html: `<div class="field"><label>Soluções propostas</label><div class="check-grid">${Object.entries(d.services).map(([k, v], i) => `<label class="check"><input type="checkbox" name="svc_${k}" ${i === 0 ? 'checked' : ''}> ${esc(v)}</label>`).join('')}</div></div>` },
      { name: 'weeks', label: 'Prazo estimado (semanas)', type: 'number', default: 8, min: 1 },
      { name: 'investment', label: 'Investimento (um item por linha: Descrição | detalhe | valor)', type: 'textarea', span: 2, placeholder: 'Sistema de gestão | Módulos financeiro e estoque | 18.000,00\nImplantação e treinamento | 3.500,00', help: 'Deixe em branco para indicar "investimento definido após o diagnóstico".' },
      { name: 'terms', label: 'Condições (uma por linha)', type: 'textarea', span: 2, placeholder: '50% na assinatura\n50% na entrega\nProposta válida por 15 dias' },
    ],
    results: [
      { name: 'customer_id', label: 'Cliente', type: 'select', required: true, options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'start', label: 'De', type: 'date', default: past(3) }, { name: 'end', label: 'Até', type: 'date', default: today() },
    ],
    kickoff: [{ name: 'project_id', label: 'Projeto', type: 'select', required: true, span: 2, options: lk.projects.map((p) => ({ value: p.id, label: p.name })) }],
    institutional: [{ name: 'client_name', label: 'Para quem (opcional)', span: 2 }],
    report: [
      { name: 'report', label: 'Relatório', type: 'select', required: true, span: 2, options: d.reports.map((r) => ({ value: r.key, label: r.title })) },
      { name: 'start', label: 'De', type: 'date', default: `${q.getFullYear()}-01-01` }, { name: 'end', label: 'Até', type: 'date', default: today() },
    ],
  };
  byKind.qbr = byKind.results;
  formModal({
    title: 'Nova apresentação — ' + (d.kinds[kind]?.label || kind), size: 'lg', submitLabel: 'Gerar apresentação',
    values: { ...pre, theme: pre.theme || (kind === 'results' ? 'brand' : 'dark'), use_ai: 1 },
    intro: `<p class="muted" style="margin:0">${esc(d.kinds[kind]?.description || '')}</p>`,
    fields: [...byKind[kind], ...common].filter(Boolean),
    onSubmit: async (v, m) => {
      const body = { kind, ...v };
      if (kind === 'proposal') body.services = Object.keys(v).filter((k) => k.startsWith('svc_') && v[k]).map((k) => k.slice(4));
      Object.keys(body).filter((k) => k.startsWith('svc_') || k === '_services').forEach((k) => delete body[k]);
      $('[data-submit]', m.el).textContent = body.use_ai ? 'Escrevendo com IA...' : 'Gerando...';
      const r = await api('/presentations/generate', { method: 'POST', body });
      toast(r.ai ? 'Apresentação criada com textos da IA.' : 'Apresentação criada.');
      location.hash = '#/presentations/' + r.id;
    },
  });
}

/* ================================================================ EDITOR */
async function renderEditor(el, ctx) {
  const deck = await api('/presentations/' + ctx.id);
  ctx.setTitle(deck.title);
  let slides = deck.slides;
  let cur = 0;
  let dirty = false;
  el.innerHTML = `
    <div class="page-head deck-head">
      <div style="flex:1;min-width:0"><a href="#/presentations" class="muted small">← Apresentações</a>
        <input class="deck-title" data-title value="${esc(deck.title)}" aria-label="Título da apresentação"></div>
      <div class="page-actions">
        <span class="save-state muted small" data-state>Salvo</span>
        <select class="input" data-theme style="width:auto" aria-label="Tema">${[['dark', 'Tema escuro'], ['brand', 'Tema azul'], ['light', 'Tema claro']].map(([k, l]) => `<option value="${k}" ${deck.theme === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <label class="btn share-toggle"><input type="checkbox" data-shared ${Number(deck.shared) ? 'checked' : ''}> Link público</label>
        <button class="btn" data-copy ${Number(deck.shared) ? '' : 'disabled'}>${icon('link')} Copiar link</button>
        <a class="btn" href="/apresentacao?id=${deck.id}&print=1&auto=1" target="_blank" rel="noopener">${icon('download')} PDF</a>
        <a class="btn btn-primary" href="/apresentacao?id=${deck.id}" target="_blank" rel="noopener">${icon('play')} Apresentar</a>
      </div>
    </div>
    <div class="deck-editor">
      <aside class="card slide-list"><div class="card-head"><h3>Slides</h3><div class="add-slide"><select class="input" data-add aria-label="Adicionar slide"><option value="">+ Slide</option>${Object.entries(TEMPLATES).map(([k]) => `<option value="${k}">${esc(LAYOUTS[k][0])}</option>`).join('')}</select></div></div><ol data-list></ol></aside>
      <section class="card slide-form"><div class="card-head"><h3 data-form-title>Slide</h3>
        <div class="slide-tools"><button class="btn btn-xs" data-ai-slide ${state.ai?.admin ? '' : 'disabled title="Configure a IA em Configurações"'}>${icon('sparkles')} Reescrever com IA</button><button class="btn btn-xs btn-ghost" data-up title="Subir">${icon('arrowUp')}</button><button class="btn btn-xs btn-ghost" data-down title="Descer">${icon('arrowDown')}</button><button class="btn btn-xs btn-ghost" data-dup title="Duplicar">${icon('copy')}</button><button class="btn btn-xs btn-ghost" data-del title="Excluir slide">${icon('trash')}</button></div></div>
        <div class="card-body" data-form></div></section>
      <section class="card slide-preview"><div class="card-head"><h3>Prévia</h3><span class="muted small">${deck.views} visualização(ões)</span></div><div class="preview-box"><iframe data-preview title="Prévia" src="/apresentacao?id=${deck.id}&embed=1#1"></iframe></div></section>
    </div>`;
  const list = $('[data-list]', el);
  const form = $('[data-form]', el);
  const frame = $('[data-preview]', el);
  const stateEl = $('[data-state]', el);

  const titleOf = (s) => s.title || s.text || s.eyebrow || LAYOUTS[s.layout]?.[0] || s.layout;
  function drawList() {
    list.innerHTML = slides.map((s, i) => `<li class="${i === cur ? 'active' : ''}" data-i="${i}"><span class="n">${i + 1}</span><div><b>${esc(String(titleOf(s)).replace(/\*\*/g, ''))}</b><small>${esc(LAYOUTS[s.layout]?.[0] || s.layout)}</small></div></li>`).join('');
    $$('li', list).forEach((li) => li.addEventListener('click', () => { cur = +li.dataset.i; drawList(); drawForm(); goPreview(); }));
  }
  const inputFor = (name, label, type, value, extra) => {
    const id = 'sf_' + name + '_' + Math.random().toString(36).slice(2, 6);
    if (type === 'textarea') return `<div class="field"><label for="${id}">${esc(label)}</label><textarea id="${id}" data-f="${name}" rows="3">${esc(value ?? '')}</textarea></div>`;
    if (type === 'lines') return `<div class="field"><label for="${id}">${esc(label)}</label><textarea id="${id}" data-f="${name}" data-type="lines" rows="4">${esc((value || []).map((x) => (typeof x === 'object' ? x.title || '' : x)).join('\n'))}</textarea></div>`;
    if (type === 'rows') return `<div class="field"><label for="${id}">${esc(label)}</label><textarea id="${id}" data-f="${name}" data-type="rows" rows="6" class="mono">${esc((value || []).map((r) => (Array.isArray(r) ? r.join(' | ') : r)).join('\n'))}</textarea></div>`;
    if (type === 'select') return `<div class="field"><label for="${id}">${esc(label)}</label><select id="${id}" data-f="${name}">${extra.map(([v, l]) => `<option value="${v}" ${String(value ?? '') === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>`;
    return `<div class="field"><label for="${id}">${esc(label)}</label><input id="${id}" data-f="${name}" value="${esc(value ?? '')}"></div>`;
  };
  function drawForm() {
    const s = slides[cur];
    if (!s) { form.innerHTML = emptyState('Sem slides.', 'play'); return; }
    const def = LAYOUTS[s.layout] || ['Slide', [['title', 'Título']]];
    $('[data-form-title]', el).textContent = `Slide ${cur + 1} · ${def[0]}`;
    form.innerHTML = def[1].map(([name, label, type, extra]) => {
      if (type === 'objects') {
        const items = Array.isArray(s[name]) ? s[name] : [];
        return `<div class="field obj-field" data-obj="${name}"><label>${esc(label)}</label>
          ${items.map((it, k) => `<div class="obj-row" data-k="${k}">${extra.map(([f, fl, ft, fx]) => (ft === 'select'
            ? `<select data-of="${f}" aria-label="${esc(fl)}">${fx.map(([v, l]) => `<option value="${v}" ${String(it[f] ?? '') === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`
            : ft === 'textarea' ? `<textarea data-of="${f}" rows="2" placeholder="${esc(fl)}">${esc(it[f] ?? '')}</textarea>` : `<input data-of="${f}" placeholder="${esc(fl)}" value="${esc(it[f] ?? '')}">`)).join('')}
            <button type="button" class="btn btn-xs btn-ghost" data-obj-del title="Remover">${icon('x')}</button></div>`).join('')}
          <button type="button" class="btn btn-xs" data-obj-add>${icon('plus')} Adicionar</button></div>`;
      }
      return inputFor(name, label, type, s[name], extra);
    }).join('') + (s.layout === 'chart' ? '<p class="muted small">Os dados do gráfico vêm do sistema e não são editáveis aqui. Você pode ajustar título e textos.</p>' : '')
      + '<p class="muted small">Dica: envolva palavras em **asteriscos duplos** para destacá-las com o degradê da marca.</p>';
    form.querySelectorAll('[data-f], [data-of]').forEach((i) => i.addEventListener('input', readForm));
    form.querySelectorAll('select').forEach((i) => i.addEventListener('change', readForm));
    $$('[data-obj-add]', form).forEach((b) => b.addEventListener('click', () => {
      const name = b.closest('[data-obj]').dataset.obj;
      const fields = LAYOUTS[s.layout][1].find((f) => f[0] === name)[3];
      s[name] = [...(s[name] || []), Object.fromEntries(fields.map(([f, , ft, fx]) => [f, ft === 'select' ? fx[0][0] : '']))];
      drawForm(); changed();
    }));
    $$('[data-obj-del]', form).forEach((b) => b.addEventListener('click', () => {
      const name = b.closest('[data-obj]').dataset.obj;
      s[name].splice(+b.closest('.obj-row').dataset.k, 1);
      drawForm(); changed();
    }));
  }
  function readForm() {
    const s = slides[cur];
    $$('[data-f]', form).forEach((i) => {
      const t = i.dataset.type;
      if (t === 'lines') s[i.dataset.f] = i.value.split('\n').map((x) => x.trim()).filter(Boolean);
      else if (t === 'rows') s[i.dataset.f] = i.value.split('\n').filter((x) => x.trim()).map((r) => r.split('|').map((c) => c.trim()));
      else s[i.dataset.f] = i.value;
    });
    $$('[data-obj]', form).forEach((box) => {
      s[box.dataset.obj] = $$('.obj-row', box).map((row) => Object.fromEntries($$('[data-of]', row).map((i) => [i.dataset.of, i.value])));
    });
    changed(false);
  }
  const save = debounce(async () => {
    stateEl.textContent = 'Salvando...';
    try {
      await api('/presentations/' + deck.id, { method: 'PUT', body: { title: $('[data-title]', el).value, theme: $('[data-theme]', el).value, slides } });
      dirty = false;
      stateEl.textContent = 'Salvo';
      frame.src = `/apresentacao?id=${deck.id}&embed=1&r=${Date.now()}#${cur + 1}`;
    } catch (e) { stateEl.textContent = 'Erro ao salvar'; toastError(e); }
  }, 900);
  function changed(redrawList = true) { dirty = true; stateEl.textContent = 'Alterações pendentes...'; if (redrawList) drawList(); else { const li = $(`li[data-i="${cur}"] b`, list); if (li) li.textContent = String(titleOf(slides[cur])).replace(/\*\*/g, ''); } save(); }
  const goPreview = () => { try { frame.contentWindow.postMessage({ goto: cur }, location.origin); } catch (e) { /* ignore */ } };

  $('[data-title]', el).addEventListener('input', () => changed(false));
  $('[data-theme]', el).addEventListener('change', () => changed(false));
  $('[data-shared]', el).addEventListener('change', async (e) => {
    try { const r = await api('/presentations/' + deck.id, { method: 'PUT', body: { shared: e.target.checked } }); deck.share_url = r.share_url; $('[data-copy]', el).disabled = !e.target.checked; toast(e.target.checked ? 'Link público ativado. Copie e envie ao cliente.' : 'Link desativado.'); } catch (err) { toastError(err); }
  });
  $('[data-copy]', el).addEventListener('click', () => copyText(deck.share_url));
  $('[data-add]', el).addEventListener('change', (e) => {
    if (!e.target.value) return;
    slides.splice(cur + 1, 0, JSON.parse(JSON.stringify(TEMPLATES[e.target.value])));
    cur++; e.target.value = '';
    drawList(); drawForm(); changed();
  });
  $('[data-up]', el).addEventListener('click', () => { if (cur < 1) return; [slides[cur - 1], slides[cur]] = [slides[cur], slides[cur - 1]]; cur--; drawList(); drawForm(); changed(); });
  $('[data-down]', el).addEventListener('click', () => { if (cur >= slides.length - 1) return; [slides[cur + 1], slides[cur]] = [slides[cur], slides[cur + 1]]; cur++; drawList(); drawForm(); changed(); });
  $('[data-dup]', el).addEventListener('click', () => { slides.splice(cur + 1, 0, JSON.parse(JSON.stringify(slides[cur]))); cur++; drawList(); drawForm(); changed(); });
  $('[data-del]', el).addEventListener('click', async () => { if (slides.length < 2 || !await confirmDialog('Excluir este slide?', { danger: true })) return; slides.splice(cur, 1); cur = Math.max(0, cur - 1); drawList(); drawForm(); changed(); });
  $('[data-ai-slide]', el).addEventListener('click', () => formModal({
    title: 'Reescrever slide com IA', size: 'sm', submitLabel: 'Reescrever',
    fields: [{ name: 'instruction', label: 'O que mudar?', type: 'textarea', span: 2, required: true, placeholder: 'Ex.: deixar mais persuasivo e objetivo; focar em economia de tempo; linguagem menos técnica.' }],
    onSubmit: async (v) => {
      const r = await api(`/presentations/${deck.id}/ai-slide`, { method: 'POST', body: { index: cur, slide: slides[cur], instruction: v.instruction } });
      slides[cur] = r.slide; drawList(); drawForm(); changed(); toast('Slide reescrito. Revise o texto.', 'info');
    },
  }));
  frame.addEventListener('load', () => setTimeout(goPreview, 200));
  window.onbeforeunload = (e) => { if (dirty && document.body.contains(el) && location.hash.startsWith('#/presentations/')) { e.preventDefault(); e.returnValue = ''; } };
  drawList();
  drawForm();
}
