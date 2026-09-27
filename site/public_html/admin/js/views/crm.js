import { api, state, $, $$, esc, icon, date, datetime, money, badge, options, label, dataTable, formModal, confirmDialog, modal, toast, toastError, lookups, aiText, tagsHtml, activityPanel, filesPanel } from '../core.js';

const wa = (phone) => (phone ? `https://wa.me/55${String(phone).replace(/\D/g, '').replace(/^55/, '')}` : null);

export async function render(el, ctx) {
  return ctx.sub === 'appointments' ? renderAppointments(el, ctx) : renderLeads(el, ctx);
}

/* ================================================================ LEADS */
async function leadForm(values, onSaved) {
  const lk = await lookups();
  formModal({
    title: values.id ? 'Editar lead' : 'Novo lead', values, size: 'lg',
    fields: [
      { name: 'name', label: 'Nome', required: true },
      { name: 'company', label: 'Empresa' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'phone', label: 'Telefone/WhatsApp' },
      { name: 'subject', label: 'Assunto', span: 2 },
      { name: 'status', label: 'Etapa do funil', type: 'select', options: options('lead_status'), empty: false, default: 'new' },
      { name: 'source', label: 'Origem', type: 'select', options: options('lead_source'), empty: false, default: 'manual' },
      { name: 'estimated_value', label: 'Valor estimado (R$)', type: 'money' },
      { name: 'next_action_at', label: 'Próximo contato', type: 'datetime' },
      { name: 'owner', label: 'Responsável', type: 'select', options: lk.users.map((u) => ({ value: u.name, label: u.name })) },
      { name: 'tags', label: 'Tags', type: 'tags' },
      { name: 'lost_reason', label: 'Motivo da perda (se perdido)', span: 2 },
      { name: 'message', label: 'Mensagem / anotações', type: 'textarea', span: 2 },
    ],
    onSubmit: async (d) => {
      values.id ? await api('/leads/' + values.id, { method: 'PUT', body: d }) : await api('/leads', { method: 'POST', body: d });
      toast('Lead salvo.');
      onSaved();
    },
  });
}

function leadDetail(lead, table) {
  let payload = null;
  try { payload = lead.payload ? JSON.parse(lead.payload) : null; } catch (e) { /* ignore */ }
  const m = modal({
    title: lead.name, size: 'lg',
    body: `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">${badge('lead_status', lead.status)} ${badge('lead_source', lead.source)} <span class="muted small">Recebido ${datetime(lead.created_at)}</span></div>
      <dl class="kv">
        <dt>Empresa</dt><dd>${esc(lead.company || '—')}</dd>
        <dt>E-mail</dt><dd>${lead.email ? `<a href="mailto:${esc(lead.email)}">${esc(lead.email)}</a>` : '—'}</dd>
        <dt>Telefone</dt><dd>${lead.phone ? `<a href="${wa(lead.phone)}" target="_blank" rel="noopener">${esc(lead.phone)} ${icon('external')}</a>` : '—'}</dd>
        <dt>Assunto</dt><dd>${esc(lead.subject || '—')}</dd>
        <dt>Valor estimado</dt><dd>${lead.estimated_value ? money(lead.estimated_value) : '—'}</dd>
        <dt>Próximo contato</dt><dd>${lead.next_action_at ? datetime(lead.next_action_at) : '—'}</dd>
        <dt>Responsável</dt><dd>${esc(lead.owner || '—')}</dd>
        ${lead.tags ? `<dt>Tags</dt><dd>${tagsHtml(lead.tags)}</dd>` : ''}
        ${lead.lost_reason ? `<dt>Motivo da perda</dt><dd>${esc(lead.lost_reason)}</dd>` : ''}
      </dl>
      ${lead.message ? `<div class="alert" style="margin-top:14px;white-space:pre-wrap">${esc(lead.message)}</div>` : ''}
      ${payload?.answers ? `<h4 style="margin:18px 0 8px">Respostas do diagnóstico · maturidade ${payload.score}%</h4><ul class="list card">${payload.answers.map((a) => `<li><div class="grow"><small>${esc(a.question)}</small><b style="white-space:normal">${esc(a.answer || '—')}</b></div></li>`).join('')}</ul>` : ''}
      ${state.ai?.admin ? `<div style="margin-top:16px"><button class="btn btn-sm" data-ai-lead>${icon('sparkles')} Analisar lead com IA</button><div data-ai-out class="alert hidden" style="margin:10px 0 0;border-color:rgba(0,207,129,.35)"></div></div>` : ''}
      <div style="margin-top:16px"><label class="field-label">Etapa do funil</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">${options('lead_status').map((o) => `<button class="btn btn-sm ${o.value === lead.status ? 'btn-primary' : ''}" data-status="${o.value}">${esc(o.label)}</button>`).join('')}</div></div>
      <div class="tabs" style="margin:18px 0 12px"><button class="tab active" data-lt="activity">Histórico e follow-ups</button><button class="tab" data-lt="files">Arquivos</button></div>
      <div data-lead-panel></div>`,
    footer: `<button class="btn btn-danger" data-del>${icon('trash')} Excluir</button><span style="flex:1"></span>
      ${lead.phone ? `<a class="btn" href="${wa(lead.phone)}" target="_blank" rel="noopener">WhatsApp</a>` : ''}
      <a class="btn" href="#/pricing?lead_id=${lead.id}">${icon('money')} Simular orçamento</a><button class="btn" data-proposal>${icon('sparkles')} Gerar proposta</button><button class="btn" data-edit>${icon('edit')} Editar</button><button class="btn btn-primary" data-convert>${icon('users')} Converter em cliente</button>`,
  });
  const panel = $('[data-lead-panel]', m.el);
  const showTab = (t) => { $$('[data-lt]', m.el).forEach((b) => b.classList.toggle('active', b.dataset.lt === t)); t === 'files' ? filesPanel(panel, 'lead', lead.id) : activityPanel(panel, 'lead', lead.id, { onChange: () => table.reload() }); };
  $$('[data-lt]', m.el).forEach((b) => b.addEventListener('click', () => showTab(b.dataset.lt)));
  showTab('activity');
  m.el.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', async () => {
    const body = { status: b.dataset.status };
    if (b.dataset.status === 'lost') {
      const reason = window.prompt('Motivo da perda (preço, prazo, concorrente, sem resposta...):', lead.lost_reason || '');
      if (reason === null) return;
      body.lost_reason = reason;
    }
    await api('/leads/' + lead.id, { method: 'PUT', body });
    toast('Etapa atualizada.'); m.close(); table.reload(); window.dispatchEvent(new Event('ic:refresh-counts'));
  }));
  $('[data-ai-lead]', m.el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const out = $('[data-ai-out]', m.el);
    btn.classList.add('loading');
    try { const r = await api(`/ai/lead-summary/${lead.id}`, { method: 'POST' }); out.innerHTML = aiText(r.text); out.classList.remove('hidden'); } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-edit]', m.el).addEventListener('click', () => { m.close(); leadForm(lead, () => table.reload()); });
  $('[data-proposal]', m.el).addEventListener('click', async () => { m.close(); const { wizard } = await import('./presentations.js'); wizard('proposal', null, { lead_id: lead.id }); });
  $('[data-del]', m.el).addEventListener('click', async () => {
    if (!await confirmDialog('Excluir este lead?', { danger: true })) return;
    await api('/leads/' + lead.id, { method: 'DELETE' }); m.close(); table.reload();
  });
  $('[data-convert]', m.el).addEventListener('click', async () => {
    try {
      const c = await api('/customers', { method: 'POST', body: { name: lead.company || lead.name, trade_name: lead.company ? lead.name : '', email: lead.email, phone: lead.phone, status: 'active', notes: `Convertido do lead #${lead.id}. ${lead.subject || ''}` } });
      await api('/leads/' + lead.id, { method: 'PUT', body: { status: 'won' } });
      lookups(true);
      toast('Cliente criado a partir do lead!');
      m.close();
      location.hash = '#/customers/' + c.id;
    } catch (e) { toastError(e); }
  });
}

async function renderLeads(el, ctx) {
  const lk = await lookups();
  let mode = localStorage.getItem('ic-leads-mode') || 'pipeline';
  el.innerHTML = `
    <div class="page-head"><div><h2>Leads</h2><p>Funil comercial: do primeiro contato ao contrato fechado.</p></div>
      <div class="page-actions">
        <div class="seg"><button data-mode="pipeline">${icon('columns')} Funil</button><button data-mode="list">${icon('list')} Lista</button></div>
        <button class="btn btn-primary" data-new>${icon('plus')} Novo lead</button></div></div>
    <div data-table></div>`;
  const host = $('[data-table]', el);
  let table = { reload: () => draw() };

  async function renderPipeline() {
    const res = await api('/leads', { query: { per_page: 400, sort: 'created_at', dir: 'desc' } });
    const cols = options('lead_status');
    const now = new Date();
    host.innerHTML = `<div class="kanban">${cols.map((c) => {
      const items = res.data.filter((l) => l.status === c.value);
      const total = items.reduce((sum, l) => sum + Number(l.estimated_value || 0), 0);
      return `<div class="k-col" data-col="${c.value}"><div class="k-col-head">${esc(c.label)}<span class="badge">${items.length}</span></div>
        ${total ? `<div class="k-col-sum">${money(total)}</div>` : ''}
        <div class="k-col-body">${items.map((l) => {
          const due = l.next_action_at && new Date(l.next_action_at.replace(' ', 'T'));
          return `<article class="k-card" draggable="true" data-id="${l.id}" tabindex="0">
            <b>${esc(l.name)}</b><small class="muted">${esc(l.company || l.subject || l.email || '')}</small>
            <div class="meta"><span>${badge('lead_source', l.source)}</span>${l.estimated_value ? `<b>${money(l.estimated_value)}</b>` : ''}</div>
            ${due ? `<div class="small ${due < now ? 'neg' : 'muted'}" style="margin-top:6px">${icon('bell')} ${datetime(l.next_action_at)}</div>` : ''}
            ${l.tags ? `<div style="margin-top:6px">${tagsHtml(l.tags)}</div>` : ''}
          </article>`;
        }).join('') || '<div class="muted small" style="text-align:center;padding:16px">Arraste leads para cá</div>'}</div></div>`;
    }).join('')}</div><p class="muted small">Arraste os cartões entre as etapas. Clique para ver detalhes, histórico e converter em cliente.</p>`;
    let dragId = null;
    $$('.k-card', host).forEach((card) => {
      card.addEventListener('dragstart', (e) => { dragId = card.dataset.id; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
      card.addEventListener('dragend', () => card.classList.remove('dragging'));
      const open = () => leadDetail(res.data.find((x) => String(x.id) === card.dataset.id), table);
      card.addEventListener('click', open);
      card.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
    });
    $$('.k-col', host).forEach((col) => {
      const zone = $('.k-col-body', col);
      col.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
      col.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault(); zone.classList.remove('drag-over');
        if (!dragId) return;
        const body = { status: col.dataset.col };
        if (body.status === 'lost') { const r = window.prompt('Motivo da perda:'); if (r === null) return; body.lost_reason = r; }
        try { await api('/leads/' + dragId, { method: 'PUT', body }); toast('Lead movido para ' + label('lead_status', body.status) + '.'); renderPipeline(); window.dispatchEvent(new Event('ic:refresh-counts')); } catch (err) { toastError(err); }
      });
    });
  }

  function renderList() {
    table = dataTable(host, {
      endpoint: '/leads', exportPath: '/leads/export.csv',
      searchPlaceholder: 'Buscar por nome, e-mail, empresa, mensagem, tag...',
      filters: [
        { name: 'status', label: 'Todas as etapas', options: options('lead_status'), value: ctx.query.status },
        { name: 'source', label: 'Todas as origens', options: options('lead_source') },
        { name: 'owner', label: 'Responsável', options: lk.users.map((u) => ({ value: u.name, label: u.name })) },
        { name: 'followup', label: 'Follow-up', options: [{ value: 'due', label: 'Contato vencido/hoje' }] },
        lk.tags.length ? { name: 'tag', label: 'Tag', options: lk.tags.map((t) => ({ value: t, label: '#' + t })) } : null,
      ].filter(Boolean),
      columns: [
        { label: 'Contato', sort: 'name', primary: true, render: (l) => `<b>${esc(l.name)}</b><span class="sub">${esc(l.company || l.email || '')}</span>${l.tags ? `<span class="sub">${tagsHtml(l.tags)}</span>` : ''}` },
        { label: 'Assunto', render: (l) => esc(l.subject || '—') },
        { label: 'Origem', render: (l) => badge('lead_source', l.source) },
        { label: 'Valor', sort: 'estimated_value', num: true, render: (l) => (l.estimated_value ? money(l.estimated_value) : '—') },
        { label: 'Próx. contato', sort: 'next_action_at', render: (l) => (l.next_action_at ? `<span class="${new Date(l.next_action_at.replace(' ', 'T')) < new Date() ? 'neg' : ''}">${datetime(l.next_action_at)}</span>` : '—') },
        { label: 'Recebido', sort: 'created_at', render: (l) => date(l.created_at) },
        { label: 'Etapa', sort: 'status', render: (l) => badge('lead_status', l.status) },
      ],
      onRowClick: (l) => leadDetail(l, table),
      actions: (l) => [l.phone && { label: 'WhatsApp', icon: 'send', iconOnly: true, onClick: () => window.open(wa(l.phone), '_blank') }],
      bulk: [
        { label: 'Mudar etapa...', icon: 'refresh', action: (ids) => new Promise((resolve) => formModal({ title: 'Mudar etapa do funil', size: 'sm', fields: [{ name: 'status', label: 'Etapa', type: 'select', options: options('lead_status'), empty: false, span: 2 }], onSubmit: async (d) => { await Promise.all(ids.map((id) => api('/leads/' + id, { method: 'PUT', body: { status: d.status } }))); toast('Leads atualizados.'); resolve(); } })) },
        { label: 'Excluir', danger: true, icon: 'trash', action: async (ids) => { if (await confirmDialog(`Excluir ${ids.length} lead(s)?`, { danger: true })) await Promise.all(ids.map((id) => api('/leads/' + id, { method: 'DELETE' }))); } },
      ],
    });
  }

  function draw() {
    $$('[data-mode]', el).forEach((b) => b.classList.toggle('active', b.dataset.mode === mode));
    host.innerHTML = '<div class="loading-box">Carregando...</div>';
    if (mode === 'pipeline') { table = { reload: () => renderPipeline() }; renderPipeline().catch(toastError); } else renderList();
  }
  $$('[data-mode]', el).forEach((b) => b.addEventListener('click', () => { mode = b.dataset.mode; localStorage.setItem('ic-leads-mode', mode); draw(); }));
  $('[data-new]', el).addEventListener('click', () => leadForm({}, () => table.reload()));
  draw();
  if (ctx.query.open) api('/leads/' + ctx.query.open).then((l) => leadDetail(l, table)).catch(() => {});
}

/* ========================================================== APPOINTMENTS */
function appointmentForm(values, onSaved) {
  formModal({
    title: values.id ? 'Editar reunião' : 'Nova reunião', values, size: 'lg',
    fields: [
      { name: 'name', label: 'Nome', required: true },
      { name: 'company', label: 'Empresa' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'phone', label: 'Telefone/WhatsApp' },
      { name: 'scheduled_at', label: 'Data e hora', type: 'datetime', required: true },
      { name: 'meeting_type', label: 'Formato', type: 'select', options: options('meeting_type'), empty: false },
      { name: 'topic', label: 'Assunto', span: 2 },
      { name: 'status', label: 'Status', type: 'select', options: options('appointment_status'), empty: false, default: 'scheduled' },
      { name: 'notes', label: 'Anotações', type: 'textarea', span: 2 },
    ],
    onSubmit: async (d) => {
      values.id ? await api('/appointments/' + values.id, { method: 'PUT', body: d }) : await api('/appointments', { method: 'POST', body: d });
      toast('Reunião salva.');
      onSaved();
    },
  });
}

function renderAppointments(el) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Agenda</h2><p>Reuniões agendadas pelo site e pela equipe.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Nova reunião</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/appointments', sort: 'scheduled_at', dir: 'desc',
    searchPlaceholder: 'Buscar por nome, empresa, assunto...',
    filters: [{ name: 'status', label: 'Todos os status', options: options('appointment_status') }],
    columns: [
      { label: 'Quando', sort: 'scheduled_at', primary: true, render: (a) => `<b>${datetime(a.scheduled_at)}</b><span class="sub">${esc(label('meeting_type', a.meeting_type))}</span>` },
      { label: 'Contato', render: (a) => `${esc(a.name)}<span class="sub">${esc(a.company || a.email || '')}</span>` },
      { label: 'Assunto', render: (a) => esc(a.topic || '—') },
      { label: 'Status', render: (a) => badge('appointment_status', a.status) },
    ],
    onRowClick: (a) => appointmentForm(a, () => table.reload()),
    actions: (a) => [
      a.status === 'scheduled' && { label: 'Confirmar', icon: 'check', success: true, onClick: async () => { await api('/appointments/' + a.id, { method: 'PUT', body: { status: 'confirmed' } }); toast('Reunião confirmada.'); table.reload(); } },
      a.phone && { label: 'WhatsApp', icon: 'send', iconOnly: true, onClick: () => window.open(wa(a.phone) + '?text=' + encodeURIComponent(`Olá ${a.name}! Confirmando nossa conversa em ${datetime(a.scheduled_at)}. — Integra Code`), '_blank') },
      { label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog('Excluir esta reunião?', { danger: true })) { await api('/appointments/' + a.id, { method: 'DELETE' }); table.reload(); } } },
    ],
  });
  $('[data-new]', el).addEventListener('click', () => appointmentForm({}, () => table.reload()));
}
