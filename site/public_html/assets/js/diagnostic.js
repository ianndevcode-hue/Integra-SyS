/* Digital maturity quiz: 8 questions, animated score gauge, recommendations, lead capture. */
(() => {
  'use strict';
  const quiz = document.getElementById('quiz');
  if (!quiz) return;
  const $ = (s, r = quiz) => r.querySelector(s);
  const $$ = (s, r = quiz) => Array.from(r.querySelectorAll(s));

  const scale = (a, b, c, d) => [[a, 0], [b, 1], [c, 2], [d, 3]];
  const questions = [
    { q: 'Como sua empresa controla vendas e estoque?', o: scale('Papel ou caderno', 'Planilhas', 'Sistema básico, pouco usado', 'Sistema integrado e atualizado'), rec: ['Sistema de gestão com vendas e estoque integrados (Integra SYS).', '/integra-sys'] },
    { q: 'E o financeiro (contas a pagar e a receber)?', o: scale('Não temos controle formal', 'Planilhas preenchidas à mão', 'Sistema, mas sem conciliação bancária', 'Sistema com conciliação e fluxo de caixa'), rec: ['Financeiro com conciliação bancária automática, fluxo de caixa e DRE.', '/solucoes#sistemas-web'] },
    { q: 'Seus sistemas e canais conversam entre si?', o: scale('Quase não usamos sistemas', 'Não, digitamos a mesma coisa várias vezes', 'Em parte', 'Sim, tudo integrado'), rec: ['Integrações entre loja, marketplaces, bancos e financeiro para eliminar digitação dupla.', '/solucoes#integracoes'] },
    { q: 'Como é o atendimento aos clientes?', o: scale('Só por telefone ou pessoalmente', 'WhatsApp pessoal, sem organização', 'WhatsApp Business com respostas rápidas', 'Plataforma com histórico e chatbot'), rec: ['Atendimento com WhatsApp Business API, histórico centralizado e chatbot com IA.', '/solucoes#inteligencia-artificial'] },
    { q: 'Como você acompanha os números do negócio?', o: scale('Não acompanhamos', 'Montamos relatórios manualmente', 'Alguns relatórios automáticos', 'Dashboards em tempo real'), rec: ['Dashboards de BI com os indicadores essenciais atualizados automaticamente.', '/solucoes#dashboards-bi'] },
    { q: 'Como são feitas as cobranças?', o: scale('Cobramos manualmente, um a um', 'Emitimos boletos manualmente', 'O sistema emite, mas sem lembretes', 'Automáticas com PIX/boleto e lembretes'), rec: ['Cobrança automática com PIX, boleto, lembretes e baixa automática.', '/solucoes#automacao'] },
    { q: 'Backups e segurança dos dados?', o: scale('Não sei dizer', 'Pendrive ou cópia manual', 'Nuvem, sem rotina definida', 'Backup automático e adequação à LGPD'), rec: ['Rotina de backup automático, controle de acessos e adequação à LGPD.', '/solucoes#suporte-sla'] },
    { q: 'Qual é a sua maior prioridade hoje?', o: [['Vender mais', 'sales'], ['Reduzir custos e retrabalho', 'costs'], ['Organizar o financeiro', 'finance'], ['Atender melhor', 'service']], priority: true },
  ];
  const priorityRec = {
    sales: 'Priorize CRM, integração com marketplaces e campanhas no WhatsApp para aumentar as vendas.',
    costs: 'Priorize automação de processos: cada tarefa repetitiva eliminada vira economia mensal.',
    finance: 'Priorize o módulo financeiro com conciliação automática para ter clareza de caixa e lucro.',
    service: 'Priorize atendimento omnichannel com chatbot e histórico unificado do cliente.',
  };
  const levels = [
    [35, 'Inicial', 'Há muito espaço para ganhar eficiência', 'Sua empresa ainda depende de processos manuais. A boa notícia: as primeiras melhorias costumam trazer o maior retorno.'],
    [65, 'Em evolução', 'Você já deu os primeiros passos', 'Alguns processos estão digitalizados, mas faltam integração e automação para ganhar escala.'],
    [85, 'Avançado', 'Sua operação é bem estruturada', 'Você já usa tecnologia a seu favor. O próximo nível é IA, automações e dados em tempo real.'],
    [101, 'Referência', 'Parabéns, você está à frente!', 'Sua maturidade digital é alta. Podemos ajudar com otimizações pontuais e inovação com IA.'],
  ];

  const stepsEl = $('[data-quiz-steps]');
  const letters = 'ABCD';
  stepsEl.innerHTML = questions.map((item, i) => `
    <div class="quiz-step" data-step="${i}">
      <small class="muted">Pergunta ${i + 1} de ${questions.length}</small>
      <h2>${item.q}</h2>
      <div class="options" role="radiogroup" aria-label="${item.q}">
        ${item.o.map(([label], k) => `<button type="button" class="option" role="radio" aria-checked="false" data-k="${k}"><span class="letter">${letters[k]}</span>${label}</button>`).join('')}
      </div>
      ${i > 0 ? '<button type="button" class="btn btn-link" data-back>← Voltar</button>' : ''}
    </div>`).join('');

  const answers = new Array(questions.length).fill(null);
  const bar = $('.quiz-progress i');
  let current = 0;

  function go(i) {
    current = i;
    $$('.quiz-step').forEach((s) => s.classList.remove('active'));
    const step = i < questions.length ? $(`[data-step="${i}"]`) : $('[data-result]');
    step.classList.add('active');
    bar.style.width = (Math.min(i, questions.length) / questions.length * 100) + '%';
    if (i >= questions.length) showResult();
    if (i > 0) quiz.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  $$('.option').forEach((opt) => opt.addEventListener('click', () => {
    const stepEl = opt.closest('.quiz-step');
    const i = +stepEl.dataset.step;
    $$('.option', stepEl).forEach((o) => { o.classList.toggle('selected', o === opt); o.setAttribute('aria-checked', String(o === opt)); });
    answers[i] = +opt.dataset.k;
    setTimeout(() => go(i + 1), 280);
  }));
  quiz.addEventListener('click', (e) => { if (e.target.closest('[data-back]')) go(current - 1); });
  $('[data-restart]').addEventListener('click', () => { answers.fill(null); $$('.option').forEach((o) => o.classList.remove('selected')); $('#diag-form').classList.remove('sent'); go(0); });

  function showResult() {
    const scored = questions.map((q, i) => (q.priority ? null : q.o[answers[i]]?.[1] ?? 0)).filter((v) => v !== null);
    const score = Math.round(scored.reduce((a, b) => a + b, 0) / (scored.length * 3) * 100);
    const [, name, titleText, text] = levels.find(([max]) => score < max);
    $('[data-level]').textContent = 'Nível: ' + name;
    $('[data-level-title]').textContent = titleText;
    $('[data-level-text]').textContent = text;

    const recs = questions.map((q, i) => (!q.priority && answers[i] <= 1 ? q.rec : null)).filter(Boolean).slice(0, 4);
    const prio = questions[questions.length - 1].o[answers[questions.length - 1]]?.[1];
    const recsEl = $('[data-recs]');
    recsEl.innerHTML = '';
    if (prio) recsEl.insertAdjacentHTML('beforeend', `<li><b>${priorityRec[prio]}</b></li>`);
    (recs.length ? recs : [['Explore IA aplicada e automações avançadas para ganhar ainda mais eficiência.', '/solucoes#inteligencia-artificial']])
      .forEach(([txt, href]) => recsEl.insertAdjacentHTML('beforeend', `<li><span>${txt} <a href="${href}" style="color:var(--primary)">Saiba mais</a></span></li>`));

    const val = $('.gauge .val');
    const scoreEl = $('[data-score]');
    const circ = 2 * Math.PI * 92;
    val.style.strokeDasharray = circ;
    val.style.strokeDashoffset = circ;
    requestAnimationFrame(() => requestAnimationFrame(() => { val.style.strokeDashoffset = circ * (1 - score / 100); }));
    const start = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - start) / 1600);
      scoreEl.textContent = Math.round(score * (1 - Math.pow(1 - t, 3))) + '%';
      if (t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);

    $('#diag-form').dataset.score = score;

    // Personalized analysis from Cloudflare Workers AI (optional, typed out progressively).
    const box = $('[data-ai-report]');
    if (box && window.IC?.ai?.diagnostic) {
      const out = $('[data-ai-text]', box);
      box.classList.remove('hidden');
      out.innerHTML = '<span class="typing"><i></i><i></i><i></i></span> Gerando sua análise...';
      window.icApi('/api/public/diagnostic-ai', { method: 'POST', body: {
        score, company: $('#q-company')?.value || '',
        answers: questions.map((q, i) => ({ question: q.q, answer: q.o[answers[i]]?.[0] ?? null })),
      } }).then((res) => {
        out.textContent = '';
        const text = res.report || '';
        let i = 0;
        const step = () => { out.textContent = text.slice(0, i += 4); if (i < text.length) requestAnimationFrame(step); };
        step();
      }).catch(() => box.classList.add('hidden'));
    }
  }

  $('#diag-form').icExtra = () => ({
    ai_report: $('[data-ai-text]')?.textContent || '',
    score: +($('#diag-form').dataset.score || 0),
    answers: questions.map((q, i) => ({ question: q.q, answer: q.o[answers[i]]?.[0] ?? null })),
  });

  go(0);
})();
