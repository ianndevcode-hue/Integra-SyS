import { api, state, $, $$, esc, icon, money, date, badge, options, formModal, confirmDialog, modal, toast, toastError, lookups, today, addDays, can, debounce, aiText, emptyState } from '../core.js';

/* Simulator draft and AI chat survive tab switches while the admin is open. */
let sim = null;
let chat = [];
let catalogCache = null;

const blankSim = () => ({ id: null, number: '', status: 'draft', title: '', client: '', items: [], setup_discount: 0, monthly_discount: 0, installments: 1, contract_months: 12, valid_until: addDays(today(), 15), start_date: '', notes: '' });
const PACKS = [
  { name: 'Integra SYS Essencial', hint: 'Até 3 usuários · 1 loja', codes: ['IMP-ESS', 'MEN-SYS-ESS', 'SUP-BAS'] },
  { name: 'Integra SYS Profissional', hint: 'Até 10 usuários · fiscal completo', codes: ['IMP-PRO', 'MEN-SYS-PRO', 'SUP-PRO', 'ADD-TRE'] },
  { name: 'Integra SYS Enterprise', hint: 'Multiempresa · filiais · BI', codes: ['IMP-ENT', 'MEN-SYS-ENT', 'SUP-PREM', 'ADD-MIG'] },
  { name: 'Sistema sob medida', hint: 'Projeto + manutenção mensal', codes: ['IMP-PRO', 'DEV-WEB', 'MEN-SOB', 'SUP-PRO'] },
  { name: 'Site + presença digital', hint: 'Site, hospedagem e suporte', codes: ['IMP-ESS', 'DEV-SITE', 'ADD-HOS', 'SUP-BAS'] },
];
const unitLabel = (it) => ({ monthly: '/mês', yearly: '/ano', hourly: '/hora' }[it.billing] || '') + (it.unit && !['mês', 'projeto', 'hora'].includes(it.unit) ? ' por ' + it.unit : '');
const host = (u) => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } };
const pct = (v) => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';

async function catalog(force = false) {
  if (!catalogCache || force) catalogCache = await api('/pricing/catalog');
  return catalogCache;
}

/** Horizontal market range: min ——avg—— max, with the Integra price marker. */
function marketBar(min, avg, max, ours) {
  min = Number(min || 0); avg = Number(avg || 0); max = Number(max || 0); ours = Number(ours || 0);
  if (!avg || max <= min) return '';
  const lo = Math.min(min, ours || min);
  const hi = Math.max(max, ours || max);
  const pos = (v) => Math.max(0, Math.min(100, ((v - lo) / (hi - lo)) * 100));
  return `<div class="mbar" title="Mercado em Marília: mín ${money(min)} · média ${money(avg)} · máx ${money(max)}">
      <div class="mbar-track"><i class="mbar-range" style="left:${pos(min)}%;right:${100 - pos(max)}%"></i><i class="mbar-avg" style="left:${pos(avg)}%"></i>${ours ? `<i class="mbar-ours" style="left:${pos(ours)}%"></i>` : ''}</div>
      <div class="mbar-legend"><span>${money(min)}</span><span>média ${money(avg)}</span><span>${money(max)}</span></div>
    </div>`;
}

function tabs(active) {
  return `<div class="tabs">${[['', 'Simulador', 'money'], ['/quotes', 'Orçamentos', 'file'], ['/contracts', 'Contratos & MRR', 'refresh'], ['/catalog', 'Tabela de preços', 'tag'], ['/ask', 'Consultor IA', 'sparkles']]
    .map(([p, l, ic]) => `<a class="tab ${active === p ? 'active' : ''}" href="#/pricing${p}">${icon(ic)} ${l}</a>`).join('')}</div>`;
}

export async function render(el, ctx) {
  const sub = ctx.sub || 'simulator';
  const head = (title, text, actions = '') => `<div class="page-head"><div><h2>${title}</h2><p>${text}</p></div><div class="page-actions">${actions}</div></div>`;
  if (sub === 'quotes') return renderQuotes(el, ctx, head);
  if (sub === 'contracts') return renderContracts(el, ctx, head);
  if (sub === 'catalog') return renderCatalog(el, ctx, head);
  if (sub === 'ask') return renderAsk(el, ctx, head);
  return renderSimulator(el, ctx, head);
}

/* =================================================================== SIMULATOR */
async function renderSimulator(el, ctx, head) {
  const [cat, lk, leads] = await Promise.all([catalog(true), lookups(), api('/leads', { query: { per_page: 200, sort: 'created_at', dir: 'desc' } }).then((r) => r.data).catch(() => [])]);
  const byId = Object.fromEntries(cat.data.map((i) => [i.id, i]));
  const active = cat.data.filter((i) => Number(i.active));
  if (ctx.id) {
    const q = await api('/quotes/' + ctx.id);
    sim = { ...blankSim(), ...q, client: q.customer_id ? 'c:' + q.customer_id : q.lead_id ? 'l:' + q.lead_id : '', items: q.items.map((l) => ({ item_id: l.item_id, qty: l.qty, price: l.price })), start_date: q.start_date || '' };
  } else {
    if (!sim || ctx.query.new || ctx.query.customer_id || ctx.query.lead_id || sim.status === 'accepted') sim = blankSim();
    if (ctx.query.customer_id) sim.client = 'c:' + ctx.query.customer_id;
    if (ctx.query.lead_id) sim.client = 'l:' + ctx.query.lead_id;
    const add = byId[+ctx.query.add];
    if (add) {
      if (add.category === 'suporte') sim.items = sim.items.filter((it) => byId[it.item_id]?.category !== 'suporte');
      const ex = sim.items.find((it) => +it.item_id === add.id);
      if (ex) { if (ctx.query.price) ex.price = ctx.query.price; } else sim.items.push({ item_id: add.id, qty: 1, price: ctx.query.price || '' });
    }
    if (Object.keys(ctx.query).length) history.replaceState(null, '', sim.id ? '#/pricing/quotes/' + sim.id : '#/pricing');
  }
  const locked = sim.status === 'accepted';
  const openLeads = leads.filter((l) => !['won', 'lost'].includes(l.status) || 'l:' + l.id === sim.client);

  el.innerHTML = `
    ${head('Simulador de preços', `Preços físicos com base na média de mercado de Marília −${Math.round((1 - cat.factor) * 100)}%. Para contratar: implantação + um plano de suporte + mensalidade.`,
      `<button class="btn" data-new>${icon('plus')} Nova simulação</button>`)}
    ${tabs('')}
    ${locked ? `<div class="alert alert-info">Orçamento <b>${esc(sim.number)}</b> já foi aceito e virou contrato. Para alterar, <button class="btn btn-xs" data-dup>duplique como nova versão</button>.</div>` : ''}
    <div class="sim">
      <div class="sim-catalog">
        <section class="card card-pad sim-packs"><div class="card-head" style="padding:0 0 12px;border:0"><h3>${icon('layers')} Pacotes prontos</h3><span class="muted small">Um clique monta o orçamento — ajuste depois</span></div>
          <div class="pack-grid">${PACKS.map((p, i) => {
            const items = p.codes.map((c) => active.find((it) => it.code === c)).filter(Boolean);
            const setup = items.filter((it) => !['monthly', 'yearly'].includes(it.billing)).reduce((s, it) => s + Number(it.price), 0);
            const monthly = items.filter((it) => it.billing === 'monthly').reduce((s, it) => s + Number(it.price), 0);
            return items.length ? `<button class="pack" data-pack="${i}" ${locked ? 'disabled' : ''}><b>${esc(p.name)}</b><small>${esc(p.hint)}</small><span>${money(setup)} + ${money(monthly)}/mês</span></button>` : '';
          }).join('')}</div>
        </section>
        ${Object.entries(cat.categories).map(([k, c]) => {
          const items = active.filter((i) => i.category === k);
          if (!items.length) return '';
          return `<section class="card sim-cat" data-cat="${k}">
            <div class="card-head"><div><h3>${esc(c.label)} ${c.required ? `<span class="badge ${k === 'suporte' ? 'violet' : 'blue'}">${k === 'suporte' ? 'obrigatório · escolha 1' : 'obrigatório'}</span>` : ''}</h3><p class="muted small" style="margin:2px 0 0">${esc(c.hint)}</p></div></div>
            <div class="price-grid">${items.map((it) => `
              <article class="price-card" data-item="${it.id}">
                <div class="pc-top"><div><b>${esc(it.name)}</b>${it.tier ? ` <span class="badge">${esc(it.tier)}</span>` : ''}</div><span class="pc-code mono">${esc(it.code)}</span></div>
                ${it.description ? `<p class="pc-desc">${esc(it.description)}</p>` : ''}
                <div class="pc-price"><b>${money(it.price)}</b><span>${esc(unitLabel(it))}</span>${Number(it.market_avg) ? `<em>${pct((1 - it.price / it.market_avg) * 100)} abaixo da média</em>` : ''}</div>
                ${marketBar(it.market_min, it.market_avg, it.market_max, it.price)}
                ${it.includes ? `<details class="pc-inc"><summary>O que inclui</summary><ul>${String(it.includes).split(/\n|;/).filter((s) => s.trim()).map((s) => `<li>${esc(s.trim())}</li>`).join('')}</ul></details>` : ''}
                <button class="btn btn-sm btn-block" data-add="${it.id}" ${locked ? 'disabled' : ''}>${icon('plus')} Adicionar</button>
              </article>`).join('')}</div>
          </section>`;
        }).join('')}
      </div>
      <aside class="sim-quote card">
        <div class="card-head"><h3>${sim.number ? esc(sim.number) : 'Orçamento'} ${sim.id ? badge('quote_status', sim.status) : ''}</h3><button class="btn btn-xs btn-ghost" data-clear ${locked ? 'disabled' : ''}>Limpar</button></div>
        <div class="card-body sim-body">
          <div class="field"><label for="sim-client">Cliente ou lead</label><select id="sim-client" data-k="client" ${locked ? 'disabled' : ''}><option value="">— Simulação avulsa —</option>
            <optgroup label="Clientes">${lk.customers.map((c) => `<option value="c:${c.id}" ${sim.client === 'c:' + c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</optgroup>
            ${openLeads.length ? `<optgroup label="Leads">${openLeads.map((l) => `<option value="l:${l.id}" ${sim.client === 'l:' + l.id ? 'selected' : ''}>${esc(l.company ? l.company + ' — ' + l.name : l.name)}</option>`).join('')}</optgroup>` : ''}</select></div>
          <div class="field"><label for="sim-title">Título</label><input id="sim-title" data-k="title" value="${esc(sim.title)}" placeholder="Ex.: Integra SYS para Mercado Bom Preço" ${locked ? 'disabled' : ''}></div>
          <div class="sim-lines" data-lines></div>
          <div class="sim-opts">
            <div class="field"><label for="sim-sd">Desc. implantação (%)</label><input id="sim-sd" type="number" min="0" max="50" step="0.5" data-k="setup_discount" value="${esc(sim.setup_discount)}" ${locked ? 'disabled' : ''}></div>
            <div class="field"><label for="sim-md">Desc. mensalidade (%)</label><input id="sim-md" type="number" min="0" max="50" step="0.5" data-k="monthly_discount" value="${esc(sim.monthly_discount)}" ${locked ? 'disabled' : ''}></div>
            <div class="field"><label for="sim-in">Parcelas da implantação</label><select id="sim-in" data-k="installments" ${locked ? 'disabled' : ''}>${Array.from({ length: 12 }, (_, i) => `<option value="${i + 1}" ${+sim.installments === i + 1 ? 'selected' : ''}>${i + 1}x</option>`).join('')}</select></div>
            <div class="field"><label for="sim-cm">Fidelidade</label><select id="sim-cm" data-k="contract_months" ${locked ? 'disabled' : ''}>${[6, 12, 18, 24, 36].map((n) => `<option value="${n}" ${+sim.contract_months === n ? 'selected' : ''}>${n} meses</option>`).join('')}</select></div>
            <div class="field"><label for="sim-vu">Válido até</label><input id="sim-vu" type="date" data-k="valid_until" value="${esc(sim.valid_until || '')}" ${locked ? 'disabled' : ''}></div>
            <div class="field"><label for="sim-sdt">Início previsto</label><input id="sim-sdt" type="date" data-k="start_date" value="${esc(sim.start_date || '')}" ${locked ? 'disabled' : ''}></div>
          </div>
          <div class="field"><label for="sim-notes">Observações (vão para a proposta)</label><textarea id="sim-notes" rows="2" data-k="notes" ${locked ? 'disabled' : ''}>${esc(sim.notes || '')}</textarea></div>
          <div data-result></div>
        </div>
        <div class="sim-actions">
          <button class="btn" data-save ${locked ? 'disabled' : ''}>${icon('check')} Salvar</button>
          <button class="btn" data-deck>${icon('sparkles')} Gerar proposta</button>
          <button class="btn btn-success" data-accept ${locked ? 'disabled' : ''}>${icon('bolt')} Contratar</button>
        </div>
      </aside>
    </div>`;

  const linesBox = $('[data-lines]', el);
  const resultBox = $('[data-result]', el);
  let last = null;
  let dirty = false;

  function renderLines() {
    if (!sim.items.length) { linesBox.innerHTML = `<div class="sim-empty">${icon('plus')} Adicione itens da tabela ao lado ou escolha um pacote pronto.</div>`; return; }
    const lines = last?.lines || [];
    linesBox.innerHTML = sim.items.map((it, i) => {
      const p = byId[it.item_id];
      if (!p) return '';
      const line = lines.find((l) => l.item_id === +it.item_id);
      return `<div class="sim-line" data-i="${i}">
        <div class="sl-name"><b>${esc(p.name)}</b><small>${esc(cat.categories[p.category]?.label || p.category)} · ${esc(unitLabel(p) || 'valor único')}</small></div>
        <label class="sl-qty"><span>Qtd.</span><input type="number" min="0.5" step="0.5" value="${esc(it.qty)}" data-qty ${locked ? 'disabled' : ''} aria-label="Quantidade"></label>
        <label class="sl-price"><span>Preço</span><input type="number" min="0" step="0.01" value="${esc(it.price === '' || it.price == null ? p.price : it.price)}" data-price ${locked ? 'disabled' : ''} aria-label="Preço unitário"></label>
        <b class="sl-total">${line ? money(line.total) : '—'}</b>
        <button class="btn btn-xs btn-ghost" data-rm title="Remover" ${locked ? 'disabled' : ''}>${icon('x')}</button>
      </div>`;
    }).join('');
  }

  function renderResult() {
    if (!last) { resultBox.innerHTML = ''; return; }
    const t = last.totals;
    const count = (c) => last.lines.filter((l) => l.category === c).length;
    const reqs = [['Implantação', count('implantacao') >= 1], ['Plano de suporte (1)', count('suporte') === 1], ['Mensalidade', count('mensalidade') >= 1]];
    resultBox.innerHTML = `
      <ul class="req-list">${reqs.map(([l, ok]) => `<li class="${ok ? 'ok' : ''}">${icon(ok ? 'check' : 'x')} ${l}</li>`).join('')}</ul>
      ${last.warnings.length ? `<div class="alert alert-warning small" style="margin:10px 0 0">${last.warnings.map(esc).join('<br>')}</div>` : ''}
      <div class="sim-totals">
        <div><span>Implantação${t.setup_discount ? ` <em>−${pct(t.setup_discount)}</em>` : ''}</span><b>${money(t.setup)}</b>${t.installments > 1 ? `<small>${t.installments}x de ${money(t.installment_value)}</small>` : '<small>à vista</small>'}</div>
        <div><span>Mensalidade${t.monthly_discount ? ` <em>−${pct(t.monthly_discount)}</em>` : ''}</span><b>${money(t.monthly)}</b><small>suporte incluso: ${money(t.support)}</small></div>
        <div><span>Primeiro ano</span><b>${money(t.first_year)}</b><small>contrato ${t.contract_months} meses: ${money(t.contract_total)}</small></div>
        <div class="sim-market"><span>Economia vs. média de Marília (1º ano)</span><b class="${t.savings_first_year >= 0 ? 'pos' : 'neg'}">${money(t.savings_first_year)}</b><small>mercado: ${money(t.market_setup)} + ${money(t.market_monthly)}/mês</small></div>
      </div>`;
    $('[data-accept]', el).disabled = locked || last.missing.length > 0;
    $('[data-accept]', el).title = last.missing.join(' ');
  }

  // Only totals are refreshed after a recalculation so the input being typed in keeps focus.
  const recalc = debounce(async () => {
    try {
      last = await api('/pricing/simulate', { method: 'POST', body: payload() });
      $$('[data-i]', linesBox).forEach((row) => {
        const line = last.lines.find((l) => l.item_id === +sim.items[+row.dataset.i]?.item_id);
        $('.sl-total', row).textContent = line ? money(line.total) : '—';
      });
      renderResult();
    } catch (e) { toastError(e); }
  }, 200);

  function payload() {
    const [kind, id] = (sim.client || ':').split(':');
    return {
      title: sim.title, customer_id: kind === 'c' ? id : null, lead_id: kind === 'l' ? id : null,
      items: sim.items.map((it) => ({ item_id: it.item_id, qty: it.qty, price: it.price })),
      setup_discount: sim.setup_discount, monthly_discount: sim.monthly_discount, installments: sim.installments, contract_months: sim.contract_months,
      valid_until: sim.valid_until, start_date: sim.start_date, notes: sim.notes,
    };
  }

  function addItem(id, price = '') {
    const p = byId[id];
    if (!p) return;
    if (p.category === 'suporte') sim.items = sim.items.filter((it) => byId[it.item_id]?.category !== 'suporte');
    const ex = sim.items.find((it) => +it.item_id === +id);
    if (ex) ex.qty = Number(ex.qty) + 1;
    else sim.items.push({ item_id: +id, qty: 1, price });
    dirty = true;
    renderLines();
    recalc();
  }

  async function save(silent = false) {
    if (!sim.items.length) throw new Error('Adicione ao menos um item ao orçamento.');
    const q = await api(sim.id ? '/quotes/' + sim.id : '/quotes', { method: sim.id ? 'PUT' : 'POST', body: payload() });
    Object.assign(sim, { id: q.id, number: q.number, status: q.status, title: q.title });
    dirty = false;
    if (!silent) toast(`Orçamento ${q.number} salvo.`);
    history.replaceState(null, '', '#/pricing/quotes/' + q.id);
    $('.sim-quote .card-head h3', el).innerHTML = `${esc(q.number)} ${badge('quote_status', q.status)}`;
    $('#sim-title', el).value = q.title;
    return q;
  }

  el.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    try {
      if (b.dataset.add) addItem(+b.dataset.add);
      else if (b.dataset.pack !== undefined) {
        const pack = PACKS[+b.dataset.pack];
        sim.items = pack.codes.map((c) => active.find((it) => it.code === c)).filter(Boolean).map((it) => ({ item_id: it.id, qty: 1, price: '' }));
        if (!sim.title) { sim.title = pack.name; $('#sim-title', el).value = pack.name; }
        dirty = true; renderLines(); recalc();
      } else if (b.matches('[data-rm]')) { sim.items.splice(+b.closest('[data-i]').dataset.i, 1); dirty = true; renderLines(); recalc(); }
      else if (b.matches('[data-clear]')) { sim.items = []; renderLines(); recalc(); }
      else if (b.matches('[data-new]')) { sim = blankSim(); location.hash = '#/pricing?new=1'; }
      else if (b.matches('[data-save]')) { b.classList.add('loading'); await save().finally(() => b.classList.remove('loading')); }
      else if (b.matches('[data-dup]')) { const q = await api(`/quotes/${sim.id}/duplicate`, { method: 'POST' }); toast('Nova versão criada: ' + q.number); location.hash = '#/pricing/quotes/' + q.id; }
      else if (b.matches('[data-deck]')) {
        if (!sim.id || dirty) await save(true);
        proposalDeck(sim.id);
      } else if (b.matches('[data-accept]')) {
        if (last?.missing.length) { toast('Para contratar: ' + last.missing.join(' '), 'error'); return; }
        if (!sim.client) { toast('Selecione o cliente ou lead antes de contratar.', 'error'); $('#sim-client', el).focus(); return; }
        if (!sim.id || dirty) await save(true);
        acceptQuote(await api('/quotes/' + sim.id), () => { sim = null; location.hash = '#/pricing/contracts'; });
      }
    } catch (err) { toastError(err); }
  });
  el.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.k) { sim[t.dataset.k] = t.value; dirty = true; if (['setup_discount', 'monthly_discount', 'installments', 'contract_months'].includes(t.dataset.k)) recalc(); return; }
    const row = t.closest('[data-i]');
    if (!row) return;
    const it = sim.items[+row.dataset.i];
    if (t.matches('[data-qty]')) it.qty = t.value;
    if (t.matches('[data-price]')) it.price = t.value;
    dirty = true;
    recalc();
  });
  el.addEventListener('change', (e) => { if (e.target.dataset.k) { sim[e.target.dataset.k] = e.target.value; dirty = true; recalc(); } });
  renderLines();
  recalc();
}

/** "Gerar proposta" → AI presentation built from the quote. */
function proposalDeck(quoteId) {
  formModal({
    title: 'Gerar proposta comercial', submitLabel: 'Gerar apresentação',
    intro: `<p class="muted" style="margin:0">A apresentação usa a identidade Integra Code e inclui os slides de investimento (implantação e mensalidade) deste orçamento.${state.ai?.admin ? ' A IA escreve o diagnóstico e a solução.' : ''}</p>`,
    values: { theme: 'dark', tone: 'consultivo', use_ai: !!state.ai?.admin },
    fields: [
      { name: 'theme', label: 'Tema', type: 'select', empty: false, options: [{ value: 'dark', label: 'Escuro (padrão)' }, { value: 'light', label: 'Claro (impressão)' }] },
      { name: 'tone', label: 'Tom', type: 'select', empty: false, options: [{ value: 'consultivo', label: 'Consultivo' }, { value: 'executivo', label: 'Executivo' }, { value: 'tecnico', label: 'Técnico' }, { value: 'proximo', label: 'Próximo' }] },
      { name: 'instructions', label: 'Instruções extras para a IA', type: 'textarea', rows: 3, span: 2 },
      state.ai?.admin && { name: 'use_ai', label: 'Escrever com IA', type: 'checkbox', span: 2 },
    ],
    onSubmit: async (d) => {
      const r = await api(`/quotes/${quoteId}/presentation`, { method: 'POST', body: d });
      toast('Proposta gerada!');
      location.hash = '#/presentations/' + r.id;
    },
  });
}

/** Accept modal: contract + project + receivables (+ optional Asaas charge). */
export function acceptQuote(q, onDone) {
  const asaas = state.asaas?.configured && can('charges');
  formModal({
    title: `Contratar ${q.number}`, submitLabel: 'Confirmar contratação', size: 'lg',
    intro: `<div class="accept-sum"><div><span>Implantação</span><b>${money(q.totals.setup)}</b><small>${q.totals.installments}x de ${money(q.totals.installment_value)}</small></div><div><span>Mensalidade</span><b>${money(q.totals.monthly)}</b><small>${q.contract_months} meses</small></div><div><span>1º ano</span><b>${money(q.totals.first_year)}</b><small>${esc(q.customer_name || q.lead_company || q.lead_name || '')}</small></div></div>
      ${q.lead_id && !q.customer_id ? '<div class="alert alert-info small" style="margin:10px 0 0">O lead será convertido em cliente automaticamente.</div>' : ''}`,
    values: { start_date: q.start_date || today(), billing_day: 10, create_project: true, billing_type: 'UNDEFINED' },
    fields: [
      { name: 'start_date', label: 'Início do contrato', type: 'date', required: true },
      { name: 'billing_day', label: 'Dia de vencimento da mensalidade', type: 'number', min: 1, max: 28 },
      { name: 'billing_type', label: 'Forma de pagamento', type: 'select', empty: false, options: options('billing_type') },
      { name: 'create_project', label: 'Criar projeto de implantação com etapas padrão', type: 'checkbox', span: 2 },
      asaas && { name: 'charge_setup', label: 'Emitir já a cobrança da 1ª parcela da implantação no Asaas', type: 'checkbox', span: 2 },
      asaas && { name: 'auto_charge', label: 'Emitir cobranças das mensalidades automaticamente (Asaas)', type: 'checkbox', span: 2 },
    ],
    onSubmit: async (d) => {
      const r = await api(`/quotes/${q.id}/accept`, { method: 'POST', body: d });
      toast('Contrato ativado! ' + (r.entries.length ? r.entries.length + ' parcela(s) lançadas em contas a receber.' : ''));
      if (r.charge_error) toast('Cobrança não emitida: ' + r.charge_error, 'error');
      window.dispatchEvent(new Event('ic:refresh-counts'));
      onDone && onDone(r);
    },
  });
}

/* ==================================================================== QUOTES */
async function renderQuotes(el, ctx, head) {
  el.innerHTML = `${head('Orçamentos', 'Simulações salvas, propostas enviadas e contratações.', `<a class="btn btn-primary" href="#/pricing?new=1">${icon('plus')} Novo orçamento</a>`)}${tabs('/quotes')}
    <div class="grid g4" style="margin-bottom:16px" data-kpis></div>
    <div class="card"><div class="toolbar"><label class="search">${icon('search')}<input type="search" placeholder="Buscar por número, cliente ou título..." data-q aria-label="Buscar"></label>
      <select data-st aria-label="Situação"><option value="">Todas as situações</option>${options('quote_status').map((o) => `<option value="${o.value}">${o.label}</option>`).join('')}</select></div>
      <div class="table-wrap"><table class="dt cards"><thead><tr><th>Número</th><th>Cliente / lead</th><th>Título</th><th class="num">Implantação</th><th class="num">Mensalidade</th><th>Validade</th><th>Situação</th><th></th></tr></thead><tbody data-rows><tr><td colspan="8"><div class="loading-box">Carregando...</div></td></tr></tbody></table></div></div>`;
  let rows = [];
  const draw = () => {
    const q = $('[data-q]', el).value.toLowerCase();
    const st = $('[data-st]', el).value;
    const list = rows.filter((r) => (!st || r.status === st) && (!q || [r.number, r.title, r.customer_name, r.lead_name].join(' ').toLowerCase().includes(q)));
    $('[data-rows]', el).innerHTML = list.length ? list.map((r) => {
      const expired = r.status !== 'accepted' && r.valid_until && r.valid_until < today();
      return `<tr class="clickable" data-id="${r.id}">
        <td data-label="Número" class="primary"><b class="mono">${esc(r.number)}</b></td>
        <td data-label="Cliente">${r.customer_id ? `<a href="#/customers/${r.customer_id}">${esc(r.customer_name)}</a>` : r.lead_id ? `<a href="#/leads?open=${r.lead_id}">${esc(r.lead_name)}</a> <span class="badge">lead</span>` : '<span class="muted">avulso</span>'}</td>
        <td data-label="Título">${esc(r.title)}</td>
        <td data-label="Implantação" class="num">${money(r.setup_total)}</td>
        <td data-label="Mensalidade" class="num">${money(r.monthly_total)}</td>
        <td data-label="Validade" class="${expired ? 'neg' : ''}">${date(r.valid_until)}</td>
        <td data-label="Situação">${badge('quote_status', expired && ['draft', 'sent'].includes(r.status) ? 'expired' : r.status)}</td>
        <td class="actions">
          ${r.presentation_id ? `<a class="btn btn-xs" href="#/presentations/${r.presentation_id}" title="Proposta">${icon('sparkles')}</a>` : `<button class="btn btn-xs" data-deck title="Gerar proposta">${icon('sparkles')}</button>`}
          ${r.status !== 'accepted' ? `<button class="btn btn-xs btn-success" data-accept title="Contratar">${icon('bolt')}</button>` : r.contract_id ? `<a class="btn btn-xs" href="#/pricing/contracts" title="Contrato">${icon('file')}</a>` : ''}
          <button class="btn btn-xs" data-dup title="Duplicar">${icon('copy')}</button>
          ${r.status !== 'accepted' ? `<button class="btn btn-xs" data-status title="Alterar situação">${icon('edit')}</button><button class="btn btn-xs btn-danger" data-del title="Excluir">${icon('trash')}</button>` : ''}
        </td></tr>`;
    }).join('') : `<tr><td colspan="8">${emptyState(rows.length ? 'Nenhum orçamento com esses filtros.' : 'Nenhum orçamento ainda. Monte o primeiro no simulador.', 'file')}</td></tr>`;
  };
  const load = async () => {
    rows = (await api('/quotes')).data;
    const open = rows.filter((r) => ['draft', 'sent'].includes(r.status));
    const acc = rows.filter((r) => r.status === 'accepted');
    const decided = rows.filter((r) => ['accepted', 'rejected'].includes(r.status)).length;
    $('[data-kpis]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico">${icon('file')}</span>Em aberto</div><div class="k-value">${open.length}</div><div class="k-sub">${money(open.reduce((s, r) => s + Number(r.setup_total), 0))} + ${money(open.reduce((s, r) => s + Number(r.monthly_total), 0))}/mês</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('check')}</span>Aceitos</div><div class="k-value">${acc.length}</div><div class="k-sub">${money(acc.reduce((s, r) => s + Number(r.monthly_total), 0))}/mês contratados</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('target')}</span>Conversão</div><div class="k-value">${decided ? Math.round(acc.length / decided * 100) : 0}%</div><div class="k-sub">aceitos ÷ decididos</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('trendUp')}</span>Ticket médio (1º ano)</div><div class="k-value">${money(rows.length ? rows.reduce((s, r) => s + Number(r.first_year_total), 0) / rows.length : 0)}</div><div class="k-sub">${rows.length} orçamento(s)</div></div>`;
    draw();
  };
  $('[data-q]', el).addEventListener('input', debounce(draw, 150));
  $('[data-st]', el).addEventListener('change', draw);
  $('[data-rows]', el).addEventListener('click', async (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = +tr.dataset.id;
    const b = e.target.closest('button');
    try {
      if (!b) { if (!e.target.closest('a')) location.hash = '#/pricing/quotes/' + id; return; }
      if (b.matches('[data-deck]')) proposalDeck(id);
      else if (b.matches('[data-accept]')) {
        const q = await api('/quotes/' + id);
        if (q.missing.length) { toast('Para contratar: ' + q.missing.join(' '), 'error'); return; }
        acceptQuote(q, load);
      } else if (b.matches('[data-dup]')) { const q = await api(`/quotes/${id}/duplicate`, { method: 'POST' }); toast('Nova versão: ' + q.number); load(); }
      else if (b.matches('[data-del]')) { if (await confirmDialog('Excluir este orçamento?', { danger: true })) { await api('/quotes/' + id, { method: 'DELETE' }); load(); } }
      else if (b.matches('[data-status]')) {
        const r = rows.find((x) => x.id === id);
        formModal({ title: 'Situação do orçamento', size: 'sm', values: { status: r.status }, fields: [{ name: 'status', label: 'Situação', type: 'select', empty: false, span: 2, options: options('quote_status').filter((o) => o.value !== 'accepted') }],
          onSubmit: async (d) => { await api('/quotes/' + id, { method: 'PUT', body: { status: d.status } }); load(); } });
      }
    } catch (err) { toastError(err); }
  });
  await load();
}

/* ================================================================= CONTRACTS */
async function renderContracts(el, ctx, head) {
  const finance = can('finance');
  el.innerHTML = `${head('Contratos & receita recorrente', 'Mensalidades ativas, faturamento automático e emissão de notas.', finance ? `<button class="btn btn-primary" data-bill>${icon('refresh')} Gerar mensalidades</button>` : '')}${tabs('/contracts')}
    <div class="grid g4" style="margin-bottom:16px" data-kpis></div>
    <div class="card"><div class="toolbar"><label class="search">${icon('search')}<input type="search" placeholder="Buscar contrato ou cliente..." data-q aria-label="Buscar"></label>
      <select data-st aria-label="Situação"><option value="">Todas as situações</option>${options('contract_status').map((o) => `<option value="${o.value}" ${o.value === (ctx.query.status || '') ? 'selected' : ''}>${o.label}</option>`).join('')}</select></div>
      <div class="table-wrap"><table class="dt cards"><thead><tr><th>Contrato</th><th>Cliente</th><th>Suporte</th><th class="num">Mensalidade</th><th>Próx. faturamento</th><th>Vigência</th><th>Situação</th><th></th></tr></thead><tbody data-rows><tr><td colspan="8"><div class="loading-box">Carregando...</div></td></tr></tbody></table></div></div>`;
  let res = { data: [] };
  const draw = () => {
    const q = $('[data-q]', el).value.toLowerCase();
    const st = $('[data-st]', el).value;
    const list = res.data.filter((r) => (!st || r.status === st) && (!q || [r.number, r.title, r.customer_name].join(' ').toLowerCase().includes(q)));
    $('[data-rows]', el).innerHTML = list.length ? list.map((r) => `<tr data-id="${r.id}">
        <td data-label="Contrato" class="primary"><b class="mono">${esc(r.number)}</b><br><small class="muted">${esc(r.title || '')}</small></td>
        <td data-label="Cliente"><a href="#/customers/${r.customer_id}">${esc(r.customer_name)}</a></td>
        <td data-label="Suporte">${esc(r.support_plan || '—')}</td>
        <td data-label="Mensalidade" class="num"><b>${money(r.monthly_amount)}</b>${Number(r.auto_charge) ? '<br><small class="muted">cobrança automática</small>' : ''}</td>
        <td data-label="Próx. faturamento" class="${r.status === 'active' && r.next_billing_date && r.next_billing_date < today() ? 'neg' : ''}">${r.status === 'active' ? date(r.next_billing_date) + ` <small class="muted">dia ${r.billing_day}</small>` : '—'}</td>
        <td data-label="Vigência">${date(r.start_date)} → ${date(r.end_date)}</td>
        <td data-label="Situação">${badge('contract_status', r.status)}</td>
        <td class="actions">
          ${finance && r.status === 'active' ? `<button class="btn btn-xs" data-billone title="Gerar a próxima mensalidade agora">${icon('receipt')}</button>` : ''}
          ${finance ? `<a class="btn btn-xs" title="Emitir NFS-e da mensalidade" href="#/finance/nfse?new=1&customer_id=${r.customer_id}&amount=${r.monthly_amount}&contract_id=${r.id}&service_code=${encodeURIComponent(r.nfse_service_code || '')}&description=${encodeURIComponent(nfseDesc(r))}">${icon('file')}</a>` : ''}
          ${can('charges') ? `<a class="btn btn-xs" title="Cobrança avulsa" href="#/finance/charges?new=1&customer_id=${r.customer_id}&amount=${r.monthly_amount}&contract_id=${r.id}&description=${encodeURIComponent('Mensalidade ' + r.number)}">${icon('money')}</a>` : ''}
          <button class="btn btn-xs" data-edit title="Editar">${icon('edit')}</button>
        </td></tr>`).join('') : `<tr><td colspan="8">${emptyState(res.data.length ? 'Nenhum contrato com esses filtros.' : 'Nenhum contrato ainda — aceite um orçamento para ativar o primeiro.', 'file')}</td></tr>`;
  };
  const load = async () => {
    res = await api('/contracts');
    const act = res.data.filter((r) => r.status === 'active');
    $('[data-kpis]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('refresh')}</span>MRR</div><div class="k-value">${money(res.mrr)}</div><div class="k-sub">receita recorrente mensal</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('trendUp')}</span>ARR</div><div class="k-value">${money(res.arr)}</div><div class="k-sub">MRR × 12</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('users')}</span>Contratos ativos</div><div class="k-value">${act.length}</div><div class="k-sub">ticket médio ${money(act.length ? res.mrr / act.length : 0)}/mês</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${res.billing_due ? 'red' : ''}">${icon('calendar')}</span>A faturar (${res.lead_days} dias)</div><div class="k-value">${res.billing_due}</div><div class="k-sub">${res.auto_billing ? 'faturamento automático ligado' : '<a href="#/settings">ligar faturamento automático</a>'}</div></div>`;
    draw();
  };
  $('[data-q]', el).addEventListener('input', debounce(draw, 150));
  $('[data-st]', el).addEventListener('change', draw);
  $('[data-bill]', el)?.addEventListener('click', async (e) => {
    if (!await confirmDialog(`Gerar as contas a receber das mensalidades que vencem nos próximos ${res.lead_days} dias? Contratos com "cobrança automática" também recebem a cobrança no Asaas.`, { okLabel: 'Gerar mensalidades' })) return;
    try {
      const r = await api('/contracts/bill', { method: 'POST', body: {} });
      toast(`${r.entries} mensalidade(s) lançada(s)${r.charges ? `, ${r.charges} cobrança(s) emitida(s)` : ''}.`);
      r.errors.forEach((m) => toast(m, 'error'));
      load();
    } catch (err) { toastError(err); }
  });
  $('[data-rows]', el).addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    const tr = e.target.closest('tr[data-id]');
    if (!b || !tr) return;
    const r = res.data.find((x) => x.id === +tr.dataset.id);
    try {
      if (b.matches('[data-billone]')) {
        if (!await confirmDialog(`Lançar agora a mensalidade de ${date(r.next_billing_date)} (${money(r.monthly_amount)}) do contrato ${r.number}?`)) return;
        const out = await api('/contracts/bill', { method: 'POST', body: { contract_id: r.id, lead_days: 40 } });
        toast(out.entries ? 'Mensalidade lançada em contas a receber.' : 'Nada a lançar para este contrato.');
        out.errors.forEach((m) => toast(m, 'error'));
        load();
      } else if (b.matches('[data-edit]')) contractForm(r, load);
    } catch (err) { toastError(err); }
  });
  await load();
}

const nfseDesc = (r) => `${r.title || 'Contrato ' + r.number} — mensalidade ${new Date().toLocaleDateString('pt-BR', { month: '2-digit', year: 'numeric' })}\n${(r.items || []).map((l) => '• ' + l.name + (Number(l.qty) !== 1 ? ' × ' + l.qty : '')).join('\n')}\nContrato ${r.number}.`;

function contractForm(r, onDone) {
  formModal({
    title: 'Contrato ' + r.number, size: 'lg', values: r,
    intro: `<div class="small muted">Itens recorrentes: ${(r.items || []).map((l) => esc(l.name) + ' ' + money(l.monthly)).join(' · ') || '—'}</div>`,
    fields: [
      { name: 'title', label: 'Título', span: 2 },
      { name: 'status', label: 'Situação', type: 'select', empty: false, options: options('contract_status') },
      { name: 'monthly_amount', label: 'Mensalidade (R$)', type: 'money', help: 'Reajustes passam a valer na próxima mensalidade gerada.' },
      { name: 'billing_day', label: 'Dia de vencimento', type: 'number', min: 1, max: 28 },
      { name: 'next_billing_date', label: 'Próximo faturamento', type: 'date' },
      { name: 'end_date', label: 'Fim da vigência', type: 'date', help: 'Renovação automática em Configurações.' },
      { name: 'billing_type', label: 'Forma de pagamento', type: 'select', empty: false, options: options('billing_type') },
      { name: 'auto_charge', label: 'Emitir cobrança no Asaas automaticamente ao gerar a mensalidade', type: 'checkbox', span: 2 },
      { name: 'notes', label: 'Observações', type: 'textarea', rows: 3, span: 2 },
    ],
    onSubmit: async (d) => { await api('/contracts/' + r.id, { method: 'PUT', body: d }); toast('Contrato atualizado.'); onDone(); },
  });
}

/* =================================================================== CATALOG */
async function renderCatalog(el, ctx, head) {
  const admin = can('users');
  el.innerHTML = `${head('Tabela de preços', 'Referência de mercado em Marília (mínimo, média e máximo) e o preço Integra Code (média −10%).', admin ? `<button class="btn" data-recalc>${icon('refresh')} Recalcular (média −10%)</button><button class="btn btn-primary" data-new>${icon('plus')} Novo item</button>` : '')}${tabs('/catalog')}<div data-body><div class="loading-box">Carregando...</div></div>`;
  const load = async () => {
    const cat = await catalog(true);
    $('[data-body]', el).innerHTML = Object.entries(cat.categories).map(([k, c]) => {
      const items = cat.data.filter((i) => i.category === k);
      if (!items.length) return '';
      return `<section class="card" style="margin-bottom:16px"><div class="card-head"><h3>${esc(c.label)}</h3><span class="muted small">${esc(c.hint)}</span></div>
        <div class="table-wrap"><table class="dt cards"><thead><tr><th>Item</th><th>Cobrança</th><th class="num">Mín. mercado</th><th class="num">Média Marília</th><th class="num">Máx. mercado</th><th class="num">Preço Integra</th><th class="num">vs. média</th><th>Desc. máx.</th>${admin ? '<th></th>' : ''}</tr></thead><tbody>
        ${items.map((it) => {
          const diff = Number(it.market_avg) ? (it.price / it.market_avg - 1) * 100 : 0;
          return `<tr class="${Number(it.active) ? '' : 'row-off'}" data-id="${it.id}">
          <td data-label="Item" class="primary"><b>${esc(it.name)}</b> ${it.tier ? `<span class="badge">${esc(it.tier)}</span>` : ''}${Number(it.active) ? '' : ' <span class="badge red">inativo</span>'}<br><small class="muted mono">${esc(it.code)}${it.service_code ? ' · LC 116 item ' + esc(it.service_code) : ''}</small></td>
          <td data-label="Cobrança">${esc(cat.billing[it.billing] || it.billing)}${it.unit ? `<br><small class="muted">por ${esc(it.unit)}</small>` : ''}</td>
          <td data-label="Mín. mercado" class="num">${money(it.market_min)}</td>
          <td data-label="Média Marília" class="num">${money(it.market_avg)}</td>
          <td data-label="Máx. mercado" class="num">${money(it.market_max)}</td>
          <td data-label="Preço Integra" class="num"><b>${money(it.price)}</b></td>
          <td data-label="vs. média" class="num ${diff > 0 ? 'neg' : 'pos'}">${Number(it.market_avg) ? (diff > 0 ? '+' : '') + pct(diff) : '—'}</td>
          <td data-label="Desc. máx.">${pct(it.max_discount)}</td>
          ${admin ? `<td class="actions"><button class="btn btn-xs" data-edit title="Editar">${icon('edit')}</button><a class="btn btn-xs" href="#/pricing?add=${it.id}" title="Simular">${icon('plus')}</a></td>` : ''}
        </tr>`;
        }).join('')}</tbody></table></div>
        ${items[0].market_source ? `<p class="muted small" style="margin:0;padding:10px 18px 14px">Fontes da média: ${esc(items[0].market_source)} Atualizado em ${date(items[0].market_updated_at)}.</p>` : ''}</section>`;
    }).join('');
  };
  $('[data-body]', el).addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit]');
    if (!b) return;
    const it = catalogCache.data.find((x) => x.id === +b.closest('tr').dataset.id);
    priceItemForm(it, load);
  });
  $('[data-new]', el)?.addEventListener('click', () => priceItemForm({}, load));
  $('[data-recalc]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Recalcular o preço de todos os itens como média de mercado −10%? Preços ajustados manualmente serão substituídos.', { okLabel: 'Recalcular' })) return;
    try { const r = await api('/pricing/recalculate', { method: 'POST' }); toast(`${r.count} preço(s) recalculado(s).`); load(); } catch (err) { toastError(err); }
  });
  await load();
}

export async function priceItemForm(it, onDone) {
  const cat = await catalog();
  const isNew = !it.id;
  formModal({
    title: isNew ? 'Novo item de preço' : 'Editar ' + it.name, size: 'lg', submitLabel: 'Salvar item',
    values: { billing: 'one_time', unit: 'projeto', max_discount: 15, active: true, ...it, use_market: isNew },
    fields: [
      { name: 'name', label: 'Nome', required: true, span: 2 },
      { name: 'code', label: 'Código', help: isNew ? 'Opcional — gerado se vazio.' : '' },
      { name: 'category', label: 'Categoria', type: 'select', required: true, options: Object.entries(cat.categories).map(([v, c]) => ({ value: v, label: c.label })) },
      { name: 'billing', label: 'Cobrança', type: 'select', empty: false, options: Object.entries(cat.billing).map(([v, l]) => ({ value: v, label: l })) },
      { name: 'unit', label: 'Unidade', help: 'projeto, mês, hora, usuário, PDV, filial...' },
      { name: 'tier', label: 'Nível / variação' },
      { name: 'service_code', label: 'Item da LC 116 (NFS-e)', help: 'Ex.: 01.05 licenciamento · 01.07 suporte · 01.03 hospedagem.' },
      { name: 'market_min', label: 'Mínimo de mercado (R$)', type: 'money' },
      { name: 'market_avg', label: 'Média em Marília (R$)', type: 'money' },
      { name: 'market_max', label: 'Máximo de mercado (R$)', type: 'money' },
      { name: 'price', label: 'Preço Integra (R$)', type: 'money', required: true },
      { name: 'use_market', label: 'Calcular preço automaticamente (média −10%)', type: 'checkbox', span: 2 },
      { name: 'max_discount', label: 'Desconto máximo sem aprovação (%)', type: 'number', step: '0.5', min: 0, max: 50 },
      { name: 'active', label: 'Ativo no simulador', type: 'checkbox' },
      { name: 'description', label: 'Descrição curta', type: 'textarea', rows: 2, span: 2 },
      { name: 'includes', label: 'O que inclui (um por linha)', type: 'textarea', rows: 3, span: 2 },
      { name: 'market_source', label: 'Fonte da pesquisa de mercado', type: 'textarea', rows: 2, span: 2 },
    ],
    onReady: (form) => {
      const sync = () => { if (form.elements.use_market.checked && Number(form.elements.market_avg.value)) form.elements.price.value = (Math.round(form.elements.market_avg.value * 0.9 / 5) * 5).toFixed(2); };
      ['market_avg', 'use_market'].forEach((n) => form.elements[n].addEventListener('input', sync));
      form.elements.use_market.addEventListener('change', sync);
      sync();
    },
    onSubmit: async (d) => {
      d.market_touched = ['market_min', 'market_avg', 'market_max'].some((k) => Number(d[k] || 0) !== Number(it[k] || 0));
      const r = await api(isNew ? '/pricing/catalog' : '/pricing/catalog/' + it.id, { method: isNew ? 'POST' : 'PUT', body: d });
      toast('Item salvo.');
      catalogCache = null;
      onDone && onDone(r);
    },
  });
}

/* ================================================================ AI ADVISOR */
async function renderAsk(el, ctx, head) {
  const cat = await catalog();
  const examples = ['Quanto cobrar por um site institucional de 5 páginas?', 'Qual o valor médio de manutenção mensal de um sistema web?', 'Quanto custa um app de delivery para restaurante?', 'Valor da hora de consultoria em TI em Marília', 'Preço de integração com iFood e WhatsApp', 'Quanto cobrar por implantação de ERP em loja de roupas?'];
  el.innerHTML = `${head('Consultor de preços com IA', 'Pergunte o valor de qualquer serviço: a IA estima mínimo, média e máximo em Marília e sugere o preço Integra (média −10%).', chat.length ? `<button class="btn" data-reset>${icon('refresh')} Nova conversa</button>` : '')}${tabs('/ask')}
    ${!cat.ai ? '<div class="alert alert-warning">A IA não está configurada: as respostas usam apenas a tabela de preços. Configure o Cloudflare Workers AI em Configurações.</div>' : !cat.web_search ? `<div class="alert alert-info small">Busca na web ao vivo desligada — a IA usa a tabela de referência de Marília e o conhecimento de mercado. Para pesquisar preços em tempo real, informe uma chave da <b>Brave Search API</b> em <a href="#/settings">Configurações → Vendas, preços e contratos</a>.</div>` : `<div class="alert alert-success small">${icon('globe')} Busca na web ao vivo ativa: cada pergunta consulta preços atuais antes de responder.</div>`}
    <div class="ask card">
      <div class="ask-log" data-log></div>
      <form class="ask-form" data-form><textarea name="q" rows="2" placeholder="Ex.: Quanto cobrar por um e-commerce com 200 produtos e integração com o Integra SYS?" aria-label="Pergunta" required></textarea><button class="btn btn-primary" data-send>${icon('send')} Perguntar</button></form>
    </div>`;
  const log = $('[data-log]', el);
  const form = $('[data-form]', el);
  const draw = () => {
    log.innerHTML = chat.length ? chat.map((m, i) => m.role === 'user'
      ? `<div class="ask-msg me"><div>${esc(m.content)}</div></div>`
      : m.pending ? `<div class="ask-msg bot"><div class="ask-typing"><i></i><i></i><i></i></div></div>`
        : `<div class="ask-msg bot"><div>${aiText(m.content)}${m.estimate ? estimateCard(m.estimate, i) : ''}${m.sources?.length ? `<div class="ask-src">${icon('link')} Fontes: ${m.sources.map((u, k) => `<a href="${esc(u)}" target="_blank" rel="noopener">[${k + 1}] ${esc(host(u))}</a>`).join(' ')}</div>` : ''}</div></div>`).join('')
      : `<div class="ask-empty">${icon('sparkles')}<b>Pergunte qualquer valor</b><p>Exemplos:</p><div class="chips-row">${examples.map((x) => `<button class="chip on" type="button" data-ex="${esc(x)}">${esc(x)}</button>`).join('')}</div></div>`;
    log.scrollTop = log.scrollHeight;
  };
  const estimateCard = (e, i) => `<div class="est">
      <div class="est-head"><b>${esc(e.service)}</b><span class="badge ${e.confidence === 'alta' ? 'green' : e.confidence === 'baixa' ? 'yellow' : 'blue'}">confiança ${esc(e.confidence)}</span></div>
      <div class="est-vals"><div><span>Mínimo</span><b>${money(e.min)}</b></div><div><span>Média Marília</span><b>${money(e.avg)}</b></div><div><span>Máximo</span><b>${money(e.max)}</b></div><div class="est-ours"><span>Sugerido Integra (−10%)</span><b>${money(e.suggested)}</b></div></div>
      ${marketBar(e.min, e.avg, e.max, e.suggested)}
      <div class="est-unit muted small">${esc(cat.billing[e.billing] || e.billing)} · por ${esc(e.unit)}</div>
      <div class="est-actions">${e.item_id ? `<a class="btn btn-sm" href="#/pricing?add=${e.item_id}&price=${e.suggested}">${icon('plus')} Usar no simulador</a>` : ''}${can('users') ? `<button class="btn btn-sm" type="button" data-save-item="${i}">${icon('tag')} Salvar na tabela de preços</button>` : ''}</div>
    </div>`;
  const send = async (text) => {
    text = text.trim();
    if (!text) return;
    const history = chat.filter((m) => !m.pending).map((m) => ({ role: m.role, content: m.content }));
    chat.push({ role: 'user', content: text }, { role: 'assistant', pending: true, content: '' });
    draw();
    form.q.value = '';
    try {
      const r = await api('/pricing/ask', { method: 'POST', body: { question: text, history } });
      chat[chat.length - 1] = { role: 'assistant', content: r.answer || 'Sem resposta.', estimate: r.estimate, sources: r.sources };
    } catch (err) {
      chat[chat.length - 1] = { role: 'assistant', content: 'Não consegui responder: ' + err.message };
    }
    draw();
  };
  form.addEventListener('submit', (e) => { e.preventDefault(); send(form.q.value); });
  form.q.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(form.q.value); } });
  log.addEventListener('click', (e) => {
    const ex = e.target.closest('[data-ex]');
    if (ex) { send(ex.dataset.ex); return; }
    const sv = e.target.closest('[data-save-item]');
    if (sv) {
      const est = chat[+sv.dataset.saveItem].estimate;
      const guess = est.billing === 'monthly' ? 'mensalidade' : est.billing === 'hourly' ? 'desenvolvimento' : /implanta/i.test(est.service) ? 'implantacao' : /suporte/i.test(est.service) ? 'suporte' : 'desenvolvimento';
      priceItemForm({ name: est.service, category: guess, billing: est.billing, unit: est.unit, market_min: est.min, market_avg: est.avg, market_max: est.max, price: est.suggested, market_source: 'Estimativa do Consultor IA em ' + date(today()) + '.' + (chat[+sv.dataset.saveItem].sources?.length ? ' Fontes: ' + chat[+sv.dataset.saveItem].sources.join(', ') : '') }, (r) => { toast('Item criado — já disponível no simulador.'); location.hash = `#/pricing?add=${r.id}`; });
    }
  });
  $('[data-reset]', el)?.addEventListener('click', () => { chat = []; renderAsk(el, ctx, head); });
  draw();
  form.q.focus();
}
