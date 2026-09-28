import { api, $, $$, esc, icon, money, date, datetime, badge, options, label, dataTable, formModal, confirmDialog, toast, toastError, lookups, modal, can, emptyState, tagsHtml, activityPanel, filesPanel, today } from '../core.js';

const RUNNING = ['approved', 'active', 'waiting_client', 'review', 'maintenance'];
const HEALTH = { on_track: ['No prazo', 'green'], attention: ['Atenção', 'yellow'], at_risk: ['Em risco', 'red'], idle: ['Parado', ''], done: ['Concluído', 'green'] };
const healthBadge = (h) => (HEALTH[h] ? `<span class="badge ${HEALTH[h][1]}" title="Saúde do projeto">${HEALTH[h][0]}</span>` : '');

async function projectForm(values = {}, onSaved) {
  const lk = await lookups();
  const templates = values.id ? [] : (await api('/project-templates').catch(() => ({ data: [] }))).data;
  formModal({
    title: values.id ? 'Editar projeto' : 'Novo projeto',
    size: 'lg',
    values,
    intro: values.id ? '' : `<div class="alert alert-info" style="margin:0">As etapas padrão (${esc(lk.stage_template.join(' → '))}) serão criadas automaticamente${templates.length ? ', ou escolha um modelo abaixo' : ''}.</div>`,
    fields: [
      !values.id && templates.length ? { name: 'template_id', label: 'Modelo de projeto', type: 'select', span: 2, empty: 'Etapas padrão', options: templates.map((t) => ({ value: t.id, label: `${t.name} (${t.stages.length} etapas)` })) } : null,
      { name: 'name', label: 'Nome do projeto', required: true, span: 2 },
      { name: 'customer_id', label: 'Cliente', type: 'select', options: lk.customers.map((c) => ({ value: c.id, label: c.name })) },
      { name: 'project_type', label: 'Tipo', type: 'select', options: lk.services.map((s) => ({ value: s, label: s })) },
      { name: 'status', label: 'Status', type: 'select', options: options('project_status'), empty: false, default: 'active' },
      { name: 'priority', label: 'Prioridade', type: 'select', options: options('priority'), empty: false, default: 'normal' },
      { name: 'start_date', label: 'Início', type: 'date' },
      { name: 'due_date', label: 'Prazo de entrega', type: 'date' },
      { name: 'budget', label: 'Valor do contrato (R$)', type: 'money' },
      { name: 'manager', label: 'Responsável', type: 'select', options: lk.users.map((u) => ({ value: u.name, label: u.name })).concat(values.manager && !lk.users.some((u) => u.name === values.manager) ? [{ value: values.manager, label: values.manager }] : []) },
      { name: 'estimated_hours', label: 'Horas estimadas', type: 'number', step: '0.5', min: 0 },
      { name: 'hourly_rate', label: 'Valor-hora (R$)', type: 'money' },
      { name: 'tags', label: 'Tags', type: 'tags', span: 2 },
      { name: 'description', label: 'Descrição / escopo (visível para o cliente)', type: 'textarea', span: 2 },
    ],
    onSubmit: async (data) => {
      const saved = values.id ? await api('/projects/' + values.id, { method: 'PUT', body: data }) : await api('/projects', { method: 'POST', body: data });
      toast(values.id ? 'Projeto atualizado.' : 'Projeto criado.');
      onSaved && onSaved(saved);
    },
  });
}

export async function render(el, ctx) {
  if (ctx.id) return renderDetail(el, ctx);
  const lk = await lookups();
  let mode = ctx.query.view || localStorage.getItem('ic-projects-view') || 'kanban';
  let groupBy = localStorage.getItem('ic-projects-group') || 'stage';

  el.innerHTML = `
    <div class="page-head"><div><h2>Projetos</h2><p>Acompanhe cada projeto pelas etapas de entrega.</p></div>
      <div class="page-actions">
        <div class="seg" role="tablist"><button data-mode="kanban">${icon('columns')} Quadro</button><button data-mode="timeline">${icon('flow')} Cronograma</button><button data-mode="list">${icon('list')} Lista</button></div>
        <button class="btn btn-primary" data-new>${icon('plus')} Novo projeto</button>
      </div></div>
    <div data-body></div>`;
  const body = $('[data-body]', el);

  async function renderKanban() {
    const res = await api('/projects', { query: { per_page: 300, sort: 'due_date', dir: 'asc' } });
    const byStatus = groupBy === 'status';
    const cols = byStatus
      ? options('project_status').filter((o) => o.value !== 'canceled').map((o) => ({ key: o.value, title: o.label }))
      : [...lk.stage_template.map((s) => ({ key: s, title: s })), { key: '__done', title: 'Concluídos' }];
    const active = res.data.filter((p) => p.status !== 'canceled');
    const byCol = (key) => active.filter((p) => (byStatus ? p.status === key : key === '__done' ? p.status === 'done' : p.status !== 'done' && (p.current_stage || lk.stage_template[0]) === key));
    const today = new Date().toISOString().slice(0, 10);
    body.innerHTML = `<div class="kanban-bar"><span class="muted small">Agrupar por</span><div class="seg seg-sm"><button data-group="stage" class="${!byStatus ? 'active' : ''}">Etapa</button><button data-group="status" class="${byStatus ? 'active' : ''}">Situação</button></div></div><div class="kanban">${cols.map((col) => {
      const items = byCol(col.key);
      return `<div class="k-col" data-col="${esc(col.key)}"><div class="k-col-head">${esc(col.title)}<span class="badge">${items.length}</span></div>
        <div class="k-col-body">${items.map((p) => `
          <article class="k-card" draggable="true" data-id="${p.id}" tabindex="0">
            <div style="display:flex;justify-content:space-between;gap:6px;align-items:flex-start"><b>${esc(p.name)}</b>${p.priority !== 'normal' ? badge('priority', p.priority) : ''}</div>
            <small class="muted">${esc(p.customer_name || 'Sem cliente')}</small>
            <div class="progress" style="margin-top:10px"><i style="width:${p.progress}%"></i></div>
            <div class="meta"><span>${p.progress}% · ${p.tasks_done}/${p.tasks_total} tarefas</span><span class="${p.due_date && p.due_date < today && p.status !== 'done' ? 'neg' : ''}">${icon('clock')} ${date(p.due_date)}</span></div>
            ${!byStatus && !['active', 'done'].includes(p.status) ? '<div style="margin-top:8px">' + badge('project_status', p.status) + '</div>' : ''}
            ${byStatus && p.current_stage ? `<div class="muted small" style="margin-top:6px">Etapa: ${esc(p.current_stage)}</div>` : ''}
            <div style="margin-top:8px;display:flex;gap:4px;flex-wrap:wrap">${healthBadge(p.health)}${Number(p.approvals_pending) ? `<span class="badge violet">${icon('check')} aprovação pendente</span>` : ''}</div>
            ${p.tags ? `<div style="margin-top:6px">${tagsHtml(p.tags)}</div>` : ''}
          </article>`).join('') || '<div class="muted small" style="text-align:center;padding:16px">Arraste projetos para cá</div>'}
        </div></div>`;
    }).join('')}</div>
    <p class="muted small">Dica: arraste um cartão para outra coluna para mudar ${byStatus ? 'a situação' : 'a etapa atual'}. Clique para abrir o projeto.</p>`;
    $$('[data-group]', body).forEach((b) => b.addEventListener('click', () => { groupBy = b.dataset.group; localStorage.setItem('ic-projects-group', groupBy); renderKanban(); }));

    let dragId = null;
    $$('.k-card', body).forEach((card) => {
      card.addEventListener('dragstart', (e) => { dragId = card.dataset.id; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
      card.addEventListener('dragend', () => card.classList.remove('dragging'));
      card.addEventListener('click', () => { location.hash = '#/projects/' + card.dataset.id; });
      card.addEventListener('keydown', (e) => { if (e.key === 'Enter') location.hash = '#/projects/' + card.dataset.id; });
    });
    $$('.k-col', body).forEach((col) => {
      const zone = $('.k-col-body', col);
      col.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
      col.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (!dragId) return;
        try {
          if (byStatus) {
            await api(`/projects/${dragId}`, { method: 'PUT', body: { status: col.dataset.col } });
            toast(`Situação: ${label('project_status', col.dataset.col)}.`);
          } else {
            await api(`/projects/${dragId}/move-stage`, { method: 'POST', body: { stage: col.dataset.col } });
            toast(col.dataset.col === '__done' ? 'Projeto concluído! 🎉' : `Projeto movido para ${col.dataset.col}.`);
          }
          renderKanban();
        } catch (err) { toastError(err); }
      });
    });
  }

  async function renderTimeline() {
    const res = await api('/projects-timeline');
    const rows = res.data.filter((p) => p.start_date || p.due_date);
    if (!rows.length) { body.innerHTML = emptyState('Defina datas de início e prazo nos projetos para ver o cronograma.', 'flow'); return; }
    const day = 86400000;
    const toT = (d) => new Date(String(d).slice(0, 10) + 'T12:00:00').getTime();
    let min = Math.min(...rows.map((p) => toT(p.start_date || p.created_at)));
    let max = Math.max(...rows.map((p) => toT(p.due_date || p.start_date || p.created_at)), Date.now() + 14 * day);
    min = new Date(new Date(min).getFullYear(), new Date(min).getMonth(), 1).getTime();
    max = new Date(new Date(max).getFullYear(), new Date(max).getMonth() + 1, 0).getTime();
    const span = max - min;
    const pct = (t) => ((t - min) / span * 100).toFixed(2);
    const months = [];
    for (let d = new Date(min); d.getTime() <= max; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) months.push(new Date(d));
    const todayPct = pct(Date.now());
    const cls = { done: 'done', skipped: 'done', in_progress: 'current', review: 'review', waiting_client: 'wait', blocked: 'blocked', pending: 'pending' };
    body.innerHTML = `<div class="card gantt"><div class="gantt-inner">
      <div class="g-row g-head"><div class="g-label">Projeto</div><div class="g-track">${months.map((m) => `<span style="left:${pct(m.getTime())}%">${m.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' }).replace('.', '')}</span>`).join('')}</div></div>
      ${rows.map((p) => {
        const s = toT(p.start_date || p.created_at); const e = toT(p.due_date || p.start_date || p.created_at) + day;
        const late = p.due_date && p.due_date < new Date().toISOString().slice(0, 10) && !['done', 'canceled'].includes(p.status);
        const stages = p.stages.filter((st) => st.start_date || st.due_date);
        return `<div class="g-row"><div class="g-label"><a href="#/projects/${p.id}"><b>${esc(p.name)}</b></a><small>${esc(p.customer_name || '')}</small></div>
          <div class="g-track"><i class="g-today" style="left:${todayPct}%"></i>
            <a class="g-bar ${late ? 'late' : ''} ${p.status}" href="#/projects/${p.id}" style="left:${pct(s)}%;width:${Math.max(0.8, pct(e) - pct(s))}%" title="${esc(p.name)} · ${date(p.start_date)} → ${date(p.due_date)}">
              ${stages.length ? stages.map((st) => { const a = toT(st.start_date || st.due_date); const b = toT(st.due_date || st.start_date) + day; return `<i class="g-stage ${cls[st.status] || ''}" style="left:${((a - s) / (e - s) * 100).toFixed(2)}%;width:${Math.max(2, (b - a) / (e - s) * 100).toFixed(2)}%" title="${esc(st.name)}"></i>`; }).join('') : ''}
              <span>${esc(p.name)}</span></a></div></div>`;
      }).join('')}
    </div></div>
    <p class="muted small">Barra = início ao prazo de entrega. Faixas internas = etapas com datas. Linha azul = hoje. Vermelho = atrasado.</p>`;
  }

  function renderList() {
    const table = dataTable(body, {
      endpoint: '/projects',
      searchPlaceholder: 'Buscar projeto, cliente, responsável...',
      filters: [
        { name: 'status', label: 'Todos os status', options: [{ value: 'open', label: '• Em aberto (não concluídos)' }, { value: 'running', label: '• Em execução' }, ...options('project_status')], value: ctx.query.status || 'open' },
        { name: 'priority', label: 'Prioridade', options: options('priority') },
        { name: 'customer_id', label: 'Cliente', options: lk.customers.map((c) => ({ value: c.id, label: c.name })), value: ctx.query.customer_id },
        { name: 'manager', label: 'Responsável', options: lk.users.map((u) => ({ value: u.name, label: u.name })) },
        { name: 'late', label: 'Prazo', options: [{ value: '1', label: 'Somente atrasados' }] },
        lk.tags.length ? { name: 'tag', label: 'Tag', options: lk.tags.map((t) => ({ value: t, label: '#' + t })), value: ctx.query.tag } : null,
      ].filter(Boolean),
      columns: [
        { label: 'Projeto', sort: 'name', primary: true, render: (p) => `<b>${esc(p.name)}</b><span class="sub">${esc(p.customer_name || 'Sem cliente')}</span>${p.tags ? `<span class="sub">${tagsHtml(p.tags)}</span>` : ''}` },
        { label: 'Etapa atual', render: (p) => esc(p.status === 'done' ? 'Concluído' : p.current_stage || '—') },
        { label: 'Progresso', render: (p) => `<div style="min-width:110px"><div class="progress"><i style="width:${p.progress}%"></i></div><small class="muted">${p.progress}%</small></div>` },
        { label: 'Prazo', sort: 'due_date', render: (p) => `<span class="${p.late ? 'neg' : ''}">${date(p.due_date)}</span>` },
        { label: 'Valor', sort: 'budget', num: true, render: (p) => money(p.budget) },
        { label: 'Horas', num: true, render: (p) => `${String(p.hours_logged).replace('.', ',')}${p.estimated_hours ? `<span class="sub">de ${String(Number(p.estimated_hours)).replace('.', ',')} h</span>` : ''}` },
        { label: 'Saúde', render: (p) => healthBadge(p.health) },
        { label: 'Status', render: (p) => badge('project_status', p.status) },
      ],
      onRowClick: (p) => { location.hash = '#/projects/' + p.id; },
      actions: (p) => [{ label: 'Editar', icon: 'edit', iconOnly: true, onClick: () => projectForm(p, () => table.reload()) }],
    });
  }

  const draw = () => {
    $$('[data-mode]', el).forEach((b) => b.classList.toggle('active', b.dataset.mode === mode));
    try { localStorage.setItem('ic-projects-view', mode); } catch (e) { /* ignore */ }
    body.innerHTML = '<div class="loading-box">Carregando...</div>';
    mode === 'kanban' ? renderKanban() : mode === 'timeline' ? renderTimeline() : renderList();
  };
  $$('[data-mode]', el).forEach((b) => b.addEventListener('click', () => { mode = b.dataset.mode; draw(); }));
  $('[data-new]', el).addEventListener('click', () => projectForm({ customer_id: ctx.query.customer_id }, (p) => { location.hash = '#/projects/' + p.id; }));
  draw();
  if (ctx.query.new) projectForm({ customer_id: ctx.query.customer_id }, (p) => { location.hash = '#/projects/' + p.id; });
}

async function renderDetail(el, ctx) {
  const { project: p, stages, finance, tickets } = await api(`/projects/${ctx.id}/board`);
  ctx.setTitle(p.name);
  const late = p.late;
  const margin = Number(finance.received) + Number(finance.to_receive) - Number(finance.spent);
  const STAGE_ICON = { done: 'check', skipped: 'x' };
  const tab = sessionStorage.getItem('ic-proj-tab') || 'activity';

  el.innerHTML = `
    <div class="page-head">
      <div><a href="#/projects" class="muted small">← Projetos</a><h2 style="margin-top:4px">${esc(p.name)}</h2>
        <p>${badge('project_status', p.status)} ${badge('priority', p.priority)} ${p.customer_id ? `· <a href="#/customers/${p.customer_id}">${esc(p.customer_name)}</a>` : ''} ${p.project_type ? '· ' + esc(p.project_type) : ''} ${tagsHtml(p.tags, { link: '#/projects?view=list&tag=' })}</p></div>
      <div class="page-actions">
        <select class="input" data-status aria-label="Situação do projeto" style="width:auto">${options('project_status').map((o) => `<option value="${o.value}" ${o.value === p.status ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>
        ${!['done', 'canceled'].includes(p.status) ? `<button class="btn btn-primary" data-advance>${icon('play')} Avançar etapa</button>` : ''}
        ${can('charges') && p.customer_id ? `<a class="btn" href="#/finance/charges?new=1&customer_id=${p.customer_id}&project_id=${p.id}">${icon('receipt')} Cobrar</a>` : ''}
        <button class="btn" data-edit>${icon('edit')} Editar</button>
        <button class="btn" data-more aria-label="Mais ações">${icon('menu')} Mais</button>
        <button class="btn btn-danger" data-del aria-label="Excluir">${icon('trash')}</button>
      </div>
    </div>
    ${Number(p.approvals_pending) ? `<div class="alert alert-info" style="margin-bottom:14px">${icon('clock')} ${p.approvals_pending} etapa(s) aguardando aprovação do cliente na Área do Cliente.</div>` : ''}
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label">Progresso</div><div class="k-value">${p.progress}%</div><div class="progress" style="margin-top:8px"><i style="width:${p.progress}%"></i></div></div>
      <div class="card kpi"><div class="k-label">Prazo</div><div class="k-value ${late ? 'neg' : ''}">${date(p.due_date)}</div><div class="k-sub">${late ? 'Atrasado' : p.start_date ? 'Início ' + date(p.start_date) : ''}</div></div>
      <div class="card kpi"><div class="k-label">Contrato</div><div class="k-value">${money(p.budget)}</div><div class="k-sub">Recebido ${money(finance.received)} · a receber ${money(finance.to_receive)}${p.quote_id ? ` · <a href="#/pricing/quotes/${p.quote_id}">ver orçamento</a>` : ''}</div></div>
      <div class="card kpi"><div class="k-label">Horas · ${healthBadge(p.health)}</div><div class="k-value">${String(p.hours_logged).replace('.', ',')} h</div>${p.estimated_hours ? `<div class="progress" style="margin:6px 0 4px"><i style="width:${Math.min(100, p.hours_logged / p.estimated_hours * 100)}%;${p.hours_logged > p.estimated_hours ? 'background:var(--danger)' : ''}"></i></div>` : ''}<div class="k-sub">${p.estimated_hours ? `de ${String(Number(p.estimated_hours)).replace('.', ',')} h estimadas · ` : ''}custos diretos ${money(finance.spent)} · margem ${money(margin)}</div></div>
    </div>
    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Etapas e tarefas</h3><button class="btn btn-sm" data-add-stage>${icon('plus')} Etapa</button></div>
        <div class="card-body"><div class="stages">${stages.map((s, i) => `
          <div class="stage ${s.status}" data-stage="${s.id}">
            <div class="stage-dot">${STAGE_ICON[s.status] ? icon(STAGE_ICON[s.status]) : i + 1}</div>
            <div class="stage-body">
              <div class="stage-head"><h4>${esc(s.name)}</h4>${badge('stage_status', s.status)}
                <select class="input" data-stage-status style="width:auto;padding:4px 8px;font-size:.8rem" aria-label="Status da etapa">${options('stage_status').map((o) => `<option value="${o.value}" ${o.value === s.status ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>
                ${!['done', 'skipped', 'review'].includes(s.status) && p.customer_id ? `<button class="btn btn-xs" data-approval title="Envia e-mail ao cliente e mostra os botões Aprovar / Pedir ajustes na Área do Cliente">${icon('send')} Pedir aprovação</button>` : ''}
                <button class="btn btn-xs btn-ghost" data-stage-edit title="Editar etapa">${icon('edit')}</button>
              </div>
              ${s.due_date || s.completed_at ? `<small class="muted">${s.due_date ? 'Prazo ' + date(s.due_date) : ''} ${s.completed_at ? (s.status === 'skipped' ? '· dispensada em ' : '· concluída em ') + date(s.completed_at) : ''}</small>` : ''}
              ${s.client_approval ? `<div class="approval-state ${s.client_approval}">${s.client_approval === 'approved' ? icon('check') + ' Aprovada pelo cliente' : s.client_approval === 'changes' ? icon('alert') + ' Cliente pediu ajustes' : icon('clock') + ' Aguardando aprovação do cliente'}${s.approval_at ? ' · ' + datetime(s.approval_at) : ''}${s.client_feedback ? `<p>“${esc(s.client_feedback)}”</p>` : ''}</div>` : ''}
              ${s.notes ? `<p class="small muted" style="margin:6px 0 0;white-space:pre-wrap">${icon('lock')} ${esc(s.notes)}</p>` : ''}
              <div style="margin-top:8px">${s.tasks.map((t) => `
                <div class="task ${Number(t.done) ? 'done' : ''}"><input type="checkbox" data-task="${t.id}" ${Number(t.done) ? 'checked' : ''} aria-label="Concluir tarefa"><span class="grow">${esc(t.title)}${t.assignee ? ` <small class="muted">· ${esc(t.assignee)}</small>` : ''}${t.due_date ? ` <small class="muted">· ${date(t.due_date)}</small>` : ''}</span>
                  <button class="btn btn-xs btn-ghost" data-task-vis="${t.id}" data-on="${Number(t.client_visible) ? 1 : 0}" title="${Number(t.client_visible) ? 'Visível para o cliente' : 'Só a equipe vê'}">${icon(Number(t.client_visible) ? 'eye' : 'lock')}</button>
                  <button class="btn btn-xs btn-ghost" data-task-del="${t.id}" aria-label="Excluir tarefa">${icon('x')}</button></div>`).join('')}
                <form class="task task-new" data-task-form><input class="input" name="title" placeholder="+ Nova tarefa nesta etapa" aria-label="Nova tarefa"><input class="input" name="assignee" placeholder="Responsável" list="proj-users" aria-label="Responsável"><input class="input" type="date" name="due_date" aria-label="Prazo"><label class="small muted" title="Visível na Área do Cliente"><input type="checkbox" name="client_visible" checked> cliente vê</label><button class="btn btn-xs">Adicionar</button></form>
              </div>
            </div>
          </div>`).join('')}</div><datalist id="proj-users"></datalist></div>
      </section>
      <section class="card"><div class="card-head"><h3>Detalhes</h3></div><div class="card-body">
        <dl class="kv" style="grid-template-columns:110px 1fr">
          <dt>Cliente</dt><dd>${p.customer_id ? `<a href="#/customers/${p.customer_id}">${esc(p.customer_name)}</a>` : '—'}</dd>
          <dt>Responsável</dt><dd>${esc(p.manager || '—')}</dd>
          <dt>Início</dt><dd>${date(p.start_date)}</dd>
          <dt>Tarefas</dt><dd>${p.tasks_done}/${p.tasks_total} concluídas</dd>
          <dt>Chamados</dt><dd>${Number(p.tickets_open) ? `<span class="badge orange">${p.tickets_open} aberto(s)</span>` : 'Nenhum aberto'}</dd>
          <dt>Atualizado</dt><dd>${date(p.updated_at)}</dd>
        </dl>
        ${p.description ? `<div class="alert" style="margin:14px 0 0;white-space:pre-wrap">${esc(p.description)}</div>` : ''}
      </div></section>
    </div>
    <section class="card" style="margin-top:16px">
      <div class="card-head" style="padding:0 18px"><div class="tabs" style="margin:0;border:0">
        <button class="tab" data-t="activity">${icon('log')} Histórico e follow-ups</button><button class="tab" data-t="time">${icon('clock')} Horas</button><button class="tab" data-t="files">${icon('paperclip')} Arquivos</button><button class="tab" data-t="tickets">${icon('ticket')} Chamados (${tickets.length})</button>
      </div></div>
      <div class="card-body" data-tab-body></div>
    </section>`;

  lookups().then((lk) => { const dl = $('#proj-users', el); if (dl) dl.innerHTML = lk.users.map((u) => `<option value="${esc(u.name)}">`).join(''); });
  const tabBody = $('[data-tab-body]', el);
  const showTab = (t) => {
    sessionStorage.setItem('ic-proj-tab', t);
    $$('[data-t]', el).forEach((b) => b.classList.toggle('active', b.dataset.t === t));
    if (t === 'activity') activityPanel(tabBody, 'project', p.id);
    if (t === 'files') filesPanel(tabBody, 'project', p.id, { clientToggle: !!p.customer_id });
    if (t === 'time') timePanel(tabBody, p, stages, reload);
    if (t === 'tickets') tabBody.innerHTML = tickets.length ? `<ul class="list">${tickets.map((k) => `<li><div class="grow"><a href="#/tickets/${k.id}"><b>${esc(k.subject)}</b></a><small>${esc(k.protocol)} · ${datetime(k.updated_at)}</small></div>${badge('priority', k.priority)} ${badge('ticket_status', k.status)}</li>`).join('')}</ul>` : emptyState('Nenhum chamado vinculado a este projeto.', 'ticket');
  };
  $$('[data-t]', el).forEach((b) => b.addEventListener('click', () => showTab(b.dataset.t)));
  showTab(tab);

  const reload = () => renderDetail(el, ctx);
  $('[data-status]', el).addEventListener('change', async (e) => {
    try { await api('/projects/' + p.id, { method: 'PUT', body: { status: e.target.value } }); toast('Situação atualizada.'); reload(); } catch (err) { toastError(err); }
  });
  $('[data-edit]', el).addEventListener('click', () => projectForm(p, reload));
  $('[data-more]', el).addEventListener('click', () => {
    const m = modal({ title: 'Mais ações', size: 'sm', footer: null, body: `<div class="action-list">
      <button data-a="kickoff">${icon('play')}<div><b>Apresentação de kickoff</b><small>Objetivos, escopo, cronograma e papéis, com a identidade da Integra Code.</small></div></button>
      ${p.customer_id ? `<button data-a="results">${icon('trendUp')}<div><b>Apresentação de resultados</b><small>Entregas, indicadores e próximos passos para o cliente.</small></div></button>` : ''}
      <button data-a="duplicate">${icon('copy')}<div><b>Duplicar projeto</b><small>Copia etapas e tarefas para um novo projeto.</small></div></button>
      <button data-a="template">${icon('layers')}<div><b>Salvar como modelo</b><small>Reaproveite estas etapas e tarefas em novos projetos.</small></div></button>
      <a href="#/reports/project_profitability">${icon('pie')}<div><b>Relatório de rentabilidade</b><small>Margem, custos e horas de todos os projetos.</small></div></a>
    </div>` });
    m.el.querySelectorAll('[data-a]').forEach((b) => b.addEventListener('click', async () => {
      const a = b.dataset.a;
      m.close();
      if (a === 'kickoff' || a === 'results') {
        const { wizard } = await import('./presentations.js');
        wizard(a, null, a === 'kickoff' ? { project_id: p.id } : { customer_id: p.customer_id });
      }
      if (a === 'duplicate') formModal({ title: 'Duplicar projeto', size: 'sm', values: { name: p.name + ' (cópia)', customer_id: p.customer_id }, fields: [{ name: 'name', label: 'Nome do novo projeto', required: true, span: 2 }, { name: 'customer_id', label: 'Cliente', type: 'select', span: 2, options: (await lookups()).customers.map((c) => ({ value: c.id, label: c.name })) }],
        onSubmit: async (v) => { const r = await api(`/projects/${p.id}/duplicate`, { method: 'POST', body: v }); toast('Projeto duplicado.'); location.hash = '#/projects/' + r.id; } });
      if (a === 'template') formModal({ title: 'Salvar como modelo', size: 'sm', values: { name: p.project_type ? 'Modelo — ' + p.project_type : 'Modelo — ' + p.name }, fields: [{ name: 'name', label: 'Nome do modelo', required: true, span: 2 }, { name: 'description', label: 'Descrição', span: 2 }],
        onSubmit: async (v) => { await api(`/projects/${p.id}/save-template`, { method: 'POST', body: v }); toast('Modelo salvo. Ele aparece ao criar um novo projeto.'); } });
    }));
  });
  $('[data-advance]', el)?.addEventListener('click', async () => {
    try { await api(`/projects/${p.id}/advance`, { method: 'POST' }); toast('Etapa avançada.'); reload(); } catch (e) { toastError(e); }
  });
  $('[data-del]', el).addEventListener('click', async () => {
    if (!await confirmDialog(`Excluir o projeto "${p.name}" com todas as etapas, tarefas, arquivos e histórico?`, { danger: true, okLabel: 'Excluir' })) return;
    try { await api('/projects/' + p.id, { method: 'DELETE' }); toast('Projeto excluído.'); location.hash = '#/projects'; } catch (e) { toastError(e); }
  });
  $('[data-add-stage]', el).addEventListener('click', () => formModal({
    title: 'Nova etapa', size: 'sm', fields: [{ name: 'name', label: 'Nome da etapa', required: true, span: 2 }],
    onSubmit: async (d) => { await api(`/projects/${p.id}/stages`, { method: 'POST', body: d }); reload(); },
  }));
  $$('[data-stage]', el).forEach((st) => {
    const sid = st.dataset.stage;
    const stage = stages.find((s) => String(s.id) === sid);
    $('[data-stage-status]', st).addEventListener('change', async (e) => {
      try { await api('/stages/' + sid, { method: 'PUT', body: { status: e.target.value } }); reload(); } catch (err) { toastError(err); }
    });
    $('[data-approval]', st)?.addEventListener('click', async () => {
      if (!await confirmDialog(`Marcar "${stage.name}" como pronta e pedir a aprovação do cliente? Ele recebe um e-mail e vê os botões Aprovar / Pedir ajustes na Área do Cliente.`, { okLabel: 'Pedir aprovação' })) return;
      try { await api('/stages/' + sid, { method: 'PUT', body: { status: 'review', request_approval: true } }); toast('Aprovação solicitada ao cliente.'); reload(); } catch (err) { toastError(err); }
    });
    $('[data-stage-edit]', st).addEventListener('click', () => {
      const m = formModal({
        title: 'Editar etapa', values: stage,
        fields: [
          { name: 'name', label: 'Nome', required: true, span: 2 },
          { name: 'start_date', label: 'Início', type: 'date' },
          { name: 'due_date', label: 'Prazo', type: 'date' },
          { name: 'notes', label: 'Anotações (visíveis só para a equipe)', type: 'textarea', span: 2 },
          { name: '_del', type: 'html', span: 2, html: `<button type="button" class="btn btn-sm btn-danger" data-stage-del>${icon('trash')} Excluir etapa</button>` },
        ],
        onSubmit: async (d) => { delete d._del; await api('/stages/' + sid, { method: 'PUT', body: d }); reload(); },
      });
      $('[data-stage-del]', m.el).addEventListener('click', async () => {
        if (!await confirmDialog('Excluir esta etapa? As tarefas ficarão sem etapa.', { danger: true })) return;
        await api('/stages/' + sid, { method: 'DELETE' }); m.close(); reload();
      });
    });
    $('[data-task-form]', st).addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target;
      if (!f.title.value.trim()) return;
      try { await api(`/projects/${p.id}/tasks`, { method: 'POST', body: { title: f.title.value, assignee: f.assignee.value, due_date: f.due_date.value, client_visible: f.client_visible.checked, stage_id: sid } }); reload(); } catch (err) { toastError(err); }
    });
  });
  $$('[data-task]', el).forEach((cb) => cb.addEventListener('change', async () => {
    try { await api('/tasks/' + cb.dataset.task, { method: 'PUT', body: { done: cb.checked } }); cb.closest('.task').classList.toggle('done', cb.checked); } catch (e) { toastError(e); cb.checked = !cb.checked; }
  }));
  $$('[data-task-vis]', el).forEach((b) => b.addEventListener('click', async () => {
    const on = b.dataset.on === '1';
    try { await api('/tasks/' + b.dataset.taskVis, { method: 'PUT', body: { client_visible: !on } }); b.dataset.on = on ? '0' : '1'; b.innerHTML = icon(on ? 'lock' : 'eye'); b.title = on ? 'Só a equipe vê' : 'Visível para o cliente'; } catch (e) { toastError(e); }
  }));
  $$('[data-task-del]', el).forEach((b) => b.addEventListener('click', async () => {
    try { await api('/tasks/' + b.dataset.taskDel, { method: 'DELETE' }); b.closest('.task').remove(); } catch (e) { toastError(e); }
  }));
}

/* ---------------------------------------------------------------- time tracking */
async function timePanel(box, p, stages, onChange) {
  const tasks = stages.flatMap((s) => s.tasks.map((t) => ({ id: t.id, title: `${s.name} · ${t.title}` })));
  box.innerHTML = `
    <form class="time-form" data-time>
      <input type="date" class="input" name="work_date" value="${today()}" aria-label="Data" required>
      <input class="input" name="hours" placeholder="Horas (ex.: 1,5)" inputmode="decimal" aria-label="Horas" required style="max-width:140px">
      <select class="input" name="task_id" aria-label="Tarefa"><option value="">Sem tarefa específica</option>${tasks.map((t) => `<option value="${t.id}">${esc(t.title)}</option>`).join('')}</select>
      <input class="input" name="description" placeholder="O que foi feito?" aria-label="Descrição">
      <label class="check small"><input type="checkbox" name="billable" checked> Faturável</label>
      <button class="btn btn-primary btn-sm">${icon('plus')} Apontar</button>
    </form>
    <div data-time-list><div class="loading-box">Carregando...</div></div>`;
  const form = $('[data-time]', box);
  const listEl = $('[data-time-list]', box);
  async function load() {
    const r = await api(`/projects/${p.id}/time`);
    const total = r.data.reduce((s, t) => s + Number(t.minutes), 0) / 60;
    const billable = r.data.filter((t) => Number(t.billable)).reduce((s, t) => s + Number(t.minutes), 0) / 60;
    const byPerson = {};
    r.data.forEach((t) => { byPerson[t.user_name || '—'] = (byPerson[t.user_name || '—'] || 0) + t.minutes / 60; });
    listEl.innerHTML = `<div class="time-sum"><span><b>${total.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} h</b> apontadas</span><span><b>${billable.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} h</b> faturáveis</span>${p.hourly_rate ? `<span>Valor: <b>${money(billable * p.hourly_rate)}</b></span>` : ''}${Object.entries(byPerson).map(([n, h]) => `<span class="muted">${esc(n)}: ${h.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} h</span>`).join('')}</div>
      ${r.data.length ? `<div class="table-wrap"><table class="dt cards"><thead><tr><th>Data</th><th>Pessoa</th><th>Atividade</th><th class="num">Horas</th><th></th></tr></thead><tbody>${r.data.map((t) => `<tr><td data-label="Data">${date(t.work_date)}</td><td data-label="Pessoa">${esc(t.user_name || '—')}</td><td data-label="Atividade" class="primary">${esc(t.description || t.task_title || '—')}${t.task_title && t.description ? `<span class="sub">${esc(t.task_title)}</span>` : ''}${Number(t.billable) ? '' : ' <span class="badge">não faturável</span>'}</td><td class="num" data-label="Horas">${(t.minutes / 60).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td><td class="actions"><button class="btn btn-xs btn-ghost" data-del-time="${t.id}" aria-label="Excluir">${icon('trash')}</button></td></tr>`).join('')}</tbody></table></div>` : emptyState('Nenhuma hora apontada ainda.', 'clock')}`;
    $$('[data-del-time]', listEl).forEach((b) => b.addEventListener('click', async () => { if (!await confirmDialog('Excluir este apontamento?', { danger: true })) return; await api('/time/' + b.dataset.delTime, { method: 'DELETE' }); load(); }));
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button', form);
    btn.classList.add('loading');
    try {
      await api(`/projects/${p.id}/time`, { method: 'POST', body: { work_date: form.work_date.value, hours: form.hours.value, task_id: form.task_id.value, description: form.description.value, billable: form.billable.checked } });
      form.hours.value = ''; form.description.value = '';
      toast('Horas apontadas.');
      load();
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  load();
}
