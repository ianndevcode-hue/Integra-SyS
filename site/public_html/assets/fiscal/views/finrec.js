/* Recorrências automáticas do financeiro: contas a pagar e a receber que se criam sozinhas (aluguel, salários, mensalidades...). */
import { api, $, esc, icon, money, date, modal, confirmDialog, toast, toastError, emptyState, today, debounce } from '/admin/js/core.js';
import { fin, KIND, catOptions, accOptions, methodOptions, statusBadge, needEdit, entryDetail } from '/assets/fiscal/fin.js';

const FREQ = { weekly: ['Semanal', 0, 7], biweekly: ['Quinzenal', 0, 14], monthly: ['Mensal', 1, 0], bimonthly: ['Bimestral', 2, 0], quarterly: ['Trimestral', 3, 0], semiannual: ['Semestral', 6, 0], yearly: ['Anual', 12, 0] };
const PLACEHOLDERS = [['{mes_ano}', 'mês/ano'], ['{mes}', 'mês'], ['{ano}', 'ano'], ['{mm/aaaa}', 'mm/aaaa'], ['{vencimento}', 'vencimento']];
const perMonth = (r) => { const [, m, d] = FREQ[r.frequency] || FREQ.monthly; return m ? Number(r.amount) / m : Number(r.amount) * 30 / d; };
const daysTo = (d) => Math.round((new Date(d + 'T12:00:00') - new Date(today() + 'T12:00:00')) / 86400000);
const whenLabel = (d) => { const n = daysTo(d); return n === 0 ? 'hoje' : n === 1 ? 'amanhã' : n < 0 ? `há ${-n} dia(s)` : `em ${n} dias`; };
const endLabel = (r) => (r.max_occurrences ? `${r.generated_count} de ${r.max_occurrences}` : r.end_date ? 'até ' + date(r.end_date) : 'sem data para terminar');

export async function render(el, ctx) {
  await fin.load(true);
  let rows = [];
  let kind = ['receivable', 'payable'].includes(ctx.query.kind) ? ctx.query.kind : '';
  let filter = 'active';
  let q = '';
  el.innerHTML = `<div class="page-head"><div><h2>Recorrências automáticas</h2><p>Cadastre uma vez e o sistema cria sozinho cada conta a pagar ou a receber antes do vencimento — aluguel, salários, mensalidades, assinaturas, impostos fixos.</p></div>
      <div class="page-actions"><a class="btn" href="#/financeiro/${kind === 'payable' ? 'pagar' : 'receber'}">${icon('list')} Ver lançamentos</a>
        <button class="btn" data-new="payable">${icon('trendDown')} Nova conta a pagar</button><button class="btn btn-primary" data-new="receivable">${icon('trendUp')} Nova conta a receber</button></div></div>
    <div class="grid g4 fh-rec-kpis" data-kpis></div>
    <div class="fh-rec-layout">
      <div>
        <div class="fh-rec-toolbar"><div class="chips-row" data-kinds></div><div class="chips-row" data-filters></div><input type="search" data-q placeholder="Buscar descrição ou favorecido..." aria-label="Buscar recorrência"></div>
        <div data-list></div>
      </div>
      <aside class="card fh-rec-next"><div class="card-head"><h3>${icon('calendar')} Próximos vencimentos</h3></div><div data-next></div></aside>
    </div>`;

  const draw = () => {
    const live = rows.filter((r) => Number(r.active) && !r.ended);
    const inc = live.filter((r) => r.kind === 'receivable').reduce((t, r) => t + perMonth(r), 0);
    const out = live.filter((r) => r.kind === 'payable').reduce((t, r) => t + perMonth(r), 0);
    const overdue = rows.reduce((t, r) => t + Number(r.overdue || 0), 0);
    $('[data-kpis]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico">${icon('refresh')}</span>Recorrências ativas</div><div class="k-value">${live.length}</div><div class="k-sub">${rows.length - live.length} pausada(s) ou encerrada(s)</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>A receber por mês</div><div class="k-value pos">${money(inc)}</div><div class="k-sub">${money(inc * 12)} por ano</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico red">${icon('trendDown')}</span>A pagar por mês</div><div class="k-value neg">${money(out)}</div><div class="k-sub">${money(out * 12)} por ano</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${overdue ? 'red' : 'violet'}">${icon(overdue ? 'alert' : 'check')}</span>Saldo recorrente / mês</div><div class="k-value ${inc - out < 0 ? 'neg' : 'pos'}">${money(inc - out)}</div><div class="k-sub">${overdue ? overdue + ' conta(s) vencida(s) em aberto' : 'nenhuma conta vencida'}</div></div>`;
    const byKind = rows.filter((r) => !kind || r.kind === kind);
    $('[data-kinds]', el).innerHTML = [['', 'Todas'], ['receivable', 'A receber'], ['payable', 'A pagar']]
      .map(([k, l]) => `<button class="fh-chip ${kind === k ? 'on' : ''}" data-k="${k}">${l} <b>${rows.filter((r) => !k || r.kind === k).length}</b></button>`).join('');
    const counts = { active: byKind.filter((r) => Number(r.active) && !r.ended).length, paused: byKind.filter((r) => !Number(r.active) || r.ended).length, all: byKind.length };
    $('[data-filters]', el).innerHTML = [['active', 'Ativas'], ['paused', 'Pausadas/encerradas'], ['all', 'Todas']]
      .map(([k, l]) => `<button class="fh-chip ${filter === k ? 'on' : ''}" data-f="${k}">${l} <b>${counts[k]}</b></button>`).join('');
    const list = byKind.filter((r) => (filter === 'all' || (filter === 'active' ? Number(r.active) && !r.ended : !Number(r.active) || r.ended))
      && (!q || `${r.description} ${r.party_name || ''} ${r.category_name || ''}`.toLowerCase().includes(q)));
    $('[data-list]', el).innerHTML = !rows.length
      ? `<div class="card fh-rec-empty">${icon('refresh')}<h3>Nunca mais esqueça uma conta fixa</h3><p class="muted">Aluguel, internet, salários, pró-labore, mensalidades de clientes: cadastre uma vez e cada conta aparece sozinha em Contas a pagar ou a receber, com a antecedência que você escolher. Pode até dar baixa automática no vencimento.</p>
          <button class="btn" data-new="payable">${icon('trendDown')} Conta a pagar</button> <button class="btn btn-primary" data-new="receivable">${icon('trendUp')} Conta a receber</button></div>`
      : list.length ? `<div class="fh-rec-grid">${list.map(card).join('')}</div>` : emptyState('Nenhuma recorrência neste filtro.', 'search');
    const events = rows.filter((r) => (!kind || r.kind === kind) && (Number(r.active) || r.open_dues?.length)).flatMap((r) => (Number(r.active) ? r.upcoming || [] : r.open_dues || []).slice(0, 4).map((d) => ({ d, r }))).sort((a, b) => a.d.localeCompare(b.d)).slice(0, 10);
    $('[data-next]', el).innerHTML = events.length ? `<ul class="fh-rec-timeline">${events.map(({ d, r }) => `<li class="${daysTo(d) < 0 ? 'late' : ''}"><span class="fh-rec-date ${r.kind === 'payable' ? 'out' : ''}"><b>${d.slice(8, 10)}</b>${new Date(d + 'T12:00:00').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '')}</span>
        <div class="grow"><b>${esc(r.description.replace(/\{[^}]+\}/g, '').replace(/\s+/g, ' ').trim())}</b><small>${r.kind === 'payable' ? 'pagar' : 'receber'} ${money(r.amount)} · ${whenLabel(d)}${r.open_dues?.includes(d) ? ' · já lançada' : ''}</small></div></li>`).join('')}</ul>
        <p class="muted small" style="padding:0 18px 16px;margin:0">"já lançada" = a conta já está em Contas a pagar/receber; as demais são criadas com a antecedência escolhida.</p>`
      : '<p class="muted small" style="padding:0 18px 18px">Nenhum vencimento programado.</p>';
  };

  const card = (r) => {
    const on = Number(r.active) && !r.ended;
    const K = KIND[r.kind];
    return `<section class="card fh-rec ${on ? '' : 'off'} ${r.overdue ? 'err' : ''}" data-id="${r.id}">
      <div class="fh-rec-head"><div><h3>${esc(r.preview || r.description)}</h3><small class="muted">${esc(r.party_name || (r.kind === 'payable' ? 'Sem fornecedor' : 'Sem cliente'))}${r.category_name ? ' · ' + esc(r.category_name) : ''}</small></div>
        <div class="fh-rec-badges"><span class="badge ${r.kind === 'payable' ? 'red' : 'green'}">${r.kind === 'payable' ? 'A pagar' : 'A receber'}</span>${r.ended ? '<span class="badge">Encerrada</span>' : Number(r.active) ? '' : '<span class="badge">Pausada</span>'}<span class="badge blue">${esc(r.frequency_label)}</span></div></div>
      <div class="fh-rec-body">
        <div class="fh-rec-amount ${r.kind === 'payable' ? 'neg' : 'pos'}">${money(r.amount)}<small>${esc(r.frequency_label.toLowerCase())}${r.day_of_month ? ' · todo dia ' + r.day_of_month : ''}</small></div>
        <dl class="fh-rec-facts">
          <div><dt>Próximo vencimento</dt><dd>${(r.upcoming || []).length && (on || r.open_dues?.length) ? `<b>${date(r.upcoming[0])}</b> <span class="${daysTo(r.upcoming[0]) < 0 ? 'neg' : 'muted'}">${whenLabel(r.upcoming[0])}</span>` : `<span class="muted">${r.ended ? 'encerrada' : 'pausada'}</span>`}</dd></div>
          <div><dt>Duração</dt><dd>${esc(endLabel(r))}</dd></div>
          <div><dt>Contas geradas</dt><dd>${r.entries} · ${r.open} em aberto${r.overdue ? ` · <b class="neg">${r.overdue} vencida(s)</b>` : ''}</dd></div>
          <div><dt>${K.done}</dt><dd>${money(r.paid_total)}</dd></div>
          <div><dt>Criação</dt><dd>${Number(r.lead_days) ? r.lead_days + ' dias antes do vencimento' : 'no dia do vencimento'}</dd></div>
          <div><dt>Baixa</dt><dd>${Number(r.auto_settle) ? `${icon('check')} automática${r.account_name ? ' · ' + esc(r.account_name) : ''}` : 'manual'}</dd></div>
        </dl>
      </div>
      <div class="fh-card-actions">
        <button class="btn btn-sm btn-primary" data-act="gen" ${on ? '' : 'disabled'} title="Cria agora a conta do próximo vencimento">${icon('plus')} Gerar próxima</button>
        <button class="btn btn-sm" data-act="toggle" ${r.ended ? 'disabled' : ''}>${icon(Number(r.active) ? 'clock' : 'play')} ${Number(r.active) ? 'Pausar' : 'Retomar'}</button>
        <button class="btn btn-sm" data-act="history">${icon('list')} Contas</button>
        <button class="btn btn-sm btn-ghost" data-act="edit">${icon('edit')} Editar</button>
        <button class="btn btn-sm btn-ghost btn-icon" data-act="del" title="Excluir">${icon('trash')}</button>
      </div></section>`;
  };

  const load = async () => { rows = (await api('/fh/fin/recurring')).data; draw(); };

  el.addEventListener('click', async (e) => {
    const n = e.target.closest('[data-new]');
    if (n) return recForm(null, n.dataset.new, load);
    const k = e.target.closest('[data-k]');
    if (k) { kind = k.dataset.k; draw(); return; }
    const f = e.target.closest('[data-f]');
    if (f) { filter = f.dataset.f; draw(); return; }
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const r = rows.find((x) => x.id === +b.closest('[data-id]').dataset.id);
    try {
      if (b.dataset.act === 'edit') recForm(r, r.kind, load);
      else if (b.dataset.act === 'history') history(r, load);
      else if (b.dataset.act === 'toggle') { if (!needEdit()) return; await api(`/fh/fin/recurring/${r.id}/toggle`, { method: 'POST' }); toast(Number(r.active) ? 'Recorrência pausada: nenhuma conta nova será criada.' : 'Recorrência retomada.'); await load(); }
      else if (b.dataset.act === 'gen') {
        if (!needEdit()) return;
        b.classList.add('loading');
        const res = await api(`/fh/fin/recurring/${r.id}/generate`, { method: 'POST' }).finally(() => b.classList.remove('loading'));
        toast(res.created ? `Conta de ${date(r.schedule[0])} lançada em ${KIND[r.kind].title}.` : 'Nada a gerar.');
        await load();
      } else if (b.dataset.act === 'del') {
        if (!needEdit()) return;
        const choice = await delChoice(r);
        if (!choice) return;
        const res = await api(`/fh/fin/recurring/${r.id}${choice === 'all' ? '?open=1' : ''}`, { method: 'DELETE' });
        toast(res.deleted_entries ? `Recorrência excluída e ${res.deleted_entries} conta(s) futura(s) removida(s).` : 'Recorrência excluída. As contas já criadas continuam nos lançamentos.');
        await load();
      }
    } catch (err) { toastError(err); }
  });
  $('[data-q]', el).addEventListener('input', debounce((e) => { q = e.target.value.trim().toLowerCase(); draw(); }, 150));
  await load();
}

function delChoice(r) {
  return new Promise((resolve) => {
    let v = null;
    const m = modal({
      title: 'Excluir recorrência', size: 'sm',
      body: `<p style="margin:0">Nenhuma conta nova será criada para <b>${esc(r.preview || r.description)}</b>. O que fazer com as ${r.open} conta(s) em aberto que ela já criou?</p><p class="small muted">As contas pagas/recebidas sempre ficam no histórico.</p>`,
      footer: '<button class="btn" data-s="keep">Manter as contas</button><button class="btn btn-danger" data-s="all">Excluir as futuras em aberto</button>',
      onClose: () => resolve(v),
    });
    m.el.querySelectorAll('[data-s]').forEach((b) => b.addEventListener('click', () => { v = b.dataset.s; m.close(); }));
  });
}

async function history(r, onChange) {
  const m = modal({ title: 'Contas geradas · ' + (r.preview || r.description), size: 'lg', body: '<p class="muted">Carregando...</p>', footer: null });
  const list = (await api(`/fh/fin/recurring/${r.id}/entries`)).data;
  m.body.innerHTML = list.length ? `<div class="table-wrap"><table class="dt"><thead><tr><th>Vencimento</th><th>Descrição</th><th class="num">Valor</th><th>Situação</th></tr></thead><tbody>${list.map((e) => `<tr data-e="${e.id}" style="cursor:pointer">
      <td><b>${date(e.due_date)}</b>${e.status === 'paid' ? `<br><small class="muted">${KIND[e.kind].done.toLowerCase()} ${date(e.paid_at)}</small>` : ''}</td><td>${esc(e.description)}</td><td class="num">${money(e.amount)}</td><td>${statusBadge(e)}</td></tr>`).join('')}</tbody></table></div>`
    : emptyState('Nenhuma conta criada ainda: a primeira aparece ' + (Number(r.lead_days) ? r.lead_days + ' dias antes do vencimento.' : 'no dia do vencimento.'), 'wallet');
  m.body.addEventListener('click', (e) => { const tr = e.target.closest('[data-e]'); if (tr) { m.close(); entryDetail(+tr.dataset.e, onChange); } });
}

/** New / edit template with live preview of the next due dates. */
export async function recForm(r, kind, onSaved) {
  if (!needEdit()) return;
  await fin.load();
  const K = KIND[kind];
  const v = r ? { ...r } : { frequency: 'monthly', start_date: today(), lead_days: 30, description: kind === 'payable' ? 'Aluguel {mes_ano}' : 'Mensalidade {mes_ano}', end_mode: 'none', active: 1 };
  v.end_mode = r ? (r.max_occurrences ? 'count' : r.end_date ? 'date' : 'none') : 'none';
  const opt = (list, sel) => list.map((o) => `<option value="${esc(o.value)}" ${String(o.value) === String(sel ?? '') ? 'selected' : ''}>${esc(o.label)}</option>`).join('');
  const m = modal({
    title: (r ? 'Editar' : 'Nova') + ' recorrência · ' + (kind === 'payable' ? 'conta a pagar' : 'conta a receber'), size: 'lg',
    body: `<form data-rf novalidate><div class="form-grid cols-4">
      <div class="field span-2" style="grid-column:span 3"><label>Descrição *</label><input name="description" value="${esc(v.description || '')}" placeholder="${kind === 'payable' ? 'Ex.: Aluguel {mes_ano}' : 'Ex.: Mensalidade {mes_ano}'}">
        <div class="fh-rec-ph">${PLACEHOLDERS.map(([p, l]) => `<button type="button" class="fh-chip" data-ph="${p}">+ ${l}</button>`).join('')}<span class="help">trocados pelo mês de cada vencimento</span></div></div>
      <div class="field"><label>Valor (R$) *</label><input name="amount" inputmode="decimal" value="${esc(v.amount ?? '')}" placeholder="0,00"></div>
      <div class="field"><label>Periodicidade</label><select name="frequency">${opt(Object.entries(FREQ).map(([value, [label]]) => ({ value, label })), v.frequency)}</select></div>
      <div class="field"><label>${r && Number(r.generated_count) ? 'Primeiro vencimento (histórico)' : 'Primeiro vencimento *'}</label><input name="start_date" type="date" value="${esc(v.start_date || '')}" ${r && Number(r.generated_count) ? 'disabled' : ''}><span class="help">o dia se repete nos meses seguintes</span></div>
      <div class="field"><label>Termina</label><select name="end_mode">${opt([{ value: 'none', label: 'Nunca (sem fim)' }, { value: 'date', label: 'Em uma data' }, { value: 'count', label: 'Depois de N vezes' }], v.end_mode)}</select></div>
      <div class="field" data-end="date"><label>Última data</label><input name="end_date" type="date" value="${esc(v.end_date || '')}"></div>
      <div class="field" data-end="count"><label>Quantidade de vezes</label><input name="max_occurrences" type="number" min="1" max="1000" value="${esc(v.max_occurrences || 12)}"></div>
      <div class="field span-2"><label>${K.party}</label><input name="party_name" value="${esc(v.party_name || '')}" placeholder="${kind === 'payable' ? 'Ex.: Imobiliária, Copel, contador' : 'Nome do cliente'}"></div>
      <div class="field"><label>Categoria</label><select name="category_id"><option value="">Sem categoria</option>${opt(catOptions(kind), v.category_id)}</select></div>
      <div class="field"><label>Conta</label><select name="account_id"><option value="">Definir na baixa</option>${opt(accOptions(), v.account_id)}</select></div>
      <div class="field"><label>Forma de pagamento</label><select name="payment_method"><option value="">—</option>${opt(methodOptions(), v.payment_method)}</select></div>
      <div class="field"><label>Criar a conta</label><select name="lead_days">${opt([0, 3, 5, 7, 10, 15, 30, 45, 60].map((d) => ({ value: d, label: d ? d + ' dias antes do vencimento' : 'no dia do vencimento' })), v.lead_days)}</select></div>
      <label class="check span-2" style="align-self:end"><input type="checkbox" name="auto_settle" ${Number(v.auto_settle) ? 'checked' : ''}> <span>Dar baixa automática no vencimento<small class="muted" style="display:block">débito automático, cartão, salário em conta</small></span></label>
      <div class="field span-2" style="grid-column:1/-1"><label>Observações</label><textarea name="notes" rows="2">${esc(v.notes || '')}</textarea></div>
      ${r && r.open ? `<label class="check" style="grid-column:1/-1"><input type="checkbox" name="apply_open" checked> Aplicar valor, descrição e demais dados também às ${r.open} conta(s) em aberto já criadas</label>` : ''}
    </div>
    <div class="fh-rec-live" data-live></div></form>`,
    footer: `<button class="btn" data-close>Cancelar</button><button class="btn btn-primary" data-save>${icon('check')} ${r ? 'Salvar alterações' : 'Criar recorrência'}</button>`,
  });
  const form = $('[data-rf]', m.body);
  const F = form.elements;
  const read = () => {
    const d = { description: F.description.value.trim(), amount: F.amount.value, frequency: F.frequency.value, party_name: F.party_name.value, category_id: F.category_id.value, account_id: F.account_id.value,
      payment_method: F.payment_method.value, lead_days: F.lead_days.value, auto_settle: F.auto_settle.checked, notes: F.notes.value,
      end_date: F.end_mode.value === 'date' ? F.end_date.value : '', max_occurrences: F.end_mode.value === 'count' ? F.max_occurrences.value : '' };
    if (!F.start_date.disabled) d.start_date = F.start_date.value;
    if (!r) d.kind = kind;
    if (F.apply_open) d.apply_open = F.apply_open.checked;
    return d;
  };
  const sync = () => form.querySelectorAll('[data-end]').forEach((x) => x.classList.toggle('hidden', x.dataset.end !== F.end_mode.value));
  const live = debounce(async () => {
    try {
      const p = await api('/fh/fin/recurring/preview', { method: 'POST', body: { ...read(), start_date: F.start_date.value } });
      $('[data-live]', m.body).innerHTML = `<div><b>${icon('calendar')} Próximos vencimentos</b><div class="chips-row">${p.schedule.map((d) => `<span class="fh-chip on">${date(d)}</span>`).join('') || '<span class="muted small">nenhum (confira o término)</span>'}</div></div>
        <div><b>${icon('eye')} Como a conta vai aparecer</b><p>“${esc(p.text)}” — ${money(Number(String(F.amount.value).replace(/\./g, '').replace(',', '.')) || 0)}${r ? '' : p.first_created ? `<br><small>A primeira conta é criada ${p.first_created <= today() ? 'agora, ao salvar' : 'em ' + date(p.first_created)}.</small>` : ''}</p></div>`;
    } catch (e) { /* preview is optional */ }
  }, 250);
  form.addEventListener('input', live);
  form.addEventListener('change', () => { sync(); live(); });
  form.addEventListener('click', (e) => {
    const ph = e.target.closest('[data-ph]');
    if (!ph) return;
    const i = F.description.selectionStart ?? F.description.value.length;
    F.description.value = F.description.value.slice(0, i) + ph.dataset.ph + F.description.value.slice(F.description.selectionEnd ?? i);
    F.description.focus();
    live();
  });
  sync();
  live();
  $('[data-save]', m.foot).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      const saved = await api('/fh/fin/recurring' + (r ? '/' + r.id : ''), { method: r ? 'PUT' : 'POST', body: read() });
      m.close();
      toast(r ? 'Recorrência atualizada.' : saved.entries ? `Recorrência criada: ${saved.entries} conta(s) já lançada(s) em ${K.title}.` : `Recorrência criada: a primeira conta aparece em ${K.title} ${Number(saved.lead_days) ? saved.lead_days + ' dias antes de vencer' : 'no dia do vencimento'}.`);
      onSaved && onSaved(saved);
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
}
