import { api, state, $, esc, icon, money, date, datetime, badge, options, label, dataTable, formModal, confirmDialog, modal, toast, toastError, lookups, today, addDays, copyText, can } from '../core.js';

const PAID = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];

async function chargeForm(prefill, onSaved) {
  const lk = await lookups();
  formModal({
    title: 'Emitir cobrança', size: 'lg',
    values: { billing_type: 'UNDEFINED', due_date: addDays(today(), 3), create_entry: true, ...Object.fromEntries(Object.entries(prefill).filter(([, v]) => v !== undefined && v !== '')) },
    intro: state.asaas?.configured
      ? `<div class="alert alert-info" style="margin:0">A cobrança será criada no Asaas (${state.asaas.environment === 'production' ? '<b>produção</b>' : 'sandbox'}) e o cliente receberá a fatura. Um lançamento "a receber" é gerado e baixado automaticamente quando o pagamento for confirmado.</div>`
      : '<div class="alert alert-warning" style="margin:0"><b>Asaas não configurado:</b> informe a chave da API em Configurações → Asaas para emitir cobranças.</div>',
    fields: [
      { name: 'customer_id', label: 'Cliente', type: 'select', required: true, options: lk.customers.map((c) => ({ value: c.id, label: c.name + (c.document ? '' : ' (sem CPF/CNPJ)') })) },
      { name: 'project_id', label: 'Projeto', type: 'select', options: lk.projects.map((p) => ({ value: p.id, label: p.name })) },
      { name: 'amount', label: 'Valor (R$)', type: 'money', required: true, help: 'Mínimo R$ 5,00.' },
      { name: 'due_date', label: 'Vencimento', type: 'date', required: true, min: today() },
      { name: 'billing_type', label: 'Forma de pagamento', type: 'select', empty: false, options: options('billing_type') },
      { name: 'category_id', label: 'Categoria da receita', type: 'select', options: lk.categories.filter((c) => c.entry_type === 'receivable').map((c) => ({ value: c.id, label: c.name })) },
      { name: 'description', label: 'Descrição (aparece para o cliente)', span: 2, required: true },
      { name: 'entry_id', type: 'hidden' },
      { name: 'contract_id', type: 'hidden' },
      !prefill.entry_id && { name: 'create_entry', label: 'Gerar conta a receber vinculada', type: 'checkbox', span: 2 },
    ],
    submitLabel: 'Emitir cobrança',
    onSubmit: async (d) => {
      const ch = await api('/charges', { method: 'POST', body: d });
      toast('Cobrança emitida!');
      onSaved && onSaved(ch);
    },
  });
}

async function chargeDetail(ch, table) {
  const paid = PAID.includes(ch.status);
  const open = !paid && !['DELETED', 'CANCELED', 'REFUNDED'].includes(ch.status);
  const m = modal({
    title: 'Cobrança #' + ch.id, size: 'lg',
    body: `
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">${badge('charge_status', ch.status)} <span class="muted small">Criada ${datetime(ch.created_at)}</span></div>
      <div class="grid g2">
        <dl class="kv" style="grid-template-columns:120px 1fr">
          <dt>Cliente</dt><dd><a href="#/customers/${ch.customer_id}">${esc(ch.customer_name)}</a></dd>
          <dt>Descrição</dt><dd>${esc(ch.description || '—')}</dd>
          <dt>Valor</dt><dd><b>${money(ch.amount)}</b>${ch.net_amount ? ` <span class="muted small">(líquido ${money(ch.net_amount)})</span>` : ''}</dd>
          <dt>Vencimento</dt><dd>${date(ch.due_date)}</dd>
          <dt>Forma</dt><dd>${esc(label('billing_type', ch.billing_type))}</dd>
          <dt>Pago em</dt><dd>${date(ch.paid_at)}</dd>
          <dt>ID Asaas</dt><dd class="mono">${esc(ch.asaas_payment_id || '—')}</dd>
          <dt>Lançamento</dt><dd>${ch.entry_id ? `<a href="#/finance/entries?type=receivable&status=">#${ch.entry_id}</a>` : '—'}</dd>
        </dl>
        <div class="pix-box" data-pix>${open && ch.billing_type !== 'BOLETO' && ch.billing_type !== 'CREDIT_CARD' ? `<button class="btn" data-load-pix>${icon('pix')} Gerar QR Code PIX</button>` : ''}</div>
      </div>`,
    footer: `
      ${open ? `<button class="btn btn-danger" data-cancel>${icon('x')} Cancelar cobrança</button>` : ''}
      <span style="flex:1"></span>
      ${!Number(ch.demo) ? `<button class="btn" data-refresh>${icon('refresh')} Atualizar status</button>` : ''}
      ${paid && can('finance') ? `<a class="btn" href="#/finance/nfse?new=1&customer_id=${ch.customer_id}&amount=${ch.amount}&charge_id=${ch.id}&description=${encodeURIComponent(ch.description || '')}">${icon('file')} Emitir NFS-e</a>` : ''}
      ${ch.invoice_url ? `<button class="btn" data-copy-link>${icon('copy')} Copiar link</button><a class="btn btn-primary" href="${esc(ch.invoice_url)}" target="_blank" rel="noopener">${icon('external')} Abrir fatura</a>` : ''}`,
  });
  const done = () => { m.close(); table && table.reload(); };
  $('[data-copy-link]', m.el)?.addEventListener('click', () => copyText(new URL(ch.invoice_url, location.origin).href));
  $('[data-load-pix]', m.el)?.addEventListener('click', async (e) => {
    e.target.closest('button').classList.add('loading');
    try {
      const pix = await api(`/charges/${ch.id}/pix`);
      $('[data-pix]', m.el).innerHTML = `${pix.encodedImage ? `<img src="data:image/png;base64,${pix.encodedImage}" alt="QR Code PIX">` : '<div class="alert alert-warning">O Asaas não retornou a imagem do QR Code. Use o código copia-e-cola abaixo.</div>'}
        <textarea class="input" readonly>${esc(pix.payload)}</textarea><button class="btn btn-sm" data-copy-pix style="margin-top:8px">${icon('copy')} Copiar PIX copia-e-cola</button>`;
      $('[data-copy-pix]', m.el).addEventListener('click', () => copyText(pix.payload));
    } catch (err) { toastError(err); e.target.closest('button')?.classList.remove('loading'); }
  });
  $('[data-refresh]', m.el)?.addEventListener('click', async () => { try { await api(`/charges/${ch.id}/refresh`, { method: 'POST' }); toast('Status atualizado.'); done(); } catch (e) { toastError(e); } });
  $('[data-cancel]', m.el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Cancelar esta cobrança? O cliente não poderá mais pagá-la.', { danger: true, okLabel: 'Cancelar cobrança' })) return;
    try { await api(`/charges/${ch.id}/cancel`, { method: 'POST' }); toast('Cobrança cancelada.'); done(); } catch (e) { toastError(e); }
  });
}

export async function render(el, ctx) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Cobranças</h2><p>Emissão de boletos, PIX e cartão via Asaas, com baixa automática por webhook.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Nova cobrança</button></div></div>
    ${!state.asaas?.configured ? `<div class="alert alert-warning">${icon('alert')} <b>Integração Asaas não configurada.</b> ${can('users') ? '<a href="#/settings" style="text-decoration:underline">Configure a chave da API</a> para emitir cobranças.' : 'Peça ao administrador para configurar a chave da API.'}</div>` : ''}
    <div class="grid g4" style="margin-bottom:16px" data-kpis></div>
    <div data-table></div>`;

  const table = dataTable($('[data-table]', el), {
    endpoint: '/charges',
    searchPlaceholder: 'Buscar cliente, descrição, ID Asaas...',
    filters: [
      { name: 'status', label: 'Todos os status', options: ['PENDING', 'RECEIVED', 'CONFIRMED', 'OVERDUE', 'REFUNDED', 'DELETED'].map((s) => ({ value: s, label: label('charge_status', s) })) },
      { name: 'billing_type', label: 'Forma', options: options('billing_type') },
    ],
    columns: [
      { label: 'Cliente', primary: true, render: (c) => `<b>${esc(c.customer_name)}</b><span class="sub">${esc(c.description || '')}</span>` },
      { label: 'Vencimento', sort: 'due_date', render: (c) => date(c.due_date) },
      { label: 'Valor', sort: 'amount', num: true, render: (c) => `<b>${money(c.amount)}</b>` },
      { label: 'Forma', render: (c) => esc(label('billing_type', c.billing_type)) },
      { label: 'Status', render: (c) => badge('charge_status', c.status) },
    ],
    onRowClick: (c) => chargeDetail(c, table),
    actions: (c) => [c.invoice_url && { label: 'Fatura', icon: 'external', iconOnly: true, onClick: () => window.open(c.invoice_url, '_blank') }],
    onLoad: (res) => {
      const rows = res.data;
      const sum = (f) => rows.filter(f).reduce((s, r) => s + Number(r.amount), 0);
      $('[data-kpis]', el).innerHTML = `
        <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('clock')}</span>Aguardando</div><div class="k-value">${money(sum((r) => r.status === 'PENDING'))}</div><div class="k-sub">nesta listagem</div></div>
        <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('check')}</span>Recebidas</div><div class="k-value pos">${money(sum((r) => PAID.includes(r.status)))}</div><div class="k-sub">nesta listagem</div></div>
        <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('alert')}</span>Vencidas</div><div class="k-value neg">${money(sum((r) => r.status === 'OVERDUE'))}</div><div class="k-sub">nesta listagem</div></div>
        <div class="card kpi" data-balance><div class="k-label"><span class="k-ico violet">${icon('bank')}</span>Saldo Asaas</div><div class="k-value">…</div></div>`;
      api('/bank/balance').then((b) => { const k = $('[data-balance] .k-value', el); if (k) k.textContent = b.balance == null ? 'Não configurado' : money(b.balance); }).catch(() => { const k = $('[data-balance] .k-value', el); if (k) k.textContent = '—'; });
    },
  });

  $('[data-new]', el).addEventListener('click', () => chargeForm({}, (ch) => { table.reload(); }));
  if (ctx.query.new) {
    const q = ctx.query;
    chargeForm({ customer_id: q.customer_id, project_id: q.project_id, entry_id: q.entry_id, contract_id: q.contract_id, amount: q.amount, due_date: q.due_date && q.due_date >= today() ? q.due_date : undefined, description: q.description }, () => table.reload());
    history.replaceState(null, '', '#/finance/charges');
  }
}
