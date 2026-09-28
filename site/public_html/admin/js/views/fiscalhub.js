/* Integra Fiscal Hub — product control: overview, subscribers (free months, activation, plan), plans & market, invoices monitor, settings. */
import { api, $, $$, esc, icon, money, moneyShort, date, datetime, badge, options, dataTable, formModal, confirmDialog, modal, toast, toastError, chart, chartColors, monthLabel, lookups, emptyState, can } from '../core.js';

const tabs = (active) => `<div class="tabs">${[['', 'Visão geral', 'home'], ['/subscriptions', 'Assinantes', 'users'], ['/plans', 'Planos e preços', 'tag'], ['/invoices', 'Notas emitidas', 'file'], ['/settings', 'Configurações', 'settings']]
  .map(([p, l, ic]) => `<a class="tab ${active === p ? 'active' : ''}" href="#/fiscal-hub${p}">${icon(ic)} ${l}</a>`).join('')}</div>`;
const STATE = { none: 'sem assinatura', pending: 'aguardando pagamento', active: 'emissão liberada', grace: 'em tolerância de atraso', expired: 'bloqueada por atraso', suspended: 'suspensa', canceled: 'cancelada', canceled_active: 'cancelada · acesso até o fim do período' };
const head = (title, text, actions = '') => `<div class="page-head"><div><h2>${title}</h2><p>${text}</p></div><div class="page-actions">${actions}</div></div>`;

export async function render(el, ctx) {
  if (ctx.sub === 'subscriptions') return ctx.id ? detail(el, ctx.id) : subscriptions(el, ctx);
  if (ctx.sub === 'plans') return plans(el);
  if (ctx.sub === 'invoices') return invoices(el);
  if (ctx.sub === 'settings') return settings(el);
  return overview(el);
}

/* ---------------------------------------------------------------- overview */
async function overview(el) {
  const d = await api('/fh-admin/dashboard');
  const s = d.by_status;
  const planName = Object.fromEntries(d.plans.map((p) => [p.code, p.name]));
  el.innerHTML = `${head('Integra Fiscal Hub', 'Emissor de NFS-e vendido aos clientes: assinaturas, uso, planos e receita.', `<a class="btn" href="/fiscal-hub" target="_blank">${icon('globe')} Página do produto</a><button class="btn btn-primary" data-new>${icon('plus')} Nova assinatura</button>`)}${tabs('')}
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('users')}</span>Assinantes ativos</div><div class="k-value">${s.active || 0}</div><div class="k-sub">${s.pending || 0} aguardando pagamento · ${s.canceled || 0} cancelados</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('refresh')}</span>MRR do produto</div><div class="k-value">${money(d.mrr)}</div><div class="k-sub">ARR ${money(d.arr)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${s.past_due ? 'red' : ''}">${icon('alert')}</span>Em atraso</div><div class="k-value">${s.past_due || 0}</div><div class="k-sub">${s.suspended || 0} suspenso(s)</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('file')}</span>Notas no mês</div><div class="k-value">${d.invoices_month.authorized || 0}</div><div class="k-sub">${money(d.invoices_month.amount)} emitidos · ${d.invoices_month.rejected || 0} rejeitada(s) · ${d.emitters} empresa(s)</div></div>
    </div>
    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Últimos 6 meses</h3></div><div class="card-body"><div class="chart-box"><canvas id="fhc"></canvas></div></div></section>
      <section class="card"><div class="card-head"><h3>Por plano</h3></div>${d.by_plan.length ? `<ul class="list">${d.by_plan.map((p) => `<li><div class="grow"><b>${esc(planName[p.plan_code] || p.plan_code)}</b><small>${p.n} assinante(s)</small></div><b>${money(p.mrr)}/mês</b></li>`).join('')}</ul>` : emptyState('Nenhum assinante ativo ainda.', 'users')}</section>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <section class="card"><div class="card-head"><h3>Atividade recente</h3></div>${d.events.length ? `<ul class="list">${d.events.map((e) => `<li><div class="grow"><a href="#/fiscal-hub/subscriptions/${e.subscription_id}"><b>${esc(e.customer_name)}</b></a><small style="white-space:normal">${esc(e.description)}</small></div><span class="small muted nowrap">${datetime(e.created_at)}</span></li>`).join('')}</ul>` : emptyState('Sem atividade.', 'clock')}</section>
      <section class="card"><div class="card-head"><h3>Certificados vencendo (30 dias)</h3></div>${d.cert_expiring.length ? `<ul class="list">${d.cert_expiring.map((c) => `<li><div class="grow"><b>${esc(c.legal_name)}</b><small>${esc(c.customer_name)}</small></div><span class="${c.cert_valid_to < new Date().toISOString().slice(0, 10) ? 'neg' : ''}">${date(c.cert_valid_to)}</span></li>`).join('')}</ul>` : emptyState('Nenhum certificado vencendo.', 'check')}</section>
    </div>`;
  const c = chartColors();
  chart($('#fhc', el), { type: 'bar', data: { labels: d.months.map((m) => monthLabel(m.month)), datasets: [
    { label: 'Receita do produto', data: d.months.map((m) => m.revenue), backgroundColor: '#00CF81', borderRadius: 6, yAxisID: 'y' },
    { label: 'Notas emitidas', data: d.months.map((m) => m.notes), type: 'line', borderColor: '#0066FE', backgroundColor: '#0066FE', yAxisID: 'y1', tension: 0.3 },
  ] }, options: { scales: { y: { ticks: { color: c.text, callback: (v) => moneyShort(v) }, grid: { color: c.grid } }, y1: { position: 'right', ticks: { color: c.text }, grid: { display: false } }, x: { ticks: { color: c.text }, grid: { display: false } } },
    plugins: { tooltip: { callbacks: { label: (x) => (x.dataset.yAxisID === 'y1' ? `${x.dataset.label}: ${x.parsed.y}` : `${x.dataset.label}: ${money(x.parsed.y)}`) } } } } });
  $('[data-new]', el).addEventListener('click', () => newSubscription(d.plans));
}

async function newSubscription(plans) {
  const lk = await lookups();
  formModal({
    title: 'Nova assinatura do Fiscal Hub', submitLabel: 'Criar assinatura', values: { cycle: 'monthly', plan_code: 'profissional', free_months: 0 },
    intro: '<p class="small muted" style="margin:0">Use para clientes que contrataram por fora do site, cortesias ou migrações. O cliente acessa em Área do Cliente → Fiscal Hub.</p>',
    fields: [
      { name: 'customer_id', label: 'Cliente', type: 'select', required: true, span: 2, options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'plan_code', label: 'Plano', type: 'select', empty: false, options: plans.map((p) => ({ value: p.code, label: `${p.name} — ${money(p.price_monthly)}/mês` })) },
      { name: 'cycle', label: 'Ciclo', type: 'select', empty: false, options: [{ value: 'monthly', label: 'Mensal' }, { value: 'yearly', label: 'Anual' }] },
      { name: 'price', label: 'Valor negociado (opcional)', type: 'money', help: 'Em branco = preço do plano.' },
      { name: 'free_months', label: 'Meses grátis na ativação', type: 'number', min: 0, max: 36 },
      { name: 'charge_now', label: 'Gerar a 1ª cobrança no Asaas agora (se não houver meses grátis)', type: 'checkbox', span: 2 },
      { name: 'notes', label: 'Observações internas', type: 'textarea', rows: 2, span: 2 },
    ],
    onSubmit: async (d) => { const s = await api('/fh-admin/subscriptions', { method: 'POST', body: d }); toast('Assinatura criada.'); location.hash = '#/fiscal-hub/subscriptions/' + s.id; },
  });
}

/* ----------------------------------------------------------- subscriptions */
async function subscriptions(el, ctx) {
  const d = await api('/fh-admin/dashboard');
  el.innerHTML = `${head('Assinantes', 'Clientes que usam o Fiscal Hub: situação, uso do mês, empresas e certificados.', `<button class="btn btn-primary" data-new>${icon('plus')} Nova assinatura</button>`)}${tabs('/subscriptions')}<div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/fh-admin/subscriptions', searchPlaceholder: 'Buscar cliente, e-mail ou CPF/CNPJ...',
    filters: [
      { name: 'status', label: 'Todas as situações', options: options('fh_sub_status'), value: ctx.query.status || '' },
      { name: 'plan', label: 'Todos os planos', options: d.plans.map((p) => ({ value: p.code, label: p.name })) },
    ],
    columns: [
      { label: 'Cliente', primary: true, render: (r) => `<b>${esc(r.customer_name)}</b><br><small class="muted">${esc(r.customer_email || '')}</small>` },
      { label: 'Plano', render: (r) => `${esc(r.plan_name || r.plan_code)}<br><small class="muted">${money(r.price)}/${r.cycle === 'yearly' ? 'ano' : 'mês'}</small>` },
      { label: 'Situação', render: (r) => badge('fh_sub_status', r.status) },
      { label: 'Acesso até', render: (r) => (r.paid_until ? `<span class="${r.paid_until < new Date().toISOString().slice(0, 10) ? 'neg' : ''}">${date(r.paid_until)}</span>` : '—') },
      { label: 'Notas no mês', num: true, render: (r) => `${r.notes_month} / ${Number(r.notes_limit || 0) + Number(r.bonus_notes || 0)}` },
      { label: 'Empresas', num: true, render: (r) => r.emitters },
      { label: 'Certificado', render: (r) => (r.cert_valid_to ? `<span class="${r.cert_valid_to < new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10) ? 'neg' : ''}">${date(r.cert_valid_to)}</span>` : '<span class="muted">—</span>') },
    ],
    actions: (r) => [
      { label: 'Meses grátis', icon: 'star', onClick: () => freeMonths(r, () => table.reload()) },
    ],
    onRowClick: (r) => { location.hash = '#/fiscal-hub/subscriptions/' + r.id; },
    emptyText: 'Nenhum assinante ainda.', emptyIcon: 'users',
  });
  $('[data-new]', el).addEventListener('click', () => newSubscription(d.plans));
}

function freeMonths(sub, onDone) {
  formModal({
    title: 'Conceder meses grátis', size: 'sm', submitLabel: 'Conceder', values: { months: 1, cancel_open_charge: true },
    intro: `<p class="small" style="margin:0">Cliente: <b>${esc(sub.customer_name || '')}</b>. O acesso é estendido${sub.paid_until ? ` a partir de ${date(sub.paid_until)}` : ' a partir de hoje'} e a próxima cobrança é adiada.</p>`,
    fields: [
      { name: 'months', label: 'Quantidade de meses', type: 'number', min: 1, max: 36, required: true },
      { name: 'reason', label: 'Motivo (fica no histórico)', span: 2 },
      { name: 'cancel_open_charge', label: 'Cancelar a fatura em aberto (se houver)', type: 'checkbox', span: 2 },
    ],
    onSubmit: async (d) => { const r = await api(`/fh-admin/subscriptions/${sub.id}/free-months`, { method: 'POST', body: d }); toast(`Acesso liberado até ${date(r.paid_until)}.`); onDone && onDone(); },
  });
}

async function detail(el, id) {
  const d = await api('/fh-admin/subscriptions/' + id);
  const s = d.subscription;
  const a = d.access;
  const plans = (await api('/fh-admin/plans')).data;
  el.innerHTML = `
    <div class="page-head"><div><a class="muted small" href="#/fiscal-hub/subscriptions">← Assinantes</a><h2 style="margin-top:4px">${esc(s.customer_name)}</h2><p>${badge('fh_sub_status', s.status)} · plano <b>${esc(a.plan?.name || s.plan_code)}</b> · ${money(s.price)}/${s.cycle === 'yearly' ? 'ano' : 'mês'} · <a href="#/customers/${s.customer_id}">ficha do cliente</a></p></div>
      <div class="page-actions">
        <button class="btn btn-primary" data-free>${icon('star')} Meses grátis</button>
        ${can('finance') ? `<button class="btn" data-activate>${icon('check')} Ativar (pago por fora)</button>` : ''}
        ${can('charges') ? `<button class="btn" data-charge>${icon('receipt')} Gerar cobrança</button>` : ''}
        <button class="btn" data-edit>${icon('edit')} Alterar</button>
        ${s.status !== 'suspended' && s.status !== 'canceled' ? `<button class="btn btn-danger" data-suspend>Suspender</button>` : `<button class="btn" data-reactivate>Reativar</button>`}
      </div></div>
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label">Acesso até</div><div class="k-value">${s.paid_until ? date(s.paid_until) : '—'}</div><div class="k-sub">${esc(STATE[a.state] || a.state)}</div></div>
      <div class="card kpi"><div class="k-label">Notas no mês</div><div class="k-value">${a.usage.notes_used} / ${a.usage.notes_limit}</div><div class="k-sub">${Number(s.bonus_notes) ? s.bonus_notes + ' extra(s) cortesia' : 'sem extras'}</div></div>
      <div class="card kpi"><div class="k-label">Empresas</div><div class="k-value">${a.usage.companies} / ${a.usage.companies_limit}</div><div class="k-sub">IA: ${a.usage.ai_used}/${a.usage.ai_limit} no mês</div></div>
      <div class="card kpi"><div class="k-label">Próxima cobrança</div><div class="k-value">${s.next_charge_date && s.status !== 'canceled' ? date(s.next_charge_date) : '—'}</div><div class="k-sub">${s.terms_accepted_at ? `termos v${esc(s.terms_version)} · ${esc(s.terms_name || '')} · IP ${esc(s.terms_ip || '')}` : 'criada pela equipe'}</div></div>
    </div>
    <div class="grid g2">
      <section class="card"><div class="card-head"><h3>Histórico</h3></div><ul class="list">${d.events.map((e) => `<li><div class="grow"><b style="white-space:normal">${esc(e.description)}</b><small>${datetime(e.created_at)} · ${esc(e.user_name || 'sistema')}</small></div></li>`).join('')}</ul></section>
      <section class="card"><div class="card-head"><h3>Faturas do produto</h3></div>${d.charges.length ? `<ul class="list">${d.charges.map((c) => `<li><div class="grow"><b>${money(c.amount)}</b><small>vence ${date(c.due_date)}${c.paid_at ? ' · paga ' + date(c.paid_at) : ''}</small></div>${badge('charge_status', c.status)}${c.invoice_url ? `<a class="btn btn-xs" href="${esc(c.invoice_url)}" target="_blank" rel="noopener">${icon('external')}</a>` : ''}</li>`).join('')}</ul>` : emptyState('Nenhuma fatura.', 'receipt')}</section>
      <section class="card"><div class="card-head"><h3>Empresas emissoras</h3></div>${d.emitters.length ? `<ul class="list">${d.emitters.map((e) => `<li><div class="grow"><b>${esc(e.legal_name)}</b><small>${esc(e.document)} · ${e.provider === 'nacional' ? 'Nacional' + (e.environment === 'production' ? '' : ' (testes)') : 'SIGISS Marília'}${e.cert_valid_to ? ' · certificado até ' + date(e.cert_valid_to) : ''}${e.problems.length ? ' · <span class="neg">' + esc(e.problems.join(' ')) + '</span>' : ''}</small></div>${e.ready ? '<span class="badge green">pronta</span>' : '<span class="badge yellow">pendente</span>'}</li>`).join('')}</ul>` : emptyState('O cliente ainda não cadastrou a empresa.', 'settings')}</section>
      <section class="card"><div class="card-head"><h3>Últimas notas</h3></div>${d.invoices.length ? `<ul class="list">${d.invoices.map((n) => `<li><div class="grow"><b>${n.nfse_number ? 'Nº ' + esc(n.nfse_number) : '#' + n.id} · ${esc(n.toma_name || '')}</b><small>${n.issued_at ? datetime(n.issued_at) : ''}${n.error_message ? ' · <span class="neg">' + esc(n.error_message.slice(0, 120)) + '</span>' : ''}</small></div><b class="nowrap">${money(n.amount)}</b>${badge('fh_invoice_status', n.status)}</li>`).join('')}</ul>` : emptyState('Nenhuma nota ainda.', 'file')}
        <div class="card-body"><div class="chart-box" style="height:160px"><canvas id="fhu"></canvas></div></div></section>
    </div>
    ${s.notes ? `<div class="alert" style="margin-top:16px;white-space:pre-wrap">${esc(s.notes)}</div>` : ''}`;
  chart($('#fhu', el), { type: 'bar', data: { labels: d.usage.map((u) => monthLabel(u.month)), datasets: [{ label: 'Notas', data: d.usage.map((u) => u.notes), backgroundColor: '#0066FE', borderRadius: 6 }] },
    options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => `${x.parsed.y} nota(s)` } } } } });
  const reload = () => detail(el, id);
  $('[data-free]', el).addEventListener('click', () => freeMonths({ ...s }, reload));
  $('[data-activate]', el)?.addEventListener('click', () => formModal({ title: 'Ativar manualmente', size: 'sm', submitLabel: 'Ativar', values: { months: s.cycle === 'yearly' ? 12 : 1 },
    intro: '<p class="small muted" style="margin:0">Para pagamentos recebidos fora do Asaas (PIX direto, transferência). Registre o valor no Financeiro.</p>',
    fields: [{ name: 'months', label: 'Meses pagos', type: 'number', min: 1, max: 24 }, { name: 'reason', label: 'Observação', span: 2 }],
    onSubmit: async (dd) => { await api(`/fh-admin/subscriptions/${id}/activate`, { method: 'POST', body: dd }); toast('Assinatura ativada.'); reload(); } }));
  $('[data-charge]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog(`Gerar agora uma cobrança de ${money(s.price)} no Asaas para ${s.customer_name}?`, { okLabel: 'Gerar cobrança' })) return;
    try { const c = await api(`/fh-admin/subscriptions/${id}/charge`, { method: 'POST', body: {} }); toast('Cobrança gerada.'); if (c.invoice_url) window.open(c.invoice_url, '_blank'); reload(); } catch (err) { toastError(err); }
  });
  $('[data-edit]', el).addEventListener('click', () => formModal({
    title: 'Alterar assinatura', values: { plan_code: s.plan_code, cycle: s.cycle, price: s.price, bonus_notes: s.bonus_notes, paid_until: s.paid_until || '', notes: s.notes || '' },
    fields: [
      { name: 'plan_code', label: 'Plano', type: 'select', empty: false, options: plans.map((p) => ({ value: p.code, label: p.name })) },
      { name: 'cycle', label: 'Ciclo', type: 'select', empty: false, options: [{ value: 'monthly', label: 'Mensal' }, { value: 'yearly', label: 'Anual' }] },
      { name: 'price', label: 'Valor (R$)', type: 'money', help: 'Vale para as próximas cobranças.' },
      { name: 'bonus_notes', label: 'Notas extras por mês (cortesia)', type: 'number', min: 0 },
      { name: 'paid_until', label: 'Acesso liberado até', type: 'date' },
      { name: 'reason', label: 'Motivo (histórico)' },
      { name: 'notes', label: 'Observações internas', type: 'textarea', rows: 3, span: 2 },
    ],
    onSubmit: async (dd) => { await api('/fh-admin/subscriptions/' + id, { method: 'PUT', body: dd }); toast('Assinatura atualizada.'); reload(); },
  }));
  $('[data-suspend]', el)?.addEventListener('click', () => formModal({ title: 'Suspender ou cancelar', size: 'sm', submitLabel: 'Confirmar', values: { status: 'suspended' },
    fields: [{ name: 'status', label: 'Ação', type: 'select', empty: false, span: 2, options: [{ value: 'suspended', label: 'Suspender (bloqueia a emissão)' }, { value: 'canceled', label: 'Cancelar assinatura' }] }, { name: 'reason', label: 'Motivo', span: 2, required: true }],
    onSubmit: async (dd) => { await api('/fh-admin/subscriptions/' + id, { method: 'PUT', body: dd }); toast('Situação alterada.'); reload(); } }));
  $('[data-reactivate]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Reativar esta assinatura? O acesso segue a data "acesso até".', { okLabel: 'Reativar' })) return;
    await api('/fh-admin/subscriptions/' + id, { method: 'PUT', body: { status: 'active', reason: 'Reativada pela equipe' } }).catch(toastError);
    reload();
  });
}

/* -------------------------------------------------------------------- plans */
async function plans(el) {
  const d = await api('/fh-admin/plans');
  const admin = can('users');
  const refsHtml = Object.entries(d.refs).map(([code, list]) => {
    const avg = list.reduce((s, r) => s + r[1], 0) / list.length;
    const plan = d.data.find((p) => p.code === code);
    return `<section class="card"><div class="card-head"><h3>${esc(plan?.name || code)}</h3><span class="small muted">média ${money(avg)} → −10% = <b>${money((d.suggested.find((x) => x.code === code) || {}).price_monthly)}</b></span></div>
      <ul class="list">${list.map(([name, price, url]) => `<li><div class="grow"><b style="white-space:normal">${esc(name)}</b><small><a href="${esc(url)}" target="_blank" rel="noopener">${esc(url.replace(/^https?:\/\//, '').split('/')[0])}</a></small></div><b class="nowrap">${money(price)}</b></li>`).join('')}</ul></section>`;
  }).join('');
  el.innerHTML = `${head('Planos e preços', `Preço = média de mercado −10% (pesquisa de ${date(d.market_date)}; sistemas integrados a Marília primeiro, média nacional para completar).`, admin ? `<button class="btn" data-reset>${icon('refresh')} Recalcular pela média −10%</button>` : '')}${tabs('/plans')}
    <div class="fh-admin-plans">${d.data.map((p) => `<section class="card"><div class="card-head"><div><h3>${esc(p.name)} ${p.highlight ? '<span class="badge blue">destaque</span>' : ''} ${Number(p.active) ? '' : '<span class="badge">inativo</span>'}</h3><small class="muted">${esc(p.tagline || '')}</small></div>${admin ? `<button class="btn btn-sm" data-edit="${p.id}">${icon('edit')} Editar</button>` : ''}</div>
      <div class="card-body"><div class="fh-admin-price"><b>${money(p.price_monthly)}</b>/mês · ${money(p.price_yearly)}/ano</div>
      <p class="small muted" style="margin:4px 0 10px">${Number(p.market_avg) > 0 ? 'Média de mercado ' + money(p.market_avg) + ' · ' : 'Plano gratuito · '}${p.notes_limit} notas/mês · ${p.companies_limit} empresa(s) · IA ${p.flags.ai_quota}/mês${p.flags.recurring ? ' · recorrentes' : ''}${p.flags.batch ? ' · lote' : ''}${p.flags.priority ? ' · prioritário' : ''}${p.flags.open_finance ? ' · Open Finance' : ''}</p>
      <ul class="small" style="margin:0;padding-left:18px">${p.features.map((f) => `<li>${esc(f)}</li>`).join('')}</ul></div></section>`).join('')}</div>
    <h3 style="margin:24px 0 12px">Referências de mercado usadas</h3><div class="grid g2">${refsHtml}</div>`;
  $('[data-reset]', el)?.addEventListener('click', async () => { if (!await confirmDialog('Recalcular os preços dos 4 planos pela média de mercado −10%? Assinaturas existentes mantêm o valor contratado.', { okLabel: 'Recalcular' })) return; await api('/fh-admin/plans/reset-prices', { method: 'POST' }).catch(toastError); plans(el); });
  $$('[data-edit]', el).forEach((b) => b.addEventListener('click', () => {
    const p = d.data.find((x) => x.id === +b.dataset.edit);
    formModal({
      title: 'Editar plano ' + p.name, size: 'lg', values: { ...p, features: p.features.join('\n'), ai_quota: p.flags.ai_quota, recurring: p.flags.recurring, batch: p.flags.batch, priority: p.flags.priority, open_finance: p.flags.open_finance },
      fields: [
        { name: 'name', label: 'Nome', required: true }, { name: 'tagline', label: 'Frase' },
        { name: 'price_monthly', label: 'Preço mensal (R$)', type: 'money' }, { name: 'price_yearly', label: 'Preço anual (R$)', type: 'money' },
        { name: 'notes_limit', label: 'Notas por mês', type: 'number', min: 1 }, { name: 'companies_limit', label: 'Empresas', type: 'number', min: 1 },
        { name: 'ai_quota', label: 'Análises com IA por mês', type: 'number', min: 0 },
        { name: 'recurring', label: 'Notas recorrentes', type: 'checkbox' }, { name: 'batch', label: 'Emissão em lote', type: 'checkbox' }, { name: 'priority', label: 'Suporte prioritário', type: 'checkbox' },
        { name: 'open_finance', label: 'Conexão bancária por Open Finance', type: 'checkbox' },
        { name: 'highlight', label: 'Destacar no site', type: 'checkbox' }, { name: 'active', label: 'Ativo (vendido no site)', type: 'checkbox' },
        { name: 'features', label: 'Vantagens (uma por linha)', type: 'textarea', rows: 8, span: 2 },
      ],
      onSubmit: async (v) => { await api('/fh-admin/plans/' + p.id, { method: 'PUT', body: { ...v, flags: { ai_quota: v.ai_quota, recurring: v.recurring, batch: v.batch, priority: v.priority, open_finance: v.open_finance } } }); toast('Plano salvo.'); plans(el); },
    });
  }));
}

/* ----------------------------------------------------------------- invoices */
function invoices(el) {
  el.innerHTML = `${head('Notas emitidas pelos clientes', 'Monitor de emissões de todos os assinantes — útil para suporte a rejeições.')}${tabs('/invoices')}<div data-table></div>`;
  dataTable($('[data-table]', el), {
    endpoint: '/fh-admin/invoices', searchPlaceholder: 'Buscar cliente, tomador ou número...',
    filters: [{ name: 'status', label: 'Todas as situações', options: options('fh_invoice_status') }],
    columns: [
      { label: 'Cliente', primary: true, render: (r) => `<a href="#/customers/${r.customer_id}"><b>${esc(r.customer_name)}</b></a><br><small class="muted">${esc(r.emitter_name)}</small>` },
      { label: 'Nota', render: (r) => (r.nfse_number ? 'Nº ' + esc(r.nfse_number) : 'DPS ' + esc(r.dps_number)) + `<br><small class="muted">${r.provider === 'sigiss' ? 'SIGISS' : 'Nacional'}${r.environment !== 'production' ? ' · testes' : ''}</small>` },
      { label: 'Tomador', render: (r) => esc(r.toma_name || '') },
      { label: 'Valor', num: true, render: (r) => money(r.amount) },
      { label: 'Data', render: (r) => datetime(r.issued_at || r.created_at) },
      { label: 'Situação', render: (r) => badge('fh_invoice_status', r.status) + (r.error_message ? `<br><small class="neg">${esc(r.error_message.slice(0, 90))}</small>` : '') },
    ],
    emptyText: 'Nenhuma nota ainda.', emptyIcon: 'file',
  });
}

/* ----------------------------------------------------------------- settings */
async function settings(el) {
  const [s, lk] = await Promise.all([api('/settings').catch(() => null), lookups()]);
  if (!s) { el.innerHTML = `${head('Configurações', '')}${tabs('/settings')}<div class="alert alert-warning">Somente administradores podem alterar as configurações.</div>`; return; }
  el.innerHTML = `${head('Configurações do Fiscal Hub', 'Vendas online, cobrança e tolerância de atraso.')}${tabs('/settings')}
    <form class="card" data-form><div class="card-body form-grid cols-4">
      <div class="field"><label>Vendas no site</label><select name="fh_sales_enabled"><option value="1">Abertas</option><option value="0" ${s.fh_sales_enabled === '0' ? 'selected' : ''}>Pausadas</option></select></div>
      <div class="field"><label>Gerar renovação com antecedência (dias)</label><input name="fh_lead_days" type="number" min="0" max="30" value="${esc(s.fh_lead_days || '7')}"></div>
      <div class="field"><label>Tolerância após o vencimento (dias)</label><input name="fh_grace_days" type="number" min="0" max="30" value="${esc(s.fh_grace_days || '5')}"><span class="help">Depois disso a emissão é bloqueada.</span></div>
      <div class="field"><label>Categoria da receita</label><select name="fh_revenue_category"><option value="">Sem categoria</option>${lk.categories.filter((c) => c.entry_type === 'receivable').map((c) => `<option value="${c.id}" ${String(s.fh_revenue_category) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
    </div>
    <div class="card-head" style="border-top:1px solid var(--border)"><h3>${icon('link')} Open Finance (financeiro dos clientes)</h3></div>
    <div class="card-body form-grid cols-4">
      <p class="small muted span-2" style="margin:0;grid-column:1/-1">Os clientes já têm opções <b>gratuitas</b> sem nenhuma configuração aqui: Meu Pluggy (com a conta deles), API do Banco Inter PJ, API do Asaas e importação de OFX. Preencha abaixo só se a Integra contratar o agregador Pluggy para oferecer a conexão "Open Finance Integra" nos planos com esse recurso.</p>
      <div class="field"><label>Open Finance Integra</label><select name="fh_openfinance_enabled"><option value="0">Desligado</option><option value="1" ${s.fh_openfinance_enabled === '1' ? 'selected' : ''}>Ligado</option></select></div>
      <div class="field"><label>Pluggy Client ID</label><input name="fh_pluggy_client_id" value="${esc(s.fh_pluggy_client_id || '')}" class="mono" autocomplete="off"></div>
      <div class="field"><label>Pluggy Client Secret</label><input name="fh_pluggy_client_secret" value="${esc(s.fh_pluggy_client_secret || '')}" class="mono" autocomplete="off" type="password"></div>
      <div class="field" style="align-self:end"><button type="button" class="btn" data-of-test>${icon('bolt')} Testar credenciais</button></div>
    </div>
    <div class="fh-card-actions" style="justify-content:flex-end"><button class="btn btn-primary">${icon('check')} Salvar</button></div></form>
    <div class="alert alert-info" style="margin-top:16px">As renovações são geradas pelo <b>cron</b> (a cada hora, a partir das 6h) e cobradas pelo Asaas. O pagamento confirmado pelo webhook libera o acesso automaticamente. O cliente pode pagar pelo link da fatura ou em Área do Cliente → Fiscal Hub → Assinatura.</div>`;
  $('[data-form]', el).addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = Object.fromEntries([...e.target.querySelectorAll('[name]')].map((i) => [i.name, i.value]));
    try { await api('/settings', { method: 'PUT', body }); toast('Configurações salvas.'); } catch (err) { toastError(err); }
  });
  $('[data-of-test]', el).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.classList.add('loading');
    try { const r = await api('/fh-admin/openfinance/test', { method: 'POST' }); toast(`Credenciais OK: ${r.connectors} instituições disponíveis · ${r.connections} conexão(ões) de clientes.`); } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
}
