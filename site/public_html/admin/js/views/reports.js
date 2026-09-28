import { api, state, $, $$, esc, icon, money, date, datetime, toast, toastError, formModal, confirmDialog, emptyState, aiText, today, BRAND_MARK, BRAND_WORDMARK } from '../core.js';

/* ------------------------------------------------------------------ formatting */
export const fmtValue = (v, f) => {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  switch (f) {
    case 'money': return money(n);
    case 'pct': return n.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
    case 'hours': return n.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h';
    case 'days': return Math.round(n) + ' d';
    case 'int': return Math.round(n).toLocaleString('pt-BR');
    case 'decimal': return n.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
    case 'date': return date(v);
    default: return esc(v);
  }
};
const shortValue = (v, f) => {
  const n = Number(v || 0);
  if (f === 'money') return Math.abs(n) >= 1000 ? 'R$ ' + (n / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : money(n);
  return fmtValue(n, f);
};

const PERIODS = [
  ['month', 'Este mês'], ['last_month', 'Mês passado'], ['quarter', 'Últimos 3 meses'], ['semester', 'Últimos 6 meses'], ['year', 'Este ano'], ['last12', 'Últimos 12 meses'], ['last_year', 'Ano passado'], ['custom', 'Personalizado'],
];
export function periodRange(p) {
  const d = new Date();
  const iso = (x) => new Date(x.getTime() - x.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const y = d.getFullYear();
  const m = d.getMonth();
  switch (p) {
    case 'month': return [iso(new Date(y, m, 1)), today()];
    case 'last_month': return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
    case 'quarter': return [iso(new Date(y, m - 2, 1)), today()];
    case 'semester': return [iso(new Date(y, m - 5, 1)), today()];
    case 'last12': return [iso(new Date(y, m - 11, 1)), today()];
    case 'last_year': return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    default: return [`${y}-01-01`, today()];
  }
}
const NO_PERIOD = ['receivables_aging', 'payables_aging', 'projects_portfolio', 'cashflow_forecast', 'project_profitability', 'price_table'];

/* ------------------------------------------------------------------ charts */
const PALETTE = ['#0066FE', '#00CF81', '#6D45F6', '#2FD4EE', '#F59E0B', '#F43F5E', '#94A3B8', '#22C55E', '#A78BFA', '#FB7185'];
function drawChart(canvas, ch) {
  if (!window.Chart) { canvas.parentElement.innerHTML = emptyState('Gráfico indisponível (sem conexão com a CDN).', 'alert'); return; }
  const light = document.documentElement.dataset.theme === 'light';
  const text = light ? '#475569' : '#9aa6bd';
  const grid = light ? 'rgba(15,23,42,.08)' : 'rgba(255,255,255,.07)';
  const type = ch.type || 'bar';
  const pie = type === 'doughnut';
  const hbar = type === 'hbar';
  const stacked = type === 'stacked';
  Chart.defaults.color = text;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  const existing = Chart.getChart(canvas);
  existing && existing.destroy();
  new Chart(canvas, {
    type: pie ? 'doughnut' : type === 'line' ? 'line' : 'bar',
    data: {
      labels: ch.labels,
      datasets: ch.datasets.map((d, i) => {
        const c = d.color || PALETTE[i % PALETTE.length];
        const line = d.type === 'line' || type === 'line';
        return { label: d.label, data: d.data, type: d.type, backgroundColor: pie ? ch.labels.map((_, k) => PALETTE[k % PALETTE.length]) : line ? c + '22' : c, borderColor: pie ? (light ? '#fff' : '#0f182d') : c, borderWidth: pie ? 2 : line ? 2.5 : 0, borderRadius: pie || line ? 0 : 6, maxBarThickness: 38, tension: 0.35, fill: type === 'line', pointRadius: line ? 2.5 : 0 };
      }),
    },
    options: {
      responsive: true, maintainAspectRatio: false, indexAxis: hbar ? 'y' : 'x', cutout: pie ? '60%' : undefined,
      interaction: { mode: pie ? 'nearest' : 'index', intersect: false },
      plugins: { legend: { display: pie || ch.datasets.length > 1, position: pie ? 'right' : 'top', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (c) => `${c.dataset.label || c.label}: ${fmtValue(pie ? c.parsed : (hbar ? c.parsed.x : c.parsed.y), ch.format)}` } } },
      scales: pie ? {} : (() => {
        const val = { stacked, grid: { color: grid }, ticks: { callback: (v) => shortValue(v, ch.format) } };
        const cat = { stacked, grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, callback: function (v) { const l = String(this.getLabelForValue(v)); return l.length > 22 ? l.slice(0, 21) + '…' : l; } } };
        return hbar ? { x: val, y: cat } : { x: cat, y: val };
      })(),
    },
  });
}

/* ------------------------------------------------------------------ generic renderer */
export function renderReport(container, r) {
  const kpi = (k) => {
    const good = k.delta === null || k.delta === undefined ? '' : (k.delta >= 0) === (k.good === 'up') ? 'pos' : 'neg';
    return `<div class="card kpi"><div class="k-label">${esc(k.label)}</div><div class="k-value">${fmtValue(k.value, k.format)}</div>
      <div class="k-sub">${k.delta !== null && k.delta !== undefined ? `<span class="${good}">${k.delta >= 0 ? '▲' : '▼'} ${Math.abs(k.delta).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}${k.format === 'pct' ? ' p.p.' : '%'}</span> vs período anterior` : ''}${k.hint ? `${k.delta !== null && k.delta !== undefined ? ' · ' : ''}${esc(k.hint)}` : ''}</div></div>`;
  };
  const table = (t, ti) => `
    <section class="card rpt-table"><div class="card-head"><h3>${esc(t.title)}</h3><span class="muted small">${t.rows.length} linha(s)</span><button class="btn btn-xs btn-ghost no-print" data-csv="${ti}">${icon('download')} CSV</button></div>
      ${t.rows.length ? `<div class="table-wrap"><table class="dt"><thead><tr>${t.columns.map((c) => `<th class="${c.align === 'right' ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>
      <tbody>${t.rows.map((row) => `<tr class="${row._bad ? 'row-bad' : ''} ${row._strong ? 'row-strong' : ''}">${t.columns.map((c) => `<td class="${c.align === 'right' ? 'num' : ''}">${fmtValue(row[c.key], c.format)}</td>`).join('')}</tr>`).join('')}</tbody>
      ${t.footer ? `<tfoot><tr>${t.columns.map((c) => `<td class="${c.align === 'right' ? 'num' : ''}"><b>${fmtValue(t.footer[c.key], c.format)}</b></td>`).join('')}</tr></tfoot>` : ''}</table></div>` : emptyState('Sem dados para o período.', 'search')}
    </section>`;
  container.innerHTML = `
    <div class="print-head">${BRAND_MARK}${BRAND_WORDMARK}<div><b>${esc(r.title)}</b><small>${esc(r.subtitle || '')} · gerado em ${datetime(r.generated_at)}</small></div></div>
    ${r.kpis.length ? `<div class="rpt-kpis">${r.kpis.map(kpi).join('')}</div>` : ''}
    ${r.highlights?.length ? `<div class="alert alert-info rpt-highlights">${icon('sparkles')}<ul>${r.highlights.map((h) => `<li>${esc(h)}</li>`).join('')}</ul></div>` : ''}
    ${r.charts.length ? `<div class="rpt-charts ${r.charts.length === 1 ? 'one' : ''}">${r.charts.map((c, i) => `<section class="card"><div class="card-head"><h3>${esc(c.title)}</h3></div><div class="card-body"><div class="chart-box ${c.type === 'hbar' ? 'tall' : ''}"><canvas data-ci="${i}"></canvas></div></div></section>`).join('')}</div>` : ''}
    ${r.tables.map(table).join('')}`;
  $$('canvas[data-ci]', container).forEach((cv) => drawChart(cv, r.charts[+cv.dataset.ci]));
  $$('[data-csv]', container).forEach((b) => b.addEventListener('click', () => downloadCsv(r.tables[+b.dataset.csv], r.title)));
}

function downloadCsv(t, title) {
  const cell = (v, f) => { if (v === null || v === undefined) return ''; if (['money', 'pct', 'hours', 'decimal'].includes(f)) return String(v).replace('.', ','); return String(v).replace(/"/g, '""'); };
  const lines = [t.columns.map((c) => `"${c.label}"`).join(';'), ...t.rows.map((r) => t.columns.map((c) => `"${cell(r[c.key], c.format)}"`).join(';'))];
  const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = (title + ' - ' + t.title).replace(/[^\w\- à-ú]/gi, '').slice(0, 80) + '.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/* ------------------------------------------------------------------ AI + deck actions */
async function insightsModal(payload, title) {
  const { modal } = await import('../core.js');
  const m = modal({ title: 'Análise executiva — ' + title, size: 'lg', body: '<div class="loading-box">Analisando os números...</div>', footer: '<button class="btn" data-close>Fechar</button>' });
  try {
    const r = await api('/reports/insights', { method: 'POST', body: payload });
    m.body.innerHTML = `${r.ai ? '<span class="badge violet">Gerada por IA</span>' : '<span class="badge">Análise automática (IA não configurada)</span>'}<div class="insight-text" style="margin-top:12px">${aiText(r.text)}</div>`;
  } catch (e) { m.body.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; }
}

async function deckFromReport(body) {
  toast('Gerando a apresentação com os números do relatório...', 'info');
  try {
    const r = await api('/presentations/generate', { method: 'POST', body: { kind: 'report', ...body } });
    location.hash = '#/presentations/' + r.id;
  } catch (e) { toastError(e); }
}

/* ------------------------------------------------------------------ pages */
export async function render(el, ctx) {
  if (ctx.sub === 'builder') return renderBuilder(el, ctx);
  if (ctx.sub === 'view') return renderView(el, ctx);
  return renderCatalog(el, ctx);
}

async function renderCatalog(el) {
  const d = await api('/reports');
  const groups = [...new Set(d.catalog.map((r) => r.group))];
  el.innerHTML = `
    <div class="page-head"><div><h2>Relatórios</h2><p>Relatórios gerenciais prontos, construtor próprio e análise executiva${d.ai ? ' com IA' : ''}.</p></div>
      <div class="page-actions"><a class="btn" href="#/presentations">${icon('play')} Apresentações</a><a class="btn btn-primary" href="#/reports/builder">${icon('plus')} Criar relatório</a></div></div>
    ${d.saved.length ? `<section class="card" style="margin-bottom:16px"><div class="card-head"><h3>${icon('star')} Meus relatórios</h3></div>
      <div class="rpt-grid saved">${d.saved.map((s) => `<div class="rpt-card" data-saved="${s.id}"><a href="#/reports/builder?saved=${s.id}"><span class="rpt-ico">${icon(s.pinned ? 'star' : 'chart')}</span><b>${esc(s.name)}</b><small>${esc(s.description || 'Relatório personalizado')} · ${esc(s.created_by || '')}</small></a>
        <div class="rpt-card-actions"><button class="btn btn-xs btn-ghost" data-pin title="${Number(s.pinned) ? 'Desafixar' : 'Fixar no topo'}">${icon('star')}</button><button class="btn btn-xs btn-ghost" data-del title="Excluir">${icon('trash')}</button></div></div>`).join('')}</div></section>` : ''}
    ${groups.map((g) => `<h3 class="rpt-group">${esc(g)}</h3><div class="rpt-grid">${d.catalog.filter((r) => r.group === g).map((r) => `
      <a class="rpt-card" href="#/reports/${r.key}"><span class="rpt-ico">${icon(r.icon)}</span><b>${esc(r.title)}</b><small>${esc(r.description)}</small></a>`).join('')}</div>`).join('')}`;
  $$('[data-saved]', el).forEach((c) => {
    const id = c.dataset.saved;
    const s = d.saved.find((x) => String(x.id) === id);
    $('[data-pin]', c).addEventListener('click', async () => { await api('/reports/saved/' + id, { method: 'PUT', body: { pinned: !Number(s.pinned) } }); renderCatalog(el); });
    $('[data-del]', c).addEventListener('click', async () => { if (!await confirmDialog(`Excluir o relatório "${s.name}"?`, { danger: true })) return; await api('/reports/saved/' + id, { method: 'DELETE' }); renderCatalog(el); });
  });
}

function periodBar(ctx, key) {
  const p = ctx.query.period || (key === 'dre' || key === 'financial_overview' ? 'year' : 'year');
  const [s, e] = ctx.query.start && ctx.query.end ? [ctx.query.start, ctx.query.end] : periodRange(p);
  return { p, s, e };
}

async function renderView(el, ctx) {
  const key = ctx.key;
  const meta = (await api('/reports')).catalog.find((r) => r.key === key);
  if (!meta) { el.innerHTML = emptyState('Relatório não encontrado ou sem permissão.', 'alert'); return; }
  ctx.setTitle(meta.title);
  let { p, s, e } = periodBar(ctx, key);
  const hasPeriod = !NO_PERIOD.includes(key);
  el.innerHTML = `
    <div class="page-head no-print"><div><a href="#/reports" class="muted small">← Relatórios</a><h2 style="margin-top:4px">${esc(meta.title)}</h2><p>${esc(meta.description)}</p></div>
      <div class="page-actions">
        <button class="btn" data-ai>${icon('sparkles')} Análise executiva</button>
        <button class="btn" data-deck>${icon('play')} Gerar apresentação</button>
        <button class="btn" data-print>${icon('printer')} PDF / imprimir</button>
      </div></div>
    ${hasPeriod ? `<div class="card rpt-filters no-print"><div class="seg seg-wrap">${PERIODS.map(([k, l]) => `<button data-p="${k}" class="${k === p ? 'active' : ''}">${l}</button>`).join('')}</div>
      <label>De <input type="date" class="input" data-s value="${s}"></label><label>até <input type="date" class="input" data-e value="${e}"></label>
      ${key === 'cashflow_forecast' ? '' : ''}</div>` : key === 'cashflow_forecast' ? `<div class="card rpt-filters no-print"><div class="seg">${[30, 60, 90, 120, 180].map((n) => `<button data-days="${n}" class="${String(n) === String(ctx.query.days || 90) ? 'active' : ''}">${n} dias</button>`).join('')}</div></div>` : ''}
    <div data-report><div class="loading-box">Gerando relatório...</div></div>`;
  const box = $('[data-report]', el);
  let days = ctx.query.days || 90;
  const params = () => ({ start: s, end: e, days });
  const load = async () => {
    box.style.opacity = '.5';
    try { renderReport(box, await api('/reports/run/' + key, { query: params() })); } catch (err) { box.innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; } finally { box.style.opacity = ''; }
  };
  $$('[data-p]', el).forEach((b) => b.addEventListener('click', () => {
    p = b.dataset.p;
    $$('[data-p]', el).forEach((x) => x.classList.toggle('active', x === b));
    if (p !== 'custom') { [s, e] = periodRange(p); $('[data-s]', el).value = s; $('[data-e]', el).value = e; load(); } else $('[data-s]', el).focus();
  }));
  $$('[data-s], [data-e]', el).forEach((i) => i.addEventListener('change', () => { s = $('[data-s]', el).value; e = $('[data-e]', el).value; $$('[data-p]', el).forEach((x) => x.classList.toggle('active', x.dataset.p === 'custom')); if (s && e) load(); }));
  $$('[data-days]', el).forEach((b) => b.addEventListener('click', () => { days = b.dataset.days; $$('[data-days]', el).forEach((x) => x.classList.toggle('active', x === b)); load(); }));
  $('[data-ai]', el).addEventListener('click', () => insightsModal({ key, params: params() }, meta.title));
  $('[data-deck]', el).addEventListener('click', () => deckFromReport({ report: key, ...params() }));
  $('[data-print]', el).addEventListener('click', () => window.print());
  await load();
}

/* ------------------------------------------------------------------ builder */
async function renderBuilder(el, ctx) {
  const d = await api('/reports');
  const ds = d.datasets;
  if (!Object.keys(ds).length) { el.innerHTML = emptyState('Nenhuma base disponível para o seu perfil.', 'alert'); return; }
  const saved = ctx.query.saved ? d.saved.find((x) => String(x.id) === String(ctx.query.saved)) : null;
  const [ys, ye] = periodRange('last12');
  const cfg = saved ? JSON.parse(saved.config) : { dataset: Object.keys(ds)[0], dim: 'month', metric: 'count', chart: 'bar', start: ys, end: ye, filters: {}, limit: 30 };
  if (saved) ctx.setTitle(saved.name);
  el.innerHTML = `
    <div class="page-head no-print"><div><a href="#/reports" class="muted small">← Relatórios</a><h2 style="margin-top:4px">${saved ? esc(saved.name) : 'Construtor de relatórios'}</h2><p>Escolha a base, como agrupar, o que medir e o tipo de gráfico. Tudo atualiza na hora.</p></div>
      <div class="page-actions"><button class="btn" data-ai>${icon('sparkles')} Análise</button><button class="btn" data-deck>${icon('play')} Apresentação</button><button class="btn" data-print>${icon('printer')} PDF</button><button class="btn btn-primary" data-save>${icon('star')} ${saved ? 'Salvar alterações' : 'Salvar relatório'}</button></div></div>
    <div class="builder">
      <aside class="card builder-side no-print"><div class="card-body form-grid" style="grid-template-columns:1fr">
        <div class="field"><label>Título</label><input data-k="title" value="${esc(cfg.title || '')}" placeholder="Ex.: Receita por cliente"></div>
        <div class="field"><label>Base de dados</label><select data-k="dataset">${Object.entries(ds).map(([k, v]) => `<option value="${k}" ${k === cfg.dataset ? 'selected' : ''}>${esc(v.label)}</option>`).join('')}</select></div>
        <div class="field"><label>Agrupar por</label><select data-k="dim"></select></div>
        <div class="field"><label>Dividir por (opcional)</label><select data-k="dim2"></select></div>
        <div class="field"><label>Medir</label><select data-k="metric"></select></div>
        <div class="field"><label>Considerar a data de</label><select data-k="date_field"></select></div>
        <div class="form-row2"><div class="field"><label>De</label><input type="date" data-k="start" value="${esc(cfg.start || ys)}"></div><div class="field"><label>Até</label><input type="date" data-k="end" value="${esc(cfg.end || ye)}"></div></div>
        <div class="field"><label>Gráfico</label><div class="chart-types">${[['bar', 'Colunas'], ['hbar', 'Barras'], ['line', 'Linha'], ['doughnut', 'Rosca'], ['stacked', 'Empilhado']].map(([k, l]) => `<button type="button" data-chart="${k}" class="${k === (cfg.chart || 'bar') ? 'active' : ''}">${l}</button>`).join('')}</div></div>
        <div class="field"><label>Máximo de grupos</label><select data-k="limit">${[10, 20, 30, 50, 100].map((n) => `<option ${Number(cfg.limit || 30) === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
        <div data-filters></div>
      </div></aside>
      <div data-out><div class="loading-box">Montando...</div></div>
    </div>`;
  const out = $('[data-out]', el);
  const sel = (k) => $(`[data-k="${k}"]`, el);
  const fill = () => {
    const meta = ds[sel('dataset').value];
    const opt = (obj, cur, empty) => (empty ? `<option value="">${empty}</option>` : '') + Object.entries(obj).map(([k, v]) => `<option value="${k}" ${k === cur ? 'selected' : ''}>${esc(v)}</option>`).join('');
    sel('dim').innerHTML = opt(meta.dims, meta.dims[cfg.dim] ? cfg.dim : Object.keys(meta.dims)[0]);
    sel('dim2').innerHTML = opt(meta.dims, cfg.dim2 || '', '— nenhum —');
    sel('metric').innerHTML = opt(meta.metrics, meta.metrics[cfg.metric] ? cfg.metric : 'count');
    sel('date_field').innerHTML = opt(meta.dates, meta.dates[cfg.date_field] ? cfg.date_field : Object.keys(meta.dates)[0]);
    $('[data-filters]', el).innerHTML = Object.entries(meta.filters || {}).map(([k, vals]) => `<div class="field"><label>Filtrar: ${esc(meta.dims[k] || k)}</label><select data-f="${k}"><option value="">Todos</option>${Object.entries(vals).map(([v, l]) => `<option value="${v}" ${cfg.filters?.[k] === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>`).join('');
    $$('[data-f]', el).forEach((s) => s.addEventListener('change', run));
  };
  const read = () => ({
    title: sel('title').value.trim(), dataset: sel('dataset').value, dim: sel('dim').value, dim2: sel('dim2').value, metric: sel('metric').value, date_field: sel('date_field').value,
    start: sel('start').value, end: sel('end').value, limit: +sel('limit').value, chart: $('[data-chart].active', el)?.dataset.chart || 'bar',
    filters: Object.fromEntries($$('[data-f]', el).map((s) => [s.dataset.f, s.value]).filter(([, v]) => v)),
  });
  let timer;
  async function run() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      out.style.opacity = '.5';
      try { const r = await api('/reports/build', { method: 'POST', body: read() }); renderReport(out, r); } catch (e) { out.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; } finally { out.style.opacity = ''; }
    }, 200);
  }
  sel('dataset').addEventListener('change', () => { cfg.filters = {}; fill(); run(); });
  $$('[data-k]', el).forEach((i) => { if (i.dataset.k !== 'dataset') i.addEventListener(i.tagName === 'INPUT' && i.type !== 'date' ? 'input' : 'change', run); });
  $$('[data-chart]', el).forEach((b) => b.addEventListener('click', () => { $$('[data-chart]', el).forEach((x) => x.classList.toggle('active', x === b)); run(); }));
  $('[data-print]', el).addEventListener('click', () => window.print());
  $('[data-ai]', el).addEventListener('click', () => insightsModal({ key: 'builder', config: read() }, read().title || 'Relatório personalizado'));
  $('[data-deck]', el).addEventListener('click', () => deckFromReport({ report: 'builder', config: read() }));
  $('[data-save]', el).addEventListener('click', () => {
    const c = read();
    if (saved) { api('/reports/saved/' + saved.id, { method: 'PUT', body: { config: c, name: c.title || saved.name } }).then(() => toast('Relatório atualizado.')).catch(toastError); return; }
    formModal({
      title: 'Salvar relatório', size: 'sm', values: { name: c.title },
      fields: [{ name: 'name', label: 'Nome', required: true, span: 2 }, { name: 'description', label: 'Descrição (opcional)', span: 2 }, { name: 'pinned', label: 'Fixar no topo de Relatórios', type: 'checkbox', span: 2 }],
      onSubmit: async (v) => { const r = await api('/reports/saved', { method: 'POST', body: { ...v, config: c } }); toast('Relatório salvo em "Meus relatórios".'); location.hash = '#/reports/builder?saved=' + r.id; },
    });
  });
  fill();
  run();
}
