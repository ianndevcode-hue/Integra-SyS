/* Subscription self-service: status, payment, plan change, history, cancel. Also the "no plan" screen. */
import { api, $, $$, esc, icon, money, date, datetime, badge, formModal, confirmDialog, toast, toastError, BRAND_MARK } from '/admin/js/core.js';
import { fh } from '/assets/fiscal/state.js';

const CHARGE = { PENDING: ['Aguardando', 'blue'], OVERDUE: ['Vencida', 'red'], RECEIVED: ['Paga', 'green'], CONFIRMED: ['Paga', 'green'], RECEIVED_IN_CASH: ['Paga', 'green'], DELETED: ['Cancelada', ''], CANCELED: ['Cancelada', ''], REFUNDED: ['Estornada', 'violet'] };

export function renderNoPlan(root) {
  root.innerHTML = `<div class="auth-body"><div class="auth-card fh-noplan">
    <div class="auth-logo">${BRAND_MARK}<b class="fh-name">Integra <span>Fiscal Hub</span></b></div>
    <h1>Emita suas notas fiscais de serviço em segundos</h1>
    <p class="muted">SIGISS de Marília e Emissor Nacional, cálculo automático de ISS e retenções, envio ao cliente, downloads em lote e relatórios com IA.</p>
    <a class="btn btn-primary btn-block" href="/fiscal-hub#planos">Ver planos e contratar</a>
    <a class="btn btn-ghost btn-block" href="/cliente/" style="margin-top:8px">Voltar à Área do Cliente</a></div></div>`;
}

export async function render(el) {
  const d = await api('/fh/subscription');
  const a = d.access;
  const s = a.sub;
  const cur = a.plan || {};
  const u = a.usage || {};
  el.innerHTML = `
    <div class="page-head"><div><h2>Assinatura</h2><p>Plano, pagamentos e limites do seu Fiscal Hub.</p></div></div>
    <div class="grid g3">
      <section class="card span-2 fh-plan-card"><div class="card-head"><div><h3>Plano ${esc(cur.name || s.plan_code)}</h3><small class="muted">${esc(cur.tagline || '')}</small></div>${badge('fh_sub_status', s.status)}</div><div class="card-body">
        <div class="fh-plan-price"><b>${money(s.price)}</b><span>/${s.cycle === 'yearly' ? 'ano' : 'mês'}</span></div>
        <dl class="kv">
          <dt>Acesso liberado até</dt><dd>${s.paid_until ? `<b>${date(s.paid_until)}</b>` : '—'}${a.grace_until && a.state === 'grace' ? ` · tolerância até ${date(a.grace_until)}` : ''}</dd>
          <dt>Próxima cobrança</dt><dd>${s.next_charge_date && s.status !== 'canceled' ? date(s.next_charge_date) : '—'}</dd>
          <dt>Contratado em</dt><dd>${datetime(s.created_at)}${s.terms_version ? ` · termos v${esc(s.terms_version)} aceitos por ${esc(s.terms_name || '')}` : ''}</dd>
        </dl>
        ${d.open_charge ? `<div class="alert ${d.open_charge.status === 'OVERDUE' ? 'alert-danger' : 'alert-info'}" style="margin:14px 0 0"><div><b>Fatura em aberto: ${money(d.open_charge.amount)}</b> · vence ${date(d.open_charge.due_date)}</div><div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">${d.open_charge.invoice_url ? `<a class="btn btn-primary btn-sm" href="${esc(d.open_charge.invoice_url)}" target="_blank" rel="noopener">${icon('receipt')} Pagar (PIX, boleto ou cartão)</a>` : ''}<button class="btn btn-sm" data-check>${icon('refresh')} Já paguei — verificar</button></div></div>`
          : ['pending', 'expired', 'grace'].includes(a.state) && s.status !== 'canceled' ? `<div class="alert alert-warning" style="margin:14px 0 0">${esc(a.message || '')}<div style="margin-top:10px"><button class="btn btn-primary btn-sm" data-pay>${icon('receipt')} Gerar pagamento</button></div></div>` : ''}
      </div></section>
      <section class="card"><div class="card-head"><h3>Uso neste mês</h3></div><div class="card-body fh-usage">
        ${bar('Notas fiscais', u.notes_used, u.notes_limit)}${bar('Empresas', u.companies, u.companies_limit)}${bar('Análises com IA', u.ai_used, u.ai_limit)}
        ${Number(s.bonus_notes) ? `<p class="small muted" style="margin:8px 0 0">Inclui ${s.bonus_notes} nota(s) extra(s) cortesia por mês.</p>` : ''}
      </div></section>
    </div>
    <h3 style="margin:24px 0 12px">Planos</h3>
    <div class="seg" data-cycle><button data-c="monthly" class="${s.cycle !== 'yearly' ? 'active' : ''}">Mensal</button><button data-c="yearly" class="${s.cycle === 'yearly' ? 'active' : ''}">Anual (2 meses grátis)</button></div>
    <div class="fh-plans" data-plans></div>
    <p class="small muted">Mudanças de plano valem a partir da próxima renovação. Precisa de mais notas agora? <a href="/cliente/?aba=suporte#novo">Fale com a equipe</a>.</p>
    <div class="grid g2" style="margin-top:16px">
      <section class="card"><div class="card-head"><h3>Faturas</h3></div>${d.charges.length ? `<ul class="list">${d.charges.map((c) => `<li><div class="grow"><b>${money(c.amount)}</b><small>${esc(c.description || '')} · vence ${date(c.due_date)}${c.paid_at ? ' · paga em ' + date(c.paid_at) : ''}</small></div><span class="badge ${(CHARGE[c.status] || ['', ''])[1]}">${esc((CHARGE[c.status] || [c.status])[0])}</span>${c.invoice_url ? `<a class="btn btn-xs" href="${esc(c.invoice_url)}" target="_blank" rel="noopener">${icon('external')}</a>` : ''}</li>`).join('')}</ul>` : '<div class="empty-box">Nenhuma fatura ainda.</div>'}</section>
      <section class="card"><div class="card-head"><h3>Histórico</h3></div><ul class="list">${d.events.map((e) => `<li><div class="grow"><b style="white-space:normal">${esc(e.description)}</b><small>${datetime(e.created_at)}</small></div></li>`).join('')}</ul></section>
    </div>
    ${s.status !== 'canceled' ? `<p style="margin-top:20px"><button class="btn btn-ghost btn-sm" data-cancel>Cancelar assinatura</button></p>` : ''}`;
  let cycle = s.cycle;
  const drawPlans = () => {
    $('[data-plans]', el).innerHTML = d.plans.map((p) => {
      const price = cycle === 'yearly' ? p.price_yearly : p.price_monthly;
      const current = p.code === s.plan_code && cycle === s.cycle;
      return `<article class="card fh-pcard ${p.highlight ? 'hl' : ''} ${current ? 'current' : ''}"><div class="card-body">
        <h4>${esc(p.name)}</h4><small class="muted">${esc(p.tagline)}</small>
        <div class="fh-plan-price"><b>${money(price)}</b><span>/${cycle === 'yearly' ? 'ano' : 'mês'}</span></div>
        <ul>${p.features.slice(0, 4).map((f) => `<li>${icon('check')} ${esc(f)}</li>`).join('')}</ul>
        <button class="btn btn-block ${current ? '' : 'btn-primary'}" data-plan="${p.code}" ${current ? 'disabled' : ''}>${current ? 'Plano atual' : 'Escolher'}</button></div></article>`;
    }).join('');
  };
  drawPlans();
  $$('[data-c]', el).forEach((b) => b.addEventListener('click', () => { cycle = b.dataset.c; $$('[data-c]', el).forEach((x) => x.classList.toggle('active', x === b)); drawPlans(); }));
  $('[data-plans]', el).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-plan]');
    if (!b) return;
    const p = d.plans.find((x) => x.code === b.dataset.plan);
    if (!await confirmDialog(`Mudar para o plano ${p.name} (${cycle === 'yearly' ? 'anual' : 'mensal'}) por ${money(cycle === 'yearly' ? p.price_yearly : p.price_monthly)}? ${s.status === 'pending' ? 'Uma nova fatura será gerada.' : 'O novo valor vale a partir da próxima renovação.'}`, { okLabel: 'Confirmar' })) return;
    try {
      const r = await api('/fh/subscription/plan', { method: 'POST', body: { plan: p.code, cycle } });
      toast('Plano alterado.');
      if (r.pay_url) window.open(r.pay_url, '_blank');
      window.dispatchEvent(new Event('fh:reload'));
    } catch (err) { toastError(err); }
  });
  const pay = async (btn) => {
    btn.classList.add('loading');
    try {
      const r = await api('/fh/subscription/pay', { method: 'POST' });
      if (r.paid) { toast('Pagamento confirmado! Emissão liberada.'); window.dispatchEvent(new Event('fh:reload')); return; }
      if (r.pay_url) window.open(r.pay_url, '_blank');
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  };
  $('[data-pay]', el)?.addEventListener('click', (e) => pay(e.currentTarget));
  $('[data-check]', el)?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.classList.add('loading');
    try {
      const r = await api('/fh/subscription/pay', { method: 'POST' });
      if (r.paid) { toast('Pagamento confirmado! Emissão liberada.'); window.dispatchEvent(new Event('fh:reload')); } else toast('Ainda não recebemos a confirmação. PIX costuma levar poucos minutos; boleto até 3 dias úteis.', 'error');
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
  $('[data-cancel]', el)?.addEventListener('click', () => formModal({
    title: 'Cancelar assinatura', size: 'sm', submitLabel: 'Cancelar assinatura',
    intro: `<div class="alert alert-warning" style="margin:0">Você continua emitindo até ${s.paid_until ? date(s.paid_until) : 'o fim do período pago'}. Depois disso, as notas ficam disponíveis para consulta e download.</div>`,
    fields: [{ name: 'reason', label: 'Conte o motivo (opcional)', type: 'textarea', rows: 3, span: 2 }],
    onSubmit: async (dd) => { await api('/fh/subscription/cancel', { method: 'POST', body: dd }); toast('Assinatura cancelada.'); window.dispatchEvent(new Event('fh:reload')); },
  }));
}

function bar(label, used = 0, limit = 0) {
  const pct = limit ? Math.min(100, (used / limit) * 100) : 0;
  return `<div class="fh-usage-row"><div><span>${label}</span><b>${used} / ${limit}</b></div><div class="progress ${pct >= 90 ? 'danger' : ''}"><i style="width:${pct}%"></i></div></div>`;
}
