/* Takers (clientes) and services catalog of the current company. */
import { api, $, esc, icon, money, dataTable, formModal, confirmDialog, toast, toastError, emptyState } from '/admin/js/core.js';
import { fh, SITUATIONS, COUNTRIES, fmtDoc } from '/assets/fiscal/state.js';

export async function render(el, ctx) {
  const em = fh.emitter();
  if (!em) { el.innerHTML = emptyState('Cadastre sua empresa primeiro.', 'settings', '<a class="btn btn-primary" href="#/empresa/nova">Cadastrar empresa</a>'); return; }
  return ctx.sub === 'services' ? services(el, em) : takers(el, em);
}

/* ------------------------------------------------------------------ takers */
function takers(el, em) {
  el.innerHTML = `<div class="page-head"><div><h2>Clientes (tomadores)</h2><p>${esc(em.trade_name || em.legal_name)} · cadastre uma vez e emita em segundos.</p></div>
    <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Novo cliente</button></div></div><div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: `/fh/emitters/${em.id}/takers`, perPage: 50, searchPlaceholder: 'Buscar por nome ou CPF/CNPJ...',
    columns: [
      { label: 'Cliente', primary: true, render: (t) => `<b>${esc(t.name)}</b>${t.trade_name ? `<br><small class="muted">${esc(t.trade_name)}</small>` : ''}` },
      { label: 'Documento', render: (t) => (t.kind === 'ext' ? `NIF ${esc(t.nif || '—')} · ${esc(t.country || '')}` : t.kind === 'pfni' ? 'Não identificado' : esc(fmtDoc(t.document))) },
      { label: 'Contato', render: (t) => `${esc(t.email || '—')}${t.phone ? `<br><small class="muted">${esc(t.phone)}</small>` : ''}` },
      { label: 'Cidade', render: (t) => esc([t.city, t.uf].filter(Boolean).join('/') || t.foreign_city || '—') + (['pj', 'pf'].includes(t.kind) && !(t.street && t.number && t.city_ibge) ? ' <span class="badge yellow" title="O SIGISS exige endereço completo">endereço incompleto</span>' : '') },
      { label: 'Notas', num: true, render: (t) => `${t.invoices}<br><small class="muted">${money(t.billed)}</small>` },
    ],
    actions: (t) => [
      { label: 'Emitir', icon: 'send', iconOnly: true, success: true, onClick: () => { location.hash = '#/emitir?taker=' + t.id; } },
      { label: 'Editar', icon: 'edit', iconOnly: true, onClick: () => takerForm(em, t, reload) },
      { label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (!await confirmDialog(`Excluir ${t.name}? As notas já emitidas continuam guardadas.`, { danger: true })) return; try { await api('/fh/takers/' + t.id, { method: 'DELETE' }); fh.cache.takers = {}; reload(); } catch (err) { toastError(err); } } },
    ],
    onRowClick: (t) => takerForm(em, t, reload),
    emptyText: 'Nenhum cliente cadastrado ainda.', emptyIcon: 'users',
  });
  const reload = () => { fh.cache.takers = {}; table.reload(); };
  $('[data-new]', el).addEventListener('click', () => takerForm(em, null, reload));
}

export function takerForm(em, t, onDone) {
  const m = formModal({
    title: t ? 'Editar cliente' : 'Novo cliente', size: 'lg', values: t || { kind: 'pj', country: 'US' },
    fields: [
      { name: 'kind', label: 'Tipo', type: 'select', empty: false, options: [{ value: 'pj', label: 'Pessoa jurídica (CNPJ)' }, { value: 'pf', label: 'Pessoa física (CPF)' }, { value: 'ext', label: 'Cliente no exterior' }] },
      { name: 'document', label: 'CPF / CNPJ' },
      { name: 'name', label: 'Nome / razão social', required: true, span: 2 },
      { name: 'trade_name', label: 'Nome fantasia' },
      { name: 'email', label: 'E-mail (recebe a nota)', type: 'email' },
      { name: 'phone', label: 'Telefone' },
      { name: 'im', label: 'Inscrição municipal' },
      { name: 'cep', label: 'CEP' },
      { name: 'street', label: 'Logradouro', span: 2 },
      { name: 'number', label: 'Número' },
      { name: 'complement', label: 'Complemento' },
      { name: 'district', label: 'Bairro' },
      { name: 'city', label: 'Cidade' },
      { name: 'uf', label: 'UF' },
      { name: 'city_ibge', label: 'Código IBGE (pelo CEP)' },
      { name: 'country', label: 'País (exterior)', type: 'select', empty: false, options: COUNTRIES.map(([value, label]) => ({ value, label })) },
      { name: 'foreign_city', label: 'Cidade no exterior' },
      { name: 'foreign_region', label: 'Estado/província' },
      { name: 'foreign_postal', label: 'Código postal' },
      { name: 'nif', label: 'NIF (identificação fiscal estrangeira)' },
      { name: 'notes', label: 'Observações', type: 'textarea', rows: 2, span: 2 },
    ],
    onReady: (form) => {
      const sync = () => {
        const ext = form.elements.kind.value === 'ext';
        ['country', 'foreign_city', 'foreign_region', 'foreign_postal', 'nif'].forEach((n) => form.querySelector(`[data-field="${n}"]`).classList.toggle('hidden', !ext));
        ['document', 'cep', 'district', 'city', 'uf', 'city_ibge', 'im'].forEach((n) => form.querySelector(`[data-field="${n}"]`).classList.toggle('hidden', ext));
      };
      form.elements.kind.addEventListener('change', sync);
      sync();
      const fillCep = async () => {
        const cep = form.elements.cep.value.replace(/\D/g, '');
        if (cep.length !== 8) return;
        try { const r = await api('/fh/cep/' + cep); ['street', 'district', 'city', 'uf', 'city_ibge'].forEach((k) => { if (r[k] && (!form.elements[k].value || ['city', 'uf', 'city_ibge'].includes(k))) form.elements[k].value = r[k]; }); } catch (e) { /* ignore */ }
      };
      form.elements.cep.addEventListener('change', fillCep);
      form.elements.document.addEventListener('change', async () => {
        const d = form.elements.document.value.replace(/\D/g, '');
        if (d.length !== 14 || form.elements.name.value) return;
        try {
          const r = await api('/fh/cnpj/' + d);
          ['cep', 'street', 'number', 'complement', 'district', 'city', 'uf', 'city_ibge', 'email', 'phone', 'trade_name'].forEach((k) => { if (r[k] && !form.elements[k].value) form.elements[k].value = r[k]; });
          form.elements.name.value = r.legal_name;
          toast('Dados do CNPJ preenchidos.');
        } catch (e) { /* ignore */ }
      });
    },
    onSubmit: async (d) => {
      await api(t ? '/fh/takers/' + t.id : `/fh/emitters/${em.id}/takers`, { method: t ? 'PUT' : 'POST', body: d });
      toast('Cliente salvo.');
      fh.cache.takers = {};
      onDone && onDone();
    },
  });
  return m;
}

/* ---------------------------------------------------------------- services */
async function services(el, em) {
  const lc = await fh.lc116();
  const nac = em.provider === 'nacional';
  el.innerHTML = `<div class="page-head"><div><h2>Serviços</h2><p>Deixe os códigos fiscais e a alíquota prontos: na emissão basta escolher o serviço.</p></div>
    <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Novo serviço</button></div></div><div data-list></div>`;
  const load = async () => {
    const list = await fh.services(true);
    $('[data-list]', el).innerHTML = list.length ? `<div class="fh-services">${list.map((s) => `<article class="card fh-service ${Number(s.active) ? '' : 'off'}" data-id="${s.id}">
        <div class="card-head"><div><h3>${esc(s.name)}</h3><small class="muted">LC 116 ${esc(s.lc116 || '—')} · ${nac ? 'cTribNac ' + esc(s.ctribnac || '—') : 'SIGISS ' + esc(s.sigiss_code || '—')}${s.cnbs ? ' · NBS ' + esc(s.cnbs) : ''}</small></div>${Number(s.active) ? '' : '<span class="badge">inativo</span>'}</div>
        <div class="card-body"><p class="small" style="margin:0 0 8px">${esc(s.description || (lc.find((i) => i.code === s.lc116) || {}).name || '')}</p>
          <div class="chips-row"><span class="badge blue">ISS ${s.iss_rate !== null ? String(s.iss_rate).replace('.', ',') + '%' : 'padrão da empresa'}</span>${s.sigiss_situacao && s.sigiss_situacao !== 'tp' ? `<span class="badge">${esc((SITUATIONS.find((x) => x[0] === s.sigiss_situacao) || [])[1] || '')}</span>` : ''}${Number(s.price) ? `<span class="badge green">${money(s.price)}</span>` : ''}<span class="badge">${s.invoices} nota(s)</span></div></div>
        <div class="fh-card-actions"><a class="btn btn-sm btn-primary" href="#/emitir?service=${s.id}">${icon('send')} Emitir</a><button class="btn btn-sm" data-edit>${icon('edit')} Editar</button><button class="btn btn-sm btn-ghost" data-del>${icon('trash')}</button></div>
      </article>`).join('')}</div>` : emptyState('Nenhum serviço cadastrado. Cadastre os serviços que você presta com o item da LC 116.', 'tag', '<button class="btn btn-primary" data-new2>Cadastrar serviço</button>');
    $('[data-new2]', el)?.addEventListener('click', () => serviceForm(em, null, lc, load));
  };
  $('[data-list]', el).addEventListener('click', async (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const s = (await fh.services()).find((x) => x.id === +card.dataset.id);
    if (e.target.closest('[data-edit]')) serviceForm(em, s, lc, load);
    if (e.target.closest('[data-del]')) {
      if (!await confirmDialog(`Remover o serviço "${s.name}"? Se já tiver notas, ele é apenas desativado.`, { danger: true })) return;
      await api('/fh/services/' + s.id, { method: 'DELETE' }).catch(toastError);
      load();
    }
  });
  $('[data-new]', el).addEventListener('click', () => serviceForm(em, null, lc, load));
  await load();
}

function serviceForm(em, s, lc, onDone) {
  const nac = em.provider === 'nacional';
  const lcLabel = (code) => { const it = lc.find((i) => i.code === code); return it ? `${it.code} — ${it.name}` : (code || ''); };
  formModal({
    title: s ? 'Editar serviço' : 'Novo serviço', size: 'lg', values: { ...(s || { sigiss_situacao: 'tp', active: true }), lc116: lcLabel(s?.lc116) },
    intro: `<datalist id="fh-lc-list">${lc.map((i) => `<option value="${i.code} — ${esc(i.name)}">`).join('')}</datalist>`,
    fields: [
      { name: 'name', label: 'Nome do serviço', required: true, span: 2, placeholder: 'Ex.: Consulta médica, Desenvolvimento de sistema, Manutenção mensal' },
      { name: 'lc116', label: 'Item da LC 116 (digite código ou palavra)', required: true, span: 2 },
      nac ? { name: 'ctribnac', label: 'Código de tributação nacional (6 dígitos)', help: 'Preenchido pelo item; confira o desdobro com seu contador.' } : { name: 'sigiss_code', label: 'Código do serviço no SIGISS', help: 'Ex.: 1701 para o item 17.01 (como no seu cadastro da prefeitura).' },
      { name: 'ctribmun', label: 'Código de tributação municipal (opcional)' },
      { name: 'cnbs', label: 'Código NBS (opcional, 9 dígitos)' },
      { name: 'iss_rate', label: 'Alíquota do ISS (%)', type: 'number', step: '0.01', min: 0, max: 5, placeholder: 'padrão da empresa' },
      { name: 'sigiss_situacao', label: 'Situação padrão do ISS', type: 'select', empty: false, span: 2, options: SITUATIONS.filter(([k]) => nac || !['ti', 'es'].includes(k)).map(([value, label]) => ({ value, label })) },
      { name: 'price', label: 'Preço padrão (R$)', type: 'money' },
      { name: 'unit', label: 'Unidade', placeholder: 'hora, mês, sessão...' },
      { name: 'description', label: 'Discriminação padrão', type: 'textarea', rows: 3, span: 2 },
      { name: '_fed', type: 'html', span: 2, html: '<p class="small muted" style="margin:0">Alíquotas federais específicas deste serviço (deixe em branco para usar as da empresa):</p>' },
      ...['pis', 'cofins', 'csll', 'irrf', 'inss'].map((k) => ({ name: k + '_rate', label: k.toUpperCase() + ' (%)', type: 'number', step: '0.01', min: 0, max: 30 })),
      { name: 'active', label: 'Ativo', type: 'checkbox' },
    ],
    onReady: (form) => {
      form.elements.lc116.setAttribute('list', 'fh-lc-list');
      form.elements.lc116.addEventListener('change', () => {
        const code = form.elements.lc116.value.split('—')[0].trim();
        const it = lc.find((i) => i.code === code || i.code.replace(/^0/, '') === code);
        if (!it) return;
        form.elements.lc116.value = `${it.code} — ${it.name}`;
        if (form.elements.ctribnac) form.elements.ctribnac.value = it.ctribnac;
        if (form.elements.sigiss_code) form.elements.sigiss_code.value = it.sigiss;
        if (!form.elements.name.value) form.elements.name.value = it.name.slice(0, 80);
      });
    },
    onSubmit: async (d) => {
      d.lc116 = d.lc116.split('—')[0].trim();
      delete d._fed;
      await api(s ? '/fh/services/' + s.id : `/fh/emitters/${em.id}/services`, { method: s ? 'PUT' : 'POST', body: d });
      toast('Serviço salvo.');
      fh.cache.services = {};
      onDone && onDone();
    },
  });
}
