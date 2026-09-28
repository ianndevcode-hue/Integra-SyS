/* Account pages: show/hide password and strength meter. */
(() => {
  'use strict';
  document.querySelectorAll('[data-toggle-pass]').forEach((btn) => btn.addEventListener('click', () => {
    const input = btn.parentElement.querySelector('input');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
    btn.textContent = show ? '🙈' : '👁';
  }));
  document.querySelectorAll('[data-strength]').forEach((input) => {
    const bar = input.closest('.field').querySelector('.strength i');
    input.addEventListener('input', () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const colors = ['#f43f5e', '#f43f5e', '#f59e0b', '#eab308', '#22c55e', '#00cf81'];
      bar.style.width = (v ? Math.max(12, score * 20) : 0) + '%';
      bar.style.background = colors[score];
    });
  });
  // Prevent double submit
  document.querySelectorAll('[data-auth-form]').forEach((form) => form.addEventListener('submit', () => {
    const b = form.querySelector('button:not([type="button"])');
    if (b) setTimeout(() => b.classList.add('loading'), 0);
  }));
})();
