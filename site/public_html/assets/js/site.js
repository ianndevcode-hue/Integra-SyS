/* Integra Code — public site interactions (vanilla JS, no dependencies). */
(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = window.matchMedia('(pointer: fine)').matches;
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ------------------------------------------------------------ toast */
  function toast(message, type = '') {
    const wrap = $('.toast-wrap');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = message;
    wrap.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .4s'; }, 4200);
    setTimeout(() => el.remove(), 4700);
  }
  window.icToast = toast;

  /* ------------------------------------------------------------ api */
  async function api(url, options = {}) {
    const res = await fetch(url, {
      method: options.method || 'GET',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-json */ }
    if (!res.ok) {
      const err = new Error(data.error || 'Não foi possível concluir. Tente novamente.');
      err.fields = data.fields || {};
      err.status = res.status;
      throw err;
    }
    return data;
  }
  window.icApi = api;

  /* ------------------------------------------------------------ theme */
  const themeBtn = $('.theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
      document.documentElement.dataset.theme = next;
      store.set('ic-theme', next);
      $('meta[name="theme-color"]').setAttribute('content', next === 'light' ? '#f7f8fb' : '#050a16');
    });
  }

  /* ------------------------------------------------------------ header / scroll */
  const header = $('.site-header');
  const progress = $('.scroll-progress');
  const toTop = $('.to-top');
  let lastY = 0;
  let ticking = false;
  const parallaxEls = $$('[data-parallax]');
  const timelines = $$('[data-timeline]');

  function onScroll() {
    const y = window.scrollY;
    const h = document.documentElement.scrollHeight - window.innerHeight;
    if (progress) progress.style.transform = `scaleX(${h > 0 ? y / h : 0})`;
    if (header) {
      header.classList.toggle('scrolled', y > 20);
      header.classList.toggle('hidden', y > 400 && y > lastY && !document.body.classList.contains('drawer-open'));
    }
    if (toTop) toTop.classList.toggle('show', y > 800);
    lastY = y;

    if (!reduced) {
      const vh = window.innerHeight;
      parallaxEls.forEach((el) => {
        const rect = el.parentElement.getBoundingClientRect();
        if (rect.bottom < -200 || rect.top > vh + 200) return;
        const speed = parseFloat(el.dataset.parallax) || 0.2;
        const offset = (rect.top + rect.height / 2 - vh / 2) * speed;
        const rot = el.dataset.rotate ? `rotate(${offset * parseFloat(el.dataset.rotate)}deg)` : '';
        el.style.transform = `translate3d(0, ${offset.toFixed(1)}px, 0) ${rot}`;
      });
    }

    timelines.forEach((tl) => {
      const rect = tl.getBoundingClientRect();
      const vh = window.innerHeight;
      const p = Math.min(1, Math.max(0, (vh * 0.75 - rect.top) / rect.height));
      tl.style.setProperty('--p', p.toFixed(3));
      $$('.step', tl).forEach((step, i, all) => step.classList.toggle('lit', p >= (i + 0.2) / all.length));
    });
    ticking = false;
  }
  window.addEventListener('scroll', () => { if (!ticking) { requestAnimationFrame(onScroll); ticking = true; } }, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();
  if (toTop) toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' }));

  /* ------------------------------------------------------------ fit nav */
  // Collapse the desktop menu into the hamburger whenever it would collide with the logo or actions.
  function fitNav() {
    if (!header) return;
    const nav = $('.main-nav', header);
    const brand = $('.brand', header);
    const actions = $('.header-actions', header);
    if (!nav || !brand || !actions) return;
    header.classList.remove('nav-collapsed');
    if (getComputedStyle(nav).display === 'none') return;
    const inner = $('.header-inner', header).getBoundingClientRect();
    const needed = brand.getBoundingClientRect().width + $('ul', nav).scrollWidth + actions.getBoundingClientRect().width + 72;
    header.classList.toggle('nav-collapsed', needed > inner.width);
  }
  fitNav();
  window.addEventListener('resize', fitNav);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNav);
  window.addEventListener('load', fitNav);

  /* ------------------------------------------------------------ drawer */
  const drawer = $('#drawer');
  const navToggle = $('.nav-toggle');
  function setDrawer(open) {
    if (!drawer) return;
    drawer.classList.toggle('open', open);
    drawer.setAttribute('aria-hidden', String(!open));
    navToggle && navToggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('drawer-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) setTimeout(() => $('nav a', drawer)?.focus(), 300);
  }
  navToggle && navToggle.addEventListener('click', () => setDrawer(true));
  $$('[data-close-drawer]').forEach((b) => b.addEventListener('click', () => setDrawer(false)));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { setDrawer(false); setChat(false); } });

  /* ------------------------------------------------------------ reveal */
  const revealEls = $$('[data-reveal], .split-line, [data-stagger]');
  $$('[data-stagger]').forEach((group) => {
    Array.from(group.children).forEach((child, i) => {
      if (!child.hasAttribute('data-reveal')) child.setAttribute('data-reveal', '');
      child.style.setProperty('--d', (i * (parseFloat(group.dataset.stagger) || 0.08)).toFixed(2) + 's');
    });
  });
  if ('IntersectionObserver' in window && !reduced) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          $$('[data-reveal]', entry.target).forEach(() => {});
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    $$('[data-reveal], .split-line').forEach((el) => io.observe(el));
  } else {
    revealEls.forEach((el) => el.classList.add('in'));
    $$('[data-reveal]').forEach((el) => el.classList.add('in'));
  }

  /* ------------------------------------------------------------ counters */
  const counters = $$('[data-count]');
  if (counters.length) {
    const run = (el) => {
      const target = parseFloat(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      const prefix = el.dataset.prefix || '';
      if (reduced) { el.textContent = prefix + target + suffix; return; }
      const start = performance.now();
      const dur = 1800;
      const tick = (now) => {
        const t = Math.min(1, (now - start) / dur);
        const eased = 1 - Math.pow(1 - t, 4);
        el.textContent = prefix + Math.round(target * eased) + suffix;
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };
    const cio = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) { run(e.target); cio.unobserve(e.target); } }), { threshold: 0.5 });
    counters.forEach((c) => cio.observe(c));
  }

  /* ------------------------------------------------------------ pointer effects */
  if (finePointer && !reduced) {
    const glow = $('.cursor-glow');
    let gx = 0, gy = 0, cx = 0, cy = 0;
    window.addEventListener('pointermove', (e) => { gx = e.clientX; gy = e.clientY; }, { passive: true });
    const loop = () => {
      cx += (gx - cx) * 0.12; cy += (gy - cy) * 0.12;
      if (glow) glow.style.transform = `translate3d(${cx}px, ${cy}px, 0)`;
      requestAnimationFrame(loop);
    };
    loop();

    $$('.spotlight').forEach((card) => {
      card.addEventListener('pointermove', (e) => {
        const r = card.getBoundingClientRect();
        card.style.setProperty('--x', `${e.clientX - r.left}px`);
        card.style.setProperty('--y', `${e.clientY - r.top}px`);
      });
    });

    $$('.magnetic').forEach((btn) => {
      btn.addEventListener('pointermove', (e) => {
        const r = btn.getBoundingClientRect();
        btn.style.setProperty('--bx', `${(e.clientX - r.left - r.width / 2) * 0.25}px`);
        btn.style.setProperty('--by', `${(e.clientY - r.top - r.height / 2) * 0.35}px`);
      });
      btn.addEventListener('pointerleave', () => { btn.style.setProperty('--bx', '0px'); btn.style.setProperty('--by', '0px'); });
    });

    $$('[data-tilt]').forEach((el) => {
      const max = parseFloat(el.dataset.tilt) || 8;
      el.style.transition = 'transform .3s ease-out';
      el.addEventListener('pointermove', (e) => {
        const r = el.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;
        el.style.transform = `perspective(900px) rotateX(${(-py * max).toFixed(2)}deg) rotateY(${(px * max).toFixed(2)}deg) translateY(-4px)`;
      });
      el.addEventListener('pointerleave', () => { el.style.transform = ''; });
    });
  } else {
    const glow = $('.cursor-glow');
    if (glow) glow.style.display = 'none';
  }

  /* ------------------------------------------------------------ tabs (generic) */
  $$('[data-tabs]').forEach((root) => {
    const buttons = $$('[data-tab]', root);
    const panels = $$('[data-panel]', root);
    const activate = (id, focus) => {
      buttons.forEach((b) => { const on = b.dataset.tab === id; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; if (on && focus) b.focus(); });
      panels.forEach((p) => p.classList.toggle('active', p.dataset.panel === id));
    };
    buttons.forEach((b, i) => {
      b.addEventListener('click', () => activate(b.dataset.tab));
      b.addEventListener('keydown', (e) => {
        const dir = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
        if (!dir) return;
        e.preventDefault();
        activate(buttons[(i + dir + buttons.length) % buttons.length].dataset.tab, true);
      });
    });
    const fromHash = location.hash.slice(1);
    if (fromHash && buttons.some((b) => b.dataset.tab === fromHash)) activate(fromHash);
  });

  /* ------------------------------------------------------------ accordion */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.acc-btn');
    if (!btn) return;
    const item = btn.closest('.acc-item');
    const open = !item.classList.contains('open');
    if (item.parentElement.dataset.single !== undefined) $$('.acc-item.open', item.parentElement).forEach((i) => { i.classList.remove('open'); $('.acc-btn', i).setAttribute('aria-expanded', 'false'); });
    item.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', String(open));
    if (open && item.dataset.articleId && !item.dataset.viewed) {
      item.dataset.viewed = '1';
      fetch(`/api/public/help/${item.dataset.articleId}/view`, { method: 'POST' }).catch(() => {});
    }
  });
  document.addEventListener('click', (e) => {
    const vote = e.target.closest('[data-vote]');
    if (!vote) return;
    const item = vote.closest('.acc-item');
    api(`/api/public/help/${item.dataset.articleId}/vote`, { method: 'POST', body: { helpful: vote.dataset.vote === '1' } }).catch(() => {});
    vote.parentElement.innerHTML = '<span class="muted">Obrigado pelo feedback! 💛</span>';
  });

  /* ------------------------------------------------------------ forms */
  function clearErrors(form) {
    $$('.field.invalid', form).forEach((f) => f.classList.remove('invalid'));
    $$('.field .err', form).forEach((e) => e.remove());
  }
  function showErrors(form, fields) {
    Object.entries(fields || {}).forEach(([name, msg]) => {
      const input = form.elements[name];
      const field = input && (input.closest ? input.closest('.field') : null);
      if (field) {
        field.classList.add('invalid');
        const s = document.createElement('span');
        s.className = 'err';
        s.textContent = msg;
        field.appendChild(s);
      } else {
        toast(msg, 'error');
      }
    });
    const first = $('.field.invalid input, .field.invalid select, .field.invalid textarea', form);
    first && first.focus();
  }
  function formData(form) {
    const data = {};
    Array.from(form.elements).forEach((el) => {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox') data[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; }
      else data[el.name] = el.value;
    });
    return data;
  }
  window.icFormData = formData;
  window.icShowErrors = showErrors;
  window.icClearErrors = clearErrors;

  $$('form[data-api-form]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(form);
      if (!form.checkValidity()) {
        const invalid = Array.from(form.elements).filter((el) => el.willValidate && !el.checkValidity());
        const fields = {};
        invalid.forEach((el) => { fields[el.name] = el.validity.valueMissing ? 'Campo obrigatório.' : el.validity.typeMismatch ? 'Formato inválido.' : el.validationMessage; });
        showErrors(form, fields);
        return;
      }
      const btn = $('button[type="submit"], button:not([type])', form);
      btn && btn.classList.add('loading');
      try {
        // forms may expose extra payload via form.icExtra = () => ({...})
        const body = Object.assign(formData(form), typeof form.icExtra === 'function' ? form.icExtra() : {});
        const res = await api(form.dataset.apiForm, { method: 'POST', body });
        if (form.dataset.reset !== undefined) { form.reset(); toast(res.message || 'Enviado com sucesso!', 'success'); }
        else form.classList.add('sent');
        form.dispatchEvent(new CustomEvent('ic:sent', { detail: res }));
      } catch (err) {
        showErrors(form, err.fields);
        if (!Object.keys(err.fields || {}).length) toast(err.message, 'error');
      } finally {
        btn && btn.classList.remove('loading');
      }
    });
  });

  // Phone mask (BR)
  $$('input[data-mask="phone"]').forEach((input) => {
    input.addEventListener('input', () => {
      const d = input.value.replace(/\D/g, '').slice(0, 11);
      input.value = d.length > 10 ? d.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3')
        : d.length > 6 ? d.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3')
        : d.length > 2 ? d.replace(/(\d{2})(\d{0,5})/, '($1) $2') : d;
    });
  });

  /* ------------------------------------------------------------ chat widget */
  const chatBox = $('#chat-box');
  const chatBtn = $('.fab-chat');
  const chatBody = chatBox && $('.chat-body', chatBox);
  let chatStarted = false;

  const chatHistory = [];
  // Minimal, safe formatting for assistant replies: escape first, then **bold** and line breaks.
  const formatReply = (text) => escapeHtml(text).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');

  function addLine(text, who, actions) {
    const line = document.createElement('div');
    line.className = 'chat-line ' + who;
    line.innerHTML = formatReply(text);
    chatBody.appendChild(line);
    if (actions && actions.length) {
      const wrap = document.createElement('div');
      wrap.className = 'chat-actions';
      actions.forEach((a) => {
        let el;
        if (a.url) {
          el = document.createElement('a');
          el.href = a.url;
          if (/^https?:/.test(a.url)) { el.target = '_blank'; el.rel = 'noopener'; }
        } else {
          el = document.createElement('button');
          el.type = 'button';
          el.addEventListener('click', () => ask(a.ask || a.label));
        }
        el.textContent = a.label;
        wrap.appendChild(el);
      });
      chatBody.appendChild(wrap);
    }
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  async function ask(message) {
    if (!message.trim()) return;
    addLine(message, 'user');
    const typing = document.createElement('div');
    typing.className = 'chat-line bot';
    typing.innerHTML = '<span class="typing"><i></i><i></i><i></i></span>';
    chatBody.appendChild(typing);
    chatBody.scrollTop = chatBody.scrollHeight;
    try {
      const [res] = await Promise.all([api('/api/public/chat', { method: 'POST', body: { message, history: chatHistory.slice(-8) } }), new Promise((r) => setTimeout(r, 500))]);
      typing.remove();
      addLine(res.reply, 'bot', res.actions);
      chatHistory.push({ role: 'user', content: message }, { role: 'assistant', content: res.reply });
    } catch (err) {
      typing.remove();
      addLine('Ops! Não consegui responder agora. Fale com a gente pelo WhatsApp ' + (window.IC?.phone || '') + '.', 'bot', [{ label: 'Abrir WhatsApp', url: window.IC?.whatsapp }]);
    }
  }

  function setChat(open) {
    if (!chatBox) return;
    chatBox.classList.toggle('open', open);
    chatBtn && chatBtn.setAttribute('aria-expanded', String(open));
    if (open) {
      $('.badge-dot', chatBtn)?.remove();
      if (!chatStarted) {
        chatStarted = true;
        addLine(window.IC?.ai?.chat ? 'Olá! 👋 Sou o assistente virtual da Integra Code, com inteligência artificial. Pergunte sobre sistemas, integrações, prazos, suporte... Como posso ajudar?' : 'Olá! 👋 Sou o assistente virtual da Integra Code. Como posso ajudar?', 'bot', [
          { label: 'Quanto custa um sistema?', ask: 'Quanto custa um sistema?' },
          { label: 'Prazo de desenvolvimento', ask: 'Quanto tempo leva para desenvolver um sistema?' },
          { label: 'Preciso de suporte', ask: 'Preciso abrir um chamado de suporte' },
          { label: 'Falar com humano', ask: 'Quero falar com um atendente' },
        ]);
      }
      setTimeout(() => $('input', chatBox).focus(), 250);
    }
  }
  chatBtn && chatBtn.addEventListener('click', () => setChat(!chatBox.classList.contains('open')));
  $$('[data-close-chat]').forEach((b) => b.addEventListener('click', () => setChat(false)));
  $$('[data-open-chat]').forEach((b) => b.addEventListener('click', (e) => { e.preventDefault(); setChat(true); }));
  chatBox && $('.chat-form', chatBox).addEventListener('submit', (e) => {
    e.preventDefault();
    const input = $('input', e.target);
    const msg = input.value;
    input.value = '';
    ask(msg);
  });

  /* ------------------------------------------------------------ cookie bar */
  const cookieBar = $('.cookie-bar');
  if (cookieBar && !store.get('ic-cookie-ok')) setTimeout(() => cookieBar.classList.add('show'), 1500);
  $('[data-cookie-ok]')?.addEventListener('click', () => { store.set('ic-cookie-ok', '1'); cookieBar.classList.remove('show'); });

  /* ------------------------------------------------------------ segments auto (mobile flip) */
  $$('.flip').forEach((f) => f.addEventListener('click', () => f.classList.toggle('flipped')));

  /* ------------------------------------------------------------ side nav scrollspy */
  const sideLinks = $$('.side-nav a[href^="#"]');
  if (sideLinks.length) {
    const map = new Map(sideLinks.map((a) => [a.getAttribute('href').slice(1), a]));
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (!e.isIntersecting) return;
        sideLinks.forEach((a) => a.classList.remove('active'));
        const link = map.get(e.target.id);
        if (link) { link.classList.add('active'); link.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }); }
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    map.forEach((_, id) => { const s = document.getElementById(id); s && spy.observe(s); });
  }

  /* ------------------------------------------------------------ AI demo (home) */
  const aiDemo = $('[data-ai-demo]');
  if (aiDemo) {
    const log = $('.ai-log', aiDemo);
    const answers = {
      'Quanto vendi este mês?': 'Neste mês você faturou R$ 184.320, alta de 12% sobre o mês anterior. A filial Centro representa 46% das vendas. 📈',
      'Quais clientes estão inadimplentes?': 'Há 7 clientes com cobranças vencidas, somando R$ 9.870. Já preparei lembretes por WhatsApp para enviar com um clique.',
      'Qual produto devo repor?': 'Pelo ritmo de vendas, "Arroz 5kg" acaba em 3 dias e "Café 500g" em 5. Sugiro um pedido de compra de 120 e 80 unidades.',
      'Previsão de caixa para 30 dias': 'Projeção: entradas de R$ 212 mil e saídas de R$ 158 mil. Saldo estimado em 30 dias: R$ 96 mil, sem dias negativos. ✅',
    };
    const runQ = (q) => {
      const u = document.createElement('div');
      u.className = 'chat-line user';
      u.textContent = q;
      log.appendChild(u);
      const t = document.createElement('div');
      t.className = 'chat-line bot';
      t.innerHTML = '<span class="typing"><i></i><i></i><i></i></span>';
      log.appendChild(t);
      while (log.children.length > 6) log.firstElementChild.remove();
      setTimeout(() => {
        t.textContent = answers[q] || 'Consigo responder perguntas sobre vendas, estoque, clientes e financeiro usando os dados do seu sistema.';
      }, 1100);
    };
    $$('.ai-suggest button', aiDemo).forEach((b) => b.addEventListener('click', () => runQ(b.textContent)));
    $('form', aiDemo)?.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = $('input', e.target);
      if (input.value.trim()) runQ(input.value.trim());
      input.value = '';
    });
    const io2 = new IntersectionObserver((en) => { if (en[0].isIntersecting) { runQ('Quanto vendi este mês?'); io2.disconnect(); } }, { threshold: 0.4 });
    io2.observe(aiDemo);
  }
})();
