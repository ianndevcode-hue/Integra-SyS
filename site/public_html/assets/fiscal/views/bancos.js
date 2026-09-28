/* Contas bancárias, conexões automáticas (Open Finance / APIs dos bancos), categorias, regras e configurações do financeiro. */
import { api, $, $$, esc, icon, money, date, datetime, formModal, modal, confirmDialog, toast, toastError, emptyState, today } from '/admin/js/core.js';
import { fin, importModal, transferModal, needEdit } from '/assets/fiscal/fin.js';

const TABS = [['contas', 'Contas', 'bank'], ['conexoes', 'Conexões automáticas', 'link'], ['categorias', 'Categorias e regras', 'tag'], ['config', 'Configurações', 'settings']];

export async function render(el, ctx) {
  await fin.load(true);
  const tab = TABS.some((t) => t[0] === ctx.query.tab) ? ctx.query.tab : 'contas';
  el.innerHTML = `
    <div class="page-head"><div><h2>Contas bancárias</h2><p>Contas, caixa e cartões da empresa, conexões automáticas com os bancos e as categorias do financeiro.</p></div>
      <div class="page-actions"><button class="btn" data-transfer>${icon('split')} Transferência</button><button class="btn btn-primary" data-new-acc>${icon('plus')} Nova conta</button></div></div>
    <div class="tabs">${TABS.map(([k, l, ic]) => `<a class="tab ${k === tab ? 'active' : ''}" href="#/financeiro/contas${k === 'contas' ? '' : '?tab=' + k}">${icon(ic)} ${l}</a>`).join('')}</div>
    <div data-body></div>`;
  const reload = () => render(el, ctx);
  $('[data-new-acc]', el).addEventListener('click', () => accountForm(null, reload));
  $('[data-transfer]', el).addEventListener('click', () => transferModal(reload));
  const body = $('[data-body]', el);
  if (tab === 'conexoes') return connections(body, reload);
  if (tab === 'categorias') return categories(body, reload);
  if (tab === 'config') return settings(body);
  return accounts(body, reload);
}

/* -------------------------------------------------------------- accounts */
async function accounts(el, reload) {
  const r = await api('/fh/fin/accounts');
  el.innerHTML = `
    <div class="fin-accounts">${r.data.map((a) => `<section class="card fin-acc ${Number(a.active) ? '' : 'off'}" data-id="${a.id}">
      <div class="card-head"><div><h3>${esc(a.name)}</h3><small class="muted">${esc(a.kind_label)}${a.bank_name ? ' · ' + esc(a.bank_name) : ''}${a.account_number ? ' · ' + esc(a.account_number) : ''}</small></div>
        ${a.connection_id ? `<span class="badge green" title="Extrato automático">${icon('link')} automática</span>` : Number(a.active) ? '' : '<span class="badge">inativa</span>'}</div>
      <div class="card-body"><div class="fin-acc-bal"><span>Saldo no sistema</span><b class="${a.balance < 0 ? 'neg' : ''}">${money(a.balance)}</b></div>
        <div class="fin-acc-meta">${a.stmt_balance !== null ? `<span>Extrato: <b>${money(a.stmt_balance)}</b>${a.stmt_balance_date ? ' em ' + date(a.stmt_balance_date) : ''}${Math.abs(a.stmt_balance - a.balance) > 0.009 ? ` <em class="neg">(diferença ${money(a.stmt_balance - a.balance)})</em>` : ' <em class="pos">✓</em>'}</span>` : '<span class="muted">Nenhum extrato importado ainda.</span>'}
          ${a.pending ? `<a href="#/financeiro/extrato?account=${a.id}">${a.pending} movimentação(ões) a conciliar</a>` : ''}${a.last_sync_at ? `<span class="muted">sincronizada ${datetime(a.last_sync_at)}</span>` : ''}</div></div>
      <div class="fh-card-actions"><a class="btn btn-sm" href="#/financeiro/extrato?account=${a.id}">${icon('list')} Extrato</a><button class="btn btn-sm" data-a="import">${icon('upload')} Importar OFX</button><button class="btn btn-sm btn-ghost" data-a="edit">${icon('edit')} Editar</button><button class="btn btn-sm btn-ghost" data-a="delete">${Number(a.active) ? icon('trash') : 'Reativar'}</button></div>
    </section>`).join('')}
      <button class="card fin-acc-new" data-new>${icon('plus')}<b>Adicionar conta</b><small>Banco, caixa, conta de pagamento ou cartão de crédito</small></button></div>
    <section class="card" style="margin-top:16px"><div class="card-head"><h3>Importações de extrato</h3></div>
      ${r.imports.length ? `<div class="table-wrap"><table class="dt cards"><thead><tr><th>Data</th><th>Conta</th><th>Origem</th><th>Período</th><th class="num">Novas</th><th class="num">Repetidas</th><th></th></tr></thead><tbody>
        ${r.imports.map((i) => `<tr><td data-label="Data">${datetime(i.created_at)}</td><td data-label="Conta">${esc(i.account_name)}</td><td data-label="Origem">${esc(i.file_name || i.source)}</td><td data-label="Período">${i.period_start ? date(i.period_start) + ' a ' + date(i.period_end) : '—'}</td><td class="num" data-label="Novas">${i.count_new}</td><td class="num" data-label="Repetidas">${i.count_dup}</td>
          <td class="actions">${['ofx', 'csv'].includes(i.source) ? `<button class="btn btn-xs btn-ghost" data-undo="${i.id}" title="Remover as movimentações desta importação ainda não conciliadas">${icon('undo')} Desfazer</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
        : emptyState('Nenhuma importação ainda.', 'upload')}</section>`;
  $('[data-new]', el).addEventListener('click', () => accountForm(null, reload));
  el.addEventListener('click', async (e) => {
    const undo = e.target.closest('[data-undo]');
    if (undo) {
      if (!needEdit() || !await confirmDialog('Desfazer esta importação? As movimentações ainda não conciliadas dela serão removidas.', { danger: true, okLabel: 'Desfazer' })) return;
      try { const x = await api('/fh/fin/imports/' + undo.dataset.undo, { method: 'DELETE' }); toast(`${x.removed} movimentação(ões) removida(s).`); reload(); } catch (err) { toastError(err); }
      return;
    }
    const b = e.target.closest('[data-a]');
    if (!b) return;
    const a = r.data.find((x) => x.id === +b.closest('[data-id]').dataset.id);
    if (b.dataset.a === 'import') importModal(a.id, () => { location.hash = '#/financeiro/extrato?account=' + a.id; });
    if (b.dataset.a === 'edit') accountForm(a, reload);
    if (b.dataset.a === 'delete') {
      if (!needEdit()) return;
      try {
        if (!Number(a.active)) { await api('/fh/fin/accounts/' + a.id, { method: 'PUT', body: { active: true } }); toast('Conta reativada.'); reload(); return; }
        if (!await confirmDialog(`Excluir a conta "${a.name}"? Se ela tiver lançamentos, será apenas desativada.`, { danger: true, okLabel: 'Excluir' })) return;
        const x = await api('/fh/fin/accounts/' + a.id, { method: 'DELETE' });
        toast(x.result === 'deleted' ? 'Conta excluída.' : 'A conta tem movimentações: foi desativada.');
        reload();
      } catch (err) { toastError(err); }
    }
  });
}

function accountForm(a, onDone) {
  if (!needEdit()) return;
  const kinds = Object.entries(fin.boot.account_kinds).map(([value, label]) => ({ value, label }));
  formModal({
    title: a ? 'Editar conta' : 'Nova conta', values: a || { kind: 'checking', opening_date: today().slice(0, 8) + '01', opening_balance: 0 },
    fields: [
      { name: 'name', label: 'Nome', required: true, placeholder: 'Ex.: Itaú PJ, Caixa da loja' },
      { name: 'kind', label: 'Tipo', type: 'select', empty: false, options: kinds },
      { name: 'bank_name', label: 'Banco', placeholder: 'opcional' },
      { name: 'bank_code', label: 'Código do banco', placeholder: 'ex.: 341' },
      { name: 'agency', label: 'Agência' },
      { name: 'account_number', label: 'Conta' },
      { name: 'opening_balance', label: 'Saldo inicial', type: 'number', step: '0.01', help: 'Saldo da conta no início da data abaixo.' },
      { name: 'opening_date', label: 'Saldo inicial em', type: 'date' },
    ],
    onSubmit: async (d) => {
      await api(a ? '/fh/fin/accounts/' + a.id : '/fh/fin/accounts', { method: a ? 'PUT' : 'POST', body: d });
      toast(a ? 'Conta atualizada.' : 'Conta criada.');
      fin.invalidate();
      onDone();
    },
  });
}

/* ----------------------------------------------------------- connections */
async function connections(el, reload) {
  const r = await api('/fh/fin/connections');
  const conns = r.data;
  const connCard = (c) => `<li data-c="${c.id}"><span class="fin-prov ${c.provider}">${icon(c.provider === 'inter' || c.provider === 'asaas' ? 'bank' : 'link')}</span>
    <div class="grow"><b>${esc(c.label || c.connector_name || c.provider_name)}</b><small>${esc(c.provider_name)}${c.last_sync_at ? ' · sincronizada ' + datetime(c.last_sync_at) : ''}${c.consent_expires_at ? ` · ${c.provider === 'inter' ? 'certificado' : 'autorização'} até ${date(c.consent_expires_at)}` : ''}${c.accounts.length ? ' · ' + c.accounts.map((a) => esc(a.name)).join(', ') : ''}</small>
      ${c.error_message ? `<small class="neg">${esc(c.error_message)}</small>` : ''}</div>
    <div class="fin-actions"><button class="btn btn-xs" data-sync="${c.id}">${icon('refresh')} Sincronizar</button>${c.provider === 'pluggy' || c.provider === 'meupluggy' ? `<button class="btn btn-xs btn-ghost" data-reconnect="${c.id}">Reconectar</button>` : `<button class="btn btn-xs btn-ghost" data-edit="${c.id}">Atualizar credenciais</button>`}<button class="btn btn-xs btn-ghost" data-del="${c.id}" title="Remover conexão">${icon('trash')}</button></div></li>`;
  el.innerHTML = `
    <div class="alert alert-info small">As conexões trazem o extrato automaticamente todos os dias. Sua senha do banco <b>nunca</b> passa pelo Fiscal Hub: você autoriza no próprio banco ou gera uma credencial de API, que fica criptografada.</div>
    ${conns.length ? `<section class="card" style="margin-bottom:16px"><div class="card-head"><h3>Suas conexões</h3></div><ul class="list fin-conns">${conns.map(connCard).join('')}</ul></section>` : ''}
    <div class="fin-providers">
      <section class="card fin-provider"><div class="card-head"><div><h3>${icon('link')} Open Finance — Meu Pluggy</h3><small class="muted">Qualquer banco do Open Finance</small></div><span class="badge green">grátis</span></div>
        <div class="card-body">
          <ol class="fin-steps">
            <li>Crie sua conta grátis em <a href="https://meu.pluggy.ai" target="_blank" rel="noopener">meu.pluggy.ai</a> e conecte os bancos da empresa (a autorização é feita no app do banco).</li>
            <li>Em <a href="https://dashboard.pluggy.ai" target="_blank" rel="noopener">dashboard.pluggy.ai</a>, crie uma aplicação de <b>Desenvolvimento</b> e inclua o conector <b>MeuPluggy</b> na lista de conectores (Personalizar).</li>
            <li>Copie o <b>Client ID</b> e o <b>Client Secret</b> da aplicação e cole abaixo.</li>
            <li>Clique em <b>Conectar banco</b> e autorize o MeuPluggy uma vez para cada banco.</li>
          </ol>
          ${r.meupluggy.configured
            ? `<p class="small" style="margin:0 0 10px">Aplicação cadastrada: <b>${esc(r.meupluggy.client_id)}</b> <button class="btn btn-xs btn-ghost" data-mp-del>Remover credenciais</button></p>
               <div class="fin-actions"><button class="btn btn-primary" data-mp-connect>${icon('link')} Conectar banco</button><button class="btn" data-mp-item>Colar ID da conexão</button></div>`
            : `<form class="form-grid" data-mp-form><div class="field"><label>Client ID</label><input name="client_id" autocomplete="off" class="mono"></div><div class="field"><label>Client Secret</label><input name="client_secret" type="password" autocomplete="new-password" class="mono"></div>
               <div class="span-2"><button class="btn btn-primary">${icon('check')} Salvar e validar</button></div></form>`}
          <p class="small muted" style="margin:10px 0 0">O Meu Pluggy é gratuito para acessar os seus próprios dados. As contas conectadas lá são atualizadas a cada 24 horas.</p>
        </div></section>
      <section class="card fin-provider"><div class="card-head"><div><h3>${icon('bank')} Banco Inter PJ — API oficial</h3><small class="muted">Extrato e saldo direto do Inter Empresas</small></div><span class="badge green">grátis</span></div>
        <div class="card-body">
          <ol class="fin-steps">
            <li>No Internet Banking do <b>Inter Empresas</b>: Soluções para sua empresa → <b>Nova integração</b> (API).</li>
            <li>Marque a permissão <b>Consultar extrato e saldo</b> e crie a integração.</li>
            <li>Baixe o <b>certificado (.crt)</b> e a <b>chave (.key)</b> e copie o Client ID e o Client Secret.</li>
          </ol>
          <button class="btn btn-primary" data-inter>${icon('plus')} Conectar Banco Inter</button>
          <p class="small muted" style="margin:10px 0 0">O certificado do Inter vale 1 ano. Avisamos quando estiver perto de vencer.</p>
        </div></section>
      <section class="card fin-provider"><div class="card-head"><div><h3>${icon('wallet')} Asaas — sua conta</h3><small class="muted">Cobranças recebidas, taxas e transferências</small></div><span class="badge green">grátis</span></div>
        <div class="card-body">
          <ol class="fin-steps"><li>No Asaas: Configurações da conta → <b>Integrações</b> → gerar <b>chave de API</b>.</li><li>Cole a chave abaixo (começa com <code>$aact_</code>).</li></ol>
          <button class="btn btn-primary" data-asaas>${icon('plus')} Conectar Asaas</button>
        </div></section>
      ${r.openfinance.enabled ? `<section class="card fin-provider"><div class="card-head"><div><h3>${icon('sparkles')} Open Finance Integra</h3><small class="muted">Sem configuração: escolha o banco e autorize</small></div>${r.openfinance.allowed ? '<span class="badge blue">incluso no plano</span>' : '<span class="badge">planos superiores</span>'}</div>
        <div class="card-body"><p class="small" style="margin:0 0 12px">Conexão direta pelo agregador da Integra Code, sem criar contas em outros serviços.</p>
          ${r.openfinance.allowed ? `<button class="btn btn-primary" data-of-connect>${icon('link')} Conectar banco</button>` : '<a class="btn" href="#/assinatura">Ver planos</a>'}</div></section>` : ''}
      <section class="card fin-provider"><div class="card-head"><div><h3>${icon('upload')} Qualquer banco — arquivo OFX</h3><small class="muted">Itaú, Bradesco, BB, Caixa, Santander, Sicoob, Nubank, C6...</small></div><span class="badge green">grátis</span></div>
        <div class="card-body"><p class="small" style="margin:0 0 12px">Baixe o extrato em OFX no internet banking e importe. Movimentações repetidas são ignoradas.</p><button class="btn" data-ofx>${icon('upload')} Importar OFX/CSV</button></div></section>
    </div>`;

  const done = () => { fin.invalidate(); reload(); };
  $('[data-ofx]', el).addEventListener('click', () => importModal(null, (x, accId) => { location.hash = '#/financeiro/extrato?account=' + accId; }));
  $('[data-mp-form]', el)?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!needEdit()) return;
    const f = e.currentTarget;
    const b = $('button', f);
    b.classList.add('loading');
    try { await api('/fh/fin/meupluggy', { method: 'PUT', body: { client_id: f.client_id.value, client_secret: f.client_secret.value } }); toast('Credenciais validadas. Agora conecte seus bancos.'); done(); } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
  $('[data-mp-del]', el)?.addEventListener('click', async () => {
    if (!needEdit() || !await confirmDialog('Remover as credenciais do Meu Pluggy? As conexões feitas com elas param de sincronizar.', { danger: true, okLabel: 'Remover' })) return;
    await api('/fh/fin/meupluggy', { method: 'DELETE' }).catch(toastError);
    done();
  });
  $('[data-mp-connect]', el)?.addEventListener('click', (e) => pluggyConnect('meupluggy', r.script, null, e.currentTarget, done));
  $('[data-of-connect]', el)?.addEventListener('click', (e) => pluggyConnect('pluggy', r.script, null, e.currentTarget, done));
  $('[data-mp-item]', el)?.addEventListener('click', () => {
    if (!needEdit()) return;
    formModal({
      title: 'Colar o ID da conexão (Meu Pluggy)', intro: '<p class="small muted" style="margin:0">No dashboard.pluggy.ai, abra a aplicação → Items e copie o ID da conexão com o MeuPluggy.</p>',
      fields: [{ name: 'item_id', label: 'Item ID', required: true }],
      onSubmit: async (d) => { const x = await api('/fh/fin/openfinance/items', { method: 'POST', body: { item_id: d.item_id.trim(), provider: 'meupluggy' } }); toast(`Conectado! ${x.new} movimentação(ões) importada(s).`); done(); },
    });
  });
  $('[data-inter]', el).addEventListener('click', () => interForm(null, done));
  $('[data-asaas]', el).addEventListener('click', () => asaasForm(null, done));
  $('.fin-conns', el)?.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b || !needEdit()) return;
    const c = conns.find((x) => x.id === +(b.dataset.sync || b.dataset.del || b.dataset.reconnect || b.dataset.edit));
    if (!c) return;
    try {
      if (b.dataset.sync) { b.classList.add('loading'); const x = await api(`/fh/fin/connections/${c.id}/sync`, { method: 'POST' }); toast(`${x.new} nova(s) movimentação(ões).`); done(); }
      if (b.dataset.reconnect) pluggyConnect(c.provider, r.script, c, b, done);
      if (b.dataset.edit) (c.provider === 'inter' ? interForm : asaasForm)(c, done);
      if (b.dataset.del && await confirmDialog('Remover esta conexão? As contas e o extrato já importado continuam no sistema.', { danger: true, okLabel: 'Remover' })) { await api('/fh/fin/connections/' + c.id, { method: 'DELETE' }); toast('Conexão removida.'); done(); }
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
}

/** Load the Pluggy Connect widget, open it and register the connection created. */
async function pluggyConnect(provider, script, conn, btn, onDone) {
  if (!needEdit()) return;
  btn.classList.add('loading');
  try {
    const t = await api('/fh/fin/openfinance/token', { method: 'POST', body: { provider, connection_id: conn?.id } });
    if (!window.PluggyConnect) {
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = script;
        s.onload = resolve;
        s.onerror = () => reject(new Error('Não foi possível carregar a janela do Open Finance. Verifique sua conexão ou bloqueadores de anúncio.'));
        document.head.appendChild(s);
      });
    }
    const opts = {
      connectToken: t.access_token, includeSandbox: false, language: 'pt', theme: document.documentElement.dataset.theme === 'light' ? 'light' : 'dark',
      onSuccess: async (data) => {
        const itemId = data?.item?.id;
        if (!itemId) return;
        try { const x = await api('/fh/fin/openfinance/items', { method: 'POST', body: { item_id: itemId, provider } }); toast(`Banco conectado! ${x.new} movimentação(ões) importada(s).`); onDone(); } catch (err) { toastError(err); }
      },
      onError: (err) => toast('Open Finance: ' + (err?.message || 'a conexão não foi concluída.'), 'error'),
    };
    if (t.connector_ids?.length) opts.connectorIds = t.connector_ids;
    if (conn?.item_id) opts.updateItem = conn.item_id;
    const widget = new window.PluggyConnect(opts);
    await widget.init();
  } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
}

function fileText(input) {
  const f = input.files[0];
  return f ? f.text() : Promise.resolve('');
}

function interForm(conn, onDone) {
  if (!needEdit()) return;
  const m = modal({
    title: conn ? 'Atualizar credenciais do Banco Inter' : 'Conectar Banco Inter PJ', size: 'lg',
    body: `<form class="form-grid" data-f novalidate>
      <div class="field"><label>Client ID</label><input name="client_id" class="mono" autocomplete="off" ${conn ? 'placeholder="(mantido)"' : ''}></div>
      <div class="field"><label>Client Secret</label><input name="client_secret" type="password" class="mono" autocomplete="new-password" ${conn ? 'placeholder="(mantido)"' : ''}></div>
      <div class="field"><label>Certificado (.crt)</label><input name="cert" type="file" accept=".crt,.pem,.cer"></div>
      <div class="field"><label>Chave (.key)</label><input name="key" type="file" accept=".key,.pem"></div>
      <div class="field"><label>Conta corrente (opcional)</label><input name="account" inputmode="numeric" placeholder="só se tiver mais de uma conta no Inter"></div>
      <div class="field"><label>Nome da conta no Fiscal Hub</label><input name="account_name" value="Banco Inter PJ"></div>
    </form><p class="small muted" style="margin:10px 0 0">Buscamos o extrato dos últimos 90 dias e depois atualizamos todo dia.</p>`,
    footer: `<button class="btn" data-close>Cancelar</button><button class="btn btn-primary" data-go>${icon('check')} Validar e conectar</button>`,
  });
  $('[data-go]', m.el).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    const f = $('[data-f]', m.el).elements;
    b.classList.add('loading');
    try {
      const x = await api('/fh/fin/connections/inter', { method: 'POST', body: { connection_id: conn?.id, client_id: f.client_id.value, client_secret: f.client_secret.value, cert: await fileText(f.cert), key: await fileText(f.key), account: f.account.value, account_name: f.account_name.value } });
      m.close();
      toast(`Banco Inter conectado! ${x.new} movimentação(ões) importada(s).`);
      onDone();
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
}

function asaasForm(conn, onDone) {
  if (!needEdit()) return;
  formModal({
    title: conn ? 'Atualizar chave do Asaas' : 'Conectar conta Asaas', values: { env: 'production', account_name: 'Conta Asaas' },
    fields: [
      { name: 'api_key', label: 'Chave de API', required: !conn, span: 2, placeholder: conn ? '(mantida)' : '$aact_...' },
      { name: 'env', label: 'Ambiente', type: 'select', empty: false, options: [{ value: 'production', label: 'Produção' }, { value: 'sandbox', label: 'Sandbox (testes)' }] },
      { name: 'account_name', label: 'Nome da conta no Fiscal Hub' },
    ],
    onSubmit: async (d) => { const x = await api('/fh/fin/connections/asaas', { method: 'POST', body: { ...d, connection_id: conn?.id } }); toast(`Asaas conectado! ${x.new} movimentação(ões) importada(s).`); onDone(); },
  });
}

/* ---------------------------------------------------- categories and rules */
async function categories(el, reload) {
  const [cats, rules] = await Promise.all([api('/fh/fin/categories'), api('/fh/fin/rules')]);
  const groups = fin.boot.dre_groups;
  const col = (kind, title) => `<section class="card"><div class="card-head"><h3>${title}</h3><button class="btn btn-sm" data-add="${kind}">${icon('plus')} Nova</button></div>
    <ul class="list fin-cats">${cats.data.filter((c) => c.kind === kind).map((c) => `<li data-id="${c.id}" class="${Number(c.active) ? '' : 'off'}"><div class="grow"><b>${esc(c.name)}</b><small>${esc(groups[c.dre_group] || '—')} · ${c.uses} lançamento(s)${Number(c.active) ? '' : ' · inativa'}</small></div>
      <button class="btn btn-xs btn-ghost" data-edit>${icon('edit')}</button><button class="btn btn-xs btn-ghost" data-del>${Number(c.active) ? icon('trash') : 'Reativar'}</button></li>`).join('')}</ul></section>`;
  el.innerHTML = `<div class="grid g2">${col('income', 'Receitas')}${col('expense', 'Despesas')}</div>
    <section class="card" style="margin-top:16px"><div class="card-head"><h3>Regras de conciliação</h3></div>
      ${rules.data.length ? `<ul class="list">${rules.data.map((r) => `<li data-rule="${r.id}"><div class="grow"><b>"${esc(r.match_text)}"</b><small>${r.direction === 'in' ? 'entradas' : 'saídas'} → ${esc(r.category_name || '—')}${r.party_name ? ' · ' + esc(r.party_name) : ''} · usada ${r.hits}x</small></div><button class="btn btn-xs btn-ghost" data-rdel>${icon('trash')}</button></li>`).join('')}</ul>`
        : emptyState('As regras aparecem quando você lança uma movimentação do extrato marcando "Lembrar".', 'bolt')}</section>`;
  el.addEventListener('click', async (e) => {
    const add = e.target.closest('[data-add]');
    const li = e.target.closest('[data-id]');
    const rule = e.target.closest('[data-rule]');
    try {
      if (add) return catForm(null, add.dataset.add, reload);
      if (rule && e.target.closest('[data-rdel]')) { if (!needEdit()) return; await api('/fh/fin/rules/' + rule.dataset.rule, { method: 'DELETE' }); reload(); return; }
      if (!li) return;
      const c = cats.data.find((x) => x.id === +li.dataset.id);
      if (e.target.closest('[data-edit]')) return catForm(c, c.kind, reload);
      if (e.target.closest('[data-del]')) {
        if (!needEdit()) return;
        if (!Number(c.active)) { await api('/fh/fin/categories/' + c.id, { method: 'PUT', body: { active: true } }); reload(); return; }
        if (!await confirmDialog(`Excluir a categoria "${c.name}"? Se já tiver lançamentos, ela só será desativada.`, { danger: true, okLabel: 'Excluir' })) return;
        const x = await api('/fh/fin/categories/' + c.id, { method: 'DELETE' });
        toast(x.result === 'deleted' ? 'Categoria excluída.' : 'Categoria desativada (tem lançamentos).');
        fin.invalidate();
        reload();
      }
    } catch (err) { toastError(err); }
  });
}

function catForm(c, kind, onDone) {
  if (!needEdit()) return;
  const groups = Object.entries(fin.boot.dre_groups).map(([value, label]) => ({ value, label }));
  formModal({
    title: c ? 'Editar categoria' : kind === 'income' ? 'Nova categoria de receita' : 'Nova categoria de despesa', values: c || { dre_group: kind === 'income' ? 'revenue' : 'expense' },
    fields: [{ name: 'name', label: 'Nome', required: true }, { name: 'dre_group', label: 'Grupo no DRE', type: 'select', empty: false, options: groups }],
    onSubmit: async (d) => { await api(c ? '/fh/fin/categories/' + c.id : '/fh/fin/categories', { method: c ? 'PUT' : 'POST', body: { ...d, kind } }); toast('Categoria salva.'); fin.invalidate(); onDone(); },
  });
}

/* ---------------------------------------------------------------- settings */
async function settings(el) {
  const s = await api('/fh/fin/settings');
  const opts = (list, sel) => list.map((x) => `<option value="${x.id}" ${String(sel) === String(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
  el.innerHTML = `<form class="card" data-f><div class="card-head"><h3>Notas fiscais → contas a receber</h3></div><div class="card-body form-grid">
      <label class="check span-2"><input type="checkbox" name="auto_receivable" ${s.auto_receivable ? 'checked' : ''}> Criar uma conta a receber automaticamente para cada NFS-e emitida</label>
      <label class="check span-2"><input type="checkbox" name="use_net_amount" ${s.use_net_amount ? 'checked' : ''}> Usar o valor líquido da nota (descontando ISS e tributos retidos pelo cliente)</label>
      <div class="field"><label>Vencimento: dias após a emissão</label><input type="number" name="receivable_days" min="0" max="180" value="${esc(s.receivable_days)}"></div>
      <div class="field"><label>Conta prevista para o recebimento</label><select name="receivable_account_id"><option value="">Definir na baixa</option>${opts(fin.accounts(), s.receivable_account_id)}</select></div>
      <div class="field"><label>Categoria</label><select name="receivable_category_id"><option value="">Venda de serviços (padrão)</option>${opts(fin.cats('income'), s.receivable_category_id)}</select></div>
    </div><div class="fh-card-actions" style="justify-content:space-between"><button type="button" class="btn" data-backfill>${icon('file')} Criar para as notas já emitidas</button><button class="btn btn-primary">${icon('check')} Salvar</button></div></form>`;
  const f = $('[data-f]', el);
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!needEdit()) return;
    try {
      await api('/fh/fin/settings', { method: 'PUT', body: { auto_receivable: f.auto_receivable.checked, use_net_amount: f.use_net_amount.checked, receivable_days: f.receivable_days.value, receivable_account_id: f.receivable_account_id.value, receivable_category_id: f.receivable_category_id.value } });
      toast('Configurações salvas.');
    } catch (err) { toastError(err); }
  });
  $('[data-backfill]', el).addEventListener('click', async (e) => {
    if (!needEdit()) return;
    const b = e.currentTarget;
    b.classList.add('loading');
    try { const r = await api('/fh/fin/invoices/import', { method: 'POST' }); toast(r.created ? `${r.created} conta(s) a receber criada(s).` : 'Nenhuma nota pendente (últimos 12 meses).'); } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
}
