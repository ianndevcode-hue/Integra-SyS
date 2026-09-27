import { api, state, $, $$, esc, icon, datetime, badge, options, label, dataTable, formModal, confirmDialog, modal, toast, toastError, copyText, lookups, can } from '../core.js';

export async function render(el, ctx) {
  const map = { general: renderGeneral, users: renderUsers, categories: renderCategories, audit: renderAudit, emails: renderEmails };
  return map[ctx.sub](el, ctx);
}

/* ============================================================== GENERAL */
async function renderGeneral(el) {
  const [s, lk] = await Promise.all([api('/settings'), lookups()]);
  const recCats = lk.categories.filter((c) => c.entry_type === 'receivable');
  el.innerHTML = `
    <div class="page-head"><div><h2>Configurações</h2><p>Empresa, integração Asaas e regras do sistema.</p></div></div>
    <form data-settings novalidate class="grid g2">
      <section class="card"><div class="card-head"><h3>${icon('bank')} Integração Asaas</h3><span class="badge ${state.asaas?.configured ? 'green' : 'yellow'}">${state.asaas?.configured ? 'Conectado' : 'Não configurado'}</span></div><div class="card-body form-grid">
        <div class="field span-2"><label for="s-env">Ambiente</label><select id="s-env" name="asaas_environment"><option value="sandbox" ${s.asaas_environment === 'sandbox' ? 'selected' : ''}>Sandbox (testes)</option><option value="production" ${s.asaas_environment === 'production' ? 'selected' : ''}>Produção</option></select></div>
        <div class="field span-2"><label for="s-key">Chave da API (access_token)</label><input id="s-key" name="asaas_api_key" value="${esc(s.asaas_api_key)}" placeholder="$aact_..." autocomplete="off" spellcheck="false">
          <span class="help">Asaas → Integrações → Chave de API. Fica criptografada (AES-256-GCM) no banco. Sem ela, cobranças e extrato ficam desativados.</span></div>
        <div class="span-2" style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn" data-test>${icon('bolt')} Testar conexão</button></div>
        <fieldset class="span-2"><legend>Webhook (baixa automática)</legend>
          <p class="small muted" style="margin:0 0 10px">No Asaas, vá em Integrações → Webhooks → Cobranças, ative e cole a URL e o token abaixo. Eventos: todos de <b>Cobrança</b>.</p>
          <div class="field"><label>URL do webhook</label><div style="display:flex;gap:6px"><input readonly value="${esc(s.webhook_url)}" class="mono"><button type="button" class="btn btn-icon" data-copy="${esc(s.webhook_url)}" aria-label="Copiar URL">${icon('copy')}</button></div></div>
          <div class="field" style="margin-top:10px"><label>Token de autenticação</label><div style="display:flex;gap:6px"><input readonly value="${esc(s.asaas_webhook_token)}" class="mono"><button type="button" class="btn btn-icon" data-copy="${esc(s.asaas_webhook_token)}" aria-label="Copiar token">${icon('copy')}</button><button type="button" class="btn btn-icon" data-regen title="Gerar novo token" aria-label="Gerar novo token">${icon('refresh')}</button></div></div>
        </fieldset>
      </div></section>
      <div class="grid" style="align-content:start">
        <section class="card"><div class="card-head"><h3>${icon('home')} Empresa</h3></div><div class="card-body form-grid">
          <div class="field span-2"><label>Nome</label><input name="company_name" value="${esc(s.company_name)}"></div>
          <div class="field"><label>CNPJ</label><input name="company_cnpj" value="${esc(s.company_cnpj)}"></div>
          <div class="field"><label>Telefone</label><input name="company_phone" value="${esc(s.company_phone)}"></div>
          <div class="field span-2"><label>E-mail</label><input name="company_email" type="email" value="${esc(s.company_email)}"></div>
        </div></section>
        <section class="card"><div class="card-head"><h3>${icon('settings')} Regras</h3></div><div class="card-body form-grid">
          <div class="field"><label>Reserva padrão de lucro (%)</label><input name="profit_reserve_percent" type="number" min="0" max="100" value="${esc(s.profit_reserve_percent)}"></div>
          <div class="field"><label>SLA padrão de chamados (horas)</label><input name="ticket_sla_hours" type="number" min="1" value="${esc(s.ticket_sla_hours)}"></div>
          <div class="field"><label>Duração da reunião (min)</label><select name="appointment_slot_minutes">${[30, 45, 60, 90].map((n) => `<option ${String(n) === String(s.appointment_slot_minutes) ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
          <div class="field span-2"><label>Etapas padrão de projeto (separadas por |)</label><input name="project_stage_template" value="${esc(s.project_stage_template)}"><span class="help">Aplicadas a novos projetos e às colunas do Kanban.</span></div>
        </div></section>
        <section class="card"><div class="card-head"><h3>${icon('ticket')} Suporte e Área do Cliente</h3><button type="button" class="btn btn-sm" data-canned-open>${icon('message')} Respostas prontas</button></div><div class="card-body form-grid cols-4">
          <div class="field"><label>SLA urgente (h)</label><input name="ticket_sla_urgent" type="number" min="1" value="${esc(s.ticket_sla_urgent || '4')}"></div>
          <div class="field"><label>SLA alta (h)</label><input name="ticket_sla_high" type="number" min="1" value="${esc(s.ticket_sla_high || '8')}"></div>
          <div class="field"><label>SLA normal (h)</label><input name="ticket_sla_normal" type="number" min="1" value="${esc(s.ticket_sla_normal || s.ticket_sla_hours || '24')}"></div>
          <div class="field"><label>SLA baixa (h)</label><input name="ticket_sla_low" type="number" min="1" value="${esc(s.ticket_sla_low || '48')}"></div>
          <div class="field span-2"><label>Encerrar resolvidos após (dias)</label><input name="ticket_autoclose_days" type="number" min="0" value="${esc(s.ticket_autoclose_days || '5')}"><span class="help">0 = nunca. Roda no cron. O cliente pode reabrir respondendo antes disso.</span></div>
          <div class="field span-2"><label>Pedir avaliação (1–5 ★) ao resolver</label><select name="ticket_csat_enabled"><option value="1">Sim</option><option value="0" ${s.ticket_csat_enabled === '0' ? 'selected' : ''}>Não</option></select></div>
          <div class="field span-2"><label>Cliente pode enviar arquivos pelo portal</label><select name="portal_uploads_enabled"><option value="1">Sim (chamados e projetos)</option><option value="0" ${s.portal_uploads_enabled === '0' ? 'selected' : ''}>Não</option></select></div>
          <p class="small muted span-2" style="margin:0">O prazo de SLA pausa sozinho enquanto o chamado está "Aguardando cliente", "Aguardando terceiros" ou "Pausado".</p>
        </div></section>
      </div>
      <section class="card span-2" id="nfse"><div class="card-head"><h3>${icon('file')} Notas fiscais de serviço (NFS-e)</h3></div>
        <div class="card-body form-grid cols-4">
          <div class="field span-2"><label>Como emitir</label><select name="nfse_provider" data-nfse-provider>
            <option value="sigiss" ${s.nfse_provider !== 'nacional' ? 'selected' : ''}>Prefeitura de Marília — SIGISS (recomendado, sem certificado)</option>
            <option value="nacional" ${s.nfse_provider === 'nacional' ? 'selected' : ''}>Sistema Nacional NFS-e (Sefin) — com certificado A1</option></select></div>
          <div class="field"><label>CNPJ do prestador</label><input name="nfse_cnpj" value="${esc(s.nfse_cnpj || (s.company_cnpj || '').replace(/\D/g, ''))}"></div>
          <div class="field"><label>Inscrição municipal (CCM)</label><input name="nfse_im" value="${esc(s.nfse_im)}"></div>
          <div class="field"><label>Regime (Simples Nacional)</label><select name="nfse_op_simp_nac">${[['1', 'Não optante'], ['2', 'MEI'], ['3', 'ME/EPP (Simples)']].map(([v, l]) => `<option value="${v}" ${String(s.nfse_op_simp_nac || '3') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field"><label>Alíquota ISS / Simples (%)</label><input name="nfse_aliquota" value="${esc(s.nfse_aliquota || '0')}"><span class="help">Ex.: 2 ou 2,5. Confira com a contabilidade.</span></div>
          <div class="field"><label>Emitir ao receber pagamento</label><select name="nfse_auto_on_payment"><option value="0">Não</option><option value="1" ${s.nfse_auto_on_payment === '1' ? 'selected' : ''}>Sim, automaticamente</option></select><span class="help">Quando uma cobrança Asaas for paga.</span></div>
          <div class="field"><label>Série do RPS/DPS</label><input name="nfse_serie" value="${esc(s.nfse_serie || '1')}"></div>
          <div class="field"><label>Próximo número do RPS/DPS</label><input name="nfse_next_number" type="number" min="1" value="${esc(s.nfse_next_number || '1')}"></div>
          <div class="field span-2"><label>Descrição padrão do serviço</label><input name="nfse_default_description" value="${esc(s.nfse_default_description || '')}"></div>

          <fieldset style="grid-column:1/-1"><legend>Tributos federais, retenções e Lei da Transparência</legend>
            <p class="help small" style="margin:0 0 10px">Deixe em branco para o padrão automático do regime: <b>Simples/MEI</b> = 0% (recolhidos no DAS); <b>não optante (lucro presumido)</b> = PIS 0,65% · COFINS 3% · CSLL 1% · IRRF 1,5%. Os valores podem ser ajustados em cada nota.</p>
            <div class="form-grid cols-4">
              ${[['pis', 'PIS (%)', '0,65'], ['cofins', 'COFINS (%)', '3'], ['csll', 'CSLL (%)', '1'], ['irrf', 'IRRF (%)', '1,5'], ['inss', 'INSS retido (%)', '11 (cessão de mão de obra)']].map(([k, l, ph]) => `<div class="field"><label>${l}</label><input name="nfse_${k}_rate" value="${esc(s['nfse_' + k + '_rate'] ?? '')}" placeholder="auto · ${ph}" inputmode="decimal"></div>`).join('')}
              <div class="field"><label>CST PIS/COFINS</label><select name="nfse_pis_cofins_cst"><option value="">Automático</option>${[['01', '01 — Tributável'], ['49', '49 — Outras saídas (Simples)'], ['06', '06 — Alíquota zero'], ['07', '07 — Isenta'], ['08', '08 — Sem incidência'], ['99', '99 — Outras']].map(([v, l]) => `<option value="${v}" ${s.nfse_pis_cofins_cst === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
              <div class="field span-2"><label>Retenção na fonte para tomador PJ</label><select name="nfse_withhold_federal_pj"><option value="0">Não reter por padrão</option><option value="1" ${s.nfse_withhold_federal_pj === '1' ? 'selected' : ''}>Reter PIS/COFINS/CSLL/IRRF de clientes PJ (não optante)</option></select><span class="help">IN RFB 459/2004 e art. 647 do RIR. IRRF abaixo de R$ 10,00 não é retido.</span></div>
              <div class="field"><label>Carga tributária aproximada (%)</label><input name="nfse_total_tax_pct" value="${esc(s.nfse_total_tax_pct ?? '')}" placeholder="auto (soma das alíquotas)" inputmode="decimal"><span class="help">Lei 12.741/2012 (tabela IBPT).</span></div>
              <div class="field"><label>Tributos na discriminação</label><select name="nfse_show_taxes"><option value="1">Incluir "valor aproximado dos tributos"</option><option value="0" ${s.nfse_show_taxes === '0' ? 'selected' : ''}>Não incluir</option></select></div>
            </div>
          </fieldset>

          <fieldset style="grid-column:1/-1" data-nfse-block="sigiss"><legend>SIGISS — Prefeitura de Marília</legend>
            <div class="form-grid cols-4">
              <div class="field"><label>Senha do SIGISS</label><input name="nfse_sigiss_password" type="password" value="${esc(s.nfse_sigiss_password)}" autocomplete="new-password" placeholder="senha do portal da prefeitura"></div>
              <div class="field"><label>Código do serviço (LC 116)</label><input name="nfse_sigiss_servico" value="${esc(s.nfse_sigiss_servico || '101')}"><span class="help">Como aparece no SIGISS. Ex.: 101 = item 1.01 (desenvolvimento de sistemas).</span></div>
              <div class="field span-2"><label>Situação padrão</label><select name="nfse_sigiss_situacao">${[['tp', 'Tributada no prestador'], ['tt', 'Tributada no tomador (ISS retido)'], ['is', 'Isenta'], ['im', 'Imune'], ['nt', 'Não tributada']].map(([v, l]) => `<option value="${v}" ${(s.nfse_sigiss_situacao || 'tp') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
              <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;align-items:center"><button type="button" class="btn" data-sigiss-test>${icon('shield')} Salvar e testar acesso ao SIGISS</button><span class="small muted">O teste só consulta — não emite nenhuma nota.</span></div>
              <details style="grid-column:1/-1"><summary style="cursor:pointer;font-weight:600">📘 Onde encontro esses dados?</summary>
                <ol class="small" style="line-height:1.8;margin:10px 0 0">
                  <li>A <b>inscrição municipal (CCM)</b> e a <b>senha</b> são as mesmas usadas para entrar em <a href="https://marilia.sigiss.com.br/marilia/contribuinte/login.php" target="_blank" rel="noopener" style="color:var(--primary)">marilia.sigiss.com.br</a>.</li>
                  <li>O <b>código do serviço</b> aparece no SIGISS ao emitir uma nota manualmente (lista de atividades da sua inscrição).</li>
                  <li>As notas emitidas por aqui são <b>reais</b> (a prefeitura não tem ambiente de testes) e o tomador recebe o e-mail da prefeitura automaticamente.</li>
                  <li>Para o tomador, o sistema envia CPF/CNPJ e endereço do cadastro do cliente — preencha CEP e número; o resto vem pelo CEP.</li>
                </ol></details>
            </div>
          </fieldset>

          <fieldset style="grid-column:1/-1" data-nfse-block="nacional"><legend>Sistema Nacional NFS-e (Sefin)
            <span class="badge ${s.nfse_certificate && !s.nfse_certificate.error ? (s.nfse_certificate.expired ? 'red' : 'green') : 'yellow'}">${s.nfse_certificate ? (s.nfse_certificate.error ? 'Certificado com erro' : s.nfse_certificate.expired ? 'Certificado vencido' : 'Certificado válido') : 'Sem certificado'}</span></legend>
            <div class="form-grid cols-4">
              <div class="field"><label>Ambiente</label><select name="nfse_environment"><option value="homologation" ${s.nfse_environment !== 'production' ? 'selected' : ''}>Produção restrita (testes)</option><option value="production" ${s.nfse_environment === 'production' ? 'selected' : ''}>Produção (valor fiscal)</option></select></div>
              <div class="field"><label>Município (IBGE)</label><input name="nfse_city_code" value="${esc(s.nfse_city_code || '3529005')}"><span class="help">3529005 = Marília-SP</span></div>
              <div class="field"><label>Apuração no Simples (opcional)</label><select name="nfse_reg_ap_trib_sn"><option value="">Não informar</option>${[['1', 'Federais e municipal pelo SN'], ['2', 'Federais pelo SN, ISS pela NFS-e'], ['3', 'Federais e ISS pela NFS-e']].map(([v, l]) => `<option value="${v}" ${String(s.nfse_reg_ap_trib_sn) === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
              <div class="field"><label>Regime especial</label><select name="nfse_reg_esp_trib">${[['0', 'Nenhum'], ['1', 'Ato cooperado'], ['2', 'Estimativa'], ['3', 'Microempresa municipal'], ['4', 'Notário/registrador'], ['5', 'Profissional autônomo'], ['6', 'Sociedade de profissionais']].map(([v, l]) => `<option value="${v}" ${String(s.nfse_reg_esp_trib || '0') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
              <div class="field"><label>cTribNac padrão</label><input name="nfse_ctribnac" value="${esc(s.nfse_ctribnac || '010101')}"></div>
              <div class="field"><label>NBS padrão</label><input name="nfse_cnbs" value="${esc(s.nfse_cnbs || '115022000')}"></div>
              <div class="field"><label>% aproximado tributos (Simples)</label><input name="nfse_simples_percent" value="${esc(s.nfse_simples_percent || '0')}"></div>
              <div class="field" style="grid-column:1/-1">
                ${s.nfse_certificate && !s.nfse_certificate.error ? `<p class="small" style="margin:0 0 10px">Certificado atual: <b>${esc(s.nfse_certificate.subject)}</b> · emitido por ${esc(s.nfse_certificate.issuer)} · válido até <b>${esc(s.nfse_certificate.valid_to)}</b></p>` : ''}
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                  <div class="field" style="flex:1 1 220px"><label>Certificado A1 (.pfx / .p12)</label><input type="file" accept=".pfx,.p12" data-cert-file></div>
                  <div class="field" style="flex:1 1 160px"><label>Senha do certificado</label><input type="password" data-cert-pass autocomplete="new-password"></div>
                  <button type="button" class="btn" data-cert-upload>${icon('upload')} Enviar certificado</button>
                  ${s.nfse_certificate ? `<button type="button" class="btn btn-danger" data-cert-del>${icon('trash')}</button>` : ''}
                </div>
                <p class="help small" style="margin:8px 0 0">Arquivo e senha ficam criptografados (AES-256-GCM). Use o e-CNPJ da empresa prestadora.</p>
              </div>
            </div>
          </fieldset>
        </div></section>

      <section class="card span-2"><div class="card-head"><h3>${icon('sparkles')} Inteligência Artificial — Cloudflare Workers AI</h3><span class="badge ${s.ai_configured ? 'green' : 'yellow'}">${s.ai_configured ? 'Conectado' : 'Não configurado'}</span></div>
        <div class="card-body form-grid cols-4">
          <div class="field span-2"><label>Account ID</label><input name="cloudflare_account_id" value="${esc(s.cloudflare_account_id)}" class="mono" autocomplete="off"></div>
          <div class="field span-2"><label>API Token (Workers AI)</label><input name="cloudflare_api_token" value="${esc(s.cloudflare_api_token)}" class="mono" autocomplete="off" placeholder="cfat_..."></div>
          <div class="field span-2"><label>Modelo</label><select name="ai_model">${[['@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'Llama 3.3 70B (recomendado)'], ['@cf/meta/llama-3.1-8b-instruct-fast', 'Llama 3.1 8B (mais rápido e barato)'], ['@cf/mistralai/mistral-small-3.1-24b-instruct', 'Mistral Small 3.1 24B']].map(([v, l]) => `<option value="${v}" ${(s.ai_model || '@cf/meta/llama-3.3-70b-instruct-fp8-fast') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field"><label>Chat do site</label><select name="ai_chat_enabled"><option value="1">Ativado</option><option value="0" ${s.ai_chat_enabled === '0' ? 'selected' : ''}>Desativado</option></select></div>
          <div class="field"><label>Análise do diagnóstico</label><select name="ai_diagnostic_enabled"><option value="1">Ativado</option><option value="0" ${s.ai_diagnostic_enabled === '0' ? 'selected' : ''}>Desativado</option></select></div>
          <div class="field"><label>Assistente no painel</label><select name="ai_admin_enabled"><option value="1">Ativado</option><option value="0" ${s.ai_admin_enabled === '0' ? 'selected' : ''}>Desativado</option></select><span class="help">Respostas de chamados, leads, blog, NFS-e.</span></div>
          <div class="span-2" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><button type="button" class="btn" data-ai-test>${icon('bolt')} Testar IA</button><span class="small muted" data-ai-usage></span></div>
        </div></section>

      <section class="card span-2" id="pricing"><div class="card-head"><h3>${icon('money')} Vendas, preços e contratos</h3><a class="btn btn-sm" href="#/pricing/catalog">${icon('tag')} Tabela de preços</a></div>
        <div class="card-body form-grid cols-4">
          <div class="field span-2"><label>Chave da Brave Search API (consultor de preços)</label><input name="search_api_key" value="${esc(s.search_api_key)}" class="mono" autocomplete="off" placeholder="opcional — busca de preços na web em tempo real"><span class="help">Crie em api-search.brave.com (plano gratuito: 2.000 consultas/mês). Sem a chave, a IA usa a tabela de referência de Marília.</span></div>
          <div class="field"><label>Gerar mensalidades com antecedência</label><input name="contracts_lead_days" type="number" min="0" max="40" value="${esc(s.contracts_lead_days || '10')}"><span class="help">dias antes do vencimento</span></div>
          <div class="field"><label>Faturamento automático (cron)</label><select name="contracts_auto_billing"><option value="0">Manual (botão em Contratos)</option><option value="1" ${s.contracts_auto_billing === '1' ? 'selected' : ''}>Automático todo dia</option></select></div>
          <div class="field"><label>Renovação ao fim da vigência</label><select name="contracts_auto_renew"><option value="1">Renovar automaticamente</option><option value="0" ${s.contracts_auto_renew === '0' ? 'selected' : ''}>Parar de faturar</option></select></div>
          <div class="field"><label>Categoria das implantações</label><select name="pricing_setup_category"><option value="">Sem categoria</option>${recCats.map((c) => `<option value="${c.id}" ${String(s.pricing_setup_category) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
          <div class="field"><label>Categoria das mensalidades</label><select name="pricing_monthly_category"><option value="">Sem categoria</option>${recCats.map((c) => `<option value="${c.id}" ${String(s.pricing_monthly_category) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
        </div></section>

      <section class="card span-2" id="email"><div class="card-head"><h3>${icon('send')} E-mail</h3><span class="badge ${s.mail_ready ? 'green' : 'yellow'}">${s.mail_ready ? 'Ativo' : 'Desativado'}</span></div>
        <div class="card-body form-grid cols-4">
          <div class="field"><label>Envio de e-mails</label><select name="mail_enabled"><option value="0">Desativado</option><option value="1" ${s.mail_enabled === '1' ? 'selected' : ''}>Ativado</option></select></div>
          <div class="field"><label>Provedor</label><select name="mail_provider" data-mail-provider><option value="smtp" ${s.mail_provider !== 'cloudflare' ? 'selected' : ''}>SMTP (Hostinger, Gmail…)</option><option value="cloudflare" ${s.mail_provider === 'cloudflare' ? 'selected' : ''}>Cloudflare Email Service</option></select></div>
          <div class="field"><label>Remetente (e-mail)</label><input name="mail_from_email" value="${esc(s.mail_from_email)}" placeholder="dev@integra-code.tech"></div>
          <div class="field"><label>Remetente (nome)</label><input name="mail_from_name" value="${esc(s.mail_from_name || 'Integra Code')}"></div>
          <div class="field span-2"><label>Respostas dos clientes vão para (Reply-To)</label><input name="mail_reply_to" value="${esc(s.mail_reply_to)}" placeholder="dev@integra-code.tech"><span class="help">Útil ao enviar pelo Gmail: o cliente responde e cai na caixa do domínio.</span></div>
          <div class="field span-2"><label>Avisos da equipe vão para</label><input name="mail_notify_to" value="${esc(s.mail_notify_to || 'dev@integra-code.tech')}"><span class="help">Leads, agendamentos, chamados, pagamentos. Separe vários e-mails por vírgula.</span></div>
          <div class="field"><label>E-mails de cobrança ao cliente</label><select name="mail_charge_emails"><option value="1">Enviar</option><option value="0" ${s.mail_charge_emails === '0' ? 'selected' : ''}>Não enviar</option></select></div>
          <div class="field"><label>Enviar NFS-e ao cliente</label><select name="mail_nfse_emails"><option value="1">Enviar</option><option value="0" ${s.mail_nfse_emails === '0' ? 'selected' : ''}>Não enviar</option></select></div>
          <div data-provider="smtp" class="form-grid cols-4" style="grid-column:1/-1">
            <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;align-items:center"><span class="small muted">Configuração rápida:</span>
              <button type="button" class="btn btn-sm" data-preset="hostinger">${icon('globe')} Hostinger (recomendado p/ @integra-code.tech)</button>
              <button type="button" class="btn btn-sm" data-preset="gmail">${icon('send')} Gmail</button></div>
            <div class="field"><label>Servidor SMTP</label><input name="mail_host" value="${esc(s.mail_host || 'smtp.gmail.com')}"></div>
            <div class="field"><label>Porta / segurança</label><select name="mail_port" data-port><option value="587" ${String(s.mail_port || '587') === '587' ? 'selected' : ''}>587 (TLS)</option><option value="465" ${String(s.mail_port) === '465' ? 'selected' : ''}>465 (SSL)</option></select><input type="hidden" name="mail_encryption" value="${esc(s.mail_encryption === 'ssl' ? 'ssl' : 'tls')}"></div>
            <div class="field"><label>Usuário (e-mail completo)</label><input name="mail_username" value="${esc(s.mail_username)}" autocomplete="off" placeholder="dev@integra-code.tech"></div>
            <div class="field"><label data-pass-label>Senha da caixa de e-mail</label><input name="mail_password" value="${esc(s.mail_password)}" autocomplete="new-password"></div>
            <details class="span-2" style="grid-column:1/-1" open><summary style="cursor:pointer;font-weight:600">📘 E-mail da Hostinger (dev@integra-code.tech)</summary>
              <ol class="small" style="line-height:1.8;margin:10px 0 0">
                <li>No hPanel da Hostinger: <b>E-mails → Contas de e-mail</b>. Confira se a caixa <b>dev@integra-code.tech</b> existe (crie se não existir) e, se não souber a senha, use <b>Alterar senha</b>.</li>
                <li>Clique em "Configuração rápida → Hostinger": servidor <b>smtp.hostinger.com</b>, porta <b>465 (SSL)</b>.</li>
                <li>Usuário = <b>dev@integra-code.tech</b> (o endereço completo) e senha = a senha dessa caixa. Remetente = o mesmo endereço.</li>
                <li>Clique em "Salvar e enviar teste". O domínio já tem SPF da Hostinger, então os e-mails chegam autenticados (sem cair no spam).</li>
                <li>Dica: em E-mails → <b>Autenticação (DKIM)</b> na Hostinger, confirme que o DKIM está ativo para melhorar a entrega.</li>
              </ol></details>
            <details class="span-2" style="grid-column:1/-1"><summary style="cursor:pointer;font-weight:600">📘 Como gerar a senha de app do Gmail</summary>
              <ol class="small" style="line-height:1.8;margin:10px 0 0">
                <li>Entre em <a href="https://myaccount.google.com/security" target="_blank" rel="noopener" style="color:var(--primary)">myaccount.google.com/security</a> com a conta que vai enviar e ative a <b>Verificação em duas etapas</b>.</li>
                <li>Abra <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="color:var(--primary)">myaccount.google.com/apppasswords</a>, dê o nome "Site Integra Code" e clique em <b>Criar</b>.</li>
                <li>Copie a senha de 16 letras e cole acima. Usuário = o endereço do Gmail. Servidor smtp.gmail.com, porta 587.</li>
                <li>O remetente deve ser esse mesmo Gmail — ou um endereço configurado em Gmail → Configurações → Contas → <b>"Enviar e-mail como"</b> (ex.: dev@integra-code.tech).</li>
                <li>Limites do Gmail: ~500 e-mails/dia (conta pessoal) ou 2.000/dia (Google Workspace).</li>
              </ol></details>
          </div>
          <div data-provider="cloudflare" class="form-grid cols-4" style="grid-column:1/-1">
            <div class="field span-2"><label>Token com permissão "Email Sending"</label><input name="mail_cf_token" value="${esc(s.mail_cf_token)}" autocomplete="off" placeholder="Opcional se o token da IA já tiver a permissão"><span class="help">Usa o Account ID informado na seção de IA.</span></div>
            <details class="span-2" style="grid-column:1/-1"><summary style="cursor:pointer;font-weight:600">📘 Como ativar o Cloudflare Email Service</summary>
              <ol class="small" style="line-height:1.8;margin:10px 0 0">
                <li>O domínio <b>integra-code.tech</b> precisa estar na Cloudflare: em dash.cloudflare.com → <b>Add a domain</b>, e troque os nameservers no painel da Hostinger (Domínios → DNS/Nameservers) pelos informados pela Cloudflare. Confira que os registros A do site e os MX de e-mail foram importados.</li>
                <li>Em <b>Compute & AI → Email Service → Email Sending → Onboard Domain</b>, escolha o domínio e clique em "Add records and onboard" (SPF e DKIM são criados sozinhos).</li>
                <li>Em <b>My Profile → API Tokens</b>, edite o token (ou crie outro) e adicione a permissão <b>Account → Email Sending → Edit</b>.</li>
                <li>Remetente: qualquer endereço @integra-code.tech (ex.: dev@integra-code.tech). Use apenas para e-mails transacionais.</li>
              </ol></details>
          </div>
          <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div class="field" style="flex:1 1 240px"><label>Enviar teste para</label><input data-test-to type="email" value="${esc(state.user.email)}"></div>
            <button type="button" class="btn" data-mail-test>${icon('send')} Salvar e enviar teste</button>
            <a class="btn btn-ghost" href="#/settings/emails">${icon('log')} E-mails enviados</a>
          </div>
          <p class="small muted" style="grid-column:1/-1;margin:0">Reenvio automático de falhas: configure um Cron Job na Hostinger (a cada 5 min) chamando <code class="mono">php …/public_html/cron.php</code> ou a URL <code class="mono" style="word-break:break-all">${esc(s.cron_url)}</code>.</p>
        </div></section>

      <section class="card span-2" id="login"><div class="card-head"><h3>${icon('shield')} Login e cadastro</h3></div>
        <div class="card-body form-grid cols-4">
          <div class="field"><label>Login com Google</label><select name="google_login_enabled"><option value="0">Desativado</option><option value="1" ${s.google_login_enabled === '1' ? 'selected' : ''}>Ativado</option></select></div>
          <div class="field"><label>Cadastro de clientes pelo site</label><select name="portal_signup_enabled"><option value="1">Permitido</option><option value="0" ${s.portal_signup_enabled === '0' ? 'selected' : ''}>Somente por convite</option></select></div>
          <div class="field span-2"><label>URI de redirecionamento autorizado (cole no Google Cloud)</label><div style="display:flex;gap:6px"><input readonly value="${esc(s.google_redirect_uri)}" class="mono"><button type="button" class="btn btn-icon" data-copy="${esc(s.google_redirect_uri)}" aria-label="Copiar">${icon('copy')}</button></div></div>
          <div class="field span-2"><label>Google Client ID</label><input name="google_client_id" value="${esc(s.google_client_id)}" class="mono" placeholder="xxxx.apps.googleusercontent.com" autocomplete="off"></div>
          <div class="field span-2"><label>Google Client Secret</label><input name="google_client_secret" value="${esc(s.google_client_secret)}" class="mono" placeholder="GOCSPX-..." autocomplete="off"></div>
          <details style="grid-column:1/-1"><summary style="cursor:pointer;font-weight:600">📘 Como criar as credenciais do Google</summary>
            <ol class="small" style="line-height:1.8;margin:10px 0 0">
              <li>Acesse <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" style="color:var(--primary)">console.cloud.google.com</a> e crie um projeto (ex.: "Integra Code Site").</li>
              <li>Em <b>APIs e serviços → Tela de permissão OAuth</b> (Google Auth Platform): tipo <b>Externo</b>, nome "Integra Code", e-mail de suporte, domínio autorizado <b>integra-code.tech</b>, links da política de privacidade (https://integra-code.tech/privacidade). Escopos: <i>openid, email, profile</i>. Publique o app (esses escopos não exigem verificação).</li>
              <li>Em <b>Credenciais → Criar credenciais → ID do cliente OAuth</b> → tipo <b>Aplicativo da Web</b>. Em "URIs de redirecionamento autorizados" cole a URI acima (e também <span class="mono">http://localhost:8080/google-login</span> para testes locais).</li>
              <li>Copie o <b>Client ID</b> e o <b>Client Secret</b> para os campos acima, ative o login com Google e salve.</li>
            </ol></details>
        </div></section>

      <div class="span-2" style="display:flex;justify-content:flex-end"><button class="btn btn-primary" data-save>${icon('check')} Salvar configurações</button></div>
    </form>`;

  const form = $('[data-settings]', el);
  const collect = () => Object.fromEntries([...form.querySelectorAll('[name]')].filter((i) => !i.readOnly && i.type !== 'file').map((i) => [i.name, i.value]));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('[data-save]', el);
    btn.classList.add('loading');
    try {
      await api('/settings', { method: 'PUT', body: collect() });
      const me = await api('/auth/me');
      state.asaas = me.asaas;
      state.ai = me.ai;
      state.nfse = me.nfse;
      lookups(true);
      toast('Configurações salvas.');
      renderGeneral(el);
      document.querySelector('.env-pill span').textContent = 'Asaas: ' + (me.asaas.configured ? (me.asaas.environment === 'production' ? 'Produção' : 'Sandbox') : 'não configurado');
      document.querySelector('.env-pill').classList.toggle('live', me.asaas.configured && me.asaas.environment === 'production');
    } catch (err) { toastError(err); btn.classList.remove('loading'); }
  });
  $$('[data-copy]', el).forEach((b) => b.addEventListener('click', () => copyText(b.dataset.copy)));
  const syncNfse = () => { const v = $('[data-nfse-provider]', el).value; $$('[data-nfse-block]', el).forEach((d) => d.classList.toggle('hidden', d.dataset.nfseBlock !== v)); };
  $('[data-nfse-provider]', el).addEventListener('change', syncNfse);
  syncNfse();
  $('[data-sigiss-test]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      await api('/settings', { method: 'PUT', body: collect() });
      const r = await api('/nfse/sigiss-test', { method: 'POST' });
      toast(r.ok ? 'Acesso ao SIGISS confirmado ✅' : 'O SIGISS recusou o acesso: ' + (r.messages.join(' ') || 'verifique CCM, CNPJ e senha.'), r.ok ? 'success' : 'error');
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  const syncProvider = () => { const v = $('[data-mail-provider]', el).value; $$('[data-provider]', el).forEach((d) => d.classList.toggle('hidden', d.dataset.provider !== v)); };
  $('[data-mail-provider]', el).addEventListener('change', syncProvider);
  syncProvider();
  $('[data-port]', el).addEventListener('change', (e) => { form.querySelector('[name=mail_encryption]').value = e.target.value === '465' ? 'ssl' : 'tls'; });
  const presets = {
    hostinger: { mail_host: 'smtp.hostinger.com', mail_port: '465', mail_encryption: 'ssl', mail_username: 'dev@integra-code.tech', mail_from_email: 'dev@integra-code.tech', pass: 'Senha da caixa de e-mail (Hostinger)' },
    gmail: { mail_host: 'smtp.gmail.com', mail_port: '587', mail_encryption: 'tls', mail_username: '', mail_from_email: '', mail_reply_to: 'dev@integra-code.tech', pass: 'Senha de app do Google (16 letras)' },
  };
  $$('[data-preset]', el).forEach((b) => b.addEventListener('click', () => {
    const p = presets[b.dataset.preset];
    Object.entries(p).forEach(([k, v]) => { const f = form.querySelector(`[name=${k}]`); if (f && (v || k !== 'mail_username')) f.value = v; });
    $('[data-pass-label]', el).textContent = p.pass;
    form.querySelector('[name=mail_password]').value = '';
    form.querySelector('[name=mail_password]').focus();
    toast(b.dataset.preset === 'hostinger' ? 'Preenchido para Hostinger. Digite a senha da caixa dev@integra-code.tech.' : 'Preenchido para Gmail. Use uma senha de app.', 'info');
  }));
  $('[data-mail-test]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      await api('/settings', { method: 'PUT', body: { ...collect(), mail_enabled: '1' } });
      form.querySelector('[name=mail_enabled]').value = '1';
      const r = await api('/mail/test', { method: 'POST', body: { to: $('[data-test-to]', el).value } });
      toast(r.message);
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-cert-upload]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const file = $('[data-cert-file]', el).files[0];
    if (!file) { toast('Escolha o arquivo .pfx do certificado.', 'error'); return; }
    btn.classList.add('loading');
    try {
      const buf = new Uint8Array(await file.arrayBuffer());
      let bin = '';
      for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
      const r = await api('/nfse/certificate', { method: 'POST', body: { pfx_b64: btoa(bin), password: $('[data-cert-pass]', el).value } });
      toast(`Certificado de ${r.info.subject} salvo (válido até ${r.info.valid_to}).`);
      renderGeneral(el);
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-cert-del]', el)?.addEventListener('click', async () => {
    if (!await confirmDialog('Remover o certificado digital? A emissão de NFS-e ficará indisponível.', { danger: true })) return;
    await api('/nfse/certificate', { method: 'DELETE' }); toast('Certificado removido.'); renderGeneral(el);
  });
  $('[data-ai-test]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      await api('/settings', { method: 'PUT', body: collect() });
      const r = await api('/ai/test', { method: 'POST' });
      toast(`IA respondeu em ${(r.ms / 1000).toFixed(1)}s: "${r.reply}"`);
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  api('/ai/usage').then((u) => {
    const total = u.data.reduce((a, r) => a + Number(r.calls), 0);
    const box = $('[data-ai-usage]', el);
    if (box) box.textContent = total ? `Últimos 30 dias: ${total} chamadas (${u.data.map((r) => `${r.feature} ${r.calls}`).join(', ')})` : 'Nenhum uso nos últimos 30 dias.';
  }).catch(() => {});
  $('[data-canned-open]', el)?.addEventListener('click', async () => { const mod = await import('./tickets.js'); mod.cannedManager(); });
  $('[data-regen]', el).addEventListener('click', async () => {
    if (!await confirmDialog('Gerar um novo token? Você precisará atualizá-lo no painel do Asaas.')) return;
    await api('/settings', { method: 'PUT', body: { regenerate_webhook_token: true } });
    toast('Novo token gerado.'); renderGeneral(el);
  });
  $('[data-test]', el).addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.classList.add('loading');
    try {
      await api('/settings', { method: 'PUT', body: collect() });
      const r = await api('/asaas/test', { method: 'POST' });
      toast(`Conexão OK (${r.environment === 'production' ? 'produção' : 'sandbox'}). Saldo: R$ ${Number(r.balance || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`);
      const me = await api('/auth/me'); state.asaas = me.asaas;
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
}

/* ================================================================ USERS */
function userForm(values, onSaved) {
  formModal({
    title: values.id ? 'Editar usuário' : 'Novo usuário', values: { active: 1, role: 'support', ...values },
    intro: '<div class="alert alert-info small" style="margin:0"><b>Perfis:</b> Administrador (tudo) · Financeiro (clientes, financeiro, cobranças, sócios) · Gestor de projetos (clientes, projetos, leads, agenda, chamados, conteúdo) · Suporte (clientes, chamados, leads, agenda, conteúdo).</div>',
    fields: [
      { name: 'name', label: 'Nome', required: true },
      { name: 'email', label: 'E-mail', type: 'email', required: true },
      { name: 'role', label: 'Perfil de acesso', type: 'select', options: options('role'), empty: false },
      { name: 'password', label: values.id ? 'Nova senha (opcional)' : 'Senha (mín. 8) — ou deixe em branco e envie convite', type: 'password', autocomplete: 'new-password' },
      !values.id && { name: 'invite', label: 'Enviar convite por e-mail para a pessoa criar a própria senha', type: 'checkbox', span: 2, default: 1 },
      { name: 'active', label: 'Usuário ativo', type: 'checkbox', span: 2 },
    ],
    onSubmit: async (d) => {
      const saved = values.id ? await api('/users/' + values.id, { method: 'PUT', body: d }) : await api('/users', { method: 'POST', body: d });
      if (!values.id && d.invite) {
        try { const r = await api(`/users/${saved.id}/invite`, { method: 'POST' }); toast(r.message); } catch (err) { toastError(err); }
      } else toast('Usuário salvo.');
      onSaved();
    },
  });
}

function renderUsers(el) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Usuários</h2><p>Acesso ao painel com permissões por perfil.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Novo usuário</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/users',
    columns: [
      { label: 'Usuário', primary: true, sort: 'name', render: (u) => `<b>${esc(u.name)}</b><span class="sub">${esc(u.email)}</span>` },
      { label: 'Perfil', render: (u) => badge('role', u.role) },
      { label: 'Último acesso', render: (u) => datetime(u.last_login_at) },
      { label: 'Status', render: (u) => (Number(u.active) ? '<span class="badge green">Ativo</span>' : '<span class="badge">Inativo</span>') },
    ],
    onRowClick: (u) => userForm(u, () => table.reload()),
    actions: (u) => [{ label: 'Enviar convite / redefinir senha', icon: 'send', iconOnly: true, onClick: async () => { try { const r = await api(`/users/${u.id}/invite`, { method: 'POST' }); toast(r.message); } catch (e) { toastError(e); } } }, u.id !== state.user.id && { label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog(`Excluir o usuário ${u.name}?`, { danger: true })) { try { await api('/users/' + u.id, { method: 'DELETE' }); table.reload(); } catch (e) { toastError(e); } } } }],
  });
  $('[data-new]', el).addEventListener('click', () => userForm({}, () => table.reload()));
}

/* =========================================================== CATEGORIES */
function categoryForm(values, onSaved) {
  formModal({
    title: values.id ? 'Editar categoria' : 'Nova categoria', size: 'sm', values: { color: '#0066fe', entry_type: 'payable', dre_group: 'expense', ...values },
    fields: [
      { name: 'name', label: 'Nome', required: true, span: 2 },
      { name: 'entry_type', label: 'Tipo', type: 'select', options: options('entry_type'), empty: false },
      { name: 'dre_group', label: 'Grupo no DRE', type: 'select', options: options('dre_group'), empty: false },
      { name: 'color', label: 'Cor', type: 'color' },
    ],
    onSubmit: async (d) => {
      values.id ? await api('/categories/' + values.id, { method: 'PUT', body: d }) : await api('/categories', { method: 'POST', body: d });
      lookups(true); toast('Categoria salva.'); onSaved();
    },
  });
}

function renderCategories(el) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Plano de contas</h2><p>Categorias usadas nos lançamentos e no agrupamento do DRE.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Nova categoria</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/categories', perPage: 100,
    filters: [{ name: 'entry_type', label: 'Todos os tipos', options: options('entry_type') }, { name: 'dre_group', label: 'Todos os grupos', options: options('dre_group') }],
    columns: [
      { label: 'Categoria', primary: true, sort: 'name', render: (c) => `<span class="dot" style="background:${esc(c.color || '#94a3b8')}"></span> <b>${esc(c.name)}</b>` },
      { label: 'Tipo', sort: 'entry_type', render: (c) => badge('entry_type', c.entry_type) },
      { label: 'Grupo DRE', render: (c) => badge('dre_group', c.dre_group) },
    ],
    onRowClick: (c) => categoryForm(c, () => table.reload()),
    actions: (c) => [{ label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog(`Excluir "${c.name}"? Os lançamentos ficarão sem categoria.`, { danger: true })) { await api('/categories/' + c.id, { method: 'DELETE' }); lookups(true); table.reload(); } } }],
  });
  $('[data-new]', el).addEventListener('click', () => categoryForm({}, () => table.reload()));
}

/* ================================================================ AUDIT */
function renderAudit(el) {
  const actions = { create: 'Criação', update: 'Alteração', delete: 'Exclusão', login: 'Login', import: 'Importação', reconcile: 'Conciliação', webhook: 'Webhook', cancel: 'Cancelamento', pay: 'Baixa' };
  el.innerHTML = `<div class="page-head"><div><h2>Auditoria</h2><p>Registro de todas as ações realizadas no sistema.</p></div></div><div data-table></div>`;
  dataTable($('[data-table]', el), {
    endpoint: '/audit-log', perPage: 50,
    filters: [{ name: 'action', label: 'Todas as ações', options: Object.entries(actions).map(([value, l]) => ({ value, label: l })) }],
    columns: [
      { label: 'Quando', sort: 'created_at', class: 'nowrap', render: (a) => datetime(a.created_at) },
      { label: 'Usuário', primary: true, render: (a) => esc(a.user_name || 'sistema') },
      { label: 'Ação', render: (a) => `<span class="badge">${esc(actions[a.action] || a.action)}</span>` },
      { label: 'Registro', render: (a) => `${esc(a.entity)}${a.entity_id ? ' #' + esc(a.entity_id) : ''}` },
      { label: 'Detalhes', render: (a) => `<span class="mono small muted clip" title="${esc(a.details || '')}">${esc(a.details || '')}</span>` },
      { label: 'IP', render: (a) => `<span class="mono small">${esc(a.ip || '')}</span>` },
    ],
  });
}

/* ================================================================ E-MAILS */
function renderEmails(el) {
  const events = { lead_team: 'Lead (equipe)', lead_ack: 'Lead (confirmação)', appointment_ack: 'Agendamento', appointment_team: 'Agendamento (equipe)', ticket_ack: 'Chamado aberto', ticket_team: 'Chamado (equipe)', ticket_reply: 'Resposta de chamado', ticket_customer: 'Cliente respondeu', diagnostic_ack: 'Diagnóstico', diagnostic_team: 'Diagnóstico (equipe)', newsletter: 'Newsletter', charge_created: 'Cobrança', charge_paid: 'Pagamento', charge_paid_team: 'Pagamento (equipe)', nfse_authorized: 'NFS-e', password_reset: 'Redefinir senha', invite: 'Convite', verify_email: 'Confirmar e-mail', signup_team: 'Cadastro (equipe)' };
  const stColor = { sent: 'green', pending: 'yellow', sending: 'blue', failed: 'red' };
  const stLabel = { sent: 'Enviado', pending: 'Na fila', sending: 'Enviando', failed: 'Falhou' };
  el.innerHTML = `<div class="page-head"><div><h2>E-mails enviados</h2><p>Fila e histórico de todos os e-mails do sistema.</p></div>
    <div class="page-actions"><a class="btn" href="#/settings">${icon('settings')} Configurar</a><button class="btn btn-primary" data-flush>${icon('send')} Processar fila agora</button></div></div><div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/mail/outbox',
    searchPlaceholder: 'Buscar destinatário, assunto...',
    filters: [{ name: 'status', label: 'Todos os status', options: Object.entries(stLabel).map(([value, label]) => ({ value, label })) }, { name: 'event', label: 'Todos os tipos', options: Object.entries(events).map(([value, label]) => ({ value, label })) }],
    emptyText: 'Nenhum e-mail ainda.', emptyIcon: 'send',
    columns: [
      { label: 'Quando', sort: 'created_at', render: (m) => datetime(m.created_at) },
      { label: 'Assunto', primary: true, render: (m) => `<b>${esc(m.subject)}</b><span class="sub">${esc(m.to_name ? m.to_name + ' · ' : '')}${esc(m.to_email)}</span>` },
      { label: 'Tipo', render: (m) => `<span class="badge">${esc(events[m.event] || m.event || '—')}</span>` },
      { label: 'Status', render: (m) => `<span class="badge ${stColor[m.status] || ''}">${stLabel[m.status] || esc(m.status)}</span>${m.last_error ? `<span class="sub" title="${esc(m.last_error)}">${esc(m.last_error.slice(0, 70))}…</span>` : ''}` },
    ],
    onRowClick: async (m) => {
      const full = await api('/mail/outbox/' + m.id);
      const box = document.createElement('div');
      box.innerHTML = `<p class="small muted" style="margin:0 0 10px">Para ${esc(full.to_email)} · ${datetime(full.created_at)} · tentativas: ${full.attempts}${full.last_error ? '<br><span class="neg">' + esc(full.last_error) + '</span>' : ''}</p><iframe sandbox style="width:100%;height:60vh;border:1px solid var(--border);border-radius:12px;background:#fff"></iframe>`;
      const md = modal({ title: full.subject, size: 'lg', body: box, footer: full.status !== 'sent' ? `<button class="btn btn-primary" data-retry>${icon('refresh')} Reenviar</button>` : '' });
      box.querySelector('iframe').srcdoc = full.html;
      md.el.querySelector('[data-retry]')?.addEventListener('click', async () => { const r = await api(`/mail/outbox/${m.id}/retry`, { method: 'POST' }); toast(`Processado: ${r.sent || 0} enviado(s), ${r.failed || 0} falha(s).`, r.failed ? 'error' : 'success'); md.close(); table.reload(); });
    },
  });
  $('[data-flush]', el).addEventListener('click', async () => { try { const r = await api('/mail/flush', { method: 'POST' }); toast(r.skipped ? 'Envio de e-mails desativado.' : `${r.sent} enviado(s), ${r.failed} falha(s).`, r.failed ? 'error' : 'success'); table.reload(); } catch (e) { toastError(e); } });
}
