/* Fiscal reports: period KPIs, monthly evolution, clients, services, taxes, AI analysis and exports. */
import { api, $, $$, esc, icon, money, moneyShort, chart, chartColors, monthLabel, aiText, downloadUrl, today, toastError, emptyState } from '/admin/js/core.js';
import { fh, fmtDoc } from '/assets/fiscal/state.js';

const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const PERIODS = [['month', 'Este mês'], ['last_month', 'Mês passado'], ['quarter', 'Últimos 3 meses'], ['year', 'Este ano'], ['last12', '12 meses'], ['last_year', 'Ano passado'], ['custom', 'Personalizado']];
function range(p) {
  const d = new Date(); const y = d.getFullYear(); const m = d.getMonth();
  switch (p) {
    case 'month': return [iso(new Date(y, m, 1)), today()];
    case 'last_month': return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
    case 'quarter': return [iso(new Date(y, m - 2, 1)), today()];
    case 'last12': return [iso(new Date(y, m - 11, 1)), today()];
    case 'last_year': return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    default: return [`${y}-01-01`, today()];
  }
}

export async function render(el, ctx) {
  let p = ctx.query.p || 'year';
  let [start, end] = ctx.query.start ? [ctx.query.start, ctx.query.end || today()] : range(p);
  let emitter = ctx.query.all ? '' : (fh.emitterId || '');
  const multi = fh.activeEmitters().length > 1;
  el.innerHTML = `
    <div class="page-head"><div><h2>Relatórios fiscais</h2><p>Faturamento, impostos, retenções, clientes e serviços — com análise por inteligência artificial.</p></div>
      <div class="page-actions no-print"><button class="btn" data-print>${icon('printer')} Imprimir / PDF</button><div class="fh-dropdown"><button class="btn" data-dl-toggle>${icon('download')} Exportar</button><div class="fh-dropdown-menu hidden" data-dl-menu>
        <button data-dl="both">Notas do período — PDF + XML (ZIP)</button><button data-dl="xml">Somente XML (ZIP, para o contador)</button><button data-dl="pdf">Somente PDF (ZIP)</button><button data-dl="csv">Planilha (CSV/Excel)</button></div></div></div></div>
    <div class="card rpt-filters no-print"><div class="seg seg-wrap">${PERIODS.map(([k, l]) => `<button data-p="${k}" class="${k === p ? 'active' : ''}">${l}</button>`).join('')}</div>
      <label class="small muted">De <input type="date" class="input" data-start value="${start}" style="width:auto"></label><label class="small muted">até <input type="date" class="input" data-end value="${end}" style="width:auto"></label>
      ${multi ? `<select class="input" data-emitter style="width:auto"><option value="">Todas as empresas</option>${fh.activeEmitters().map((e) => `<option value="${e.id}" ${e.id === Number(emitter) ? 'selected' : ''}>${esc(e.trade_name || e.legal_name)}</option>`).join('')}</select>` : ''}</div>
    <div data-body><div class="loading-box">Carregando...</div></div>`;
  const body = $('[data-body]', el);
  async function load() {
    body.innerHTML = '<div class="loading-box">Carregando...</div>';
    const r = await api('/fh/reports', { query: { start, end, emitter_id: emitter } });
    const k = r.kpis;
    const t = r.taxes;
    const u = fh.access.usage || {};
    body.innerHTML = `
      <div class="print-head"><b>Relatório fiscal · Integra Fiscal Hub</b><small>${new Date(start + 'T12:00:00').toLocaleDateString('pt-BR')} a ${new Date(end + 'T12:00:00').toLocaleDateString('pt-BR')}</small></div>
      ${r.alerts.map((a) => `<div class="alert alert-warning">${esc(a)}</div>`).join('')}
      <div class="grid g4" style="margin-bottom:16px">
        ${kpi('Notas emitidas', k.emitted, k.canceled ? `${k.canceled} cancelada(s) · ${money(k.canceled_amount)}` : 'nenhuma cancelada', 'file')}
        ${kpi('Faturamento bruto', money(k.gross), `ticket médio ${money(k.avg_ticket)} · ${k.takers} cliente(s)`, 'trendUp', 'green')}
        ${kpi('ISS a recolher', money(k.iss_due), `retido pelos clientes: ${money(k.iss_withheld)}`, 'receipt', 'violet')}
        ${kpi('Líquido recebível', money(k.net), `retenções federais: ${money(k.federal_withheld)}`, 'wallet', 'blue')}
      </div>
      <section class="card fh-ai no-print-break"><div class="card-head"><h3>${icon('sparkles')} Análise com IA</h3><div style="display:flex;gap:8px;align-items:center"><span class="small muted">${u.ai_used || 0} de ${u.ai_limit || 0} análises no mês</span><button class="btn btn-sm btn-primary" data-ai>${icon('sparkles')} Analisar período</button></div></div>
        <div class="card-body" data-ai-out><p class="muted small" style="margin:0">A IA lê os números do período e aponta tendências, concentração de clientes, impostos, alertas (como limite do MEI/Simples) e recomendações práticas.</p></div></section>
      <div class="grid g3" style="margin-top:16px">
        <section class="card span-2"><div class="card-head"><h3>Evolução mensal</h3></div><div class="card-body"><div class="chart-box"><canvas id="fh-r1"></canvas></div></div></section>
        <section class="card"><div class="card-head"><h3>Impostos no período</h3></div><div class="card-body"><table class="rpt-table"><thead><tr><th>Tributo</th><th class="num">Valor</th><th class="num">Retido</th></tr></thead><tbody>
          ${[['ISS', 'iss'], ['PIS', 'pis'], ['COFINS', 'cofins'], ['CSLL', 'csll'], ['IRRF', 'irrf'], ['INSS', 'inss']].map(([l, key]) => `<tr><td>${l}</td><td class="num">${money(t[key])}</td><td class="num">${money(t[key + '_w'])}</td></tr>`).join('')}
          <tr><td><b>Tributos aproximados</b></td><td class="num" colspan="2"><b>${money(k.approx_taxes)}</b></td></tr></tbody></table></div></section>
      </div>
      <div class="grid g2" style="margin-top:16px">
        <section class="card"><div class="card-head"><h3>Maiores clientes</h3></div>${r.by_taker.length ? `<div class="table-wrap"><table class="rpt-table"><thead><tr><th>Cliente</th><th class="num">Notas</th><th class="num">Valor</th><th class="num">%</th></tr></thead><tbody>${r.by_taker.map((x) => `<tr><td>${esc(x.name)}<br><small class="muted">${esc(fmtDoc(x.document))}</small></td><td class="num">${x.n}</td><td class="num">${money(x.amount)}</td><td class="num">${k.gross ? ((x.amount / k.gross) * 100).toFixed(1).replace('.', ',') : 0}%</td></tr>`).join('')}</tbody></table></div>` : emptyState('Sem notas no período.', 'users')}</section>
        <section class="card"><div class="card-head"><h3>Por serviço</h3></div>${r.by_service.length ? `<div class="card-body"><div class="chart-box" style="height:220px"><canvas id="fh-r2"></canvas></div></div><div class="table-wrap"><table class="rpt-table"><thead><tr><th>Serviço</th><th class="num">Notas</th><th class="num">Valor</th><th class="num">ISS</th></tr></thead><tbody>${r.by_service.map((x) => `<tr><td>${esc(x.name)}${x.lc116 ? ` <small class="muted">${esc(x.lc116)}</small>` : ''}</td><td class="num">${x.n}</td><td class="num">${money(x.amount)}</td><td class="num">${money(x.iss)}</td></tr>`).join('')}</tbody></table></div>` : emptyState('Sem notas no período.', 'tag')}</section>
      </div>`;
    const c = chartColors();
    chart($('#fh-r1', body), { type: 'bar', data: { labels: r.months.map((m) => monthLabel(m.month)), datasets: [
      { label: 'Faturado', data: r.months.map((m) => m.amount), backgroundColor: '#0066FE', borderRadius: 6 },
      { label: 'ISS', data: r.months.map((m) => m.iss), backgroundColor: '#00CF81', borderRadius: 6 },
    ] }, options: { scales: { x: { ticks: { color: c.text }, grid: { display: false } }, y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } } } } });
    if (r.by_service.length) chart($('#fh-r2', body), { type: 'doughnut', data: { labels: r.by_service.map((x) => x.name), datasets: [{ data: r.by_service.map((x) => x.amount), backgroundColor: ['#0066FE', '#00CF81', '#6D45F6', '#2FD4EE', '#F59E0B', '#F43F5E', '#94A3B8', '#22C55E'] }] }, options: { plugins: { legend: { position: 'right', labels: { color: c.text, boxWidth: 10 } }, tooltip: { callbacks: { label: (x) => `${x.label}: ${money(x.parsed)}` } } } } });
    $('[data-ai]', body).addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.classList.add('loading');
      try {
        const out = await api('/fh/reports/ai', { method: 'POST', body: { start, end, emitter_id: emitter } });
        $('[data-ai-out]', body).innerHTML = `<div class="insight-text">${aiText(out.text)}</div><p class="small muted" style="margin:10px 0 0">${out.ai ? 'Gerado por IA a partir dos números do período.' : 'Resumo automático.'} Não substitui a orientação do seu contador.</p>`;
        fh.refresh().catch(() => {});
      } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
    });
  }
  const go = () => { history.replaceState(null, '', `#/relatorios?p=${p}&start=${start}&end=${end}${emitter === '' && multi ? '&all=1' : ''}`); load().catch(toastError); };
  $$('[data-p]', el).forEach((b) => b.addEventListener('click', () => {
    p = b.dataset.p;
    $$('[data-p]', el).forEach((x) => x.classList.toggle('active', x === b));
    if (p !== 'custom') { [start, end] = range(p); $('[data-start]', el).value = start; $('[data-end]', el).value = end; go(); }
  }));
  $('[data-start]', el).addEventListener('change', (e) => { start = e.target.value; p = 'custom'; go(); });
  $('[data-end]', el).addEventListener('change', (e) => { end = e.target.value; p = 'custom'; go(); });
  $('[data-emitter]', el)?.addEventListener('change', (e) => { emitter = e.target.value; go(); });
  $('[data-print]', el).addEventListener('click', () => window.print());
  $('[data-dl-toggle]', el).addEventListener('click', () => $('[data-dl-menu]', el).classList.toggle('hidden'));
  $$('[data-dl]', el).forEach((b) => b.addEventListener('click', () => {
    $('[data-dl-menu]', el).classList.add('hidden');
    const q = { from: start, to: end, emitter_id: emitter };
    location.href = b.dataset.dl === 'csv' ? downloadUrl('/fh/export.csv', q) : downloadUrl('/fh/download', { ...q, what: b.dataset.dl });
  }));
  await load();
}

function kpi(label, value, sub, ico, color = '') {
  return `<div class="card kpi"><div class="k-label"><span class="k-ico ${color}">${icon(ico)}</span>${esc(label)}</div><div class="k-value">${value}</div><div class="k-sub">${sub}</div></div>`;
}
