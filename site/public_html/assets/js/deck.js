/* Integra Code presentation: scaling, navigation, charts, fullscreen, print. */
(() => {
  'use strict';
  const body = document.body;
  const slides = Array.from(document.querySelectorAll('.slide'));
  const counter = document.querySelector('[data-counter]');
  const bar = document.querySelector('[data-bar]');
  const isPrint = body.classList.contains('is-print');
  let cur = 0;

  const css = (v) => getComputedStyle(body).getPropertyValue(v).trim();
  const fmt = (v, f) => {
    const n = Number(v || 0);
    if (f === 'money') return Math.abs(n) >= 1000 ? 'R$ ' + (n / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    if (f === 'pct') return n.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
    if (f === 'hours') return n.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h';
    return n.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
  };

  function fit() {
    if (isPrint) return;
    const s = Math.min(window.innerWidth / 1280, window.innerHeight / 720) * (window.innerWidth < 700 ? 1 : 0.96);
    document.documentElement.style.setProperty('--scale', String(s));
  }

  function go(i, push = true) {
    if (!slides.length) return;
    cur = Math.max(0, Math.min(slides.length - 1, i));
    slides.forEach((s, k) => s.classList.toggle('active', k === cur));
    if (counter) counter.textContent = `${cur + 1} / ${slides.length}`;
    if (bar) bar.style.width = ((cur + 1) / slides.length * 100) + '%';
    if (push) history.replaceState(null, '', '#' + (cur + 1));
    renderCharts(slides[cur]);
  }

  /* ---------------------------------------------------------------- charts */
  const PALETTE = ['#0066FE', '#00CF81', '#6D45F6', '#2FD4EE', '#F59E0B', '#F43F5E', '#94A3B8', '#22C55E'];
  function renderCharts(scope) {
    if (!window.Chart) return;
    scope.querySelectorAll('canvas[data-chart]:not([data-done])').forEach((cv) => {
      let cfg;
      try { cfg = JSON.parse(cv.dataset.chart); } catch (e) { return; }
      cv.dataset.done = '1';
      const text = css('--text2') || '#b9c3d6';
      const grid = css('--line') || 'rgba(255,255,255,.1)';
      const type = cfg.type || 'bar';
      const isPie = type === 'doughnut' || type === 'pie';
      const horizontal = type === 'hbar';
      const stacked = type === 'stacked';
      const datasets = (cfg.datasets || []).map((d, i) => {
        const color = d.color || PALETTE[i % PALETTE.length];
        const line = d.type === 'line' || type === 'line';
        return {
          label: d.label, data: d.data, type: d.type || undefined,
          backgroundColor: isPie ? (cfg.labels || []).map((_, k) => PALETTE[k % PALETTE.length]) : line ? color + '33' : color,
          borderColor: isPie ? css('--bg') : color, borderWidth: isPie ? 3 : line ? 3 : 0,
          borderRadius: isPie || line ? 0 : 8, maxBarThickness: 46, tension: 0.35, fill: line && type === 'line', pointRadius: line ? 3 : 0,
        };
      });
      Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
      Chart.defaults.font.size = 14;
      Chart.defaults.color = text;
      new Chart(cv, {
        type: isPie ? 'doughnut' : (type === 'line' ? 'line' : 'bar'),
        data: { labels: cfg.labels || [], datasets },
        options: {
          responsive: true, maintainAspectRatio: false, animation: isPrint ? false : { duration: 700 },
          indexAxis: horizontal ? 'y' : 'x', cutout: isPie ? '62%' : undefined,
          plugins: {
            legend: { display: isPie || datasets.length > 1, position: isPie ? 'right' : 'top', labels: { usePointStyle: true, boxWidth: 10, padding: 18 } },
            tooltip: { callbacks: { label: (c) => `${c.dataset.label || c.label}: ${fmt(c.parsed.y ?? c.parsed.x ?? c.parsed, cfg.format)}` } },
          },
          scales: isPie ? {} : (() => {
            const valueAxis = { stacked, grid: { color: grid }, ticks: { callback: (v) => fmt(v, cfg.format) } };
            const catAxis = { stacked, grid: { color: 'transparent' }, ticks: { maxRotation: 0, autoSkip: true, callback: function (v) { const l = String(this.getLabelForValue(v)); return l.length > 26 ? l.slice(0, 25) + '…' : l; } } };
            return horizontal ? { x: valueAxis, y: catAxis } : { x: catAxis, y: valueAxis };
          })(),
        },
      });
    });
  }

  /* ---------------------------------------------------------------- input */
  function next() { go(cur + 1); }
  function prev() { go(cur - 1); }
  document.addEventListener('keydown', (e) => {
    if (['ArrowRight', 'PageDown', ' ', 'Enter'].includes(e.key)) { e.preventDefault(); next(); }
    if (['ArrowLeft', 'PageUp', 'Backspace'].includes(e.key)) { e.preventDefault(); prev(); }
    if (e.key === 'Home') go(0);
    if (e.key === 'End') go(slides.length - 1);
    if (e.key.toLowerCase() === 'f') toggleFull();
  });
  document.querySelector('[data-next]')?.addEventListener('click', next);
  document.querySelector('[data-prev]')?.addEventListener('click', prev);
  document.querySelector('[data-full]')?.addEventListener('click', toggleFull);
  document.querySelector('.stage')?.addEventListener('click', (e) => {
    if (isPrint || e.target.closest('a, button, canvas')) return;
    (e.clientX > window.innerWidth / 2 ? next : prev)();
  });
  let tx = null;
  document.addEventListener('touchstart', (e) => { tx = e.touches[0].clientX; }, { passive: true });
  document.addEventListener('touchend', (e) => { if (tx === null) return; const dx = e.changedTouches[0].clientX - tx; if (Math.abs(dx) > 50) (dx < 0 ? next : prev)(); tx = null; });
  let hideT;
  document.addEventListener('mousemove', () => { body.classList.add('show-ui'); clearTimeout(hideT); hideT = setTimeout(() => body.classList.remove('show-ui'), 2200); });
  function toggleFull() {
    if (!document.fullscreenElement) document.documentElement.requestFullscreen?.().catch(() => {});
    else document.exitFullscreen?.();
  }
  /* editor preview: parent asks to show a slide */
  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data || typeof e.data.goto !== 'number') return;
    go(e.data.goto, false);
  });

  window.addEventListener('resize', fit);
  fit();

  if (isPrint) {
    const start = () => {
      slides.forEach(renderCharts);
      if (body.dataset.autoPrint === '1') setTimeout(() => window.print(), 900);
    };
    if (window.Chart) start(); else window.addEventListener('load', start);
    return;
  }
  const initial = parseInt(location.hash.slice(1), 10);
  const boot = () => go(Number.isFinite(initial) ? initial - 1 : 0, false);
  if (window.Chart) boot(); else window.addEventListener('load', boot);
  go(Number.isFinite(initial) ? initial - 1 : 0, false);
})();
