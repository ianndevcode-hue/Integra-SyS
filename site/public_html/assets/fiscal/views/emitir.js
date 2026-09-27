/* Emit / edit an NFS-e: taker, service, values, ISS situation, federal withholdings and every special group. */
import { api, $, $$, esc, icon, money, today, toast, toastError, modal, debounce, downloadUrl, emptyState } from '/admin/js/core.js';
import { fh, SITUATIONS, CURRENCIES, COUNTRIES, provName, fmtDoc, num } from '/assets/fiscal/state.js';

const opt = (list, sel) => list.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(sel ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('');
const MEC_P = [['01', 'Nenhum'], ['02', 'ACC — Adiantamento sobre Contrato de Câmbio'], ['03', 'ACE — Adiantamento sobre Cambiais Entregues'], ['04', 'BNDES-Exim Pós-Embarque'], ['05', 'BNDES-Exim Pré-Embarque'], ['06', 'FGE — Fundo de Garantia à Exportação'], ['07', 'PROEX — Equalização'], ['08', 'PROEX — Financiamento']];
const MODOS = [['1', 'Transfronteiriço (serviço prestado do Brasil ao exterior)'], ['2', 'Consumo no Brasil (tomador estrangeiro no Brasil)'], ['3', 'Presença comercial no exterior'], ['4', 'Movimento temporário de pessoas físicas']];
const VINC = [['0', 'Sem vínculo'], ['1', 'Controlada'], ['2', 'Controladora'], ['3', 'Coligada'], ['4', 'Matriz'], ['5', 'Filial ou sucursal'], ['6', 'Outro vínculo']];

export async function render(el, ctx) {
  const em = fh.emitter();
  if (!em) { el.innerHTML = emptyState('Cadastre sua empresa para emitir notas.', 'settings', '<a class="btn btn-primary" href="#/empresa/nova">Cadastrar empresa</a>'); return; }
  let inv = null;
  if (ctx.id) {
    inv = await api('/fh/invoices/' + ctx.id);
    if (!['draft', 'rejected'].includes(inv.status)) { location.hash = '#/notas/' + inv.id; return; }
    if (inv.emitter_id !== em.id) fh.setEmitter(inv.emitter_id);
  }
  const [takers, services, lc] = await Promise.all([fh.takers(), fh.services(), fh.lc116()]);
  const E = fh.emitter();
  const nac = E.provider === 'nacional';
  const x = inv?.extra || {};
  const val = (k, d = '') => (inv && inv[k] !== undefined && inv[k] !== null ? inv[k] : d);
  let taker = inv ? (inv.taker_id ? takers.find((t) => t.id === inv.taker_id) || { ...inv.taker, id: inv.taker_id } : { ...inv.taker, id: null }) : null;
  if (!inv && ctx.query.taker) taker = takers.find((t) => t.id === +ctx.query.taker) || null;
  const firstService = ctx.query.service ? services.find((s) => s.id === +ctx.query.service) : (E.default_service_id ? services.find((s) => s.id === E.default_service_id) : null);
  const a = fh.access;
  el.innerHTML = `
    <div class="page-head"><div><h2>${inv ? 'Corrigir e emitir' : 'Emitir nota fiscal de serviço'}</h2><p>${esc(E.trade_name || E.legal_name)} · ${esc(provName(E.provider))}${nac && E.environment !== 'production' ? ' · <b class="neg">produção restrita (testes, sem valor fiscal)</b>' : ''}</p></div></div>
    ${!E.ready ? `<div class="alert alert-warning">Antes de emitir, complete a empresa: ${E.problems.map(esc).join(' ')} <a href="#/empresa/${E.id}">Configurar</a></div>` : ''}
    ${inv?.status === 'rejected' && inv.error_message ? `<div class="alert alert-danger"><b>Esta nota foi rejeitada:</b><br>${esc(inv.error_message).replace(/\n/g, '<br>')}</div>` : ''}
    <form class="fh-emit" data-form novalidate autocomplete="off">
      <div class="fh-emit-main">
        <section class="card"><div class="card-head"><h3><span class="fh-step">1</span> Cliente (tomador)</h3><button type="button" class="btn btn-sm" data-new-taker>${icon('plus')} Novo cliente</button></div><div class="card-body">
          <div class="fh-combo" data-combo><input type="search" data-taker-q placeholder="Buscar cliente por nome ou CPF/CNPJ..." aria-label="Buscar cliente"><div class="fh-combo-list hidden" data-taker-list></div></div>
          <div data-taker-view></div>
          <div class="fh-newtaker hidden" data-newtaker>
            <div class="seg" data-kind>${[['pj', 'Pessoa jurídica'], ['pf', 'Pessoa física'], ['ext', 'Exterior'], ['pfni', 'Não identificado']].map(([k, l]) => `<button type="button" data-k="${k}">${l}</button>`).join('')}</div>
            <div class="form-grid cols-4" style="margin-top:12px">
              <div class="field" data-for="doc"><label data-doc-label>CNPJ</label><div class="fh-inline"><input name="t_document" inputmode="numeric"><button type="button" class="btn btn-sm" data-t-cnpj title="Buscar dados do CNPJ">${icon('search')}</button></div></div>
              <div class="field span-2"><label>Nome / razão social *</label><input name="t_name"></div>
              <div class="field"><label>E-mail</label><input name="t_email" type="email"></div>
              <div class="field" data-for="br"><label>CEP</label><div class="fh-inline"><input name="t_cep" inputmode="numeric"><button type="button" class="btn btn-sm" data-t-cep>${icon('search')}</button></div></div>
              <div class="field span-2" data-for="addr"><label>Logradouro</label><input name="t_street"></div>
              <div class="field" data-for="addr"><label>Número</label><input name="t_number"></div>
              <div class="field" data-for="addr"><label>Complemento</label><input name="t_complement"></div>
              <div class="field" data-for="br"><label>Bairro</label><input name="t_district"></div>
              <div class="field" data-for="br"><label>Cidade / UF</label><input name="t_city" readonly placeholder="pelo CEP"></div>
              <div class="field" data-for="br"><label>Inscrição municipal</label><input name="t_im"></div>
              <div class="field" data-for="ext"><label>País</label><select name="t_country">${opt(COUNTRIES, 'US')}</select></div>
              <div class="field" data-for="ext"><label>Cidade (exterior)</label><input name="t_foreign_city"></div>
              <div class="field" data-for="ext"><label>Estado/província</label><input name="t_foreign_region"></div>
              <div class="field" data-for="ext"><label>Código postal</label><input name="t_foreign_postal"></div>
              <div class="field" data-for="ext"><label>NIF (identificação fiscal)</label><input name="t_nif"></div>
              <div class="field" data-for="br"><label>Telefone</label><input name="t_phone"></div>
              <label class="check span-2" data-for="save"><input type="checkbox" name="save_taker" checked> Salvar no cadastro de clientes</label>
              <input type="hidden" name="t_uf"><input type="hidden" name="t_city_ibge">
            </div>
          </div>
        </div></section>

        <section class="card"><div class="card-head"><h3><span class="fh-step">2</span> Serviço</h3><a class="btn btn-sm btn-ghost" href="#/servicos">${icon('tag')} Meus serviços</a></div><div class="card-body form-grid cols-4">
          <div class="field span-2"><label>Serviço cadastrado</label><select name="service_id"><option value="">— Informar manualmente —</option>${services.filter((s) => Number(s.active)).map((s) => `<option value="${s.id}" ${(inv ? inv.service_id === s.id : firstService?.id === s.id) ? 'selected' : ''}>${esc(s.name)}${s.lc116 ? ' · ' + esc(s.lc116) : ''}</option>`).join('')}</select></div>
          <div class="field span-2"><label>Item da lista de serviços (LC 116/2003)</label><input name="lc116" list="fh-lc116" value="${esc(val('lc116'))}" placeholder="Digite o código ou o nome, ex.: 17.01 ou consultoria"><datalist id="fh-lc116">${lc.map((i) => `<option value="${i.code} — ${esc(i.name)}">`).join('')}</datalist></div>
          ${nac ? `<div class="field"><label>Código de tributação nacional</label><input name="ctribnac" value="${esc(val('ctribnac'))}" maxlength="6" inputmode="numeric" placeholder="ex.: 170101"></div>`
            : `<div class="field"><label>Código do serviço no SIGISS</label><input name="sigiss_code" value="${esc(val('sigiss_code'))}" inputmode="numeric" placeholder="ex.: 1701"></div>`}
          <div class="field"><label>Código municipal ${nac ? '(cTribMun)' : ''}</label><input name="ctribmun" value="${esc(val('ctribmun'))}" maxlength="3" inputmode="numeric" placeholder="opcional"></div>
          <div class="field"><label>Código NBS</label><input name="cnbs" value="${esc(val('cnbs'))}" maxlength="9" inputmode="numeric" placeholder="opcional, 9 dígitos"></div>
          <div class="field"><label>Competência</label><input type="date" name="competence_date" value="${esc(val('competence_date', today()))}" max="${today()}"></div>
          <div class="field span-2" style="grid-column:1/-1"><label>Discriminação do serviço *</label><textarea name="description" rows="4" placeholder="Descreva o serviço prestado, período, contrato, etc.">${esc(x.desc_raw || '')}</textarea><span class="help">A linha de tributos aproximados (Lei 12.741) é adicionada automaticamente.</span></div>
        </div></section>

        <section class="card"><div class="card-head"><h3><span class="fh-step">3</span> Valores e ISS</h3></div><div class="card-body form-grid cols-4">
          <div class="field"><label>Valor do serviço (R$) *</label><input name="amount" type="number" step="0.01" min="0" inputmode="decimal" value="${esc(val('amount', ''))}" class="fh-big"></div>
          <div class="field span-2"><label>Situação do ISS</label><select name="situation">${opt(SITUATIONS.filter(([k]) => nac || !['ti', 'es'].includes(k)), val('sigiss_situacao', 'tp'))}</select></div>
          <div class="field"><label>Alíquota do ISS (%)</label><input name="iss_rate" type="number" step="0.01" min="0" max="5" value="${esc(val('iss_rate', ''))}" placeholder="${esc(E.iss_rate)}"></div>
          <div class="field"><label>Desconto incondicionado (R$)</label><input name="discount_incond" type="number" step="0.01" min="0" value="${esc(val('discount_incond', ''))}"></div>
          <div class="field"><label>Desconto condicionado (R$)</label><input name="discount_cond" type="number" step="0.01" min="0" value="${esc(val('discount_cond', ''))}"></div>
          <div class="field"><label>Deduções da base (R$)</label><input name="deductions" type="number" step="0.01" min="0" value="${esc(val('deductions', ''))}"></div>
          <div class="field"><label>Tipo de dedução</label><select name="x_ded_tipo"><option value="">—</option>${opt(Object.entries(fh.me.ded_types), x.ded_tipo)}</select></div>
          <div class="field" data-sit="im"><label>Tipo de imunidade</label><select name="x_imunidade">${opt(Object.entries(fh.me.imunidades), x.imunidade || '0')}</select></div>
          <div class="field" data-sit="es"><label>Exigibilidade suspensa por</label><select name="x_exig_tipo"><option value="1" ${x.exig?.tipo === '1' ? 'selected' : ''}>Decisão judicial</option><option value="2" ${x.exig?.tipo === '2' ? 'selected' : ''}>Processo administrativo</option></select></div>
          <div class="field span-2" data-sit="es"><label>Número do processo</label><input name="x_exig_processo" value="${esc((x.exig?.processo || '').replace(/^0+/, ''))}" inputmode="numeric"></div>
          <div class="field span-2" data-sit="is"><label>Número do benefício municipal (14 dígitos)</label><input name="x_bm_numero" value="${esc(x.bm?.numero || '')}" inputmode="numeric" placeholder="${nac ? 'obrigatório no Emissor Nacional' : 'opcional'}"></div>
          <div class="field" data-sit="is"><label>Redução da base (%)</label><input name="x_bm_percentual" type="number" step="0.01" value="${esc(x.bm?.percentual || '')}"></div>
          <div class="field" data-sit="ex"><label>País do resultado do serviço</label><select name="x_pais_resultado"><option value="">— igual ao do cliente —</option>${opt(COUNTRIES, x.pais_resultado)}</select></div>
        </div></section>

        <details class="card fh-details" ${inv && ['pis', 'cofins', 'csll', 'irrf', 'inss'].some((k) => Number(inv[k + '_withheld'])) ? 'open' : ''}><summary><span class="fh-step">4</span> Tributos federais e retenções <small class="muted">PIS, COFINS, CSLL, IRRF, INSS</small></summary><div class="card-body">
          ${['2', '3'].includes(E.op_simp_nac) ? '<p class="small muted" style="margin:0 0 10px">Empresa do Simples Nacional: os tributos federais são recolhidos no DAS. Preencha apenas se o cliente for reter algum valor.</p>' : ''}
          <div class="fh-taxgrid">${['pis', 'cofins', 'csll', 'irrf', 'inss'].map((k) => `<div class="fh-taxrow"><b>${k.toUpperCase()}</b><input name="${k}_rate" type="number" step="0.01" min="0" max="30" value="${esc(inv ? inv[k + '_rate'] : '')}" placeholder="padrão" aria-label="Alíquota ${k}"><label class="check"><input type="checkbox" name="${k}_withheld" ${inv && Number(inv[k + '_withheld']) ? 'checked' : ''}> retido</label></div>`).join('')}
            <div class="fh-taxrow"><b>CST</b><select name="pis_cofins_cst"><option value="">Padrão da empresa</option>${opt([['01', '01 — Tributável'], ['49', '49 — Outras saídas'], ['06', '06 — Alíquota zero'], ['07', '07 — Isenta'], ['08', '08 — Sem incidência'], ['09', '09 — Suspensão'], ['99', '99 — Outras']], inv?.pis_cofins_cst)}</select></div>
          </div>
        </div></details>

        <details class="card fh-details" ${x.loc || x.interm || x.obra || x.evento || x.comext || x.info || x.retro ? 'open' : ''}><summary><span class="fh-step">5</span> Situações especiais <small class="muted">local, obra, evento, exportação, intermediário, informações</small></summary><div class="card-body fh-specials">
          <fieldset><legend>Local da prestação</legend><div class="form-grid cols-4">
            <div class="field span-2"><label>Onde o serviço foi prestado</label><select name="x_loc_type">${opt([['', `No município do emissor (${E.city || 'Marília'})`], ['outro', 'Em outro município'], ['exterior', 'No exterior']], x.loc?.type || '')}</select></div>
            <div class="field" data-loc="outro"><label>CEP do local</label><div class="fh-inline"><input name="x_loc_cep" inputmode="numeric"><button type="button" class="btn btn-sm" data-loc-cep>${icon('search')}</button></div></div>
            <div class="field" data-loc="outro"><label>Código IBGE do município</label><input name="x_loc_city_ibge" value="${esc(x.loc?.city_ibge || '')}" inputmode="numeric"></div>
            <div class="field" data-loc="exterior"><label>País</label><select name="x_loc_country">${opt(COUNTRIES, x.loc?.country || 'US')}</select></div>
          </div></fieldset>
          ${nac ? `<fieldset><legend>Intermediário do serviço</legend><div class="form-grid cols-4">
            <div class="field"><label>CPF/CNPJ</label><input name="x_interm_document" value="${esc(x.interm?.document || '')}" inputmode="numeric"></div>
            <div class="field span-2"><label>Nome / razão social</label><input name="x_interm_name" value="${esc(x.interm?.name || '')}"></div>
            <div class="field"><label>E-mail</label><input name="x_interm_email" value="${esc(x.interm?.email || '')}"></div>
          </div></fieldset>` : ''}
          <fieldset><legend>Construção civil (obra)</legend><div class="form-grid cols-4">
            <div class="field"><label>Código da obra (CNO/CEI)</label><input name="x_obra_codigo" value="${esc(x.obra?.codigo || '')}"></div>
            <div class="field"><label>Código CIB</label><input name="x_obra_cib" value="${esc(x.obra?.cib || '')}" maxlength="8"></div>
            <div class="field"><label>Inscrição imobiliária</label><input name="x_obra_inscricao" value="${esc(x.obra?.inscricao || '')}"></div>
            <div class="field"><label>CEP da obra</label><input name="x_obra_cep" value="${esc(x.obra?.cep || '')}" inputmode="numeric"></div>
            <div class="field span-2"><label>Logradouro da obra</label><input name="x_obra_street" value="${esc(x.obra?.street || '')}"></div>
            <div class="field"><label>Número</label><input name="x_obra_number" value="${esc(x.obra?.number || '')}"></div>
            <div class="field"><label>Bairro</label><input name="x_obra_district" value="${esc(x.obra?.district || '')}"></div>
          </div><p class="help">Informe o código da obra, o CIB ou o endereço completo.</p></fieldset>
          <fieldset><legend>Evento (shows, congressos, feiras)</legend><div class="form-grid cols-4">
            <div class="field span-2"><label>Nome do evento</label><input name="x_evento_nome" value="${esc(x.evento?.nome || '')}"></div>
            <div class="field"><label>Início</label><input type="date" name="x_evento_inicio" value="${esc(x.evento?.inicio || '')}"></div>
            <div class="field"><label>Fim</label><input type="date" name="x_evento_fim" value="${esc(x.evento?.fim || '')}"></div>
            <div class="field"><label>Código do evento (prefeitura)</label><input name="x_evento_id" value="${esc(x.evento?.id || '')}"></div>
            <div class="field"><label>CEP do local</label><input name="x_evento_cep" value="${esc(x.evento?.cep || '')}" inputmode="numeric"></div>
            <div class="field"><label>Logradouro</label><input name="x_evento_street" value="${esc(x.evento?.street || '')}"></div>
            <div class="field"><label>Número / bairro</label><div class="fh-inline"><input name="x_evento_number" value="${esc(x.evento?.number || '')}" placeholder="nº"><input name="x_evento_district" value="${esc(x.evento?.district || '')}" placeholder="bairro"></div></div>
          </div></fieldset>
          <fieldset><legend>Comércio exterior (exportação/importação de serviço)</legend><div class="form-grid cols-4">
            <div class="field span-2"><label>Modo de prestação</label><select name="x_comext_modo"><option value="">— não se aplica —</option>${opt(MODOS, x.comext?.modo)}</select></div>
            <div class="field"><label>Vínculo entre as partes</label><select name="x_comext_vinculo">${opt(VINC, x.comext?.vinculo || '0')}</select></div>
            <div class="field"><label>Moeda</label><select name="x_comext_moeda">${opt(CURRENCIES, x.comext?.moeda || '220')}</select></div>
            <div class="field"><label>Valor na moeda estrangeira</label><input name="x_comext_valor_moeda" type="number" step="0.01" value="${esc(x.comext?.valor_moeda || '')}"></div>
            <div class="field"><label>Apoio ao comércio exterior (prestador)</label><select name="x_comext_mec_prest">${opt(MEC_P, x.comext?.mec_prest || '01')}</select></div>
            <div class="field"><label>Mecanismo do tomador (código)</label><input name="x_comext_mec_toma" value="${esc(x.comext?.mec_toma || '01')}" maxlength="2"></div>
            <div class="field"><label>Movimentação temporária de bens</label><select name="x_comext_mov_temp">${opt([['1', 'Não'], ['2', 'Vinculada a declaração de importação'], ['3', 'Vinculada a declaração de exportação']], x.comext?.mov_temp || '1')}</select></div>
            <div class="field"><label>Nº DI / RE</label><div class="fh-inline"><input name="x_comext_di" value="${esc(x.comext?.di || '')}" placeholder="DI"><input name="x_comext_re" value="${esc(x.comext?.re || '')}" placeholder="RE"></div></div>
            <label class="check span-2"><input type="checkbox" name="x_comext_mdic" ${x.comext?.mdic === '1' ? 'checked' : ''}> Compartilhar com a Secretaria de Comércio Exterior (MDIC)</label>
          </div></fieldset>
          <fieldset><legend>Informações complementares</legend><div class="form-grid cols-4">
            <div class="field span-2" style="grid-column:1/-1"><label>Texto complementar</label><textarea name="x_info_complementar" rows="2">${esc(x.info?.complementar || '')}</textarea></div>
            <div class="field"><label>Nº do pedido / OC</label><input name="x_info_pedido" value="${esc(x.info?.pedido || '')}"></div>
            <div class="field"><label>Documento de referência</label><input name="x_info_doc_ref" value="${esc(x.info?.doc_ref || '')}"></div>
            <div class="field"><label>Código interno do serviço</label><input name="x_codigo_interno" value="${esc(x.codigo_interno || '')}" maxlength="20"></div>
            ${nac ? `<div class="field"><label>Valor recebido pelo intermediário</label><input name="x_v_receb" type="number" step="0.01" value="${esc(x.v_receb || '')}"></div>` : `<label class="check"><input type="checkbox" name="x_retro" ${x.retro ? 'checked' : ''}> Nota retroativa (competência anterior)</label>`}
          </div></fieldset>
        </div></details>
      </div>

      <aside class="fh-emit-side">
        <section class="card fh-summary"><div class="card-head"><h3>Resumo da nota</h3><span class="badge ${nac && E.environment !== 'production' ? 'yellow' : 'blue'}">${nac ? (E.environment === 'production' ? 'Nacional' : 'Nacional · testes') : 'SIGISS'}</span></div>
          <div class="card-body" data-summary><p class="muted small" style="margin:0">Informe o valor para ver o cálculo.</p></div>
          <div class="fh-summary-foot">
            <button type="submit" class="btn btn-primary btn-block fh-emit-btn" data-emit ${a.can_emit ? '' : 'disabled'}>${icon('send')} Emitir nota fiscal</button>
            <div class="fh-summary-actions"><button type="button" class="btn btn-sm" data-draft>${icon('check')} Salvar rascunho</button><button type="button" class="btn btn-sm" data-preview>${icon('eye')} Prévia PDF</button></div>
            <p class="small muted" style="margin:6px 0 0">${a.usage ? `${a.usage.notes_used} de ${a.usage.notes_limit} notas usadas neste mês.` : ''}${a.can_emit ? '' : ' <b class="neg">Emissão bloqueada: ' + esc(a.message || 'verifique a assinatura') + '</b>'}</p>
          </div>
        </section>
      </aside>
    </form>`;

  const form = $('[data-form]', el);
  const F = form.elements;
  let kind = 'pj';

  /* ----- taker ----- */
  const takerView = $('[data-taker-view]', el);
  const showTaker = () => {
    $('[data-combo]', el).classList.toggle('hidden', !!taker);
    takerView.innerHTML = taker ? `<div class="fh-taker-card"><div><b>${esc(taker.name)}</b><small>${taker.kind === 'pfni' ? 'Consumidor não identificado' : taker.kind === 'ext' ? `Exterior · ${esc(taker.country || '')} ${taker.nif ? '· NIF ' + esc(taker.nif) : ''}` : esc(fmtDoc(taker.document))}${taker.city ? ' · ' + esc(taker.city) + '/' + esc(taker.uf || '') : ''}${taker.email ? ' · ' + esc(taker.email) : ' · <span class="neg">sem e-mail</span>'}</small>
      ${!nac && ['pj', 'pf'].includes(taker.kind) && !(taker.street && taker.number && taker.city_ibge) ? '<small class="neg">Endereço incompleto: o SIGISS exige endereço completo. <a href="#/clientes">Completar cadastro</a></small>' : ''}</div><button type="button" class="btn btn-sm btn-ghost" data-change-taker>Trocar</button></div>` : '';
    $('[data-change-taker]', el)?.addEventListener('click', () => { taker = null; showTaker(); $('[data-taker-q]', el).focus(); refresh(); });
  };
  const list = $('[data-taker-list]', el);
  const q = $('[data-taker-q]', el);
  const drawList = () => {
    const term = q.value.toLowerCase().trim();
    const digits = term.replace(/\D/g, '');
    const items = takers.filter((t) => !term || t.name.toLowerCase().includes(term) || (digits && String(t.document || '').includes(digits))).slice(0, 12);
    list.innerHTML = items.length ? items.map((t) => `<button type="button" data-tid="${t.id}"><b>${esc(t.name)}</b><small>${esc(fmtDoc(t.document || t.nif || ''))}${t.city ? ' · ' + esc(t.city) : ''}</small></button>`).join('') : `<div class="fh-combo-empty">Nenhum cliente encontrado. <button type="button" class="btn btn-xs" data-open-new>Cadastrar novo</button></div>`;
    list.classList.remove('hidden');
  };
  q.addEventListener('focus', drawList);
  q.addEventListener('input', drawList);
  const outside = (e) => { if (!e.target.closest('[data-combo]')) list.classList.add('hidden'); };
  document.addEventListener('click', outside);
  list.addEventListener('click', (e) => {
    const b = e.target.closest('[data-tid]');
    if (b) { taker = takers.find((t) => t.id === +b.dataset.tid); list.classList.add('hidden'); $('[data-newtaker]', el).classList.add('hidden'); showTaker(); refresh(); }
    if (e.target.closest('[data-open-new]')) openNew();
  });
  const setKind = (k) => {
    kind = k;
    $$('[data-kind] button', el).forEach((b) => b.classList.toggle('active', b.dataset.k === k));
    $('[data-doc-label]', el).textContent = k === 'pf' ? 'CPF' : 'CNPJ';
    $$('[data-for]', el).forEach((f) => {
      const t = f.dataset.for;
      const show = t === 'doc' ? ['pj', 'pf'].includes(k) : t === 'br' ? ['pj', 'pf'].includes(k) : t === 'addr' ? k !== 'pfni' : t === 'ext' ? k === 'ext' : t === 'save' ? k !== 'pfni' : true;
      f.classList.toggle('hidden', !show);
    });
    $('[data-t-cnpj]', el).classList.toggle('hidden', k !== 'pj');
  };
  const openNew = () => { taker = null; showTaker(); list.classList.add('hidden'); $('[data-combo]', el).classList.add('hidden'); $('[data-newtaker]', el).classList.remove('hidden'); setKind('pj'); F.t_document.focus(); };
  $('[data-new-taker]', el).addEventListener('click', openNew);
  $$('[data-kind] button', el).forEach((b) => b.addEventListener('click', () => { setKind(b.dataset.k); refresh(); }));
  const cepFill = async (cep) => {
    const r = await api('/fh/cep/' + String(cep).replace(/\D/g, ''));
    F.t_street.value = F.t_street.value || r.street; F.t_district.value = F.t_district.value || r.district; F.t_city.value = r.city + '/' + r.uf; F.t_uf.value = r.uf; F.t_city_ibge.value = r.city_ibge;
  };
  $('[data-t-cep]', el).addEventListener('click', () => cepFill(F.t_cep.value).catch(toastError));
  F.t_cep.addEventListener('change', () => { if (F.t_cep.value.replace(/\D/g, '').length === 8) cepFill(F.t_cep.value).catch(() => {}); });
  $('[data-t-cnpj]', el).addEventListener('click', async (e) => {
    e.currentTarget.classList.add('loading');
    try {
      const r = await api('/fh/cnpj/' + F.t_document.value.replace(/\D/g, ''));
      F.t_name.value = r.legal_name; F.t_email.value = F.t_email.value || r.email; F.t_cep.value = r.cep; F.t_street.value = r.street; F.t_number.value = r.number; F.t_complement.value = r.complement;
      F.t_district.value = r.district; F.t_city.value = r.city + '/' + r.uf; F.t_uf.value = r.uf; F.t_city_ibge.value = r.city_ibge; F.t_phone.value = r.phone;
    } catch (err) { toastError(err); } finally { e.currentTarget?.classList.remove('loading'); }
  });
  if (inv && !inv.taker_id && inv.taker) {
    openNew();
    setKind(inv.taker.kind || 'pj');
    Object.entries(inv.taker).forEach(([k, v]) => { if (F['t_' + k] && v) F['t_' + k].value = v; });
  }
  showTaker();

  /* ----- service ----- */
  const applyService = () => {
    const s = services.find((x) => x.id === +F.service_id.value);
    if (!s) return;
    if (s.lc116) { const it = lc.find((i) => i.code === s.lc116); F.lc116.value = it ? `${it.code} — ${it.name}` : s.lc116; }
    if (F.ctribnac) F.ctribnac.value = s.ctribnac || '';
    if (F.sigiss_code) F.sigiss_code.value = s.sigiss_code || '';
    F.ctribmun.value = s.ctribmun || ''; F.cnbs.value = s.cnbs || '';
    if (s.iss_rate !== null && s.iss_rate !== '') F.iss_rate.value = s.iss_rate;
    if (s.sigiss_situacao) F.situation.value = s.sigiss_situacao;
    if (!F.description.value.trim()) F.description.value = s.description || s.name;
    if (!F.amount.value && Number(s.price)) F.amount.value = s.price;
    ['pis', 'cofins', 'csll', 'irrf', 'inss'].forEach((k) => { if (s[k + '_rate'] !== null && s[k + '_rate'] !== undefined && s[k + '_rate'] !== '') F[k + '_rate'].value = s[k + '_rate']; });
    syncSituation(); refresh();
  };
  F.service_id.addEventListener('change', applyService);
  F.lc116.addEventListener('change', () => {
    const code = F.lc116.value.split('—')[0].trim();
    const it = lc.find((i) => i.code === code || i.code.replace(/^0/, '') === code);
    if (it) { F.lc116.value = `${it.code} — ${it.name}`; if (F.ctribnac && !F.ctribnac.value) F.ctribnac.value = it.ctribnac; if (F.sigiss_code && !F.sigiss_code.value) F.sigiss_code.value = it.sigiss; }
  });
  if (!inv && firstService) applyService();
  if (inv?.lc116) { const it = lc.find((i) => i.code === inv.lc116); if (it) F.lc116.value = `${it.code} — ${it.name}`; }

  /* ----- situation & specials ----- */
  const syncSituation = () => {
    const s = F.situation.value;
    $$('[data-sit]', el).forEach((f) => f.classList.toggle('hidden', f.dataset.sit !== s));
    F.iss_rate.disabled = ['im', 'nt', 'ex', 'is'].includes(s) || E.op_simp_nac === '2';
    const lt = F.x_loc_type.value;
    $$('[data-loc]', el).forEach((f) => f.classList.toggle('hidden', f.dataset.loc !== lt));
  };
  F.situation.addEventListener('change', syncSituation);
  F.x_loc_type.addEventListener('change', syncSituation);
  $('[data-loc-cep]', el)?.addEventListener('click', async () => { try { F.x_loc_city_ibge.value = (await api('/fh/cep/' + F.x_loc_cep.value.replace(/\D/g, ''))).city_ibge; } catch (err) { toastError(err); } });
  syncSituation();

  /* ----- payload ----- */
  const payload = () => {
    const v = (n) => (F[n] ? F[n].value.trim() : '');
    const d = {
      emitter_id: E.id, service_id: v('service_id') || null, lc116: v('lc116').split('—')[0].trim(), ctribnac: v('ctribnac'), sigiss_code: v('sigiss_code'), ctribmun: v('ctribmun'), cnbs: v('cnbs'),
      description: v('description'), competence_date: v('competence_date'), amount: v('amount'), situation: v('situation'), iss_rate: F.iss_rate.disabled ? '' : v('iss_rate'),
      discount_incond: v('discount_incond'), discount_cond: v('discount_cond'), deductions: v('deductions'), pis_cofins_cst: v('pis_cofins_cst'),
    };
    ['pis', 'cofins', 'csll', 'irrf', 'inss'].forEach((k) => { if (v(k + '_rate') !== '') d[k + '_rate'] = v(k + '_rate'); d[k + '_withheld'] = F[k + '_withheld'].checked; });
    if (taker?.id) d.taker_id = taker.id;
    else if (taker) d.taker = taker;
    else if (!$('[data-newtaker]', el).classList.contains('hidden')) {
      d.taker = { kind, document: v('t_document'), name: v('t_name'), email: v('t_email'), phone: v('t_phone'), cep: v('t_cep'), street: v('t_street'), number: v('t_number'), complement: v('t_complement'), district: v('t_district'),
        city: v('t_city').split('/')[0], uf: v('t_uf'), city_ibge: v('t_city_ibge'), im: v('t_im'), country: kind === 'ext' ? v('t_country') : '', foreign_city: v('t_foreign_city'), foreign_region: v('t_foreign_region'), foreign_postal: v('t_foreign_postal'), nif: v('t_nif') };
      d.save_taker = F.save_taker.checked;
    }
    const ex = {};
    if (v('x_loc_type') === 'outro') ex.loc = { type: 'outro', city_ibge: v('x_loc_city_ibge') };
    if (v('x_loc_type') === 'exterior') ex.loc = { type: 'exterior', country: v('x_loc_country') };
    if (d.situation === 'im') ex.imunidade = v('x_imunidade');
    if (d.situation === 'es') ex.exig = { tipo: v('x_exig_tipo'), processo: v('x_exig_processo') };
    if (d.situation === 'is' && v('x_bm_numero')) ex.bm = { numero: v('x_bm_numero'), percentual: v('x_bm_percentual') };
    if (d.situation === 'ex' && v('x_pais_resultado')) ex.pais_resultado = v('x_pais_resultado');
    if (v('x_ded_tipo')) ex.ded_tipo = v('x_ded_tipo');
    if (F.x_interm_name && v('x_interm_name')) ex.interm = { document: v('x_interm_document'), name: v('x_interm_name'), email: v('x_interm_email') };
    if (v('x_obra_codigo') || v('x_obra_cib') || v('x_obra_cep')) ex.obra = { codigo: v('x_obra_codigo'), cib: v('x_obra_cib'), inscricao: v('x_obra_inscricao'), cep: v('x_obra_cep'), street: v('x_obra_street'), number: v('x_obra_number'), district: v('x_obra_district') };
    if (v('x_evento_nome')) ex.evento = { nome: v('x_evento_nome'), inicio: v('x_evento_inicio'), fim: v('x_evento_fim'), id: v('x_evento_id'), cep: v('x_evento_cep'), street: v('x_evento_street'), number: v('x_evento_number'), district: v('x_evento_district') };
    if (v('x_comext_modo')) ex.comext = { modo: v('x_comext_modo'), vinculo: v('x_comext_vinculo'), moeda: v('x_comext_moeda'), valor_moeda: v('x_comext_valor_moeda'), mec_prest: v('x_comext_mec_prest'), mec_toma: v('x_comext_mec_toma'), mov_temp: v('x_comext_mov_temp'), di: v('x_comext_di'), re: v('x_comext_re'), mdic: F.x_comext_mdic.checked };
    const info = { complementar: v('x_info_complementar'), pedido: v('x_info_pedido'), doc_ref: v('x_info_doc_ref') };
    if (Object.values(info).some(Boolean)) ex.info = info;
    if (v('x_codigo_interno')) ex.codigo_interno = v('x_codigo_interno');
    if (F.x_v_receb && v('x_v_receb')) ex.v_receb = v('x_v_receb');
    if (F.x_retro?.checked) ex.retro = true;
    d.extra = ex;
    return d;
  };

  /* ----- live summary ----- */
  const sum = $('[data-summary]', el);
  const refresh = debounce(async () => {
    const d = payload();
    if (!Number(d.amount)) { sum.innerHTML = '<p class="muted small" style="margin:0">Informe o valor para ver o cálculo.</p>'; return; }
    try {
      const t = await api('/fh/invoices/preview', { method: 'POST', body: d });
      const row = (l, v, cls = '') => `<div class="fh-sum-row ${cls}"><span>${l}</span><b>${v}</b></div>`;
      const fed = ['pis', 'cofins', 'csll', 'irrf', 'inss'].filter((k) => Number(t[k + '_amount']));
      sum.innerHTML = row('Valor do serviço', money(t.amount))
        + (Number(t.discount_amount) ? row('Desconto incondicionado', '− ' + money(t.discount_amount)) : '')
        + (Number(d.discount_cond) ? row('Desconto condicionado', '− ' + money(d.discount_cond)) : '')
        + (Number(t.deductions) ? row('Deduções', '− ' + money(t.deductions)) : '')
        + row('Base de cálculo do ISS', money(t.base))
        + row(`ISS (${String(t.iss_rate).replace('.', ',')}%)${Number(t.iss_withheld) ? ' · retido' : ''}`, money(t.iss_amount), Number(t.iss_withheld) ? 'neg' : '')
        + fed.map((k) => row(`${k.toUpperCase()} (${String(t[k + '_rate']).replace('.', ',')}%)${Number(t[k + '_withheld']) ? ' · retido' : ''}`, money(t[k + '_amount']), Number(t[k + '_withheld']) ? 'neg' : '')).join('')
        + (Number(t.withheld_total) ? row('Total retido pelo cliente', '− ' + money(t.withheld_total), 'neg') : '')
        + `<div class="fh-sum-total"><span>Valor líquido a receber</span><b>${money(t.net_amount)}</b></div>`
        + `<p class="small muted" style="margin:8px 0 0">Tributos aproximados: ${money(t.total_taxes_amount)} (${String(t.total_taxes_pct).replace('.', ',')}%)</p>`;
    } catch (err) { sum.innerHTML = `<p class="small neg" style="margin:0">${esc(err.message)}</p>`; }
  }, 250);
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();

  /* ----- actions ----- */
  const save = async (transmit) => {
    const d = payload();
    if (!d.taker_id && !d.taker) throw new Error('Escolha ou cadastre o cliente (tomador).');
    if (inv) {
      inv = await api('/fh/invoices/' + inv.id, { method: 'PUT', body: d });
      if (!transmit) return { invoice: inv };
      try { return { invoice: await api(`/fh/invoices/${inv.id}/transmit`, { method: 'POST' }) }; } catch (err) { inv = await api('/fh/invoices/' + inv.id); return { invoice: inv, error: err.message, details: err.details }; }
    }
    const r = await api('/fh/invoices', { method: 'POST', body: { ...d, transmit } });
    inv = r.invoice;
    history.replaceState(null, '', '#/emitir/' + inv.id);
    fh.cache.takers = {};
    return r;
  };
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('[data-emit]', el);
    btn.classList.add('loading');
    try {
      const r = await save(true);
      if (r.error) {
        showError(r.error, r.details);
      } else {
        await fh.refresh();
        success(r.invoice);
      }
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-draft]', el).addEventListener('click', async () => { try { await save(false); toast('Rascunho salvo. Você pode emitir depois em Notas fiscais.'); } catch (err) { toastError(err); } });
  $('[data-preview]', el).addEventListener('click', async () => { try { await save(false); window.open(downloadUrl(`/fh/invoices/${inv.id}/pdf`), '_blank'); } catch (err) { toastError(err); } });
  const showError = (msg, details = []) => {
    modal({ title: 'A nota não foi autorizada', body: `<div class="alert alert-danger" style="margin:0"><b>${esc(msg)}</b>${details?.length ? `<ul style="margin:8px 0 0;padding-left:18px">${details.map((d) => `<li>${esc(d)}</li>`).join('')}</ul>` : ''}</div><p class="small muted">A nota ficou salva (você a encontra em Notas fiscais). Corrija o que foi indicado e clique em "Emitir nota fiscal" novamente — a mesma numeração é reaproveitada.</p>`,
      footer: '<button class="btn btn-primary" data-close>Corrigir</button>' });
  };
  const success = (n) => {
    const m = modal({ title: 'Nota fiscal emitida!', body: `<div class="fh-success">${icon('check')}<h3>NFS-e nº ${esc(n.nfse_number || '—')}</h3><p class="muted">${esc(n.toma_name)} · ${money(n.amount)}${n.toma_email && Number(E.auto_email) && n.environment === 'production' ? `<br>Enviada para ${esc(n.toma_email)}` : ''}</p>
      <div class="fh-success-actions"><a class="btn" href="${downloadUrl(`/fh/invoices/${n.id}/pdf`)}" target="_blank">${icon('download')} PDF</a><a class="btn" href="${downloadUrl(`/fh/invoices/${n.id}/xml`)}">${icon('code')} XML</a>${n.print_url ? `<a class="btn" href="${esc(n.print_url)}" target="_blank" rel="noopener">${icon('external')} Nota oficial</a>` : ''}</div></div>`,
      footer: '<a class="btn" href="#/notas" data-close>Ver notas</a><button class="btn btn-primary" data-again>Emitir outra</button>' });
    $('[data-again]', m.el).addEventListener('click', () => { m.close(); location.hash = '#/emitir?t=' + Date.now(); });
  };
  return () => document.removeEventListener('click', outside);
}
