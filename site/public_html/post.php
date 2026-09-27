<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

$slug = (string)($_GET['slug'] ?? '');
$post = db_one('SELECT * FROM posts WHERE slug = ? AND published = 1', [$slug]);
if (!$post) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
$related = db_all('SELECT slug, title, excerpt, category, cover_image, reading_minutes, published_at FROM posts WHERE published = 1 AND id != ? ORDER BY (category = ?) DESC, published_at DESC LIMIT 3', [$post['id'], $post['category']]);
$url = base_url() . '/blog/' . $post['slug'];

page_start([
    'title' => $post['title'],
    'description' => $post['excerpt'],
    'active' => '/blog',
    'og_type' => 'article',
    'head' => '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'],
        'description' => $post['excerpt'], 'datePublished' => date('c', strtotime($post['published_at'])),
        'author' => ['@type' => 'Organization', 'name' => COMPANY['name']], 'mainEntityOfPage' => $url,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>',
]);
?>
<article>
  <header class="page-hero">
    <div class="container" style="max-width:860px">
      <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <a href="/blog">Blog</a> / <span><?= e($post['category']) ?></span></nav>
      <div class="post-meta" data-reveal><span class="tag tag-primary"><?= e($post['category']) ?></span><span><?= date('d/m/Y', strtotime($post['published_at'])) ?></span><span><?= (int)$post['reading_minutes'] ?> min de leitura</span></div>
      <h1 data-reveal><?= e($post['title']) ?></h1>
      <p class="lead" data-reveal><?= e($post['excerpt']) ?></p>
    </div>
  </header>
  <div class="container">
    <?php if ($post['cover_image']): ?><img src="<?= e($post['cover_image']) ?>" alt="" style="max-width:980px;width:100%;margin:0 auto 40px;border-radius:22px"><?php endif; ?>
    <div class="prose" data-reveal><?= $post['content'] /* sanitized on save (sanitize_html) */ ?></div>
    <div class="prose" style="margin-top:40px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <b>Compartilhar:</b>
      <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= rawurlencode($post['title'] . ' ' . $url) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp</a>
      <a class="btn btn-ghost btn-sm" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($url) ?>" target="_blank" rel="noopener"><?= icon('linkedin') ?> LinkedIn</a>
      <button class="btn btn-ghost btn-sm" type="button" onclick="navigator.clipboard.writeText(location.href).then(()=>icToast('Link copiado!','success'))">Copiar link</button>
    </div>
  </div>
</article>

<section class="section">
  <div class="container">
    <div class="cta-band" data-reveal="zoom" style="margin-bottom:70px">
      <h2>Quer aplicar isso na sua empresa?</h2>
      <p>Converse com a nossa equipe e descubra o caminho mais rápido para colocar essas ideias em prática.</p>
      <div class="actions"><a href="/agendar" class="btn btn-primary btn-lg magnetic">Agendar conversa <?= icon('arrow') ?></a></div>
    </div>
    <?php if ($related): ?>
      <h2 style="font-size:1.8rem;margin-bottom:24px">Continue lendo</h2>
      <div class="grid grid-3" data-stagger>
        <?php foreach ($related as $p): ?>
          <a class="card spotlight post-card" href="/blog/<?= e($p['slug']) ?>">
            <div class="post-cover"><?= $p['cover_image'] ? '<img src="' . e($p['cover_image']) . '" alt="" loading="lazy">' : post_art($p['slug']) ?></div>
            <div class="post-body"><div class="post-meta"><span class="tag tag-primary"><?= e($p['category']) ?></span><span><?= (int)$p['reading_minutes'] ?> min</span></div><h3><?= e($p['title']) ?></h3></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php page_end();
