/* Fiscal Hub dashboard: alerts, month KPIs, usage, 6-month chart, recent invoices. */
import { api, $, esc, icon, money, moneyShort, datetime, badge, chart, chartColors, monthLabel, downloadUrl, today, emptyState } from '/admin/js/core.js';
import { fh, fmtDoc } from '/assets/fiscal/state.js';

export async function render(el) {
  const em = fh.emitter();
  const a = fh.access;
  const start6 = new Date(); start6.setMonth(start6.getMonth() - 5); start6.setDate(1);
  const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const monthStart = today().slice(0, 8) + '01';
  const [rep6, repMonth, recent, fin] = await Promise.all([
    api('/fh/reports', { query: { start: iso(start6), end: today() } }),
    api('/fh/reports', { query: { start: monthStart, end: today() } }),
    api('/fh/invoices', { query: { per_page: 8, sort: 'created_at', dir: 'desc' } }),
    api('/fh/fin/summary').catch(() => null),
  ]);
  const k = repMonth.kpis;
  const u = a.usage || {};
  const alerts = [];
  fh.activeEmitters().forEach((e) => {
    if (e.problems.length) alerts.push(['warn', `<b>${esc(e.trade_name || e.legal_name)}</b>: ${e.problems.map(esc).join(' ')}`, `#/empresa/${e.id}`, 'Configurar']);
    if (e.cert_days_left !== null && e.cert_days_left >= 0 && e.cert_days_left <= 30) alerts.push(['warn', `Certificado digital de <b>${esc(e.trade_name || e.legal_name)}</b> vence em ${e.cert_days_left} dia(s).`, `#/empresa/${e.id}`, 'Renovar']);
  });
  if (u.notes_limit && u.notes_used / u.notes_limit >= 0.8) alerts.push(['warn', `Você usou ${u.notes_used} de ${u.notes_limit} notas do plano neste mês.`, '#/assinatura', 'Ver planos']);
  repMonth.alerts.forEach((t) => alerts.push(['info', esc(t), '#/relatorios', 'Relatórios']));
  el.innerHTML = `
    <div class="page-head"><div><h2>Olá, ${esc((window.FH_BOOT?.name || '').split(' ')[0])}!</h2><p>${em ? esc(em.trade_name || em.legal_name) + ' · ' : ''}resumo de ${new Date().toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' })}</p></div>
      <div class="page-actions"><a class="btn" href="${downloadUrl('/fh/download', { from: monthStart, to: today(), what: 'both' })}">${icon('download')} Notas do mês (ZIP)</a><a class="btn btn-primary" href="#/emitir">${icon('plus')} Emitir nota</a></div></div>
    ${alerts.map(([t, msg, href, lbl]) => `<div class="alert ${t === 'warn' ? 'alert-warning' : 'alert-info'} fh-alert"><span>${msg}</span><a class="btn btn-sm" href="${href}">${lbl}</a></div>`).join('')}
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label"><span class="k-ico">${icon('file')}</span>Notas no mês</div><div class="k-value">${k.emitted || 0}</div><div class="k-sub">${k.canceled ? k.canceled + ' cancelada(s)' : 'nenhuma cancelada'}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>Faturado no mês</div><div class="k-value">${money(k.gross)}</div><div class="k-sub">ticket médio ${money(k.avg_ticket)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('receipt')}</span>ISS do mês</div><div class="k-value">${money(k.iss_due)}</div><div class="k-sub">a recolher · retido pelos clientes ${money(k.iss_withheld)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('wallet')}</span>Líquido a receber</div><div class="k-value">${money(k.net)}</div><div class="k-sub">retenções federais ${money(k.federal_withheld)}</div></div>
    </div>
    ${fin ? `<a class="card fh-fin-strip" href="#/financeiro">
      <div><span>${icon('bank')} Saldo em contas</span><b class="${fin.balance < 0 ? 'neg' : ''}">${money(fin.balance)}</b></div>
      <div><span>${icon('trendUp')} A receber no mês</span><b>${money(fin.receivable.month.amount)}</b>${fin.receivable.overdue.amount ? `<small class="neg">${money(fin.receivable.overdue.amount)} vencido</small>` : ''}</div>
      <div><span>${icon('trendDown')} A pagar no mês</span><b>${money(fin.payable.month.amount)}</b>${fin.payable.overdue.amount ? `<small class="neg">${money(fin.payable.overdue.amount)} vencido</small>` : ''}</div>
      <div><span>${icon('link')} Extrato a conciliar</span><b>${fin.pending_reconciliation}</b></div>
      <em>Financeiro ${icon('arrowRight')}</em></a>` : ''}
    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Faturamento com notas (6 meses)</h3><a class="btn btn-sm btn-ghost" href="#/relatorios">Relatórios ${icon('arrowRight')}</a></div><div class="card-body"><div class="chart-box"><canvas id="fh-ch"></canvas></div></div></section>
      <section class="card"><div class="card-head"><h3>Uso do plano</h3><a class="btn btn-sm btn-ghost" href="#/assinatura">${esc(a.plan?.name || '')}</a></div><div class="card-body fh-usage">
        ${usage('Notas no mês', u.notes_used, u.notes_limit)}${usage('Empresas', u.companies, u.companies_limit)}${usage('Análises com IA no mês', u.ai_used, u.ai_limit)}
        <p class="small muted" style="margin:10px 0 0">${a.sub?.paid_until ? 'Acesso liberado até <b>' + new Date(a.sub.paid_until + 'T12:00:00').toLocaleDateString('pt-BR') + '</b>' : ''}</p>
        <div class="fh-quick"><a href="#/clientes">${icon('users')} Clientes</a><a href="#/servicos">${icon('tag')} Serviços</a><a href="#/notas?status=rejected">${icon('alert')} Rejeitadas</a><a href="#/notas?status=draft">${icon('edit')} Rascunhos</a></div>
      </div></section>
    </div>
    <section class="card" style="margin-top:16px"><div class="card-head"><h3>Últimas notas</h3><a class="btn btn-sm btn-ghost" href="#/notas">Todas</a></div>
      ${recent.data.length ? `<ul class="list">${recent.data.map((r) => `<li><div class="grow"><a href="#/notas/${r.id}"><b>${r.nfse_number ? 'Nº ' + esc(r.nfse_number) : 'DPS ' + esc(r.dps_number)} · ${esc(r.toma_name)}</b></a><small>${esc(fmtDoc(r.toma_document))} · ${r.issued_at ? datetime(r.issued_at) : 'criada ' + datetime(r.created_at)}</small></div><b class="nowrap">${money(r.amount)}</b>${badge('fh_invoice_status', r.status)}</li>`).join('')}</ul>` : emptyState('Nenhuma nota ainda. Que tal emitir a primeira?', 'file', '<a class="btn btn-primary" href="#/emitir">Emitir nota</a>')}
    </section>`;
  const c = chartColors();
  chart($('#fh-ch', el), { type: 'bar', data: { labels: rep6.months.map((m) => monthLabel(m.month)), datasets: [
    { label: 'Faturado', data: rep6.months.map((m) => m.amount), backgroundColor: '#0066FE', borderRadius: 6 },
    { label: 'ISS', data: rep6.months.map((m) => m.iss), backgroundColor: '#00CF81', borderRadius: 6 },
  ] }, options: { scales: { x: { ticks: { color: c.text }, grid: { display: false } }, y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } } } } });
}

function usage(label, used = 0, limit = 0) {
  const pct = limit ? Math.min(100, (used / limit) * 100) : 0;
  return `<div class="fh-usage-row"><div><span>${label}</span><b>${used} / ${limit}</b></div><div class="progress ${pct >= 90 ? 'danger' : ''}"><i style="width:${pct}%"></i></div></div>`;
}
