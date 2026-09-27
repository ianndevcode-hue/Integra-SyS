import { api, apiForm, state, $, $$, esc, icon, datetime, relative, badge, options, label, dataTable, formModal, confirmDialog, modal, toast, toastError, lookups, tagsHtml, fileSize, emptyState, can } from '../core.js';

const OPEN = ['open', 'in_progress', 'waiting', 'waiting_third', 'on_hold'];
const VIEWS = [
  ['open', 'Abertos'], ['mine', 'Meus'], ['unassigned', 'Sem responsável'], ['customer_replied', 'Cliente respondeu'],
  ['breached', 'SLA vencido'], ['waiting', 'Aguardando'], ['done', 'Resolvidos'], ['', 'Todos'],
];

export function newTicket(values = {}) { return ticketForm(values, (t) => { location.hash = '#/tickets/' + t.id; }); }

async function ticketForm(values, onSaved) {
  const lk = await lookups();
  formModal({
    title: values.id ? 'Editar chamado' : 'Novo chamado', values, size: 'lg',
    fields: [
      { name: 'subject', label: 'Assunto', required: true, span: 2 },
      { name: 'customer_id', label: 'Cliente', type: 'select', options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'project_id', label: 'Projeto', type: 'select', options: lk.projects.map((p) => ({ value: p.id, label: p.name })) },
      { name: 'name', label: 'Nome do solicitante', required: true },
      { name: 'email', label: 'E-mail do solicitante', type: 'email', required: true },
      { name: 'phone', label: 'Telefone' },
      { name: 'category', label: 'Categoria', type: 'select', options: options('ticket_category'), empty: false },
      { name: 'priority', label: 'Prioridade', type: 'select', options: options('priority'), empty: false, default: 'normal' },
      { name: 'status', label: 'Status', type: 'select', options: options('ticket_status'), empty: false, default: 'open' },
      { name: 'assigned_to', label: 'Responsável', type: 'select', options: lk.users.map((u) => ({ value: u.id, label: u.name })), empty: 'Sem responsável', default: values.id ? '' : state.user.id },
      { name: 'source', label: 'Canal de origem', type: 'select', options: options('ticket_source'), empty: false, default: 'admin' },
      { name: 'tags', label: 'Tags', type: 'tags', span: 2 },
      !values.id && { name: 'first_message', label: 'Descrição do problema (primeira mensagem do cliente)', type: 'textarea', span: 2 },
    ],
    onReady: (form) => {
      form.elements.customer_id.addEventListener('change', async () => {
        const c = lk.customers.find((x) => String(x.id) === form.elements.customer_id.value);
        if (!c) return;
        try {
          const full = await api('/customers/' + c.id);
          if (!form.elements.name.value) form.elements.name.value = full.trade_name || full.name;
          if (!form.elements.email.value && full.email) form.elements.email.value = full.email;
          if (!form.elements.phone.value && full.phone) form.elements.phone.value = full.phone;
        } catch (e) { /* ignore */ }
      });
    },
    onSubmit: async (d) => {
      const saved = values.id ? await api('/tickets/' + values.id, { method: 'PUT', body: d }) : await api('/tickets', { method: 'POST', body: d });
      toast('Chamado salvo.');
      lookups(true);
      onSaved(saved);
    },
  });
}

const slaCell = (t) => {
  if (!OPEN.includes(t.status) || !t.sla_due_at) return '<span class="muted">—</span>';
  if (t.sla_paused_at) return `<span class="muted" title="O prazo fica congelado enquanto aguarda o cliente ou terceiros">⏸ pausado</span>`;
  const breached = new Date(t.sla_due_at.replace(' ', 'T')) < new Date();
  return `<span class="${breached ? 'neg' : ''}">${breached ? '⚠ vencido ' : ''}${relative(t.sla_due_at)}</span>`;
};

export async function render(el, ctx) {
  if (ctx.id) return renderDetail(el, ctx);
  const lk = await lookups();
  let view = ctx.query.view ?? (localStorage.getItem('ic-tickets-view') || 'open');
  let mode = localStorage.getItem('ic-tickets-mode') || 'list';

  el.innerHTML = `
    <div class="page-head"><div><h2>Chamados</h2><p>Central de suporte com SLA, responsáveis e avaliação dos clientes.</p></div>
      <div class="page-actions">
        <div class="seg"><button data-mode="list">${icon('list')} Lista</button><button data-mode="kanban">${icon('columns')} Quadro</button></div>
        <button class="btn" data-canned>${icon('message')} Respostas prontas</button>
        <button class="btn btn-primary" data-new>${icon('plus')} Novo chamado</button>
      </div></div>
    <div class="grid g4 kpi-strip" data-kpis></div>
    <div class="view-tabs" role="tablist" data-views></div>
    <div data-body></div>`;
  const body = $('[data-body]', el);
  let table = null;

  async function drawStats() {
    try {
      const st = await api('/tickets-stats');
      $('[data-views]', el).innerHTML = VIEWS.map(([k, t]) => `<button role="tab" data-view="${k}" class="${k === view ? 'active' : ''} ${k === 'breached' && st.breached ? 'alert' : ''}">${esc(t)}${st[k] !== undefined && k !== '' ? `<span>${st[k]}</span>` : ''}</button>`).join('');
      $$('[data-view]', el).forEach((b) => b.addEventListener('click', () => { view = b.dataset.view; localStorage.setItem('ic-tickets-view', view); draw(); }));
      $('[data-kpis]', el).innerHTML = `
        <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('ticket')}</span>Em aberto</div><div class="k-value">${st.open}</div><div class="k-sub">${st.unassigned} sem responsável</div></div>
        <div class="card kpi"><div class="k-label"><span class="k-ico ${st.breached ? 'red' : 'green'}">${icon('clock')}</span>SLA vencido</div><div class="k-value ${st.breached ? 'neg' : ''}">${st.breached}</div><div class="k-sub">${st.customer_replied} aguardando nossa resposta</div></div>
        <div class="card kpi"><div class="k-label"><span class="k-ico violet">${icon('message')}</span>1ª resposta (30 dias)</div><div class="k-value">${st.first_response_avg_h ? String(st.first_response_avg_h).replace('.', ',') + ' h' : '—'}</div><div class="k-sub">tempo médio</div></div>
        <div class="card kpi"><div class="k-label"><span class="k-ico yellow">${icon('star')}</span>Satisfação (CSAT)</div><div class="k-value">${st.csat_count ? String(st.csat_avg).replace('.', ',') + ' / 5' : '—'}</div><div class="k-sub">${st.csat_count} avaliação(ões)</div></div>`;
    } catch (e) { /* ignore */ }
  }

  function renderList() {
    table = dataTable(body, {
      endpoint: '/tickets',
      query: { view },
      searchPlaceholder: 'Protocolo, assunto, cliente, e-mail, tag...',
      filters: [
        { name: 'priority', label: 'Prioridade', options: options('priority') },
        { name: 'category', label: 'Categoria', options: options('ticket_category') },
        { name: 'assigned_to', label: 'Responsável', options: lk.users.map((u) => ({ value: u.id, label: u.name })) },
        { name: 'status', label: 'Status', options: options('ticket_status'), value: ctx.query.status },
        lk.tags.length ? { name: 'tag', label: 'Tag', options: lk.tags.map((t) => ({ value: t, label: '#' + t })), value: ctx.query.tag } : null,
      ].filter(Boolean),
      columns: [
        { label: 'Chamado', primary: true, render: (t) => `<b>${t.last_author === 'customer' && OPEN.includes(t.status) ? '<span class="dot-new" title="Cliente aguardando resposta"></span>' : ''}${esc(t.subject)}</b><span class="sub mono">${esc(t.protocol)} · ${t.messages_count} msg${Number(t.attachments_count) ? ` · ${icon('paperclip')}${t.attachments_count}` : ''}</span>${t.tags ? `<span class="sub">${tagsHtml(t.tags)}</span>` : ''}` },
        { label: 'Solicitante', render: (t) => `${esc(t.customer_name || t.name)}<span class="sub">${esc(t.project_name || t.email)}</span>` },
        { label: 'Responsável', render: (t) => (t.assignee_name ? esc(t.assignee_name) : '<span class="muted">—</span>') },
        { label: 'Prioridade', sort: 'priority', render: (t) => badge('priority', t.priority) },
        { label: 'SLA', sort: 'sla_due_at', render: slaCell },
        { label: 'Atualizado', sort: 'updated_at', render: (t) => `<span title="${datetime(t.updated_at)}">${relative(t.updated_at)}</span>` },
        { label: 'Status', render: (t) => badge('ticket_status', t.status) + (t.satisfaction ? ` <span class="badge yellow" title="Avaliação do cliente">${'★'.repeat(t.satisfaction)}</span>` : '') },
      ],
      bulk: [
        { label: 'Assumir', icon: 'user', action: (ids) => bulk(ids, 'assign', state.user.id) },
        { label: 'Atribuir...', icon: 'users', action: (ids) => pickAndBulk(ids, 'assign', 'Atribuir a', lk.users.map((u) => ({ value: u.id, label: u.name })), true) },
        { label: 'Status...', icon: 'refresh', action: (ids) => pickAndBulk(ids, 'status', 'Mudar status para', options('ticket_status')) },
        { label: 'Prioridade...', icon: 'alert', action: (ids) => pickAndBulk(ids, 'priority', 'Mudar prioridade para', options('priority')) },
        { label: 'Tag...', icon: 'tag', action: (ids) => tagBulk(ids) },
        can('users') ? { label: 'Excluir', icon: 'trash', danger: true, action: async (ids) => { if (await confirmDialog(`Excluir ${ids.length} chamado(s) com toda a conversa e anexos?`, { danger: true })) await bulk(ids, 'delete'); } } : null,
      ].filter(Boolean),
      onRowClick: (t) => { location.hash = '#/tickets/' + t.id; },
      onLoad: () => drawStats(),
    });
  }

  async function renderKanban() {
    const res = await api('/tickets', { query: { view: view === 'done' ? 'done' : view || 'open', per_page: 300, sort: 'sla_due_at', dir: 'asc' } });
    const cols = view === 'done' ? ['resolved', 'closed'] : [...OPEN, 'resolved'];
    body.innerHTML = `<div class="kanban">${cols.map((st) => {
      const items = res.data.filter((t) => t.status === st);
      return `<div class="k-col" data-col="${st}"><div class="k-col-head">${esc(label('ticket_status', st))}<span class="badge">${items.length}</span></div>
        <div class="k-col-body">${items.map((t) => `
          <article class="k-card ${t.sla_breached ? 'breach' : ''}" draggable="true" data-id="${t.id}" tabindex="0">
            <div style="display:flex;justify-content:space-between;gap:6px;align-items:flex-start"><b>${t.last_author === 'customer' && OPEN.includes(t.status) ? '<span class="dot-new"></span>' : ''}${esc(t.subject)}</b>${t.priority !== 'normal' ? badge('priority', t.priority) : ''}</div>
            <small class="muted">${esc(t.customer_name || t.name)} · <span class="mono">${esc(t.protocol)}</span></small>
            <div class="meta"><span>${t.assignee_name ? icon('user') + ' ' + esc(t.assignee_name.split(' ')[0]) : '<span class="neg">sem responsável</span>'}</span><span>${slaCell(t)}</span></div>
          </article>`).join('') || '<div class="muted small" style="text-align:center;padding:16px">Arraste chamados para cá</div>'}</div></div>`;
    }).join('')}</div><p class="muted small">Arraste um cartão para mudar o status. O prazo de SLA pausa automaticamente em "Aguardando".</p>`;
    let dragId = null;
    $$('.k-card', body).forEach((card) => {
      card.addEventListener('dragstart', (e) => { dragId = card.dataset.id; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
      card.addEventListener('dragend', () => card.classList.remove('dragging'));
      card.addEventListener('click', () => { location.hash = '#/tickets/' + card.dataset.id; });
      card.addEventListener('keydown', (e) => { if (e.key === 'Enter') location.hash = '#/tickets/' + card.dataset.id; });
    });
    $$('.k-col', body).forEach((col) => {
      const zone = $('.k-col-body', col);
      col.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
      col.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault(); zone.classList.remove('drag-over');
        if (!dragId) return;
        try { await api('/tickets/bulk', { method: 'POST', body: { ids: [dragId], action: 'status', value: col.dataset.col } }); toast('Status atualizado.'); renderKanban(); drawStats(); window.dispatchEvent(new Event('ic:refresh-counts')); } catch (err) { toastError(err); }
      });
    });
  }

  async function bulk(ids, action, value) {
    try {
      const r = await api('/tickets/bulk', { method: 'POST', body: { ids, action, value } });
      toast(`${r.count} chamado(s) atualizado(s).`);
      window.dispatchEvent(new Event('ic:refresh-counts'));
    } catch (err) { toastError(err); }
  }
  function pickAndBulk(ids, action, title, opts, allowEmpty = false) {
    return new Promise((resolve) => formModal({
      title, size: 'sm', fields: [{ name: 'value', label: title, type: 'select', options: opts, empty: allowEmpty ? 'Ninguém (remover)' : false, span: 2 }],
      onSubmit: async (d) => { await bulk(ids, action, d.value); resolve(); },
    }));
  }
  function tagBulk(ids) {
    return new Promise((resolve) => formModal({ title: 'Adicionar tag', size: 'sm', fields: [{ name: 'value', label: 'Tag(s)', type: 'tags', span: 2, required: true }], onSubmit: async (d) => { await bulk(ids, 'tag', d.value); lookups(true); resolve(); } }));
  }

  function draw() {
    $$('[data-mode]', el).forEach((b) => b.classList.toggle('active', b.dataset.mode === mode));
    $$('[data-view]', el).forEach((b) => b.classList.toggle('active', b.dataset.view === view));
    body.innerHTML = '<div class="loading-box">Carregando...</div>';
    if (mode === 'kanban') { renderKanban().catch(toastError); drawStats(); } else renderList();
  }
  $$('[data-mode]', el).forEach((b) => b.addEventListener('click', () => { mode = b.dataset.mode; localStorage.setItem('ic-tickets-mode', mode); draw(); }));
  $('[data-new]', el).addEventListener('click', () => ticketForm({}, (t) => { location.hash = '#/tickets/' + t.id; }));
  $('[data-canned]', el).addEventListener('click', () => cannedManager());
  draw();
}

/* ---------------------------------------------------------------- canned responses */
async function cannedList() {
  return (await api('/canned-responses', { query: { per_page: 200, sort: 'uses', dir: 'desc' } })).data;
}

export async function cannedManager(onChange) {
  const m = modal({ title: 'Respostas prontas', size: 'lg', body: '<div class="loading-box">Carregando...</div>', footer: `<span class="muted small" style="flex:1">Variáveis: {nome} {protocolo} {assunto} {atendente}</span><button class="btn btn-primary" data-add>${icon('plus')} Nova resposta</button>` });
  const draw = async () => {
    const rows = await cannedList();
    m.body.innerHTML = rows.length ? `<ul class="list">${rows.map((r) => `<li data-id="${r.id}"><div class="grow"><b>${esc(r.title)}</b><small style="white-space:normal">${esc(r.body.slice(0, 160))}${r.body.length > 160 ? '…' : ''}</small></div><span class="muted small nowrap">${r.uses} uso(s)</span><button class="btn btn-xs" data-edit>${icon('edit')}</button><button class="btn btn-xs btn-ghost" data-del>${icon('trash')}</button></li>`).join('')}</ul>`
      : emptyState('Crie respostas prontas para as dúvidas mais comuns e responda em segundos.', 'message');
    $$('[data-edit]', m.body).forEach((b) => b.addEventListener('click', () => edit(rows.find((r) => String(r.id) === b.closest('li').dataset.id))));
    $$('[data-del]', m.body).forEach((b) => b.addEventListener('click', async () => { if (!await confirmDialog('Excluir esta resposta pronta?', { danger: true })) return; await api('/canned-responses/' + b.closest('li').dataset.id, { method: 'DELETE' }); draw(); onChange && onChange(); }));
  };
  const edit = (values = {}) => formModal({
    title: values.id ? 'Editar resposta pronta' : 'Nova resposta pronta', values,
    fields: [
      { name: 'title', label: 'Título (atalho)', required: true, span: 2, placeholder: 'Ex.: Redefinir senha' },
      { name: 'category', label: 'Categoria', type: 'select', options: options('ticket_category') },
      { name: 'body', label: 'Texto', type: 'textarea', rows: 8, required: true, span: 2, help: 'Use {nome}, {protocolo}, {assunto} e {atendente} para personalizar.' },
    ],
    onSubmit: async (d) => { values.id ? await api('/canned-responses/' + values.id, { method: 'PUT', body: d }) : await api('/canned-responses', { method: 'POST', body: d }); toast('Resposta salva.'); draw(); onChange && onChange(); },
  });
  $('[data-add]', m.el).addEventListener('click', () => edit());
  draw();
}

/* ---------------------------------------------------------------- detail */
async function renderDetail(el, ctx) {
  const [t, msgs, lk, canned] = await Promise.all([api('/tickets/' + ctx.id), api(`/tickets/${ctx.id}/messages`), lookups(), cannedList().catch(() => [])]);
  ctx.setTitle(`Chamado ${t.protocol}`);
  const isOpen = t.status !== 'closed';
  const wa = t.phone ? `https://wa.me/55${String(t.phone).replace(/\D/g, '').replace(/^55/, '')}` : '';
  const fileChips = (files) => (files?.length ? `<div class="msg-files">${files.map((f) => `<a href="/api/attachments/${f.id}/download" target="_blank" rel="noopener">${icon(/\.(png|jpe?g|gif|webp)$/i.test(f.file_name) ? 'image' : 'paperclip')}${esc(f.file_name)} <small>${fileSize(f.size_bytes)}</small></a>`).join('')}</div>` : '');
  const vars = (text) => text.replace(/\{nome\}/g, (t.name || '').split(' ')[0]).replace(/\{protocolo\}/g, t.protocol).replace(/\{assunto\}/g, t.subject).replace(/\{atendente\}/g, state.user.name.split(' ')[0]);

  el.innerHTML = `
    <div class="page-head">
      <div><a href="#/tickets" class="muted small">← Chamados</a><h2 style="margin-top:4px">${esc(t.subject)}</h2>
        <p><span class="mono">${esc(t.protocol)}</span> · ${badge('ticket_status', t.status)} ${badge('priority', t.priority)} ${badge('ticket_category', t.category)} ${badge('ticket_source', t.source)} ${tagsHtml(t.tags, { link: '#/tickets?view=&tag=' })}</p></div>
      <div class="page-actions">
        ${Number(t.assigned_to) !== Number(state.user.id) && isOpen ? `<button class="btn" data-take>${icon('user')} Assumir</button>` : ''}
        ${t.status !== 'resolved' && isOpen ? `<button class="btn btn-success" data-resolve>${icon('check')} Resolver</button>` : ''}
        ${!isOpen || t.status === 'resolved' ? `<button class="btn" data-reopen>${icon('undo')} Reabrir</button>` : ''}
        <button class="btn" data-edit>${icon('edit')} Editar</button>
        ${can('users') ? `<button class="btn btn-danger" data-del aria-label="Excluir">${icon('trash')}</button>` : ''}
      </div>
    </div>
    <div class="ticket-layout">
      <section class="card ticket-main"><div class="card-head"><h3>Conversa</h3><span class="muted small">${msgs.data.filter((m) => m.kind === 'message').length} mensagem(ns)</span></div>
        <div class="card-body">
          <div class="thread">${msgs.data.map((m) => m.kind === 'event'
            ? `<div class="evt">${icon('bolt')} ${esc(m.body)} <span>· ${esc(m.author_name || '')} · ${datetime(m.created_at)}</span></div>`
            : `<div class="msg ${m.author_type} ${Number(m.internal) ? 'internal' : ''}"><header><b>${Number(m.internal) ? icon('lock') + ' Nota interna · ' : m.author_type === 'staff' ? '🛠 ' : ''}${esc(m.author_name || '')}</b><span>${datetime(m.created_at)}</span></header><p>${esc(m.body)}</p>${fileChips(m.attachments)}</div>`).join('') || '<p class="muted">Sem mensagens.</p>'}
            ${msgs.loose_attachments?.length ? `<div class="evt">${icon('paperclip')} Anexos avulsos</div>${fileChips(msgs.loose_attachments)}` : ''}</div>
          ${isOpen ? `
          <form data-reply class="composer">
            <div class="composer-tabs" role="tablist"><button type="button" class="active" data-mode="reply">${icon('send')} Responder ao cliente</button><button type="button" data-mode="note">${icon('lock')} Nota interna</button></div>
            <textarea id="reply" name="body" rows="5" placeholder="Escreva a resposta. O cliente recebe por e-mail e vê na Área do Cliente."></textarea>
            <div class="composer-tools">
              ${canned.length ? `<select class="input" data-canned-pick aria-label="Inserir resposta pronta"><option value="">${esc('Inserir resposta pronta...')}</option>${canned.map((c) => `<option value="${c.id}">${esc(c.title)}</option>`).join('')}</select>` : ''}
              <button type="button" class="btn btn-sm btn-ghost" data-canned-manage title="Gerenciar respostas prontas">${icon('message')}</button>
              <label class="btn btn-sm btn-ghost" title="Anexar arquivos">${icon('paperclip')}<input type="file" name="files" multiple hidden data-files><span data-file-count></span></label>
              ${state.ai?.admin ? `<button type="button" class="btn btn-sm btn-ghost" data-ai-reply title="Sugerir resposta com IA">${icon('sparkles')} IA</button>` : ''}
              <span style="flex:1"></span>
              <label class="small muted" for="after">Depois:</label>
              <select id="after" name="status" class="input" style="width:auto">
                <option value="waiting">Aguardar cliente</option><option value="in_progress">Manter em atendimento</option><option value="waiting_third">Aguardar terceiros</option><option value="resolved">Marcar como resolvido</option><option value="keep">Não mudar status</option>
              </select>
              <button class="btn btn-primary" data-send>${icon('send')} Enviar</button>
            </div>
            <small class="muted">Ctrl + Enter envia. Notas internas nunca aparecem para o cliente.</small>
          </form>` : '<div class="alert" style="margin-top:14px">Chamado encerrado. Use "Reabrir" para continuar o atendimento.</div>'}
        </div>
      </section>
      <aside class="ticket-side">
        <section class="card"><div class="card-head"><h3>Atendimento</h3></div><div class="card-body side-form">
          <label>Status<select class="input" data-set="status">${options('ticket_status').map((o) => `<option value="${o.value}" ${o.value === t.status ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></label>
          <label>Responsável<select class="input" data-set="assigned_to"><option value="">Sem responsável</option>${lk.users.map((u) => `<option value="${u.id}" ${Number(u.id) === Number(t.assigned_to) ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
          <label>Prioridade<select class="input" data-set="priority">${options('priority').map((o) => `<option value="${o.value}" ${o.value === t.priority ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></label>
          <label>Categoria<select class="input" data-set="category">${options('ticket_category').map((o) => `<option value="${o.value}" ${o.value === t.category ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select></label>
          <label>Projeto<select class="input" data-set="project_id"><option value="">—</option>${lk.projects.filter((p) => !t.customer_id || !p.customer_id || Number(p.customer_id) === Number(t.customer_id) || Number(p.id) === Number(t.project_id)).map((p) => `<option value="${p.id}" ${Number(p.id) === Number(t.project_id) ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
          <label>Tags<input class="input" data-set="tags" value="${esc(t.tags || '')}" list="tk-tags" placeholder="ex.: bug, financeiro"><datalist id="tk-tags">${lk.tags.map((x) => `<option value="${esc(x)}">`).join('')}</datalist></label>
        </div></section>
        <section class="card"><div class="card-head"><h3>Solicitante</h3></div><div class="card-body">
          <dl class="kv" style="grid-template-columns:96px 1fr">
            <dt>Nome</dt><dd>${esc(t.name)}</dd>
            <dt>E-mail</dt><dd><a href="mailto:${esc(t.email)}?subject=${encodeURIComponent('[' + t.protocol + '] ' + t.subject)}">${esc(t.email)}</a></dd>
            <dt>Telefone</dt><dd>${t.phone ? `${esc(t.phone)} ${wa ? `<a class="btn btn-xs" href="${wa}" target="_blank" rel="noopener">WhatsApp</a>` : ''}` : '—'}</dd>
            <dt>Cliente</dt><dd>${t.customer_id ? `<a href="#/customers/${t.customer_id}">${esc(t.customer_name)}</a>` : `<select class="input" data-set="customer_id" style="padding:4px 8px"><option value="">Vincular cliente...</option>${lk.customers.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>`}</dd>
          </dl>
        </div></section>
        <section class="card"><div class="card-head"><h3>Prazos e qualidade</h3></div><div class="card-body">
          <dl class="kv" style="grid-template-columns:110px 1fr">
            <dt>Aberto</dt><dd>${datetime(t.created_at)}</dd>
            <dt>SLA</dt><dd>${slaCell(t)}<br><small class="muted">${datetime(t.sla_due_at)}</small></dd>
            <dt>1ª resposta</dt><dd>${t.first_response_at ? datetime(t.first_response_at) : '<span class="muted">pendente</span>'}</dd>
            <dt>Resolvido</dt><dd>${t.resolved_at ? datetime(t.resolved_at) : '—'}</dd>
            <dt>Avaliação</dt><dd>${t.satisfaction ? `<span style="color:#f5b301">${'★'.repeat(t.satisfaction)}</span><span class="muted">${'★'.repeat(5 - t.satisfaction)}</span>${t.satisfaction_comment ? `<br><small>“${esc(t.satisfaction_comment)}”</small>` : ''}` : '<span class="muted">—</span>'}</dd>
          </dl>
        </div></section>
      </aside>
    </div>`;

  const reload = () => { renderDetail(el, ctx); window.dispatchEvent(new Event('ic:refresh-counts')); };
  const update = async (body, msg = 'Chamado atualizado.') => { try { await api('/tickets/' + t.id, { method: 'PUT', body }); toast(msg); reload(); } catch (err) { toastError(err); } };
  $('[data-edit]', el).addEventListener('click', () => ticketForm(t, reload));
  $('[data-take]', el)?.addEventListener('click', () => update({ assigned_to: state.user.id, ...(t.status === 'open' ? { status: 'in_progress' } : {}) }, 'Chamado assumido.'));
  $('[data-resolve]', el)?.addEventListener('click', () => update({ status: 'resolved' }, 'Chamado resolvido. O cliente recebe um pedido de avaliação.'));
  $('[data-reopen]', el)?.addEventListener('click', () => update({ status: 'in_progress' }, 'Chamado reaberto.'));
  $('[data-del]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Excluir este chamado, a conversa e os anexos?', { danger: true })) return;
    await api('/tickets/' + t.id, { method: 'DELETE' }); location.hash = '#/tickets';
  });
  $$('[data-set]', el).forEach((inp) => inp.addEventListener('change', () => {
    const k = inp.dataset.set;
    update({ [k]: inp.value });
    if (k === 'tags') lookups(true);
  }));

  const form = $('[data-reply]', el);
  if (!form) return;
  let noteMode = false;
  const ta = $('#reply', el);
  $$('.composer-tabs button', form).forEach((b) => b.addEventListener('click', () => {
    noteMode = b.dataset.mode === 'note';
    $$('.composer-tabs button', form).forEach((x) => x.classList.toggle('active', x === b));
    form.classList.toggle('note', noteMode);
    ta.placeholder = noteMode ? 'Anotação visível apenas para a equipe (não envia e-mail ao cliente).' : 'Escreva a resposta. O cliente recebe por e-mail e vê na Área do Cliente.';
    form.status.value = noteMode ? 'keep' : 'waiting';
    ta.focus();
  }));
  $('[data-canned-pick]', form)?.addEventListener('change', (e) => {
    const c = canned.find((x) => String(x.id) === e.target.value);
    if (!c) return;
    ta.value = (ta.value.trim() ? ta.value.trim() + '\n\n' : '') + vars(c.body);
    e.target.value = '';
    api(`/canned-responses/${c.id}/use`, { method: 'POST' }).catch(() => {});
    ta.focus();
  });
  $('[data-canned-manage]', form).addEventListener('click', () => cannedManager(() => {}));
  const filesInput = $('[data-files]', form);
  filesInput.addEventListener('change', () => { $('[data-file-count]', form).textContent = filesInput.files.length ? ` ${filesInput.files.length}` : ''; });
  $('[data-ai-reply]', form)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      const r = await api(`/ai/ticket-reply/${t.id}`, { method: 'POST' });
      ta.value = r.text; ta.focus();
      toast('Rascunho gerado pela IA. Revise antes de enviar.', 'info');
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  const send = async () => {
    if (!ta.value.trim() && !filesInput.files.length) { ta.focus(); return; }
    const btn = $('[data-send]', form);
    btn.classList.add('loading');
    try {
      await apiForm(`/tickets/${t.id}/messages`, { body: ta.value, status: form.status.value, internal: noteMode, files: filesInput.files });
      toast(noteMode ? 'Nota interna registrada.' : 'Resposta enviada ao cliente.');
      reload();
    } catch (err) { toastError(err); btn.classList.remove('loading'); }
  };
  form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
  ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } });
}
