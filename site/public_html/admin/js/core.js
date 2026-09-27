/* Admin core: API client, formatting, UI primitives (modal, toast, confirm, form, data table, charts). */

export const state = { user: null, csrf: null, permissions: [], asaas: null, lookups: null };

/* ------------------------------------------------------------------ utils */
export const $ = (s, r = document) => r.querySelector(s);
export const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const html = (strings, ...values) => strings.reduce((out, s, i) => out + s + (i < values.length ? (values[i] && values[i].__raw ? values[i].v : esc(values[i])) : ''), '');
export const raw = (v) => ({ __raw: true, v: String(v ?? '') });
export const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
export const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
export const monthStart = (d = today()) => d.slice(0, 8) + '01';
export const monthEnd = (d = today()) => { const x = new Date(d.slice(0, 7) + '-01T12:00:00'); x.setMonth(x.getMonth() + 1); x.setDate(0); return x.toISOString().slice(0, 10); };
export const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
export const money = (v) => brl.format(Number(v || 0));
export const moneyShort = (v) => { const n = Number(v || 0); return Math.abs(n) >= 1000 ? 'R$ ' + (n / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + 'k' : money(n); };
export const date = (d) => (d ? new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString('pt-BR') : '—');
export const datetime = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');
export const monthLabel = (ym) => { const s = new Date(ym + '-01T12:00:00').toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' }); return s.replace('.', ''); };
export const relative = (d) => {
  if (!d) return '—';
  const diff = (new Date(String(d).replace(' ', 'T')) - new Date()) / 1000;
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });
  const abs = Math.abs(diff);
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
  return rtf.format(Math.round(diff / 86400), 'day');
};
export const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
export const docFmt = (d) => { const s = String(d || '').replace(/\D/g, ''); return s.length === 14 ? s.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5') : s.length === 11 ? s.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4') : (d || '—'); };

/* ------------------------------------------------------------------ labels */
export const LABELS = {
  customer_status: { lead: ['Prospect', 'blue'], onboarding: ['Em implantação', 'violet'], active: ['Ativo', 'green'], paused: ['Pausado', 'yellow'], delinquent: ['Inadimplente', 'red'], inactive: ['Inativo', ''] },
  project_status: { proposal: ['Proposta', ''], approved: ['Aprovado (a iniciar)', 'blue'], active: ['Em andamento', 'orange'], waiting_client: ['Aguardando cliente', 'yellow'], review: ['Em homologação', 'violet'], paused: ['Pausado', 'yellow'], maintenance: ['Em manutenção', 'blue'], done: ['Concluído', 'green'], canceled: ['Cancelado', 'red'] },
  priority: { low: ['Baixa', ''], normal: ['Normal', 'blue'], high: ['Alta', 'yellow'], urgent: ['Urgente', 'red'] },
  stage_status: { pending: ['Pendente', ''], in_progress: ['Em andamento', 'orange'], waiting_client: ['Aguardando cliente', 'yellow'], review: ['Aguardando aprovação', 'violet'], blocked: ['Bloqueada', 'red'], done: ['Concluída', 'green'], skipped: ['Dispensada', ''] },
  lead_status: { new: ['Novo', 'orange'], contacted: ['Contatado', 'blue'], meeting: ['Reunião marcada', 'violet'], proposal: ['Proposta enviada', 'violet'], negotiation: ['Negociação', 'yellow'], won: ['Ganho', 'green'], lost: ['Perdido', 'red'], nurture: ['Nutrir (futuro)', ''] },
  lead_source: { contact: ['Contato', ''], diagnostic: ['Diagnóstico', 'violet'], 'sys-demo': ['Demo SYS', 'orange'], chat: ['Chat', 'blue'], manual: ['Manual', ''], whatsapp: ['WhatsApp', 'green'], indicacao: ['Indicação', 'violet'], instagram: ['Instagram', ''], google: ['Google', 'blue'], evento: ['Evento', ''] },
  appointment_status: { scheduled: ['Agendado', 'blue'], confirmed: ['Confirmado', 'green'], rescheduled: ['Remarcado', 'yellow'], done: ['Realizado', 'violet'], canceled: ['Cancelado', 'red'], no_show: ['Não compareceu', 'yellow'] },
  meeting_type: { online: ['Online', ''], presencial: ['Presencial', ''], telefone: ['Telefone', ''] },
  ticket_status: { open: ['Aberto', 'blue'], in_progress: ['Em atendimento', 'orange'], waiting: ['Aguardando cliente', 'yellow'], waiting_third: ['Aguardando terceiros', 'yellow'], on_hold: ['Pausado', ''], resolved: ['Resolvido', 'green'], closed: ['Encerrado', ''] },
  ticket_source: { site: ['Site', ''], portal: ['Área do cliente', 'violet'], email: ['E-mail', ''], whatsapp: ['WhatsApp', 'green'], phone: ['Telefone', ''], admin: ['Painel', ''] },
  activity_kind: { note: ['Anotação', ''], call: ['Ligação', 'blue'], whatsapp: ['WhatsApp', 'green'], email: ['E-mail', 'violet'], meeting: ['Reunião', 'orange'], task: ['Follow-up', 'yellow'], event: ['Evento', ''] },
  ticket_category: { duvida: ['Dúvida', ''], problema: ['Problema técnico', 'red'], melhoria: ['Melhoria', 'violet'], financeiro: ['Financeiro', 'green'], comercial: ['Comercial', 'blue'] },
  entry_type: { receivable: ['A receber', 'green'], payable: ['A pagar', 'red'] },
  entry_status: { open: ['Em aberto', 'blue'], paid: ['Pago', 'green'], canceled: ['Cancelado', ''], overdue: ['Vencido', 'red'] },
  charge_status: {
    CREATING: ['Criando', ''], PENDING: ['Aguardando', 'blue'], RECEIVED: ['Recebida', 'green'], CONFIRMED: ['Confirmada', 'green'], RECEIVED_IN_CASH: ['Recebida em dinheiro', 'green'],
    OVERDUE: ['Vencida', 'red'], REFUNDED: ['Estornada', 'violet'], REFUND_REQUESTED: ['Estorno solicitado', 'violet'], DELETED: ['Cancelada', ''], CANCELED: ['Cancelada', ''],
    AWAITING_RISK_ANALYSIS: ['Em análise', 'yellow'], CHARGEBACK_REQUESTED: ['Chargeback', 'red'], DUNNING_REQUESTED: ['Negativação', 'red'],
  },
  billing_type: { UNDEFINED: ['Cliente escolhe', ''], BOLETO: ['Boleto', ''], PIX: ['PIX', ''], CREDIT_CARD: ['Cartão', ''] },
  dre_group: { revenue: ['Receita', 'green'], other_income: ['Outras receitas', 'green'], tax: ['Impostos', 'red'], cost: ['Custos', 'yellow'], expense: ['Despesas', 'orange'], investment: ['Investimentos', 'violet'], distribution: ['Distribuição', 'blue'] },
  fh_invoice_status: { draft: ['Rascunho', ''], processing: ['Transmitindo', 'yellow'], authorized: ['Emitida', 'green'], rejected: ['Rejeitada', 'red'], canceled: ['Cancelada', 'violet'] },
  fh_sub_status: { pending: ['Aguardando pagamento', 'yellow'], active: ['Ativa', 'green'], past_due: ['Em atraso', 'red'], suspended: ['Suspensa', 'red'], canceled: ['Cancelada', ''] },
  quote_status: { draft: ['Rascunho', ''], sent: ['Enviado', 'blue'], accepted: ['Aceito', 'green'], rejected: ['Recusado', 'red'], expired: ['Expirado', 'yellow'] },
  contract_status: { active: ['Ativo', 'green'], paused: ['Pausado', 'yellow'], canceled: ['Cancelado', 'red'], ended: ['Encerrado', ''] },
  nfse_status: { draft: ['Rascunho', ''], processing: ['Transmitindo', 'yellow'], authorized: ['Autorizada', 'green'], rejected: ['Rejeitada', 'red'], canceled: ['Cancelada', 'violet'] },
  role: { admin: ['Administrador', 'orange'], finance: ['Financeiro', 'green'], manager: ['Gestor de projetos', 'blue'], support: ['Suporte', 'violet'] },
  help_category: { 'primeiros-passos': ['Primeiros passos', ''], financeiro: ['Financeiro', ''], projetos: ['Projetos', ''], suporte: ['Suporte', ''], seguranca: ['Segurança', ''], 'integra-sys': ['Integra SYS', ''] },
};
export const label = (group, key) => (LABELS[group]?.[key]?.[0]) ?? key ?? '—';
export const badge = (group, key) => { const [text, color] = LABELS[group]?.[key] ?? [key ?? '—', '']; return `<span class="badge ${color}">${esc(text)}</span>`; };
export const options = (group) => Object.entries(LABELS[group]).map(([value, [text]]) => ({ value, label: text }));

/* ------------------------------------------------------------------ icons */
const ICONS = {
  home: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
  target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
  calendar: '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>',
  kanban: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 7v7"/><path d="M12 7v4"/><path d="M16 7v9"/>',
  ticket: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
  wallet: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
  receipt: '<path d="M12 17V7"/><path d="M16 8h-6a2 2 0 0 0 0 4h4a2 2 0 0 1 0 4H8"/><path d="M4 3a1 1 0 0 1 1-1 1.3 1.3 0 0 1 .7.2l.933.6a1.3 1.3 0 0 0 1.4 0l.934-.6a1.3 1.3 0 0 1 1.4 0l.933.6a1.3 1.3 0 0 0 1.4 0l.933-.6a1.3 1.3 0 0 1 1.4 0l.934.6a1.3 1.3 0 0 0 1.4 0l.933-.6A1.3 1.3 0 0 1 19 2a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1 1.3 1.3 0 0 1-.7-.2l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.934.6a1.3 1.3 0 0 1-1.4 0l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-1.4 0l-.934-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-.7.2 1 1 0 0 1-1-1z"/>',
  bank: '<path d="M10 18v-7"/><path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>',
  flow: '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/>',
  pie: '<path d="M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951c-.55-.055-.998.398-.998.95v8a1 1 0 0 0 1 1z"/><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>',
  split: '<path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/>',
  book: '<path d="M12 5v16"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/>',
  help: '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
  settings: '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
  shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
  tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
  log: '<path d="M15 12h-5"/><path d="M15 8h-5"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>',
  plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
  search: '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
  edit: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
  trash: '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  menu: '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
  logout: '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
  download: '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
  upload: '<path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
  refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  unlink: '<path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/><line x1="8" x2="8" y1="2" y2="5"/><line x1="2" x2="5" y1="8" y2="8"/><line x1="16" x2="16" y1="19" y2="22"/><line x1="19" x2="22" y1="16" y2="16"/>',
  external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
  bolt: '<path d="M15.914 4a1.5 1.5 0 00-2.474-1.561l-9 9A1.5 1.5 0 005.5 14h4.002a.5.5 0 01.471.666L8.086 20a1.5 1.5 0 002.475 1.56l9-9A1.5 1.5 0 0018.5 10h-3.997a.5.5 0 01-.472-.667z"/>',
  arrowRight: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
  arrowUp: '<path d="m18 15-6-6-6 6"/>',
  arrowDown: '<path d="m6 9 6 6 6-6"/>',
  moon: '<path d="M20.985 12.486a9 9 0 1 1-9.473-9.472c.405-.022.617.46.402.803a6 6 0 0 0 8.268 8.268c.344-.215.825-.004.803.401"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
  eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
  send: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
  alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
  money: '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
  trendUp: '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
  trendDown: '<path d="M16 17h6v-6"/><path d="m22 17-8.5-8.5-5 5L2 7"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  play: '<path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>',
  file: '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
  sparkles: '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/>',
  printer: '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
  code: '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
  paperclip: '<path d="m16 6-8.414 8.586a2 2 0 0 0 2.829 2.829l8.414-8.586a4 4 0 1 0-5.657-5.657l-8.379 8.551a6 6 0 1 0 8.485 8.485l8.379-8.551"/>',
  lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  phone: '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
  message: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
  mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
  columns: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>',
  list: '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
  bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
  image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
  merge: '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/>',
  layers: '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
  undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>',
};
// Brand marks (Simple Icons, CC0) are filled instead of stroked.
const BRAND_ICONS = {
  pix: 'M5.283 18.36a3.505 3.505 0 0 0 2.493-1.032l3.6-3.6a.684.684 0 0 1 .946 0l3.613 3.613a3.504 3.504 0 0 0 2.493 1.032h.71l-4.56 4.56a3.647 3.647 0 0 1-5.156 0L4.85 18.36ZM18.428 5.627a3.505 3.505 0 0 0-2.493 1.032l-3.613 3.614a.67.67 0 0 1-.946 0l-3.6-3.6A3.505 3.505 0 0 0 5.283 5.64h-.434l4.573-4.572a3.646 3.646 0 0 1 5.156 0l4.559 4.559ZM1.068 9.422 3.79 6.699h1.492a2.483 2.483 0 0 1 1.744.722l3.6 3.6a1.73 1.73 0 0 0 2.443 0l3.614-3.613a2.482 2.482 0 0 1 1.744-.723h1.767l2.737 2.737a3.646 3.646 0 0 1 0 5.156l-2.736 2.736h-1.768a2.482 2.482 0 0 1-1.744-.722l-3.613-3.613a1.77 1.77 0 0 0-2.444 0l-3.6 3.6a2.483 2.483 0 0 1-1.744.722H3.791l-2.723-2.723a3.646 3.646 0 0 1 0-5.156',
};
export const icon = (name, cls = 'ico') => (BRAND_ICONS[name]
  ? `<svg class="${cls}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="${BRAND_ICONS[name]}"/></svg>`
  : `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ICONS.help}</svg>`);

/* Brand: vectorized original mark + wordmark (see inc/icons.php). */
export const BRAND_MARK = `<svg class="brand-mark" viewBox="341.6 236.02 585.38 534.44" aria-hidden="true"><defs><clipPath id="icm-admin-cb"><path d="M470.4 -5000 L4513.58 -5000 L1254.03 -298.02 L470.4 479.81Z"/><path d="M545.03 -5000 L4513.58 -5000 L670.84 543.24 L545.03 543.24Z"/><path d="M470.4 -5000 L4513.58 -5000 L766.16 405.74 L470.4 405.74Z"/><path d="M470.4 -5000 L698.57 -5000 L698.57 253.33 L470.4 479.81Z"/><path d="M545.03 -5000 L698.57 -5000 L698.57 543.24 L545.03 543.24Z"/><path d="M470.4 -5000 L698.57 -5000 L698.57 405.74 L470.4 405.74Z"/></clipPath><clipPath id="icm-admin-cg"><path d="M698.57 5000 L470.4 5000 L470.4 537.35 L698.57 763.83Z"/><path d="M698.57 5000 L576.61 5000 L576.61 463.24 L698.57 463.24Z"/><path d="M698.57 5000 L470.4 5000 L470.4 642.77 L698.57 642.77Z"/><path d="M3831.89 5000 L470.4 5000 L470.4 537.35 L1287.43 1348.34Z"/><path d="M3831.89 5000 L576.61 5000 L576.61 463.24 L670.7 463.24Z"/><path d="M3831.89 5000 L470.4 5000 L470.4 642.77 L795.8 642.77Z"/></clipPath></defs><circle class="mk-i-dot" cx="393.04" cy="287.61" r="49.44" fill="#0066FE"/><path class="mk-i" d="M380.32 370.11H405.28A34.06 34.06 0 0 1 439.34 404.17V685.69A46.65 46.65 0 0 1 392.69 732.34H392.91A46.65 46.65 0 0 1 346.26 685.69V404.17A34.06 34.06 0 0 1 380.32 370.11Z" fill="#0066FE"/><g class="mk-arc mk-arc-top" fill="#0066FE"><path d="M446.16 503.24a252.41 265.22 0 1 0 504.82 0a252.41 265.22 0 1 0 -504.82 0ZM520 503.13a176.31 189.65 0 1 0 352.62 0a176.31 189.65 0 1 0 -352.62 0Z" fill-rule="evenodd" clip-path="url(#icm-admin-cb)"/><circle cx="823.65" cy="319.35" r="38.51"/></g><g class="mk-arc mk-arc-bottom" fill="#00CF81"><path d="M446.16 503.24a252.41 265.22 0 1 0 504.82 0a252.41 265.22 0 1 0 -504.82 0ZM520 503.13a176.31 189.65 0 1 0 352.62 0a176.31 189.65 0 1 0 -352.62 0Z" fill-rule="evenodd" clip-path="url(#icm-admin-cg)"/><circle cx="824.07" cy="686.69" r="38.6"/></g><path class="mk-lt" d="M573.29 437.45L501.63 508.58L573.29 579.71" fill="none" stroke="#6D45F6" stroke-width="43.84" stroke-linecap="round" stroke-linejoin="round"/><path class="mk-gt" d="M833.89 436.55L902.57 507.45L833.89 578.35" fill="none" stroke="#00CF81" stroke-width="44.83" stroke-linecap="round" stroke-linejoin="round"/><circle class="mk-dot" fill="#0066FE" cx="635.66" cy="509.09" r="16.77"/><circle class="mk-dot" fill="#0066FE" cx="699.97" cy="509.09" r="16.77"/><circle class="mk-dot" fill="#0066FE" cx="764.28" cy="509.09" r="16.77"/></svg>`;
export const BRAND_WORDMARK = `<svg class="brand-wordmark" viewBox="21 815 1221 200" role="img" aria-label="Integra Code"><path class="wm-integra" d="M22 967V820H48V967ZM78 967V863.48H100.83L101.85 878.12Q107.2 869.81 115.55 865.41Q123.9 861 134.76 861Q147.65 861 156.9 865.95Q166.16 870.91 171.14 881.38Q176.12 891.85 176 908.62V967H151.74V914Q151.74 901.62 148.71 894.9Q145.69 888.18 140.37 885.46Q135.05 882.74 128.07 882.65Q115.72 882.48 108.99 890.36Q102.26 898.25 102.26 912.99V967ZM242.87 970Q225.14 970 215.84 961.79Q206.55 953.59 206.55 938.41V884.03H188V864.23H206.55V832H231.49V864.23H261.65V884.03H231.49V934.06Q231.49 941.26 235.19 944.97Q238.89 948.69 245.94 948.69Q248.24 948.69 250.86 947.83Q253.48 946.98 256.95 944.77L266 962.44Q260.35 966.1 254.5 968.05Q248.65 970 242.87 970ZM328 970Q312.79 970 301.07 963.03Q289.35 956.06 282.67 943.79Q276 931.52 276 915.5Q276 899.48 282.79 887.21Q289.59 874.94 301.47 867.97Q313.35 861 328.75 861Q342.71 861 353.86 868.18Q365 875.35 371.5 888.83Q378 902.31 378 921.13H299.95Q300.99 934.18 309.59 941.68Q318.2 949.17 330.08 949.17Q339.57 949.17 345.81 944.88Q352.04 940.59 355.47 933.76L375.92 942.45Q371.69 950.89 364.93 957.12Q358.16 963.34 348.93 966.67Q339.69 970 328 970ZM301.34 903.28H352.95Q352.33 895.83 348.64 890.9Q344.94 885.98 339.53 883.46Q334.12 880.94 328.15 880.94Q322.39 880.94 316.47 883.38Q310.54 885.83 306.36 890.78Q302.17 895.74 301.34 903.28ZM444.56 1014Q433.19 1014 424.25 1012.16Q415.32 1010.32 409.16 1007.71Q403.01 1005.11 399.61 1002.78L408.75 983.78Q411.66 985.35 416.59 987.57Q421.51 989.79 428.36 991.41Q435.22 993.04 443.74 993.04Q452.86 993.04 460.01 989.43Q467.16 985.81 471.24 978.22Q475.31 970.63 475.31 958.82V948.21Q469.83 956.95 460.99 961.92Q452.15 966.89 440.97 966.89Q426.5 966.89 415.72 960.43Q404.95 953.96 398.97 942.25Q393 930.53 393 914.98Q393 898.6 398.97 886.52Q404.95 874.45 415.72 867.72Q426.5 861 440.97 861Q452.15 861 460.99 866.08Q469.83 871.16 475.31 880.54V863.53H499V958.09Q499 976.09 492.19 988.64Q485.39 1001.18 473.12 1007.59Q460.85 1014 444.56 1014ZM447.44 946.99Q455.53 946.99 461.6 942.76Q467.68 938.53 471.08 931.18Q474.49 923.83 474.49 914.34Q474.49 904.79 471.04 897.5Q467.58 890.21 461.51 886.09Q455.44 881.96 447.23 881.96Q438.75 881.96 432.26 886.09Q425.78 890.21 422.15 897.5Q418.51 904.79 418.42 914.34Q418.51 923.83 422.21 931.18Q425.9 938.53 432.43 942.76Q438.96 946.99 447.44 946.99ZM520 967V861.42H544.75V880.55Q551.47 870.62 562.06 865.81Q572.66 861 584 861V883.6Q573.96 883.6 564.98 886.24Q555.99 888.87 550.37 894.81Q544.75 900.75 544.75 910.51V967ZM633.98 970Q615.01 970 604.5 961.65Q594 953.3 594 938.17Q594 921.89 605.11 913.43Q616.22 904.98 636.26 904.98H663.1Q661.8 893.41 656.47 887.4Q651.14 881.39 640.82 881.39Q633.25 881.39 627.46 884.53Q621.67 887.68 617.73 894.24L596.85 886.87Q600.24 879.97 605.94 874.07Q611.64 868.17 620.23 864.58Q628.83 861 640.82 861Q656.4 861 666.73 866.91Q677.05 872.83 682.1 883.84Q687.15 894.85 687 910.41L686.54 967.5H664.53L663.92 954.73Q659.65 962.1 652.12 966.05Q644.59 970 633.98 970ZM637.09 950.36Q644.84 950.36 650.87 946.97Q656.89 943.59 660.26 937.77Q663.62 931.95 663.62 924.78V922.83H643.74Q629.53 922.83 623.83 926.7Q618.14 930.57 618.14 937.58Q618.14 943.57 623.13 946.96Q628.13 950.36 637.09 950.36Z"/><path class="wm-code" d="M822.52 970Q801.88 970 786 960.11Q770.11 950.22 761.05 932.88Q752 915.55 752 893Q752 870.45 761 853.12Q769.99 835.78 785.82 825.89Q801.65 816 822.17 816Q836.07 816 848.29 821.17Q860.51 826.34 869.94 835.58Q879.37 844.82 884.45 856.92L862.94 866.04Q859.29 857.81 853.02 851.63Q846.76 845.44 838.86 842.01Q830.96 838.58 822.17 838.58Q808.74 838.58 798.3 845.55Q787.86 852.52 781.93 864.76Q776 877 776 893Q776 909.03 781.98 921.38Q787.95 933.72 798.55 940.69Q809.15 947.66 822.78 947.66Q831.86 947.66 839.67 943.94Q847.49 940.22 853.62 933.62Q859.75 927.01 863.49 918.41L885 927.54Q879.89 940 870.52 949.65Q861.15 959.3 848.78 964.65Q836.42 970 822.52 970ZM947.83 971Q931.79 971 919.41 963.97Q907.04 956.94 900.02 944.55Q893 932.16 893 916Q893 899.84 899.91 887.45Q906.82 875.06 919.19 868.03Q931.56 861 947.38 861Q963.43 861 975.8 868.03Q988.17 875.06 995.08 887.45Q1002 899.84 1002 916Q1002 932.16 995.09 944.55Q988.18 956.94 975.92 963.97Q963.66 971 947.83 971ZM947.82 949.43Q956.66 949.43 963.38 945.15Q970.1 940.87 973.95 933.36Q977.79 925.85 977.79 916Q977.79 906.15 973.94 898.64Q970.08 891.13 963.18 886.85Q956.29 882.57 947.42 882.57Q938.56 882.57 931.74 886.84Q924.92 891.12 921.07 898.63Q917.21 906.13 917.21 915.98Q917.21 925.83 921.12 933.33Q925.04 940.84 931.97 945.14Q938.91 949.43 947.82 949.43ZM1063.75 971Q1048.75 971 1037.57 964.03Q1026.39 957.06 1020.2 944.32Q1014 931.59 1014 914.7Q1014 897.6 1020.2 884.97Q1026.39 872.33 1037.57 865.37Q1048.75 858.4 1063.75 858.4Q1075.07 858.4 1083.95 863.25Q1092.83 868.09 1098.43 876.84V818H1123V968.42H1099.66L1098.81 952.1Q1093.21 961.03 1084.24 966.01Q1075.26 971 1063.75 971ZM1069.8 949.05Q1078.01 949.05 1084.35 944.88Q1090.7 940.71 1094.42 933.24Q1098.15 925.77 1098.43 916.3V913.22Q1098.15 903.62 1094.42 896.2Q1090.7 888.78 1084.3 884.61Q1077.91 880.44 1069.58 880.44Q1060.79 880.44 1053.92 884.8Q1047.06 889.15 1043.19 896.9Q1039.33 904.64 1039.33 914.7Q1039.33 924.76 1043.3 932.5Q1047.28 940.25 1054.14 944.65Q1061.01 949.05 1069.8 949.05ZM1190.51 971Q1175.15 971 1163.31 963.97Q1151.48 956.94 1144.74 944.55Q1138 932.16 1138 916Q1138 899.84 1144.86 887.45Q1151.72 875.06 1163.72 868.03Q1175.72 861 1191.26 861Q1205.37 861 1216.62 868.24Q1227.87 875.48 1234.44 889.09Q1241 902.69 1241 921.68H1162.19Q1163.23 934.85 1171.92 942.42Q1180.61 949.98 1192.61 949.98Q1202.19 949.98 1208.49 945.65Q1214.79 941.32 1218.25 934.43L1238.9 943.2Q1234.63 951.71 1227.8 958Q1220.97 964.28 1211.64 967.64Q1202.32 971 1190.51 971ZM1163.59 903.67H1215.71Q1215.08 896.15 1211.35 891.18Q1207.61 886.21 1202.15 883.66Q1196.69 881.12 1190.66 881.12Q1184.85 881.12 1178.86 883.59Q1172.88 886.06 1168.65 891.06Q1164.43 896.06 1163.59 903.67Z"/></svg>`;

/* ------------------------------------------------------------------ API */
export class ApiError extends Error {
  constructor(message, status, fields, details) { super(message); this.status = status; this.fields = fields || {}; this.details = details || []; }
}

export async function api(path, { method = 'GET', body, query } = {}) {
  let url = '/api' + path;
  if (query) {
    const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined));
    if ([...qs].length) url += (url.includes('?') ? '&' : '?') + qs;
  }
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(state.csrf ? { 'X-CSRF-Token': state.csrf } : {}) },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let data = {};
  try { data = await res.json(); } catch (e) { /* empty */ }
  if (res.status === 401 && path !== '/auth/login' && path !== '/auth/me') {
    window.dispatchEvent(new CustomEvent('auth:expired'));
  }
  if (!res.ok) throw new ApiError(data.error || `Erro ${res.status}`, res.status, data.fields, data.details);
  return data;
}

/** Multipart request (file uploads). `data` is a FormData or a plain object (+ files: {field: FileList}). */
export async function apiForm(path, data, { method = 'POST' } = {}) {
  const fd = data instanceof FormData ? data : Object.entries(data).reduce((f, [k, v]) => {
    if (v instanceof FileList || Array.isArray(v)) [...v].forEach((file) => f.append(k + '[]', file));
    else if (v !== undefined && v !== null) f.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
    return f;
  }, new FormData());
  const res = await fetch('/api' + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(state.csrf ? { 'X-CSRF-Token': state.csrf } : {}) }, body: fd });
  let out = {};
  try { out = await res.json(); } catch (e) { /* empty */ }
  if (res.status === 401) window.dispatchEvent(new CustomEvent('auth:expired'));
  if (res.status === 413) throw new ApiError('Arquivo grande demais para o servidor.', 413);
  if (!res.ok) throw new ApiError(out.error || `Erro ${res.status}`, res.status, out.fields, out.details);
  return out;
}

export function downloadUrl(path, query = {}) {
  const qs = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v != null));
  return '/api' + path + ([...qs].length ? '?' + qs : '');
}

export function can(area) {
  return state.permissions.includes('*') || state.permissions.includes(area);
}

export async function lookups(force = false) {
  if (!state.lookups || force) state.lookups = await api('/lookups');
  return state.lookups;
}

/* ------------------------------------------------------------------ toast */
export function toast(message, type = 'success') {
  let wrap = $('.toasts');
  if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toasts'; wrap.setAttribute('aria-live', 'polite'); document.body.appendChild(wrap); }
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = icon(type === 'error' ? 'alert' : type === 'info' ? 'help' : 'check') + `<span>${esc(message)}</span>`;
  wrap.appendChild(el);
  setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; }, 4000);
  setTimeout(() => el.remove(), 4400);
}
export const toastError = (err) => toast(err?.message || 'Erro inesperado.', 'error');

/* ------------------------------------------------------------------ modal */
export function modal({ title, body = '', footer = '', size = '', onClose, side = false } = {}) {
  const overlay = document.createElement('div');
  overlay.className = 'overlay';
  overlay.innerHTML = `
    <div class="${side ? 'drawer-side' : 'modal ' + size}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
      <div class="modal-head"><h3>${esc(title)}</h3><button class="btn btn-ghost btn-icon" data-close aria-label="Fechar">${icon('x')}</button></div>
      <div class="modal-body"></div>
      ${footer !== null ? '<div class="modal-foot"></div>' : ''}
    </div>`;
  if (side) { overlay.style.alignItems = 'stretch'; overlay.style.justifyContent = 'flex-end'; overlay.style.padding = '0'; }
  const bodyEl = $('.modal-body', overlay);
  const footEl = $('.modal-foot', overlay);
  if (typeof body === 'string') bodyEl.innerHTML = body; else if (body) bodyEl.appendChild(body);
  if (footEl) { if (typeof footer === 'string') footEl.innerHTML = footer; else if (footer) footEl.appendChild(footer); if (!footer) footEl.remove(); }
  const prevFocus = document.activeElement;
  const close = () => {
    overlay.remove();
    document.removeEventListener('keydown', onKey);
    if (!$('.overlay')) document.body.style.overflow = '';
    prevFocus && prevFocus.focus && prevFocus.focus();
    onClose && onClose();
  };
  const onKey = (e) => { if (e.key === 'Escape' && overlay === $$('.overlay').pop()) close(); };
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  $$('[data-close]', overlay).forEach((b) => b.addEventListener('click', close));
  document.addEventListener('keydown', onKey);
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';
  setTimeout(() => { const f = $('input:not([type=hidden]), select, textarea', bodyEl); (f || $('[data-close]', overlay)).focus(); }, 50);
  return { el: overlay, body: bodyEl, foot: footEl, close };
}

export function confirmDialog(message, { title = 'Confirmar', okLabel = 'Confirmar', danger = false } = {}) {
  return new Promise((resolve) => {
    let done = false;
    const m = modal({
      title, size: 'sm', body: `<p style="margin:0">${esc(message)}</p>`,
      footer: `<button class="btn" data-close>Cancelar</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${esc(okLabel)}</button>`,
      onClose: () => { if (!done) resolve(false); },
    });
    $('[data-ok]', m.el).addEventListener('click', () => { done = true; m.close(); resolve(true); });
    setTimeout(() => $('[data-ok]', m.el).focus(), 60);
  });
}

/* ------------------------------------------------------------------ forms */
export function fieldHtml(f, value) {
  const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 7);
  const req = f.required ? 'required' : '';
  const v = value ?? f.default ?? '';
  const common = `id="${id}" name="${esc(f.name)}" ${req} ${f.placeholder ? `placeholder="${esc(f.placeholder)}"` : ''} ${f.readonly ? 'readonly' : ''}`;
  let input;
  switch (f.type) {
    case 'textarea':
      input = `<textarea ${common} rows="${f.rows || 4}">${esc(v)}</textarea>`; break;
    case 'select': {
      const opts = (typeof f.options === 'function' ? f.options() : f.options) || [];
      input = `<select ${common}>${f.empty !== false ? `<option value="">${esc(f.empty || 'Selecione...')}</option>` : ''}${opts.map((o) => `<option value="${esc(o.value)}" ${String(o.value) === String(v) ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`;
      break;
    }
    case 'checkbox':
      return `<label class="check ${f.span === 2 ? 'span-2' : ''}"><input type="checkbox" name="${esc(f.name)}" ${Number(v) || v === true ? 'checked' : ''}> ${esc(f.label)}</label>`;
    case 'money':
      input = `<input ${common} type="number" step="0.01" min="0" inputmode="decimal" value="${v === '' ? '' : Number(v).toFixed(2)}">`; break;
    case 'datetime':
      input = `<input ${common} type="datetime-local" value="${esc(String(v).replace(' ', 'T').slice(0, 16))}">`; break;
    case 'html':
      return `<div class="${f.span === 2 ? 'span-2' : ''}">${f.html}</div>`;
    case 'hidden':
      return `<input type="hidden" name="${esc(f.name)}" value="${esc(v)}">`;
    case 'tags': {
      const listId = 'tags_' + Math.random().toString(36).slice(2, 7);
      input = `<input ${common} type="text" value="${esc(v)}" list="${listId}" autocomplete="off" placeholder="${esc(f.placeholder || 'ex.: vip, contrato anual, ecommerce')}"><datalist id="${listId}">${(state.lookups?.tags || []).map((t) => `<option value="${esc(t)}">`).join('')}</datalist>`;
      break;
    }
    default:
      input = `<input ${common} type="${f.type || 'text'}" value="${esc(v)}" ${f.min != null ? `min="${f.min}"` : ''} ${f.max != null ? `max="${f.max}"` : ''} ${f.step ? `step="${f.step}"` : ''} ${f.autocomplete ? `autocomplete="${f.autocomplete}"` : ''}>`;
  }
  return `<div class="field ${f.span === 2 ? 'span-2' : ''}" data-field="${esc(f.name)}"><label for="${id}">${esc(f.label)}${f.required ? ' *' : ''}</label>${input}${f.help ? `<span class="help">${esc(f.help)}</span>` : ''}</div>`;
}

export function readForm(form) {
  const data = {};
  $$('input, select, textarea', form).forEach((el) => {
    if (!el.name || el.type === 'file') return;
    if (el.type === 'checkbox') data[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) data[el.name] = el.value; }
    else data[el.name] = el.value;
  });
  return data;
}

export function showFieldErrors(form, fields = {}) {
  $$('.field.invalid', form).forEach((f) => f.classList.remove('invalid'));
  $$('.field .err', form).forEach((e) => e.remove());
  let first = null;
  Object.entries(fields).forEach(([name, msg]) => {
    const field = $(`[data-field="${CSS.escape(name)}"]`, form);
    if (!field) { toast(msg, 'error'); return; }
    field.classList.add('invalid');
    field.insertAdjacentHTML('beforeend', `<span class="err">${esc(msg)}</span>`);
    first = first || $('input, select, textarea', field);
  });
  first && first.focus();
}

/**
 * Open a modal form. onSubmit(data) may throw ApiError to show field errors.
 */
export function formModal({ title, fields, values = {}, submitLabel = 'Salvar', size = '', intro = '', onSubmit, onReady }) {
  const form = document.createElement('form');
  form.className = 'form-grid';
  form.noValidate = true;
  form.innerHTML = (intro ? `<div class="span-2">${intro}</div>` : '') + fields.filter(Boolean).map((f) => fieldHtml(f, values[f.name])).join('');
  const m = modal({ title, body: form, size, footer: `<button type="button" class="btn" data-close>Cancelar</button><button type="button" class="btn btn-primary" data-submit>${esc(submitLabel)}</button>` });
  const submitBtn = $('[data-submit]', m.el);
  const submit = async () => {
    const missing = {};
    fields.filter((f) => f && f.required).forEach((f) => { const el = form.elements[f.name]; if (el && !String(el.value).trim()) missing[f.name] = 'Campo obrigatório.'; });
    if (Object.keys(missing).length) { showFieldErrors(form, missing); return; }
    submitBtn.classList.add('loading');
    try {
      const data = readForm(form);
      fields.filter((f) => f && f.type === 'datetime').forEach((f) => { if (data[f.name]) data[f.name] = data[f.name].replace('T', ' ') + ':00'; });
      await onSubmit(data, m);
      m.close();
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fields).length) showFieldErrors(form, err.fields);
      else toastError(err);
    } finally {
      submitBtn.classList.remove('loading');
    }
  };
  submitBtn.addEventListener('click', submit);
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submit(); });
  onReady && onReady(form, m);
  return m;
}

/* ------------------------------------------------------------------ data table */
/**
 * dataTable(container, {
 *   endpoint, columns: [{key,label,render,sort,num,primary,class}], filters: [{name,type,label,options,value}],
 *   actions: (row) => [{label, icon, onClick, danger}], onRowClick, bulk: [{label, action(ids)}],
 *   toolbarExtra: html, exportPath, perPage, emptyText, totals: (rows) => html, query: {} (static params)
 * })
 */
export function dataTable(container, cfg) {
  const st = { page: 1, per_page: cfg.perPage || 25, sort: cfg.sort || '', dir: cfg.dir || '', q: '', filters: {}, rows: [], total: 0, selected: new Set() };
  (cfg.filters || []).forEach((f) => { if (f.value !== undefined) st.filters[f.name] = f.value; });

  container.innerHTML = `
    <div class="card">
      <div class="toolbar">
        <label class="search">${icon('search')}<input type="search" placeholder="${esc(cfg.searchPlaceholder || 'Buscar...')}" aria-label="Buscar" data-q></label>
        ${(cfg.filters || []).map((f) => f.type === 'date'
          ? `<input type="date" data-filter="${esc(f.name)}" value="${esc(f.value || '')}" aria-label="${esc(f.label)}" title="${esc(f.label)}">`
          : `<select data-filter="${esc(f.name)}" aria-label="${esc(f.label)}"><option value="">${esc(f.label)}</option>${(typeof f.options === 'function' ? f.options() : f.options).map((o) => `<option value="${esc(o.value)}" ${String(f.value ?? '') === String(o.value) ? 'selected' : ''}>${esc(o.label)}</option>`).join('')}</select>`).join('')}
        <span class="spacer"></span>
        ${cfg.toolbarExtra || ''}
        ${cfg.exportPath ? `<a class="btn btn-sm" data-export href="#">${icon('download')} CSV</a>` : ''}
      </div>
      <div class="bulkbar hidden" data-bulkbar></div>
      <div class="table-wrap"><table class="dt cards"><thead></thead><tbody><tr><td><div class="loading-box">Carregando...</div></td></tr></tbody></table></div>
      <div data-totals></div>
      <div class="pager"></div>
    </div>`;
  const table = $('table', container);
  const thead = $('thead', container);
  const tbody = $('tbody', container);
  const pager = $('.pager', container);
  const bulkbar = $('[data-bulkbar]', container);
  const selectable = !!(cfg.bulk && cfg.bulk.length);

  function renderHead() {
    thead.innerHTML = '<tr>' + (selectable ? '<th style="width:36px"><input type="checkbox" data-all aria-label="Selecionar todos"></th>' : '') + cfg.columns.map((c) => {
      const active = st.sort === c.sort;
      return `<th class="${c.sort ? 'sortable' : ''} ${c.num ? 'num' : ''}" ${c.sort ? `data-sort="${c.sort}"` : ''}>${esc(c.label)}${active ? `<span class="arrow">${st.dir === 'asc' ? '↑' : '↓'}</span>` : ''}</th>`;
    }).join('') + (cfg.actions ? '<th></th>' : '') + '</tr>';
    const all = $('[data-all]', thead);
    all && all.addEventListener('change', () => { st.rows.forEach((r) => (all.checked ? st.selected.add(r.id) : st.selected.delete(r.id))); renderBody(); renderBulk(); });
    $$('[data-sort]', thead).forEach((th) => th.addEventListener('click', () => {
      st.dir = st.sort === th.dataset.sort && st.dir === 'desc' ? 'asc' : 'desc';
      st.sort = th.dataset.sort; st.page = 1; load();
    }));
  }

  function renderBody() {
    if (!st.rows.length) {
      tbody.innerHTML = `<tr><td colspan="99"><div class="dt-empty">${icon(cfg.emptyIcon || 'search')}<div>${esc(cfg.emptyText || 'Nenhum registro encontrado.')}</div></div></td></tr>`;
      return;
    }
    tbody.innerHTML = st.rows.map((r, i) => `<tr data-i="${i}" class="${cfg.onRowClick ? 'clickable' : ''} ${st.selected.has(r.id) ? 'selected' : ''}">`
      + (selectable ? `<td class="sel"><input type="checkbox" data-sel="${r.id}" ${st.selected.has(r.id) ? 'checked' : ''} aria-label="Selecionar"></td>` : '')
      + cfg.columns.map((c) => `<td data-label="${esc(c.label)}" class="${c.num ? 'num' : ''} ${c.primary ? 'primary' : ''} ${c.class || ''}">${c.render ? c.render(r) : esc(r[c.key] ?? '—')}</td>`).join('')
      + (cfg.actions ? `<td class="actions">${cfg.actions(r).filter(Boolean).map((a, k) => `<button class="btn btn-xs ${a.danger ? 'btn-danger' : a.success ? 'btn-success' : ''}" data-act="${k}" title="${esc(a.label)}">${a.icon ? icon(a.icon) : ''}${a.iconOnly ? '' : esc(a.label)}</button>`).join(' ')}</td>` : '')
      + '</tr>').join('');
  }

  function renderBulk() {
    if (!selectable) return;
    bulkbar.classList.toggle('hidden', !st.selected.size);
    bulkbar.innerHTML = `<b>${st.selected.size} selecionado(s)</b>` + cfg.bulk.map((b, k) => `<button class="btn btn-sm ${b.danger ? 'btn-danger' : ''}" data-bulk="${k}">${b.icon ? icon(b.icon) : ''}${esc(b.label)}</button>`).join('') + '<button class="btn btn-sm btn-ghost" data-clear>Limpar seleção</button>';
  }

  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-i]');
    if (!tr) return;
    const row = st.rows[+tr.dataset.i];
    const act = e.target.closest('[data-act]');
    if (act) { e.stopPropagation(); cfg.actions(row).filter(Boolean)[+act.dataset.act].onClick(row, api); return; }
    const sel = e.target.closest('[data-sel]');
    if (sel) { e.stopPropagation(); sel.checked ? st.selected.add(row.id) : st.selected.delete(row.id); tr.classList.toggle('selected', sel.checked); renderBulk(); return; }
    if (e.target.closest('a, button, input')) return;
    cfg.onRowClick && cfg.onRowClick(row);
  });
  bulkbar.addEventListener('click', async (e) => {
    if (e.target.closest('[data-clear]')) { st.selected.clear(); renderBody(); renderBulk(); return; }
    const b = e.target.closest('[data-bulk]');
    if (!b) return;
    await cfg.bulk[+b.dataset.bulk].action([...st.selected]);
    st.selected.clear();
    renderBulk();
    load();
  });

  function renderPager() {
    const pages = Math.max(1, Math.ceil(st.total / st.per_page));
    const from = st.total ? (st.page - 1) * st.per_page + 1 : 0;
    const to = Math.min(st.total, st.page * st.per_page);
    pager.innerHTML = `<span>${from}–${to} de ${st.total}</span><div class="btns">
      <select data-pp aria-label="Itens por página" class="input" style="width:auto;padding:5px 8px">${[10, 25, 50, 100].map((n) => `<option ${n === st.per_page ? 'selected' : ''}>${n}</option>`).join('')}</select>
      <button class="btn btn-sm" data-prev ${st.page <= 1 ? 'disabled' : ''}>Anterior</button>
      <button class="btn btn-sm" data-next ${st.page >= pages ? 'disabled' : ''}>Próxima</button></div>`;
    $('[data-prev]', pager).addEventListener('click', () => { st.page--; load(); });
    $('[data-next]', pager).addEventListener('click', () => { st.page++; load(); });
    $('[data-pp]', pager).addEventListener('change', (e) => { st.per_page = +e.target.value; st.page = 1; load(); });
  }

  const params = () => ({ ...(cfg.query || {}), ...st.filters, q: st.q, page: st.page, per_page: st.per_page, sort: st.sort, dir: st.dir });

  async function load() {
    tbody.style.opacity = '.5';
    try {
      const res = await api(cfg.endpoint, { query: params() });
      st.rows = res.data; st.total = res.total;
      renderHead(); renderBody(); renderPager(); renderBulk();
      if (cfg.totals) $('[data-totals]', container).innerHTML = cfg.totals(st.rows, res);
      cfg.onLoad && cfg.onLoad(res);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="99"><div class="dt-empty">${icon('alert')}<div>${esc(err.message)}</div></div></td></tr>`;
    } finally {
      tbody.style.opacity = '';
    }
  }

  $('[data-q]', container).addEventListener('input', debounce((e) => { st.q = e.target.value; st.page = 1; load(); }, 300));
  $$('[data-filter]', container).forEach((el) => el.addEventListener('change', () => { st.filters[el.dataset.filter] = el.value; st.page = 1; load(); }));
  const exp = $('[data-export]', container);
  exp && exp.addEventListener('click', (e) => { e.preventDefault(); window.location.href = downloadUrl(cfg.exportPath, { ...(cfg.query || {}), ...st.filters }); });

  renderHead();
  load();
  return { reload: load, state: st, setFilter(name, value) { st.filters[name] = value; const el = $(`[data-filter="${name}"]`, container); if (el) el.value = value; st.page = 1; load(); } };
}

/* ------------------------------------------------------------------ charts */
export function chartColors() {
  const light = document.documentElement.dataset.theme === 'light';
  return { grid: light ? 'rgba(15,23,42,.08)' : 'rgba(255,255,255,.06)', text: light ? '#64748b' : '#8b97ad', orange: '#2f7bff', amber: '#00cf81', green: '#00cf81', red: '#f43f5e', violet: '#6d45f6', blue: '#38bdf8' };
}

export function chart(canvas, config) {
  if (!window.Chart) { canvas.parentElement.innerHTML = '<div class="empty-box">Gráfico indisponível (sem conexão com a CDN).</div>'; return null; }
  const c = chartColors();
  Chart.defaults.color = c.text;
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.borderColor = c.grid;
  const existing = Chart.getChart(canvas);
  existing && existing.destroy();
  config.options = Object.assign({ responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
    plugins: { legend: { labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${money(ctx.parsed.y ?? ctx.parsed)}` } } } }, config.options || {});
  return new Chart(canvas, config);
}

/** Render AI text safely: escape, then **bold**, bullets and line breaks. */
export function aiText(text) {
  return esc(text).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
}

export function emptyState(text, ico = 'search', action = '') {
  return `<div class="empty-box">${icon(ico, 'ico')}<p>${esc(text)}</p>${action}</div>`;
}

export function copyText(text) {
  navigator.clipboard.writeText(text).then(() => toast('Copiado para a área de transferência.'), () => toast('Não foi possível copiar.', 'error'));
}

/* ------------------------------------------------------------------ tags */
export const tagList = (tags) => String(tags || '').split(',').map((t) => t.trim()).filter(Boolean);
export const tagsHtml = (tags, { link = '' } = {}) => tagList(tags).map((t) => (link
  ? `<a class="tag-chip" href="${esc(link + encodeURIComponent(t))}">#${esc(t)}</a>`
  : `<span class="tag-chip">#${esc(t)}</span>`)).join('');

export const fileSize = (b) => { b = Number(b || 0); return b < 1024 ? b + ' B' : b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB'; };
const isImage = (name) => /\.(png|jpe?g|gif|webp|svg)$/i.test(name || '');

/* ------------------------------------------------------------------ activity timeline */
/**
 * Notes, calls, meetings and follow-ups (with due date) for any entity.
 * activityPanel(container, 'customer'|'lead'|'project'|'ticket', id, { onChange })
 */
export async function activityPanel(container, entity, id, opts = {}) {
  const kinds = Object.entries(LABELS.activity_kind).filter(([k]) => k !== 'event');
  container.innerHTML = `
    <form class="act-form" data-act-form>
      <div class="act-kinds" role="radiogroup" aria-label="Tipo">${kinds.map(([k, [t]], i) => `<label><input type="radio" name="kind" value="${k}" ${i === 0 ? 'checked' : ''}><span>${esc(t)}</span></label>`).join('')}</div>
      <textarea name="body" rows="2" class="input" placeholder="Registre uma anotação, ligação, reunião ou próximo passo..." aria-label="Descrição"></textarea>
      <div class="act-row"><label class="small muted">Lembrar em <input type="datetime-local" name="due_at" class="input" style="width:auto;padding:5px 8px"></label><span style="flex:1"></span><button class="btn btn-primary btn-sm">${icon('plus')} Registrar</button></div>
    </form>
    <div data-act-list><div class="loading-box">Carregando...</div></div>`;
  const list = $('[data-act-list]', container);
  const form = $('[data-act-form]', container);
  form.kind.forEach?.((r) => r.addEventListener('change', () => { if (r.checked && r.value === 'task' && !form.due_at.value) { const d = new Date(Date.now() + 86400000 - new Date().getTimezoneOffset() * 60000); form.due_at.value = d.toISOString().slice(0, 11) + '09:00'; } }));
  async function load() {
    const res = await api('/activities', { query: { entity, entity_id: id } });
    const now = new Date();
    list.innerHTML = res.data.length ? `<ul class="timeline">${res.data.map((a) => {
      const due = a.due_at ? new Date(a.due_at.replace(' ', 'T')) : null;
      const late = due && !Number(a.done) && due < now;
      return `<li class="tl-item ${a.kind} ${Number(a.done) ? 'done' : ''} ${late ? 'late' : ''}" data-id="${a.id}">
        <span class="tl-dot">${icon({ call: 'phone', whatsapp: 'message', email: 'mail', meeting: 'calendar', task: 'bell', event: 'bolt' }[a.kind] || 'edit')}</span>
        <div class="tl-body">
          <div class="tl-head">${badge('activity_kind', a.kind)}<b>${esc(a.user_name || '')}</b><span class="muted small">${datetime(a.created_at)}</span>
            ${a.kind !== 'event' ? `<span class="tl-actions">${a.due_at ? `<button class="btn btn-xs ${Number(a.done) ? '' : 'btn-success'}" data-done title="${Number(a.done) ? 'Reabrir' : 'Concluir'}">${icon(Number(a.done) ? 'undo' : 'check')}</button>` : ''}<button class="btn btn-xs btn-ghost" data-del title="Excluir">${icon('trash')}</button></span>` : ''}</div>
          <p>${esc(a.body)}</p>
          ${a.due_at ? `<small class="${late ? 'neg' : 'muted'}">${icon('clock')} ${Number(a.done) ? 'Concluído' : late ? 'Atrasado' : 'Para'} ${datetime(a.due_at)}</small>` : ''}
        </div></li>`;
    }).join('')}</ul>` : emptyState('Nenhum registro ainda. Anote ligações, reuniões e próximos passos aqui.', 'log');
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = form.body.value.trim();
    if (!body) { form.body.focus(); return; }
    const btn = $('button', form);
    btn.classList.add('loading');
    try {
      await api('/activities', { method: 'POST', body: { entity, entity_id: id, kind: form.querySelector('[name=kind]:checked').value, body, due_at: form.due_at.value ? form.due_at.value.replace('T', ' ') + ':00' : '' } });
      form.reset();
      await load();
      opts.onChange && opts.onChange();
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  list.addEventListener('click', async (e) => {
    const li = e.target.closest('[data-id]');
    if (!li) return;
    try {
      if (e.target.closest('[data-done]')) { await api('/activities/' + li.dataset.id, { method: 'PUT', body: { done: !li.classList.contains('done') } }); await load(); opts.onChange && opts.onChange(); }
      if (e.target.closest('[data-del]') && await confirmDialog('Excluir este registro?', { danger: true })) { await api('/activities/' + li.dataset.id, { method: 'DELETE' }); await load(); }
    } catch (err) { toastError(err); }
  });
  await load();
  return { reload: load };
}

/* ------------------------------------------------------------------ file attachments */
/** filesPanel(container, entity, id, { clientToggle: bool, title }) with drag & drop upload. */
export async function filesPanel(container, entity, id, opts = {}) {
  container.innerHTML = `
    <label class="dropzone" data-drop>
      <input type="file" multiple data-files hidden>
      ${icon('upload')}<span><b>Arraste arquivos aqui</b> ou clique para escolher<br><small class="muted">PDF, imagens, Office, ZIP · até 15 MB cada</small></span>
    </label>
    ${opts.clientToggle ? '<label class="check small" style="margin:8px 0 0"><input type="checkbox" data-visible checked> Visível para o cliente na Área do Cliente</label>' : ''}
    <div data-file-list style="margin-top:12px"></div>`;
  const drop = $('[data-drop]', container);
  const input = $('[data-files]', container);
  const listEl = $('[data-file-list]', container);
  async function load() {
    const res = await api('/attachments', { query: { entity, entity_id: id } });
    listEl.innerHTML = res.data.length ? `<ul class="files">${res.data.map((f) => `
      <li data-id="${f.id}">
        <a href="/api/attachments/${f.id}/download" target="_blank" rel="noopener" class="file-thumb">${isImage(f.file_name) ? `<img src="/api/attachments/${f.id}/download" alt="" loading="lazy">` : icon('file')}</a>
        <div class="grow"><a href="/api/attachments/${f.id}/download" target="_blank" rel="noopener"><b>${esc(f.file_name)}</b></a>
          <small class="muted">${fileSize(f.size_bytes)} · ${esc(f.uploaded_by_type === 'customer' ? 'Cliente' : f.uploaded_by_name || 'Equipe')} · ${datetime(f.created_at)}</small></div>
        ${opts.clientToggle ? `<button class="btn btn-xs ${Number(f.client_visible) ? 'btn-success' : ''}" data-vis title="${Number(f.client_visible) ? 'Visível ao cliente (clique para ocultar)' : 'Oculto do cliente (clique para mostrar)'}">${icon(Number(f.client_visible) ? 'eye' : 'lock')}</button>` : ''}
        <a class="btn btn-xs" href="/api/attachments/${f.id}/download?download=1" title="Baixar">${icon('download')}</a>
        <button class="btn btn-xs btn-ghost" data-del title="Excluir">${icon('trash')}</button>
      </li>`).join('')}</ul>` : '<p class="muted small" style="margin:0">Nenhum arquivo anexado.</p>';
    opts.onCount && opts.onCount(res.data.length);
  }
  async function upload(files) {
    if (!files.length) return;
    drop.classList.add('loading');
    try {
      await apiForm('/attachments', { entity, entity_id: id, client_visible: opts.clientToggle ? $('[data-visible]', container).checked : true, files });
      toast(files.length > 1 ? `${files.length} arquivos enviados.` : 'Arquivo enviado.');
      await load();
    } catch (err) { toastError(err); } finally { drop.classList.remove('loading'); input.value = ''; }
  }
  input.addEventListener('change', () => upload(input.files));
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', (e) => upload(e.dataTransfer.files));
  listEl.addEventListener('click', async (e) => {
    const li = e.target.closest('[data-id]');
    if (!li) return;
    try {
      if (e.target.closest('[data-vis]')) { const on = e.target.closest('[data-vis]').classList.contains('btn-success'); await api('/attachments/' + li.dataset.id, { method: 'PUT', body: { client_visible: !on } }); await load(); }
      if (e.target.closest('[data-del]') && await confirmDialog('Excluir este arquivo?', { danger: true })) { await api('/attachments/' + li.dataset.id, { method: 'DELETE' }); await load(); }
    } catch (err) { toastError(err); }
  });
  await load();
  return { reload: load };
}

/* ------------------------------------------------------------------ global search (Ctrl+K) */
export function openSearch() {
  if ($('.overlay [data-gsearch]')) return;
  const typeIcon = { customer: 'users', project: 'kanban', ticket: 'ticket', lead: 'target', entry: 'wallet' };
  const typeLabel = { customer: 'Cliente', project: 'Projeto', ticket: 'Chamado', lead: 'Lead', entry: 'Financeiro' };
  const m = modal({ title: 'Buscar em todo o painel', size: 'lg', footer: null, body: `
    <label class="search gsearch" data-gsearch>${icon('search')}<input type="search" placeholder="Cliente, projeto, protocolo, lead, lançamento..." aria-label="Buscar" autocomplete="off"></label>
    <div data-gresults class="gresults"><p class="muted small">Digite pelo menos 2 letras. Atalho: <kbd>Ctrl</kbd> + <kbd>K</kbd></p></div>` });
  const inp = $('input', m.el);
  const out = $('[data-gresults]', m.el);
  let sel = 0;
  let items = [];
  const paint = () => $$('a[data-i]', out).forEach((a) => a.classList.toggle('sel', +a.dataset.i === sel));
  const run = debounce(async () => {
    const q = inp.value.trim();
    if (q.length < 2) { out.innerHTML = '<p class="muted small">Digite pelo menos 2 letras.</p>'; items = []; return; }
    try {
      const res = await api('/search', { query: { q } });
      items = res.data;
      sel = 0;
      out.innerHTML = items.length ? items.map((r, i) => `<a href="${esc(r.url)}" data-i="${i}" class="gitem">${icon(typeIcon[r.type] || 'search')}<div class="grow"><b>${esc(r.title)}</b><small class="muted">${esc(typeLabel[r.type] || '')}${r.sub ? ' · ' + esc(r.sub) : ''}</small></div>${r.status ? `<span class="badge">${esc(r.status)}</span>` : ''}</a>`).join('') : `<p class="muted">Nada encontrado para "${esc(q)}".</p>`;
      paint();
    } catch (err) { out.innerHTML = `<p class="neg">${esc(err.message)}</p>`; }
  }, 200);
  inp.addEventListener('input', run);
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); paint(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); paint(); }
    if (e.key === 'Enter' && items[sel]) { e.preventDefault(); m.close(); location.hash = items[sel].url; }
  });
  out.addEventListener('click', (e) => { if (e.target.closest('a')) m.close(); });
  setTimeout(() => inp.focus(), 60);
}
