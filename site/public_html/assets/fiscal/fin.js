/* Integra Fiscal Hub — financial module: shared state, entry form, payment, detail and statement import. */
import { api, $, $$, esc, icon, money, date, badge, formModal, modal, confirmDialog, toast, toastError, today } from '/admin/js/core.js';

export const fin = {
  boot: null,
  async load(force = false) {
    if (force || !this.boot) this.boot = await api('/fh/fin/bootstrap');
    return this.boot;
  },
  invalidate() { this.boot = null; },
  cats(kind, all = false) { return (this.boot?.categories || []).filter((c) => c.kind === (kind === 'receivable' || kind === 'income' ? 'income' : 'expense') && (all || Number(c.active))); },
  accounts(all = false) { return (this.boot?.accounts || []).filter((a) => all || Number(a.active)); },
  get canEdit() { return !!this.boot?.can_edit; },
};

export const KIND = {
  receivable: { title: 'Contas a receber', one: 'conta a receber', party: 'Cliente', verb: 'Receber', done: 'Recebido', icon: 'trendUp' },
  payable: { title: 'Contas a pagar', one: 'conta a pagar', party: 'Fornecedor', verb: 'Pagar', done: 'Pago', icon: 'trendDown' },
};
export const catOptions = (kind) => fin.cats(kind).map((c) => ({ value: c.id, label: c.name }));
export const accOptions = () => fin.accounts().map((a) => ({ value: a.id, label: a.name + (a.kind === 'credit_card' ? ' (cartão)' : '') }));
export const methodOptions = () => Object.entries(fin.boot?.methods || {}).map(([value, label]) => ({ value, label }));
export const statusBadge = (e) => badge('entry_status', e.display_status || e.status) + (e.days_late ? `<small class="neg fin-late">há ${e.days_late} dia${e.days_late > 1 ? 's' : ''}</small>` : '');
export const signed = (v) => `<b class="${v < 0 ? 'neg' : 'pos'}">${v < 0 ? '−' : '+'} ${money(Math.abs(v))}</b>`;
export const isoAdd = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };

/** Guard for write actions when the subscription is read-only. */
export function needEdit() {
  if (fin.canEdit) return true;
  toast('Sua assinatura está inativa: o financeiro fica disponível só para consulta.', 'error');
  return false;
}

/** Party (customer/supplier) name with suggestions from previous entries and NFS-e customers. */
function bindParty(form, kind) {
  const input = form.elements.party_name;
  if (!input) return;
  const listId = 'fin-parties-' + Math.random().toString(36).slice(2, 7);
  input.setAttribute('list', listId);
  input.setAttribute('autocomplete', 'off');
  input.insertAdjacentHTML('afterend', `<datalist id="${listId}"></datalist>`);
  let found = [];
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const hit = found.find((p) => p.name === input.value);
    if (hit) {
      if (form.elements.party_document && hit.document) form.elements.party_document.value = hit.document;
      if (form.elements.taker_id) form.elements.taker_id.value = hit.taker_id || '';
      return;
    }
    if (input.value.trim().length < 2) return;
    timer = setTimeout(async () => {
      try {
        found = (await api('/fh/fin/parties', { query: { q: input.value.trim(), kind } })).data;
        $('#' + listId, form).innerHTML = found.map((p) => `<option value="${esc(p.name)}">`).join('');
      } catch (e) { /* suggestions are optional */ }
    }, 250);
  });
}

/**
 * New/edit entry. opts: { kind, prefill, onSaved(entries) }.
 */
export async function entryForm(entry, opts = {}) {
  if (!needEdit()) return;
  await fin.load();
  const kind = entry?.kind || opts.kind || 'receivable';
  const K = KIND[kind];
  const isNew = !entry;
  const values = entry ? { ...entry } : { due_date: today(), repeat_mode: 'none', repeat: 2, paid_at: today(), ...(opts.prefill || {}) };
  const fields = [
    { name: 'description', label: 'Descrição', required: true, span: 2, placeholder: kind === 'receivable' ? 'Ex.: Consultoria de setembro, Venda à vista' : 'Ex.: Aluguel, Energia, Fornecedor X' },
    { name: 'amount', label: isNew ? 'Valor (total, se parcelado)' : 'Valor', type: 'money', required: true },
    { name: 'due_date', label: 'Vencimento', type: 'date', required: true },
    { name: 'party_name', label: K.party, placeholder: 'Nome' },
    { name: 'party_document', label: 'CPF/CNPJ', placeholder: 'opcional' },
    { name: 'category_id', label: 'Categoria', type: 'select', empty: 'Sem categoria', options: catOptions(kind) },
    { name: 'account_id', label: 'Conta prevista', type: 'select', empty: 'Definir na baixa', options: accOptions() },
    { name: 'competence_date', label: 'Competência (para o DRE)', type: 'date', help: 'Em branco = vencimento.' },
    { name: 'payment_method', label: 'Forma de pagamento', type: 'select', empty: '—', options: methodOptions() },
    { name: 'document_number', label: 'Nº do documento', placeholder: 'NF, boleto, contrato...' },
    { name: 'cost_center', label: 'Centro de custo / projeto', placeholder: 'opcional' },
    isNew && { name: 'repeat_mode', label: 'Repetição', type: 'select', empty: false, options: [{ value: 'none', label: 'Não repete' }, { value: 'installments', label: 'Parcelado (divide o valor)' }, { value: 'monthly', label: 'Mensal (mesmo valor)' }, { value: 'weekly', label: 'Semanal' }, { value: 'yearly', label: 'Anual' }] },
    isNew && { name: 'repeat', label: 'Quantidade', type: 'number', min: 2, max: 120 },
    isNew && { name: 'paid', label: kind === 'receivable' ? 'Já foi recebido (baixar agora)' : 'Já foi pago (baixar agora)', type: 'checkbox' },
    isNew && { name: 'paid_at', label: 'Data do pagamento', type: 'date' },
    !isNew && entry.series_key && entry.origin !== 'transfer' && { name: 'apply_series', label: 'Aplicar categoria, conta e dados às próximas em aberto da série', type: 'checkbox', span: 2 },
    !isNew && entry.status === 'paid' && !entry.transaction_id && { name: 'paid_at', label: 'Pago em', type: 'date' },
    { name: 'notes', label: 'Observações', type: 'textarea', rows: 2, span: 2 },
    { name: 'taker_id', type: 'hidden' },
  ];
  formModal({
    title: isNew ? 'Nova ' + K.one : 'Editar ' + K.one, size: 'lg', values, fields, submitLabel: isNew ? 'Salvar' : 'Salvar alterações',
    intro: entry?.invoice_id ? `<div class="alert alert-info small" style="margin:0">Criado automaticamente pela NFS-e ${esc(entry.document_number || '')}.</div>` : '',
    onReady: (form) => {
      bindParty(form, kind);
      const sync = () => {
        const mode = form.elements.repeat_mode?.value;
        form.elements.repeat && form.elements.repeat.closest('.field').classList.toggle('hidden', !mode || mode === 'none');
        form.elements.paid_at && isNew && form.elements.paid_at.closest('.field').classList.toggle('hidden', !form.elements.paid.checked);
      };
      form.elements.repeat_mode?.addEventListener('change', sync);
      form.elements.paid?.addEventListener('change', sync);
      sync();
    },
    onSubmit: async (d) => {
      const body = { ...d };
      if (isNew) {
        body.kind = kind;
        if (d.repeat_mode === 'installments') body.installments = d.repeat;
        else if (d.repeat_mode && d.repeat_mode !== 'none') body.recurrence = d.repeat_mode;
        delete body.repeat_mode;
        const r = await api('/fh/fin/entries', { method: 'POST', body });
        toast(r.data.length > 1 ? `${r.data.length} lançamentos criados.` : 'Lançamento salvo.');
        opts.onSaved && opts.onSaved(r.data);
      } else {
        const r = await api('/fh/fin/entries/' + entry.id, { method: 'PUT', body });
        toast('Alterações salvas.');
        opts.onSaved && opts.onSaved([r]);
      }
    },
  });
}

/** Settle (baixa) an entry. */
export async function payModal(entry, onDone) {
  if (!needEdit()) return;
  await fin.load();
  const K = KIND[entry.kind];
  const accs = accOptions();
  if (!accs.length) { toast('Cadastre uma conta bancária ou caixa em Financeiro → Contas bancárias.', 'error'); return; }
  formModal({
    title: `${K.verb}: ${entry.description}`, submitLabel: 'Confirmar baixa',
    intro: `<div class="fin-pay-head"><span>Valor em aberto</span><b>${money(entry.amount)}</b><small>vencimento ${date(entry.due_date)}${entry.party_name ? ' · ' + esc(entry.party_name) : ''}</small></div>`,
    values: { paid_at: today(), account_id: entry.account_id || accs[0].value, paid_amount: entry.amount, interest: 0, discount: 0, payment_method: entry.payment_method || '' },
    fields: [
      { name: 'paid_at', label: 'Data do ' + (entry.kind === 'receivable' ? 'recebimento' : 'pagamento'), type: 'date', required: true },
      { name: 'account_id', label: 'Conta', type: 'select', empty: false, options: accs, required: true },
      { name: 'interest', label: 'Juros / multa', type: 'money' },
      { name: 'discount', label: 'Desconto', type: 'money' },
      { name: 'paid_amount', label: 'Valor ' + (entry.kind === 'receivable' ? 'recebido' : 'pago'), type: 'money', required: true },
      { name: 'payment_method', label: 'Forma', type: 'select', empty: '—', options: methodOptions() },
      { name: 'keep_remainder', label: 'Pagamento parcial: manter o saldo restante em aberto', type: 'checkbox', span: 2 },
    ],
    onReady: (form) => {
      const f = form.elements;
      const recalc = () => { f.paid_amount.value = (Number(entry.amount) + Number(f.interest.value || 0) - Number(f.discount.value || 0)).toFixed(2); sync(); };
      const sync = () => { const exp = Number(entry.amount) + Number(f.interest.value || 0) - Number(f.discount.value || 0); f.keep_remainder.closest('.check').classList.toggle('hidden', !(Number(f.paid_amount.value) < exp - 0.004)); };
      f.interest.addEventListener('input', recalc);
      f.discount.addEventListener('input', recalc);
      f.paid_amount.addEventListener('input', sync);
      sync();
    },
    onSubmit: async (d) => {
      await api(`/fh/fin/entries/${entry.id}/pay`, { method: 'POST', body: d });
      toast(entry.kind === 'receivable' ? 'Recebimento registrado.' : 'Pagamento registrado.');
      onDone && onDone();
    },
  });
}

/** Detail drawer with every action. */
export async function entryDetail(id, onChange) {
  const e = await api('/fh/fin/entries/' + id);
  const K = KIND[e.kind];
  const row = (l, v) => (v ? `<dt>${l}</dt><dd>${v}</dd>` : '');
  const m = modal({
    title: e.description, side: true,
    body: `<div class="fin-detail">
      <div class="fin-detail-top"><span class="muted small">${esc(K.title.replace('Contas', 'Conta'))}</span><b class="${e.kind === 'receivable' ? 'pos' : 'neg'}">${money(e.amount)}</b>${statusBadge(e)}</div>
      <dl class="kv">
        ${row('Vencimento', date(e.due_date))}${row('Competência', e.competence_date ? date(e.competence_date) : '')}
        ${row(K.party, e.party_name ? esc(e.party_name) + (e.party_document ? ` <small class="muted">${esc(e.party_document)}</small>` : '') : '')}
        ${row('Categoria', esc(e.category_name || ''))}${row('Conta', esc(e.account_name || ''))}
        ${row('Forma', esc(fin.boot?.methods?.[e.payment_method] || ''))}${row('Documento', esc(e.document_number || ''))}${row('Centro de custo', esc(e.cost_center || ''))}
        ${e.status === 'paid' ? row(K.done + ' em', date(e.paid_at)) + row('Valor ' + K.done.toLowerCase(), money(e.paid_amount)) + (e.interest ? row('Juros/multa', money(e.interest)) : '') + (e.discount ? row('Desconto', money(e.discount)) : '') : ''}
        ${e.transaction ? row('Extrato', `${date(e.transaction.tx_date)} · ${esc(e.transaction.description)} · ${esc(e.transaction.account_name)}`) : ''}
        ${e.invoice ? row('Nota fiscal', `<a href="#/notas/${e.invoice.id}">NFS-e ${esc(e.invoice.nfse_number || 'DPS ' + e.invoice.dps_number)}</a>`) : ''}
        ${row('Observações', e.notes ? esc(e.notes).replace(/\n/g, '<br>') : '')}
      </dl>
      ${e.series.length > 1 ? `<h4 class="fin-h4">${e.installments ? 'Parcelas' : 'Recorrência'} (${e.series.length})</h4><ul class="list fin-series">${e.series.map((s) => `<li class="${s.id === e.id ? 'current' : ''}"><div class="grow"><b>${date(s.due_date)}</b><small>${esc(s.description)}</small></div><span class="nowrap">${money(s.amount)}</span>${statusBadge(s)}</li>`).join('')}</ul>` : ''}
    </div>`,
    footer: `<div class="fin-actions">
      ${e.status === 'open' ? `<button class="btn btn-success" data-a="pay">${icon('check')} ${K.verb}</button>` : ''}
      ${e.status === 'paid' && e.origin !== 'transfer' ? `<button class="btn" data-a="reopen">${icon('undo')} Reabrir</button>` : ''}
      ${e.origin !== 'transfer' ? `<button class="btn" data-a="edit">${icon('edit')} Editar</button>` : ''}
      ${e.status === 'open' ? `<button class="btn btn-ghost" data-a="cancel">Cancelar</button>` : ''}
      <button class="btn btn-danger btn-icon" data-a="delete" title="Excluir">${icon('trash')}</button></div>`,
  });
  const done = () => { m.close(); onChange && onChange(); };
  m.el.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-a]');
    if (!b) return;
    try {
      if (b.dataset.a === 'pay') { m.close(); payModal(e, onChange); }
      if (b.dataset.a === 'edit') { m.close(); entryForm(e, { onSaved: onChange }); }
      if (b.dataset.a === 'reopen' && needEdit() && await confirmDialog(e.transaction_id ? 'Reabrir? A conciliação com o extrato também será desfeita.' : 'Reabrir este lançamento (desfazer a baixa)?')) { await api(`/fh/fin/entries/${e.id}/reopen`, { method: 'POST' }); toast('Lançamento reaberto.'); done(); }
      if (b.dataset.a === 'cancel' && needEdit() && await confirmDialog('Cancelar este lançamento? Ele sai das previsões, mas fica no histórico.', { okLabel: 'Cancelar lançamento', danger: true })) { await api(`/fh/fin/entries/${e.id}/cancel`, { method: 'POST' }); toast('Lançamento cancelado.'); done(); }
      if (b.dataset.a === 'delete' && needEdit()) {
        const series = e.series_key && e.origin !== 'transfer' && e.series.filter((s) => s.status === 'open' && s.due_date >= e.due_date).length > 1;
        const scope = series ? await chooseScope() : 'one';
        if (!scope) return;
        if (!series && !await confirmDialog(e.origin === 'transfer' ? 'Excluir a transferência (os dois lados)?' : 'Excluir este lançamento?', { danger: true, okLabel: 'Excluir' })) return;
        const r = await api(`/fh/fin/entries/${e.id}?scope=${scope}`, { method: 'DELETE' });
        toast(r.deleted > 1 ? `${r.deleted} lançamentos excluídos.` : 'Lançamento excluído.');
        done();
      }
    } catch (err) { toastError(err); }
  });
}

function chooseScope() {
  return new Promise((resolve) => {
    let v = null;
    const m = modal({
      title: 'Excluir lançamento', size: 'sm', body: '<p style="margin:0">Este lançamento faz parte de uma série (parcelas ou recorrência).</p>',
      footer: '<button class="btn" data-s="one">Só este</button><button class="btn btn-danger" data-s="series">Este e os próximos em aberto</button>',
      onClose: () => resolve(v),
    });
    $$('[data-s]', m.el).forEach((b) => b.addEventListener('click', () => { v = b.dataset.s; m.close(); }));
  });
}

/** Statement import: OFX (any bank) or CSV. The file goes as base64 so the server can detect its encoding. */
export async function importModal(accountId, onDone) {
  if (!needEdit()) return;
  await fin.load();
  const accs = accOptions();
  const m = modal({
    title: 'Importar extrato bancário', size: 'lg',
    body: `<form class="form-grid" data-f novalidate>
      <div class="field"><label>Conta</label><select name="account_id">${accs.map((a) => `<option value="${a.value}" ${String(a.value) === String(accountId || '') ? 'selected' : ''}>${esc(a.label)}</option>`).join('')}</select></div>
      <div class="field"><label>Arquivo (.ofx ou .csv)</label><input type="file" name="file" accept=".ofx,.OFX,.csv,.txt,application/x-ofx,text/csv"></div>
      <div class="span-2 fin-howto"><b>${icon('help')} Como baixar o OFX</b><span>No internet banking, abra o <b>extrato</b>, escolha o período e exporte em <b>OFX</b> (às vezes chamado de "Money", "Quicken" ou "arquivo para contabilidade"). Movimentações repetidas são ignoradas, então pode importar períodos sobrepostos sem medo.</span>
        <span>Planilha CSV: colunas <b>data; descrição; valor</b> (negativo para saídas) ou <b>data; descrição; crédito; débito</b>.</span></div>
    </form>`,
    footer: `<button class="btn" data-close>Fechar</button><button class="btn btn-primary" data-go>${icon('upload')} Importar</button>`,
  });
  $('[data-go]', m.el).addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    const form = $('[data-f]', m.el);
    const file = form.elements.file.files[0];
    if (!file) { toast('Escolha o arquivo do extrato.', 'error'); return; }
    if (file.size > 8 * 1024 * 1024) { toast('Arquivo grande demais (máx. 8 MB).', 'error'); return; }
    btn.classList.add('loading');
    try {
      const buf = new Uint8Array(await file.arrayBuffer());
      let bin = '';
      for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
      const r = await api('/fh/fin/import', { method: 'POST', body: { account_id: form.elements.account_id.value, file_name: file.name, content_b64: btoa(bin) } });
      m.close();
      toast(`${r.new} movimentação(ões) importada(s)${r.duplicates ? `, ${r.duplicates} já existia(m)` : ''}${r.suggestions ? ` · ${r.suggestions} com sugestão de conciliação` : ''}.`);
      fin.invalidate();
      onDone && onDone(r, Number(form.elements.account_id.value));
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
}

/** Transfer between own accounts. */
export async function transferModal(onDone) {
  if (!needEdit()) return;
  await fin.load();
  const accs = accOptions();
  if (accs.length < 2) { toast('Cadastre pelo menos duas contas para transferir entre elas.', 'error'); return; }
  formModal({
    title: 'Transferência entre contas', values: { date: today(), from_account_id: accs[0].value, to_account_id: accs[1].value },
    fields: [
      { name: 'from_account_id', label: 'De', type: 'select', empty: false, options: accs },
      { name: 'to_account_id', label: 'Para', type: 'select', empty: false, options: accs },
      { name: 'amount', label: 'Valor', type: 'money', required: true },
      { name: 'date', label: 'Data', type: 'date', required: true },
      { name: 'description', label: 'Descrição (opcional)', span: 2 },
    ],
    onSubmit: async (d) => { await api('/fh/fin/transfers', { method: 'POST', body: d }); toast('Transferência registrada.'); fin.invalidate(); onDone && onDone(); },
  });
}

/** Period presets used by the reports. */
export function periodPresets() {
  const t = today();
  const y = t.slice(0, 4);
  const m0 = t.slice(0, 8) + '01';
  const prev = new Date(m0 + 'T12:00:00'); prev.setMonth(prev.getMonth() - 1);
  const pm0 = prev.toISOString().slice(0, 8) + '01';
  const end = (s) => { const x = new Date(s + 'T12:00:00'); x.setMonth(x.getMonth() + 1); x.setDate(0); return x.toISOString().slice(0, 10); };
  return { month: [m0, end(m0)], prev: [pm0, end(pm0)], quarter: [isoAdd(t, -89), t], year: [y + '-01-01', y + '-12-31'], next30: [t, isoAdd(t, 30)], next90: [t, isoAdd(t, 90)] };
}
