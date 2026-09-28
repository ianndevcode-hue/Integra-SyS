/* Contas a receber / a pagar: lista com filtros, baixa individual ou em lote, parcelas, recorrência e exportação. */
import { api, $, $$, esc, icon, money, date, dataTable, formModal, confirmDialog, toast, toastError, downloadUrl, today } from '/admin/js/core.js';
import { fin, KIND, entryForm, payModal, entryDetail, statusBadge, catOptions, accOptions, methodOptions, needEdit } from '/assets/fiscal/fin.js';

export async function render(el, ctx) {
  await fin.load(true);
  const kind = ctx.sub === 'payable' ? 'payable' : 'receivable';
  const K = KIND[kind];
  const status = ctx.query.status || 'pending';
  el.innerHTML = `
    <div class="page-head"><div><h2>${K.title}</h2><p>${kind === 'receivable' ? 'Tudo o que seus clientes devem: notas emitidas, vendas, parcelas e mensalidades.' : 'Fornecedores, impostos, aluguel, salários e demais compromissos da empresa.'}</p></div>
      <div class="page-actions">
        ${kind === 'receivable' ? `<button class="btn" data-invoices title="Criar contas a receber das NFS-e já emitidas">${icon('file')} Das notas emitidas</button>` : ''}
        <a class="btn" href="#/financeiro/recorrencias?kind=${kind}" title="Contas fixas que se criam sozinhas todo mês">${icon('refresh')} Recorrências automáticas</a>
        <button class="btn" data-csv>${icon('download')} Planilha</button>
        <button class="btn btn-primary" data-new>${icon('plus')} Nova ${K.one}</button>
      </div></div>
    <div class="fin-status" data-status>${[['pending', 'Em aberto'], ['overdue', 'Vencidas'], ['paid', kind === 'receivable' ? 'Recebidas' : 'Pagas'], ['canceled', 'Canceladas'], ['', 'Todas']].map(([v, l]) => `<button type="button" class="fin-chip ${v === status ? 'on' : ''}" data-s="${v}">${l}</button>`).join('')}</div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/fh/fin/entries', query: { kind }, perPage: 25, sort: status === 'paid' ? 'paid_at' : 'due_date', dir: status === 'paid' ? 'desc' : 'asc',
    searchPlaceholder: `Buscar por descrição, ${K.party.toLowerCase()}, documento...`,
    filters: [
      { name: 'status', label: 'Situação', options: [], value: status },
      { name: 'date_field', label: 'Período por', options: [{ value: 'due', label: 'Vencimento' }, { value: 'paid', label: 'Pagamento' }, { value: 'competence', label: 'Competência' }], value: ctx.query.date_field || '' },
      { name: 'from', type: 'date', label: 'De', value: ctx.query.from || '' },
      { name: 'to', type: 'date', label: 'Até', value: ctx.query.to || '' },
      { name: 'category_id', label: 'Todas as categorias', options: catOptions(kind), value: ctx.query.category_id || '' },
      { name: 'account_id', label: 'Todas as contas', options: accOptions(), value: ctx.query.account_id || '' },
    ],
    columns: [
      { label: 'Vencimento', sort: 'due_date', render: (r) => `<b>${date(r.due_date)}</b>${r.status === 'paid' ? `<br><small class="muted">${K.done.toLowerCase()} ${date(r.paid_at)}</small>` : ''}` },
      { label: 'Descrição', sort: 'description', primary: true, render: (r) => `<b>${esc(r.description)}</b>${r.party_name ? `<br><small class="muted">${esc(r.party_name)}</small>` : ''}${r.origin === 'invoice' ? ' <span class="badge blue" title="Gerado pela nota fiscal">NFS-e</span>' : ''}${r.origin === 'recurring' ? ` <span class="badge violet" title="Criado pela recorrência automática">${icon('refresh')} automática</span>` : ''}${r.transaction_id ? ` <span title="Conciliado com o extrato">${icon('link')}</span>` : ''}` },
      { label: 'Categoria', render: (r) => (r.category_name ? `<span class="fin-cat">${esc(r.category_name)}</span>` : '<span class="muted">—</span>') },
      { label: 'Conta', render: (r) => esc(r.account_name || '—') },
      { label: 'Valor', sort: 'amount', num: true, render: (r) => `<b>${money(r.amount)}</b>${r.status === 'paid' && Math.abs(r.paid_amount - r.amount) > 0.004 ? `<br><small class="muted">${K.done.toLowerCase()} ${money(r.paid_amount)}</small>` : ''}` },
      { label: 'Situação', render: (r) => statusBadge(r) },
    ],
    actions: (r) => [
      r.status === 'open' && { label: K.verb, icon: 'check', success: true, onClick: () => payModal(r, () => table.reload()) },
      { label: 'Editar', icon: 'edit', iconOnly: true, onClick: () => entryForm(r, { onSaved: () => table.reload() }) },
    ],
    onRowClick: (r) => entryDetail(r.id, () => table.reload()),
    bulk: [
      { label: `${K.verb} selecionadas`, icon: 'check', action: (ids) => bulkPay(ids, kind) },
      { label: 'Mudar categoria', icon: 'tag', action: (ids) => bulkCategory(ids, kind) },
      { label: 'Excluir', icon: 'trash', danger: true, action: async (ids) => { if (!needEdit() || !await confirmDialog(`Excluir ${ids.length} lançamento(s)?`, { danger: true, okLabel: 'Excluir' })) return; report(await api('/fh/fin/entries/bulk', { method: 'POST', body: { action: 'delete', ids } }), 'excluído(s)'); } },
    ],
    totals: (rows, res) => (res.sum ? `<div class="totals"><span>${res.total} lançamento(s)</span><span>Total: <b>${money(res.sum.amount)}</b></span><span>Em aberto: <b>${money(res.sum.open)}</b></span>${res.sum.overdue ? `<span class="neg">Vencido: <b>${money(res.sum.overdue)}</b></span>` : ''}<span>${K.done}: <b>${money(res.sum.paid)}</b></span></div>` : ''),
    emptyText: status === 'overdue' ? 'Nada vencido. 👏' : 'Nenhum lançamento neste filtro.', emptyIcon: 'wallet',
  });
  // the status filter is driven by the chips
  $('[data-filter=status]', el)?.classList.add('hidden');
  $('[data-status]', el).addEventListener('click', (e) => {
    const b = e.target.closest('[data-s]');
    if (!b) return;
    $$('[data-s]', el).forEach((x) => x.classList.toggle('on', x === b));
    table.state.sort = b.dataset.s === 'paid' ? 'paid_at' : 'due_date';
    table.state.dir = b.dataset.s === 'paid' ? 'desc' : 'asc';
    table.setFilter('status', b.dataset.s);
  });
  $('[data-new]', el).addEventListener('click', () => entryForm(null, { kind, onSaved: () => table.reload() }));
  $('[data-csv]', el).addEventListener('click', () => { location.href = downloadUrl('/fh/fin/entries.csv', { kind, ...table.state.filters, q: table.state.q }); });
  $('[data-invoices]', el)?.addEventListener('click', async (e) => {
    if (!needEdit()) return;
    const b = e.currentTarget;
    b.classList.add('loading');
    try {
      const r = await api('/fh/fin/invoices/import', { method: 'POST' });
      toast(r.created ? `${r.created} conta(s) a receber criada(s) a partir das notas emitidas.` : 'Todas as notas emitidas (últimos 12 meses) já estão no contas a receber.');
      table.reload();
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });

  function report(r, verb) {
    toast(`${r.ok} lançamento(s) ${verb}.${r.errors.length ? ' ' + r.errors.length + ' com erro.' : ''}`, r.errors.length ? 'error' : 'success');
    if (r.errors.length) console.warn(r.errors);
  }
  async function bulkPay(ids, kd) {
    if (!needEdit()) return;
    const accs = accOptions();
    await new Promise((resolve) => formModal({
      title: `${KIND[kd].verb} ${ids.length} lançamento(s)`, submitLabel: 'Confirmar baixa', values: { paid_at: today(), account_id: accs[0]?.value },
      intro: '<p class="small muted" style="margin:0">Cada lançamento é baixado pelo seu valor em aberto. Para juros, desconto ou pagamento parcial, baixe individualmente.</p>',
      fields: [{ name: 'paid_at', label: 'Data', type: 'date', required: true }, { name: 'account_id', label: 'Conta', type: 'select', empty: false, options: accs }, { name: 'payment_method', label: 'Forma', type: 'select', empty: '—', options: methodOptions() }],
      onSubmit: async (d) => { report(await api('/fh/fin/entries/bulk', { method: 'POST', body: { action: 'pay', ids, ...d } }), 'baixado(s)'); resolve(); },
    }));
  }
  async function bulkCategory(ids, kd) {
    if (!needEdit()) return;
    await new Promise((resolve) => formModal({
      title: `Categoria de ${ids.length} lançamento(s)`, fields: [{ name: 'category_id', label: 'Categoria', type: 'select', empty: 'Sem categoria', options: catOptions(kd) }],
      onSubmit: async (d) => { report(await api('/fh/fin/entries/bulk', { method: 'POST', body: { action: 'category', ids, ...d } }), 'atualizado(s)'); resolve(); },
    }));
  }
}
