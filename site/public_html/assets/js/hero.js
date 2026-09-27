/* Interactive hero: particle network canvas, auto-rotating slides with typed words, 3D mouse parallax. */
(() => {
  'use strict';
  const hero = document.querySelector('.hero');
  if (!hero) return;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------ particle network */
  const canvas = hero.querySelector('.hero-canvas');
  if (canvas && canvas.getContext) {
    const ctx = canvas.getContext('2d');
    let w = 0, h = 0, dpr = 1, particles = [], running = true;
    const mouse = { x: -9999, y: -9999, active: false };

    const colors = () => document.documentElement.dataset.theme === 'light'
      ? { dot: 'rgba(0,87,224,', dot2: 'rgba(0,168,102,', line: 'rgba(20,50,110,' }
      : { dot: 'rgba(60,140,255,', dot2: 'rgba(0,207,129,', line: 'rgba(120,160,230,' };

    function resize() {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = hero.clientWidth;
      h = hero.clientHeight;
      canvas.width = w * dpr;
      canvas.height = h * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const count = Math.round(Math.min(110, (w * h) / 14000));
      particles = Array.from({ length: count }, () => ({
        x: Math.random() * w,
        y: Math.random() * h,
        vx: (Math.random() - 0.5) * 0.35,
        vy: (Math.random() - 0.5) * 0.35,
        r: Math.random() * 1.8 + 0.6,
        g: Math.random() < 0.35,
      }));
    }

    function frame() {
      if (!running) return;
      const c = colors();
      ctx.clearRect(0, 0, w, h);
      const linkDist = Math.min(150, w / 7);
      for (let i = 0; i < particles.length; i++) {
        const p = particles[i];
        if (!reduced) {
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > w) p.vx *= -1;
          if (p.y < 0 || p.y > h) p.vy *= -1;
          if (mouse.active) {
            const dx = mouse.x - p.x, dy = mouse.y - p.y;
            const d = Math.hypot(dx, dy);
            if (d < 180 && d > 1) { p.x += dx / d * 0.6; p.y += dy / d * 0.6; }
          }
        }
        for (let j = i + 1; j < particles.length; j++) {
          const q = particles[j];
          const d = Math.hypot(p.x - q.x, p.y - q.y);
          if (d < linkDist) {
            ctx.strokeStyle = c.line + (0.16 * (1 - d / linkDist)).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
        if (mouse.active) {
          const d = Math.hypot(p.x - mouse.x, p.y - mouse.y);
          if (d < 200) {
            ctx.strokeStyle = c.dot + (0.45 * (1 - d / 200)).toFixed(3) + ')';
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
          }
        }
        ctx.fillStyle = (p.g ? c.dot2 : c.dot) + '0.9)';
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill();
      }
      requestAnimationFrame(frame);
    }

    hero.addEventListener('pointermove', (e) => {
      const r = hero.getBoundingClientRect();
      mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; mouse.active = true;
    });
    hero.addEventListener('pointerleave', () => { mouse.active = false; });
    // Pause when hero is off-screen to save battery.
    new IntersectionObserver((en) => {
      const vis = en[0].isIntersecting;
      if (vis && !running) { running = true; requestAnimationFrame(frame); }
      running = vis;
    }).observe(hero);
    let rt;
    window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(resize, 150); });
    resize();
    requestAnimationFrame(frame);
  }

  /* ------------------------------------------------ slides + typed word */
  const tabs = Array.from(hero.querySelectorAll('.slide-tab'));
  const panels = Array.from(hero.querySelectorAll('.hv-panel'));
  const typedEl = hero.querySelector('.typed');
  const words = tabs.map((t) => t.dataset.word);
  const DURATION = 6500;
  let index = 0, timer = null, typeTimer = null, startAt = 0, paused = false;

  function typeWord(word) {
    if (!typedEl) return;
    clearInterval(typeTimer);
    if (reduced) { typedEl.textContent = word; return; }
    let current = typedEl.textContent;
    let phase = 'erase';
    typeTimer = setInterval(() => {
      if (phase === 'erase') {
        current = current.slice(0, -1);
        if (!current.length) phase = 'type';
      } else {
        current = word.slice(0, current.length + 1);
        if (current === word) clearInterval(typeTimer);
      }
      typedEl.textContent = current;
    }, phase === 'erase' ? 35 : 60);
  }

  function show(i) {
    index = (i + tabs.length) % tabs.length;
    tabs.forEach((t, k) => {
      t.classList.toggle('active', k === index);
      t.setAttribute('aria-selected', String(k === index));
      const bar = t.querySelector('.bar');
      if (bar) { bar.style.transition = 'none'; bar.style.width = '0'; }
    });
    panels.forEach((p, k) => p.classList.toggle('active', k === index));
    typeWord(words[index]);
    startAt = performance.now();
  }

  function tick(now) {
    if (!paused && !reduced) {
      const t = (now - startAt) / DURATION;
      const bar = tabs[index]?.querySelector('.bar');
      if (bar) bar.style.width = Math.min(100, t * 100) + '%';
      if (t >= 1) show(index + 1);
    }
    timer = requestAnimationFrame(tick);
  }

  if (tabs.length) {
    tabs.forEach((t, k) => t.addEventListener('click', () => show(k)));
    const visual = hero.querySelector('.hero-visual');
    visual && visual.addEventListener('pointerenter', () => { paused = true; });
    visual && visual.addEventListener('pointerleave', () => { paused = false; startAt = performance.now() - 0; });
    show(0);
    timer = requestAnimationFrame(tick);
  }

  /* ------------------------------------------------ 3D mouse parallax on visual */
  const stage = hero.querySelector('.hv-stage');
  const layers = Array.from(hero.querySelectorAll('[data-depth]'));
  if (stage && !reduced && window.matchMedia('(pointer: fine)').matches) {
    hero.addEventListener('pointermove', (e) => {
      const r = hero.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width - 0.5;
      const py = (e.clientY - r.top) / r.height - 0.5;
      stage.style.transform = `rotateY(${px * 10}deg) rotateX(${-py * 8}deg)`;
      layers.forEach((l) => {
        const d = parseFloat(l.dataset.depth);
        l.style.transform = `translate3d(${px * d * 40}px, ${py * d * 40}px, ${d * 60}px)`;
      });
    });
    hero.addEventListener('pointerleave', () => {
      stage.style.transform = '';
      layers.forEach((l) => { l.style.transform = ''; });
    });
  }
})();
