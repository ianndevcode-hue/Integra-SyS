import { api, $, $$, esc, icon, money, moneyShort, date, badge, options, label, dataTable, formModal, confirmDialog, toast, toastError, lookups, chart, chartColors, today, monthStart, monthEnd, monthLabel, addDays, modal, filesPanel, tagsHtml } from '../core.js';

export async function render(el, ctx) {
  if (ctx.sub === 'cashflow') return renderCashflow(el, ctx);
  if (ctx.sub === 'pnl') return renderPnl(el, ctx);
  if (ctx.sub === 'budget') return renderBudget(el, ctx);
  return renderEntries(el, ctx);
}

/* =============================================================== ENTRIES */
export async function entryForm(values = {}, onSaved) {
  const lk = await lookups();
  const type = values.entry_type || 'receivable';
  const catsFor = (t) => lk.categories.filter((c) => c.entry_type === t).map((c) => ({ value: c.id, label: c.name }));
  formModal({
    title: values.id ? 'Editar lançamento' : (type === 'payable' ? 'Nova conta a pagar' : 'Nova conta a receber'),
    size: 'lg',
    values: { status: 'open', due_date: today(), ...values },
    fields: [
      { name: 'entry_type', label: 'Tipo', type: 'select', options: options('entry_type'), empty: false, required: true },
      { name: 'status', label: 'Situação', type: 'select', options: [{ value: 'open', label: 'Em aberto' }, { value: 'paid', label: 'Pago / recebido' }, { value: 'canceled', label: 'Cancelado' }], empty: false },
      { name: 'description', label: 'Descrição', required: true, span: 2 },
      { name: 'category_id', label: 'Categoria (plano de contas)', type: 'select', options: catsFor(type) },
      { name: 'amount', label: 'Valor (R$)', type: 'money', required: true },
      { name: 'customer_id', label: 'Cliente', type: 'select', options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'supplier', label: 'Fornecedor / favorecido' },
      { name: 'project_id', label: 'Projeto (centro de custo)', type: 'select', options: lk.projects.map((p) => ({ value: p.id, label: p.name })) },
      { name: 'payment_method', label: 'Forma de pagamento', type: 'select', options: ['PIX', 'Boleto', 'Cartão', 'Transferência', 'Dinheiro', 'Débito automático', 'Asaas'].map((v) => ({ value: v, label: v })) },
      { name: 'due_date', label: 'Vencimento', type: 'date', required: true },
      { name: 'competence_date', label: 'Competência', type: 'date' },
      { name: 'paid_at', label: 'Data do pagamento', type: 'date', help: 'Preencha se já foi pago.' },
      { name: 'paid_amount', label: 'Valor pago (R$)', type: 'money', help: 'Com juros/descontos, se houver.' },
      { name: 'document_number', label: 'Nº documento / NF' },
      { name: 'tags', label: 'Tags', type: 'tags' },
      !values.id && { name: 'repeat', label: 'Repetir / parcelar', type: 'select', empty: false, options: [{ value: 1, label: 'Lançamento único' }, ...[2, 3, 4, 5, 6, 10, 12, 18, 24].map((n) => ({ value: n, label: `${n}x mensais` }))], help: 'Cria os próximos lançamentos mensalmente.' },
      { name: 'notes', label: 'Observações', type: 'textarea', span: 2 },
    ],
    onReady: (form) => {
      form.elements.entry_type.addEventListener('change', (e) => {
        const sel = form.elements.category_id;
        sel.innerHTML = '<option value="">Selecione...</option>' + catsFor(e.target.value).map((o) => `<option value="${o.value}">${esc(o.label)}</option>`).join('');
      });
      form.elements.status.addEventListener('change', (e) => { if (e.target.value === 'paid' && !form.elements.paid_at.value) form.elements.paid_at.value = today(); });
    },
    onSubmit: async (d) => {
      if (d.status !== 'paid') { d.paid_at = ''; d.paid_amount = ''; }
      values.id ? await api('/entries/' + values.id, { method: 'PUT', body: d }) : await api('/entries', { method: 'POST', body: d });
      toast('Lançamento salvo.');
      onSaved && onSaved();
    },
  });
}

function payDialog(entry, onDone) {
  formModal({
    title: entry.entry_type === 'receivable' ? 'Registrar recebimento' : 'Registrar pagamento', size: 'sm',
    values: { paid_at: today(), paid_amount: entry.amount, payment_method: entry.payment_method },
    intro: `<p style="margin:0"><b>${esc(entry.description)}</b><br><span class="muted">Vencimento ${date(entry.due_date)} · ${money(entry.amount)}</span></p>`,
    fields: [
      { name: 'paid_at', label: 'Data', type: 'date', required: true },
      { name: 'paid_amount', label: 'Valor pago', type: 'money', required: true },
      { name: 'payment_method', label: 'Forma', type: 'select', options: ['PIX', 'Boleto', 'Cartão', 'Transferência', 'Dinheiro', 'Asaas'].map((v) => ({ value: v, label: v })), span: 2 },
    ],
    submitLabel: 'Confirmar baixa',
    onSubmit: async (d) => { await api(`/entries/${entry.id}/pay`, { method: 'POST', body: d }); toast('Baixa registrada.'); onDone(); },
  });
}

async function renderEntries(el, ctx) {
  const lk = await lookups();
  const type = ctx.query.type || '';
  el.innerHTML = `
    <div class="page-head"><div><h2>Contas a pagar e receber</h2><p>Controle de lançamentos, vencimentos e baixas.</p></div>
      <div class="page-actions">
        <button class="btn btn-success" data-new="receivable">${icon('plus')} A receber</button>
        <button class="btn btn-danger" data-new="payable">${icon('plus')} A pagar</button>
      </div></div>
    <div class="grid g4" style="margin-bottom:16px" data-summary>${'<div class="card kpi"><div class="skeleton" style="height:52px"></div></div>'.repeat(4)}</div>
    <div class="tabs">
      <a class="tab ${type === '' ? 'active' : ''}" href="#/finance/entries">Todos</a>
      <a class="tab ${type === 'receivable' ? 'active' : ''}" href="#/finance/entries?type=receivable">A receber</a>
      <a class="tab ${type === 'payable' ? 'active' : ''}" href="#/finance/entries?type=payable">A pagar</a>
    </div>
    <div data-table></div>`;

  const loadSummary = async () => {
    const s = await api('/finance/summary');
    $('[data-summary]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>A receber</div><div class="k-value">${money(s.receivable_open)}</div><div class="k-sub">${s.receivable_overdue ? `<span class="neg">${money(s.receivable_overdue)} vencido</span>` : 'Nada vencido'} · hoje ${money(s.receivable_today)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>A pagar</div><div class="k-value">${money(s.payable_open)}</div><div class="k-sub">${s.payable_overdue ? `<span class="neg">${money(s.payable_overdue)} vencido</span>` : 'Nada vencido'} · hoje ${money(s.payable_today)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('wallet')}</span>Saldo realizado</div><div class="k-value">${money(s.cash_balance)}</div><div class="k-sub">Entradas − saídas pagas</div></div>
      ${s.goal_month > 0
        ? `<div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('target')}</span>Meta de receita do mês</div><div class="k-value">${Math.round(s.revenue_month / s.goal_month * 100)}%</div><div class="progress" style="margin:6px 0 4px"><i style="width:${Math.min(100, s.revenue_month / s.goal_month * 100)}%"></i></div><div class="k-sub">${money(s.revenue_month)} de ${money(s.goal_month)}</div></div>`
        : `<div class="card kpi"><div class="k-label"><span class="k-ico">${icon('flow')}</span>Saldo projetado</div><div class="k-value">${money(s.cash_balance + s.receivable_open - s.payable_open)}</div><div class="k-sub">Após liquidar tudo em aberto · <a href="#/finance/budget">definir meta</a></div></div>`}`;
  };
  loadSummary();

  const refresh = () => { table.reload(); loadSummary(); };
  const table = dataTable($('[data-table]', el), {
    endpoint: '/entries',
    query: type ? { entry_type: type } : {},
    exportPath: '/entries/export.csv',
    sort: 'due_date', dir: 'asc',
    searchPlaceholder: 'Buscar descrição, fornecedor, cliente, documento...',
    filters: [
      { name: 'status', label: 'Qualquer situação', options: [...options('entry_status')], value: ctx.query.status ?? 'open' },
      { name: 'category_id', label: 'Todas as categorias', options: lk.categories.filter((c) => !type || c.entry_type === type).map((c) => ({ value: c.id, label: c.name })) },
      { name: 'customer_id', label: 'Todos os clientes', options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'from', type: 'date', label: 'Vencimento de' },
      { name: 'to', type: 'date', label: 'Vencimento até' },
    ],
    columns: [
      { label: 'Descrição', sort: 'description', primary: true, render: (e) => `<b>${esc(e.description)}${Number(e.attachments_count) ? ` <span class="muted small" title="Comprovantes anexados">${icon('paperclip')}${e.attachments_count}</span>` : ''}</b><span class="sub">${e.category_name ? `<span class="dot" style="background:${esc(e.category_color || '#94a3b8')}"></span> ${esc(e.category_name)}` : 'Sem categoria'}${e.customer_name ? ' · ' + esc(e.customer_name) : ''}${e.supplier ? ' · ' + esc(e.supplier) : ''}${e.project_name ? ' · ' + esc(e.project_name) : ''}</span>${e.tags ? `<span class="sub">${tagsHtml(e.tags)}</span>` : ''}` },
      !type && { label: 'Tipo', render: (e) => badge('entry_type', e.entry_type) },
      { label: 'Vencimento', sort: 'due_date', render: (e) => `<span class="${e.overdue ? 'neg' : ''}">${date(e.due_date)}</span>` },
      { label: 'Valor', sort: 'amount', num: true, render: (e) => `<b class="${e.entry_type === 'receivable' ? 'pos' : 'neg'}">${e.entry_type === 'payable' ? '−' : ''}${money(e.amount)}</b>` },
      { label: 'Pagamento', sort: 'paid_at', render: (e) => (e.status === 'paid' ? `${date(e.paid_at)}<span class="sub">${money(e.paid_amount)}${e.bank_transaction_id ? ' · ✓ conciliado' : ''}</span>` : '—') },
      { label: 'Situação', render: (e) => badge('entry_status', e.overdue ? 'overdue' : e.status) + (e.charge_id ? ' <span class="badge violet">Asaas</span>' : '') },
    ].filter(Boolean),
    onRowClick: (e) => entryForm(e, refresh),
    actions: (e) => [
      e.status === 'open' && { label: e.entry_type === 'receivable' ? 'Receber' : 'Pagar', icon: 'check', success: true, onClick: () => payDialog(e, refresh) },
      e.status === 'open' && e.entry_type === 'receivable' && !e.charge_id && e.customer_id && { label: 'Cobrar via Asaas', icon: 'receipt', iconOnly: true, onClick: () => { location.hash = `#/finance/charges?new=1&customer_id=${e.customer_id}&entry_id=${e.id}&amount=${e.amount}&due_date=${e.due_date}&description=${encodeURIComponent(e.description)}`; } },
      { label: 'Comprovantes', icon: 'paperclip', iconOnly: true, onClick: () => { const m = modal({ title: 'Comprovantes — ' + e.description, size: 'lg', body: '<div data-files></div>', footer: '<button class="btn" data-close>Fechar</button>', onClose: refresh }); filesPanel($('[data-files]', m.el), 'entry', e.id); } },
      { label: 'Duplicar para o próximo mês', icon: 'copy', iconOnly: true, onClick: async () => { await api(`/entries/${e.id}/duplicate`, { method: 'POST', body: { months: 1 } }); toast('Lançamento duplicado para o mês seguinte.'); refresh(); } },
      { label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog(`Excluir "${e.description}"?`, { danger: true })) { await api('/entries/' + e.id, { method: 'DELETE' }); toast('Lançamento excluído.'); refresh(); } } },
    ],
    bulk: [
      { label: 'Dar baixa hoje', icon: 'check', action: async (ids) => { await api('/entries/bulk', { method: 'POST', body: { ids, action: 'pay' } }); toast('Baixas registradas.'); loadSummary(); } },
      { label: 'Adiar...', icon: 'clock', action: (ids) => bulkForm(ids, 'postpone', 'Adiar vencimentos', [{ name: 'days', label: 'Adiar em quantos dias?', type: 'select', empty: false, options: [7, 10, 15, 30, 45, 60].map((n) => ({ value: n, label: `${n} dias` })), span: 2 }]) },
      { label: 'Nova data...', icon: 'calendar', action: (ids) => bulkForm(ids, 'set_date', 'Definir novo vencimento', [{ name: 'value', label: 'Novo vencimento', type: 'date', required: true, span: 2 }]) },
      { label: 'Categoria...', icon: 'tag', action: (ids) => bulkForm(ids, 'category', 'Alterar categoria', [{ name: 'value', label: 'Categoria', type: 'select', options: lk.categories.map((c) => ({ value: c.id, label: (c.entry_type === 'receivable' ? '↑ ' : '↓ ') + c.name })), span: 2 }]) },
      { label: 'Projeto...', icon: 'kanban', action: (ids) => bulkForm(ids, 'project', 'Vincular a projeto (centro de custo)', [{ name: 'value', label: 'Projeto', type: 'select', options: lk.projects.map((p) => ({ value: p.id, label: p.name })), empty: 'Nenhum', span: 2 }]) },
      { label: 'Tag...', icon: 'tag', action: (ids) => bulkForm(ids, 'tag', 'Adicionar tag', [{ name: 'value', label: 'Tag(s)', type: 'tags', required: true, span: 2 }]) },
      { label: 'Cancelar', action: async (ids) => { await api('/entries/bulk', { method: 'POST', body: { ids, action: 'cancel' } }); toast('Lançamentos cancelados.'); loadSummary(); } },
      { label: 'Excluir', danger: true, icon: 'trash', action: async (ids) => { if (await confirmDialog(`Excluir ${ids.length} lançamento(s)?`, { danger: true })) { await api('/entries/bulk', { method: 'POST', body: { ids, action: 'delete' } }); loadSummary(); } } },
    ],
    totals: (rows) => {
      const rec = rows.filter((r) => r.entry_type === 'receivable').reduce((s, r) => s + Number(r.amount), 0);
      const pay = rows.filter((r) => r.entry_type === 'payable').reduce((s, r) => s + Number(r.amount), 0);
      return `<div class="totals"><span>Nesta página:</span>${rec ? `<span>Receber <b class="pos">${money(rec)}</b></span>` : ''}${pay ? `<span>Pagar <b class="neg">${money(pay)}</b></span>` : ''}${rec && pay ? `<span>Saldo <b>${money(rec - pay)}</b></span>` : ''}</div>`;
    },
  });
  function bulkForm(ids, action, title, fields) {
    return new Promise((resolve) => formModal({
      title, size: 'sm', fields, submitLabel: 'Aplicar',
      onSubmit: async (v) => { const r = await api('/entries/bulk', { method: 'POST', body: { ids, action, ...v } }); toast(`${r.count} lançamento(s) atualizado(s).`); loadSummary(); lookups(true); resolve(); },
    }));
  }
  $$('[data-new]', el).forEach((b) => b.addEventListener('click', () => entryForm({ entry_type: b.dataset.new }, refresh)));
  if (ctx.query.new) entryForm({ entry_type: ctx.query.new === 'payable' ? 'payable' : 'receivable' }, refresh);
}

/* ============================================================== CASHFLOW */
async function renderCashflow(el, ctx) {
  const year = +(ctx.query.year || new Date().getFullYear());
  let days = +(ctx.query.days || 60);
  const [cf, proj] = await Promise.all([api('/finance/cashflow', { query: { year } }), api('/finance/projection', { query: { days } })]);
  const c = chartColors();
  const months = cf.months;
  const totals = months.reduce((t, m) => ({ in: t.in + m.in, out: t.out + m.out, ip: t.ip + m.in_projected, op: t.op + m.out_projected }), { in: 0, out: 0, ip: 0, op: 0 });
  const minPoint = proj.series.reduce((m, p) => (p.balance < m.balance ? p : m), proj.series[0]);

  el.innerHTML = `
    <div class="page-head"><div><h2>Fluxo de caixa</h2><p>Realizado (pago) + previsto (em aberto) e projeção de saldo diário.</p></div>
      <div class="page-actions">
        <div class="seg">${[year - 1, year, year + 1].map((y) => `<button class="${y === year ? 'active' : ''}" data-year="${y}">${y}</button>`).join('')}</div>
        <a class="btn" href="#/finance/bank">${icon('bank')} Importar extrato</a>
      </div></div>

    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('wallet')}</span>Saldo atual</div><div class="k-value">${money(proj.current_balance)}</div><div class="k-sub">Saldo inicial ${year}: ${money(cf.opening_balance)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>Entradas ${year}</div><div class="k-value pos">${money(totals.in)}</div><div class="k-sub">+ ${money(totals.ip)} previstas</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>Saídas ${year}</div><div class="k-value neg">${money(totals.out)}</div><div class="k-sub">+ ${money(totals.op)} previstas</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${minPoint.balance < 0 ? 'red' : 'green'}">${icon('alert')}</span>Menor saldo (${days}d)</div><div class="k-value ${minPoint.balance < 0 ? 'neg' : ''}">${money(minPoint.balance)}</div><div class="k-sub">em ${date(minPoint.date)}</div></div>
    </div>

    ${proj.overdue_receivable || proj.overdue_payable ? `<div class="alert alert-warning">${icon('alert')} Há ${money(proj.overdue_receivable)} a receber e ${money(proj.overdue_payable)} a pagar <b>vencidos</b>, fora da projeção abaixo. <a href="#/finance/entries?status=overdue" style="text-decoration:underline">Ver vencidos</a></div>` : ''}

    <div class="grid g2">
      <section class="card span-2"><div class="card-head"><h3>Mensal ${year}</h3></div><div class="card-body"><div class="chart-box"><canvas id="cf-month"></canvas></div></div></section>
      <section class="card span-2"><div class="card-head"><h3>Projeção de saldo diário</h3>
        <div class="seg">${[30, 60, 90].map((d) => `<button class="${d === days ? 'active' : ''}" data-days="${d}">${d} dias</button>`).join('')}</div></div>
        <div class="card-body"><div class="chart-box sm"><canvas id="cf-proj"></canvas></div></div></section>
      <section class="card span-2"><div class="card-head"><h3>Detalhamento mensal</h3></div>
        <div class="table-wrap"><table class="dt cards"><thead><tr><th>Mês</th><th class="num">Entradas</th><th class="num">Saídas</th><th class="num">Resultado</th><th class="num">A receber</th><th class="num">A pagar</th><th class="num">Saldo acumulado</th></tr></thead>
          <tbody>${months.map((m) => `<tr><td class="primary" data-label="Mês">${new Date(year, m.month - 1, 1).toLocaleDateString('pt-BR', { month: 'long' })}</td>
            <td class="num" data-label="Entradas"><span class="pos">${money(m.in)}</span></td><td class="num" data-label="Saídas"><span class="neg">${money(m.out)}</span></td>
            <td class="num" data-label="Resultado"><b class="${m.net >= 0 ? 'pos' : 'neg'}">${money(m.net)}</b></td>
            <td class="num" data-label="A receber">${money(m.in_projected)}</td><td class="num" data-label="A pagar">${money(m.out_projected)}</td>
            <td class="num" data-label="Saldo acumulado"><b>${money(m.balance)}</b></td></tr>`).join('')}</tbody></table></div></section>
    </div>`;

  const labels = months.map((m) => new Date(year, m.month - 1, 1).toLocaleDateString('pt-BR', { month: 'short' }).replace('.', ''));
  chart($('#cf-month', el), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Entradas', data: months.map((m) => m.in), backgroundColor: c.green, stack: 'in', borderRadius: 4 },
        { label: 'A receber', data: months.map((m) => m.in_projected), backgroundColor: 'rgba(0,207,129,.35)', stack: 'in', borderRadius: 4 },
        { label: 'Saídas', data: months.map((m) => m.out), backgroundColor: c.red, stack: 'out', borderRadius: 4 },
        { label: 'A pagar', data: months.map((m) => m.out_projected), backgroundColor: 'rgba(244,63,94,.35)', stack: 'out', borderRadius: 4 },
        { label: 'Saldo acumulado', data: months.map((m) => m.balance), type: 'line', borderColor: c.orange, backgroundColor: c.orange, tension: .3, yAxisID: 'y' },
      ],
    },
    options: { scales: { x: { stacked: true }, y: { stacked: false, ticks: { callback: (v) => moneyShort(v) } } } },
  });
  chart($('#cf-proj', el), {
    type: 'line',
    data: {
      labels: proj.series.map((p) => date(p.date).slice(0, 5)),
      datasets: [{ label: 'Saldo projetado', data: proj.series.map((p) => p.balance), borderColor: c.orange, backgroundColor: 'rgba(47,123,255,.14)', fill: true, tension: .25, pointRadius: 0 }],
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: (v) => moneyShort(v) } } } },
  });

  $$('[data-year]', el).forEach((b) => b.addEventListener('click', () => { location.hash = `#/finance/cashflow?year=${b.dataset.year}&days=${days}`; }));
  $$('[data-days]', el).forEach((b) => b.addEventListener('click', () => { location.hash = `#/finance/cashflow?year=${year}&days=${b.dataset.days}`; }));
}

/* =================================================================== DRE */
function periodRange(p) {
  const t = today();
  const y = +t.slice(0, 4);
  const m = +t.slice(5, 7);
  switch (p) {
    case 'last_month': { const d = new Date(y, m - 2, 15).toISOString().slice(0, 10); return [monthStart(d), monthEnd(d)]; }
    case 'quarter': { const q = Math.floor((m - 1) / 3); return [`${y}-${String(q * 3 + 1).padStart(2, '0')}-01`, monthEnd(`${y}-${String(q * 3 + 3).padStart(2, '0')}-01`)]; }
    case 'year': return [`${y}-01-01`, `${y}-12-31`];
    case 'last_year': return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    case '12m': return [monthStart(addDays(t, -335)), monthEnd(t)];
    default: return [monthStart(t), monthEnd(t)];
  }
}

async function renderPnl(el, ctx) {
  const period = ctx.query.period || 'month';
  const [start, end] = ctx.query.start ? [ctx.query.start, ctx.query.end] : periodRange(period);
  const p = await api('/finance/pnl', { query: { start, end } });
  const g = p.groups;
  const c = chartColors();
  const periods = [['month', 'Mês atual'], ['last_month', 'Mês anterior'], ['quarter', 'Trimestre'], ['year', 'Ano'], ['12m', '12 meses'], ['last_year', 'Ano anterior']];
  const catRows = (group) => p.by_category.filter((x) => x.group === group).map((x) => `<tr class="sub"><td><span class="dot" style="background:${esc(x.color)}"></span> ${esc(x.category)}</td><td class="num">${money(x.total)}</td><td class="num muted">${g.revenue ? ((x.total / g.revenue) * 100).toFixed(1) + '%' : '—'}</td></tr>`).join('');
  const pct = (v) => (g.revenue ? ((v / g.revenue) * 100).toFixed(1) + '%' : '—');

  el.innerHTML = `
    <div class="page-head"><div><h2>Lucros & gastos</h2><p>DRE gerencial (regime de caixa) de ${date(start)} a ${date(end)}.</p></div>
      <div class="page-actions" style="align-items:center">
        <select class="input" data-period style="width:auto">${periods.map(([v, l]) => `<option value="${v}" ${v === period && !ctx.query.start ? 'selected' : ''}>${l}</option>`).join('')}<option value="custom" ${ctx.query.start ? 'selected' : ''}>Personalizado</option></select>
        <input type="date" class="input" data-start value="${start}" style="width:auto" aria-label="Início">
        <input type="date" class="input" data-end value="${end}" style="width:auto" aria-label="Fim">
        <button class="btn" data-apply>Aplicar</button>
        <a class="btn" href="#/finance/partners">${icon('split')} Distribuir lucros</a>
      </div></div>

    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>Receita bruta</div><div class="k-value">${money(g.revenue)}</div><div class="k-sub">+ ${money(g.other_income)} outras receitas</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>Gastos totais</div><div class="k-value">${money(g.tax + g.cost + g.expense)}</div><div class="k-sub">Impostos, custos e despesas</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${p.net_profit >= 0 ? '' : 'red'}">${icon('pie')}</span>Lucro líquido</div><div class="k-value ${p.net_profit >= 0 ? 'pos' : 'neg'}">${money(p.net_profit)}</div><div class="k-sub">Margem líquida ${p.net_margin}%</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('split')}</span>Distribuído / investido</div><div class="k-value">${money(g.distribution + g.investment)}</div><div class="k-sub">Sócios ${money(g.distribution)} · invest. ${money(g.investment)}</div></div>
    </div>

    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Demonstrativo de resultado</h3></div>
        <div class="table-wrap"><table class="dre">
          <tr class="total"><td>Receita bruta</td><td class="num">${money(g.revenue)}</td><td class="num">100%</td></tr>${catRows('revenue')}
          <tr><td>(−) Impostos sobre faturamento</td><td class="num neg">${money(g.tax)}</td><td class="num muted">${pct(g.tax)}</td></tr>${catRows('tax')}
          <tr class="total"><td>= Receita líquida</td><td class="num">${money(p.net_revenue)}</td><td class="num">${pct(p.net_revenue)}</td></tr>
          <tr><td>(−) Custos dos serviços</td><td class="num neg">${money(g.cost)}</td><td class="num muted">${pct(g.cost)}</td></tr>${catRows('cost')}
          <tr class="total"><td>= Lucro bruto</td><td class="num">${money(p.gross_profit)}</td><td class="num">${p.gross_margin}%</td></tr>
          <tr><td>(−) Despesas operacionais</td><td class="num neg">${money(g.expense)}</td><td class="num muted">${pct(g.expense)}</td></tr>${catRows('expense')}
          <tr class="total"><td>= Resultado operacional</td><td class="num">${money(p.operating_profit)}</td><td class="num">${pct(p.operating_profit)}</td></tr>
          <tr><td>(+) Outras receitas</td><td class="num pos">${money(g.other_income)}</td><td class="num muted">${pct(g.other_income)}</td></tr>${catRows('other_income')}
          <tr class="result"><td>= Lucro líquido</td><td class="num ${p.net_profit >= 0 ? 'pos' : 'neg'}">${money(p.net_profit)}</td><td class="num">${p.net_margin}%</td></tr>
          <tr><td class="muted">Investimentos (fora do resultado)</td><td class="num">${money(g.investment)}</td><td></td></tr>
          <tr><td class="muted">Distribuição de lucros a sócios</td><td class="num">${money(g.distribution)}</td><td></td></tr>
          <tr class="total"><td>Resultado retido no caixa</td><td class="num">${money(p.net_profit - g.investment - g.distribution)}</td><td></td></tr>
        </table></div></section>
      <section class="card"><div class="card-head"><h3>Para onde vai o dinheiro</h3></div><div class="card-body"><div class="chart-box"><canvas id="pnl-donut"></canvas></div></div></section>
      <section class="card span-2" style="grid-column:1/-1"><div class="card-head"><h3>Evolução 12 meses</h3></div><div class="card-body"><div class="chart-box"><canvas id="pnl-12"></canvas></div></div></section>
    </div>`;

  const exp = p.by_category.filter((x) => ['tax', 'cost', 'expense'].includes(x.group));
  if (exp.length) {
    chart($('#pnl-donut', el), {
      type: 'doughnut',
      data: { labels: exp.map((x) => x.category), datasets: [{ data: exp.map((x) => x.total), backgroundColor: exp.map((x) => x.color), borderWidth: 0 }] },
      options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 11 } } }, tooltip: { callbacks: { label: (x) => `${x.label}: ${money(x.parsed)}` } } }, interaction: { mode: 'nearest' } },
    });
  } else $('#pnl-donut', el).parentElement.innerHTML = '<div class="empty-box">Sem gastos no período.</div>';
  chart($('#pnl-12', el), {
    type: 'bar',
    data: {
      labels: p.monthly.map((m) => monthLabel(m.month)),
      datasets: [
        { label: 'Receitas', data: p.monthly.map((m) => m.revenue), backgroundColor: c.green, borderRadius: 5, maxBarThickness: 26 },
        { label: 'Gastos', data: p.monthly.map((m) => m.expenses), backgroundColor: c.red, borderRadius: 5, maxBarThickness: 26 },
        { label: 'Lucro', data: p.monthly.map((m) => m.profit), type: 'line', borderColor: c.orange, backgroundColor: c.orange, tension: .35 },
      ],
    },
    options: { scales: { y: { ticks: { callback: (v) => moneyShort(v) } } } },
  });

  $('[data-period]', el).addEventListener('change', (e) => { if (e.target.value !== 'custom') location.hash = '#/finance/pnl?period=' + e.target.value; });
  $('[data-apply]', el).addEventListener('click', () => { location.hash = `#/finance/pnl?start=${$('[data-start]', el).value}&end=${$('[data-end]', el).value}`; });
}

/* =============================================================== BUDGET */
async function renderBudget(el, ctx) {
  let year = +(ctx.query.year || new Date().getFullYear());
  const MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
  el.innerHTML = `
    <div class="page-head"><div><h2>Orçamento & metas</h2><p>Planeje receitas e despesas por categoria e compare com o realizado mês a mês.</p></div>
      <div class="page-actions"><a class="btn" href="#/reports/budget_vs_actual">${icon('pie')} Relatório orçado × realizado</a><button class="btn btn-primary" data-save>${icon('check')} Salvar orçamento</button></div></div>
    <section class="card" style="margin-bottom:16px"><div class="card-head"><h3>${icon('target')} Metas</h3></div><div class="card-body form-grid cols-4" data-goals></div></section>
    <div class="card" style="margin-bottom:12px"><div class="toolbar"><div class="seg" data-years></div><span class="spacer"></span><span class="muted small">Digite o valor previsto. Abaixo de cada campo aparece o realizado (pago). <b>Alt + clique</b> num campo repete o valor para os meses seguintes.</span></div></div>
    <div data-grid><div class="loading-box">Carregando...</div></div>`;
  const grid = $('[data-grid]', el);
  let data;
  const cellVal = (v) => (v ? String(v).replace('.', ',') : '');
  async function load() {
    data = await api('/budgets', { query: { year } });
    $('[data-years]', el).innerHTML = [year - 1, year, year + 1].map((y) => `<button data-y="${y}" class="${y === year ? 'active' : ''}">${y}</button>`).join('');
    $$('[data-y]', el).forEach((b) => b.addEventListener('click', () => { year = +b.dataset.y; load(); }));
    const g = data.goals;
    $('[data-goals]', el).innerHTML = `
      <div class="field"><label>Meta de receita mensal (R$)</label><input data-goal="goal_revenue_month" value="${cellVal(g.goal_revenue_month)}" inputmode="decimal" placeholder="0,00"><span class="help">Aparece no dashboard e no financeiro.</span></div>
      <div class="field"><label>Custo da equipe por hora (R$)</label><input data-goal="cost_per_hour" value="${cellVal(g.cost_per_hour)}" inputmode="decimal" placeholder="0,00"><span class="help">Usado na rentabilidade dos projetos.</span></div>
      <div class="field"><label>Valor-hora padrão de venda (R$)</label><input data-goal="default_hourly_rate" value="${cellVal(g.default_hourly_rate)}" inputmode="decimal" placeholder="0,00"><span class="help">Sugerido em novos projetos.</span></div>
      <div class="field" style="align-self:end"><button class="btn" data-goals-save>${icon('check')} Salvar metas</button></div>`;
    $('[data-goals-save]', el).addEventListener('click', async () => {
      try { await api('/finance/goals', { method: 'PUT', body: Object.fromEntries($$('[data-goal]', el).map((i) => [i.dataset.goal, i.value])) }); toast('Metas salvas.'); } catch (e) { toastError(e); }
    });
    const months = MONTHS.map((_, i) => `${year}-${String(i + 1).padStart(2, '0')}`);
    const section = (type, title) => {
      const cats = data.categories.filter((c) => c.entry_type === type);
      const tot = (m, src) => cats.reduce((s, c) => s + Number(data[src][c.id]?.[m] || 0), 0);
      return `<section class="card budget-card"><div class="card-head"><h3>${title}</h3></div><div class="table-wrap"><table class="dt budget">
        <thead><tr><th>Categoria</th>${MONTHS.map((m) => `<th class="num">${m}</th>`).join('')}<th class="num">Total</th></tr></thead>
        <tbody>${cats.map((c) => {
          const bt = months.reduce((s, m) => s + Number(data.budget[c.id]?.[m] || 0), 0);
          const at = months.reduce((s, m) => s + Number(data.actual[c.id]?.[m] || 0), 0);
          return `<tr><td><span class="dot" style="background:${esc(c.color || '#94a3b8')}"></span> ${esc(c.name)}</td>${months.map((m) => {
            const b = Number(data.budget[c.id]?.[m] || 0); const a = Number(data.actual[c.id]?.[m] || 0);
            const bad = b > 0 && (type === 'payable' ? a > b * 1.05 : a < b * 0.95 && m < new Date().toISOString().slice(0, 7));
            return `<td class="num"><input class="bcell" data-cat="${c.id}" data-m="${m}" value="${cellVal(b)}" inputmode="decimal" aria-label="${esc(c.name)} ${m}"><small class="${bad ? 'neg' : a ? 'muted' : 'muted faint'}">${a ? moneyShort(a) : '—'}</small></td>`;
          }).join('')}<td class="num"><b>${moneyShort(bt)}</b><small class="${type === 'payable' && at > bt && bt ? 'neg' : 'muted'}">${moneyShort(at)}</small></td></tr>`;
        }).join('')}</tbody>
        <tfoot><tr><td><b>Total</b></td>${months.map((m) => `<td class="num"><b>${moneyShort(tot(m, 'budget'))}</b><small class="muted">${moneyShort(tot(m, 'actual'))}</small></td>`).join('')}<td class="num"><b>${moneyShort(months.reduce((s, m) => s + tot(m, 'budget'), 0))}</b><small class="muted">${moneyShort(months.reduce((s, m) => s + tot(m, 'actual'), 0))}</small></td></tr></tfoot>
      </table></div></section>`;
    };
    grid.innerHTML = section('receivable', `${icon('trendUp')} Receitas`) + section('payable', `${icon('trendDown')} Despesas`);
    $$('.bcell', grid).forEach((i) => i.addEventListener('click', (e) => {
      if (!e.altKey || !i.value) return;
      $$(`.bcell[data-cat="${i.dataset.cat}"]`, grid).filter((x) => x.dataset.m > i.dataset.m).forEach((x) => { x.value = i.value; x.classList.add('changed'); });
      toast('Valor repetido nos meses seguintes. Clique em Salvar.', 'info');
    }));
    $$('.bcell', grid).forEach((i) => i.addEventListener('input', () => i.classList.add('changed')));
  }
  $('[data-save]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const cells = $$('.bcell.changed', grid).map((i) => ({ category_id: +i.dataset.cat, month: i.dataset.m, amount: i.value.replace(/\./g, '').replace(',', '.') || 0 }));
    if (!cells.length) { toast('Nenhuma alteração para salvar.', 'info'); return; }
    btn.classList.add('loading');
    try { await api('/budgets', { method: 'PUT', body: { cells } }); toast('Orçamento salvo.'); load(); } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  await load();
}
