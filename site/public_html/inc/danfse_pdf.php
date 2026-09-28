<?php
declare(strict_types=1);

/**
 * DANFSe v1.0 — Documento Auxiliar da NFS-e in the official national layout (same blocks, fields and
 * order as the DANFSe printed by the Emissor Nacional), with the authenticity QR Code.
 * danfse_pdf_render($d) takes a normalized array (see fh_danfse_data / nfse_danfse_data) and returns the PDF.
 */

require_once INC_PATH . '/pdf.php';
require_once INC_PATH . '/qrcode.php';

const DANFSE_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';
const DANFSE_CONSULTA_RESTRITA = 'https://www.producaorestrita.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

/** Public consultation link of a key: notes of the produção restrita (homologação) exist only in its own portal. */
function danfse_consulta_url(string $key, ?string $environment): string
{
    return (($environment ?? 'production') === 'production' ? DANFSE_CONSULTA : DANFSE_CONSULTA_RESTRITA) . $key;
}
/** Official texts used by the DANFSe for the regime / ISS codes. */
const DANFSE_SIMPLES = ['1' => 'Não Optante', '2' => 'Optante - Microempreendedor Individual (MEI)', '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)'];
const DANFSE_REG_AP = ['1' => 'Regime de apuração dos tributos federais e municipal pelo SN', '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN por fora do SN conforme respectiva legislação municipal do tributo', '3' => 'Regime de apuração dos tributos federais e municipal por fora do SN conforme respectivas legislações federal e municipal de cada tributo'];
const DANFSE_TRIB = ['1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência'];
const DANFSE_RET = ['1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário'];
const DANFSE_REG_ESP = ['0' => 'Nenhum', '1' => 'Ato Cooperado (Cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa Municipal', '4' => 'Notário ou Registrador', '5' => 'Profissional Autônomo', '6' => 'Sociedade de Profissionais'];

/** Date-time from an official XML tag (dhEmi / dhProc), formatted for the DANFSe. */
function danfse_xml_dt(?string $xml, string $tag): ?string
{
    return $xml && preg_match('/<' . $tag . '>([^<]+)<\/' . $tag . '>/', $xml, $m) ? date('d/m/Y H:i:s', strtotime($m[1])) : null;
}

function danfse_money($v): string
{
    return $v === null || $v === '' ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');
}

/** Money that prints "-" when zero (as the official DANFSe does for empty tax fields). */
function danfse_money0($v): string
{
    return (float)$v > 0 ? danfse_money($v) : '-';
}

function danfse_pct($v): string
{
    return (float)$v > 0 ? number_format((float)$v, 2, ',', '.') . '%' : '-';
}

function danfse_doc(?string $d): string
{
    $d = only_digits((string)$d);
    if (strlen($d) === 14) return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    return $d !== '' ? $d : '-';
}

function danfse_phone(?string $p): string
{
    $p = only_digits((string)$p);
    if (strlen($p) === 11) return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $p);
    if (strlen($p) === 10) return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $p);
    return $p !== '' ? $p : '-';
}

function danfse_cep(?string $c): string
{
    $c = only_digits((string)$c);
    return strlen($c) === 8 ? substr($c, 0, 5) . '-' . substr($c, 5) : ($c !== '' ? $c : '-');
}

/** "010701" → "01.07.01" */
function danfse_ctn(?string $c): string
{
    $c = only_digits((string)$c);
    return strlen($c) === 6 ? substr($c, 0, 2) . '.' . substr($c, 2, 2) . '.' . substr($c, 4, 2) : ($c !== '' ? $c : '-');
}

function danfse_dt(?string $s, bool $time = true): string
{
    return $s ? date($time ? 'd/m/Y H:i:s' : 'd/m/Y', strtotime($s)) : '-';
}

/**
 * $d keys: key, number, competence, dh_nfse, dps_number, dps_serie, dh_dps, qr (text), status, homolog (bool),
 * emit/toma/interm: [doc, im, phone, name, email, address, city, cep] (+ emit: simples, reg_ap),
 * serv: [ctn, ctm, local, pais, desc], mun: [trib, pais_result, incid, reg_esp, imun, susp, proc, bm,
 * vserv, desc_incond, ded, calc_bm, bc, aliq, ret, iss], fed: [irrf, cp, csll, pis, cofins, ret_pc, total],
 * tot: [vserv, desc_cond, desc_incond, iss_ret, ret_fed, ret_pc, liquido], aprox: [fed, est, mun], info: string
 */
function danfse_pdf_render(array $d): string
{
    $pdf = new MiniPdf('DANFSe ' . ($d['number'] ?: $d['dps_number']));
    $pdf->addPage();
    $L = 14.0; $R = MiniPdf::W - 14.0; $W = $R - $L;
    $cols = [$L, $L + 134, $L + 281, $L + 426];
    $colW = fn(int $c, int $span) => ($c + $span >= 4 ? $R : $cols[$c + $span]) - $cols[$c] - 8;
    $ink = [0, 0, 0];
    $y = 10.0;
    $bottom = MiniPdf::H - 26;
    $hr = function (float $yy) use ($pdf, $L, $R) { $pdf->line($L, $yy, $R, $yy, 0.6, [60, 60, 60]); };
    $newPage = function () use ($pdf, &$y, $d) {
        $pdf->addPage();
        $y = 20.0;
        $pdf->font(false, 6.5)->color(90, 90, 90)->text(14, $y, 'DANFSe v1.0 · NFS-e nº ' . ($d['number'] ?: '-') . ' · continuação');
        $y += 8;
    };

    // ---- header: logo, title, municipality
    $pdf->font(true, 22)->color(0, 150, 110)->text($L, $y + 21, 'NFS');
    $nfsW = $pdf->width('NFS');
    $pdf->font(true, 22)->color(28, 90, 170)->text($L + $nfsW, $y + 21, 'e');
    $lx = $L + $nfsW + $pdf->width('e') + 4;
    $pdf->font(false, 6.2)->color(80, 80, 80)->text($lx, $y + 12, 'Nota Fiscal de');
    $pdf->text($lx, $y + 20, 'Serviço eletrônica');
    $pdf->font(false, 10)->color(...$ink)->text($L, $y + 8, 'DANFSe v1.0', $W, 'C');
    $pdf->font(false, 10)->text($L, $y + 20, 'Documento Auxiliar da NFS-e', $W, 'C');
    if (!empty($d['municipio'])) {
        $pdf->font(true, 7)->text($L, $y + 10, $d['municipio'], $W, 'R');
        if (!empty($d['municipio_sub'])) $pdf->font(false, 6.5)->text($L, $y + 18, $d['municipio_sub'], $W, 'R');
    }
    $y += 34;

    // generic row: cells = [[col, span, label, value], ...]; returns row height
    $row = function (array $cells, float $lead = 8.8, int $maxLines = 3) use ($pdf, &$y, $cols, $colW, $ink) {
        $h = 0;
        $prepared = [];
        foreach ($cells as [$c, $span, $label, $value]) {
            $pdf->font(false, 7.2);
            $lines = $value === null ? [] : $pdf->wrap((string)($value === '' ? '-' : $value), $colW($c, $span));
            if (count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] .= '…'; }
            $prepared[] = [$c, $label, $lines];
            $h = max($h, 9.5 + count($lines) * $lead);
        }
        foreach ($prepared as [$c, $label, $lines]) {
            $pdf->font(true, 6.6)->color(...$ink)->text($cols[$c], $y + 7, $label);
            $pdf->font(false, 7.2);
            $yy = $y + 8 + $lead;
            foreach ($lines as $l) { $pdf->text($cols[$c], $yy, $l); $yy += $lead; }
        }
        $y += $h + 4;
        return $h;
    };
    $ensure = function (float $need) use (&$y, $bottom, $newPage) { if ($y + $need > $bottom) $newPage(); };
    $title = function (string $t, ?string $sub = null) use ($pdf, &$y, $L, $ink) {
        $pdf->font(true, 7.8)->color(...$ink)->text($L, $y + 8, $t);
        if ($sub) $pdf->font(false, 6.8)->text($L, $y + 16.5, $sub);
    };

    // ---- identification + QR
    $qrSize = 62.0;
    $qx = $R - 75 - $qrSize / 2; $qy = $y;
    if (!empty($d['qr'])) {
        try {
            $m = qr_matrix($d['qr']);
            $n = count($m);
            $mod = $qrSize / $n;
            foreach ($m as $r => $rowm) foreach ($rowm as $c => $dark) if ($dark) $pdf->rect($qx + $c * $mod, $qy + $r * $mod, $mod + 0.05, $mod + 0.05, [0, 0, 0], null);
        } catch (Throwable $e) { /* no QR if the text does not fit */ }
    }
    $pdf->font(false, 5.6)->color(...$ink);
    $qt = $y + $qrSize + 8;
    foreach ($pdf->wrap('A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e', 150) as $l) { $pdf->text($R - 150, $qt, $l); $qt += 6.8; }
    $row([[0, 3, 'Chave de Acesso da NFS-e', $d['key'] ?: 'Não se aplica']]);
    $row([[0, 1, 'Número da NFS-e', $d['number'] ?: '-'], [1, 1, 'Competência da NFS-e', $d['competence']], [2, 1, 'Data e Hora da emissão da NFS-e', $d['dh_nfse']]]);
    $row([[0, 1, 'Número da DPS', $d['dps_number']], [1, 1, 'Série da DPS', $d['dps_serie']], [2, 1, 'Data e Hora da emissão da DPS', $d['dh_dps']]]);
    $y = max($y, $qt) + 2;
    $hr($y);

    // ---- people
    $person = function (string $heading, ?string $sub, ?array $p, bool $emit) use (&$y, $row, $title, $hr, $pdf, $L, $W, $ensure) {
        $ensure(80);
        $y0 = $y;
        $title($heading, $sub);
        $y = $y0;
        $row([[1, 1, 'CNPJ / CPF / NIF', danfse_doc($p['doc'] ?? '')], [2, 1, 'Inscrição Municipal', ($p['im'] ?? '') ?: '-'], [3, 1, 'Telefone', danfse_phone($p['phone'] ?? '')]]);
        $y = max($y, $y0 + 22);
        $row([[0, 2, 'Nome / Nome empresarial', $p['name'] ?? '-'], [2, 2, 'E-mail', ($p['email'] ?? '') ?: '-']]);
        $row([[0, 2, 'Endereço', ($p['address'] ?? '') ?: '-'], [2, 1, 'Município', ($p['city'] ?? '') ?: '-'], [3, 1, 'CEP', danfse_cep($p['cep'] ?? '')]]);
        if ($emit) $row([[0, 2, 'Simples Nacional na Data da Competência', $p['simples'] ?? '-'], [2, 2, 'Regime de Apuração Tributária pelo SN', ($p['reg_ap'] ?? '') ?: '-']]);
        $hr($y);
    };
    $person('EMITENTE DA NFS-e', 'Prestador do Serviço', $d['emit'], true);
    if ($d['toma']) {
        $person('TOMADOR DO SERVIÇO', null, $d['toma'], false);
    } else {
        $pdf->font(false, 6.8)->text($L, $y + 8, 'TOMADOR DO SERVIÇO NÃO IDENTIFICADO NA NFS-e', $W, 'C');
        $y += 11; $hr($y);
    }
    if ($d['interm']) {
        $person('INTERMEDIÁRIO DO SERVIÇO', null, $d['interm'], false);
    } else {
        $pdf->font(false, 6.8)->text($L, $y + 8, 'INTERMEDIÁRIO DO SERVIÇO NÃO IDENTIFICADO NA NFS-e', $W, 'C');
        $y += 11; $hr($y);
    }

    // ---- service (the description may continue on the next page)
    $ensure(60);
    $title('SERVIÇO PRESTADO');
    $y += 11;
    $s = $d['serv'];
    $row([[0, 1, 'Código de Tributação Nacional', $s['ctn']], [1, 1, 'Código de Tributação Municipal', $s['ctm'] ?: '-'], [2, 1, 'Local da Prestação', $s['local'] ?: '-'], [3, 1, 'País da Prestação', $s['pais'] ?: '-']]);
    $pdf->font(false, 7.2);
    $desc = $pdf->wrap((string)$s['desc'], $W - 8);
    $first = true;
    while ($desc) {
        $fit = max(1, (int)floor(($bottom - $y - 12) / 8.2));
        if ($fit < 3 && !$first) { $newPage(); continue; }
        $chunk = array_splice($desc, 0, $fit);
        $pdf->font(true, 6.6)->color(0, 0, 0)->text($L, $y + 7, $first ? 'Descrição do Serviço' : 'Descrição do Serviço (continuação)');
        $pdf->font(false, 7.2);
        $yy = $y + 15.2;
        foreach ($chunk as $l) { $pdf->text($L, $yy, $l); $yy += 8.2; }
        $y = $yy;
        $first = false;
        if ($desc) $newPage();
    }
    $y += 2; $hr($y);

    // ---- taxes
    $mun = $d['mun'];
    $ensure(100);
    $title('TRIBUTAÇÃO MUNICIPAL'); $y += 11;
    $row([[0, 1, 'Tributação do ISSQN', $mun['trib']], [1, 1, 'País Resultado da Prestação do Serviço', $mun['pais_result'] ?: '-'], [2, 1, 'Município de Incidência do ISSQN', $mun['incid'] ?: '-'], [3, 1, 'Regime Especial de Tributação', $mun['reg_esp']]]);
    $row([[0, 1, 'Tipo de Imunidade', $mun['imun'] ?: '-'], [1, 1, 'Suspensão da Exigibilidade do ISSQN', $mun['susp']], [2, 1, 'Número Processo Suspensão', $mun['proc'] ?: '-'], [3, 1, 'Benefício Municipal', $mun['bm'] ?: '-']]);
    $row([[0, 1, 'Valor do Serviço', danfse_money($mun['vserv'])], [1, 1, 'Desconto Incondicionado', danfse_money0($mun['desc_incond'])], [2, 1, 'Total Deduções/Reduções', danfse_money0($mun['ded'])], [3, 1, 'Cálculo do BM', danfse_money0($mun['calc_bm'])]]);
    $row([[0, 1, 'BC ISSQN', danfse_money0($mun['bc'])], [1, 1, 'Alíquota Aplicada', danfse_pct($mun['aliq'])], [2, 1, 'Retenção do ISSQN', $mun['ret']], [3, 1, 'ISSQN Apurado', danfse_money0($mun['iss'])]]);
    $hr($y);
    $f = $d['fed'];
    $ensure(50);
    $title('TRIBUTAÇÃO FEDERAL'); $y += 11;
    $row([[0, 1, 'IRRF', danfse_money0($f['irrf'])], [1, 1, 'CP', danfse_money0($f['cp'])], [2, 1, 'CSLL', danfse_money0($f['csll'])]]);
    $row([[0, 1, 'PIS', danfse_money0($f['pis'])], [1, 1, 'COFINS', danfse_money0($f['cofins'])], [2, 1, 'Retenção do PIS/COFINS', $f['ret_pc'] ?: '-'], [3, 1, 'TOTAL TRIBUTAÇÃO FEDERAL', danfse_money0($f['total'])]]);
    $hr($y);
    $t = $d['tot'];
    $ensure(50);
    $title('VALOR TOTAL DA NFS-E'); $y += 11;
    $row([[0, 1, 'Valor do Serviço', danfse_money($t['vserv'])], [1, 1, 'Desconto Condicionado', danfse_money0($t['desc_cond'])], [2, 1, 'Desconto Incondicionado', danfse_money0($t['desc_incond'])], [3, 1, 'ISSQN Retido', danfse_money0($t['iss_ret'])]]);
    $row([[0, 1, 'IRRF, CP,CSLL - Retidos', danfse_money($t['ret_fed'])], [1, 1, 'PIS/COFINS Retidos', danfse_money0($t['ret_pc'])], [3, 1, 'Valor Líquido da NFS-e', danfse_money($t['liquido'])]]);
    $hr($y);
    $a = $d['aprox'];
    $ensure(30);
    $title('TOTAIS APROXIMADOS DOS TRIBUTOS'); $y += 10;
    $third = $W / 3;
    foreach ([['Federais', $a['fed']], ['Estaduais', $a['est']], ['Municipais', $a['mun']]] as $i => [$lbl, $val]) {
        $pdf->font(true, 6.6)->text($L + $i * $third, $y + 7, $lbl, $third, 'C');
        $pdf->font(false, 7.2)->text($L + $i * $third, $y + 15.2, danfse_money($val), $third, 'C');
    }
    $y += 19; $hr($y);

    // ---- complementary information
    $ensure(24);
    $title('INFORMAÇÕES COMPLEMENTARES'); $y += 11;
    $pdf->font(false, 7);
    foreach ($pdf->wrap(trim((string)$d['info']) ?: '-', $W - 4) as $l) {
        if ($y + 9 > $bottom) $newPage();
        $pdf->text($L, $y + 7, $l);
        $y += 8.4;
    }

    if (!empty($d['watermark'])) $pdf->watermarkBehind($d['watermark'], $d['watermark_rgb'] ?? [225, 229, 236]);
    return $pdf->output();
}

/** Normalized DANFSe data of an admin-panel invoice (nfse_invoices: the company's own NFS-e). */
function nfse_danfse_data(array $inv): array
{
    $cfg = nfse_config();
    $c = !empty($inv['customer_id']) ? (db_find('customers', (int)$inv['customer_id']) ?: []) : [];
    $op = (string)$cfg['op_simp_nac'];
    [$regAp, $regEsp] = nfse_regime($op, $cfg['reg_ap_trib_sn'] ?? '', $cfg['reg_esp_trib'] ?? '0', '1');
    $key = (string)($inv['access_key'] ?? '');
    $iss = (float)$inv['iss_amount'];
    $w = fn($k) => !empty($inv[$k . '_withheld']) ? (float)($inv[$k . '_amount'] ?? 0) : 0;
    $fedSum = (float)($inv['pis_amount'] ?? 0) + (float)($inv['cofins_amount'] ?? 0) + (float)($inv['csll_amount'] ?? 0) + (float)($inv['irrf_amount'] ?? 0) + $w('inss');
    $total = (float)($inv['total_taxes_amount'] ?? 0);
    $mun = $total > 0 ? min($iss, $total) : $iss;
    $nbs = (string)($inv['nbs_code'] ?? '');
    $homolog = ($inv['environment'] ?? 'production') !== 'production';
    $city = trim(((string)setting('company_city', 'Marília')) . ' - ' . ((string)setting('company_state', 'SP')), ' -');
    $info = array_filter([
        $nbs !== '' ? 'NBS: ' . $nbs . (($n = nfse_tables()['nbs'][$nbs] ?? '') ? ' - ' . $n : '') : '',
        ($inv['provider'] ?? '') === 'sigiss' && !empty($inv['print_url']) ? 'Via oficial da prefeitura (SIGISS): ' . $inv['print_url'] : '',
        $homolog ? 'NFS-e EMITIDA EM AMBIENTE DE PRODUÇÃO RESTRITA (HOMOLOGAÇÃO) - SEM VALOR FISCAL' : '',
    ]);
    $status = (string)$inv['status'];
    return [
        'key' => $key, 'number' => (string)($inv['nfse_number'] ?? ''), 'competence' => danfse_dt($inv['competence_date'] ?? null, false),
        'dh_nfse' => danfse_xml_dt($inv['xml_nfse'] ?? null, 'dhProc') ?? danfse_dt($inv['issued_at'] ?? null), 'dps_number' => (string)$inv['dps_number'], 'dps_serie' => (string)$inv['dps_serie'],
        'dh_dps' => danfse_xml_dt($inv['xml_dps'] ?? null, 'dhEmi') ?? danfse_dt($inv['issued_at'] ?: $inv['created_at']),
        'qr' => $key !== '' ? danfse_consulta_url($key, $inv['environment'] ?? null) : ((string)($inv['print_url'] ?? '') ?: null),
        'emit' => ['doc' => $cfg['cnpj'], 'im' => $cfg['im'], 'phone' => (string)setting('company_phone', COMPANY['phone']), 'name' => (string)setting('company_name', COMPANY['name']),
            'email' => (string)setting('company_email', COMPANY['email']), 'address' => (string)setting('company_address', ''), 'city' => $city, 'cep' => (string)setting('company_cep', ''),
            'simples' => DANFSE_SIMPLES[$op] ?? '-', 'reg_ap' => $regAp !== '' ? DANFSE_REG_AP[$regAp] : '-'],
        'toma' => only_digits((string)$inv['toma_document']) !== '' ? ['doc' => $inv['toma_document'], 'im' => '', 'phone' => $c['phone'] ?? '', 'name' => $inv['toma_name'], 'email' => $inv['toma_email'] ?? '',
            'address' => trim(implode(', ', array_filter([trim(($c['address'] ?? '') . ', ' . ($c['address_number'] ?? ''), ', '), $c['district'] ?? '']))), 'city' => trim(($c['city'] ?? '') . (!empty($c['state']) ? ' - ' . $c['state'] : ''), ' -'), 'cep' => $c['postal_code'] ?? ''] : null,
        'interm' => null,
        'serv' => ['ctn' => danfse_ctn($inv['service_code']) . (($n = nfse_tables()['ctribnac'][(string)$inv['service_code']] ?? '') ? ' - ' . $n : ''), 'ctm' => '', 'local' => $city, 'pais' => '', 'desc' => (string)$inv['description']],
        'mun' => ['trib' => 'Operação Tributável', 'pais_result' => '', 'incid' => $city, 'reg_esp' => DANFSE_REG_ESP[$regEsp] ?? 'Nenhum', 'imun' => '', 'susp' => 'Não', 'proc' => '', 'bm' => '',
            'vserv' => $inv['amount'], 'desc_incond' => $inv['discount_amount'] ?? 0, 'ded' => $inv['deductions'] ?? 0, 'calc_bm' => 0,
            'bc' => (float)$inv['amount'] - (float)($inv['discount_amount'] ?? 0) - (float)($inv['deductions'] ?? 0), 'aliq' => $inv['iss_rate'], 'ret' => !empty($inv['iss_withheld']) ? 'Retido pelo Tomador' : 'Não Retido', 'iss' => $iss],
        'fed' => ['irrf' => $inv['irrf_amount'] ?? 0, 'cp' => $w('inss'), 'csll' => $inv['csll_amount'] ?? 0, 'pis' => $inv['pis_amount'] ?? 0, 'cofins' => $inv['cofins_amount'] ?? 0,
            'ret_pc' => ((float)($inv['pis_amount'] ?? 0) > 0 || (float)($inv['cofins_amount'] ?? 0) > 0) ? (($w('pis') || $w('cofins')) ? 'PIS/COFINS Retidos' : 'PIS/COFINS Não Retidos') : '', 'total' => $fedSum],
        'tot' => ['vserv' => $inv['amount'], 'desc_cond' => 0, 'desc_incond' => $inv['discount_amount'] ?? 0, 'iss_ret' => !empty($inv['iss_withheld']) ? $iss : 0,
            'ret_fed' => $w('irrf') + $w('inss') + $w('csll'), 'ret_pc' => $w('pis') + $w('cofins'), 'liquido' => $inv['net_amount'] ?? $inv['amount']],
        'aprox' => ['fed' => $total > 0 ? max(0, $total - $mun) : $fedSum, 'est' => 0, 'mun' => $mun],
        'info' => implode("\n", $info),
        'watermark' => $status === 'canceled' ? 'CANCELADA' : ($status !== 'authorized' ? 'SEM VALOR FISCAL' : ($homolog ? 'SEM VALOR FISCAL' : null)),
        'watermark_rgb' => $status === 'canceled' ? [248, 205, 210] : [225, 229, 236],
    ];
}

