import { api, state, $, $$, esc, icon, money, date, badge, dataTable, formModal, confirmDialog, modal, toast, toastError, lookups, today, addDays, downloadUrl } from '../core.js';

async function reconcileModal(tx, onDone) {
  const lk = await lookups();
  const { candidates } = await api(`/bank-transactions/${tx.id}/candidates`);
  const isIn = Number(tx.amount) >= 0;
  const cats = lk.categories.filter((c) => c.entry_type === (isIn ? 'receivable' : 'payable'));
  const m = modal({
    title: 'Conciliar transação', size: 'lg',
    body: `
      <div class="card card-pad" style="margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <span class="k-ico ${isIn ? 'green' : 'red'}" style="width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:${isIn ? 'var(--success-soft)' : 'var(--danger-soft)'};color:${isIn ? 'var(--success)' : 'var(--danger)'}">${icon(isIn ? 'trendUp' : 'trendDown')}</span>
        <div style="flex:1;min-width:200px"><b>${esc(tx.description || 'Transação')}</b><br><small class="muted">${date(tx.tx_date)} · ${esc(tx.tx_type || '')} · <span class="mono">${esc(tx.external_id || '')}</span></small></div>
        <b style="font-size:1.3rem" class="${isIn ? 'pos' : 'neg'}">${money(tx.amount)}</b>
      </div>
      <div class="tabs"><button class="tab active" data-t="match">Vincular a lançamento</button><button class="tab" data-t="create">Criar lançamento</button></div>
      <div data-pane="match">
        ${candidates.length ? `<ul class="list card">${candidates.map((c) => `
          <li><div class="grow"><b>${esc(c.entry.description)}</b><small>${esc(c.entry.customer_name || c.entry.supplier || '')} · venc. ${date(c.entry.due_date)} · ${money(c.entry.amount)} · ${c.entry.status === 'paid' ? 'já pago' : 'em aberto'}</small></div>
            ${c.score ? `<span class="badge ${c.score >= 90 ? 'green' : c.score >= 70 ? 'yellow' : ''}" title="${esc(c.reason)}">${c.score}% match</span>` : ''}
            <button class="btn btn-sm btn-primary" data-link="${c.entry.id}">${icon('link')} Vincular</button></li>`).join('')}</ul>`
          : '<div class="empty-box">Nenhum lançamento compatível encontrado. Crie um lançamento a partir desta transação.</div>'}
      </div>
      <div data-pane="create" class="hidden">
        <div class="form-grid">
          <div class="field span-2" data-field="description"><label>Descrição</label><input class="input" name="description" value="${esc(tx.description || '')}"></div>
          <div class="field span-2" data-field="category_id"><label>Categoria</label><select class="input" name="category_id"><option value="">Sem categoria</option>${cats.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
        </div>
        <p class="muted small">Será criado um lançamento ${isIn ? 'a receber' : 'a pagar'} já pago em ${date(tx.tx_date)}, conciliado com esta transação.</p>
        <button class="btn btn-primary" data-create>${icon('plus')} Criar e conciliar</button>
      </div>`,
    footer: `<button class="btn btn-ghost" data-ignore>Ignorar transação</button><span style="flex:1"></span><button class="btn" data-close>Fechar</button>`,
  });
  $$('.tab', m.el).forEach((t) => t.addEventListener('click', () => {
    $$('.tab', m.el).forEach((x) => x.classList.toggle('active', x === t));
    $$('[data-pane]', m.el).forEach((p) => p.classList.toggle('hidden', p.dataset.pane !== t.dataset.t));
  }));
  $$('[data-link]', m.el).forEach((b) => b.addEventListener('click', async () => {
    b.classList.add('loading');
    try { await api(`/bank-transactions/${tx.id}/link`, { method: 'POST', body: { entry_id: +b.dataset.link } }); toast('Transação conciliada.'); m.close(); onDone(); } catch (e) { toastError(e); b.classList.remove('loading'); }
  }));
  $('[data-create]', m.el).addEventListener('click', async (e) => {
    e.target.classList.add('loading');
    try {
      await api(`/bank-transactions/${tx.id}/create-entry`, { method: 'POST', body: { description: $('[name=description]', m.el).value, category_id: $('[name=category_id]', m.el).value } });
      toast('Lançamento criado e conciliado.'); m.close(); onDone();
    } catch (err) { toastError(err); e.target.classList.remove('loading'); }
  });
  $('[data-ignore]', m.el).addEventListener('click', async () => { await api(`/bank-transactions/${tx.id}/ignore`, { method: 'POST' }); toast('Transação ignorada.'); m.close(); onDone(); });
}

export async function render(el, ctx) {
  const tab = ctx.query.state ?? 'pending';
  const start = ctx.query.start || addDays(today(), -30);
  const end = ctx.query.end || today();

  el.innerHTML = `
    <div class="page-head"><div><h2>Extrato & conciliação</h2><p>Importe o extrato do Asaas e concilie com as contas a pagar e receber.</p></div>
      <div class="page-actions">
        <button class="btn" data-manual>${icon('plus')} Transação manual</button>
        <button class="btn" data-auto>${icon('bolt')} Conciliar automaticamente</button>
      </div></div>
    <div class="card card-pad" style="margin-bottom:16px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="flex:1 1 150px"><label for="imp-start">De</label><input id="imp-start" type="date" value="${start}"></div>
        <div class="field" style="flex:1 1 150px"><label for="imp-end">Até</label><input id="imp-end" type="date" value="${end}"></div>
        <button class="btn btn-primary" data-import style="flex:1 1 220px">${icon('download')} Importar extrato do Asaas</button>
        <a class="btn" data-export-csv style="flex:0 1 auto">${icon('upload')} Exportar CSV</a>
      </div>
      <p class="muted small" style="margin:10px 0 0">${state.asaas?.configured ? `Conectado ao Asaas (${state.asaas.environment === 'production' ? 'produção' : 'sandbox'}). Transações já importadas são ignoradas automaticamente.` : '<b>Integração Asaas não configurada.</b> Informe a chave da API em Configurações → Asaas para importar o extrato.'} Tarifas do Asaas são categorizadas automaticamente.</p>
    </div>
    <div class="grid g4" style="margin-bottom:16px" data-kpis></div>
    <div class="tabs">${[['pending', 'Pendentes'], ['reconciled', 'Conciliadas'], ['ignored', 'Ignoradas'], ['', 'Todas']].map(([k, l]) => `<a class="tab ${tab === k ? 'active' : ''}" href="#/finance/bank?state=${k}">${l}</a>`).join('')}</div>
    <div data-table></div>`;

  const refreshKpis = async () => {
    const [bal, pend, rec] = await Promise.all([
      api('/bank/balance').catch(() => ({ balance: null })),
      api('/bank-transactions', { query: { state: 'pending', per_page: 1 } }),
      api('/bank-transactions', { query: { state: 'reconciled', per_page: 1 } }),
    ]);
    $('[data-kpis]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('bank')}</span>Saldo Asaas</div><div class="k-value">${bal.balance == null ? '—' : money(bal.balance)}</div><div class="k-sub">${bal.configured === false ? 'Asaas não configurado' : 'saldo disponível'}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${pend.total ? 'red' : 'green'}">${icon('alert')}</span>Pendentes</div><div class="k-value">${pend.total}</div><div class="k-sub">aguardando conciliação</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('check')}</span>Conciliadas</div><div class="k-value">${rec.total}</div><div class="k-sub">transações</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('pie')}</span>Taxa de conciliação</div><div class="k-value">${pend.total + rec.total ? Math.round((rec.total / (pend.total + rec.total)) * 100) : 100}%</div><div class="k-sub">do extrato importado</div></div>`;
    window.dispatchEvent(new Event('ic:refresh-counts'));
  };
  refreshKpis();

  const table = dataTable($('[data-table]', el), {
    endpoint: '/bank-transactions',
    query: { state: tab },
    sort: 'tx_date', dir: 'desc',
    searchPlaceholder: 'Buscar descrição, tipo, ID...',
    filters: [{ name: 'from', type: 'date', label: 'De' }, { name: 'to', type: 'date', label: 'Até' }],
    emptyText: tab === 'pending' ? 'Tudo conciliado! Nenhuma transação pendente.' : 'Nenhuma transação.',
    emptyIcon: tab === 'pending' ? 'check' : 'search',
    columns: [
      { label: 'Data', sort: 'tx_date', render: (t) => date(t.tx_date) },
      { label: 'Descrição', primary: true, render: (t) => `<b>${esc(t.description || '—')}</b><span class="sub">${esc(t.tx_type || t.source)}</span>` },
      { label: 'Valor', sort: 'amount', num: true, render: (t) => `<b class="${Number(t.amount) >= 0 ? 'pos' : 'neg'}">${money(t.amount)}</b>` },
      { label: 'Saldo', num: true, render: (t) => (t.balance != null ? money(t.balance) : '—') },
      { label: 'Conciliação', render: (t) => (Number(t.reconciled) ? `<span class="badge green">${icon('check')} Conciliada</span><span class="sub">${esc(t.entry_description || '')}</span>` : Number(t.ignored) ? '<span class="badge">Ignorada</span>' : '<span class="badge yellow">Pendente</span>') },
    ],
    onRowClick: (t) => { if (!Number(t.reconciled) && !Number(t.ignored)) reconcileModal(t, () => { table.reload(); refreshKpis(); }); },
    actions: (t) => [
      !Number(t.reconciled) && !Number(t.ignored) && { label: 'Conciliar', icon: 'link', onClick: () => reconcileModal(t, () => { table.reload(); refreshKpis(); }) },
      Number(t.reconciled) && { label: 'Desfazer', icon: 'unlink', onClick: async () => { if (await confirmDialog('Desfazer a conciliação? O lançamento continuará marcado como pago.')) { await api(`/bank-transactions/${t.id}/unlink`, { method: 'POST' }); table.reload(); refreshKpis(); } } },
      Number(t.ignored) && { label: 'Reativar', icon: 'refresh', onClick: async () => { await api(`/bank-transactions/${t.id}/ignore`, { method: 'POST', body: { undo: true } }); table.reload(); refreshKpis(); } },
    ],
  });

  $('[data-import]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      const r = await api('/bank/import', { method: 'POST', body: { start: $('#imp-start', el).value, end: $('#imp-end', el).value } });
      toast(`${r.imported} transação(ões) importada(s), ${r.skipped} já existente(s), ${r.auto_reconciled} conciliada(s) automaticamente.`);
      table.reload(); refreshKpis();
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-auto]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try { const r = await api('/bank/auto-reconcile', { method: 'POST' }); toast(`${r.reconciled} transação(ões) conciliada(s).`, r.reconciled ? 'success' : 'info'); table.reload(); refreshKpis(); } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-export-csv]', el).addEventListener('click', (e) => { e.preventDefault(); location.href = downloadUrl('/bank-transactions/export.csv', { from: $('#imp-start', el).value, to: $('#imp-end', el).value }); });
  $('[data-manual]', el).addEventListener('click', () => formModal({
    title: 'Transação manual', size: 'sm',
    intro: '<p class="muted small" style="margin:0">Use para movimentações de outras contas ou ajustes. Valores negativos são saídas.</p>',
    values: { tx_date: today() },
    fields: [
      { name: 'tx_date', label: 'Data', type: 'date', required: true },
      { name: 'amount', label: 'Valor (use − para saída)', type: 'number', step: '0.01', required: true },
      { name: 'description', label: 'Descrição', required: true, span: 2 },
    ],
    onSubmit: async (d) => { await api('/bank-transactions', { method: 'POST', body: d }); toast('Transação registrada.'); table.reload(); refreshKpis(); },
  }));
}
