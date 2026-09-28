/* Recurring invoices and batch emission from a spreadsheet (plan features). */
import { api, $, $$, esc, icon, money, date, formModal, modal, confirmDialog, toast, toastError, emptyState, today, debounce, downloadUrl } from '/admin/js/core.js';
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
const INTERVALS = { 1: 'Mensal', 2: 'Bimestral', 3: 'Trimestral', 6: 'Semestral', 12: 'Anual' };
const daysTo = (d) => Math.round((new Date(d + 'T12:00:00') - new Date(today() + 'T12:00:00')) / 86400000);
const whenLabel = (d) => { const n = daysTo(d); return n < 0 ? `atrasada ${-n} dia(s)` : n === 0 ? 'hoje' : n === 1 ? 'amanhã' : `em ${n} dias`; };
const INV_BADGE = { authorized: ['green', 'Emitida'], rejected: ['red', 'Rejeitada'], canceled: ['', 'Cancelada'], draft: ['yellow', 'Rascunho'], processing: ['blue', 'Transmitindo'], voided: ['', 'Inutilizada'] };
const PLACEHOLDERS = [['{mes_ano}', 'mês/ano'], ['{mes}', 'mês'], ['{ano}', 'ano'], ['{mm/aaaa}', 'mm/aaaa'], ['{data_vencimento}', 'data de vencimento']];

async function recurring(el, em) {
  let rows = [];
  let filter = 'all';
  let q = '';
  el.innerHTML = `<div class="page-head"><div><h2>Notas recorrentes</h2><p>Mensalidades e contratos: o Fiscal Hub emite a nota no dia certo e envia ao cliente automaticamente.</p></div>
      <div class="page-actions"><button class="btn btn-primary" data-new>${icon('plus')} Nova recorrência</button></div></div>
    <div class="grid g4 fh-rec-kpis" data-kpis></div>
    <div class="fh-rec-layout">
      <div>
        <div class="fh-rec-toolbar"><div class="chips-row" data-filters></div><input type="search" data-q placeholder="Buscar cliente ou serviço..." aria-label="Buscar recorrência"></div>
        <div data-list></div>
      </div>
      <aside class="card fh-rec-next"><div class="card-head"><h3>${icon('calendar')} Próximas emissões</h3></div><div data-next></div></aside>
    </div>`;

  const draw = () => {
    const active = rows.filter((r) => Number(r.active));
    const mrr = active.reduce((t, r) => t + Number(r.amount) / (Number(r.interval_months) || 1), 0);
    const in30 = active.filter((r) => daysTo(r.next_run) <= 30);
    const failing = rows.filter((r) => r.last_invoice?.status === 'rejected');
    $('[data-kpis]', el).innerHTML = `
      <div class="card kpi"><div class="k-label"><span class="k-ico">${icon('refresh')}</span>Recorrências ativas</div><div class="k-value">${active.length}</div><div class="k-sub">${rows.length - active.length} pausada(s)</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico green">${icon('trendUp')}</span>Receita recorrente / mês</div><div class="k-value">${money(mrr)}</div><div class="k-sub">${money(mrr * 12)} por ano</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico blue">${icon('calendar')}</span>Próximos 30 dias</div><div class="k-value">${in30.length} nota(s)</div><div class="k-sub">${money(in30.reduce((t, r) => t + Number(r.amount), 0))} a faturar</div></div>
      <div class="card kpi"><div class="k-label"><span class="k-ico ${failing.length ? 'red' : 'violet'}">${icon(failing.length ? 'alert' : 'check')}</span>Última emissão</div><div class="k-value">${failing.length ? failing.length + ' com erro' : 'Tudo certo'}</div><div class="k-sub">${failing.length ? 'veja os avisos nos cartões' : 'nenhuma rejeição pendente'}</div></div>`;
    const counts = { all: rows.length, active: active.length, paused: rows.length - active.length, error: failing.length };
    $('[data-filters]', el).innerHTML = [['all', 'Todas'], ['active', 'Ativas'], ['paused', 'Pausadas'], ['error', 'Com erro']]
      .map(([k, l]) => `<button class="fh-chip ${filter === k ? 'on' : ''}" data-f="${k}">${l} <b>${counts[k]}</b></button>`).join('');
    const list = rows.filter((r) => (filter === 'all' || (filter === 'active' && Number(r.active)) || (filter === 'paused' && !Number(r.active)) || (filter === 'error' && r.last_invoice?.status === 'rejected'))
      && (!q || `${r.taker_name} ${r.service_name} ${r.description}`.toLowerCase().includes(q)));
    $('[data-list]', el).innerHTML = !rows.length
      ? `<div class="card fh-rec-empty">${icon('refresh')}<h3>Automatize suas mensalidades</h3><p class="muted">Cadastre um contrato uma vez e o Fiscal Hub emite e envia a nota todo mês, no dia combinado. Você acompanha tudo por aqui.</p><button class="btn btn-primary" data-new>${icon('plus')} Criar a primeira recorrência</button></div>`
      : list.length ? `<div class="fh-rec-grid">${list.map(card).join('')}</div>` : emptyState('Nenhuma recorrência neste filtro.', 'search');
    const events = active.flatMap((r) => (r.schedule || []).map((d) => ({ d, r }))).sort((a, b) => a.d.localeCompare(b.d)).slice(0, 8);
    $('[data-next]', el).innerHTML = events.length ? `<ul class="fh-rec-timeline">${events.map(({ d, r }) => `<li class="${daysTo(d) < 0 ? 'late' : ''}"><span class="fh-rec-date"><b>${d.slice(8, 10)}</b>${new Date(d + 'T12:00:00').toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '')}</span>
        <div class="grow"><b>${esc(r.taker_name || '—')}</b><small>${money(r.amount)} · ${whenLabel(d)}</small></div></li>`).join('')}</ul>`
      : '<p class="muted small" style="padding:0 18px 18px">Nenhuma emissão programada.</p>';
  };

  const card = (r) => {
    const on = Number(r.active);
    const li = r.last_invoice;
    const [bc, bl] = li ? INV_BADGE[li.status] || ['', li.status] : [];
    return `<section class="card fh-rec ${on ? '' : 'off'} ${li?.status === 'rejected' ? 'err' : ''}" data-id="${r.id}">
      <div class="fh-rec-head"><div><h3>${esc(r.taker_name || '—')}</h3><small class="muted">${esc(r.service_name || '')}</small></div>
        <div class="fh-rec-badges">${on ? '<span class="badge green">Ativa</span>' : '<span class="badge">Pausada</span>'}<span class="badge blue">${INTERVALS[r.interval_months] || 'Mensal'}</span></div></div>
      <div class="fh-rec-body">
        <div class="fh-rec-amount">${money(r.amount)}<small>${r.interval_months > 1 ? 'a cada ' + r.interval_months + ' meses' : 'por mês'} · todo dia ${r.day_of_month}</small></div>
        <dl class="fh-rec-facts">
          <div><dt>Próxima emissão</dt><dd>${on ? `<b>${date(r.next_run)}</b> <span class="muted">${whenLabel(r.next_run)}</span>` : '<span class="muted">pausada</span>'}</dd></div>
          <div><dt>Última nota</dt><dd>${li ? `<span class="badge ${bc}">${bl}${li.nfse_number ? ' nº ' + esc(li.nfse_number) : ''}</span> <span class="muted small">${date(li.issued_at || li.created_at)}</span>` : '<span class="muted">nenhuma ainda</span>'}</dd></div>
          <div><dt>Já emitidas</dt><dd>${Number(r.emitted_count)} nota(s) · ${money(r.emitted_total)}</dd></div>
          <div><dt>Vencimento</dt><dd>${r.due_day ? `todo dia ${r.due_day}${on && r.next_due ? ` <span class="muted">· próximo ${date(r.next_due)}</span>` : ''}` : '<span class="muted">não informado</span>'}</dd></div>
          ${r.end_date ? `<div><dt>Encerra em</dt><dd>${date(r.end_date)}</dd></div>` : ''}
        </dl>
        ${li?.status === 'rejected' ? `<div class="alert alert-warning small" style="margin:10px 0 0">${icon('alert')} ${esc((li.error_message || 'A última emissão foi rejeitada.').split('\n').slice(0, 2).join(' '))}</div>` : ''}
        <p class="fh-rec-preview" title="Como a descrição vai sair na próxima nota">“${esc(r.preview || '')}”</p>
      </div>
      <div class="fh-card-actions">
        <button class="btn btn-sm btn-primary" data-act="run" ${on ? '' : 'disabled'}>${icon('send')} Emitir agora</button>
        <button class="btn btn-sm" data-act="toggle">${icon(on ? 'clock' : 'play')} ${on ? 'Pausar' : 'Retomar'}</button>
        <button class="btn btn-sm" data-act="history">${icon('list')} Histórico</button>
        <button class="btn btn-sm btn-ghost" data-act="edit">${icon('edit')} Editar</button>
        <button class="btn btn-sm btn-ghost btn-icon" data-act="dup" title="Duplicar">${icon('copy')}</button>
        <button class="btn btn-sm btn-ghost btn-icon" data-act="del" title="Excluir">${icon('trash')}</button>
      </div></section>`;
  };

  const load = async () => { rows = (await api('/fh/recurring')).data; draw(); };

  el.addEventListener('click', async (e) => {
    if (e.target.closest('[data-new]')) return form(null);
    const f = e.target.closest('[data-f]');
    if (f) { filter = f.dataset.f; draw(); return; }
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const r = rows.find((x) => x.id === +b.closest('[data-id]').dataset.id);
    try {
      if (b.dataset.act === 'edit') form(r);
      else if (b.dataset.act === 'history') history(r);
      else if (b.dataset.act === 'toggle') { await api(`/fh/recurring/${r.id}/toggle`, { method: 'POST' }); toast(Number(r.active) ? 'Recorrência pausada.' : 'Recorrência retomada.'); await load(); }
      else if (b.dataset.act === 'dup') { await api(`/fh/recurring/${r.id}/duplicate`, { method: 'POST' }); toast('Cópia criada (pausada). Ajuste e ative quando quiser.'); await load(); }
      else if (b.dataset.act === 'del') { if (await confirmDialog('Excluir esta recorrência? As notas já emitidas continuam em Notas fiscais.', { danger: true, okLabel: 'Excluir' })) { await api('/fh/recurring/' + r.id, { method: 'DELETE' }); await load(); } }
      else if (b.dataset.act === 'run') {
        if (!await confirmDialog(`Emitir agora a nota de ${money(r.amount)} para ${r.taker_name}? Descrição: “${r.preview}”. A próxima emissão passa para o ciclo seguinte.`, { title: 'Emitir nota agora', okLabel: 'Emitir nota' })) return;
        b.classList.add('loading');
        const res = await api(`/fh/recurring/${r.id}/run`, { method: 'POST' }).finally(() => b.classList.remove('loading'));
        toast(`NFS-e ${res.invoice.nfse_number ? 'nº ' + res.invoice.nfse_number + ' ' : ''}emitida para ${r.taker_name}.`);
        await load();
      }
    } catch (err) {
      if (err.details?.length) { const m = modal({ title: 'A nota não foi emitida', body: `<div class="alert alert-danger"><b>${esc(err.message)}</b><ul style="margin:8px 0 0">${err.details.map((d) => `<li>${esc(d)}</li>`).join('')}</ul></div>`, footer: '<button class="btn btn-primary" data-close>Entendi</button>' }); $('[data-close]', m.foot)?.addEventListener('click', m.close); await load(); }
      else toastError(err);
    }
  });
  $('[data-q]', el).addEventListener('input', debounce((e) => { q = e.target.value.trim().toLowerCase(); draw(); }, 150));

  const history = async (r) => {
    const m = modal({ title: 'Histórico · ' + (r.taker_name || ''), size: 'lg', body: '<p class="muted">Carregando...</p>', footer: null });
    const list = (await api(`/fh/recurring/${r.id}/history`)).data;
    m.body.innerHTML = list.length ? `<div class="table-wrap"><table class="dt"><thead><tr><th>Nota</th><th>Competência</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead><tbody>${list.map((i) => {
      const [c, l] = INV_BADGE[i.status] || ['', i.status];
      return `<tr><td>${i.nfse_number ? 'NFS-e nº ' + esc(i.nfse_number) : 'DPS ' + esc(i.dps_number)}</td><td>${date(i.competence_date)}</td><td class="num">${money(i.amount)}</td><td><span class="badge ${c}">${l}</span>${i.status === 'rejected' && i.error_message ? `<br><small class="muted">${esc(i.error_message.split('\n')[0])}</small>` : ''}</td>
        <td class="actions">${['authorized', 'canceled'].includes(i.status) ? `<a class="btn btn-xs" href="${downloadUrl(`/fh/invoices/${i.id}/pdf`)}" target="_blank">${icon('download')} PDF</a>` : ''}<a class="btn btn-xs btn-ghost" href="#/notas/${i.id}">Abrir</a></td></tr>`;
    }).join('')}</tbody></table></div>` : emptyState('Nenhuma nota emitida por esta recorrência ainda.', 'file');
    m.body.addEventListener('click', (e) => { if (e.target.closest('a[href^="#/"]')) m.close(); });
  };

  const form = async (r) => {
    const [takers, services] = await Promise.all([fh.takers(), fh.services()]);
    if (!takers.length) { toast('Cadastre o cliente antes de criar a recorrência.', 'error'); location.hash = '#/clientes'; return; }
    const svcs = services.filter((s) => Number(s.active));
    if (!svcs.length) { toast('Cadastre o serviço (com o item da LC 116) antes de criar a recorrência.', 'error'); location.hash = '#/servicos'; return; }
    const v = r ? { ...r } : { day_of_month: 5, interval_months: 1, active: 1, description: 'Mensalidade referente a {mes_ano}', service_id: svcs[0].id };
    const opt = (list, sel) => list.map(([val, lbl]) => `<option value="${esc(val)}" ${String(val) === String(sel ?? '') ? 'selected' : ''}>${esc(lbl)}</option>`).join('');
    const m = modal({
      title: r ? 'Editar recorrência' : 'Nova nota recorrente', size: 'lg',
      body: `<form class="fh-rec-form" data-rf novalidate><div class="form-grid cols-4">
        <div class="field span-2"><label>Cliente *</label><select name="taker_id">${opt([['', 'Selecione...'], ...takers.map((t) => [t.id, t.name])], v.taker_id)}</select></div>
        <div class="field span-2"><label>Serviço *</label><select name="service_id">${opt(svcs.map((s) => [s.id, s.name]), v.service_id)}</select></div>
        <div class="field"><label>Valor (R$) *</label><input name="amount" inputmode="decimal" value="${esc(v.amount ?? '')}" placeholder="0,00"></div>
        <div class="field"><label>Periodicidade</label><select name="interval_months">${opt(Object.entries(INTERVALS), v.interval_months || 1)}</select></div>
        <div class="field"><label>Emitir todo dia</label><input name="day_of_month" type="number" min="1" max="28" value="${esc(v.day_of_month)}"><span class="help">1 a 28</span></div>
        <div class="field"><label>Próxima emissão</label><input name="next_run" type="date" value="${esc(v.next_run || '')}"><span class="help">em branco = próximo dia escolhido</span></div>
        <div class="field span-2" style="grid-column:1/-1"><label>Discriminação da nota *</label><textarea name="description" rows="3">${esc(v.description || '')}</textarea>
          <div class="fh-rec-ph">${PLACEHOLDERS.map(([p, l]) => `<button type="button" class="fh-chip" data-ph="${p}">+ ${l}</button>`).join('')}<span class="help">trocados pelo mês da emissão</span></div></div>
        <div class="field"><label>Dia para vencimento</label><input name="due_day" type="number" min="1" max="31" value="${esc(v.due_day ?? '')}" placeholder="ex.: 10"><span class="help">preenche {data_vencimento} e o contas a receber</span></div>
        <div class="field"><label>Encerrar em (opcional)</label><input name="end_date" type="date" value="${esc(v.end_date || '')}"></div>
        <label class="check span-2" style="align-self:end"><input type="checkbox" name="active" ${Number(v.active) ? 'checked' : ''}> Ativa (emitir automaticamente)</label>
      </div>
      <div class="fh-rec-live" data-live></div></form>`,
      footer: `<button class="btn" data-close>Cancelar</button><button class="btn btn-primary" data-save>${icon('check')} ${r ? 'Salvar alterações' : 'Criar recorrência'}</button>`,
    });
    const F = $('[data-rf]', m.body).elements;
    const read = () => ({ taker_id: F.taker_id.value, service_id: F.service_id.value, amount: F.amount.value.replace(/\./g, '').replace(',', '.'), interval_months: F.interval_months.value,
      day_of_month: F.day_of_month.value, due_day: F.due_day.value, next_run: F.next_run.value, description: F.description.value, end_date: F.end_date.value, active: F.active.checked, emitter_id: em.id });
    const live = debounce(async () => {
      try {
        const p = await api('/fh/recurring/preview', { method: 'POST', body: read() });
        $('[data-live]', m.body).innerHTML = `<div><b>${icon('calendar')} Próximas emissões</b><div class="chips-row">${p.schedule.map((d, i) => `<span class="fh-chip on">${date(d)}${p.dues[i] ? ' · vence ' + date(p.dues[i]).slice(0, 5) : ''}</span>`).join('') || '<span class="muted small">nenhuma (confira a data de encerramento)</span>'}</div></div>
          <div><b>${icon('eye')} Como a descrição vai sair</b><p>“${esc(p.text)}”</p></div>`;
      } catch (err) { /* preview is best-effort */ }
    }, 250);
    $('[data-rf]', m.body).addEventListener('input', live);
    $('[data-rf]', m.body).addEventListener('change', live);
    F.service_id.addEventListener('change', () => { const s = svcs.find((x) => String(x.id) === F.service_id.value); if (s?.price && !F.amount.value) F.amount.value = String(s.price).replace('.', ','); });
    $$('[data-ph]', m.body).forEach((b) => b.addEventListener('click', () => {
      const t = F.description; const p = t.selectionStart ?? t.value.length;
      t.value = t.value.slice(0, p) + b.dataset.ph + t.value.slice(t.selectionEnd ?? p); t.focus(); live();
    }));
    live();
    $('[data-save]', m.foot).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.classList.add('loading');
      try {
        await api(r ? '/fh/recurring/' + r.id : '/fh/recurring', { method: r ? 'PUT' : 'POST', body: read() });
        toast(r ? 'Recorrência atualizada.' : 'Recorrência criada. A nota será emitida automaticamente.');
        m.close();
        await load();
      } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
    });
  };
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
