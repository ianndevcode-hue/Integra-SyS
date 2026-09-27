<?php
// Local dev router for `php -S` mirroring public_html/.htaccess rules.
$root = dirname(__DIR__) . '/public_html';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/(inc|storage)(/|$)#', $path)) { http_response_code(403); exit('Forbidden'); }
if (strpos($path, '/api/') === 0 || $path === '/api') { require $root . '/api/index.php'; return true; }
if (preg_match('#^/blog/([a-z0-9-]+)/?$#', $path, $m)) { $_GET['slug'] = $m[1]; require $root . '/post.php'; return true; }

$file = $root . $path;
if ($path !== '/' && is_file($file)) return false; // static asset
if (is_dir($file)) {
    foreach (['index.php', 'index.html'] as $idx) if (is_file(rtrim($file, '/') . '/' . $idx)) {
        if (substr($idx, -4) === 'html') { readfile(rtrim($file, '/') . '/' . $idx); return true; }
        chdir(rtrim($file, '/')); require rtrim($file, '/') . '/' . $idx; return true;
    }
}
$php = $root . rtrim($path, '/') . '.php';
if (is_file($php)) { require $php; return true; }
http_response_code(404);
require $root . '/404.php';
return true;
