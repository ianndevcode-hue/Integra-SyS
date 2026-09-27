/* Recurring invoices and batch emission from a spreadsheet (plan features). */
import { api, $, esc, icon, money, date, formModal, confirmDialog, toast, toastError, emptyState, today } from '/admin/js/core.js';
import { fh } from '/assets/fiscal/state.js';

const locked = (el, what) => {
  el.innerHTML = `<div class="page-head"><div><h2>${what}</h2></div></div>${emptyState(`${what} está disponível nos planos superiores.`, 'lock', '<a class="btn btn-primary" href="#/assinatura">Ver planos</a>')}`;
};

export async function render(el, ctx) {
  const em = fh.emitter();
  if (!em) { el.innerHTML = emptyState('Cadastre sua empresa primeiro.', 'settings', '<a class="btn btn-primary" href="#/empresa/nova">Cadastrar empresa</a>'); return; }
  if (ctx.sub === 'batch') return fh.flag('batch') ? batch(el, em) : locked(el, 'Emissão em lote');
  return fh.flag('recurring') ? recurring(el, em) : locked(el, 'Notas recorrentes');
}

/* --------------------------------------------------------------- recurring */
async function recurring(el, em) {
  el.innerHTML = `<div class="page-head"><div><h2>Notas recorrentes</h2><p>Mensalidades e contratos: o Fiscal Hub emite e envia a nota automaticamente todo mês.</p></div>
    <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Nova recorrência</button></div></div><div data-list></div>`;
  const load = async () => {
    const rows = (await api('/fh/recurring')).data;
    $('[data-list]', el).innerHTML = rows.length ? `<div class="card"><div class="table-wrap"><table class="dt cards"><thead><tr><th>Cliente</th><th>Serviço</th><th class="num">Valor</th><th>Todo dia</th><th>Próxima emissão</th><th>Situação</th><th></th></tr></thead><tbody>
      ${rows.map((r) => `<tr data-id="${r.id}"><td class="primary" data-label="Cliente"><b>${esc(r.taker_name || '—')}</b><br><small class="muted">${esc(r.emitter_name)}</small></td><td data-label="Serviço">${esc(r.service_name || (r.description || '').slice(0, 40))}</td><td class="num" data-label="Valor">${money(r.amount)}</td><td data-label="Dia">${r.day_of_month}</td>
        <td data-label="Próxima">${date(r.next_run)}${r.last_run ? `<br><small class="muted">última: ${date(r.last_run)}</small>` : ''}</td><td data-label="Situação">${Number(r.active) ? '<span class="badge green">Ativa</span>' : '<span class="badge">Pausada</span>'}</td>
        <td class="actions"><button class="btn btn-xs" data-edit>${icon('edit')}</button><button class="btn btn-xs btn-danger" data-del>${icon('trash')}</button></td></tr>`).join('')}</tbody></table></div></div>`
      : emptyState('Nenhuma nota recorrente. Cadastre mensalidades para emitir automaticamente.', 'refresh');
    $('[data-list]', el).onclick = async (e) => {
      const tr = e.target.closest('[data-id]');
      if (!tr) return;
      const r = rows.find((x) => x.id === +tr.dataset.id);
      if (e.target.closest('[data-edit]')) form(r);
      if (e.target.closest('[data-del]') && await confirmDialog('Excluir esta recorrência?', { danger: true })) { await api('/fh/recurring/' + r.id, { method: 'DELETE' }).catch(toastError); load(); }
    };
  };
  const form = async (r) => {
    const [takers, services] = await Promise.all([fh.takers(), fh.services()]);
    if (!takers.length) { toast('Cadastre o cliente antes de criar a recorrência.', 'error'); location.hash = '#/clientes'; return; }
    if (!services.some((s) => Number(s.active))) { toast('Cadastre o serviço (com o item da LC 116) antes de criar a recorrência.', 'error'); location.hash = '#/servicos'; return; }
    formModal({
      title: r ? 'Editar recorrência' : 'Nova nota recorrente', size: 'lg', values: r || { day_of_month: 5, active: true, description: 'Mensalidade referente a {mes_ano}' },
      intro: '<p class="small muted" style="margin:0">Use <b>{mes}</b>, <b>{ano}</b>, <b>{mes_ano}</b> ou <b>{mm/aaaa}</b> na descrição: trocamos pelo mês da emissão.</p>',
      fields: [
        { name: 'taker_id', label: 'Cliente', type: 'select', required: true, span: 2, options: takers.map((t) => ({ value: t.id, label: t.name })) },
        { name: 'service_id', label: 'Serviço', type: 'select', required: true, options: services.filter((s) => Number(s.active)).map((s) => ({ value: s.id, label: s.name })) },
        { name: 'amount', label: 'Valor (R$)', type: 'money', required: true },
        { name: 'description', label: 'Discriminação', type: 'textarea', rows: 3, span: 2 },
        { name: 'day_of_month', label: 'Emitir todo dia', type: 'number', min: 1, max: 28 },
        { name: 'next_run', label: 'Próxima emissão', type: 'date', help: 'Em branco = próximo dia escolhido.' },
        { name: 'end_date', label: 'Encerrar em (opcional)', type: 'date' },
        { name: 'active', label: 'Ativa', type: 'checkbox' },
      ],
      onSubmit: async (d) => { await api(r ? '/fh/recurring/' + r.id : '/fh/recurring', { method: r ? 'PUT' : 'POST', body: { ...d, emitter_id: em.id } }); toast('Recorrência salva.'); load(); },
    });
  };
  $('[data-new]', el).addEventListener('click', () => form(null));
  await load();
}

/* ------------------------------------------------------------------- batch */
function batch(el, em) {
  const tpl = 'documento;nome;email;valor;descricao;servico;competencia;cep;numero\n11222333000181;Empresa Exemplo Ltda;financeiro@exemplo.com.br;1500,00;Consultoria referente a setembro/2026;17.01;09/2026;17500000;100\n';
  el.innerHTML = `<div class="page-head"><div><h2>Emissão em lote</h2><p>Importe uma planilha, revise os rascunhos e emita tudo de uma vez.</p></div>
    <div class="page-actions"><a class="btn" data-tpl download="modelo-notas-fiscal-hub.csv">${icon('download')} Baixar modelo</a></div></div>
    <div class="grid g2">
      <section class="card"><div class="card-head"><h3>1. Planilha</h3></div><div class="card-body">
        <p class="small muted" style="margin:0 0 10px">CSV separado por ponto e vírgula (salve no Excel como "CSV UTF-8"). Colunas obrigatórias: <b>documento, nome, valor</b>. Opcionais: email, descricao, servico (nome, código interno ou item da LC 116), competencia (mm/aaaa), cep, numero. Clientes novos são cadastrados automaticamente.</p>
        <input type="file" accept=".csv,text/csv" data-file class="input">
        <textarea class="input" rows="8" data-csv placeholder="ou cole aqui o conteúdo da planilha" style="margin-top:10px;font-family:monospace;font-size:.8rem"></textarea>
        <button class="btn btn-primary" data-import style="margin-top:10px">${icon('upload')} Importar como rascunhos</button>
      </div></section>
      <section class="card"><div class="card-head"><h3>2. Revisar e emitir</h3></div><div class="card-body" data-result><p class="muted small" style="margin:0">Os rascunhos importados aparecem aqui.</p></div></section>
    </div>`;
  $('[data-tpl]', el).href = URL.createObjectURL(new Blob(['﻿' + tpl], { type: 'text/csv;charset=utf-8' }));
  $('[data-file]', el).addEventListener('change', async (e) => { const f = e.target.files[0]; if (f) $('[data-csv]', el).value = await f.text(); });
  $('[data-import]', el).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.classList.add('loading');
    try {
      const r = await api('/fh/batch/import', { method: 'POST', body: { emitter_id: em.id, csv: $('[data-csv]', el).value } });
      fh.cache.takers = {};
      const errs = Object.entries(r.errors);
      $('[data-result]', el).innerHTML = `<p style="margin:0 0 10px"><b>${r.created.length}</b> rascunho(s) criado(s)${errs.length ? `, <span class="neg">${errs.length} linha(s) com erro</span>` : ''}.</p>
        ${errs.length ? `<ul class="list small">${errs.map(([line, msg]) => `<li><div class="grow"><b>Linha ${line}</b><small>${esc(msg)}</small></div></li>`).join('')}</ul>` : ''}
        ${r.created.length ? `<button class="btn btn-success" data-emit-all>${icon('send')} Emitir as ${r.created.length} nota(s)</button> <a class="btn" href="#/notas?status=draft">Revisar rascunhos</a><div data-progress style="margin-top:12px"></div>` : ''}`;
      $('[data-emit-all]', el)?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        if (!await confirmDialog(`Emitir ${r.created.length} nota(s) agora?`, { okLabel: 'Emitir' })) return;
        btn.classList.add('loading');
        const prog = $('[data-progress]', el);
        let ok = 0; const fails = [];
        for (let i = 0; i < r.created.length; i += 20) {
          const chunk = r.created.slice(i, i + 20);
          prog.innerHTML = `<div class="progress"><i style="width:${(i / r.created.length) * 100}%"></i></div><small class="muted">Emitindo ${i + 1}–${Math.min(i + 20, r.created.length)} de ${r.created.length}...</small>`;
          try {
            const out = await api('/fh/batch/transmit', { method: 'POST', body: { ids: chunk } });
            ok += out.ok.length; fails.push(...out.errors);
          } catch (err) { fails.push({ id: '-', error: err.message }); break; }
        }
        btn.classList.remove('loading');
        prog.innerHTML = `<div class="alert ${fails.length ? 'alert-warning' : 'alert-success'}" style="margin:0"><b>${ok}</b> nota(s) emitida(s).${fails.length ? `<ul style="margin:6px 0 0;padding-left:18px">${fails.map((f) => `<li>#${f.id}: ${esc(f.error)}</li>`).join('')}</ul>` : ''}</div>`;
        window.dispatchEvent(new Event('fh:company'));
      });
    } catch (err) { toastError(err); } finally { b.classList.remove('loading'); }
  });
}
