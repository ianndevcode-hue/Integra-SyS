import { api, state, esc, icon, money, moneyShort, date, datetime, relative, badge, chart, chartColors, monthLabel, can, emptyState } from '../core.js';

export async function render(el) {
  const d = await api('/dashboard');
  const k = d.kpis;
  const c = chartColors();
  const kpi = (href, ico, color, lbl, value, sub = '') => `
    <a class="card kpi" href="${href}"><div class="k-label"><span class="k-ico ${color}">${icon(ico)}</span>${esc(lbl)}</div><div class="k-value">${value}</div>${sub ? `<div class="k-sub">${sub}</div>` : ''}</a>`;

  const finance = can('finance');
  el.innerHTML = `
    <div class="page-head"><div><h2>Olá${state.user?.name ? ', ' + esc(state.user.name.split(' ')[0]) : ''}! 👋</h2><p>Resumo de ${new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' })}</p></div>
      <div class="page-actions">
        ${can('charges') ? `<a class="btn btn-primary" href="#/finance/charges?new=1">${icon('receipt')} Nova cobrança</a>` : ''}
        ${can('projects') ? `<a class="btn" href="#/projects?new=1">${icon('plus')} Novo projeto</a>` : ''}
      </div>
    </div>

    ${finance ? `<div class="grid g4" style="margin-bottom:16px">
      ${kpi('#/finance/budget', 'trendUp', 'green', 'Receita do mês', money(k.revenue_month), k.goal_month > 0 ? `<div class="progress" style="margin:4px 0"><i style="width:${Math.min(100, k.revenue_month / k.goal_month * 100)}%"></i></div>${Math.round(k.revenue_month / k.goal_month * 100)}% da meta de ${moneyShort(k.goal_month)}` : '<a href="#/finance/budget">Definir meta mensal</a>')}
      ${kpi('#/finance/pnl', 'pie', k.profit_month >= 0 ? '' : 'red', 'Lucro do mês', money(k.profit_month))}
      ${kpi('#/finance/cashflow', 'wallet', 'blue', 'Saldo de caixa', money(k.cash_balance))}
      ${kpi('#/finance/entries?type=receivable&status=overdue', 'alert', k.receivable_overdue > 0 ? 'red' : 'green', 'Inadimplência', money(k.receivable_overdue), `A receber em aberto: ${money(k.receivable_open)}`)}
    </div>` : ''}

    <div class="grid g4" style="margin-bottom:16px">
      ${kpi('#/customers', 'users', 'violet', 'Clientes ativos', k.customers_active, k.contracts_active ? `<a href="#/pricing/contracts">MRR ${money(k.mrr)} · ${k.contracts_active} contrato(s)</a>` : '<a href="#/pricing">Simular primeiro contrato</a>')}
      ${kpi('#/projects', 'kanban', '', 'Projetos em execução', k.projects_active, [k.projects_late ? `<span class="neg">${k.projects_late} atrasado(s)</span>` : 'Todos no prazo', k.approvals_pending ? `${k.approvals_pending} aprovação(ões) pendente(s)` : ''].filter(Boolean).join(' · '))}
      ${kpi('#/tickets', 'ticket', k.tickets_sla_breached ? 'red' : 'blue', 'Chamados abertos', k.tickets_open, `${k.tickets_mine} meus · ${k.tickets_unassigned} sem responsável${k.tickets_sla_breached ? ` · <span class="neg">${k.tickets_sla_breached} fora do SLA</span>` : ''}`)}
      ${kpi('#/leads', 'target', 'green', 'Leads novos', k.leads_new, `Funil: ${moneyShort(k.leads_open_value)} · ${k.appointments_upcoming} reunião(ões)`)}
    </div>
    ${d.followups.length ? `<section class="card" style="margin-bottom:16px"><div class="card-head"><h3>${icon('bell')} Follow-ups (até 3 dias)</h3><a class="btn btn-sm btn-ghost" href="#/followups">Todos ${icon('arrowRight')}</a></div>
      <ul class="list">${d.followups.map((a) => { const late = new Date(a.due_at.replace(' ', 'T')) < new Date(); return `<li><span class="dot" style="background:${late ? 'var(--danger)' : 'var(--warning, #f59e0b)'}"></span><div class="grow"><b style="white-space:normal">${esc(a.body)}</b><small><a href="${{ customer: '#/customers/', project: '#/projects/', ticket: '#/tickets/' }[a.entity] ? { customer: '#/customers/', project: '#/projects/', ticket: '#/tickets/' }[a.entity] + a.entity_id : '#/leads?open=' + a.entity_id}">${esc(a.entity_name || '')}</a> · ${esc(a.user_name || '')}</small></div><span class="small nowrap ${late ? 'neg' : 'muted'}">${datetime(a.due_at)}</span></li>`; }).join('')}</ul></section>` : ''}

    <div class="quick-links">
      <a href="#/reports">${icon('pie')}<span>Relatórios</span></a>
      <a href="#/presentations">${icon('sparkles')}<span>Apresentações com IA</span></a>
      <a href="#/pricing">${icon('money')}<span>Simulador de preços</span></a>
      <a href="#/pricing/ask">${icon('bolt')}<span>Consultor de preços IA</span></a>
      <a href="#/reports/financial_overview">${icon('trendUp')}<span>Financeiro executivo</span></a>
      <a href="#/reports/recurring_revenue">${icon('refresh')}<span>Receita recorrente</span></a>
      <a href="#/reports/support_performance">${icon('ticket')}<span>Suporte e SLA</span></a>
      <a href="#/reports/sales_funnel">${icon('target')}<span>Funil comercial</span></a>
    </div>
    <div class="grid g3">
      ${finance ? `<section class="card span-2"><div class="card-head"><h3>Receitas x despesas (6 meses)</h3><a class="btn btn-sm btn-ghost" href="#/finance/pnl">Ver DRE ${icon('arrowRight')}</a></div><div class="card-body"><div class="chart-box"><canvas id="ch-monthly"></canvas></div></div></section>` : ''}
      ${finance ? `<section class="card"><div class="card-head"><h3>Vencimentos (15 dias)</h3><a class="btn btn-sm btn-ghost" href="#/finance/entries">Todos</a></div>
        ${d.upcoming.length ? `<ul class="list">${d.upcoming.map((u) => `<li><span class="dot" style="background:${u.entry_type === 'receivable' ? 'var(--success)' : 'var(--danger)'}"></span><div class="grow"><b>${esc(u.description)}</b><small>${esc(u.customer_name || '')} ${date(u.due_date)}${u.due_date < new Date().toISOString().slice(0, 10) ? ' · <span class="neg">vencido</span>' : ''}</small></div><b class="${u.entry_type === 'receivable' ? 'pos' : 'neg'} nowrap">${u.entry_type === 'payable' ? '-' : ''}${moneyShort(u.amount)}</b></li>`).join('')}</ul>` : emptyState('Nada vencendo nos próximos 15 dias.', 'check')}
      </section>` : ''}
      <section class="card"><div class="card-head"><h3>Projetos em andamento</h3><a class="btn btn-sm btn-ghost" href="#/projects">Kanban</a></div>
        ${d.projects.length ? `<ul class="list">${d.projects.map((p) => `<li><div class="grow"><a href="#/projects/${p.id}"><b>${esc(p.name)}</b></a><small>${esc(p.customer_name || 'Sem cliente')} · ${esc(p.current_stage || '—')}</small></div><span class="small ${p.due_date && p.due_date < new Date().toISOString().slice(0, 10) ? 'neg' : 'muted'} nowrap">${date(p.due_date)}</span></li>`).join('')}</ul>` : emptyState('Nenhum projeto ativo.', 'kanban')}
      </section>
      <section class="card"><div class="card-head"><h3>Chamados por SLA</h3><a class="btn btn-sm btn-ghost" href="#/tickets">Todos</a></div>
        ${d.tickets.length ? `<ul class="list">${d.tickets.map((t) => `<li><div class="grow"><a href="#/tickets/${t.id}"><b>${esc(t.subject)}</b></a><small>${esc(t.protocol)} · ${t.sla_paused_at ? 'SLA pausado' : 'SLA ' + relative(t.sla_due_at)} · ${esc(t.assignee_name || 'sem responsável')}</small></div>${badge('priority', t.priority)}</li>`).join('')}</ul>` : emptyState('Nenhum chamado aguardando atendimento. 🎉', 'check')}
      </section>
      <section class="card"><div class="card-head"><h3>Próximas reuniões</h3><a class="btn btn-sm btn-ghost" href="#/appointments">Agenda</a></div>
        ${d.appointments.length ? `<ul class="list">${d.appointments.map((a) => `<li><span class="k-ico" style="width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--primary-soft);color:var(--primary)">${icon('calendar')}</span><div class="grow"><b>${esc(a.name)}${a.company ? ' · ' + esc(a.company) : ''}</b><small>${datetime(a.scheduled_at)} · ${esc(a.topic || '')}</small></div></li>`).join('')}</ul>` : emptyState('Nenhuma reunião agendada.', 'calendar')}
      </section>
    </div>`;

  if (finance) {
    chart(el.querySelector('#ch-monthly'), {
      type: 'bar',
      data: {
        labels: d.monthly.map((m) => monthLabel(m.month)),
        datasets: [
          { label: 'Receitas', data: d.monthly.map((m) => m.revenue), backgroundColor: c.green, borderRadius: 6, maxBarThickness: 28 },
          { label: 'Despesas', data: d.monthly.map((m) => m.expenses), backgroundColor: c.red, borderRadius: 6, maxBarThickness: 28 },
          { label: 'Lucro', data: d.monthly.map((m) => m.profit), type: 'line', borderColor: c.orange, backgroundColor: c.orange, tension: .35, pointRadius: 4 },
        ],
      },
      options: { scales: { y: { ticks: { callback: (v) => moneyShort(v) } } } },
    });
  }
}
