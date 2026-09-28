import { api, $, $$, esc, icon, money, date, datetime, badge, options, dataTable, formModal, confirmDialog, toast, toastError, docFmt, lookups, can, emptyState, tagsHtml, activityPanel, filesPanel, label } from '../core.js';

const UFS = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ').map((u) => ({ value: u, label: u }));

export async function customerForm(values = {}, onSaved) {
  const lk = await lookups();
  const segs = Object.values(lk.segments).map((s) => ({ value: s, label: s }));
  formModal({
    title: values.id ? 'Editar cliente' : 'Novo cliente',
    size: 'lg',
    values,
    fields: [
      { name: 'name', label: 'Razão social / Nome', required: true, span: 2 },
      { name: 'trade_name', label: 'Nome fantasia' },
      { name: 'document', label: 'CPF/CNPJ', help: 'Obrigatório para emitir cobranças no Asaas.' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'phone', label: 'Telefone/WhatsApp' },
      { name: 'segment', label: 'Segmento', type: 'select', options: segs },
      { name: 'status', label: 'Situação', type: 'select', options: options('customer_status'), empty: false, default: 'active' },
      { name: 'owner', label: 'Responsável pela conta', type: 'select', options: lk.users.map((u) => ({ value: u.name, label: u.name })).concat(values.owner && !lk.users.some((u) => u.name === values.owner) ? [{ value: values.owner, label: values.owner }] : []) },
      { name: 'tags', label: 'Tags', type: 'tags' },
      { name: 'postal_code', label: 'CEP', help: 'Digite o CEP para preencher o endereço.' },
      { name: 'address_number', label: 'Número' },
      { name: 'address', label: 'Endereço (rua/avenida)', span: 2 },
      { name: 'district', label: 'Bairro' },
      { name: 'city', label: 'Cidade' },
      { name: 'state', label: 'UF', type: 'select', options: UFS },
      { name: 'city_ibge', label: 'Código IBGE da cidade', help: 'Preenchido pelo CEP. Usado na nota fiscal.' },
      { name: 'portal_enabled', label: 'Liberar acesso à Área do Cliente', type: 'checkbox' },
      { name: 'portal_password', label: values.id ? 'Nova senha do portal (deixe em branco para manter)' : 'Senha do portal', type: 'password', span: 2, autocomplete: 'new-password', help: 'Mínimo de 8 caracteres. O cliente entra com o e-mail acima.' },
      { name: 'notes', label: 'Observações', type: 'textarea', span: 2 },
    ],
    onReady: (form) => {
      const cep = form.elements.postal_code;
      cep.addEventListener('input', async () => {
        const d = cep.value.replace(/\D/g, '');
        if (d.length !== 8 || cep.dataset.last === d) return;
        cep.dataset.last = d;
        try {
          const r = await api('/cep/' + d);
          [['address', r.address], ['district', r.district], ['city', r.city], ['state', r.state], ['city_ibge', r.city_ibge]].forEach(([k, v]) => { if (v && form.elements[k]) form.elements[k].value = v; });
          cep.value = d.replace(/(\d{5})(\d{3})/, '$1-$2');
          form.elements.address_number.focus();
        } catch (e) { toast('CEP não encontrado. Preencha o endereço manualmente.', 'info'); }
      });
    },
    onSubmit: async (data) => {
      const saved = values.id ? await api('/customers/' + values.id, { method: 'PUT', body: data }) : await api('/customers', { method: 'POST', body: data });
      toast(values.id ? 'Cliente atualizado.' : 'Cliente cadastrado.');
      lookups(true);
      onSaved && onSaved(saved);
    },
  });
}

export async function render(el, ctx) {
  if (ctx.id) return renderDetail(el, ctx);

  const lk = await lookups();
  el.innerHTML = `
    <div class="page-head"><div><h2>Clientes</h2><p>Cadastro, histórico e acesso ao portal.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Novo cliente</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/customers',
    exportPath: '/customers/export.csv',
    searchPlaceholder: 'Buscar por nome, e-mail, CNPJ, cidade...',
    filters: [
      { name: 'status', label: 'Todas as situações', options: options('customer_status'), value: ctx.query.status },
      { name: 'owner', label: 'Responsável', options: lk.users.map((u) => ({ value: u.name, label: u.name })) },
      lk.tags.length ? { name: 'tag', label: 'Tag', options: lk.tags.map((t) => ({ value: t, label: '#' + t })), value: ctx.query.tag } : null,
    ].filter(Boolean),
    bulk: [
      { label: 'Mudar situação...', icon: 'refresh', action: (ids) => new Promise((resolve) => formModal({ title: 'Mudar situação', size: 'sm', fields: [{ name: 'status', label: 'Nova situação', type: 'select', options: options('customer_status'), empty: false, span: 2 }], onSubmit: async (d) => { await Promise.all(ids.map((id) => api('/customers/' + id, { method: 'PUT', body: { status: d.status } }))); toast(`${ids.length} cliente(s) atualizados.`); resolve(); } })) },
    ],
    columns: [
      { label: 'Cliente', sort: 'name', primary: true, render: (r) => `<b>${esc(r.name)}</b><span class="sub">${esc(r.trade_name || r.email || '')}</span>${r.tags ? `<span class="sub">${tagsHtml(r.tags)}</span>` : ''}` },
      { label: 'CPF/CNPJ', render: (r) => `<span class="mono">${esc(docFmt(r.document))}</span>` },
      { label: 'Cidade', sort: 'city', render: (r) => esc([r.city, r.state].filter(Boolean).join('/') || '—') },
      { label: 'Segmento', render: (r) => esc(r.segment || '—') },
      { label: 'Projetos', num: true, render: (r) => r.projects_count },
      { label: 'Chamados', num: true, render: (r) => (Number(r.tickets_open) ? `<span class="badge orange">${r.tickets_open}</span>` : '—') },
      { label: 'Em aberto', num: true, render: (r) => (Number(r.open_amount) ? money(r.open_amount) : '—') },
      { label: 'Status', sort: 'status', render: (r) => badge('customer_status', r.status) + (Number(r.portal_enabled) ? ' <span class="badge violet" title="Acesso ao portal liberado">Portal</span>' : '') },
    ],
    onRowClick: (r) => { location.hash = '#/customers/' + r.id; },
    actions: (r) => [{ label: 'Editar', icon: 'edit', iconOnly: true, onClick: () => customerForm(r, () => table.reload()) }],
  });
  $('[data-new]', el).addEventListener('click', () => customerForm({}, () => table.reload()));
  if (ctx.query.new) customerForm({}, () => table.reload());
}

async function renderDetail(el, ctx) {
  const c = await api('/customers/' + ctx.id);
  ctx.setTitle(c.name);
  const [projects, entries, charges, tickets, contracts, quotes] = await Promise.all([
    can('projects') ? api('/projects', { query: { customer_id: c.id, per_page: 50 } }) : { data: [] },
    can('finance') ? api('/entries', { query: { customer_id: c.id, per_page: 100, sort: 'due_date', dir: 'desc' } }) : { data: [] },
    can('charges') ? api('/charges', { query: { customer_id: c.id, per_page: 50 } }) : { data: [] },
    can('tickets') ? api('/tickets', { query: { customer_id: c.id, per_page: 50 } }) : { data: [] },
    api('/contracts', { query: { customer_id: c.id } }).catch(() => ({ data: [] })),
    api('/quotes', { query: { customer_id: c.id } }).catch(() => ({ data: [] })),
  ]);
  const mrr = contracts.data.filter((k) => k.status === 'active').reduce((s, k) => s + Number(k.monthly_amount), 0);
  const received = entries.data.filter((e) => e.entry_type === 'receivable' && e.status === 'paid').reduce((s, e) => s + Number(e.paid_amount), 0);
  const open = entries.data.filter((e) => e.entry_type === 'receivable' && e.status === 'open').reduce((s, e) => s + Number(e.amount), 0);

  el.innerHTML = `
    <div class="page-head">
      <div><a href="#/customers" class="muted small">← Clientes</a><h2 style="margin-top:4px">${esc(c.name)}</h2><p>${badge('customer_status', c.status)} ${c.segment ? '· ' + esc(c.segment) : ''} ${c.city ? '· ' + esc(c.city) + '/' + esc(c.state || '') : ''} ${c.owner ? '· ' + icon('user') + ' ' + esc(c.owner) : ''} ${tagsHtml(c.tags, { link: '#/customers?tag=' })}</p></div>
      <div class="page-actions">
        <select class="input" data-status aria-label="Situação do cliente" style="width:auto">${options('customer_status').map((o) => `<option value="${o.value}" ${o.value === c.status ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>
        ${can('tickets') ? `<a class="btn" href="#/tickets?view=&customer_id=${c.id}" data-new-ticket>${icon('ticket')} Novo chamado</a>` : ''}
        <a class="btn" href="#/pricing?customer_id=${c.id}">${icon('money')} Simular orçamento</a>
        <button class="btn" data-deck>${icon('sparkles')} Apresentação</button>
        ${can('charges') ? `<a class="btn btn-primary" href="#/finance/charges?new=1&customer_id=${c.id}">${icon('receipt')} Emitir cobrança</a>` : ''}
        ${c.email ? `<button class="btn" data-invite>${icon('send')} ${Number(c.portal_enabled) ? 'Reenviar acesso' : 'Convidar p/ Área do Cliente'}</button>` : ''}
        <button class="btn" data-edit>${icon('edit')} Editar</button>
        <button class="btn btn-danger" data-del>${icon('trash')}</button>
      </div>
    </div>
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label">Recebido (total)</div><div class="k-value pos">${money(received)}</div></div>
      <div class="card kpi"><div class="k-label">A receber</div><div class="k-value">${money(open)}</div></div>
      <div class="card kpi"><div class="k-label">Mensalidade (MRR)</div><div class="k-value">${money(mrr)}</div>${contracts.data.length ? `<div class="k-sub">${contracts.data.filter((k) => k.status === 'active').length} contrato(s) ativo(s)</div>` : '<div class="k-sub">sem contrato</div>'}</div>
      <div class="card kpi"><div class="k-label">Chamados</div><div class="k-value">${tickets.data.length}</div></div>
    </div>
    <div class="grid g3">
      <section class="card"><div class="card-head"><h3>Dados cadastrais</h3></div><div class="card-body">
        <dl class="kv">
          <dt>Nome fantasia</dt><dd>${esc(c.trade_name || '—')}</dd>
          <dt>CPF/CNPJ</dt><dd class="mono">${esc(docFmt(c.document))}</dd>
          <dt>E-mail</dt><dd>${c.email ? `<a href="mailto:${esc(c.email)}">${esc(c.email)}</a>` : '—'}</dd>
          <dt>Telefone</dt><dd>${c.phone ? `<a href="https://wa.me/55${esc(String(c.phone).replace(/\D/g, ''))}" target="_blank" rel="noopener">${esc(c.phone)}</a>` : '—'}</dd>
          <dt>Endereço</dt><dd>${esc([[c.address, c.address_number].filter(Boolean).join(', '), c.district, [c.city, c.state].filter(Boolean).join('/'), c.postal_code].filter(Boolean).join(' · ') || '—')}</dd>
          <dt>Área do cliente</dt><dd>${Number(c.portal_enabled) ? '<span class="badge green">Liberada</span>' : '<span class="badge">Bloqueada</span>'}</dd>
          <dt>Asaas</dt><dd>${c.asaas_customer_id ? `<span class="mono">${esc(c.asaas_customer_id)}</span>` : '<span class="muted">Não sincronizado</span>'} ${can('customers') ? `<button class="btn btn-xs" data-sync>${icon('refresh')} Sincronizar</button>` : ''}</dd>
          <dt>Cliente desde</dt><dd>${date(c.created_at)}</dd>
          <dt>Último acesso</dt><dd>${c.portal_last_login_at ? datetime(c.portal_last_login_at) : '<span class="muted">nunca entrou no portal</span>'}</dd>
        </dl>
        ${c.notes ? `<div class="alert" style="margin:14px 0 0;white-space:pre-wrap">${esc(c.notes)}</div>` : ''}
      </div></section>
      <section class="card span-2">
        <div class="card-head" style="padding:0 18px"><div class="tabs" style="margin:0;border:0">
          <button class="tab active" data-t="activity">Histórico</button><button class="tab" data-t="contracts">Contratos & orçamentos</button><button class="tab" data-t="projects">Projetos (${projects.data.length})</button><button class="tab" data-t="finance">Financeiro</button><button class="tab" data-t="charges">Cobranças</button><button class="tab" data-t="tickets">Chamados</button><button class="tab" data-t="files">Arquivos</button>
        </div></div>
        <div data-tab-body></div>
      </section>
    </div>`;

  const bodies = {
    projects: projects.data.length ? `<ul class="list">${projects.data.map((p) => `<li><div class="grow"><a href="#/projects/${p.id}"><b>${esc(p.name)}</b></a><small>${esc(p.current_stage || '—')} · prazo ${date(p.due_date)}</small></div><div style="width:120px"><div class="progress"><i style="width:${p.progress}%"></i></div><small class="muted">${p.progress}%</small></div>${badge('project_status', p.status)}</li>`).join('')}</ul>` : emptyState('Nenhum projeto.', 'kanban', can('projects') ? `<a class="btn btn-sm" href="#/projects?new=1&customer_id=${c.id}">${icon('plus')} Criar projeto</a>` : ''),
    finance: entries.data.length ? `<div class="table-wrap"><table class="dt cards"><thead><tr><th>Descrição</th><th>Vencimento</th><th class="num">Valor</th><th>Status</th></tr></thead><tbody>${entries.data.map((e) => `<tr><td class="primary" data-label="Descrição">${esc(e.description)}</td><td data-label="Vencimento">${date(e.due_date)}</td><td class="num" data-label="Valor"><span class="${e.entry_type === 'receivable' ? 'pos' : 'neg'}">${money(e.amount)}</span></td><td data-label="Status">${badge('entry_status', e.overdue ? 'overdue' : e.status)}</td></tr>`).join('')}</tbody></table></div>` : emptyState('Sem lançamentos financeiros.', 'wallet'),
    charges: charges.data.length ? `<ul class="list">${charges.data.map((ch) => `<li><div class="grow"><b>${esc(ch.description || 'Cobrança')}</b><small>Vence ${date(ch.due_date)} · ${esc(ch.billing_type)}</small></div><b class="nowrap">${money(ch.amount)}</b>${badge('charge_status', ch.status)}${ch.invoice_url ? `<a class="btn btn-xs" href="${esc(ch.invoice_url)}" target="_blank" rel="noopener">${icon('external')}</a>` : ''}</li>`).join('')}</ul>` : emptyState('Nenhuma cobrança emitida.', 'receipt'),
    contracts: `<div class="card-body" style="display:grid;gap:16px">
      <div><div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px"><b>Contratos</b><a class="btn btn-sm" href="#/pricing?customer_id=${c.id}">${icon('plus')} Novo orçamento</a></div>
      ${contracts.data.length ? `<ul class="list">${contracts.data.map((k) => `<li><div class="grow"><b class="mono">${esc(k.number)}</b> ${esc(k.title || '')}<small>${esc(k.support_plan || '')} · ${date(k.start_date)} → ${date(k.end_date)} · próximo faturamento ${date(k.next_billing_date)}</small></div><b class="nowrap">${money(k.monthly_amount)}/mês</b>${badge('contract_status', k.status)}</li>`).join('')}</ul>` : emptyState('Nenhum contrato ativo.', 'file')}</div>
      <div><b style="display:block;margin-bottom:8px">Orçamentos</b>
      ${quotes.data.length ? `<ul class="list">${quotes.data.map((q) => `<li><div class="grow"><a href="#/pricing/quotes/${q.id}"><b class="mono">${esc(q.number)}</b> ${esc(q.title)}</a><small>${date(q.created_at)} · válido até ${date(q.valid_until)}</small></div><span class="nowrap small">${money(q.setup_total)} + ${money(q.monthly_total)}/mês</span>${badge('quote_status', q.status)}</li>`).join('')}</ul>` : emptyState('Nenhum orçamento.', 'file')}</div></div>`,
    tickets: tickets.data.length ? `<ul class="list">${tickets.data.map((t) => `<li><div class="grow"><a href="#/tickets/${t.id}"><b>${esc(t.subject)}</b></a><small>${esc(t.protocol)} · ${datetime(t.created_at)}</small></div>${badge('ticket_status', t.status)}</li>`).join('')}</ul>` : emptyState('Nenhum chamado.', 'ticket'),
  };
  const tabBody = $('[data-tab-body]', el);
  const show = (t) => {
    el.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b.dataset.t === t));
    if (t === 'activity') { tabBody.innerHTML = '<div class="card-body" data-panel></div>'; activityPanel($('[data-panel]', tabBody), 'customer', c.id); return; }
    if (t === 'files') { tabBody.innerHTML = '<div class="card-body" data-panel></div>'; filesPanel($('[data-panel]', tabBody), 'customer', c.id, { clientToggle: true }); return; }
    tabBody.innerHTML = bodies[t];
  };
  el.querySelectorAll('.tab').forEach((b) => b.addEventListener('click', () => show(b.dataset.t)));
  show('activity');
  $('[data-status]', el).addEventListener('change', async (e) => {
    try { await api('/customers/' + c.id, { method: 'PUT', body: { status: e.target.value } }); toast('Situação: ' + label('customer_status', e.target.value)); renderDetail(el, ctx); } catch (err) { toastError(err); }
  });
  $('[data-deck]', el).addEventListener('click', async () => {
    const { modal: mdl } = await import('../core.js');
    const m = mdl({ title: 'Gerar apresentação para ' + (c.trade_name || c.name), size: 'sm', footer: null, body: `<div class="action-list">
      <button data-k="results">${icon('trendUp')}<div><b>Relatório de resultados</b><small>Entregas, indicadores de suporte e próximos passos do período.</small></div></button>
      <button data-k="qbr">${icon('pie')}<div><b>Revisão estratégica (QBR)</b><small>Resultados do trimestre, financeiro e oportunidades de evolução.</small></div></button>
      <button data-k="proposal">${icon('send')}<div><b>Nova proposta comercial</b><small>Para vender um novo projeto ou módulo a este cliente.</small></div></button>
    </div>` });
    m.el.querySelectorAll('[data-k]').forEach((b) => b.addEventListener('click', async () => { m.close(); const { wizard } = await import('./presentations.js'); wizard(b.dataset.k, null, { customer_id: c.id }); }));
  });
  $('[data-new-ticket]', el)?.addEventListener('click', async (e) => {
    e.preventDefault();
    const mod = await import('./tickets.js');
    mod.newTicket({ customer_id: c.id, name: c.trade_name || c.name, email: c.email || '', phone: c.phone || '' });
  });

  $('[data-edit]', el).addEventListener('click', () => customerForm(c, () => renderDetail(el, ctx)));
  $('[data-invite]', el)?.addEventListener('click', async (e) => {
    if (!await confirmDialog(`Enviar para ${c.email} um link para criar a senha da Área do Cliente?`, { okLabel: 'Enviar convite' })) return;
    const btn = e.target.closest('button');
    btn.classList.add('loading');
    try { const r = await api(`/customers/${c.id}/invite`, { method: 'POST' }); toast(r.message); renderDetail(el, ctx); } catch (err) { toastError(err); btn.classList.remove('loading'); }
  });
  $('[data-del]', el).addEventListener('click', async () => {
    if (!await confirmDialog(`Excluir o cliente "${c.name}"? Projetos e lançamentos serão mantidos sem vínculo.`, { danger: true, okLabel: 'Excluir' })) return;
    try { await api('/customers/' + c.id, { method: 'DELETE' }); toast('Cliente excluído.'); location.hash = '#/customers'; } catch (e) { toastError(e); }
  });
  $('[data-sync]', el)?.addEventListener('click', async (e) => {
    e.target.closest('button').classList.add('loading');
    try { const r = await api(`/customers/${c.id}/asaas-sync`, { method: 'POST' }); toast('Cliente sincronizado no Asaas: ' + r.asaas_customer_id); renderDetail(el, ctx); } catch (err) { toastError(err); e.target.closest('button').classList.remove('loading'); }
  });
}
