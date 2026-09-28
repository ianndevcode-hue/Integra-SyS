/* Integra Fiscal Hub — shared client state (session, access, current company, cached lists). */
import { api, state } from '/admin/js/core.js';

state.csrf = window.FH_BOOT?.csrf || null;

export const fh = {
  me: null,
  emitterId: null,
  cache: { takers: {}, services: {}, lc116: null },

  async refresh() {
    this.me = await api('/fh/me');
    const active = this.me.emitters.filter((e) => Number(e.active));
    let saved = null;
    try { saved = Number(localStorage.getItem('fh-emitter')); } catch (e) { /* ignore */ }
    if (!active.find((e) => e.id === this.emitterId)) this.emitterId = (active.find((e) => e.id === saved) || active[0])?.id || null;
    return this.me;
  },
  get access() { return this.me?.access || {}; },
  get plan() { return this.me?.access?.plan || null; },
  emitter() { return this.me?.emitters.find((e) => e.id === this.emitterId) || null; },
  activeEmitters() { return (this.me?.emitters || []).filter((e) => Number(e.active)); },
  setEmitter(id) {
    this.emitterId = Number(id) || null;
    try { localStorage.setItem('fh-emitter', String(this.emitterId)); } catch (e) { /* ignore */ }
  },
  flag(name) { return !!this.plan?.flags?.[name]; },
  async takers(force = false) {
    if (!this.emitterId) return [];
    if (force || !this.cache.takers[this.emitterId]) this.cache.takers[this.emitterId] = (await api(`/fh/emitters/${this.emitterId}/takers`)).data;
    return this.cache.takers[this.emitterId];
  },
  async services(force = false) {
    if (!this.emitterId) return [];
    if (force || !this.cache.services[this.emitterId]) this.cache.services[this.emitterId] = (await api(`/fh/emitters/${this.emitterId}/services`)).data;
    return this.cache.services[this.emitterId];
  },
  async lc116() {
    if (!this.cache.lc116) this.cache.lc116 = (await api('/fh/lc116')).data;
    return this.cache.lc116;
  },
  invalidate() { this.cache.takers = {}; this.cache.services = {}; },
};

export const SITUATIONS = [
  ['tp', 'Tributável — ISS devido pelo prestador'], ['tt', 'Tributável — ISS retido pelo tomador'], ['ti', 'Tributável — ISS retido pelo intermediário'],
  ['is', 'Isenta (benefício municipal)'], ['im', 'Imune (Constituição, art. 150)'], ['nt', 'Não incidência'], ['ex', 'Exportação de serviço'], ['es', 'Exigibilidade suspensa'],
];
export const REGIMES = [['1', 'Não optante do Simples (Lucro Presumido/Real)'], ['2', 'MEI — Microempreendedor Individual'], ['3', 'Simples Nacional — ME/EPP']];
export const CURRENCIES = [['220', 'Dólar dos EUA (USD)'], ['978', 'Euro (EUR)'], ['540', 'Libra esterlina (GBP)'], ['165', 'Dólar canadense (CAD)'], ['150', 'Dólar australiano (AUD)'], ['425', 'Franco suíço (CHF)'], ['470', 'Iene (JPY)']];
export const COUNTRIES = [['US', 'Estados Unidos'], ['PT', 'Portugal'], ['ES', 'Espanha'], ['GB', 'Reino Unido'], ['DE', 'Alemanha'], ['FR', 'França'], ['IT', 'Itália'], ['CA', 'Canadá'], ['AR', 'Argentina'], ['UY', 'Uruguai'], ['PY', 'Paraguai'], ['CL', 'Chile'], ['MX', 'México'], ['JP', 'Japão'], ['CN', 'China'], ['AU', 'Austrália'], ['CH', 'Suíça'], ['NL', 'Holanda'], ['IE', 'Irlanda'], ['AE', 'Emirados Árabes']];
export const provName = (p) => (p === 'nacional' ? 'Emissor Nacional' : 'Prefeitura de Marília (SIGISS)');
export const fmtDoc = (d) => { const s = String(d || '').replace(/\D/g, ''); return s.length === 14 ? s.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5') : s.length === 11 ? s.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4') : (d || '—'); };
export const num = (v) => { if (v === '' || v == null) return ''; const s = String(v).trim(); return s.includes(',') ? Number(s.replace(/\./g, '').replace(',', '.')) : Number(s); };
