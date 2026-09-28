/* Integra Fiscal Hub — customer app shell: sidebar, company switcher, hash router. */
import { $, $$, esc, icon, toast, toastError, BRAND_MARK } from '/admin/js/core.js';
import { fh } from '/assets/fiscal/state.js';

// Same version as app.js (?v=mtime from cliente/fiscal/index.php): a deploy changes the URL of every view.
const ASSET_V = new URL(import.meta.url).search;

const NAV = [
  { group: null, items: [['/', 'Painel', 'home'], ['/emitir', 'Emitir nota', 'plus']] },
  { group: 'Notas', items: [['/notas', 'Notas fiscais', 'file'], ['/recorrentes', 'Recorrentes', 'refresh', 'recurring'], ['/lote', 'Emissão em lote', 'upload', 'batch']] },
  { group: 'Cadastros', items: [['/clientes', 'Clientes (tomadores)', 'users'], ['/servicos', 'Serviços', 'tag']] },
  { group: 'Financeiro', items: [['/financeiro', 'Visão geral e fluxo', 'flow'], ['/financeiro/receber', 'Contas a receber', 'trendUp'], ['/financeiro/pagar', 'Contas a pagar', 'trendDown'], ['/financeiro/extrato', 'Extrato e conciliação', 'link'], ['/financeiro/contas', 'Contas bancárias', 'bank']] },
  { group: 'Gestão', items: [['/relatorios', 'Relatórios e IA', 'pie'], ['/empresa', 'Empresa e certificado', 'settings'], ['/assinatura', 'Assinatura', 'wallet']] },
];
const ROUTES = [
  [/^\/$/, 'painel', 'Painel'],
  [/^\/emitir$/, 'emitir', 'Emitir nota fiscal'],
  [/^\/emitir\/(\d+)$/, 'emitir', 'Editar nota'],
  [/^\/notas$/, 'notas', 'Notas fiscais'],
  [/^\/notas\/(\d+)$/, 'notas', 'Nota fiscal'],
  [/^\/clientes$/, 'cadastros', 'Clientes (tomadores)', 'takers'],
  [/^\/servicos$/, 'cadastros', 'Serviços', 'services'],
  [/^\/recorrentes$/, 'extras', 'Notas recorrentes', 'recurring'],
  [/^\/lote$/, 'extras', 'Emissão em lote', 'batch'],
  [/^\/relatorios$/, 'relatorios', 'Relatórios e IA'],
  [/^\/empresa$/, 'empresa', 'Empresa e certificado'],
  [/^\/empresa\/(nova|\d+)$/, 'empresa', 'Empresa', 'edit'],
  [/^\/assinatura$/, 'assinatura', 'Assinatura'],
  [/^\/financeiro$/, 'financeiro', 'Financeiro'],
  [/^\/financeiro\/receber$/, 'contas', 'Contas a receber', 'receivable'],
  [/^\/financeiro\/pagar$/, 'contas', 'Contas a pagar', 'payable'],
  [/^\/financeiro\/extrato$/, 'extrato', 'Extrato e conciliação'],
  [/^\/financeiro\/contas$/, 'bancos', 'Contas bancárias'],
];
const root = document.getElementById('root');

function toggleTheme() {
  const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
  document.documentElement.dataset.theme = next;
  try { localStorage.setItem('ic-admin-theme', next); } catch (e) { /* ignore */ }
}

export function renderShell() {
  const a = fh.access;
  const plan = fh.plan;
  root.innerHTML = `
    <div class="app fh-app">
      <aside class="sidebar" aria-label="Menu do Fiscal Hub">
        <a href="#/" class="sb-brand fh-brand" aria-label="Integra Fiscal Hub">${BRAND_MARK}<div><b>Integra <span>Fiscal Hub</span></b><small>Emissor de NFS-e</small></div></a>
        <div class="fh-company" data-company></div>
        <nav class="sb-nav">
          ${NAV.map((g) => `<div class="sb-group">${g.group ? `<span>${g.group}</span>` : ''}${g.items.map(([path, label, ico, flag]) => `<a class="sb-link" href="#${path}" data-path="${path}">${icon(ico)}${esc(label)}${flag && !fh.flag(flag) ? `<span class="fh-lock" title="Disponível em planos superiores">${icon('lock')}</span>` : ''}</a>`).join('')}</div>`).join('')}
        </nav>
        <div class="sb-foot">
          <div class="fh-plan-mini">${plan ? `<b>Plano ${esc(plan.name)}</b><small>${a.usage?.notes_used ?? 0} de ${a.usage?.notes_limit ?? 0} notas no mês</small><div class="progress"><i style="width:${Math.min(100, ((a.usage?.notes_used || 0) / Math.max(1, a.usage?.notes_limit || 1)) * 100)}%"></i></div>` : '<b>Sem plano</b><small><a href="/fiscal-hub">Conheça os planos</a></small>'}</div>
          <div class="sb-user"><span class="avatar">${esc((window.FH_BOOT?.name || '?').slice(0, 1).toUpperCase())}</span><div><b>${esc(window.FH_BOOT?.name || '')}</b><small>${esc(window.FH_BOOT?.email || '')}</small></div>
            <a class="btn btn-ghost btn-icon btn-sm" href="/cliente/" title="Voltar à Área do Cliente" aria-label="Área do Cliente">${icon('undo')}</a>
          </div>
        </div>
      </aside>
      <div class="sb-backdrop" data-nav-close></div>
      <div class="main">
        <header class="topbar">
          <button class="btn btn-ghost btn-icon menu-btn" data-nav-open aria-label="Abrir menu">${icon('menu')}</button>
          <h1 data-title>Fiscal Hub</h1>
          <span class="env-pill ${a.can_emit ? 'live' : ''}" title="Situação da assinatura"><i></i><span>${a.can_emit ? 'Emissão liberada' : a.state === 'pending' ? 'Aguardando pagamento' : 'Emissão bloqueada'}</span></span>
          <a class="btn btn-primary btn-sm fh-top-emit" href="#/emitir">${icon('plus')} Emitir nota</a>
          <button class="btn btn-ghost btn-icon" data-theme-toggle title="Alternar tema" aria-label="Alternar tema">${icon(document.documentElement.dataset.theme === 'light' ? 'moon' : 'sun')}</button>
        </header>
        ${a.message ? `<div class="fh-banner ${a.state === 'grace' ? 'warn' : a.state === 'pending' ? 'info' : 'danger'}">${icon('alert')}<span>${esc(a.message)}</span>${['pending', 'grace', 'expired'].includes(a.state) ? `<a class="btn btn-sm btn-primary" href="#/assinatura">${a.state === 'pending' ? 'Pagar e liberar' : 'Regularizar'}</a>` : ''}</div>` : ''}
        <main class="content" id="view" tabindex="-1"></main>
      </div>
    </div>`;
  renderCompany();
  const app = $('.app');
  $('[data-nav-open]').addEventListener('click', () => app.classList.add('nav-open'));
  $('[data-nav-close]').addEventListener('click', () => app.classList.remove('nav-open'));
  $('[data-theme-toggle]').addEventListener('click', (e) => { toggleTheme(); e.currentTarget.innerHTML = icon(document.documentElement.dataset.theme === 'light' ? 'moon' : 'sun'); });
}

function renderCompany() {
  const box = $('[data-company]');
  if (!box) return;
  const list = fh.activeEmitters();
  const em = fh.emitter();
  if (!list.length) { box.innerHTML = `<a class="fh-company-empty" href="#/empresa/nova">${icon('plus')} Cadastrar minha empresa</a>`; return; }
  box.innerHTML = `<label class="fh-company-label" for="fh-company">Empresa emissora</label>
    <select id="fh-company" class="input">${list.map((e) => `<option value="${e.id}" ${e.id === fh.emitterId ? 'selected' : ''}>${esc(e.trade_name || e.legal_name)}</option>`).join('')}</select>
    ${em ? `<small class="fh-company-status ${em.ready ? 'ok' : 'bad'}">${em.ready ? '● Pronta para emitir' : '● Configuração pendente'} · ${em.provider === 'nacional' ? 'Nacional' + (em.environment === 'production' ? '' : ' (testes)') : 'SIGISS Marília'}</small>` : ''}`;
  $('#fh-company').addEventListener('change', (e) => { fh.setEmitter(e.target.value); renderCompany(); route(); });
}

export async function reloadMe() {
  await fh.refresh();
  renderShell();
}

let cleanup = null;
async function route() {
  const hash = location.hash.replace(/^#/, '') || '/';
  const [path, qs] = hash.split('?');
  const query = Object.fromEntries(new URLSearchParams(qs || ''));
  const match = ROUTES.map(([re, mod, title, sub]) => ({ m: path.match(re), mod, title, sub })).find((r) => r.m);
  const view = $('#view');
  if (!view) return;
  $('.app')?.classList.remove('nav-open');
  const links = $$('.sb-link');
  const best = links.filter((a) => a.dataset.path === path || (a.dataset.path !== '/' && path.startsWith(a.dataset.path + '/'))).sort((a, b) => b.dataset.path.length - a.dataset.path.length)[0];
  links.forEach((a) => a.classList.toggle('active', a === best));
  if (!match) { view.innerHTML = '<div class="empty-box">Página não encontrada.</div>'; return; }
  $('[data-title]').textContent = match.title;
  document.title = match.title + ' · Integra Fiscal Hub';
  $$('.overlay').forEach((o) => o.remove());
  document.body.style.overflow = '';
  if (typeof cleanup === 'function') { try { cleanup(); } catch (e) { /* ignore */ } }
  cleanup = null;
  view.innerHTML = '<div class="loading-box">Carregando...</div>';
  try {
    const mod = await import(`/assets/fiscal/views/${match.mod}.js${ASSET_V}`);
    const ctx = { id: match.m[1] && /^\d+$/.test(match.m[1]) ? +match.m[1] : null, key: match.m[1] || null, sub: match.sub, query, setTitle: (t) => { $('[data-title]').textContent = t; } };
    cleanup = await mod.render(view, ctx);
    window.scrollTo(0, 0);
  } catch (err) {
    console.error(err);
    view.innerHTML = `<div class="alert alert-danger">Não foi possível carregar esta tela: ${esc(err.message)}</div>`;
  }
}
window.addEventListener('hashchange', route);
window.addEventListener('fh:reload', async () => { await reloadMe(); route(); });
window.addEventListener('fh:company', () => renderCompany());
window.addEventListener('auth:expired', () => { location.href = '/entrar?next=' + encodeURIComponent('/cliente/fiscal/' + location.hash); });

async function boot() {
  try {
    await fh.refresh();
  } catch (e) {
    if (e.status === 401) { location.href = '/entrar?next=' + encodeURIComponent('/cliente/fiscal/'); return; }
    root.innerHTML = `<div class="auth-body"><div class="auth-card"><h1>Não foi possível abrir o Fiscal Hub</h1><p class="muted">${esc(e.message)}</p><a class="btn btn-primary" href="/cliente/">Voltar à Área do Cliente</a></div></div>`;
    return;
  }
  if (!fh.access.sub) {
    const { renderNoPlan } = await import(`/assets/fiscal/views/assinatura.js${ASSET_V}`);
    renderNoPlan(root);
    return;
  }
  renderShell();
  if (!fh.activeEmitters().length && !/^#\/(empresa|assinatura|financeiro)/.test(location.hash)) location.hash = '#/empresa/nova';
  route();
}

boot().catch(toastError);
export { toast };
