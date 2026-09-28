<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['categoria'] ?? ''));
$page = max(1, (int)($_GET['pagina'] ?? 1));
$perPage = 9;

$where = ['published = 1'];
$params = [];
if ($q !== '') { $where[] = '(title LIKE ? OR excerpt LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($cat !== '') { $where[] = 'category = ?'; $params[] = $cat; }
$whereSql = implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) FROM posts WHERE $whereSql", $params);
$posts = db_all("SELECT slug, title, excerpt, category, cover_image, reading_minutes, published_at FROM posts WHERE $whereSql ORDER BY published_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$categories = array_column(db_all('SELECT DISTINCT category FROM posts WHERE published = 1 AND category IS NOT NULL ORDER BY category'), 'category');
$pages = (int)ceil($total / $perPage);

page_start(['title' => 'Blog', 'active' => '/blog', 'description' => 'Artigos sobre gestão, automação, inteligência artificial, finanças e tecnologia para pequenas e médias empresas.']);
?>
<section class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Blog</span></nav>
    <span class="eyebrow" data-reveal><span class="dot"></span> Conteúdo</span>
    <h1 data-reveal>Blog <span class="grad-text">Integra Code</span></h1>
    <p class="lead" data-reveal>Tecnologia explicada de forma simples, com dicas práticas para você tomar melhores decisões.</p>
    <form class="help-search" method="get" style="margin-top:26px" data-reveal>
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar artigos..." aria-label="Buscar artigos">
      <?php if ($cat): ?><input type="hidden" name="categoria" value="<?= e($cat) ?>"><?php endif; ?>
    </form>
  </div>
</section>

<section class="section-sm" style="padding-top:0">
  <div class="container">
    <div class="chip-tabs" style="margin-bottom:28px">
      <a class="chip <?= $cat === '' ? 'active' : '' ?>" href="/blog<?= $q ? '?q=' . rawurlencode($q) : '' ?>">Todos</a>
      <?php foreach ($categories as $c): ?>
        <a class="chip <?= $cat === $c ? 'active' : '' ?>" href="/blog?categoria=<?= rawurlencode($c) ?><?= $q ? '&q=' . rawurlencode($q) : '' ?>"><?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$posts): ?>
      <div class="empty-state panel"><?= icon('book', 'ico ico-lg') ?><h3 style="margin-top:12px">Nenhum artigo encontrado</h3><p>Tente outra busca ou <a href="/blog" style="color:var(--primary)">veja todos os artigos</a>.</p></div>
    <?php else: ?>
      <div class="grid grid-3" data-stagger>
        <?php foreach ($posts as $p): ?>
          <a class="card spotlight post-card" href="/blog/<?= e($p['slug']) ?>">
            <div class="post-cover"><?= $p['cover_image'] ? '<img src="' . e($p['cover_image']) . '" alt="" loading="lazy">' : post_art($p['slug']) ?></div>
            <div class="post-body">
              <div class="post-meta"><span class="tag tag-primary"><?= e($p['category']) ?></span><span><?= date('d/m/Y', strtotime($p['published_at'])) ?></span><span><?= (int)$p['reading_minutes'] ?> min de leitura</span></div>
              <h3><?= e($p['title']) ?></h3>
              <p><?= e($p['excerpt']) ?></p>
              <span class="more" style="margin-top:auto;padding-top:14px">Ler artigo <?= icon('arrow') ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?>
        <nav class="chip-tabs" style="justify-content:center;margin-top:36px" aria-label="Paginação">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="chip <?= $i === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_filter(['q' => $q, 'categoria' => $cat, 'pagina' => $i])) ?>"><?= $i ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php page_end();
