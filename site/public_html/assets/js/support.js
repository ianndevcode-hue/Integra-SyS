/* Help center: live search, category filter, ticket protocol display, ticket lookup + reply. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (d) => new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  const statusLabel = { open: 'Aberto', in_progress: 'Em atendimento', waiting: 'Aguardando você', waiting_third: 'Aguardando terceiros', on_hold: 'Pausado', resolved: 'Resolvido', closed: 'Encerrado' };
  const preScore = Math.max(0, Math.min(5, parseInt(new URLSearchParams(location.search).get('avaliar') || '0', 10) || 0));

  /* live search */
  const q = $('#help-q');
  const results = $('#help-results');
  let t;
  function openArticle(id) {
    const item = document.getElementById('artigo-' + id);
    if (!item) return;
    $$('[data-help-filter]').forEach((c) => c.classList.toggle('active', c.dataset.helpFilter === ''));
    $$('#faq-list .acc-item').forEach((i) => { i.style.display = ''; });
    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (!item.classList.contains('open')) $('.acc-btn', item).click();
    item.animate([{ boxShadow: '0 0 0 3px rgba(0,102,254,.7)' }, { boxShadow: '0 0 0 0 transparent' }], { duration: 1600 });
  }
  if (q) {
    q.addEventListener('input', () => {
      clearTimeout(t);
      const term = q.value.trim();
      if (term.length < 2) { results.classList.remove('show'); return; }
      t = setTimeout(async () => {
        try {
          const res = await window.icApi('/api/public/help?q=' + encodeURIComponent(term));
          results.innerHTML = res.data.length
            ? res.data.map((a) => `<a href="#artigo-${a.id}" data-id="${a.id}" role="option"><b>${esc(a.question)}</b><small>${esc(a.answer)}</small></a>`).join('')
            : `<div class="empty">Nada encontrado para "${esc(term)}". <a href="#chamado" style="display:inline;padding:0;border:0;color:var(--primary)">Abra um chamado</a> ou fale conosco pelo WhatsApp.</div>`;
          results.classList.add('show');
        } catch (e) { /* ignore */ }
      }, 220);
    });
    results.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-id]');
      if (!a) return;
      e.preventDefault();
      results.classList.remove('show');
      openArticle(a.dataset.id);
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.help-search')) results.classList.remove('show'); });
    q.addEventListener('keydown', (e) => { if (e.key === 'Escape') results.classList.remove('show'); });
  }

  /* category filter */
  $$('[data-help-filter]').forEach((chip) => chip.addEventListener('click', () => {
    $$('[data-help-filter]').forEach((c) => c.classList.toggle('active', c === chip));
    const cat = chip.dataset.helpFilter;
    $$('#faq-list .acc-item').forEach((i) => { i.style.display = !cat || i.dataset.category === cat ? '' : 'none'; });
  }));
  if (location.hash.startsWith('#artigo-')) setTimeout(() => openArticle(location.hash.slice(8)), 400);

  /* protocol after ticket creation */
  const ticketForm = $('#ticket-form');
  ticketForm && ticketForm.addEventListener('ic:sent', (e) => {
    $('#ticket-protocol').textContent = e.detail.protocol;
    $('#l-protocol').value = e.detail.protocol;
    $('#l-email').value = ticketForm.elements.email.value;
  });

  /* lookup */
  const lookup = $('#lookup-form');
  const view = $('#ticket-view');
  async function load(protocol, email) {
    const btn = $('button', lookup);
    btn.classList.add('loading');
    try {
      const tk = await window.icApi(`/api/public/tickets/${encodeURIComponent(protocol)}?email=${encodeURIComponent(email)}`);
      view.style.display = 'block';
      view.innerHTML = `
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
          <div><small class="muted">Protocolo ${esc(tk.protocol)} · aberto em ${fmt(tk.created_at)}</small><h3 style="margin:4px 0 0">${esc(tk.subject)}</h3></div>
          <span class="badge ${esc(tk.status)}">${statusLabel[tk.status] || esc(tk.status_label || tk.status)}</span>
        </div>
        ${['resolved', 'closed'].includes(tk.status) && tk.satisfaction == null ? `
        <form class="rate-box" id="rate-form">
          <b>Como foi o atendimento?</b>
          <div class="stars" role="radiogroup" aria-label="Nota de 1 a 5">${[5, 4, 3, 2, 1].map((i) => `<input type="radio" id="rs${i}" name="score" value="${i}" ${preScore === i ? 'checked' : ''} required><label for="rs${i}" title="${i} de 5">★</label>`).join('')}</div>
          <input name="comment" maxlength="2000" placeholder="Comentário (opcional)" aria-label="Comentário">
          <button class="btn btn-primary btn-sm">Enviar avaliação</button>
        </form>` : tk.satisfaction != null ? `<p class="rate-done">Sua avaliação: <span>${'★'.repeat(tk.satisfaction)}<i>${'★'.repeat(5 - tk.satisfaction)}</i></span></p>` : ''}
        <div class="ticket-thread">${tk.messages.map((m) => `
          <div class="msg ${m.author_type === 'staff' ? 'staff' : ''}">
            <header><b>${m.author_type === 'staff' ? '🛠️ ' + esc(m.author_name || 'Equipe Integra') : esc(m.author_name || 'Você')}</b><span>${fmt(m.created_at)}</span></header>
            <p>${esc(m.body)}</p>
          </div>`).join('')}</div>
        ${tk.status === 'closed' ? '<p class="muted">Este chamado foi encerrado. Se precisar, abra um novo chamado.</p>' : `
        <form class="form" id="reply-form">
          <div class="field"><label for="r-msg">${tk.status === 'resolved' ? 'Ainda precisa de ajuda? Responda para reabrir' : 'Responder'}</label><textarea id="r-msg" name="message" required placeholder="Escreva sua mensagem..."></textarea></div>
          <button class="btn btn-primary">Enviar resposta</button>
        </form>`}`;
      const rate = $('#rate-form', view);
      rate && rate.addEventListener('submit', async (e) => {
        e.preventDefault();
        const score = rate.querySelector('input[name=score]:checked');
        if (!score) { window.icToast('Escolha de 1 a 5 estrelas.', 'error'); return; }
        $('button', rate).classList.add('loading');
        try {
          await window.icApi(`/api/public/tickets/${encodeURIComponent(protocol)}/rate`, { method: 'POST', body: { email, score: +score.value, comment: rate.elements.comment.value } });
          window.icToast('Obrigado pela avaliação! 💙', 'success');
          load(protocol, email);
        } catch (err) { window.icToast(err.message, 'error'); $('button', rate).classList.remove('loading'); }
      });
      const reply = $('#reply-form', view);
      reply && reply.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = reply.elements.message.value.trim();
        if (!msg) return;
        $('button', reply).classList.add('loading');
        try {
          await window.icApi(`/api/public/tickets/${encodeURIComponent(protocol)}/reply`, { method: 'POST', body: { email, message: msg } });
          window.icToast('Resposta enviada!', 'success');
          load(protocol, email);
        } catch (err) { window.icToast(err.message, 'error'); $('button', reply).classList.remove('loading'); }
      });
    } catch (err) {
      view.style.display = 'block';
      view.innerHTML = `<p style="margin:0">${esc(err.message)}</p>`;
    } finally {
      btn.classList.remove('loading');
    }
  }
  const params = new URLSearchParams(location.search);
  if (lookup && params.get('protocolo') && params.get('email')) {
    lookup.elements.protocol.value = params.get('protocolo');
    lookup.elements.email.value = params.get('email');
    setTimeout(() => load(params.get('protocolo').toUpperCase(), params.get('email')), 300);
  }
  lookup && lookup.addEventListener('submit', (e) => {
    e.preventDefault();
    const protocol = lookup.elements.protocol.value.trim().toUpperCase();
    const email = lookup.elements.email.value.trim();
    if (!protocol || !email) { window.icToast('Informe o protocolo e o e-mail.', 'error'); return; }
    load(protocol, email);
  });
})();
