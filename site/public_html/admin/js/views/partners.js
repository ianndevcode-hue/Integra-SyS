import { api, $, $$, esc, icon, money, date, dataTable, formModal, confirmDialog, toast, toastError, today, monthStart, monthEnd, addDays, chart } from '../core.js';

function partnerForm(values, onSaved) {
  formModal({
    title: values.id ? 'Editar sócio' : 'Novo sócio', values: { active: 1, ...values },
    fields: [
      { name: 'name', label: 'Nome', required: true, span: 2 },
      { name: 'document', label: 'CPF' },
      { name: 'email', label: 'E-mail', type: 'email' },
      { name: 'share_percent', label: 'Participação nos lucros (%)', type: 'number', step: '0.01', min: 0, max: 100, required: true },
      { name: 'pix_key', label: 'Chave PIX para repasse' },
      { name: 'active', label: 'Sócio ativo (participa das distribuições)', type: 'checkbox', span: 2 },
    ],
    onSubmit: async (d) => {
      values.id ? await api('/partners/' + values.id, { method: 'PUT', body: d }) : await api('/partners', { method: 'POST', body: d });
      toast('Sócio salvo.');
      onSaved();
    },
  });
}

export async function render(el) {
  const t = today();
  const lastMonth = addDays(monthStart(t), -1);
  el.innerHTML = `
    <div class="page-head"><div><h2>Sócios & distribuição de lucros</h2><p>Defina a participação de cada sócio e distribua o lucro apurado no DRE.</p></div>
      <div class="page-actions"><button class="btn" data-new>${icon('plus')} Novo sócio</button></div></div>
    <div class="grid g3">
      <section class="card span-2"><div class="card-head"><h3>Simular distribuição</h3></div><div class="card-body">
        <div class="form-grid cols-4" data-sim>
          <div class="field"><label for="d-start">Início do período</label><input id="d-start" type="date" value="${monthStart(lastMonth)}"></div>
          <div class="field"><label for="d-end">Fim do período</label><input id="d-end" type="date" value="${monthEnd(lastMonth)}"></div>
          <div class="field"><label for="d-reserve">Reserva/reinvestimento (%)</label><input id="d-reserve" type="number" min="0" max="100" step="1" value="20"></div>
          <div class="field"><label for="d-pay">Data do repasse</label><input id="d-pay" type="date" value="${t}"></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          ${[['Mês anterior', monthStart(lastMonth), monthEnd(lastMonth)], ['Mês atual', monthStart(t), monthEnd(t)], ['Ano até hoje', t.slice(0, 4) + '-01-01', t]].map(([l, s, e]) => `<button class="btn btn-sm" data-range="${s}|${e}">${l}</button>`).join('')}
          <span style="flex:1"></span><button class="btn btn-primary" data-preview>${icon('eye')} Calcular</button>
        </div>
        <div data-preview-out style="margin-top:16px"></div>
      </div></section>
      <section class="card"><div class="card-head"><h3>Participação societária</h3></div><div class="card-body"><div class="chart-box sm"><canvas id="share-chart"></canvas></div></div></section>
    </div>
    <h3 style="margin:24px 0 12px">Sócios</h3>
    <div data-partners></div>
    <h3 style="margin:24px 0 12px">Histórico de distribuições</h3>
    <div data-history></div>`;

  const partnersTable = dataTable($('[data-partners]', el), {
    endpoint: '/partners', perPage: 50,
    columns: [
      { label: 'Sócio', primary: true, render: (p) => `<b>${esc(p.name)}</b><span class="sub">${esc(p.email || '')}</span>` },
      { label: 'Participação', num: true, render: (p) => `<b>${Number(p.share_percent).toLocaleString('pt-BR')}%</b>` },
      { label: 'Chave PIX', render: (p) => `<span class="mono">${esc(p.pix_key || '—')}</span>` },
      { label: 'Status', render: (p) => (Number(p.active) ? '<span class="badge green">Ativo</span>' : '<span class="badge">Inativo</span>') },
    ],
    onRowClick: (p) => partnerForm(p, reloadAll),
    actions: (p) => [{ label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog(`Excluir o sócio ${p.name}?`, { danger: true })) { try { await api('/partners/' + p.id, { method: 'DELETE' }); reloadAll(); } catch (e) { toastError(e); } } } }],
    onLoad: (res) => {
      const act = res.data.filter((p) => Number(p.active));
      const total = act.reduce((s, p) => s + Number(p.share_percent), 0);
      const canvas = $('#share-chart', el);
      if (!canvas) return;
      if (!act.length) { canvas.parentElement.innerHTML = '<div class="empty-box">Cadastre os sócios.</div>'; return; }
      chart(canvas, {
        type: 'doughnut',
        data: { labels: act.map((p) => p.name), datasets: [{ data: act.map((p) => Number(p.share_percent)), backgroundColor: ['#0066fe', '#00cf81', '#6d45f6', '#38bdf8', '#14b8a6', '#f43f5e'], borderWidth: 0 }] },
        options: { cutout: '60%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (x) => `${x.label}: ${x.parsed}%` } }, title: { display: Math.abs(total - 100) > 0.01, text: `⚠ Soma = ${total}% (deve ser 100%)`, color: '#f43f5e' } }, interaction: { mode: 'nearest' } },
      });
    },
  });

  async function loadHistory() {
    const { data } = await api('/distributions');
    $('[data-history]', el).innerHTML = data.length ? `<div class="card"><div class="table-wrap"><table class="dt cards"><thead><tr><th>Período</th><th class="num">Lucro líquido</th><th class="num">Reserva</th><th class="num">Distribuído</th><th>Repasses</th><th></th></tr></thead><tbody>
      ${data.map((d) => `<tr><td class="primary" data-label="Período">${date(d.period_start)} a ${date(d.period_end)}<span class="sub">por ${esc(d.created_by || '—')} em ${date(d.created_at)}</span></td>
        <td class="num" data-label="Lucro">${money(d.net_profit)}</td><td class="num" data-label="Reserva">${Number(d.reserve_percent)}%</td><td class="num" data-label="Distribuído"><b>${money(d.distributable)}</b></td>
        <td data-label="Repasses">${d.items.map((i) => `<div class="small">${esc(i.name)}: ${money(i.amount)} ${i.entry_status === 'paid' ? '<span class="badge green">pago</span>' : '<span class="badge blue">a pagar</span>'}</div>`).join('')}</td>
        <td class="actions"><button class="btn btn-xs btn-danger" data-del-dist="${d.id}" title="Excluir">${icon('trash')}</button></td></tr>`).join('')}
      </tbody></table></div></div>` : '<div class="card empty-box">Nenhuma distribuição registrada ainda.</div>';
    $$('[data-del-dist]', el).forEach((b) => b.addEventListener('click', async () => {
      if (!await confirmDialog('Excluir esta distribuição e as contas a pagar geradas (somente se nenhuma foi paga)?', { danger: true })) return;
      try { await api('/distributions/' + b.dataset.delDist, { method: 'DELETE' }); toast('Distribuição excluída.'); loadHistory(); } catch (e) { toastError(e); }
    }));
  }

  function reloadAll() { partnersTable.reload(); loadHistory(); }
  loadHistory();

  const val = (s) => $(s, el).value;
  async function preview() {
    const out = $('[data-preview-out]', el);
    out.innerHTML = '<div class="loading-box" style="padding:20px">Calculando...</div>';
    try {
      const p = await api('/distributions/preview', { method: 'POST', body: { start: val('#d-start'), end: val('#d-end'), reserve_percent: val('#d-reserve') } });
      out.innerHTML = `
        ${p.warning ? `<div class="alert alert-danger">${esc(p.warning)}</div>` : ''}
        <div class="stat-inline" style="margin-bottom:14px">
          <div><small>Lucro líquido do período</small><b class="${p.net_profit >= 0 ? 'pos' : 'neg'}">${money(p.net_profit)}</b></div>
          <div><small>Reserva (${p.reserve_percent}%)</small><b>${money(p.reserve_amount)}</b></div>
          <div><small>Já distribuído no período</small><b>${money(p.already_distributed)}</b></div>
          <div><small>Disponível para distribuir</small><b style="color:var(--primary)">${money(p.distributable)}</b></div>
        </div>
        ${p.items.length ? `<ul class="list card">${p.items.map((i) => `<li><span class="avatar" style="width:32px;height:32px;font-size:.8rem">${esc(i.name[0])}</span><div class="grow"><b>${esc(i.name)}</b><small>${i.share_percent}% · PIX ${esc(i.pix_key || 'não informado')}</small></div><b>${money(i.amount)}</b></li>`).join('')}</ul>` : '<div class="alert alert-warning">Cadastre sócios ativos para distribuir.</div>'}
        ${p.distributable > 0 && !p.warning ? `<div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn btn-primary" data-confirm>${icon('check')} Registrar distribuição e gerar contas a pagar</button></div>` : ''}`;
      $('[data-confirm]', out)?.addEventListener('click', async (e) => {
        if (!await confirmDialog(`Registrar a distribuição de ${money(p.distributable)}? Serão criadas contas a pagar para cada sócio com vencimento em ${date(val('#d-pay'))}.`)) return;
        e.target.classList.add('loading');
        try {
          await api('/distributions', { method: 'POST', body: { start: p.start, end: p.end, reserve_percent: p.reserve_percent, payment_date: val('#d-pay') } });
          toast('Distribuição registrada! Contas a pagar geradas.');
          out.innerHTML = '';
          loadHistory();
        } catch (err) { toastError(err); e.target.classList.remove('loading'); }
      });
    } catch (e) { out.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; }
  }
  $('[data-preview]', el).addEventListener('click', preview);
  $$('[data-range]', el).forEach((b) => b.addEventListener('click', () => { const [s, e] = b.dataset.range.split('|'); $('#d-start', el).value = s; $('#d-end', el).value = e; preview(); }));
  $('[data-new]', el).addEventListener('click', () => partnerForm({}, reloadAll));
}
