/* Admin shell: authentication, navigation, hash router. */
import { state, api, $, $$, esc, icon, initials, can, toast, toastError, formModal, label, lookups, openSearch, BRAND_MARK, BRAND_WORDMARK } from './core.js';

const NAV = [
  { group: null, items: [{ path: '/', label: 'Dashboard', icon: 'home', area: 'dashboard' }] },
  { group: 'Comercial', items: [
    { path: '/customers', label: 'Clientes', icon: 'users', area: 'customers' },
    { path: '/leads', label: 'Leads', icon: 'target', area: 'leads', count: 'leads_new' },
    { path: '/appointments', label: 'Agenda', icon: 'calendar', area: 'appointments', count: 'appointments_upcoming' },
  ] },
  { group: 'Vendas & preços', items: [
    { path: '/pricing', label: 'Simulador de preços', icon: 'money', area: 'customers' },
    { path: '/pricing/quotes', label: 'Orçamentos', icon: 'file', area: 'customers', count: 'quotes_open' },
    { path: '/pricing/contracts', label: 'Contratos & MRR', icon: 'refresh', area: 'customers' },
    { path: '/pricing/ask', label: 'Consultor de preços IA', icon: 'sparkles', area: 'customers' },
  ] },
  { group: 'Produtos', items: [
    { path: '/fiscal-hub', label: 'Integra Fiscal Hub', icon: 'receipt', area: 'customers' },
    { path: '/fiscal-hub/subscriptions', label: 'Assinantes do Fiscal Hub', icon: 'users', area: 'customers' },
  ] },
  { group: 'Operação', items: [
    { path: '/projects', label: 'Projetos', icon: 'kanban', area: 'projects' },
    { path: '/tickets', label: 'Chamados', icon: 'ticket', area: 'tickets', count: 'tickets_open' },
    { path: '/followups', label: 'Follow-ups', icon: 'bell', area: 'dashboard', count: 'followups_due' },
    { path: '/calendar', label: 'Calendário', icon: 'calendar', area: 'dashboard' },
  ] },
  { group: 'Inteligência', items: [
    { path: '/reports', label: 'Relatórios', icon: 'pie', area: 'dashboard' },
    { path: '/presentations', label: 'Apresentações com IA', icon: 'sparkles', area: 'customers' },
  ] },
  { group: 'Financeiro', items: [
    { path: '/finance/entries', label: 'Contas a pagar/receber', icon: 'wallet', area: 'finance' },
    { path: '/finance/charges', label: 'Cobranças (Asaas)', icon: 'receipt', area: 'charges' },
    { path: '/finance/nfse', label: 'Notas fiscais (NFS-e)', icon: 'file', area: 'finance' },
    { path: '/finance/bank', label: 'Extrato & Conciliação', icon: 'bank', area: 'finance', count: 'unreconciled' },
    { path: '/finance/cashflow', label: 'Fluxo de caixa', icon: 'flow', area: 'finance' },
    { path: '/finance/pnl', label: 'Lucros & gastos (DRE)', icon: 'pie', area: 'finance' },
    { path: '/finance/budget', label: 'Orçamento & metas', icon: 'target', area: 'finance' },
    { path: '/finance/partners', label: 'Sócios & distribuição', icon: 'split', area: 'partners' },
  ] },
  { group: 'Conteúdo', items: [
    { path: '/content/posts', label: 'Blog', icon: 'book', area: 'content' },
    { path: '/content/help', label: 'Central de ajuda', icon: 'help', area: 'content' },
  ] },
  { group: 'Sistema', items: [
    { path: '/settings', label: 'Configurações', icon: 'settings', area: 'users' },
    { path: '/settings/users', label: 'Usuários', icon: 'shield', area: 'users' },
    { path: '/settings/categories', label: 'Plano de contas', icon: 'tag', area: 'finance' },
    { path: '/settings/emails', label: 'E-mails enviados', icon: 'send', area: 'users' },
    { path: '/settings/audit', label: 'Auditoria', icon: 'log', area: 'users' },
  ] },
];

const ROUTES = [
  [/^\/$/, 'dashboard', 'Dashboard'],
  [/^\/customers$/, 'customers', 'Clientes'],
  [/^\/customers\/(\d+)$/, 'customers', 'Cliente', 'detail'],
  [/^\/leads$/, 'crm', 'Leads', 'leads'],
  [/^\/appointments$/, 'crm', 'Agenda', 'appointments'],
  [/^\/projects$/, 'projects', 'Projetos'],
  [/^\/projects\/(\d+)$/, 'projects', 'Projeto', 'detail'],
  [/^\/tickets$/, 'tickets', 'Chamados'],
  [/^\/tickets\/(\d+)$/, 'tickets', 'Chamado', 'detail'],
  [/^\/followups$/, 'followups', 'Follow-ups'],
  [/^\/calendar$/, 'calendar', 'Calendário'],
  [/^\/reports$/, 'reports', 'Relatórios'],
  [/^\/reports\/builder$/, 'reports', 'Construtor de relatórios', 'builder'],
  [/^\/reports\/([a-z_]+)$/, 'reports', 'Relatório', 'view'],
  [/^\/pricing$/, 'pricing', 'Simulador de preços', 'simulator'],
  [/^\/pricing\/quotes$/, 'pricing', 'Orçamentos', 'quotes'],
  [/^\/pricing\/quotes\/(\d+)$/, 'pricing', 'Orçamento', 'simulator'],
  [/^\/pricing\/contracts$/, 'pricing', 'Contratos', 'contracts'],
  [/^\/pricing\/catalog$/, 'pricing', 'Tabela de preços', 'catalog'],
  [/^\/pricing\/ask$/, 'pricing', 'Consultor de preços', 'ask'],
  [/^\/fiscal-hub$/, 'fiscalhub', 'Integra Fiscal Hub', 'overview'],
  [/^\/fiscal-hub\/subscriptions$/, 'fiscalhub', 'Assinantes do Fiscal Hub', 'subscriptions'],
  [/^\/fiscal-hub\/subscriptions\/(\d+)$/, 'fiscalhub', 'Assinante', 'subscriptions'],
  [/^\/fiscal-hub\/plans$/, 'fiscalhub', 'Planos do Fiscal Hub', 'plans'],
  [/^\/fiscal-hub\/invoices$/, 'fiscalhub', 'Notas do Fiscal Hub', 'invoices'],
  [/^\/fiscal-hub\/settings$/, 'fiscalhub', 'Configurações do Fiscal Hub', 'settings'],
  [/^\/presentations$/, 'presentations', 'Apresentações'],
  [/^\/presentations\/(\d+)$/, 'presentations', 'Apresentação', 'editor'],
  [/^\/finance\/budget$/, 'finance', 'Orçamento & metas', 'budget'],
  [/^\/finance\/entries$/, 'finance', 'Contas a pagar e receber', 'entries'],
  [/^\/finance\/cashflow$/, 'finance', 'Fluxo de caixa', 'cashflow'],
  [/^\/finance\/pnl$/, 'finance', 'Lucros & gastos', 'pnl'],
  [/^\/finance\/charges$/, 'charges', 'Cobranças'],
  [/^\/finance\/nfse$/, 'nfse', 'Notas fiscais (NFS-e)'],
  [/^\/finance\/bank$/, 'bank', 'Extrato & conciliação'],
  [/^\/finance\/partners$/, 'partners', 'Sócios & distribuição'],
  [/^\/content\/posts$/, 'content', 'Blog', 'posts'],
  [/^\/content\/help$/, 'content', 'Central de ajuda', 'help'],
  [/^\/settings$/, 'settings', 'Configurações', 'general'],
  [/^\/settings\/users$/, 'settings', 'Usuários', 'users'],
  [/^\/settings\/categories$/, 'settings', 'Plano de contas', 'categories'],
  [/^\/settings\/audit$/, 'settings', 'Auditoria', 'audit'],
  [/^\/settings\/emails$/, 'settings', 'E-mails enviados', 'emails'],
];

const root = document.getElementById('root');
let counts = {};

/* ------------------------------------------------------------------ theme */
function toggleTheme() {
  const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
  document.documentElement.dataset.theme = next;
  try { localStorage.setItem('ic-admin-theme', next); } catch (e) { /* ignore */ }
  route();
}

/* ------------------------------------------------------------------ login */
async function renderLogin(message) {
  document.body.className = 'auth-body';
  const urlErr = new URLSearchParams(location.search).get('erro');
  if (urlErr) { message = urlErr; history.replaceState(null, '', '/admin/' + location.hash); }
  let opts = { google: false };
  try { opts = await api('/auth/options'); } catch (e) { /* ignore */ }
  root.innerHTML = `
    <main class="auth-card">
      <div class="auth-logo">${BRAND_MARK}${BRAND_WORDMARK}</div>
      <h1>Painel administrativo</h1>
      <p class="muted" style="margin:0">Entre com seu e-mail e senha.</p>
      ${message ? `<div class="alert alert-warning" style="margin:16px 0 0">${esc(message)}</div>` : ''}
      ${opts.google ? `<a class="btn btn-block" href="/google-login?intent=admin" style="margin-top:18px;padding:11px;background:#fff;color:#1f1f1f;border-color:#dadce0"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg> Entrar com Google</a><p class="muted small" style="text-align:center;margin:12px 0 0">ou com e-mail e senha</p>` : ''}
      <form id="login" novalidate>
        <label>E-mail<input type="email" name="email" autocomplete="username" required autofocus></label>
        <label><span style="display:flex;justify-content:space-between">Senha <a href="/recuperar-senha" style="color:var(--primary);font-weight:500">Esqueci minha senha</a></span><input type="password" name="password" autocomplete="current-password" required></label>
        <label class="check" style="display:flex;flex-direction:row"><input type="checkbox" name="remember"> Manter conectado (30 dias)</label>
        <div class="alert alert-danger hidden" data-err></div>
        <button class="btn btn-primary btn-block" style="padding:12px">Entrar ${icon('arrowRight')}</button>
      </form>
      <p class="muted small" style="margin:18px 0 0;text-align:center"><a href="/">← Voltar ao site</a></p>
    </main>`;
  const form = $('#login');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button', form);
    const err = $('[data-err]', form);
    err.classList.add('hidden');
    btn.classList.add('loading');
    try {
      const res = await api('/auth/login', { method: 'POST', body: { email: form.email.value, password: form.password.value, remember: form.remember.checked } });
      Object.assign(state, { user: res.user, csrf: res.csrf, permissions: res.permissions });
      await boot();
    } catch (ex) {
      err.textContent = ex.message;
      err.classList.remove('hidden');
      btn.classList.remove('loading');
    }
  });
}

/* ------------------------------------------------------------------ shell */
function renderShell() {
  document.body.className = '';
  const u = state.user;
  root.innerHTML = `
    <div class="app">
      <aside class="sidebar" aria-label="Menu do painel">
        <a href="#/" class="sb-brand" aria-label="Integra Code - painel">${BRAND_MARK}<div>${BRAND_WORDMARK}<small>Painel de gestão</small></div></a>
        <nav class="sb-nav">
          ${NAV.map((g) => {
            const items = g.items.filter((i) => can(i.area));
            if (!items.length) return '';
            return `<div class="sb-group">${g.group ? `<span>${g.group}</span>` : ''}${items.map((i) => `<a class="sb-link" href="#${i.path}" data-path="${i.path}">${icon(i.icon)}${esc(i.label)}${i.count ? `<span class="count hidden" data-count="${i.count}"></span>` : ''}</a>`).join('')}</div>`;
          }).join('')}
        </nav>
        <div class="sb-foot">
          <div class="sb-user"><span class="avatar">${esc(initials(u.name))}</span><div><b>${esc(u.name)}</b><small>${esc(label('role', u.role))}</small></div>
            <button class="btn btn-ghost btn-icon btn-sm" data-password title="Alterar senha" aria-label="Alterar senha">${icon('shield')}</button>
            <button class="btn btn-ghost btn-icon btn-sm" data-logout title="Sair" aria-label="Sair">${icon('logout')}</button>
          </div>
        </div>
      </aside>
      <div class="sb-backdrop" data-nav-close></div>
      <div class="main">
        <header class="topbar">
          <button class="btn btn-ghost btn-icon menu-btn" data-nav-open aria-label="Abrir menu">${icon('menu')}</button>
          <h1 data-title>Dashboard</h1>
          <button class="topsearch" data-search aria-label="Buscar (Ctrl+K)">${icon('search')}<span>Buscar...</span><kbd>Ctrl K</kbd></button>
          <span class="env-pill ${state.asaas?.configured && state.asaas.environment === 'production' ? 'live' : ''}" title="Integração Asaas">
            <i></i><span>Asaas: ${state.asaas?.configured ? (state.asaas.environment === 'production' ? 'Produção' : 'Sandbox') : 'não configurado'}</span>
          </span>
          <a class="btn btn-ghost btn-icon" href="/" target="_blank" title="Ver site" aria-label="Ver site">${icon('globe')}</a>
          <button class="btn btn-ghost btn-icon" data-theme-toggle title="Alternar tema" aria-label="Alternar tema">${icon(document.documentElement.dataset.theme === 'light' ? 'moon' : 'sun')}</button>
        </header>
        <main class="content" id="view" tabindex="-1"></main>
      </div>
    </div>`;
  const app = $('.app');
  $('[data-nav-open]').addEventListener('click', () => app.classList.add('nav-open'));
  $('[data-nav-close]').addEventListener('click', () => app.classList.remove('nav-open'));
  $('[data-search]').addEventListener('click', openSearch);
  $('[data-theme-toggle]').addEventListener('click', (e) => { toggleTheme(); e.currentTarget.innerHTML = icon(document.documentElement.dataset.theme === 'light' ? 'moon' : 'sun'); });
  $('[data-logout]').addEventListener('click', async () => { await api('/auth/logout', { method: 'POST' }).catch(() => {}); state.user = null; state.csrf = null; renderLogin('Você saiu do painel.'); });
  $('[data-password]').addEventListener('click', () => formModal({
    title: 'Alterar minha senha', size: 'sm',
    fields: [
      { name: 'current', label: 'Senha atual', type: 'password', required: true, span: 2, autocomplete: 'current-password' },
      { name: 'new', label: 'Nova senha (mín. 8 caracteres)', type: 'password', required: true, span: 2, autocomplete: 'new-password' },
    ],
    onSubmit: async (d) => { await api('/auth/password', { method: 'POST', body: d }); toast('Senha alterada com sucesso.'); },
  }));
  refreshCounts();
}

export async function refreshCounts() {
  try {
    const d = await api('/dashboard');
    counts = d.kpis;
    $$('[data-count]').forEach((el) => {
      const v = counts[el.dataset.count] || 0;
      el.textContent = v;
      el.classList.toggle('hidden', !v);
    });
    return d;
  } catch (e) { return null; }
}
window.addEventListener('ic:refresh-counts', refreshCounts);
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && state.user) { e.preventDefault(); openSearch(); }
  if (e.key === '/' && state.user && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); openSearch(); }
});

/* ------------------------------------------------------------------ router */
let currentCleanup = null;

async function route() {
  if (!state.user) return;
  const hash = location.hash.replace(/^#/, '') || '/';
  const [path, qs] = hash.split('?');
  const query = Object.fromEntries(new URLSearchParams(qs || ''));
  const match = ROUTES.map(([re, mod, title, sub]) => ({ m: path.match(re), mod, title, sub })).find((r) => r.m);
  const view = $('#view');
  $('.app')?.classList.remove('nav-open');
  const links = $$('.sb-link');
  const best = links.filter((a) => a.dataset.path === path || (a.dataset.path !== '/' && path.startsWith(a.dataset.path + '/'))).sort((a, b) => b.dataset.path.length - a.dataset.path.length)[0];
  links.forEach((a) => a.classList.toggle('active', a === best));
  if (!match) { view.innerHTML = '<div class="empty-box">Página não encontrada.</div>'; return; }
  $('[data-title]').textContent = match.title;
  document.title = match.title + ' · Painel Integra Code';
  // Modals belong to the previous screen; drop them on navigation.
  $$('.overlay').forEach((o) => o.remove());
  document.body.style.overflow = '';
  if (typeof currentCleanup === 'function') { try { currentCleanup(); } catch (e) { /* ignore */ } }
  currentCleanup = null;
  view.innerHTML = '<div class="loading-box">Carregando...</div>';
  try {
    const mod = await import(`./views/${match.mod}.js`);
    const ctx = { id: match.m[1] && /^\d+$/.test(match.m[1]) ? +match.m[1] : null, key: match.m[1] || null, sub: match.sub, query, setTitle: (t) => { $('[data-title]').textContent = t; document.title = t + ' · Painel Integra Code'; } };
    currentCleanup = await mod.render(view, ctx);
    view.focus({ preventScroll: true });
    window.scrollTo(0, 0);
  } catch (err) {
    console.error(err);
    view.innerHTML = `<div class="alert alert-danger">Não foi possível carregar esta tela: ${esc(err.message)}</div>`;
  }
}
window.addEventListener('hashchange', route);
window.addEventListener('auth:expired', () => { if (state.user) { state.user = null; renderLogin('Sua sessão expirou. Entre novamente.'); } });

export function navigate(path) { location.hash = '#' + path; }

/* ------------------------------------------------------------------ boot */
async function boot() {
  try {
    const me = await api('/auth/me');
    Object.assign(state, { user: me.user, csrf: me.csrf, permissions: me.permissions, asaas: me.asaas, ai: me.ai, nfse: me.nfse });
  } catch (e) {
    if (e.status === 503) { root.innerHTML = `<div class="auth-body"><div class="auth-card"><h1>Instalação pendente</h1><p class="muted">${esc(e.message)}</p><a class="btn btn-primary" href="/install.php">Instalar agora</a></div></div>`; return; }
    renderLogin();
    return;
  }
  renderShell();
  lookups().catch(() => {});
  route();
}

boot().catch(toastError);
