import { api, $, $$, esc, icon, emptyState } from '../core.js';

const TYPES = {
  meeting: ['Reuniões', 'blue'], followup: ['Follow-ups', 'yellow'], receivable: ['A receber', 'green'], payable: ['A pagar', 'red'], deadline: ['Entregas de projeto', 'violet'], stage: ['Prazos de etapas', 'orange'],
};
const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export async function render(el, ctx) {
  let base = ctx.query.m ? new Date(ctx.query.m + '-01T12:00:00') : new Date();
  base = new Date(base.getFullYear(), base.getMonth(), 1);
  let hidden = new Set(JSON.parse(localStorage.getItem('ic-cal-hidden') || '[]'));
  el.innerHTML = `
    <div class="page-head"><div><h2>Calendário</h2><p>Reuniões, follow-ups, vencimentos e prazos em um só lugar.</p></div>
      <div class="page-actions"><button class="btn btn-icon" data-prev aria-label="Mês anterior">${icon('arrowDown')}</button><b class="cal-title" data-title></b><button class="btn btn-icon" data-next aria-label="Próximo mês">${icon('arrowUp')}</button><button class="btn" data-today>Hoje</button></div></div>
    <div class="chips-row" data-types>${Object.entries(TYPES).map(([k, [l, c]]) => `<button class="chip ${hidden.has(k) ? '' : 'on'}" data-type="${k}"><i class="dot ${c}"></i>${l}</button>`).join('')}</div>
    <div data-cal><div class="loading-box">Carregando...</div></div>`;
  $('[data-prev]', el).innerHTML = '‹';
  $('[data-next]', el).innerHTML = '›';
  const box = $('[data-cal]', el);

  async function load() {
    const first = new Date(base);
    const start = new Date(first); start.setDate(1 - ((first.getDay() + 6) % 7));
    const end = new Date(start); end.setDate(start.getDate() + 41);
    $('[data-title]', el).textContent = MONTHS[base.getMonth()] + ' de ' + base.getFullYear();
    history.replaceState(null, '', '#/calendar?m=' + iso(base).slice(0, 7));
    const res = await api('/calendar', { query: { from: iso(start), to: iso(end) } });
    const events = res.events.filter((e) => !hidden.has(e.type));
    const byDay = {};
    events.forEach((e) => { (byDay[e.date] = byDay[e.date] || []).push(e); });
    const todayIso = iso(new Date());
    const days = [];
    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) days.push(new Date(d));
    const inMonth = events.filter((e) => e.date.slice(0, 7) === iso(base).slice(0, 7));
    box.innerHTML = `
      <div class="card cal">
        <div class="cal-head">${['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map((d) => `<span>${d}</span>`).join('')}</div>
        <div class="cal-grid">${days.map((d) => {
          const k = iso(d);
          const evs = byDay[k] || [];
          return `<div class="cal-day ${d.getMonth() !== base.getMonth() ? 'out' : ''} ${k === todayIso ? 'today' : ''}"><span class="cal-n">${d.getDate()}</span>
            ${evs.slice(0, 4).map((e) => `<a class="cal-ev ${TYPES[e.type]?.[1] || ''}" href="${esc(e.url)}" title="${esc(e.title + (e.sub ? ' — ' + e.sub : ''))}">${e.time ? `<b>${esc(e.time)}</b> ` : ''}${esc(e.title)}</a>`).join('')}
            ${evs.length > 4 ? `<button class="cal-more" data-day="${k}">+${evs.length - 4} mais</button>` : ''}</div>`;
        }).join('')}</div>
      </div>
      <section class="card cal-list"><div class="card-head"><h3>Agenda do mês</h3><span class="muted small">${inMonth.length} item(ns)</span></div>
        ${inMonth.length ? `<ul class="list">${inMonth.map((e) => `<li><span class="dot ${TYPES[e.type]?.[1] || ''}"></span><div class="grow"><a href="${esc(e.url)}"><b style="white-space:normal">${esc(e.title)}</b></a><small>${esc(TYPES[e.type]?.[0] || '')}${e.sub ? ' · ' + esc(e.sub) : ''}</small></div><span class="nowrap small muted">${e.date.split('-').reverse().slice(0, 2).join('/')}${e.time ? ' ' + esc(e.time) : ''}</span></li>`).join('')}</ul>` : emptyState('Nada agendado neste mês.', 'calendar')}
      </section>`;
    $$('[data-day]', box).forEach((b) => b.addEventListener('click', () => {
      const evs = byDay[b.dataset.day];
      b.parentElement.innerHTML = `<span class="cal-n">${+b.dataset.day.slice(8)}</span>` + evs.map((e) => `<a class="cal-ev ${TYPES[e.type]?.[1] || ''}" href="${esc(e.url)}">${e.time ? `<b>${esc(e.time)}</b> ` : ''}${esc(e.title)}</a>`).join('');
    }));
  }
  $('[data-prev]', el).addEventListener('click', () => { base = new Date(base.getFullYear(), base.getMonth() - 1, 1); load(); });
  $('[data-next]', el).addEventListener('click', () => { base = new Date(base.getFullYear(), base.getMonth() + 1, 1); load(); });
  $('[data-today]', el).addEventListener('click', () => { const t = new Date(); base = new Date(t.getFullYear(), t.getMonth(), 1); load(); });
  $$('[data-type]', el).forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.type;
    hidden.has(k) ? hidden.delete(k) : hidden.add(k);
    b.classList.toggle('on', !hidden.has(k));
    try { localStorage.setItem('ic-cal-hidden', JSON.stringify([...hidden])); } catch (e) { /* ignore */ }
    load();
  }));
  await load();
}
