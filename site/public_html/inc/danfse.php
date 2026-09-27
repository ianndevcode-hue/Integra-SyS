<?php
declare(strict_types=1);

/**
 * Printable DANFSe (HTML) generated from the authorized NFS-e XML.
 * Expects $inv (nfse_find row incl. xml_nfse). Rendered by GET /api/nfse/{id}/danfse?source=local.
 */

$xml = (string)db_value('SELECT xml_nfse FROM nfse_invoices WHERE id = ?', [$inv['id']]);
$x = null;
if ($xml !== '') {
    $d = new DOMDocument();
    if (@$d->loadXML($xml)) $x = new DOMXPath($d);
}
$v = function (string $path, string $default = '') use ($x): string {
    if (!$x) return $default;
    $n = $x->query('//*[local-name()="' . implode('"]/*[local-name()="', explode('/', $path)) . '"]')->item(0);
    return $n ? trim($n->textContent) : $default;
};
$m = fn($n) => 'R$ ' . number_format((float)$n, 2, ',', '.');
$docFmt = function (string $d): string {
    $d = only_digits($d);
    if (strlen($d) === 14) return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    return $d;
};
$emitName = $v('emit/xNome', COMPANY['name']);
$emitDoc = $v('emit/CNPJ', nfse_config()['cnpj']);
$number = $inv['nfse_number'] ?: $v('infNFSe/nNFSe', '—');
$proc = $v('infNFSe/dhProc', (string)$inv['issued_at']);
$vServ = $v('valores/vServPrest/vServ', (string)$inv['amount']) ?: $inv['amount'];
$vIss = $v('infNFSe/valores/vISSQN', (string)$inv['iss_amount']);
$vLiq = $v('infNFSe/valores/vLiq', (string)($inv['net_amount'] ?? round((float)$inv['amount'] - ($inv['iss_withheld'] ? (float)$vIss : 0), 2)));
$fed = [];
foreach (['pis' => 'PIS', 'cofins' => 'COFINS', 'csll' => 'CSLL', 'irrf' => 'IRRF', 'inss' => 'INSS'] as $k => $l) {
    if ((float)($inv[$k . '_amount'] ?? 0) > 0) $fed[] = [$l, (float)$inv[$k . '_rate'], (float)$inv[$k . '_amount'], !empty($inv[$k . '_withheld'])];
}
$canceled = $inv['status'] === 'canceled';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>DANFSe <?= e($number) ?> · <?= e($emitName) ?></title>
<meta name="robots" content="noindex">
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #eef1f6; }
  .page { width: 190mm; min-height: 270mm; margin: 12px auto; background: #fff; padding: 10mm; box-sizing: border-box; position: relative; box-shadow: 0 4px 24px rgba(0,0,0,.12); }
  h1 { font-size: 15px; margin: 0; }
  .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0066fe; padding-bottom: 8px; margin-bottom: 10px; gap: 12px; }
  .head img { height: 40px; }
  .box { border: 1px solid #999; border-radius: 4px; padding: 6px 8px; margin-bottom: 8px; }
  .box h2 { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; background: #f1f4f9; margin: -6px -8px 6px; padding: 4px 8px; border-bottom: 1px solid #ccc; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 10px; font-size: 11px; }
  .grid div small { display: block; font-size: 8.5px; color: #555; text-transform: uppercase; }
  .span2 { grid-column: span 2; } .span4 { grid-column: span 4; }
  .desc { font-size: 11px; white-space: pre-wrap; min-height: 40mm; }
  .key { font-family: monospace; font-size: 12px; letter-spacing: .5px; word-break: break-all; }
  .total { font-size: 16px; font-weight: bold; }
  .stamp { position: absolute; top: 45%; left: 10%; right: 10%; text-align: center; font-size: 60px; font-weight: bold; color: rgba(220,0,0,.18); transform: rotate(-25deg); pointer-events: none; }
  .demo { background: #fff4d6; border: 1px solid #f0c24b; padding: 6px 8px; font-size: 11px; margin-bottom: 8px; }
  .bar { text-align: center; margin: 10px; }
  .bar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
  @media print { body { background: #fff; } .page { margin: 0; box-shadow: none; } .bar { display: none; } }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Imprimir / salvar em PDF</button></div>
<div class="page">
  <?php if ($canceled): ?><div class="stamp">CANCELADA</div><?php endif; ?>
  <div class="head">
    <img src="/assets/img/logo.svg" alt="Integra Code" style="height:44px">
    <div style="text-align:right"><h1>DANFSe — Documento Auxiliar da NFS-e</h1><div style="font-size:11px">Sistema Nacional NFS-e · Número <b><?= e($number) ?></b></div></div>
  </div>
  <?php if ($inv['environment'] !== 'production'): ?><div class="demo">Emitida em <b>produção restrita (homologação)</b> — sem valor fiscal.</div><?php endif; ?>
  <div class="box"><h2>Chave de acesso</h2><div class="key"><?= e($inv['access_key']) ?></div>
    <div style="font-size:10px;margin-top:4px">Consulte a autenticidade em <b>www.nfse.gov.br/consultapublica</b></div></div>
  <div class="box"><h2>Identificação</h2><div class="grid">
    <div><small>Número NFS-e</small><?= e($number) ?></div>
    <div><small>Emissão / processamento</small><?= e($proc ? date('d/m/Y H:i', strtotime($proc)) : '—') ?></div>
    <div><small>Competência</small><?= e($inv['competence_date'] ? date('d/m/Y', strtotime($inv['competence_date'])) : '—') ?></div>
    <div><small>DPS (série/número)</small><?= e($inv['dps_serie'] . ' / ' . $inv['dps_number']) ?></div>
  </div></div>
  <div class="box"><h2>Prestador de serviços</h2><div class="grid">
    <div class="span2"><small>Nome / razão social</small><?= e($emitName) ?></div>
    <div><small>CNPJ</small><?= e($docFmt($emitDoc)) ?></div>
    <div><small>Inscrição municipal</small><?= e(nfse_config()['im'] ?: '—') ?></div>
    <div class="span2"><small>E-mail</small><?= e(COMPANY['email']) ?></div>
    <div class="span2"><small>Telefone</small><?= e(COMPANY['phone']) ?></div>
  </div></div>
  <div class="box"><h2>Tomador de serviços</h2><div class="grid">
    <div class="span2"><small>Nome / razão social</small><?= e($inv['toma_name']) ?></div>
    <div><small>CPF/CNPJ</small><?= e($docFmt((string)$inv['toma_document'])) ?></div>
    <div><small>E-mail</small><?= e($inv['toma_email'] ?: '—') ?></div>
  </div></div>
  <div class="box"><h2>Serviço prestado</h2><div class="grid" style="margin-bottom:6px">
    <div><small>Código tributação nacional</small><?= e($inv['service_code']) ?></div>
    <div><small>NBS</small><?= e($inv['nbs_code'] ?: '—') ?></div>
    <div class="span2"><small>Local da prestação</small>IBGE <?= e(nfse_config()['city_code']) ?></div>
  </div><div class="desc"><?= e($inv['description']) ?></div></div>
  <div class="box"><h2>Valores</h2><div class="grid">
    <div><small>Valor do serviço</small><?= e($m($vServ)) ?></div>
    <div><small>Alíquota ISS</small><?= e(number_format((float)$inv['iss_rate'], 2, ',', '.')) ?>%</div>
    <div><small>ISS <?= $inv['iss_withheld'] ? '(retido)' : '' ?></small><?= e($m($vIss)) ?></div>
    <div><small>Valor líquido</small><span class="total"><?= e($m($vLiq)) ?></span></div>
    <?php if ((float)($inv['discount_amount'] ?? 0) > 0): ?><div><small>Desconto incondicionado</small><?= e($m($inv['discount_amount'])) ?></div><?php endif; ?>
    <?php if ((float)($inv['deductions'] ?? 0) > 0): ?><div><small>Deduções da base</small><?= e($m($inv['deductions'])) ?></div><?php endif; ?>
    <?php foreach ($fed as [$l, $rate, $amt, $w]): ?><div><small><?= e($l) ?> <?= e(number_format($rate, 2, ',', '.')) ?>%<?= $w ? ' (retido)' : '' ?></small><?= e($m($amt)) ?></div><?php endforeach; ?>
    <?php if ((float)($inv['total_taxes_amount'] ?? 0) > 0): ?><div class="span2"><small>Valor aproximado dos tributos (Lei 12.741/2012)</small><?= e($m($inv['total_taxes_amount'])) ?> (<?= e(number_format((float)$inv['total_taxes_pct'], 2, ',', '.')) ?>%)</div><?php endif; ?>
  </div></div>
  <?php if ($canceled): ?><div class="box"><h2>Cancelamento</h2><div style="font-size:11px"><?= e(date('d/m/Y H:i', strtotime($inv['canceled_at']))) ?> — <?= e($inv['cancel_reason']) ?></div></div><?php endif; ?>
  <p style="font-size:9px;color:#666;margin-top:10px">Documento auxiliar gerado pelo sistema Integra Code a partir do XML autorizado pelo Sistema Nacional NFS-e. O documento fiscal válido é o XML.</p>
</div>
</body>
</html>
