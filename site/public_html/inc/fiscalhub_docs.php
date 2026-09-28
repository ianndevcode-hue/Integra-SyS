<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — documents and analytics: DANFSe PDF, XML, e-mail to the taker,
 * ZIP downloads (single / selected / whole period), CSV export, reports (+ AI analysis),
 * batch import from spreadsheets and recurring invoices.
 */

require_once INC_PATH . '/pdf.php';

const FH_STATUS = ['draft' => 'Rascunho', 'processing' => 'Transmitindo', 'authorized' => 'Emitida', 'rejected' => 'Rejeitada', 'canceled' => 'Cancelada'];

function fh_doc_fmt(string $d): string
{
    $d = only_digits($d);
    if (strlen($d) === 14) return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    if (strlen($d) === 11) return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    return $d;
}

function fh_money($v): string
{
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function fh_file_base(array $inv): string
{
    return 'NFSe-' . ($inv['nfse_number'] ?: 'DPS' . $inv['dps_number']) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', strip_accents(mb_substr((string)$inv['toma_name'], 0, 30)));
}

/* ================================================================== PDF */

/** Normalized DANFSe data of a Fiscal Hub invoice. */
function fh_danfse_data(array $inv, array $em): array
{
    require_once INC_PATH . '/danfse_pdf.php';
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $t = json_decode((string)$inv['toma_json'], true) ?: [];
    $addr = fn(array $a) => trim(implode(', ', array_filter([trim(($a['street'] ?? '') . ', ' . ($a['number'] ?? ''), ', '), $a['complement'] ?? '', $a['district'] ?? ''])));
    $cityUf = fn(array $a) => trim(($a['city'] ?? '') . (!empty($a['uf']) ? ' - ' . $a['uf'] : ''), ' -');
    [$regAp, $regEsp] = nfse_regime((string)$em['op_simp_nac'], $em['reg_ap_trib_sn'] ?? '', $em['reg_esp_trib'] ?? '0', (string)$inv['trib_issqn']);
    $key = (string)$inv['access_key'];
    $toma = null;
    if ($inv['toma_kind'] !== 'pfni') {
        $toma = ['doc' => $inv['toma_kind'] === 'ext' ? ($t['nif'] ?? '') : $inv['toma_document'], 'im' => $t['im'] ?? '', 'phone' => $t['phone'] ?? '', 'name' => $inv['toma_name'], 'email' => $inv['toma_email'] ?? '',
            'address' => $addr($t), 'city' => $inv['toma_kind'] === 'ext' ? trim(($t['foreign_city'] ?? '') . ' - ' . ($t['country'] ?? ''), ' -') : $cityUf($t), 'cep' => $inv['toma_kind'] === 'ext' ? ($t['foreign_postal'] ?? '') : ($t['cep'] ?? '')];
    }
    $interm = !empty($x['interm']) ? ['doc' => $x['interm']['document'] ?: $x['interm']['nif'], 'im' => $x['interm']['im'] ?? '', 'phone' => $x['interm']['phone'] ?? '', 'name' => $x['interm']['name'], 'email' => $x['interm']['email'] ?? '', 'address' => '', 'city' => '', 'cep' => ''] : null;
    $emCity = $cityUf(['city' => $em['city'] ?: 'Marília', 'uf' => $em['uf'] ?: 'SP']);
    $loc = $x['loc'] ?? [];
    $local = ($loc['type'] ?? '') === 'outro' ? trim(($loc['city'] ?? '') ?: 'IBGE ' . $loc['city_ibge']) : (($loc['type'] ?? '') === 'exterior' ? '-' : $emCity);
    $ctnName = nfse_tables()['ctribnac'][(string)$inv['ctribnac']] ?? '';
    if ($ctnName === '') foreach (fh_lc116_list() as [$c, $n]) if ($c === $inv['lc116']) { $ctnName = $n; break; }
    $pc = (int)!empty($inv['pis_withheld']) . (int)!empty($inv['cofins_withheld']);
    $retFed = ($inv['irrf_withheld'] ? (float)$inv['irrf_amount'] : 0) + ($inv['inss_withheld'] ? (float)$inv['inss_amount'] : 0) + ($inv['csll_withheld'] ? (float)$inv['csll_amount'] : 0);
    $retPc = ($inv['pis_withheld'] ? (float)$inv['pis_amount'] : 0) + ($inv['cofins_withheld'] ? (float)$inv['cofins_amount'] : 0);
    $fedSum = (float)$inv['pis_amount'] + (float)$inv['cofins_amount'] + (float)$inv['csll_amount'] + (float)$inv['irrf_amount'] + ($inv['inss_withheld'] ? (float)$inv['inss_amount'] : 0);
    $iss = (float)$inv['iss_amount'];
    $totalAprox = (float)$inv['total_taxes_amount'];
    $aproxMun = $totalAprox > 0 ? min($iss, $totalAprox) : $iss;
    $aproxFed = $totalAprox > 0 ? max(0, $totalAprox - $aproxMun) : $fedSum;
    $nbs = (string)($inv['cnbs'] ?? '');
    $info = [];
    if ($nbs !== '') $info[] = 'NBS: ' . $nbs . (($n = nfse_tables()['nbs'][$nbs] ?? '') ? ' - ' . $n : '');
    if (!empty($x['vencimento'])) $info[] = 'Data de vencimento: ' . date('d/m/Y', strtotime($x['vencimento']));
    if ($inv['lc116']) $info[] = 'Item da lista de serviços (LC 116/2003): ' . $inv['lc116'];
    if ($inv['provider'] === 'sigiss') $info[] = 'NFS-e emitida pelo SIGISS da Prefeitura de Marília' . ($inv['verification_code'] ? ' · código de verificação ' . $inv['verification_code'] : '') . ($inv['print_url'] ? ' · via oficial: ' . $inv['print_url'] : '');
    if (!empty($x['obra'])) $info[] = 'Obra: ' . trim(implode(' ', array_filter([$x['obra']['codigo'] ?? '', $x['obra']['cib'] ?? '', $x['obra']['street'] ?? '', $x['obra']['number'] ?? '', $x['obra']['cep'] ?? ''])));
    if (!empty($x['evento'])) $info[] = 'Evento: ' . $x['evento']['nome'] . ' (' . date('d/m/Y', strtotime($x['evento']['inicio'])) . ' a ' . date('d/m/Y', strtotime($x['evento']['fim'])) . ')';
    if (!empty($x['comext'])) $info[] = 'Exportação de serviço · moeda ' . $x['comext']['moeda'] . ' · valor na moeda ' . number_format((float)$x['comext']['valor_moeda'], 2, ',', '.');
    if (!empty($x['subst'])) $info[] = 'Substitui a NFS-e de chave de acesso ' . $x['subst']['chave'];
    if (!empty($x['info']['pedido'])) $info[] = 'Pedido: ' . $x['info']['pedido'];
    if (!empty($x['info']['doc_ref'])) $info[] = 'Documento de referência: ' . $x['info']['doc_ref'];
    if (!empty($x['info']['complementar'])) $info[] = $x['info']['complementar'];
    $homolog = $inv['environment'] !== 'production';
    if ($homolog) $info[] = 'NFS-e EMITIDA EM AMBIENTE DE PRODUÇÃO RESTRITA (HOMOLOGAÇÃO) - SEM VALOR FISCAL';
    $wm = $inv['status'] === 'canceled' ? 'CANCELADA' : ($inv['status'] !== 'authorized' ? mb_strtoupper(FH_STATUS[$inv['status']] ?? 'RASCUNHO') : ($homolog ? 'SEM VALOR FISCAL' : null));
    return [
        'key' => $key, 'number' => (string)($inv['nfse_number'] ?? ''), 'competence' => danfse_dt($inv['competence_date'], false),
        'dh_nfse' => danfse_xml_dt($inv['xml_nfse'] ?? null, 'dhProc') ?? danfse_dt($inv['issued_at']), 'dps_number' => (string)$inv['dps_number'], 'dps_serie' => (string)$inv['dps_serie'],
        'dh_dps' => danfse_xml_dt($inv['xml_dps'] ?? null, 'dhEmi') ?? danfse_dt($inv['issued_at'] ?: $inv['created_at']),
        'qr' => $key !== '' ? DANFSE_CONSULTA . $key : ((string)($inv['print_url'] ?? '') ?: null),
        'emit' => ['doc' => $em['document'], 'im' => $em['im'] ?? '', 'phone' => $em['phone'] ?? '', 'name' => $em['legal_name'], 'email' => $em['email'] ?? '',
            'address' => $addr($em), 'city' => $emCity, 'cep' => $em['cep'] ?? '', 'simples' => DANFSE_SIMPLES[$em['op_simp_nac']] ?? '-', 'reg_ap' => $regAp !== '' ? DANFSE_REG_AP[$regAp] : '-'],
        'toma' => $toma, 'interm' => $interm,
        'serv' => ['ctn' => danfse_ctn($inv['ctribnac']) . ($ctnName ? ' - ' . $ctnName : ''), 'ctm' => $inv['provider'] === 'sigiss' ? ($inv['sigiss_code'] ? $inv['sigiss_code'] . ' (SIGISS)' : '') : (string)($inv['ctribmun'] ?? ''),
            'local' => $local, 'pais' => ($loc['type'] ?? '') === 'exterior' ? $loc['country'] : '', 'desc' => (string)$inv['description']],
        'mun' => ['trib' => DANFSE_TRIB[(string)$inv['trib_issqn']] ?? 'Operação Tributável', 'pais_result' => $x['pais_resultado'] ?? '', 'incid' => $local !== '-' ? $local : $emCity,
            'reg_esp' => DANFSE_REG_ESP[$regEsp] ?? 'Nenhum', 'imun' => (string)$inv['trib_issqn'] === '2' ? (FH_IMUNIDADES[$x['imunidade'] ?? ''] ?? '') : '',
            'susp' => !empty($x['exig']) ? 'Sim' : 'Não', 'proc' => !empty($x['exig']) ? ltrim($x['exig']['processo'], '0') : '', 'bm' => $x['bm']['numero'] ?? '',
            'vserv' => $inv['amount'], 'desc_incond' => $inv['discount_incond'], 'ded' => $inv['deductions'], 'calc_bm' => $x['bm']['valor'] ?? 0,
            'bc' => $inv['base'], 'aliq' => $inv['iss_rate'], 'ret' => DANFSE_RET[(string)$inv['iss_retention']] ?? 'Não Retido', 'iss' => $iss],
        'fed' => ['irrf' => $inv['irrf_amount'], 'cp' => $inv['inss_withheld'] ? $inv['inss_amount'] : 0, 'csll' => $inv['csll_amount'], 'pis' => $inv['pis_amount'], 'cofins' => $inv['cofins_amount'],
            'ret_pc' => ((float)$inv['pis_amount'] > 0 || (float)$inv['cofins_amount'] > 0) ? ['00' => 'PIS/COFINS Não Retidos', '11' => 'PIS/COFINS Retidos', '10' => 'PIS Retido, COFINS Não Retido', '01' => 'COFINS Retido, PIS Não Retido'][$pc] : '',
            'total' => $fedSum],
        'tot' => ['vserv' => $inv['amount'], 'desc_cond' => $inv['discount_cond'], 'desc_incond' => $inv['discount_incond'], 'iss_ret' => $inv['iss_retention'] !== '1' ? $iss : 0,
            'ret_fed' => $retFed, 'ret_pc' => $retPc, 'liquido' => $inv['net_amount']],
        'aprox' => ['fed' => $aproxFed, 'est' => 0, 'mun' => $aproxMun],
        'info' => implode("\n", $info),
        'watermark' => $wm, 'watermark_rgb' => $inv['status'] === 'canceled' ? [248, 205, 210] : [225, 229, 236],
    ];
}

/** DANFSe v1.0 (official national layout) of a Fiscal Hub invoice. */
function fh_pdf(array $inv, array $em): string
{
    require_once INC_PATH . '/danfse_pdf.php';
    return danfse_pdf_render(fh_danfse_data($inv, $em));
}

/** Official XML (Emissor Nacional) or a structured XML with the note data (SIGISS). */
function fh_xml(array $inv, array $em): string
{
    if ($inv['provider'] === 'nacional' && !empty($inv['xml_nfse'])) return (string)$inv['xml_nfse'];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->appendChild($dom->createElement('NotaFiscalServico'));
    $root->setAttribute('origem', $inv['provider'] === 'sigiss' ? 'SIGISS-Marilia' : 'EmissorNacional');
    $root->setAttribute('geradoPor', 'Integra Fiscal Hub');
    $add = function (DOMElement $p, string $n, $v) use ($dom) { if ($v !== null && $v !== '') $p->appendChild($dom->createElement($n))->appendChild($dom->createTextNode((string)$v)); };
    foreach (['numero' => $inv['nfse_number'], 'codigoVerificacao' => $inv['verification_code'], 'chaveAcesso' => $inv['access_key'], 'situacao' => FH_STATUS[$inv['status']] ?? $inv['status'], 'dataEmissao' => $inv['issued_at'], 'competencia' => $inv['competence_date'], 'rps' => $inv['dps_serie'] . '-' . $inv['dps_number'], 'linkOficial' => $inv['print_url']] as $k => $v) $add($root, $k, $v);
    $p = $root->appendChild($dom->createElement('Prestador'));
    foreach (['documento' => $em['document'], 'razaoSocial' => $em['legal_name'], 'inscricaoMunicipal' => $em['im'], 'municipio' => $em['city_ibge']] as $k => $v) $add($p, $k, $v);
    $t = $root->appendChild($dom->createElement('Tomador'));
    $tj = json_decode((string)$inv['toma_json'], true) ?: [];
    foreach (['documento' => $inv['toma_document'], 'nome' => $inv['toma_name'], 'email' => $inv['toma_email'], 'cep' => $tj['cep'] ?? '', 'logradouro' => $tj['street'] ?? '', 'numero' => $tj['number'] ?? '', 'bairro' => $tj['district'] ?? '', 'municipio' => $tj['city_ibge'] ?? ''] as $k => $v) $add($t, $k, $v);
    $s = $root->appendChild($dom->createElement('Servico'));
    foreach (['itemLC116' => $inv['lc116'], 'codigoSigiss' => $inv['sigiss_code'], 'codigoTributacaoNacional' => $inv['ctribnac'], 'nbs' => $inv['cnbs'], 'discriminacao' => $inv['description']] as $k => $v) $add($s, $k, $v);
    $v = $root->appendChild($dom->createElement('Valores'));
    foreach (['valorServico' => 'amount', 'descontoIncondicionado' => 'discount_incond', 'descontoCondicionado' => 'discount_cond', 'deducoes' => 'deductions', 'baseCalculo' => 'base', 'aliquotaIss' => 'iss_rate', 'valorIss' => 'iss_amount',
        'valorPis' => 'pis_amount', 'valorCofins' => 'cofins_amount', 'valorCsll' => 'csll_amount', 'valorIrrf' => 'irrf_amount', 'valorInss' => 'inss_amount', 'totalRetencoes' => 'withheld_total', 'valorLiquido' => 'net_amount', 'tributosAproximados' => 'total_taxes_amount'] as $k => $col) {
        $add($v, $k, number_format((float)$inv[$col], 2, '.', ''));
    }
    $add($v, 'issRetido', $inv['iss_retention'] !== '1' ? 'sim' : 'nao');
    return $dom->saveXML();
}

function fh_email_invoice(array $inv, array $em, ?string $to = null): void
{
    $to = $to ?: (string)$inv['toma_email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new AppException('O cliente não tem e-mail válido.');
    if ($inv['status'] !== 'authorized') throw new AppException('Somente notas emitidas podem ser enviadas.');
    $name = $em['trade_name'] ?: $em['legal_name'];
    $base = fh_file_base($inv);
    $atts = [['name' => $base . '.pdf', 'content' => fh_pdf($inv, $em), 'type' => 'application/pdf'], ['name' => $base . '.xml', 'content' => fh_xml($inv, $em), 'type' => 'application/xml']];
    $msg = trim((string)$em['email_message']) !== '' ? '<p>' . nl2br(e((string)$em['email_message'])) . '</p>' : '<p>Olá! Segue a nota fiscal de serviço referente aos serviços prestados por <b>' . e($name) . '</b>. O PDF e o XML estão anexados.</p>';
    mail_queue($to, 'Nota fiscal de serviço nº ' . ($inv['nfse_number'] ?: $inv['dps_number']) . ' — ' . $name, mail_template('Nota fiscal de serviço emitida',
        $msg . mail_details(['Número' => $inv['nfse_number'] ?: '—', 'Emissão' => $inv['issued_at'] ? date('d/m/Y', strtotime($inv['issued_at'])) : '', 'Valor' => money($inv['amount']), 'Valor líquido' => money($inv['net_amount']), 'Código de verificação' => (string)$inv['verification_code']])
        . ($inv['print_url'] ? '<p><a href="' . e($inv['print_url']) . '" style="color:#0066fe">Ver a nota no site da prefeitura</a></p>' : ''), null, 'Enviado por ' . $name . ' via Integra Fiscal Hub.'),
        ['event' => 'fh_invoice', 'name' => $inv['toma_name'], 'reply_to' => filter_var($em['email'], FILTER_VALIDATE_EMAIL) ? $em['email'] : null, 'attachments' => $atts]);
    db_update('fh_invoices', (int)$inv['id'], ['emailed_at' => now()]);
}

/* ============================================================ LIST/EXPORT */

/** Filtered list for a customer: emitter_id, status, from, to, q, taker_id, service_id, page, per_page, sort, dir. */
function fh_invoices_query(int $customerId, array $f, bool $paginate = true): array
{
    $w = ['i.customer_id = ?'];
    $p = [$customerId];
    if (!empty($f['emitter_id'])) { $w[] = 'i.emitter_id = ?'; $p[] = (int)$f['emitter_id']; }
    if (!empty($f['status']) && isset(FH_STATUS[$f['status']])) { $w[] = 'i.status = ?'; $p[] = $f['status']; }
    if (!empty($f['taker_id'])) { $w[] = 'i.taker_id = ?'; $p[] = (int)$f['taker_id']; }
    if (!empty($f['service_id'])) { $w[] = 'i.service_id = ?'; $p[] = (int)$f['service_id']; }
    if (!empty($f['ids'])) {
        $ids = array_values(array_filter(array_map('intval', is_array($f['ids']) ? $f['ids'] : explode(',', (string)$f['ids']))));
        if (!$ids) $ids = [0];
        $w[] = 'i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $p = array_merge($p, $ids);
    }
    $dateCol = 'COALESCE(i.issued_at, i.created_at)';
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['from'])) { $w[] = "$dateCol >= ?"; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['to'])) { $w[] = "$dateCol <= ?"; $p[] = $f['to'] . ' 23:59:59'; }
    if (!empty($f['q'])) {
        $q = '%' . mb_strtolower(trim((string)$f['q'])) . '%';
        $w[] = '(LOWER(i.toma_name) LIKE ? OR i.nfse_number LIKE ? OR LOWER(i.description) LIKE ? OR i.toma_document LIKE ?)';
        array_push($p, $q, $q, $q, '%' . only_digits((string)$f['q']) . '%');
    }
    $where = implode(' AND ', $w);
    $sorts = ['issued_at' => $dateCol, 'amount' => 'i.amount', 'nfse_number' => 'i.dps_number', 'toma_name' => 'i.toma_name', 'created_at' => 'i.created_at'];
    $sort = $sorts[$f['sort'] ?? ''] ?? 'i.id';
    $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $select = "SELECT i.id, i.emitter_id, i.provider, i.environment, i.status, i.source, i.dps_serie, i.dps_number, i.nfse_number, i.access_key, i.verification_code, i.print_url, i.toma_kind, i.toma_document, i.toma_name, i.toma_email,
        i.service_name, i.lc116, i.description, i.competence_date, i.amount, i.base, i.iss_rate, i.iss_amount, i.iss_retention, i.sigiss_situacao, i.withheld_total, i.net_amount, i.total_taxes_amount,
        i.pis_amount, i.cofins_amount, i.csll_amount, i.irrf_amount, i.inss_amount, i.discount_incond, i.deductions, i.error_message, i.issued_at, i.canceled_at, i.emailed_at, i.created_at, i.taker_id, i.service_id,
        e.legal_name AS emitter_name, e.document AS emitter_document FROM fh_invoices i JOIN fh_emitters e ON e.id = i.emitter_id WHERE $where";
    if (!$paginate) return db_all("$select ORDER BY $sort $dir LIMIT 5000", $p);
    $per = max(1, min(200, (int)($f['per_page'] ?? 25)));
    $page = max(1, (int)($f['page'] ?? 1));
    $total = (int)db_value("SELECT COUNT(*) FROM fh_invoices i WHERE $where", $p);
    $sum = db_one("SELECT COALESCE(SUM(CASE WHEN status='authorized' THEN amount END),0) AS amount, COALESCE(SUM(CASE WHEN status='authorized' THEN iss_amount END),0) AS iss, COALESCE(SUM(CASE WHEN status='authorized' THEN net_amount END),0) AS net FROM fh_invoices i WHERE $where", $p);
    return ['data' => db_all("$select ORDER BY $sort $dir LIMIT $per OFFSET " . (($page - 1) * $per), $p), 'total' => $total, 'page' => $page, 'per_page' => $per, 'sum' => $sum];
}

/** ZIP with PDF and/or XML of the given invoices. $what: pdf | xml | both */
function fh_zip(array $rows, string $what): string
{
    $files = [];
    $emitters = [];
    $csv = [];
    foreach ($rows as $r) {
        $inv = db_find('fh_invoices', (int)$r['id']);
        if (!$inv || in_array($inv['status'], ['draft'], true)) continue;
        $em = $emitters[$inv['emitter_id']] ??= db_find('fh_emitters', (int)$inv['emitter_id']);
        $folder = count(array_unique(array_column($rows, 'emitter_id'))) > 1 ? preg_replace('/[^A-Za-z0-9]+/', '-', strip_accents(mb_substr($em['legal_name'], 0, 30))) . '/' : '';
        $sub = $inv['status'] === 'canceled' ? 'canceladas/' : ($inv['status'] === 'rejected' ? 'rejeitadas/' : '');
        $base = $folder . $sub . fh_file_base($inv);
        if ($what !== 'xml') $files[$base . '.pdf'] = fh_pdf($inv, $em);
        if ($what !== 'pdf' && $inv['status'] !== 'rejected') $files[$base . '.xml'] = fh_xml($inv, $em);
        $csv[] = $inv;
    }
    if (!$files) throw new AppException('Nenhuma nota emitida na seleção.');
    $files['resumo.csv'] = fh_csv($csv);
    return zip_build($files);
}

function fh_csv(array $rows): string
{
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Número', 'Série/RPS', 'Situação', 'Emissão', 'Competência', 'Tomador', 'CPF/CNPJ', 'Serviço (LC 116)', 'Valor', 'Desc. incond.', 'Deduções', 'Base ISS', 'Alíq. ISS', 'ISS', 'ISS retido', 'PIS', 'COFINS', 'CSLL', 'IRRF', 'INSS', 'Retenções', 'Líquido', 'Chave de acesso', 'Código verificação', 'Emissor', 'Canal'], ';', '"', '\\');
    $n = fn($v) => number_format((float)$v, 2, ',', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['nfse_number'], $r['dps_serie'] . '-' . $r['dps_number'], FH_STATUS[$r['status']] ?? $r['status'], $r['issued_at'] ? date('d/m/Y H:i', strtotime($r['issued_at'])) : '', date('m/Y', strtotime($r['competence_date'])),
            $r['toma_name'], fh_doc_fmt((string)$r['toma_document']), $r['lc116'], $n($r['amount']), $n($r['discount_incond'] ?? 0), $n($r['deductions'] ?? 0), $n($r['base']), $n($r['iss_rate']), $n($r['iss_amount']),
            ($r['iss_retention'] ?? '1') !== '1' ? 'Sim' : 'Não', $n($r['pis_amount']), $n($r['cofins_amount']), $n($r['csll_amount']), $n($r['irrf_amount']), $n($r['inss_amount']), $n($r['withheld_total']), $n($r['net_amount']),
            $r['access_key'] ? "'" . $r['access_key'] : '', $r['verification_code'] ?? '', $r['emitter_name'] ?? '', ($r['provider'] ?? '') === 'sigiss' ? 'SIGISS Marília' : 'Emissor Nacional'], ';', '"', '\\');
    }
    rewind($out);
    return (string)stream_get_contents($out);
}

/* ================================================================ REPORTS */

function fh_report(int $customerId, ?int $emitterId, string $start, string $end): array
{
    $w = "customer_id = ? AND issued_at >= ? AND issued_at <= ?" . ($emitterId ? ' AND emitter_id = ?' : '');
    $p = array_merge([$customerId, $start . ' 00:00:00', $end . ' 23:59:59'], $emitterId ? [$emitterId] : []);
    $k = db_one("SELECT SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END) AS emitted, SUM(CASE WHEN status='canceled' THEN 1 ELSE 0 END) AS canceled,
        COALESCE(SUM(CASE WHEN status='authorized' THEN amount END),0) AS gross, COALESCE(SUM(CASE WHEN status='canceled' THEN amount END),0) AS canceled_amount,
        COALESCE(SUM(CASE WHEN status='authorized' AND iss_retention='1' THEN iss_amount END),0) AS iss_due, COALESCE(SUM(CASE WHEN status='authorized' AND iss_retention!='1' THEN iss_amount END),0) AS iss_withheld,
        COALESCE(SUM(CASE WHEN status='authorized' THEN withheld_total END),0) AS withheld, COALESCE(SUM(CASE WHEN status='authorized' THEN net_amount END),0) AS net,
        COALESCE(SUM(CASE WHEN status='authorized' THEN total_taxes_amount END),0) AS approx_taxes, COUNT(DISTINCT CASE WHEN status='authorized' THEN COALESCE(toma_document, toma_name) END) AS takers
        FROM fh_invoices WHERE $w", $p);
    $k = array_map(fn($v) => (float)$v, $k ?: []);
    $k['avg_ticket'] = $k['emitted'] ? round($k['gross'] / $k['emitted'], 2) : 0;
    $k['federal_withheld'] = round($k['withheld'] - $k['iss_withheld'], 2);
    $months = [];
    $m = substr($start, 0, 7);
    for ($i = 0; $m <= substr($end, 0, 7) && $i < 36; $i++) { $months[$m] = ['month' => $m, 'count' => 0, 'amount' => 0.0, 'iss' => 0.0]; $m = date('Y-m', strtotime($m . '-01 +1 month')); }
    foreach (db_all("SELECT SUBSTR(issued_at, 1, 7) AS m, COUNT(*) AS n, SUM(amount) AS amount, SUM(iss_amount) AS iss FROM fh_invoices WHERE $w AND status = 'authorized' GROUP BY SUBSTR(issued_at, 1, 7)", $p) as $r) {
        if (isset($months[$r['m']])) $months[$r['m']] = ['month' => $r['m'], 'count' => (int)$r['n'], 'amount' => round((float)$r['amount'], 2), 'iss' => round((float)$r['iss'], 2)];
    }
    $byTaker = db_all("SELECT toma_name AS name, toma_document AS document, COUNT(*) AS n, SUM(amount) AS amount FROM fh_invoices WHERE $w AND status = 'authorized' GROUP BY toma_name, toma_document ORDER BY SUM(amount) DESC LIMIT 15", $p);
    $byService = db_all("SELECT COALESCE(service_name, lc116, 'Sem serviço') AS name, lc116, COUNT(*) AS n, SUM(amount) AS amount, SUM(iss_amount) AS iss FROM fh_invoices WHERE $w AND status = 'authorized' GROUP BY COALESCE(service_name, lc116, 'Sem serviço'), lc116 ORDER BY SUM(amount) DESC LIMIT 15", $p);
    $taxes = db_one("SELECT COALESCE(SUM(iss_amount),0) AS iss, COALESCE(SUM(pis_amount),0) AS pis, COALESCE(SUM(cofins_amount),0) AS cofins, COALESCE(SUM(csll_amount),0) AS csll, COALESCE(SUM(irrf_amount),0) AS irrf, COALESCE(SUM(inss_amount),0) AS inss,
        COALESCE(SUM(CASE WHEN iss_retention!='1' THEN iss_amount END),0) AS iss_w, COALESCE(SUM(CASE WHEN pis_withheld=1 THEN pis_amount END),0) AS pis_w, COALESCE(SUM(CASE WHEN cofins_withheld=1 THEN cofins_amount END),0) AS cofins_w,
        COALESCE(SUM(CASE WHEN csll_withheld=1 THEN csll_amount END),0) AS csll_w, COALESCE(SUM(CASE WHEN irrf_withheld=1 THEN irrf_amount END),0) AS irrf_w, COALESCE(SUM(CASE WHEN inss_withheld=1 THEN inss_amount END),0) AS inss_w
        FROM fh_invoices WHERE $w AND status = 'authorized'", $p);
    $alerts = [];
    foreach (db_all('SELECT id, legal_name, op_simp_nac FROM fh_emitters WHERE customer_id = ?' . ($emitterId ? ' AND id = ?' : ''), $emitterId ? [$customerId, $emitterId] : [$customerId]) as $em) {
        $year = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM fh_invoices WHERE emitter_id = ? AND status = 'authorized' AND issued_at >= ?", [$em['id'], date('Y') . '-01-01 00:00:00']);
        $limit = $em['op_simp_nac'] === '2' ? 81000 : ($em['op_simp_nac'] === '3' ? 4800000 : 0);
        if ($limit && $year >= $limit * 0.8) $alerts[] = "{$em['legal_name']}: faturou " . money($year) . ' em ' . date('Y') . ' com notas (' . round($year / $limit * 100) . '% do limite ' . ($limit === 81000 ? 'do MEI, R$ 81 mil' : 'do Simples Nacional, R$ 4,8 milhões') . '). Converse com seu contador.';
    }
    if ($byTaker && $k['gross'] > 0 && (float)$byTaker[0]['amount'] / $k['gross'] > 0.5 && count($byTaker) > 1) $alerts[] = 'Mais da metade do faturamento vem de um único cliente (' . $byTaker[0]['name'] . ').';
    $rejected = (int)db_value("SELECT COUNT(*) FROM fh_invoices WHERE customer_id = ? AND status = 'rejected'" . ($emitterId ? ' AND emitter_id = ?' : ''), $emitterId ? [$customerId, $emitterId] : [$customerId]);
    if ($rejected) $alerts[] = "$rejected nota(s) rejeitada(s) aguardando correção.";
    return ['period' => [$start, $end], 'kpis' => $k, 'months' => array_values($months), 'by_taker' => $byTaker, 'by_service' => $byService, 'taxes' => array_map('floatval', $taxes ?: []), 'alerts' => $alerts];
}

function fh_ai_report(int $customerId, array $report): array
{
    $access = fh_access($customerId);
    if ($access['usage']['ai_used'] >= $access['usage']['ai_limit']) throw new AppException('Você usou as ' . $access['usage']['ai_limit'] . ' análises com IA do seu plano neste mês.');
    $k = $report['kpis'];
    $facts = "Período: " . date('d/m/Y', strtotime($report['period'][0])) . ' a ' . date('d/m/Y', strtotime($report['period'][1])) . "\n"
        . "Notas emitidas: {$k['emitted']} (canceladas: {$k['canceled']}); faturamento bruto " . money($k['gross']) . '; ticket médio ' . money($k['avg_ticket']) . "; clientes atendidos: {$k['takers']}\n"
        . 'ISS a recolher (não retido): ' . money($k['iss_due']) . '; ISS retido por clientes: ' . money($k['iss_withheld']) . '; retenções federais: ' . money($k['federal_withheld']) . '; valor líquido: ' . money($k['net']) . "\n"
        . 'Mês a mês: ' . implode('; ', array_map(fn($m) => $m['month'] . ' = ' . money($m['amount']) . " ({$m['count']} notas)", $report['months'])) . "\n"
        . 'Maiores clientes: ' . implode('; ', array_map(fn($t) => $t['name'] . ' ' . money($t['amount']) . " ({$t['n']})", array_slice($report['by_taker'], 0, 8))) . "\n"
        . 'Serviços: ' . implode('; ', array_map(fn($s) => $s['name'] . ' ' . money($s['amount']), array_slice($report['by_service'], 0, 8))) . "\n"
        . 'Alertas do sistema: ' . ($report['alerts'] ? implode(' ', $report['alerts']) : 'nenhum');
    if (!ai_configured()) {
        $lines = ['**Resumo:** ' . $k['emitted'] . ' notas e ' . money($k['gross']) . ' faturados no período, ticket médio de ' . money($k['avg_ticket']) . '.'];
        if ($report['by_taker']) $lines[] = '**Maior cliente:** ' . $report['by_taker'][0]['name'] . ' (' . money($report['by_taker'][0]['amount']) . ').';
        $lines[] = '**Impostos:** ISS a recolher ' . money($k['iss_due']) . '; retido pelos clientes ' . money($k['iss_withheld']) . '; retenções federais ' . money($k['federal_withheld']) . '.';
        foreach ($report['alerts'] as $a) $lines[] = '⚠️ ' . $a;
        return ['text' => implode("\n\n", $lines), 'ai' => false];
    }
    $text = ai_chat([
        ['role' => 'system', 'content' => 'Você é um analista fiscal e financeiro que ajuda pequenas empresas prestadoras de serviço no Brasil. Escreva em português do Brasil, em markdown simples (títulos com **negrito**, listas com "- "). Use SOMENTE os números fornecidos; não invente dados nem alíquotas. Seções: **Resumo do período**, **Tendência**, **Clientes e serviços**, **Impostos e retenções**, **Alertas**, **Recomendações práticas** (3 a 5 itens objetivos, incluindo organização para a contabilidade). Máximo de 350 palavras. Lembre que não substitui a orientação do contador.'],
        ['role' => 'user', 'content' => $facts],
    ], 'fh_report_' . $customerId, 900, 0.3);
    return ['text' => $text, 'ai' => true];
}

/* ================================================================== BATCH */

/**
 * Import a CSV (; or ,) with header: documento, nome, email, valor, descricao, servico, competencia, cep, numero.
 * Creates drafts (and new clients). Returns ['created' => [ids], 'errors' => [line => msg]].
 */
function fh_batch_import(array $em, string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) throw new AppException('A planilha precisa ter o cabeçalho e ao menos uma linha.');
    if (count($lines) > 301) throw new AppException('Importe no máximo 300 notas por vez.');
    $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
    $head = array_map(fn($h) => strtolower(strip_accents(trim($h))), str_getcsv($lines[0], $sep));
    $col = fn(array $row, string $name) => ($i = array_search($name, $head, true)) !== false ? trim((string)($row[$i] ?? '')) : '';
    foreach (['documento', 'nome', 'valor'] as $req) if (!in_array($req, $head, true)) throw new AppException("Coluna obrigatória ausente: $req. Use o modelo de planilha.");
    $services = db_all('SELECT * FROM fh_services WHERE emitter_id = ? AND active = 1', [$em['id']]);
    $created = [];
    $errors = [];
    foreach (array_slice($lines, 1) as $n => $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line, $sep);
        try {
            $doc = only_digits($col($row, 'documento'));
            $taker = ['kind' => strlen($doc) === 11 ? 'pf' : 'pj', 'document' => $doc, 'name' => $col($row, 'nome'), 'email' => $col($row, 'email'), 'cep' => $col($row, 'cep'), 'number' => $col($row, 'numero')];
            $existing = db_one('SELECT * FROM fh_takers WHERE emitter_id = ? AND document = ?', [$em['id'], $doc]);
            if (!$existing && $taker['cep'] && ($c = cep_lookup($taker['cep']))) $taker += ['street' => $c['logradouro'] ?? '', 'district' => $c['bairro'] ?? '', 'city' => $c['localidade'] ?? '', 'uf' => $c['uf'] ?? '', 'city_ibge' => $c['ibge'] ?? ''];
            $takerRow = $existing ?: fh_taker_save($em, $taker, null);
            $svcKey = mb_strtolower($col($row, 'servico'));
            $svc = null;
            $lcKey = fh_lc_parts($svcKey);
            foreach ($services as $s) {
                if ($svcKey === '') break;
                $sp = fh_lc_parts((string)$s['lc116']);
                if ((string)$s['id'] === $svcKey || mb_strtolower($s['name']) === $svcKey || ($lcKey && $sp && $lcKey == $sp)) { $svc = $s; break; }
            }
            if (!$svc && $em['default_service_id']) $svc = db_find('fh_services', (int)$em['default_service_id']);
            $lcIn = !$svc && $lcKey ? sprintf('%02d.%s', $lcKey[0], $lcKey[1]) : null;
            $valor = str_replace(['R$', ' '], '', $col($row, 'valor'));
            $valor = strpos($valor, ',') !== false ? str_replace(['.', ','], ['', '.'], $valor) : $valor;
            $comp = $col($row, 'competencia');
            if (preg_match('#^(\d{2})/(\d{4})$#', $comp, $mm)) $comp = "{$mm[2]}-{$mm[1]}-01";
            elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $comp, $mm)) $comp = "{$mm[3]}-{$mm[2]}-{$mm[1]}";
            $inv = fh_invoice_save($em, ['taker_id' => $takerRow['id'], 'service_id' => $svc['id'] ?? null, 'lc116' => $lcIn, 'amount' => $valor, 'description' => $col($row, 'descricao'), 'competence_date' => $comp], null, 'batch');
            $created[] = (int)$inv['id'];
        } catch (Throwable $e) {
            $errors[$n + 2] = $e->getMessage();
        }
    }
    return ['created' => $created, 'errors' => $errors];
}

/* ============================================================== RECURRING */

const FH_RECURRING_INTERVALS = [1 => 'Mensal', 2 => 'Bimestral', 3 => 'Trimestral', 6 => 'Semestral', 12 => 'Anual'];
const FH_MONTHS = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** Next emission date: $months after the month of $from, on $day (clamped to the month length). */
function fh_recurring_next(string $from, int $day, int $months = 1): string
{
    $base = strtotime(date('Y-m-01', strtotime($from)) . ' +' . max(1, $months) . ' month');
    return date('Y-m-', $base) . sprintf('%02d', min($day, (int)date('t', $base)));
}

/** Due date of an occurrence emitted on $date: day $dueDay of the same month, or of the next one if it already passed. */
function fh_recurring_due(string $date, $dueDay): ?string
{
    $dueDay = (int)$dueDay;
    if ($dueDay < 1) return null;
    $ts = strtotime(date('Y-m-01', strtotime($date)));
    $due = date('Y-m-', $ts) . sprintf('%02d', min($dueDay, (int)date('t', $ts)));
    if ($due < $date) { $ts = strtotime(date('Y-m-01', $ts) . ' +1 month'); $due = date('Y-m-', $ts) . sprintf('%02d', min($dueDay, (int)date('t', $ts))); }
    return $due;
}

/** Description with the {mes} {ano} {mes_ano} {mm/aaaa} {data_vencimento} placeholders filled for the emission date. */
function fh_recurring_text(string $text, string $date, ?string $due = null): string
{
    $ts = strtotime($date);
    return strtr($text, ['{mes}' => FH_MONTHS[(int)date('n', $ts)], '{ano}' => date('Y', $ts), '{mes_ano}' => FH_MONTHS[(int)date('n', $ts)] . '/' . date('Y', $ts), '{mm/aaaa}' => date('m/Y', $ts),
        '{data_vencimento}' => $due ? date('d/m/Y', strtotime($due)) : '', '{vencimento}' => $due ? date('d/m/Y', strtotime($due)) : '']);
}

/** Emit the occurrence of a recurrence due on $date (draft → transmit). Returns the invoice. */
function fh_recurring_emit(array $r, string $date): array
{
    $em = db_find('fh_emitters', (int)$r['emitter_id']);
    $due = fh_recurring_due(max($date, today()), $r['due_day'] ?? null);
    $inv = fh_invoice_save($em, ['taker_id' => $r['taker_id'], 'service_id' => $r['service_id'], 'amount' => $r['amount'], 'description' => fh_recurring_text((string)$r['description'], $date, $due),
        'competence_date' => min($date, today()), 'recurring_id' => $r['id'], 'extra' => $due ? ['vencimento' => $due] : []], null, 'recurring');
    return fh_transmit((int)$inv['id']);
}

function fh_recurring_run(): array
{
    $out = ['emitted' => 0, 'errors' => []];
    foreach (db_all("SELECT r.*, e.customer_id FROM fh_recurring r JOIN fh_emitters e ON e.id = r.emitter_id WHERE r.active = 1 AND r.next_run <= ? AND e.active = 1 LIMIT 200", [today()]) as $r) {
        $next = fh_recurring_next($r['next_run'], (int)$r['day_of_month'], (int)($r['interval_months'] ?? 1));
        try {
            $plan = fh_access((int)$r['customer_id'])['plan'];
            if (empty($plan['flags']['recurring'])) throw new AppException('Plano sem notas recorrentes.');
            if ($r['end_date'] && $r['next_run'] > $r['end_date']) { db_update('fh_recurring', (int)$r['id'], ['active' => 0, 'updated_at' => now()]); continue; }
            fh_recurring_emit($r, $r['next_run']);
            $out['emitted']++;
        } catch (Throwable $e) {
            $out['errors'][] = "#{$r['id']}: " . $e->getMessage();
            log_line('fiscalhub', 'recurring failed', ['id' => $r['id'], 'error' => $e->getMessage()]);
            $c = db_find('customers', (int)$r['customer_id']);
            if ($c && !empty($c['email'])) mail_queue($c['email'], 'Nota recorrente não emitida — ' . FH_NAME, mail_template('Não conseguimos emitir uma nota recorrente', '<p>A nota recorrente programada para ' . date('d/m/Y', strtotime($r['next_run'])) . ' não foi emitida:</p><p><b>' . e($e->getMessage()) . '</b></p><p>Corrija e emita manualmente pelo Fiscal Hub (botão "Emitir agora" em Notas recorrentes).</p>', ['label' => 'Abrir o Fiscal Hub', 'url' => app_link('/cliente/fiscal/#/recorrentes')]), ['event' => 'fh_recurring_error']);
        }
        db_update('fh_recurring', (int)$r['id'], ['last_run' => today(), 'next_run' => $next, 'updated_at' => now()]);
    }
    return $out;
}
