<?php
declare(strict_types=1);

/**
 * Customer-side file download: only attachments visible to the client that belong to
 * the logged-in customer (their tickets, projects or their own record).
 */

require dirname(__DIR__) . '/inc/bootstrap.php';

$customer = current_customer();
if (!$customer) {
    header('Location: /entrar?next=' . rawurlencode('/cliente/'));
    exit;
}
$cid = (int)$customer['id'];
$a = db_one('SELECT * FROM attachments WHERE id = ? AND client_visible = 1', [(int)($_GET['id'] ?? 0)]);
$owned = $a && match ($a['entity']) {
    'ticket' => (bool)db_value('SELECT id FROM tickets WHERE id = ? AND customer_id = ?', [$a['entity_id'], $cid]),
    'project' => (bool)db_value('SELECT id FROM projects WHERE id = ? AND customer_id = ?', [$a['entity_id'], $cid]),
    'customer' => (int)$a['entity_id'] === $cid,
    default => false,
};
if (!$owned) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
attachment_output($a, isset($_GET['baixar']));
