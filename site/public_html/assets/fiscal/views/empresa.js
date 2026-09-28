/* Company (emitter) setup: identification, address, tax regime, channel, certificate, defaults. */
import { api, $, $$, esc, icon, date, toast, toastError, confirmDialog, fieldHtml, readForm, showFieldErrors, emptyState } from '/admin/js/core.js';
import { fh, REGIMES, provName, fmtDoc } from '/assets/fiscal/state.js';

export async function render(el, ctx) {
  await fh.refresh();
  if (ctx.sub === 'edit') return renderForm(el, ctx.key === 'nova' ? null : fh.me.emitters.find((e) => e.id === ctx.id));
  const list = fh.me.emitters;
  const u = fh.access.usage || {};
  el.innerHTML = `
    <div class="page-head"><div><h2>Empresas emissoras</h2><p>Dados fiscais, canal de emissão e certificado digital de cada empresa.</p></div>
      <div class="page-actions"><a class="btn btn-primary" href="#/empresa/nova" ${u.companies >= u.companies_limit ? 'data-limit' : ''}>${icon('plus')} Nova empresa</a></div></div>
    <p class="muted small">${u.companies || 0} de ${u.companies_limit || 1} empresa(s) do seu plano.</p>
    <div class="grid g2" data-list>${list.length ? list.map(card).join('') : emptyState('Nenhuma empresa cadastrada ainda.', 'settings', '<a class="btn btn-primary" href="#/empresa/nova">Cadastrar agora</a>')}</div>`;
  $('[data-limit]', el)?.addEventListener('click', (e) => { e.preventDefault(); toast(`Seu plano permite ${u.companies_limit} empresa(s). Faça upgrade em Assinatura.`, 'error'); });
  el.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const id = +b.closest('[data-id]').dataset.id;
    const em = fh.me.emitters.find((x) => x.id === id);
    try {
      if (b.dataset.act === 'test') {
        b.classList.add('loading');
        const r = await api(`/fh/emitters/${id}/test`, { method: 'POST' }).finally(() => b.classList.remove('loading'));
        toast(r.messages.join(' '), r.ok ? 'success' : 'error');
      } else if (b.dataset.act === 'toggle') {
        if (Number(em.active)) {
          if (!await confirmDialog('Desativar esta empresa? As notas continuam disponíveis para consulta.', { danger: true, okLabel: 'Desativar' })) return;
          await api('/fh/emitters/' + id, { method: 'DELETE' });
        } else await api('/fh/emitters/' + id, { method: 'PUT', body: { active: true } });
        window.dispatchEvent(new Event('fh:reload'));
      }
    } catch (err) { toastError(err); }
  });
}

function card(e) {
  return `<section class="card fh-emitter ${Number(e.active) ? '' : 'off'}" data-id="${e.id}">
    <div class="card-head"><div><h3>${esc(e.trade_name || e.legal_name)}</h3><small class="muted">${esc(e.legal_name)} · ${e.document.length === 11 ? 'CPF' : 'CNPJ'} ${esc(fmtDoc(e.document))}</small></div>
      ${Number(e.active) ? (e.ready ? '<span class="badge green">Pronta</span>' : '<span class="badge yellow">Pendente</span>') : '<span class="badge">Desativada</span>'}</div>
    <div class="card-body">
      <dl class="kv">
        <dt>Canal</dt><dd>${esc(provName(e.provider))}${e.provider === 'nacional' ? ` · ${e.environment === 'production' ? '<b>Produção</b>' : 'Produção restrita (testes)'}` : ''}</dd>
        <dt>Regime</dt><dd>${esc((REGIMES.find((r) => r[0] === e.op_simp_nac) || [])[1] || '')}</dd>
        <dt>Inscrição municipal</dt><dd>${esc(e.im || '—')}</dd>
        ${e.provider === 'sigiss' ? `<dt>Senha SIGISS</dt><dd>${e.has_sigiss_password ? 'Cadastrada' : '<span class="neg">Não informada</span>'}</dd>` : ''}
        <dt>Certificado A1</dt><dd>${e.has_certificate ? `${e.cert_expired ? '<span class="neg">Vencido</span>' : `Válido até <b>${date(e.cert_valid_to)}</b>`}${e.cert_days_left !== null && e.cert_days_left <= 30 && !e.cert_expired ? ` <span class="badge yellow">vence em ${e.cert_days_left} dia(s)</span>` : ''}` : `<span class="${e.provider === 'nacional' ? 'neg' : 'muted'}">Não enviado${e.provider === 'sigiss' ? ' (opcional no SIGISS)' : ''}</span>`}</dd>
        <dt>Próxima DPS/RPS</dt><dd>Série ${esc(e.dps_serie)} · nº ${esc(e.next_number)}</dd>
      </dl>
      ${e.problems.length ? `<div class="alert alert-warning small" style="margin:12px 0 0">${e.problems.map(esc).join('<br>')}</div>` : ''}
    </div>
    <div class="fh-card-actions"><a class="btn btn-sm btn-primary" href="#/empresa/${e.id}">${icon('edit')} Editar</a><button class="btn btn-sm" data-act="test">${icon('bolt')} Testar conexão</button><button class="btn btn-sm btn-ghost" data-act="toggle">${Number(e.active) ? 'Desativar' : 'Reativar'}</button></div>
  </section>`;
}

function renderForm(el, em) {
  const isNew = !em;
  const v = em || { provider: 'sigiss', op_simp_nac: '3', reg_esp_trib: '0', environment: 'homologation', dps_serie: '1', next_number: 1, show_taxes: 1, auto_email: 1, city_ibge: '3529005', uf: 'SP', city: 'Marília' };
  const f = (def) => fieldHtml(def, v[def.name]);
  el.innerHTML = `
    <div class="page-head"><div><a class="muted small" href="#/empresa">← Empresas</a><h2 style="margin-top:4px">${isNew ? 'Cadastrar empresa emissora' : esc(v.trade_name || v.legal_name)}</h2><p>${isNew ? 'Comece pelo CNPJ: buscamos os dados públicos para você.' : 'Alterações valem para as próximas notas.'}</p></div></div>
    <form class="fh-form" data-form novalidate>
      <section class="card"><div class="card-head"><h3>${icon('user')} Identificação</h3></div><div class="card-body form-grid cols-4">
        <div class="field"><label>CNPJ ou CPF *</label><div class="fh-inline"><input name="document" value="${esc(fmtDoc(v.document || ''))}" ${isNew ? '' : 'readonly'} inputmode="numeric" placeholder="00.000.000/0000-00"><button type="button" class="btn btn-sm" data-cnpj ${isNew ? '' : 'hidden'}>${icon('search')} Buscar</button></div></div>
        ${f({ name: 'legal_name', label: 'Razão social / nome *', span: 2 })}
        ${f({ name: 'trade_name', label: 'Nome fantasia' })}
        ${f({ name: 'im', label: 'Inscrição municipal (CCM)' })}
        ${f({ name: 'ie', label: 'Inscrição estadual' })}
        ${f({ name: 'cnae', label: 'CNAE principal' })}
        ${f({ name: 'email', label: 'E-mail fiscal', type: 'email' })}
        ${f({ name: 'phone', label: 'Telefone' })}
      </div></section>
      <section class="card"><div class="card-head"><h3>${icon('home')} Endereço</h3></div><div class="card-body form-grid cols-4">
        <div class="field"><label>CEP</label><div class="fh-inline"><input name="cep" value="${esc(v.cep || '')}" inputmode="numeric"><button type="button" class="btn btn-sm" data-cep>${icon('search')}</button></div></div>
        ${f({ name: 'street', label: 'Logradouro', span: 2 })}
        ${f({ name: 'number', label: 'Número' })}
        ${f({ name: 'complement', label: 'Complemento' })}
        ${f({ name: 'district', label: 'Bairro' })}
        ${f({ name: 'city', label: 'Cidade' })}
        ${f({ name: 'uf', label: 'UF' })}
        ${f({ name: 'city_ibge', label: 'Código IBGE do município', help: 'Preenchido pelo CEP. Marília = 3529005.' })}
      </div></section>
      <section class="card"><div class="card-head"><h3>${icon('shield')} Regime tributário</h3></div><div class="card-body form-grid cols-4">
        ${f({ name: 'op_simp_nac', label: 'Situação no Simples Nacional', type: 'select', empty: false, span: 2, options: REGIMES.map(([value, label]) => ({ value, label })) })}
        ${f({ name: 'reg_esp_trib', label: 'Regime especial', type: 'select', empty: false, options: Object.entries(fh.me.reg_esp).map(([value, label]) => ({ value, label })) })}
        ${f({ name: 'reg_ap_trib_sn', label: 'Apuração no Simples (se ultrapassou sublimite)', type: 'select', empty: 'Normal (não informar)', options: [{ value: '1', label: 'Federais e ISS pelo Simples' }, { value: '2', label: 'Federais pelo Simples, ISS fora' }, { value: '3', label: 'Federais e ISS fora do Simples' }] })}
        ${f({ name: 'iss_rate', label: 'Alíquota padrão do ISS (%)', type: 'number', step: '0.01', min: 0, max: 5, help: 'No Simples, use a alíquota de ISS da sua faixa.' })}
        ${f({ name: 'simples_rate', label: 'Alíquota efetiva do Simples (%)', type: 'number', step: '0.01', min: 0, max: 33, help: 'Para "tributos aproximados" (Lei 12.741).' })}
        ${f({ name: 'total_tax_pct', label: 'Carga tributária aproximada (%)', type: 'number', step: '0.01', min: 0, max: 60, help: 'Opcional (tabela IBPT).' })}
        ${f({ name: 'pis_cofins_cst', label: 'CST PIS/COFINS padrão', type: 'select', empty: 'Automático', options: [['01', '01 — Tributável'], ['49', '49 — Outras saídas'], ['06', '06 — Alíquota zero'], ['07', '07 — Isenta'], ['08', '08 — Sem incidência'], ['09', '09 — Suspensão'], ['99', '99 — Outras']].map(([value, label]) => ({ value, label })) })}
      </div>
      <details class="fh-details"><summary>Alíquotas federais padrão e retenções (não optantes)</summary><div class="form-grid cols-4" style="padding:0 18px 18px">
        ${['pis', 'cofins', 'csll', 'irrf', 'inss'].map((k) => f({ name: k + '_rate', label: k.toUpperCase() + ' (%)', type: 'number', step: '0.01', min: 0, max: 30, placeholder: 'automático' })).join('')}
        ${f({ name: 'withhold_federal_pj', label: 'Reter PIS/COFINS/CSLL/IRRF de tomadores PJ por padrão', type: 'checkbox', span: 2 })}
      </div></details></section>
      <section class="card"><div class="card-head"><h3>${icon('send')} Canal de emissão</h3></div><div class="card-body">
        <div class="fh-choice">
          <label class="fh-opt"><input type="radio" name="provider" value="sigiss" ${v.provider !== 'nacional' ? 'checked' : ''}><div><b>Prefeitura de Marília (SIGISS)</b><small>Para empresas com inscrição em Marília-SP. Usa a senha do portal da prefeitura — não precisa de certificado digital.</small></div></label>
          <label class="fh-opt"><input type="radio" name="provider" value="nacional" ${v.provider === 'nacional' ? 'checked' : ''}><div><b>Emissor Nacional (NFS-e padrão nacional)</b><small>Para municípios conveniados ao Sistema Nacional. Exige certificado digital A1 (e-CNPJ ou e-CPF).</small></div></label>
        </div>
        <div class="form-grid cols-4" data-prov="sigiss" style="margin-top:14px">
          <div class="field span-2"><label>Senha do SIGISS</label><input type="password" name="sigiss_password" autocomplete="new-password" placeholder="${v.has_sigiss_password ? '•••••••• (mantida)' : 'senha do portal marilia.sigiss.com.br'}"></div>
          ${f({ name: 'sigiss_crc', label: 'CRC do contador (opcional)' })}
          ${f({ name: 'sigiss_crc_uf', label: 'UF do CRC' })}
        </div>
        <div class="form-grid cols-4" data-prov="nacional" style="margin-top:14px">
          ${f({ name: 'environment', label: 'Ambiente', type: 'select', empty: false, span: 2, options: [{ value: 'homologation', label: 'Produção restrita (testes, sem valor fiscal)' }, { value: 'production', label: 'Produção (notas com valor fiscal)' }] })}
        </div>
        <div class="form-grid cols-4" style="margin-top:14px">
          ${f({ name: 'dps_serie', label: 'Série da DPS/RPS' })}
          ${f({ name: 'next_number', label: 'Próximo número', type: 'number', min: 1, help: 'Continue a numeração que você já usava.' })}
        </div>
      </div></section>
      ${isNew ? '' : certBlock(v)}
      <section class="card"><div class="card-head"><h3>${icon('mail')} Envio ao cliente</h3></div><div class="card-body form-grid">
        ${f({ name: 'auto_email', label: 'Enviar a nota (PDF + XML) automaticamente ao e-mail do cliente ao emitir', type: 'checkbox', span: 2 })}
        ${f({ name: 'show_taxes', label: 'Incluir "valor aproximado dos tributos" na discriminação (Lei 12.741/2012)', type: 'checkbox', span: 2 })}
        ${f({ name: 'email_message', label: 'Mensagem do e-mail (opcional)', type: 'textarea', rows: 3, span: 2 })}
      </div></section>
      <div class="fh-form-foot"><a class="btn" href="#/empresa">Cancelar</a><button class="btn btn-primary" data-save>${icon('check')} ${isNew ? 'Cadastrar empresa' : 'Salvar alterações'}</button></div>
    </form>`;
  const form = $('[data-form]', el);
  const syncProv = () => { const p = form.elements.provider.value; $$('[data-prov]', form).forEach((b) => b.classList.toggle('hidden', b.dataset.prov !== p)); };
  $$('input[name=provider]', form).forEach((r) => r.addEventListener('change', syncProv));
  syncProv();
  const fill = (d) => Object.entries(d).forEach(([k, val]) => { if (form.elements[k] && val && !form.elements[k].readOnly && (!form.elements[k].value || ['street', 'district', 'city', 'uf', 'city_ibge'].includes(k))) form.elements[k].value = val; });
  $('[data-cnpj]', form)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const doc = form.elements.document.value.replace(/\D/g, '');
    if (doc.length !== 14) { toast('Digite o CNPJ completo (14 dígitos).', 'error'); return; }
    btn.classList.add('loading');
    try {
      const r = await api('/fh/cnpj/' + doc);
      fill(r);
      if (r.mei) form.elements.op_simp_nac.value = '2'; else if (r.simples) form.elements.op_simp_nac.value = '3'; else if (r.simples === false) form.elements.op_simp_nac.value = '1';
      toast('Dados do CNPJ preenchidos. Confira antes de salvar.');
      // The channel is the customer's choice: only warn when the SIGISS of Marília does not fit the address.
      if (r.city_ibge && r.city_ibge !== '3529005' && $('input[name=provider]:checked', form)?.value === 'sigiss') toast(`A empresa fica em ${r.city || 'outro município'}: o SIGISS atende só Marília. Se for o caso, selecione o Emissor Nacional.`, 'error');
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-cep]', form).addEventListener('click', async () => { try { fill(await api('/fh/cep/' + form.elements.cep.value.replace(/\D/g, ''))); } catch (err) { toastError(err); } });
  form.elements.cep.addEventListener('change', async () => { if (form.elements.cep.value.replace(/\D/g, '').length === 8) { try { fill(await api('/fh/cep/' + form.elements.cep.value.replace(/\D/g, ''))); } catch (err) { /* ignore */ } } });
  bindCert(el, v);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('[data-save]', form);
    const d = readForm(form);
    if (!d.sigiss_password) delete d.sigiss_password;
    delete d.pfx; delete d.pfx_password;
    btn.classList.add('loading');
    try {
      const saved = await api(isNew ? '/fh/emitters' : '/fh/emitters/' + v.id, { method: isNew ? 'POST' : 'PUT', body: d });
      fh.setEmitter(saved.id);
      toast(isNew ? 'Empresa cadastrada!' : 'Alterações salvas.');
      await fh.refresh();
      window.dispatchEvent(new Event('fh:reload'));
      location.hash = saved.provider === 'nacional' && !saved.has_certificate ? '#/empresa/' + saved.id : (saved.ready ? '#/servicos' : '#/empresa');
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length) showFieldErrors(form, err.fields); else toastError(err);
    } finally { btn.classList.remove('loading'); }
  });
}

function certBlock(v) {
  return `<section class="card" data-cert><div class="card-head"><div><h3>${icon('lock')} Certificado digital A1</h3><small class="muted">Obrigatório para o Emissor Nacional, NF-e e NFC-e · opcional no SIGISS de Marília</small></div>${v.has_certificate ? (v.cert_expired ? '<span class="badge red">Vencido</span>' : '<span class="badge green">Válido</span>') : '<span class="badge yellow">Não enviado</span>'}</div><div class="card-body">
    ${v.has_certificate ? `<p class="small" style="margin:0 0 12px">Atual: <b>${esc(v.cert_subject || '')}</b> · válido até <b>${date(v.cert_valid_to)}</b></p>` : '<p class="small muted" style="margin:0 0 12px">Envie o arquivo .pfx (ou .p12) do e-CNPJ/e-CPF A1 da empresa e a senha. O arquivo fica criptografado (AES-256).</p>'}
    <div class="form-grid cols-4">
      <div class="field span-2"><label>Arquivo do certificado (.pfx/.p12)</label><input type="file" name="pfx" accept=".pfx,.p12,application/x-pkcs12"></div>
      <div class="field"><label>Senha do certificado</label><input type="password" name="pfx_password" autocomplete="new-password"></div>
      <div class="field" style="align-self:end;display:flex;gap:8px"><button type="button" class="btn btn-primary" data-cert-send>${icon('upload')} Enviar</button>${v.has_certificate ? `<button type="button" class="btn btn-danger btn-icon" data-cert-del title="Remover">${icon('trash')}</button>` : ''}</div>
    </div></div></section>`;
}

function bindCert(el, v) {
  $('[data-cert-send]', el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; // e.currentTarget is null after the first await
    const file = $('[name=pfx]', el).files[0];
    if (!file) { toast('Escolha o arquivo do certificado.', 'error'); return; }
    if (!/\.(pfx|p12)$/i.test(file.name)) { toast('Envie o arquivo .pfx ou .p12 do certificado A1 (o .cer/.crt não tem a chave privada).', 'error'); return; }
    if (file.size > 150000) { toast('Arquivo grande demais para um certificado A1. Confira se escolheu o .pfx correto.', 'error'); return; }
    btn.classList.add('loading');
    try {
      const prov = $('[data-form]', el)?.elements.provider?.value;
      if (prov && prov !== v.provider) await api('/fh/emitters/' + v.id, { method: 'PUT', body: { provider: prov } }); // keep the channel the user just picked
      const buf = new Uint8Array(await file.arrayBuffer());
      let bin = '';
      for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
      const r = await api(`/fh/emitters/${v.id}/certificate`, { method: 'POST', body: { pfx_b64: btoa(bin), password: $('[name=pfx_password]', el).value } });
      toast('Certificado salvo: válido até ' + date(r.info.valid_to));
      if (r.warning) toast(r.warning, 'error');
      window.dispatchEvent(new Event('fh:reload'));
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-cert-del]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Remover o certificado? A emissão pelo Emissor Nacional para até enviar outro.', { danger: true, okLabel: 'Remover' })) return;
    await api(`/fh/emitters/${v.id}/certificate`, { method: 'DELETE' }).catch(toastError);
    window.dispatchEvent(new Event('fh:reload'));
  });
}
