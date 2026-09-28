/* Extrato bancário e conciliação: importação OFX/CSV ou sincronização automática, sugestões, conciliar, lançar, ignorar. */
import { api, $, $$, esc, icon, money, date, modal, formModal, confirmDialog, toast, toastError, emptyState, today } from '/admin/js/core.js';
import { fin, KIND, importModal, catOptions, signed, needEdit, isoAdd } from '/assets/fiscal/fin.js';

export async function render(el, ctx) {
  await fin.load(true);
  const accounts = fin.accounts(true);
  const st = {
    account_id: ctx.query.account || (accounts.find((a) => a.pending) || accounts[0] || {}).id || '',
    from: ctx.query.from || isoAdd(today(), -60), to: ctx.query.to || today(), status: ctx.query.status ?? 'pending', q: '', page: 1,
  };
  el.innerHTML = `
    <div class="page-head"><div><h2>Extrato e conciliação</h2><p>Importe o extrato do banco (ou sincronize automaticamente) e confirme cada movimentação com as contas a pagar e a receber.</p></div>
      <div class="page-actions">
        <button class="btn" data-auto>${icon('bolt')} Conciliar automaticamente</button>
        <button class="btn hidden" data-sync>${icon('refresh')} Sincronizar banco</button>
        <button class="btn btn-primary" data-import>${icon('upload')} Importar OFX/CSV</button>
      </div></div>
    <div class="card fin-filters"><div class="toolbar">
      <select data-f="account_id" aria-label="Conta">${accounts.map((a) => `<option value="${a.id}">${esc(a.name)}${a.pending ? ` (${a.pending})` : ''}</option>`).join('')}<option value="">Todas as contas</option></select>
      <input type="date" data-f="from" value="${st.from}" aria-label="De"><input type="date" data-f="to" value="${st.to}" aria-label="Até">
      <div class="seg" data-st>${[['pending', 'A conciliar'], ['reconciled', 'Conciliadas'], ['ignored', 'Ignoradas'], ['', 'Todas']].map(([v, l]) => `<button type="button" data-s="${v}">${l}</button>`).join('')}</div>
      <label class="search">${icon('search')}<input type="search" data-q placeholder="Buscar no extrato..." aria-label="Buscar"></label>
    </div></div>
    <div data-head></div>
    <div data-list><div class="loading-box">Carregando...</div></div>`;
  $('[data-f=account_id]', el).value = String(st.account_id);
  const acc = () => fin.accounts(true).find((a) => String(a.id) === String(st.account_id));
  const load = async () => {
    $$('[data-s]', el).forEach((b) => b.classList.toggle('active', b.dataset.s === st.status));
    const a = acc();
    $('[data-sync]', el).classList.toggle('hidden', !(a && a.connection_id));
    const list = $('[data-list]', el);
    list.style.opacity = '.5';
    try {
      const r = await api('/fh/fin/transactions', { query: { account_id: st.account_id, from: st.from, to: st.to, status: st.status, q: st.q, per_page: 150 } });
      $('[data-head]', el).innerHTML = `<div class="fin-stmt-head">
        <div><span>Entradas no período</span><b class="pos">${money(r.sum.in)}</b></div>
        <div><span>Saídas no período</span><b class="neg">${money(r.sum.out)}</b></div>
        <div><span>A conciliar</span><b>${r.sum.pending}</b></div>
        ${a ? `<div><span>Saldo no sistema</span><b>${money(a.balance)}</b></div><div><span>Saldo no extrato${a.stmt_balance_date ? ' (' + date(a.stmt_balance_date) + ')' : ''}</span><b>${a.stmt_balance !== null ? money(a.stmt_balance) : '—'}</b>${a.stmt_balance !== null && Math.abs(a.stmt_balance - a.balance) > 0.009 ? `<small class="neg">diferença ${money(a.stmt_balance - a.balance)}</small>` : a.stmt_balance !== null ? '<small class="pos">conferido ✓</small>' : ''}</div>` : ''}
      </div>`;
      list.innerHTML = r.data.length ? `<section class="card"><ul class="fin-tx">${r.data.map(row).join('')}</ul>${r.total > r.data.length ? `<p class="small muted" style="padding:10px 16px;margin:0">Mostrando ${r.data.length} de ${r.total}. Refine o período para ver o restante.</p>` : ''}</section>`
        : `<section class="card">${emptyState(st.status === 'pending' ? (accounts.length ? 'Nada a conciliar neste período. Importe um extrato OFX para começar.' : 'Cadastre uma conta bancária primeiro.') : 'Nenhuma movimentação neste filtro.', 'bank', `<button class="btn btn-primary" data-import2>${icon('upload')} Importar extrato</button>`)}</section>`;
      $('[data-import2]', list)?.addEventListener('click', () => importModal(st.account_id, afterImport));
      list.__rows = r.data;
    } catch (err) { list.innerHTML = `<div class="alert alert-danger">${esc(err.message)}</div>`; } finally { list.style.opacity = ''; }
  };
  const row = (t) => {
    let side = '';
    if (t.status === 'reconciled') side = `<div class="fin-tx-match ok">${icon('link')}<span>${(t.entries || []).map((e) => esc(e.description)).join(' + ') || 'Conciliada'}</span></div>`;
    else if (t.status === 'ignored') side = `<div class="fin-tx-match muted">Ignorada</div>`;
    else if (t.suggestion) side = `<div class="fin-tx-match sug"><span>Sugestão: <b>${esc(t.suggestion.description)}</b> · vence ${date(t.suggestion.due_date)} · ${money(t.suggestion.amount)}</span><button class="btn btn-xs btn-success" data-a="accept">${icon('check')} Confirmar</button></div>`;
    else if (t.rule) side = `<div class="fin-tx-match rule"><span>Lançar como <b>${esc(t.rule.category_name || t.rule.party_name || '')}</b>?</span><button class="btn btn-xs btn-success" data-a="rule">${icon('check')} Lançar</button></div>`;
    return `<li data-id="${t.id}" class="${t.status}">
      <div class="fin-tx-date"><b>${date(t.tx_date).slice(0, 5)}</b><small>${t.tx_date.slice(0, 4)}</small></div>
      <div class="fin-tx-desc"><b>${esc(t.description)}</b><small>${t.memo ? esc(t.memo) + ' · ' : ''}${esc(t.account_name)}${t.source === 'ofx' || t.source === 'csv' ? '' : ' · ' + esc(sourceLabel(t.source))}</small>${side}</div>
      <div class="fin-tx-amt">${signed(t.amount)}</div>
      <div class="fin-tx-act">${t.status === 'pending' ? `<button class="btn btn-xs btn-primary" data-a="match">${icon('link')} Conciliar</button><button class="btn btn-xs" data-a="create">${icon('plus')} Lançar</button><button class="btn btn-xs btn-ghost" data-a="ignore" title="Ignorar (ex.: transferência entre suas contas)">Ignorar</button>`
        : t.status === 'ignored' ? `<button class="btn btn-xs" data-a="restore">Restaurar</button>` : `<button class="btn btn-xs btn-ghost" data-a="unlink">${icon('unlink')} Desfazer</button>`}</div>
    </li>`;
  };
  const afterImport = (r, accountId) => { if (accountId) st.account_id = accountId; render(el, { ...ctx, query: { ...ctx.query, account: String(st.account_id), status: 'pending' } }); };
  $('[data-list]', el).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-a]');
    if (!b) return;
    const li = b.closest('[data-id]');
    const t = ($('[data-list]', el).__rows || []).find((x) => x.id === +li.dataset.id);
    if (!t || !needEdit()) return;
    b.classList.add('loading');
    try {
      if (b.dataset.a === 'accept') { await api(`/fh/fin/transactions/${t.id}/reconcile`, { method: 'POST', body: { entry_ids: [t.suggestion.id] } }); toast('Conciliado.'); }
      if (b.dataset.a === 'rule') { await api(`/fh/fin/transactions/${t.id}/create-entry`, { method: 'POST', body: { category_id: t.rule.category_id, party_name: t.rule.party_name } }); toast('Lançado e conciliado.'); }
      if (b.dataset.a === 'match') { b.classList.remove('loading'); return matchModal(t, refresh); }
      if (b.dataset.a === 'create') { b.classList.remove('loading'); return createModal(t, refresh); }
      if (b.dataset.a === 'ignore') await api(`/fh/fin/transactions/${t.id}/ignore`, { method: 'POST', body: { ignore: true } });
      if (b.dataset.a === 'restore') await api(`/fh/fin/transactions/${t.id}/ignore`, { method: 'POST', body: { ignore: false } });
      if (b.dataset.a === 'unlink') {
        if (!await confirmDialog('Desfazer a conciliação? Lançamentos criados a partir do extrato são excluídos; os demais voltam a ficar em aberto.')) { b.classList.remove('loading'); return; }
        await api(`/fh/fin/transactions/${t.id}/unlink`, { method: 'POST' });
      }
      refresh();
    } catch (err) { toastError(err); b.classList.remove('loading'); }
  });
  const refresh = async () => { await fin.load(true); await load(); };
  $$('[data-f]', el).forEach((i) => i.addEventListener('change', () => { st[i.dataset.f] = i.value; load(); }));
  $$('[data-s]', el).forEach((b) => b.addEventListener('click', () => { st.status = b.dataset.s; load(); }));
  let qt;
  $('[data-q]', el).addEventListener('input', (e) => { clearTimeout(qt); qt = setTimeout(() => { st.q = e.target.value; load(); }, 300); });
  $('[data-import]', el).addEventListener('click', () => importModal(st.account_id, afterImport));
  $('[data-auto]', el).addEventListener('click', async (e) => {
    if (!needEdit()) return;
    const b = e.currentTarget;
    b.classList.add('loading');
    try {
      const r = await api('/fh/fin/reconcile/auto', { method: 'POST', body: { account_id: st.account_id } });
      toast(r.reconciled ? `${r.reconciled} movimentação(ões) conciliada(s) automaticamente. ${r.pending} ainda precisam da sua confirmação.` : 'Nenhuma correspondência exata encontrada. Use "Conciliar" ou "Lançar" em cada linha.');
      refresh();
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
  $('[data-sync]', el).addEventListener('click', async (e) => {
    if (!needEdit()) return;
    const b = e.currentTarget;
    const a = acc();
    b.classList.add('loading');
    try {
      const r = await api(`/fh/fin/connections/${a.connection_id}/sync`, { method: 'POST' });
      toast(`${r.new} nova(s) movimentação(ões) do banco.`);
      refresh();
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
  await load();
}

export const sourceLabel = (s) => ({ openfinance: 'Open Finance', meupluggy: 'Meu Pluggy', inter: 'API Inter', asaas: 'Asaas', ofx: 'OFX', csv: 'CSV' }[s] || s);

/** Pick one or more open entries for a statement line (amounts must add up). */
async function matchModal(t, onDone) {
  const kind = t.amount > 0 ? 'receivable' : 'payable';
  const K = KIND[kind];
  const target = Math.abs(t.amount);
  const m = modal({
    title: 'Conciliar movimentação', size: 'lg',
    body: `<div class="fin-match-head">${signed(t.amount)}<div><b>${esc(t.description)}</b><small>${date(t.tx_date)} · ${esc(t.account_name)}</small></div></div>
      <input type="search" class="input" style="margin:12px 0" data-q placeholder="Buscar ${K.title.toLowerCase()} por descrição, nome ou documento" aria-label="Buscar">
      <div data-c><div class="loading-box">Procurando lançamentos compatíveis...</div></div>
      <div class="fin-match-sum" data-sum></div>`,
    footer: `<button class="btn" data-new>${icon('plus')} Não existe: lançar agora</button><span style="flex:1"></span><button class="btn" data-close>Cancelar</button><button class="btn btn-primary" data-ok disabled>${icon('link')} Conciliar</button>`,
  });
  const chosen = new Map();
  let rows = [];
  const sum = () => {
    const s = [...chosen.values()].reduce((a, e) => a + e.amount, 0);
    const diff = Math.round((target - s) * 100) / 100;
    const ok = chosen.size === 1 || (chosen.size > 1 && Math.abs(diff) < 0.01);
    $('[data-sum]', m.el).innerHTML = chosen.size ? `Selecionado: <b>${money(s)}</b> de ${money(target)}${chosen.size === 1 && Math.abs(diff) >= 0.01 ? ` · a diferença de <b>${money(Math.abs(diff))}</b> será registrada como ${diff > 0 ? 'juros/multa' : 'desconto'}` : chosen.size > 1 && !ok ? ` · <span class="neg">falta ${money(diff)}</span>` : ''}` : '';
    $('[data-ok]', m.el).disabled = !ok;
  };
  const draw = () => {
    $('[data-c]', m.el).innerHTML = rows.length ? `<ul class="list fin-cands">${rows.map((e) => `<li><label class="check"><input type="checkbox" data-e="${e.id}" ${chosen.has(e.id) ? 'checked' : ''}></label>
      <div class="grow"><b>${esc(e.description)}</b><small>vence ${date(e.due_date)}${e.party_name ? ' · ' + esc(e.party_name) : ''}${e.category_name ? ' · ' + esc(e.category_name) : ''}</small></div>
      ${e.score >= 60 ? '<span class="badge green">provável</span>' : ''}<b class="nowrap">${money(e.amount)}</b></li>`).join('')}</ul>`
      : `<div class="empty-box">${icon('search')}<p>Nenhuma ${K.one} em aberto com valor e data próximos. Busque pelo nome acima ou lance agora.</p></div>`;
    $$('[data-e]', m.el).forEach((c) => c.addEventListener('change', () => { const e = rows.find((x) => x.id === +c.dataset.e); c.checked ? chosen.set(e.id, e) : chosen.delete(e.id); sum(); }));
  };
  const search = async (q = '') => {
    try { rows = (await api(`/fh/fin/transactions/${t.id}/candidates`, { query: { q } })).data; draw(); } catch (err) { toastError(err); }
  };
  let qt;
  $('[data-q]', m.el).addEventListener('input', (e) => { clearTimeout(qt); qt = setTimeout(() => search(e.target.value.trim()), 300); });
  $('[data-new]', m.el).addEventListener('click', () => { m.close(); createModal(t, onDone); });
  $('[data-ok]', m.el).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.classList.add('loading');
    try { await api(`/fh/fin/transactions/${t.id}/reconcile`, { method: 'POST', body: { entry_ids: [...chosen.keys()] } }); toast('Conciliado.'); m.close(); onDone(); } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
  await search();
}

/** Book the statement line as a new (paid) entry and optionally remember the category. */
async function createModal(t, onDone) {
  const kind = t.amount > 0 ? 'receivable' : 'payable';
  const cand = await api(`/fh/fin/transactions/${t.id}/candidates`).catch(() => ({ rule: null }));
  const rule = cand.rule || t.rule;
  const words = t.description.replace(/[^\p{L}\s]/gu, ' ').split(/\s+/).filter((w) => w.length >= 3).slice(0, 3).join(' ');
  formModal({
    title: `Lançar ${kind === 'receivable' ? 'recebimento' : 'pagamento'} do extrato`, size: 'lg', submitLabel: 'Lançar e conciliar',
    intro: `<div class="fin-match-head">${signed(t.amount)}<div><b>${esc(t.description)}</b><small>${date(t.tx_date)} · ${esc(t.account_name)}</small></div></div>`,
    values: { description: t.description, category_id: rule?.category_id || '', party_name: rule?.party_name || '', remember: true, rule_text: words },
    fields: [
      { name: 'description', label: 'Descrição', required: true, span: 2 },
      { name: 'category_id', label: 'Categoria', type: 'select', empty: 'Sem categoria', options: catOptions(kind) },
      { name: 'party_name', label: kind === 'receivable' ? 'Cliente' : 'Fornecedor' },
      { name: 'cost_center', label: 'Centro de custo / projeto' },
      { name: 'competence_date', label: 'Competência', type: 'date' },
      { name: 'remember', label: 'Lembrar: próximas movimentações com estas palavras recebem a mesma categoria', type: 'checkbox', span: 2 },
      { name: 'rule_text', label: 'Palavras que identificam a movimentação', span: 2, help: 'Ex.: "tarifa" para todas as tarifas bancárias.' },
    ],
    onSubmit: async (d) => { await api(`/fh/fin/transactions/${t.id}/create-entry`, { method: 'POST', body: d }); toast('Lançado e conciliado.'); onDone(); },
  });
}
