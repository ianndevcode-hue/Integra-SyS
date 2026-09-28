import { api, state, $, $$, esc, icon, money, date, datetime, badge, options, dataTable, formModal, confirmDialog, modal, toast, toastError, lookups, today, can, downloadUrl, debounce } from '../core.js';

const errorList = (details) => (details && details.length ? `<ul style="margin:8px 0 0;padding-left:18px">${details.map((d) => `<li>${esc(d)}</li>`).join('')}</ul>` : '');

async function emitForm(prefill, onDone) {
  const lk = await lookups();
  const st = await api('/nfse/status');
  const cfg = st.config;
  const sig = cfg.provider === 'sigiss';
  const env = sig ? '<b>Prefeitura de Marília (SIGISS)</b> — nota real, com valor fiscal' : cfg.environment === 'production' ? 'Sistema Nacional · <b>Produção</b> (com valor fiscal)' : 'Sistema Nacional · <b>Produção restrita</b> (sem valor fiscal)';
  const notReady = sig ? 'Informe a inscrição municipal (CCM) e a senha do SIGISS em Configurações → NFS-e.' : 'Configure o certificado A1 e o CNPJ em Configurações → NFS-e.';
  const m = formModal({
    title: 'Emitir NFS-e', size: 'lg', submitLabel: 'Emitir nota',
    values: { amount: prefill.amount, description: prefill.description || cfg.default_description, customer_id: prefill.customer_id, service_code: prefill.service_code || (sig ? st.sigiss.servico : cfg.ctribnac), nbs_code: cfg.cnbs, iss_rate: cfg.aliquota, competence_date: today(), charge_id: prefill.charge_id || '', contract_id: prefill.contract_id || '' },
    intro: `<div class="alert ${st.ready ? 'alert-info' : 'alert-warning'}" style="margin:0">Emissão via ${env}.${st.ready ? '' : ` <b>${notReady}</b>`}</div>`,
    fields: [
      { name: 'customer_id', label: 'Tomador (cliente)', type: 'select', required: true, options: lk.customers.map((c) => ({ value: c.id, label: c.name + (c.document ? '' : ' — sem CPF/CNPJ') })) },
      { name: 'amount', label: 'Valor do serviço (R$)', type: 'money', required: true },
      { name: 'description', label: 'Discriminação do serviço', type: 'textarea', rows: 4, span: 2, required: true },
      { name: '_ai', type: 'html', span: 2, html: state.ai?.admin ? `<button type="button" class="btn btn-sm" data-ai-desc>${icon('sparkles')} Melhorar descrição com IA</button>` : '' },
      { name: 'service_code', label: sig ? 'Código do serviço (SIGISS)' : 'Código de tributação nacional (cTribNac)', help: sig ? 'Como cadastrado na sua inscrição no SIGISS.' : 'Ex.: 010101 = análise e desenvolvimento de sistemas; 010701 = suporte técnico.' },
      { name: 'nbs_code', label: 'Código NBS', help: 'Ex.: 115022000 = desenvolvimento de software personalizado.' },
      { name: 'iss_rate', label: 'Alíquota ISS (%)', type: 'number', step: '0.01', min: 0, max: 5 },
      { name: 'competence_date', label: 'Competência', type: 'date' },
      { name: 'charge_id', type: 'hidden' },
      { name: 'discount_amount', label: 'Desconto incondicionado (R$)', type: 'money' },
      { name: 'deductions', label: 'Deduções da base do ISS (R$)', type: 'money', help: 'Materiais/subempreitadas previstos em lei.' },
      { name: 'iss_withheld', label: 'ISS retido pelo tomador', type: 'checkbox', span: 2 },
      { name: '_taxes', type: 'html', span: 2, html: `<fieldset class="tax-grid"><legend>Tributos federais ${['2', '3'].includes(cfg.op_simp_nac) ? '<span class="badge">Simples Nacional: recolhidos no DAS — informe só se houver retenção</span>' : '<span class="badge yellow">Não optante do Simples</span>'}</legend>
        <div class="tax-row tax-head"><span>Tributo</span><span>Alíquota (%)</span><span>Retido pelo tomador</span></div>
        ${[['pis', 'PIS'], ['cofins', 'COFINS'], ['csll', 'CSLL'], ['irrf', 'IRRF'], ['inss', 'INSS (cessão de mão de obra)']].map(([k, l]) => `<div class="tax-row"><span>${l}</span><input name="${k}_rate" type="number" step="0.01" min="0" max="30" value="${esc(cfg[k + '_rate'] ?? 0)}" aria-label="Alíquota ${l}"><label class="check"><input type="checkbox" name="${k}_withheld"> retido</label></div>`).join('')}
        <div class="tax-row cst"><span>CST PIS/COFINS</span><select name="pis_cofins_cst" aria-label="CST">${[['01', '01 — Tributável (alíquota básica)'], ['49', '49 — Outras operações de saída'], ['06', '06 — Alíquota zero'], ['07', '07 — Isenta'], ['08', '08 — Sem incidência'], ['99', '99 — Outras operações']].map(([v, l]) => `<option value="${v}" ${v === cfg.pis_cofins_cst ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
        <label class="check small" style="margin-top:6px"><input type="checkbox" name="skip_tax_note"> Não incluir "valor aproximado dos tributos" na discriminação</label>
      </fieldset><div class="tax-summary" data-tax-summary></div>` },
      { name: 'contract_id', type: 'hidden' },
      !sig && { name: '_preview', type: 'html', span: 2, html: `<button type="button" class="btn btn-sm" data-preview>${icon('code')} Pré-visualizar e validar XML</button><div data-preview-box class="hidden" style="margin-top:10px"></div>` },
    ],
    onSubmit: async (d) => {
      delete d._ai; delete d._preview; delete d._taxes;
      const res = await api('/nfse', { method: 'POST', body: { ...d, transmit: true } });
      if (res.error) {
        toast(res.error, 'error');
        showInvoice(res.invoice.id, onDone, res.details);
      } else {
        toast(`NFS-e autorizada! Nº ${res.invoice.nfse_number || ''}`);
        showInvoice(res.invoice.id, onDone);
      }
      onDone && onDone();
    },
  });
  const form = m.el.querySelector('form');
  const taxBox = $('[data-tax-summary]', m.el);
  const TAXKEYS = ['amount', 'discount_amount', 'deductions', 'iss_rate', 'customer_id', 'pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate', 'pis_cofins_cst'];
  const recalc = debounce(async () => {
    if (!Number(form.elements.amount.value)) { taxBox.innerHTML = ''; return; }
    const body = Object.fromEntries(TAXKEYS.map((k) => [k, form.elements[k]?.value ?? '']));
    ['iss', 'pis', 'cofins', 'csll', 'irrf', 'inss'].forEach((k) => { body[k + '_withheld'] = form.elements[k + '_withheld']?.checked || false; });
    try {
      const t = await api('/nfse/taxes', { method: 'POST', body });
      const row = (l, k) => (Number(t[k + '_amount']) ? `<span>${l} <b>${money(t[k + '_amount'])}</b>${Number(t[k + '_withheld']) ? ' <em>retido</em>' : ''}</span>` : '');
      taxBox.innerHTML = `<div>${row('ISS', 'iss')}${row('PIS', 'pis')}${row('COFINS', 'cofins')}${row('CSLL', 'csll')}${row('IRRF', 'irrf')}${row('INSS', 'inss')}</div>
        <div class="tax-totals"><span>Base de cálculo <b>${money(t.base)}</b></span><span>Retenções <b class="${t.withheld_total ? 'neg' : ''}">${money(t.withheld_total)}</b></span><span>Valor líquido a receber <b class="pos">${money(t.net_amount)}</b></span><span class="muted">Tributos aproximados ${money(t.total_taxes_amount)} (${String(t.total_taxes_pct).replace('.', ',')}%)</span></div>`;
    } catch (e) { taxBox.innerHTML = ''; }
  }, 300);
  form.addEventListener('input', recalc);
  form.addEventListener('change', recalc);
  recalc();
  $('[data-ai-desc]', m.el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try { const r = await api('/ai/nfse-description', { method: 'POST', body: { note: form.elements.description.value } }); form.elements.description.value = r.text; } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-preview]', m.el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const box = $('[data-preview-box]', m.el);
    btn.classList.add('loading');
    try {
      const data = Object.fromEntries(['customer_id', 'amount', 'description', 'service_code', 'nbs_code', 'iss_rate', 'competence_date'].map((k) => [k, form.elements[k].value]));
      data.iss_withheld = form.elements.iss_withheld.checked;
      const r = await api('/nfse/preview', { method: 'POST', body: data });
      box.classList.remove('hidden');
      box.innerHTML = `<div class="alert ${r.valid ? 'alert-success' : 'alert-danger'}" style="margin:0 0 8px">${r.valid ? '✓ XML válido no leiaute oficial (DPS v1.01).' : 'XML inválido:' + errorList(r.errors)}</div>
        <pre class="mono" style="max-height:260px;overflow:auto;background:var(--bg-2);padding:12px;border-radius:10px;border:1px solid var(--border);white-space:pre;font-size:.74rem">${esc(r.xml)}</pre>`;
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
}

async function showInvoice(id, onChange, details) {
  const inv = await api('/nfse/' + id);
  const authorized = inv.status === 'authorized';
  const m = modal({
    title: `NFS-e ${inv.nfse_number ? 'nº ' + inv.nfse_number : '· DPS ' + inv.dps_number}`, size: 'lg',
    body: `
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">${badge('nfse_status', inv.status)} <span class="badge ${inv.provider === 'sigiss' ? 'blue' : inv.environment === 'production' ? 'green' : 'yellow'}">${inv.provider === 'sigiss' ? 'SIGISS Marília' : inv.environment === 'production' ? 'Sistema Nacional' : 'Nacional · produção restrita'}</span> <span class="muted small">Criada ${datetime(inv.created_at)}</span></div>
      ${inv.error_message || details ? `<div class="alert alert-danger"><b>Retorno do Sistema Nacional:</b>${errorList(details || inv.error_message.split('\n'))}</div>` : ''}
      <dl class="kv">
        <dt>Tomador</dt><dd>${esc(inv.toma_name)} <span class="muted mono">${esc(inv.toma_document)}</span></dd>
        <dt>Valor</dt><dd><b>${money(inv.amount)}</b> · ISS ${Number(inv.iss_rate).toLocaleString('pt-BR')}% = ${money(inv.iss_amount)}${Number(inv.iss_withheld) ? ' (retido)' : ''}${Number(inv.discount_amount) ? ` · desconto ${money(inv.discount_amount)}` : ''}${Number(inv.deductions) ? ` · deduções ${money(inv.deductions)}` : ''}</dd>
        ${['pis', 'cofins', 'csll', 'irrf', 'inss'].some((k) => Number(inv[k + '_amount'])) ? `<dt>Tributos federais</dt><dd>${['pis', 'cofins', 'csll', 'irrf', 'inss'].filter((k) => Number(inv[k + '_amount'])).map((k) => `${k.toUpperCase()} ${Number(inv[k + '_rate']).toLocaleString('pt-BR')}% = ${money(inv[k + '_amount'])}${Number(inv[k + '_withheld']) ? ' (retido)' : ''}`).join(' · ')}</dd>` : ''}
        ${inv.net_amount !== null && inv.net_amount !== undefined ? `<dt>Valor líquido</dt><dd><b>${money(inv.net_amount)}</b> · tributos aproximados ${money(inv.total_taxes_amount)} (${Number(inv.total_taxes_pct).toLocaleString('pt-BR')}%)</dd>` : ''}
        <dt>Serviço</dt><dd>cTribNac ${esc(inv.service_code)} · NBS ${esc(inv.nbs_code || '—')}</dd>
        <dt>Discriminação</dt><dd style="white-space:pre-wrap">${esc(inv.description)}</dd>
        <dt>Competência</dt><dd>${date(inv.competence_date)}</dd>
        <dt>${inv.provider === 'sigiss' ? 'RPS' : 'DPS'}</dt><dd class="mono small">${inv.provider === 'sigiss' ? `série ${esc(inv.dps_serie)} · nº ${esc(inv.dps_number)}` : esc(inv.dps_id)}</dd>
        <dt>Chave de acesso</dt><dd class="mono small">${esc(inv.access_key || '—')}</dd>
        ${inv.verification_code ? `<dt>Autenticidade</dt><dd class="mono small">${esc(inv.verification_code)}</dd>` : ''}
        <dt>Autorizada em</dt><dd>${datetime(inv.issued_at)}</dd>
        ${inv.status === 'canceled' ? `<dt>Cancelada em</dt><dd>${datetime(inv.canceled_at)} — ${esc(inv.cancel_reason)}</dd>` : ''}
      </dl>`,
    footer: `
      ${['draft', 'rejected'].includes(inv.status) ? `<button class="btn btn-danger" data-del>${icon('trash')} Excluir</button>` : ''}
      ${authorized ? `<button class="btn btn-danger" data-cancel>${icon('x')} Cancelar NFS-e</button>` : ''}
      <span style="flex:1"></span>
      ${inv.has_xml_dps ? `<a class="btn" href="${downloadUrl('/nfse/' + inv.id + '/xml', { type: 'dps' })}">${icon('code')} XML DPS</a>` : ''}
      ${inv.has_xml_nfse ? `<a class="btn" href="${downloadUrl('/nfse/' + inv.id + '/xml', { type: 'nfse' })}">${icon('download')} XML NFS-e</a>` : ''}
      ${authorized || inv.status === 'canceled' ? `<a class="btn" target="_blank" href="${downloadUrl('/nfse/' + inv.id + '/danfse', { source: 'local' })}">${icon('printer')} Imprimir</a><a class="btn btn-primary" target="_blank" href="${downloadUrl('/nfse/' + inv.id + '/danfse')}">${icon('file')} DANFSe (PDF)</a>` : ''}
      ${['draft', 'rejected'].includes(inv.status) ? `<button class="btn btn-primary" data-transmit>${icon('send')} ${inv.status === 'rejected' ? 'Corrigir e reenviar' : 'Transmitir'}</button>` : ''}`,
  });
  const done = () => { m.close(); onChange && onChange(); };
  $('[data-transmit]', m.el)?.addEventListener('click', async (e) => {
    e.currentTarget.classList.add('loading');
    try { const r = await api(`/nfse/${inv.id}/transmit`, { method: 'POST' }); toast(`NFS-e autorizada! Nº ${r.nfse_number || ''}`); m.close(); showInvoice(inv.id, onChange); onChange && onChange(); } catch (err) {
      m.close();
      showInvoice(inv.id, onChange, err.details && err.details.length ? err.details : undefined);
      toastError(err);
    }
  });
  $('[data-del]', m.el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Excluir este rascunho de nota?', { danger: true })) return;
    await api('/nfse/' + inv.id, { method: 'DELETE' }); toast('Nota excluída.'); done();
  });
  $('[data-cancel]', m.el)?.addEventListener('click', () => formModal({
    title: 'Cancelar NFS-e', size: 'sm', submitLabel: 'Cancelar nota',
    intro: '<div class="alert alert-warning" style="margin:0">O cancelamento é registrado no Sistema Nacional e não pode ser desfeito. Verifique o prazo de cancelamento do município.</div>',
    fields: [
      { name: 'reason', label: 'Motivo', type: 'select', empty: false, span: 2, options: [{ value: 1, label: 'Erro na emissão' }, { value: 2, label: 'Serviço não prestado' }, { value: 9, label: 'Outros' }] },
      { name: 'justification', label: 'Justificativa (mín. 15 caracteres)', type: 'textarea', required: true, span: 2 },
    ],
    onSubmit: async (d) => { await api(`/nfse/${inv.id}/cancel`, { method: 'POST', body: d }); toast('NFS-e cancelada.'); done(); },
  }));
}

export async function render(el, ctx) {
  const st = await api('/nfse/status');
  const cert = st.certificate;
  const certHtml = !cert ? '<span class="badge red">Não configurado</span>'
    : cert.error ? `<span class="badge red">Erro</span> <span class="small muted">${esc(cert.error)}</span>`
    : `<span class="badge ${cert.expired ? 'red' : 'green'}">${cert.expired ? 'Vencido' : 'Válido'}</span> <span class="small muted">${esc(cert.subject)} · até ${date(cert.valid_to)}</span>`;

  el.innerHTML = `
    <div class="page-head"><div><h2>Notas fiscais de serviço</h2><p>${st.config.provider === 'sigiss' ? 'Emissão pelo SIGISS da Prefeitura de Marília.' : 'Emissão direta no Sistema Nacional NFS-e (Sefin Nacional), com certificado A1.'}</p></div>
      <div class="page-actions">
        ${can('users') ? `<a class="btn" href="#/settings">${icon('settings')} Configurar</a>` : ''}
        <button class="btn btn-primary" data-new>${icon('plus')} Emitir NFS-e</button>
      </div></div>
    <div class="card card-pad" style="margin-bottom:16px;display:flex;gap:18px;flex-wrap:wrap;align-items:center">
      <div><small class="muted">Emissor</small><br><span class="badge ${st.config.provider === 'sigiss' ? 'blue' : st.config.environment === 'production' ? 'green' : 'yellow'}">${st.config.provider === 'sigiss' ? 'SIGISS Marília' : st.config.environment === 'production' ? 'Nacional · Produção' : 'Nacional · Produção restrita'}</span> ${st.ready ? '<span class="badge green">Pronto</span>' : '<span class="badge red">Configuração pendente</span>'}</div>
      <div><small class="muted">Prestador</small><br><b class="mono">${esc(st.config.cnpj || '—')}</b> · IM ${esc(st.config.im || '—')} · município IBGE ${esc(st.config.city_code)}</div>
      ${st.config.provider === 'sigiss' ? '' : `<div><small class="muted">Certificado A1</small><br>${certHtml}</div>`}
      <div><small class="muted">Próxima DPS</small><br><b>série ${esc(st.config.serie)} · nº ${st.config.next_number}</b></div>
      <span style="flex:1"></span>
      ${st.config.provider === 'sigiss' ? `<button class="btn btn-sm" data-sigiss ${st.ready ? '' : 'disabled'}>${icon('shield')} Testar acesso ao SIGISS</button>` : ''}
      <button class="btn btn-sm ${st.config.provider === 'sigiss' ? 'hidden' : ''}" data-muni ${cert && !cert.error ? '' : 'disabled'}>${icon('globe')} Verificar município no Sistema Nacional</button>
    </div>
    <div data-muni-out></div>
    <div class="grid g4" style="margin-bottom:16px">
      <div class="card kpi"><div class="k-label"><span class="k-ico">${icon('file')}</span>Notas no mês</div><div class="k-value">${st.totals.total || 0}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('check')}</span>Faturado (autorizadas)</div><div class="k-value pos">${money(st.totals.authorized_amount)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('receipt')}</span>ISS do mês</div><div class="k-value">${money(st.totals.iss_amount)}</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${st.totals.pending ? 'red' : 'green'}">${icon('alert')}</span>Pendentes/rejeitadas</div><div class="k-value">${st.totals.pending || 0}</div></div>
    </div>
    <div data-table></div>`;

  const table = dataTable($('[data-table]', el), {
    endpoint: '/nfse',
    searchPlaceholder: 'Buscar tomador, descrição, chave, número...',
    filters: [{ name: 'status', label: 'Todas as situações', options: options('nfse_status') }, { name: 'environment', label: 'Ambiente', options: [{ value: 'production', label: 'Produção' }, { value: 'homologation', label: 'Produção restrita' }] }],
    emptyText: 'Nenhuma nota emitida ainda.', emptyIcon: 'file',
    columns: [
      { label: 'Nº / DPS', render: (n) => `<b>${esc(n.nfse_number || '—')}</b><span class="sub">DPS ${n.dps_serie}/${n.dps_number}</span>` },
      { label: 'Tomador', primary: true, render: (n) => `<b>${esc(n.toma_name)}</b><span class="sub">${esc((n.description || '').slice(0, 70))}</span>` },
      { label: 'Competência', render: (n) => date(n.competence_date) },
      { label: 'Valor', sort: 'amount', num: true, render: (n) => `<b>${money(n.amount)}</b>` },
      { label: 'Situação', render: (n) => badge('nfse_status', n.status) + (n.environment !== 'production' ? ' <span class="badge yellow">teste</span>' : '') },
    ],
    onRowClick: (n) => showInvoice(n.id, () => table.reload()),
  });

  $('[data-new]', el).addEventListener('click', () => emitForm({}, () => table.reload()));
  $('[data-sigiss]', el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.classList.add('loading');
    try { const r = await api('/nfse/sigiss-test', { method: 'POST' }); toast(r.ok ? 'Acesso ao SIGISS confirmado ✅' : 'SIGISS recusou: ' + r.messages.join(' '), r.ok ? 'success' : 'error'); } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-muni]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      const r = await api('/nfse/municipality', { method: 'POST' });
      $('[data-muni-out]', el).innerHTML = r.ok
        ? `<div class="alert alert-success">Município conveniado ao Sistema Nacional. Parâmetros: <pre class="mono small" style="white-space:pre-wrap;margin:6px 0 0">${esc(JSON.stringify(r.data, null, 2).slice(0, 1500))}</pre></div>`
        : `<div class="alert alert-warning">Não foi possível confirmar o convênio do município.${errorList(r.errors)}</div>`;
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  if (ctx.query.new) {
    emitForm({ customer_id: ctx.query.customer_id, amount: ctx.query.amount, description: ctx.query.description, charge_id: ctx.query.charge_id, contract_id: ctx.query.contract_id, service_code: ctx.query.service_code }, () => table.reload());
    history.replaceState(null, '', '#/finance/nfse');
  }
}
