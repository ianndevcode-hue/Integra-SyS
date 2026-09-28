/* Financeiro: resumo (saldos, a receber/a pagar, projeção), fluxo de caixa e DRE. */
import { api, $, $$, esc, icon, money, moneyShort, date, chart, chartColors, monthLabel, emptyState, downloadUrl, today } from '/admin/js/core.js';
import { fin, KIND, entryForm, payModal, entryDetail, importModal, transferModal, periodPresets, isoAdd } from '/assets/fiscal/fin.js';

const TABS = [['resumo', 'Resumo', 'home'], ['fluxo', 'Fluxo de caixa', 'flow'], ['dre', 'DRE', 'pie']];

export async function render(el, ctx) {
  await fin.load(true);
  const tab = TABS.some((t) => t[0] === ctx.query.tab) ? ctx.query.tab : 'resumo';
  el.innerHTML = `
    <div class="page-head"><div><h2>Financeiro</h2><p>Saldos, contas a pagar e a receber, fluxo de caixa e resultado da empresa.</p></div>
      <div class="page-actions">
        <button class="btn" data-new="payable">${icon('trendDown')} A pagar</button>
        <button class="btn" data-new="receivable">${icon('trendUp')} A receber</button>
        <button class="btn btn-primary" data-import>${icon('upload')} Importar extrato</button>
      </div></div>
    <div class="tabs">${TABS.map(([k, l, ic]) => `<a class="tab ${k === tab ? 'active' : ''}" href="#/financeiro${k === 'resumo' ? '' : '?tab=' + k}">${icon(ic)} ${l}</a>`).join('')}</div>
    <div data-body><div class="loading-box">Carregando...</div></div>`;
  const reload = () => render(el, ctx);
  $$('[data-new]', el).forEach((b) => b.addEventListener('click', () => entryForm(null, { kind: b.dataset.new, onSaved: reload })));
  $('[data-import]', el).addEventListener('click', () => importModal(null, () => { location.hash = '#/financeiro/extrato'; }));
  const body = $('[data-body]', el);
  if (tab === 'fluxo') return cashflow(body, ctx);
  if (tab === 'dre') return dre(body, ctx);
  return summary(body, reload);
}

/* ------------------------------------------------------------------ resumo */
async function summary(el, reload) {
  const s = await api('/fh/fin/summary');
  const [flow, daily] = await Promise.all([api('/fh/fin/cashflow', { query: { from: today(), to: isoAdd(today(), 90), group: 'week' } }), api('/fh/fin/cashflow', { query: { from: today(), to: isoAdd(today(), 90), group: 'day' } })]);
  const res = s.month.in - s.month.out;
  const alerts = [];
  if (s.receivable.overdue.count) alerts.push(['warning', `<b>${s.receivable.overdue.count}</b> conta(s) a receber vencida(s): <b>${money(s.receivable.overdue.amount)}</b>.`, '#/financeiro/receber?status=overdue', 'Cobrar']);
  if (s.payable.overdue.count) alerts.push(['danger', `<b>${s.payable.overdue.count}</b> conta(s) a pagar vencida(s): <b>${money(s.payable.overdue.amount)}</b>.`, '#/financeiro/pagar?status=overdue', 'Ver contas']);
  if (s.pending_reconciliation) alerts.push(['info', `<b>${s.pending_reconciliation}</b> movimentação(ões) do extrato aguardando conciliação.`, '#/financeiro/extrato', 'Conciliar']);
  if (daily.lowest && daily.lowest.balance < 0) alerts.push(['danger', `Pela previsão, o saldo fica <b>negativo</b> em ${date(daily.lowest.start)} (${money(daily.lowest.balance)}). Antecipe recebimentos ou renegocie pagamentos.`, '#/financeiro?tab=fluxo', 'Ver fluxo']);
  (s.expiring || []).forEach((c) => alerts.push(['warning', `A conexão <b>${esc(c.label || c.provider)}</b> ${c.expired ? 'expirou' : 'expira'} em ${date(c.consent_expires_at)}. ${c.provider === 'inter' ? 'Gere um novo certificado no Inter Empresas e atualize as credenciais.' : 'Renove a autorização para continuar recebendo o extrato.'}`, '#/financeiro/contas?tab=conexoes', 'Atualizar']));
  el.innerHTML = `
    ${alerts.map(([t, msg, href, lbl]) => `<div class="alert alert-${t} fh-alert"><span>${msg}</span><a class="btn btn-sm" href="${href}">${lbl}</a></div>`).join('')}
    <div class="grid g4" style="margin-bottom:16px">
      <a class="card kpi" href="#/financeiro/contas"><div class="k-label"><span class="k-ico">${icon('bank')}</span>Saldo em contas</div><div class="k-value ${s.balance < 0 ? 'neg' : ''}">${money(s.balance)}</div><div class="k-sub">${s.accounts.length} conta(s) · caixa + bancos</div></a>
      <a class="card kpi" href="#/financeiro/receber"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>A receber no mês</div><div class="k-value">${money(s.receivable.month.amount)}</div><div class="k-sub">${s.receivable.overdue.amount ? `<span class="neg">${money(s.receivable.overdue.amount)} vencido</span>` : 'nada vencido'}</div></a>
      <a class="card kpi" href="#/financeiro/pagar"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>A pagar no mês</div><div class="k-value">${money(s.payable.month.amount)}</div><div class="k-sub">${s.payable.overdue.amount ? `<span class="neg">${money(s.payable.overdue.amount)} vencido</span>` : 'nada vencido'}</div></a>
      <a class="card kpi" href="#/financeiro?tab=dre"><div class="k-label"><span class="k-ico violet">${icon('pie')}</span>Resultado do mês (caixa)</div><div class="k-value ${res < 0 ? 'neg' : 'pos'}">${money(res)}</div><div class="k-sub">entrou ${money(s.month.in)} · saiu ${money(s.month.out)}</div></a>
    </div>
    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Saldo previsto — próximos 90 dias</h3><a class="btn btn-sm btn-ghost" href="#/financeiro?tab=fluxo">Fluxo de caixa ${icon('arrowRight')}</a></div>
        <div class="card-body"><div class="chart-box"><canvas data-proj></canvas></div>
        <div class="fin-proj">${s.projection.map((p) => `<div><span>Em ${p.days} dias</span><b class="${p.balance < 0 ? 'neg' : ''}">${money(p.balance)}</b><small>+${moneyShort(p.in)} · −${moneyShort(p.out)}</small></div>`).join('')}</div></div></section>
      <section class="card"><div class="card-head"><h3>Contas</h3><a class="btn btn-sm btn-ghost" href="#/financeiro/contas">Gerenciar</a></div>
        ${s.accounts.length ? `<ul class="list">${s.accounts.map((a) => `<li><div class="grow"><b>${esc(a.name)}</b><small>${esc(a.kind_label)}${a.stmt_balance !== null ? ` · extrato ${money(a.stmt_balance)}` : ''}${a.pending ? ` · <a href="#/financeiro/extrato?account=${a.id}">${a.pending} a conciliar</a>` : ''}</small></div><b class="nowrap ${a.balance < 0 ? 'neg' : ''}">${money(a.balance)}</b></li>`).join('')}</ul>`
          : emptyState('Nenhuma conta.', 'bank')}
        <div class="fh-card-actions"><button class="btn btn-sm" data-transfer>${icon('split')} Transferir</button><a class="btn btn-sm" href="#/financeiro/extrato">${icon('link')} Conciliar extrato</a></div></section>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <section class="card"><div class="card-head"><h3>Vencimentos até ${date(isoAdd(today(), 7))}</h3><div class="page-actions"><a class="btn btn-sm btn-ghost" href="#/financeiro/receber">Receber</a><a class="btn btn-sm btn-ghost" href="#/financeiro/pagar">Pagar</a></div></div>
        ${s.upcoming.length ? `<ul class="list fin-upcoming">${s.upcoming.map((e) => `<li data-id="${e.id}"><span class="fin-dir ${e.kind}">${icon(KIND[e.kind].icon)}</span><div class="grow"><b>${esc(e.description)}</b><small>${e.days_late ? `<span class="neg">venceu há ${e.days_late} dia${e.days_late > 1 ? 's' : ''}</span>` : date(e.due_date) === date(today()) ? '<span class="pos">vence hoje</span>' : 'vence ' + date(e.due_date)}${e.party_name ? ' · ' + esc(e.party_name) : ''}</small></div><b class="nowrap">${money(e.amount)}</b><button class="btn btn-xs btn-success" data-pay="${e.id}">${KIND[e.kind].verb}</button></li>`).join('')}</ul>`
          : emptyState('Nada vencendo nos próximos 7 dias.', 'calendar')}</section>
      <section class="card"><div class="card-head"><h3>Entradas e saídas (12 meses)</h3></div><div class="card-body"><div class="chart-box"><canvas data-months></canvas></div></div></section>
    </div>`;
  $('[data-transfer]', el)?.addEventListener('click', () => transferModal(reload));
  $('.fin-upcoming', el)?.addEventListener('click', (ev) => {
    const pay = ev.target.closest('[data-pay]');
    const li = ev.target.closest('[data-id]');
    if (pay) { ev.stopPropagation(); payModal(s.upcoming.find((x) => x.id === +pay.dataset.pay), reload); return; }
    if (li) entryDetail(+li.dataset.id, reload);
  });
  const c = chartColors();
  const rows = flow.rows;
  chart($('[data-proj]', el), { type: 'bar', data: { labels: rows.map((r) => date(r.start).slice(0, 5) + '–' + date(r.end).slice(0, 5)), datasets: [
    { type: 'line', label: 'Saldo previsto (fim da semana)', data: rows.map((r) => r.balance), borderColor: '#0066FE', backgroundColor: 'rgba(0,102,254,.12)', fill: true, tension: 0.25, pointRadius: 3, yAxisID: 'y' },
    { label: 'Entradas', data: rows.map((r) => r.in_real + r.in_proj), backgroundColor: 'rgba(0,207,129,.65)', borderRadius: 3, yAxisID: 'y' },
    { label: 'Saídas', data: rows.map((r) => -(r.out_real + r.out_proj)), backgroundColor: 'rgba(244,63,94,.6)', borderRadius: 3, yAxisID: 'y' },
  ] }, options: { scales: { x: { ticks: { color: c.text, maxTicksLimit: 10 }, grid: { display: false } }, y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } } } } });
  chart($('[data-months]', el), { type: 'bar', data: { labels: s.months.map((m) => monthLabel(m.month)), datasets: [
    { label: 'Entradas', data: s.months.map((m) => m.in), backgroundColor: '#00CF81', borderRadius: 5 },
    { label: 'Saídas', data: s.months.map((m) => m.out), backgroundColor: '#f43f5e', borderRadius: 5 },
  ] }, options: { scales: { x: { ticks: { color: c.text }, grid: { display: false } }, y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } } } } });
}

/* ------------------------------------------------------------ fluxo de caixa */
async function cashflow(el, ctx) {
  const p = periodPresets();
  const st = { from: ctx.query.from || today().slice(0, 8) + '01', to: ctx.query.to || isoAdd(today(), 60), group: ctx.query.group || 'day', account: ctx.query.account || '' };
  el.innerHTML = `
    <div class="card fin-filters"><div class="toolbar">
      <div class="seg" data-presets>${[['month', 'Este mês'], ['next30', 'Próx. 30 dias'], ['next90', 'Próx. 90 dias'], ['year', 'Ano']].map(([k, l]) => `<button type="button" data-p="${k}">${l}</button>`).join('')}</div>
      <input type="date" data-f="from" value="${st.from}" aria-label="De"><input type="date" data-f="to" value="${st.to}" aria-label="Até">
      <select data-f="group" aria-label="Agrupar"><option value="day">Por dia</option><option value="week">Por semana</option><option value="month">Por mês</option></select>
      <select data-f="account" aria-label="Conta"><option value="">Todas as contas</option>${fin.accounts(true).map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select>
      <span class="spacer"></span><button class="btn btn-sm" data-csv>${icon('download')} CSV</button>
    </div></div>
    <div data-out><div class="loading-box">Carregando...</div></div>`;
  $('[data-f=group]', el).value = st.group;
  $('[data-f=account]', el).value = st.account;
  let data = null;
  const load = async () => {
    const out = $('[data-out]', el);
    out.style.opacity = '.5';
    try {
      data = await api('/fh/fin/cashflow', { query: { from: st.from, to: st.to, group: st.group, account_id: st.account } });
      const t = data.totals;
      const label = (r) => (data.group === 'month' ? monthLabel(r.key) : data.group === 'week' ? `${date(r.start).slice(0, 5)}–${date(r.end).slice(0, 5)}` : date(r.start));
      const shown = data.group === 'day' ? data.rows.filter((r) => r.in_real || r.out_real || r.in_proj || r.out_proj || r.start === data.from || r.end === data.to) : data.rows;
      out.innerHTML = `
        <div class="grid g4" style="margin-bottom:16px">
          <div class="card kpi"><div class="k-label">Saldo inicial</div><div class="k-value">${money(data.start_balance)}</div><div class="k-sub">em ${date(isoAdd(data.from, -1))}</div></div>
          <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>Entradas</div><div class="k-value pos">${money(t.in_real + t.in_proj)}</div><div class="k-sub">realizado ${money(t.in_real)} · previsto ${money(t.in_proj)}</div></div>
          <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>Saídas</div><div class="k-value neg">${money(t.out_real + t.out_proj)}</div><div class="k-sub">realizado ${money(t.out_real)} · previsto ${money(t.out_proj)}</div></div>
          <div class="card kpi"><div class="k-label">Saldo final previsto</div><div class="k-value ${data.end_balance < 0 ? 'neg' : ''}">${money(data.end_balance)}</div><div class="k-sub">${data.lowest ? `menor saldo ${money(data.lowest.balance)} em ${date(data.lowest.start)}` : '—'}</div></div>
        </div>
        ${data.overdue.in || data.overdue.out ? `<div class="alert alert-warning small">Os valores vencidos e ainda em aberto (a receber ${money(data.overdue.in)} · a pagar ${money(data.overdue.out)}) foram projetados para hoje.</div>` : ''}
        <section class="card" style="margin-bottom:16px"><div class="card-body"><div class="chart-box"><canvas data-ch></canvas></div></div></section>
        <section class="card"><div class="table-wrap"><table class="dt cards fin-flow"><thead><tr><th>Período</th><th class="num">Entradas realizadas</th><th class="num">Saídas realizadas</th><th class="num">Entradas previstas</th><th class="num">Saídas previstas</th><th class="num">Saldo</th></tr></thead><tbody>
          ${shown.map((r) => `<tr class="${r.future ? 'future' : ''}"><td class="primary" data-label="Período">${label(r)}${r.start === today() ? ' <span class="badge blue">hoje</span>' : ''}</td>
            <td class="num" data-label="Entradas realizadas">${r.in_real ? money(r.in_real) : '—'}</td><td class="num" data-label="Saídas realizadas">${r.out_real ? money(r.out_real) : '—'}</td>
            <td class="num" data-label="Entradas previstas">${r.in_proj ? money(r.in_proj) : '—'}</td><td class="num" data-label="Saídas previstas">${r.out_proj ? money(r.out_proj) : '—'}</td>
            <td class="num" data-label="Saldo"><b class="${r.balance < 0 ? 'neg' : ''}">${money(r.balance)}</b></td></tr>`).join('')}
          </tbody><tfoot><tr><td>Total</td><td class="num">${money(t.in_real)}</td><td class="num">${money(t.out_real)}</td><td class="num">${money(t.in_proj)}</td><td class="num">${money(t.out_proj)}</td><td class="num"><b>${money(data.end_balance)}</b></td></tr></tfoot></table></div>
          ${data.group === 'day' && shown.length < data.rows.length ? `<p class="small muted" style="padding:0 16px 12px;margin:0">Dias sem movimentação foram ocultados.</p>` : ''}</section>`;
      const c = chartColors();
      chart($('[data-ch]', out), { type: 'bar', data: { labels: data.rows.map(label), datasets: [
        { type: 'line', label: 'Saldo', data: data.rows.map((r) => r.balance), borderColor: '#0066FE', backgroundColor: 'rgba(0,102,254,.1)', fill: true, tension: 0.25, pointRadius: data.rows.length > 45 ? 0 : 2 },
        { label: 'Entradas', data: data.rows.map((r) => r.in_real + r.in_proj), backgroundColor: 'rgba(0,207,129,.7)', borderRadius: 3 },
        { label: 'Saídas', data: data.rows.map((r) => -(r.out_real + r.out_proj)), backgroundColor: 'rgba(244,63,94,.65)', borderRadius: 3 },
      ] }, options: { scales: { x: { ticks: { color: c.text, maxTicksLimit: 14 }, grid: { display: false } }, y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } } } } });
    } catch (err) { out.innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; } finally { out.style.opacity = ''; }
  };
  $$('[data-f]', el).forEach((i) => i.addEventListener('change', () => { st[i.dataset.f] = i.value; load(); }));
  $$('[data-p]', el).forEach((b) => b.addEventListener('click', () => {
    [st.from, st.to] = p[b.dataset.p];
    if (b.dataset.p === 'year') st.group = 'month';
    $('[data-f=from]', el).value = st.from; $('[data-f=to]', el).value = st.to; $('[data-f=group]', el).value = st.group;
    load();
  }));
  $('[data-csv]', el).addEventListener('click', () => {
    if (!data) return;
    const n = (v) => Number(v).toFixed(2).replace('.', ',');
    const csv = '﻿Período;Entradas realizadas;Saídas realizadas;Entradas previstas;Saídas previstas;Saldo\n' + data.rows.map((r) => [data.group === 'month' ? r.key : r.start, n(r.in_real), n(r.out_real), n(r.in_proj), n(r.out_proj), n(r.balance)].join(';')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `fluxo-de-caixa-${data.from}-a-${data.to}.csv`;
    a.click();
  });
  await load();
}

/* ------------------------------------------------------------------- DRE */
async function dre(el, ctx) {
  const p = periodPresets();
  const st = { from: ctx.query.from || p.year[0], to: ctx.query.to || p.month[1], basis: ctx.query.basis || 'cash' };
  el.innerHTML = `
    <div class="card fin-filters"><div class="toolbar">
      <div class="seg">${[['month', 'Este mês'], ['prev', 'Mês passado'], ['quarter', '90 dias'], ['year', 'Ano']].map(([k, l]) => `<button type="button" data-p="${k}">${l}</button>`).join('')}</div>
      <input type="date" data-f="from" value="${st.from}" aria-label="De"><input type="date" data-f="to" value="${st.to}" aria-label="Até">
      <div class="seg" data-basis><button type="button" data-b="cash">Regime de caixa</button><button type="button" data-b="competence">Competência</button></div>
    </div></div>
    <div data-out><div class="loading-box">Carregando...</div></div>`;
  const load = async () => {
    $$('[data-b]', el).forEach((b) => b.classList.toggle('active', b.dataset.b === st.basis));
    const out = $('[data-out]', el);
    out.style.opacity = '.5';
    try {
      const d = await api('/fh/fin/dre', { query: st });
      const g = Object.fromEntries(d.groups.map((x) => [x.group, x]));
      const L = d.lines;
      const line = (label, v, cls = '') => `<tr class="${cls}"><td>${label}</td><td class="num ${v < 0 ? 'neg' : ''}">${money(v)}</td></tr>`;
      const items = (grp, sign) => (g[grp]?.items || []).map((it) => `<tr class="sub"><td>${esc(it.name)}${it.kind === 'receivable' && sign < 0 ? ' <small class="muted">(estorno)</small>' : ''}</td><td class="num">${money(it.kind === 'receivable' ? it.total : -it.total)}</td></tr>`).join('');
      const grpNet = (k) => (g[k]?.income || 0) - (g[k]?.expense || 0);
      out.innerHTML = `
        <div class="grid g4" style="margin-bottom:16px">
          <div class="card kpi"><div class="k-label">Receita bruta</div><div class="k-value">${money(L.gross_revenue)}</div></div>
          <div class="card kpi"><div class="k-label">Resultado operacional</div><div class="k-value ${L.operating_result < 0 ? 'neg' : ''}">${money(L.operating_result)}</div></div>
          <div class="card kpi"><div class="k-label">Lucro líquido</div><div class="k-value ${L.net_result < 0 ? 'neg' : 'pos'}">${money(L.net_result)}</div><div class="k-sub">${L.margin !== null ? 'margem ' + String(L.margin).replace('.', ',') + '%' : ''}</div></div>
          <div class="card kpi"><div class="k-label">Após investimentos e sócios</div><div class="k-value ${L.cash_result < 0 ? 'neg' : ''}">${money(L.cash_result)}</div></div>
        </div>
        <section class="card"><div class="card-head"><h3>Demonstrativo de resultado — ${date(d.from)} a ${date(d.to)}</h3><span class="badge">${d.basis === 'cash' ? 'regime de caixa (pelo pagamento)' : 'competência'}</span></div>
          <div class="table-wrap"><table class="dre">
            ${line('Receita bruta de vendas e serviços', L.gross_revenue, 'total')}${items('revenue', 1)}
            ${line('(−) Impostos sobre o faturamento', -(g.deduction?.expense || 0) + (g.deduction?.income || 0))}${items('deduction', -1)}
            ${line('= Receita líquida', L.net_revenue, 'total')}
            ${line('(−) Custos (fornecedores e terceiros)', grpNet('cost'))}${items('cost', -1)}
            ${line('= Lucro bruto', L.gross_profit, 'total')}
            ${line('(−) Pessoal e pró-labore', grpNet('payroll'))}${items('payroll', -1)}
            ${line('(−) Despesas operacionais', grpNet('expense'))}${items('expense', -1)}
            ${line('= Resultado operacional', L.operating_result, 'total')}
            ${line('(±) Resultado financeiro', grpNet('financial'))}${items('financial', 0)}
            ${line('(±) Outros', grpNet('other'))}${items('other', 0)}
            ${line('= Lucro líquido do período', L.net_result, 'result')}
            ${line('(±) Investimentos', grpNet('investment'))}${items('investment', -1)}
            ${line('(±) Sócios (aportes e retiradas)', grpNet('owner'))}${items('owner', 0)}
            ${line('= Resultado de caixa', L.cash_result, 'total')}
          </table></div>
          <p class="small muted" style="padding:12px 16px;margin:0">Classifique as categorias em Financeiro → Contas e categorias para o DRE ficar completo. Lançamentos sem categoria de despesa entram em "Outros".</p></section>`;
    } catch (err) { out.innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; } finally { out.style.opacity = ''; }
  };
  $$('[data-f]', el).forEach((i) => i.addEventListener('change', () => { st[i.dataset.f] = i.value; load(); }));
  $$('[data-p]', el).forEach((b) => b.addEventListener('click', () => { [st.from, st.to] = p[b.dataset.p]; $('[data-f=from]', el).value = st.from; $('[data-f=to]', el).value = st.to; load(); }));
  $$('[data-b]', el).forEach((b) => b.addEventListener('click', () => { st.basis = b.dataset.b; load(); }));
  await load();
}
