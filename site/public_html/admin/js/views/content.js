import { api, state, $, esc, icon, date, badge, options, label, dataTable, formModal, confirmDialog, toast, toastError } from '../core.js';

export async function render(el, ctx) {
  return ctx.sub === 'help' ? renderHelp(el) : renderPosts(el);
}

/* ================================================================ POSTS */
function postForm(values, onSaved) {
  const m = formModal({
    title: values.id ? 'Editar artigo' : 'Novo artigo', size: 'lg', values: { reading_minutes: 4, ...values },
    fields: [
      state.ai?.admin && !values.id ? { name: '_ai', type: 'html', span: 2, html: `<div class="alert" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-color:rgba(0,207,129,.35)">${icon('sparkles')}<input class="input" data-ai-topic placeholder="Tema do artigo para a IA escrever um rascunho..." style="flex:1 1 260px"><button type="button" class="btn btn-sm btn-primary" data-ai-draft>Gerar rascunho</button></div>` } : null,
      { name: 'title', label: 'Título', required: true, span: 2 },
      { name: 'category', label: 'Categoria', placeholder: 'Ex.: Gestão, IA, Financeiro' },
      { name: 'reading_minutes', label: 'Tempo de leitura (min)', type: 'number', min: 1 },
      { name: 'excerpt', label: 'Resumo (aparece na listagem e no Google)', type: 'textarea', rows: 2, span: 2 },
      { name: 'cover_image', label: 'URL da imagem de capa (opcional)', span: 2, placeholder: 'https://...' },
      { name: 'content', label: 'Conteúdo (HTML simples: <p>, <h2>, <ul>, <strong>, <a>...)', type: 'textarea', rows: 14, span: 2 },
      { name: '_preview', type: 'html', span: 2, html: '<button type="button" class="btn btn-sm" data-preview>' + icon('eye') + ' Pré-visualizar</button><div data-preview-box class="card card-pad hidden" style="margin-top:10px;max-height:320px;overflow:auto"></div>' },
      { name: 'slug', label: 'Endereço (slug)', help: 'Gerado a partir do título se vazio.' },
      { name: 'published', label: 'Publicado no site', type: 'checkbox' },
    ],
    onSubmit: async (d) => {
      delete d._preview;
      delete d._ai;
      values.id ? await api('/posts/' + values.id, { method: 'PUT', body: d }) : await api('/posts', { method: 'POST', body: d });
      toast(d.published ? 'Artigo publicado.' : 'Rascunho salvo.');
      onSaved();
    },
  });
  $('[data-ai-draft]', m.el)?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const topic = $('[data-ai-topic]', m.el).value.trim();
    if (topic.length < 5) { toast('Descreva o tema do artigo.', 'error'); return; }
    btn.classList.add('loading');
    try {
      const r = await api('/ai/blog-draft', { method: 'POST', body: { topic } });
      const f = m.el.querySelector('form').elements;
      f.title.value = r.title; f.excerpt.value = r.excerpt; f.content.value = r.content;
      if (!f.category.value) f.category.value = 'Gestão';
      toast('Rascunho gerado. Revise e ajuste antes de publicar.', 'info');
    } catch (err) { toastError(err); } finally { btn.classList.remove('loading'); }
  });
  $('[data-preview]', m.el).addEventListener('click', () => {
    const box = $('[data-preview-box]', m.el);
    const tpl = document.createElement('template');
    tpl.innerHTML = m.el.querySelector('[name=content]').value;
    tpl.content.querySelectorAll('script, iframe, object, embed, style').forEach((n) => n.remove());
    tpl.content.querySelectorAll('*').forEach((n) => [...n.attributes].forEach((a) => { if (/^on/i.test(a.name) || /javascript:/i.test(a.value)) n.removeAttribute(a.name); }));
    box.innerHTML = '';
    box.appendChild(tpl.content);
    box.classList.remove('hidden');
  });
}

function renderPosts(el) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Blog</h2><p>Artigos publicados em /blog.</p></div>
      <div class="page-actions"><a class="btn" href="/blog" target="_blank">${icon('external')} Ver blog</a><button class="btn btn-primary" data-new>${icon('plus')} Novo artigo</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/posts',
    filters: [{ name: 'published', label: 'Todos', options: [{ value: 1, label: 'Publicados' }, { value: 0, label: 'Rascunhos' }] }],
    columns: [
      { label: 'Artigo', sort: 'title', primary: true, render: (p) => `<b>${esc(p.title)}</b><span class="sub">/blog/${esc(p.slug)}</span>` },
      { label: 'Categoria', render: (p) => esc(p.category || '—') },
      { label: 'Publicado em', sort: 'published_at', render: (p) => date(p.published_at) },
      { label: 'Status', render: (p) => (Number(p.published) ? '<span class="badge green">Publicado</span>' : '<span class="badge">Rascunho</span>') },
    ],
    onRowClick: (p) => postForm(p, () => table.reload()),
    actions: (p) => [
      Number(p.published) && { label: 'Ver', icon: 'external', iconOnly: true, onClick: () => window.open('/blog/' + p.slug, '_blank') },
      { label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog(`Excluir "${p.title}"?`, { danger: true })) { await api('/posts/' + p.id, { method: 'DELETE' }); table.reload(); } } },
    ],
  });
  $('[data-new]', el).addEventListener('click', () => postForm({}, () => table.reload()));
}

/* ================================================================= HELP */
function helpForm(values, onSaved) {
  formModal({
    title: values.id ? 'Editar artigo de ajuda' : 'Novo artigo de ajuda', size: 'lg', values: { published: 1, position: 0, ...values },
    fields: [
      { name: 'question', label: 'Pergunta', required: true, span: 2 },
      { name: 'category', label: 'Categoria', type: 'select', required: true, options: options('help_category') },
      { name: 'position', label: 'Ordem', type: 'number' },
      { name: 'answer', label: 'Resposta', type: 'textarea', rows: 7, required: true, span: 2 },
      { name: 'keywords', label: 'Palavras-chave (melhoram a busca e o assistente virtual)', span: 2, placeholder: 'boleto segunda via fatura' },
      { name: 'published', label: 'Publicado', type: 'checkbox' },
    ],
    onSubmit: async (d) => {
      values.id ? await api('/help-articles/' + values.id, { method: 'PUT', body: d }) : await api('/help-articles', { method: 'POST', body: d });
      toast('Artigo salvo.');
      onSaved();
    },
  });
}

function renderHelp(el) {
  el.innerHTML = `
    <div class="page-head"><div><h2>Central de ajuda</h2><p>Perguntas frequentes usadas no site e pelo assistente virtual.</p></div>
      <div class="page-actions"><a class="btn" href="/suporte" target="_blank">${icon('external')} Ver central</a><button class="btn btn-primary" data-new>${icon('plus')} Novo artigo</button></div></div>
    <div data-table></div>`;
  const table = dataTable($('[data-table]', el), {
    endpoint: '/help-articles', perPage: 50,
    filters: [{ name: 'category', label: 'Todas as categorias', options: options('help_category') }],
    columns: [
      { label: 'Pergunta', primary: true, render: (a) => `<b>${esc(a.question)}</b><span class="sub">${esc(a.answer.slice(0, 110))}${a.answer.length > 110 ? '…' : ''}</span>` },
      { label: 'Categoria', render: (a) => esc(label('help_category', a.category)) },
      { label: 'Visualizações', sort: 'views', num: true, render: (a) => a.views },
      { label: 'Útil', sort: 'helpful', num: true, render: (a) => `👍 ${a.helpful}` },
      { label: 'Status', render: (a) => (Number(a.published) ? '<span class="badge green">Publicado</span>' : '<span class="badge">Oculto</span>') },
    ],
    onRowClick: (a) => helpForm(a, () => table.reload()),
    actions: (a) => [{ label: 'Excluir', icon: 'trash', iconOnly: true, danger: true, onClick: async () => { if (await confirmDialog('Excluir este artigo?', { danger: true })) { await api('/help-articles/' + a.id, { method: 'DELETE' }); table.reload(); } } }],
  });
  $('[data-new]', el).addEventListener('click', () => helpForm({}, () => table.reload()));
}
