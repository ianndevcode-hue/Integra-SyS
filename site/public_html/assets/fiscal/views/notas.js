/* Invoices: list with filters, bulk downloads (ZIP), CSV export, batch transmit, detail with every action. */
import { api, $, $$, esc, icon, money, date, datetime, badge, dataTable, modal, formModal, confirmDialog, toast, toastError, downloadUrl, today, emptyState } from '/admin/js/core.js';
import { fh, provName, fmtDoc } from '/assets/fiscal/state.js';

const monthStart = () => today().slice(0, 8) + '01';
const VOIDABLE = ['draft', 'rejected', 'processing'];
const reloadList = () => window.dispatchEvent(new Event('fh:reload'));

/** Inutilização: the server first checks with the Sefin/SIGISS that the number never became a note, then voids it for good. */
function voidModal(rows, after = reloadList) {
  const one = rows.length === 1;
  const label = (r) => `DPS/RPS ${r.dps_serie ? r.dps_serie + '-' : ''}${r.dps_number}`;
  formModal({
    title: one ? 'Inutilizar ' + label(rows[0]) : `Inutilizar ${rows.length} números`, size: 'sm', submitLabel: one ? 'Inutilizar número' : `Inutilizar ${rows.length}`,
    intro: `<div class="alert alert-warning" style="margin:0"><b>A inutilização não pode ser desfeita.</b> O número ${one ? esc(label(rows[0])) : 'de cada nota selecionada'} fica registrado como não utilizado e nunca mais será emitido.
      <br><small>Antes de inutilizar, consultamos a Sefin Nacional ou a Prefeitura (SIGISS) para confirmar que o número não virou nota. Se virou, a nota é recuperada no sistema e você poderá cancelá-la.</small></div>
      ${one ? '' : `<p class="small muted" style="margin:10px 0 0">${rows.map((r) => esc(label(r) + ' · ' + (r.toma_name || ''))).join('<br>')}</p>`}`,
    fields: [{ name: 'justification', label: 'Justificativa (mín. 15 caracteres)', type: 'textarea', rows: 3, required: true, span: 2, placeholder: 'Ex.: nota rejeitada por dados incorretos, emitida novamente em outro número.' }],
    onSubmit: async (d) => {
      if ((d.justification || '').trim().length < 15) throw new Error('A justificativa precisa ter pelo menos 15 caracteres.');
      const errors = [];
      let ok = 0;
      for (const r of rows) {
        try { await api(`/fh/invoices/${r.id}/void`, { method: 'POST', body: d }); ok++; } catch (err) { errors.push({ r, msg: err.message || String(err) }); }
      }
      if (ok) toast(ok === 1 ? 'Número inutilizado.' : `${ok} números inutilizados.`);
      if (errors.length) {
        after(); // a number that did become a note was recovered: show it
        if (one) throw new Error(errors[0].msg);
        return void modal({ title: 'Não inutilizados', body: `<ul class="list">${errors.map((e) => `<li><div class="grow"><b>${esc(label(e.r))}</b><small>${esc(e.msg)}</small></div></li>`).join('')}</ul>` });
      }
      after();
    },
  });
}

export async function render(el, ctx) {
  if (ctx.id) return detail(el, ctx.id);
  const em = fh.emitter();
  if (!em) { el.innerHTML = emptyState('Cadastre sua empresa primeiro.', 'settings', '<a class="btn btn-primary" href="#/empresa/nova">Cadastrar empresa</a>'); return; }
  const scope = ctx.query.scope === 'all' ? '' : em.id;
  el.innerHTML = `
    <div class="page-head"><div><h2>Notas fiscais</h2><p>${scope ? esc(em.trade_name || em.legal_name) : 'Todas as empresas'} · filtre, selecione e baixe PDF/XML individualmente, em lote ou o período inteiro.</p></div>
      <div class="page-actions">
        ${fh.activeEmitters().length > 1 ? `<a class="btn" href="#/notas${scope ? '?scope=all' : ''}">${scope ? 'Ver todas as empresas' : 'Só a empresa atual'}</a>` : ''}
        <div class="fh-dropdown"><button class="btn" data-dl-toggle>${icon('download')} Baixar período</button><div class="fh-dropdown-menu hidden" data-dl-menu>
          <button data-dl="both">PDF + XML (ZIP)</button><button data-dl="pdf">Somente PDF (ZIP)</button><button data-dl="xml">Somente XML (ZIP)</button><button data-dl="csv">Planilha (CSV/Excel)</button></div></div>
        <a class="btn btn-primary" href="#/emitir">${icon('plus')} Emitir nota</a>
      </div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/fh/invoices', query: { emitter_id: scope }, perPage: 25, sort: 'issued_at', dir: 'desc', searchPlaceholder: 'Buscar por cliente, número, CPF/CNPJ ou descrição...',
    filters: [
      { name: 'status', label: 'Todas as situações', options: Object.entries({ authorized: 'Emitidas', draft: 'Rascunhos', rejected: 'Rejeitadas', canceled: 'Canceladas', voided: 'Inutilizadas', processing: 'Transmitindo' }).map(([value, label]) => ({ value, label })), value: ctx.query.status || '' },
      { name: 'from', type: 'date', label: 'De', value: ctx.query.from || monthStart() },
      { name: 'to', type: 'date', label: 'Até', value: ctx.query.to || today() },
    ],
    columns: [
      { label: 'Número', sort: 'nfse_number', primary: true, render: (r) => `<b>${r.nfse_number ? 'Nº ' + esc(r.nfse_number) : '<span class="muted">DPS ' + esc(r.dps_number) + '</span>'}</b>${!scope ? `<br><small class="muted">${esc(r.emitter_name)}</small>` : ''}` },
      { label: 'Data', sort: 'issued_at', render: (r) => (r.issued_at ? datetime(r.issued_at) : `<span class="muted">${date(r.created_at)}</span>`) },
      { label: 'Cliente', sort: 'toma_name', render: (r) => `${esc(r.toma_name)}<br><small class="muted">${esc(fmtDoc(r.toma_document))}</small>` },
      { label: 'Valor', sort: 'amount', num: true, render: (r) => money(r.amount) },
      { label: 'ISS', num: true, render: (r) => money(r.iss_amount) + (r.iss_retention !== '1' ? '<br><small class="muted">retido</small>' : '') },
      { label: 'Líquido', num: true, render: (r) => `<b>${money(r.net_amount)}</b>` },
      { label: 'Situação', render: (r) => badge('fh_invoice_status', r.status) + (r.environment !== 'production' ? ' <span class="badge">teste</span>' : '') + (r.emailed_at ? ` <span title="Enviada por e-mail em ${datetime(r.emailed_at)}">${icon('mail')}</span>` : '') },
    ],
    actions: (r) => [
      r.status !== 'draft' && { label: 'PDF', icon: 'download', iconOnly: true, onClick: () => window.open(downloadUrl(`/fh/invoices/${r.id}/pdf`), '_blank') },
      ['authorized', 'canceled', 'voided'].includes(r.status) && { label: 'XML', icon: 'code', iconOnly: true, onClick: () => { location.href = downloadUrl(`/fh/invoices/${r.id}/xml`); } },
      ['draft', 'rejected'].includes(r.status) && { label: 'Emitir', icon: 'send', success: true, onClick: () => { location.hash = '#/emitir/' + r.id; } },
      VOIDABLE.includes(r.status) && { label: 'Inutilizar', icon: 'ban', iconOnly: true, onClick: () => voidModal([r]) },
    ],
    onRowClick: (r) => { location.hash = '#/notas/' + r.id; },
    bulk: [
      { label: 'Baixar PDF + XML', icon: 'download', action: async (ids) => { location.href = downloadUrl('/fh/download', { ids: ids.join(','), what: 'both' }); } },
      { label: 'Só PDF', action: async (ids) => { location.href = downloadUrl('/fh/download', { ids: ids.join(','), what: 'pdf' }); } },
      { label: 'Só XML', action: async (ids) => { location.href = downloadUrl('/fh/download', { ids: ids.join(','), what: 'xml' }); } },
      { label: 'Emitir rascunhos selecionados', icon: 'send', action: async (ids) => transmitMany(ids) },
      { label: 'Inutilizar selecionadas', icon: 'ban', action: async (ids) => {
        const rows = table.state.rows.filter((r) => ids.includes(r.id) && VOIDABLE.includes(r.status));
        if (!rows.length) { toast('Selecione rascunhos, notas rejeitadas ou travadas em "Transmitindo".', 'error'); return; }
        voidModal(rows);
      } },
      { label: 'Planilha', action: async (ids) => { location.href = downloadUrl('/fh/export.csv', { ids: ids.join(',') }); } },
    ],
    totals: (rows, res) => (res.sum ? `<div class="totals"><span>${res.total} nota(s) no filtro</span><span>Emitido: <b>${money(res.sum.amount)}</b></span><span>ISS: <b>${money(res.sum.iss)}</b></span><span>Líquido: <b>${money(res.sum.net)}</b></span></div>` : ''),
    emptyText: 'Nenhuma nota neste período.', emptyIcon: 'file',
  });
  const filters = () => ({ emitter_id: scope, ...table.state.filters, q: table.state.q });
  $('[data-dl-toggle]', el).addEventListener('click', () => $('[data-dl-menu]', el).classList.toggle('hidden'));
  $$('[data-dl]', el).forEach((b) => b.addEventListener('click', () => {
    $('[data-dl-menu]', el).classList.add('hidden');
    const f = filters();
    location.href = b.dataset.dl === 'csv' ? downloadUrl('/fh/export.csv', f) : downloadUrl('/fh/download', { ...f, what: b.dataset.dl });
  }));
  async function transmitMany(ids) {
    const drafts = table.state.rows.filter((r) => ids.includes(r.id) && ['draft', 'rejected'].includes(r.status)).map((r) => r.id);
    if (!drafts.length) { toast('Selecione rascunhos ou notas rejeitadas.', 'error'); return; }
    if (!await confirmDialog(`Emitir ${drafts.length} nota(s) agora?`, { okLabel: 'Emitir' })) return;
    try {
      const r = await api('/fh/batch/transmit', { method: 'POST', body: { ids: drafts } });
      toast(`${r.ok.length} nota(s) emitida(s).`);
      if (r.errors.length) modal({ title: 'Notas com erro', body: `<ul class="list">${r.errors.map((x) => `<li><div class="grow"><b>#${x.id}</b><small>${esc(x.error)}</small></div></li>`).join('')}</ul>` });
      window.dispatchEvent(new Event('fh:reload'));
    } catch (err) { toastError(err); }
  }
}

async function detail(el, id) {
  const n = await api('/fh/invoices/' + id);
  const em = fh.me.emitters.find((e) => e.id === n.emitter_id) || {};
  const x = n.extra || {};
  const t = n.taker || {};
  const fed = ['pis', 'cofins', 'csll', 'irrf', 'inss'].filter((k) => Number(n[k + '_amount']));
  el.innerHTML = `
    <div class="page-head"><div><a class="muted small" href="#/notas">← Notas fiscais</a><h2 style="margin-top:4px">${n.nfse_number ? 'NFS-e nº ' + esc(n.nfse_number) : 'DPS/RPS ' + esc(n.dps_serie + '-' + n.dps_number)}</h2>
      <p>${badge('fh_invoice_status', n.status)} ${n.environment !== 'production' ? '<span class="badge yellow">produção restrita — sem valor fiscal</span>' : ''} · ${esc(provName(n.provider))} · ${esc(em.trade_name || em.legal_name || '')}</p></div>
      <div class="page-actions">
        ${n.status !== 'draft' ? `<a class="btn" href="${downloadUrl(`/fh/invoices/${n.id}/pdf`)}" target="_blank">${icon('download')} PDF</a>` : `<a class="btn" href="${downloadUrl(`/fh/invoices/${n.id}/pdf`)}" target="_blank">${icon('eye')} Prévia</a>`}
        ${['authorized', 'canceled', 'voided'].includes(n.status) ? `<a class="btn" href="${downloadUrl(`/fh/invoices/${n.id}/xml`)}">${icon('code')} XML</a>` : ''}
        ${n.print_url ? `<a class="btn" href="${esc(n.print_url)}" target="_blank" rel="noopener">${icon('external')} Oficial</a>` : ''}
        ${n.status === 'authorized' ? `<button class="btn" data-email>${icon('mail')} Enviar</button>` : ''}
        ${['draft', 'rejected'].includes(n.status) ? `<a class="btn btn-primary" href="#/emitir/${n.id}">${icon('send')} Corrigir e emitir</a>` : ''}
        <button class="btn" data-dup>${icon('copy')} Duplicar</button>
        ${n.status === 'authorized' && n.provider === 'nacional' ? `<button class="btn" data-subst>${icon('refresh')} Substituir</button>` : ''}
        ${n.status === 'authorized' ? `<button class="btn btn-danger" data-cancel>${icon('x')} Cancelar</button>` : ''}
        ${VOIDABLE.includes(n.status) ? `<button class="btn btn-danger" data-void>${icon('ban')} Inutilizar</button>` : ''}
        ${['draft', 'rejected'].includes(n.status) ? `<button class="btn btn-danger btn-icon" data-del title="Excluir rascunho">${icon('trash')}</button>` : ''}
      </div></div>
    ${n.status === 'rejected' && n.error_message ? `<div class="alert alert-danger"><b>Motivo da rejeição:</b><br>${esc(n.error_message).replace(/\n/g, '<br>')}</div>` : ''}
    ${n.status === 'canceled' ? `<div class="alert alert-warning">Cancelada em ${datetime(n.canceled_at)}${n.cancel_reason ? ' — ' + esc(n.cancel_reason) : ''}.</div>` : ''}
    ${n.status === 'voided' ? `<div class="alert alert-warning"><b>Número inutilizado</b> em ${datetime(n.canceled_at)}${x.inutilizacao?.usuario ? ' por ' + esc(x.inutilizacao.usuario) : ''} — ${esc(n.cancel_reason || '')}.
      ${(x.inutilizacao?.verificacao || []).map((v) => `<br><small>${icon('check')} ${esc(v)}</small>`).join('')}
      <br><small class="muted">O DPS/RPS ${esc(n.dps_serie + '-' + n.dps_number)} não será usado novamente. Para emitir este serviço, use "Duplicar" (a cópia recebe um novo número).</small></div>` : ''}
    <div class="grid g3">
      <section class="card"><div class="card-head"><h3>Identificação</h3></div><div class="card-body"><dl class="kv">
        <dt>Número</dt><dd>${esc(n.nfse_number || '—')}</dd><dt>Emissão</dt><dd>${n.issued_at ? datetime(n.issued_at) : '—'}</dd><dt>Competência</dt><dd>${date(n.competence_date)}</dd>
        <dt>DPS/RPS</dt><dd>${esc(n.dps_serie)}-${esc(n.dps_number)}</dd><dt>Verificação</dt><dd class="mono">${esc(n.verification_code || '—')}</dd>
        <dt>Chave de acesso</dt><dd class="mono small" style="word-break:break-all">${esc(n.access_key || '—')}</dd><dt>Origem</dt><dd>${esc({ manual: 'Manual', batch: 'Lote', recurring: 'Recorrente', substitute: 'Substituição' }[n.source] || n.source)}</dd>
        ${n.emailed_at ? `<dt>E-mail</dt><dd>Enviada em ${datetime(n.emailed_at)}</dd>` : ''}
      </dl></div></section>
      <section class="card"><div class="card-head"><h3>Cliente (tomador)</h3></div><div class="card-body"><dl class="kv">
        <dt>Nome</dt><dd>${esc(n.toma_name)}</dd><dt>Documento</dt><dd>${esc(fmtDoc(n.toma_document))}</dd><dt>E-mail</dt><dd>${esc(n.toma_email || '—')}</dd>
        <dt>Endereço</dt><dd>${esc([t.street, t.number, t.district, t.city && t.city + '/' + (t.uf || ''), t.cep].filter(Boolean).join(', ') || [t.foreign_city, t.country].filter(Boolean).join(' / ') || '—')}</dd>
      </dl></div></section>
      <section class="card"><div class="card-head"><h3>Valores</h3></div><div class="card-body"><dl class="kv">
        <dt>Serviço</dt><dd>${money(n.amount)}</dd>${Number(n.discount_incond) ? `<dt>Desc. incondicionado</dt><dd>${money(n.discount_incond)}</dd>` : ''}${Number(n.discount_cond) ? `<dt>Desc. condicionado</dt><dd>${money(n.discount_cond)}</dd>` : ''}${Number(n.deductions) ? `<dt>Deduções</dt><dd>${money(n.deductions)}</dd>` : ''}
        <dt>Base ISS</dt><dd>${money(n.base)}</dd><dt>ISS ${Number(n.iss_rate).toLocaleString('pt-BR')}%</dt><dd>${money(n.iss_amount)}${n.iss_retention !== '1' ? ' (retido)' : ''}</dd>
        ${fed.map((k) => `<dt>${k.toUpperCase()}</dt><dd>${money(n[k + '_amount'])}${Number(n[k + '_withheld']) ? ' (retido)' : ''}</dd>`).join('')}
        <dt>Líquido</dt><dd><b class="pos">${money(n.net_amount)}</b></dd>
      </dl></div></section>
    </div>
    <section class="card" style="margin-top:16px"><div class="card-head"><h3>Serviço e discriminação</h3><span class="muted small">LC 116 ${esc(n.lc116 || '—')} · ${n.provider === 'sigiss' ? 'SIGISS ' + esc(n.sigiss_code || '') : 'cTribNac ' + esc(n.ctribnac || '')}</span></div><div class="card-body"><p style="white-space:pre-wrap;margin:0">${esc(n.description)}</p>
      ${x.obra || x.evento || x.comext || x.interm || x.loc ? `<div class="chips-row" style="margin-top:12px">${x.loc ? `<span class="badge">Local: ${esc(x.loc.type === 'outro' ? 'IBGE ' + x.loc.city_ibge : x.loc.country)}</span>` : ''}${x.obra ? '<span class="badge">Obra</span>' : ''}${x.evento ? `<span class="badge">Evento: ${esc(x.evento.nome)}</span>` : ''}${x.comext ? '<span class="badge">Comércio exterior</span>' : ''}${x.interm ? `<span class="badge">Intermediário: ${esc(x.interm.name)}</span>` : ''}</div>` : ''}
    </div></section>`;
  const reload = () => detail(el, id);
  $('[data-email]', el)?.addEventListener('click', () => formModal({ title: 'Enviar nota por e-mail', size: 'sm', values: { to: n.toma_email || '' }, fields: [{ name: 'to', label: 'E-mail do destinatário', type: 'email', required: true, span: 2 }], submitLabel: 'Enviar',
    onSubmit: async (d) => { await api(`/fh/invoices/${n.id}/email`, { method: 'POST', body: d }); toast('Nota enviada (PDF + XML).'); reload(); } }));
  $('[data-dup]', el).addEventListener('click', async () => { try { const d = await api(`/fh/invoices/${n.id}/duplicate`, { method: 'POST' }); toast('Cópia criada como rascunho.'); location.hash = '#/emitir/' + d.id; } catch (err) { toastError(err); } });
  $('[data-cancel]', el)?.addEventListener('click', () => formModal({
    title: 'Cancelar NFS-e nº ' + (n.nfse_number || ''), size: 'sm', submitLabel: 'Cancelar nota', values: { reason: '1' },
    intro: '<div class="alert alert-warning" style="margin:0">O cancelamento é enviado para a ' + (n.provider === 'sigiss' ? 'Prefeitura (SIGISS)' : 'Receita (Emissor Nacional)') + ' e não pode ser desfeito. Verifique o prazo de cancelamento do seu município.</div>',
    fields: [{ name: 'reason', label: 'Motivo', type: 'select', empty: false, span: 2, options: [{ value: '1', label: 'Erro na emissão' }, { value: '2', label: 'Serviço não prestado' }, { value: '9', label: 'Outros' }] },
      { name: 'justification', label: 'Justificativa (mín. 15 caracteres)', type: 'textarea', rows: 3, required: true, span: 2 }],
    onSubmit: async (d) => { await api(`/fh/invoices/${n.id}/cancel`, { method: 'POST', body: d }); toast('Nota cancelada.'); window.dispatchEvent(new Event('fh:reload')); },
  }));
  $('[data-subst]', el)?.addEventListener('click', () => formModal({
    title: 'Substituir NFS-e', size: 'sm', submitLabel: 'Criar nota substituta', values: { motivo: '99' },
    intro: '<p class="small muted" style="margin:0">Criamos um rascunho com os mesmos dados, vinculado a esta nota. Corrija o que precisar e emita: a nota original é cancelada automaticamente pela substituição.</p>',
    fields: [{ name: 'motivo', label: 'Motivo', type: 'select', empty: false, span: 2, options: [['01', 'Desenquadramento do Simples Nacional'], ['02', 'Enquadramento no Simples Nacional'], ['03', 'Inclusão retroativa de imunidade/isenção'], ['04', 'Exclusão retroativa de imunidade/isenção'], ['05', 'Rejeição pelo tomador/intermediário'], ['99', 'Outros']].map(([value, label]) => ({ value, label })) },
      { name: 'descricao', label: 'Descrição do motivo', type: 'textarea', rows: 2, span: 2 }],
    onSubmit: async (d) => { const r = await api(`/fh/invoices/${n.id}/substitute`, { method: 'POST', body: d }); location.hash = '#/emitir/' + r.id; },
  }));
  $('[data-void]', el)?.addEventListener('click', () => voidModal([n], reload));
  $('[data-del]', el)?.addEventListener('click', async () => { if (!await confirmDialog('Excluir este rascunho?', { danger: true })) return; await api('/fh/invoices/' + n.id, { method: 'DELETE' }).catch(toastError); location.hash = '#/notas'; });
}
