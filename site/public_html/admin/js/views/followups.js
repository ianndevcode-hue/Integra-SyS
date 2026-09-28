import { api, $, $$, esc, icon, datetime, badge, emptyState, toast, toastError } from '../core.js';

const LINKS = { customer: (id) => '#/customers/' + id, lead: (id) => '#/leads?open=' + id, project: (id) => '#/projects/' + id, ticket: (id) => '#/tickets/' + id };
const ENTITY = { customer: 'Cliente', lead: 'Lead', project: 'Projeto', ticket: 'Chamado' };

export async function render(el) {
  let scope = localStorage.getItem('ic-followups-scope') || 'all';
  el.innerHTML = `
    <div class="page-head"><div><h2>Follow-ups</h2><p>Próximos passos agendados em clientes, leads, projetos e chamados.</p></div>
      <div class="page-actions"><div class="seg"><button data-scope="all">Toda a equipe</button><button data-scope="mine">Só os meus</button></div></div></div>
    <div data-body><div class="loading-box">Carregando...</div></div>`;
  const body = $('[data-body]', el);

  async function load() {
    $$('[data-scope]', el).forEach((b) => b.classList.toggle('active', b.dataset.scope === scope));
    const res = await api('/activities', { query: { scope: scope === 'mine' ? 'mine' : '' } });
    const now = new Date();
    const endToday = new Date(); endToday.setHours(23, 59, 59, 999);
    const groups = [
      ['Atrasados', res.data.filter((a) => new Date(a.due_at.replace(' ', 'T')) < now), 'neg'],
      ['Hoje', res.data.filter((a) => { const d = new Date(a.due_at.replace(' ', 'T')); return d >= now && d <= endToday; }), ''],
      ['Próximos', res.data.filter((a) => new Date(a.due_at.replace(' ', 'T')) > endToday), 'muted'],
    ];
    body.innerHTML = res.data.length ? groups.filter(([, items]) => items.length).map(([title, items, cls]) => `
      <section class="card" style="margin-bottom:16px"><div class="card-head"><h3 class="${cls}">${title} <span class="badge">${items.length}</span></h3></div>
        <ul class="list">${items.map((a) => `
          <li data-id="${a.id}">
            <button class="btn btn-xs btn-success" data-done title="Concluir">${icon('check')}</button>
            <div class="grow"><b style="white-space:normal">${esc(a.body)}</b>
              <small>${badge('activity_kind', a.kind)} ${ENTITY[a.entity] || ''}: <a href="${LINKS[a.entity]?.(a.entity_id) || '#'}">${esc(a.entity_name || '#' + a.entity_id)}</a> · por ${esc(a.user_name || '—')}</small></div>
            <span class="nowrap small ${cls}">${icon('clock')} ${datetime(a.due_at)}</span>
          </li>`).join('')}</ul></section>`).join('') : emptyState('Nenhum follow-up pendente. 🎉 Registre próximos passos nas fichas de clientes, leads e projetos.', 'bell');
  }
  body.addEventListener('click', async (e) => {
    const li = e.target.closest('[data-id]');
    if (!li || !e.target.closest('[data-done]')) return;
    try {
      await api('/activities/' + li.dataset.id, { method: 'PUT', body: { done: true } });
      li.style.opacity = '.4';
      toast('Follow-up concluído.');
      window.dispatchEvent(new Event('ic:refresh-counts'));
      setTimeout(load, 300);
    } catch (err) { toastError(err); }
  });
  $$('[data-scope]', el).forEach((b) => b.addEventListener('click', () => { scope = b.dataset.scope; localStorage.setItem('ic-followups-scope', scope); load(); }));
  await load();
}
