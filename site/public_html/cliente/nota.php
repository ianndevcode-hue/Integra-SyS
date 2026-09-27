<?php
declare(strict_types=1);

/**
 * Customer-side NFS-e download: PDF (municipal/national print or local DANFSe) or XML.
 * Only notes that belong to the logged-in customer and were authorized (or later canceled).
 */

require dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/nfse.php';

$customer = current_customer();
if (!$customer) {
    header('Location: /entrar?next=' . rawurlencode('/cliente/?aba=financeiro'));
    exit;
}
$inv = db_one("SELECT * FROM nfse_invoices WHERE id = ? AND customer_id = ? AND status IN ('authorized','canceled')", [(int)($_GET['id'] ?? 0), (int)$customer['id']]);
if (!$inv) {
    http_response_code(404);
    exit('Nota fiscal não encontrada.');
}
if (isset($_GET['xml'])) {
    if (!$inv['xml_nfse']) {
        http_response_code(404);
        exit('XML indisponível para esta nota.');
    }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="NFSe-' . ($inv['nfse_number'] ?: $inv['id']) . '.xml"');
    echo $inv['xml_nfse'];
    exit;
}
if (!empty($inv['print_url'])) {
    header('Location: ' . $inv['print_url']);
    exit;
}
if (($inv['provider'] ?? 'nacional') === 'nacional' && ($pdf = nfse_danfse_pdf($inv))) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="DANFSe-' . $inv['access_key'] . '.pdf"');
    echo $pdf;
    exit;
}
header('Content-Type: text/html; charset=utf-8');
require INC_PATH . '/danfse.php';
