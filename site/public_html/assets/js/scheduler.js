/* Appointment scheduler: month calendar, live slot availability, .ics export. */
(() => {
  'use strict';
  const root = document.getElementById('scheduler');
  if (!root) return;
  const $ = (s, r = root) => r.querySelector(s);
  const form = document.getElementById('appointment-form');
  const grid = $('[data-cal-grid]');
  const title = $('[data-cal-title]');
  const slotsEl = $('[data-slots]');
  const slotTitle = $('[data-slot-title]');
  const label = $('[data-selected-label]', form);

  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const maxDate = new Date(today); maxDate.setDate(maxDate.getDate() + 60);
  let view = new Date(today.getFullYear(), today.getMonth(), 1);
  let selectedDate = null;
  let selectedTime = null;

  function render() {
    const monthLabel = view.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    title.textContent = monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1);
    grid.innerHTML = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map((d) => `<div class="dow" aria-hidden="true">${d}</div>`).join('');
    const first = new Date(view);
    for (let i = 0; i < first.getDay(); i++) grid.insertAdjacentHTML('beforeend', '<span></span>');
    const days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
    for (let d = 1; d <= days; d++) {
      const date = new Date(view.getFullYear(), view.getMonth(), d);
      const weekend = date.getDay() === 0 || date.getDay() === 6;
      const disabled = weekend || date < today || date > maxDate;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cal-day' + (date.getTime() === today.getTime() ? ' today' : '') + (selectedDate === iso(date) ? ' selected' : '');
      btn.textContent = d;
      btn.disabled = disabled;
      btn.setAttribute('aria-label', date.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' }) + (disabled ? ' (indisponível)' : ''));
      btn.addEventListener('click', () => selectDate(iso(date)));
      grid.appendChild(btn);
    }
    $('[data-cal-prev]').disabled = view <= new Date(today.getFullYear(), today.getMonth(), 1);
    $('[data-cal-next]').disabled = new Date(view.getFullYear(), view.getMonth() + 1, 1) > maxDate;
  }

  async function selectDate(date) {
    selectedDate = date;
    selectedTime = null;
    updateLabel();
    render();
    const nice = new Date(date + 'T12:00:00').toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
    slotTitle.textContent = 'Horários para ' + nice;
    slotsEl.innerHTML = '<span class="muted">Carregando horários...</span>';
    try {
      const res = await window.icApi('/api/public/slots?date=' + date);
      if (!res.slots.length || !res.slots.some((s) => s.available)) {
        slotsEl.innerHTML = '<span class="muted">Sem horários livres neste dia. Escolha outra data.</span>';
        return;
      }
      slotsEl.innerHTML = '';
      res.slots.forEach((s) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'slot';
        b.textContent = s.time;
        b.disabled = !s.available;
        b.addEventListener('click', () => {
          selectedTime = s.time;
          slotsEl.querySelectorAll('.slot').forEach((x) => x.classList.toggle('selected', x === b));
          updateLabel();
          if (window.innerWidth < 1100) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        slotsEl.appendChild(b);
      });
    } catch (e) {
      slotsEl.innerHTML = '<span class="muted">Não foi possível carregar os horários.</span>';
    }
  }

  function updateLabel() {
    form.elements.date.value = selectedDate || '';
    form.elements.time.value = selectedTime || '';
    label.lastChild.textContent = selectedDate && selectedTime
      ? ' ' + new Date(selectedDate + 'T12:00:00').toLocaleDateString('pt-BR') + ' às ' + selectedTime
      : ' Nenhum horário selecionado';
  }

  $('[data-cal-prev]').addEventListener('click', () => { view.setMonth(view.getMonth() - 1); render(); });
  $('[data-cal-next]').addEventListener('click', () => { view.setMonth(view.getMonth() + 1); render(); });

  form.addEventListener('submit', (e) => {
    if (!selectedDate || !selectedTime) {
      e.stopImmediatePropagation();
      e.preventDefault();
      window.icToast('Escolha um dia e um horário no calendário.', 'error');
      root.scrollIntoView({ behavior: 'smooth' });
    }
  }, true);

  form.addEventListener('ic:sent', () => {
    const when = new Date(selectedDate + 'T' + selectedTime + ':00');
    form.querySelector('[data-confirm-text]').textContent =
      `Te esperamos em ${when.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })} às ${selectedTime}. Enviaremos a confirmação por e-mail/WhatsApp.`;
    form.querySelector('[data-ics]').addEventListener('click', () => {
      const end = new Date(when.getTime() + 60 * 60000);
      const fmt = (d) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
      const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Integra Code//Agenda//PT', 'BEGIN:VEVENT',
        'UID:' + Date.now() + '@integra-code.tech', 'DTSTAMP:' + fmt(new Date()), 'DTSTART:' + fmt(when), 'DTEND:' + fmt(end),
        'SUMMARY:Conversa com a Integra Code', 'DESCRIPTION:' + form.elements.topic.value, 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
      a.download = 'integra-code-reuniao.ics';
      a.click();
    });
  });

  render();
  // Preselect the next business day for convenience.
  const next = new Date(today);
  do { next.setDate(next.getDate() + 1); } while (next.getDay() === 0 || next.getDay() === 6);
  if (next.getMonth() !== view.getMonth()) { view = new Date(next.getFullYear(), next.getMonth(), 1); }
  selectDate(iso(next));
})();
