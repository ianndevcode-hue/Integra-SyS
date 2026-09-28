<?php
declare(strict_types=1);

/**
 * NFS-e emission through the Brazilian National NFS-e System (Sefin Nacional API).
 *
 * Flow: build DPS XML (layout v1.01) -> validate against official XSD -> sign (XMLDSig
 * enveloped, RSA-SHA1, C14N) -> gzip + base64 -> POST /nfse over mTLS with the company's
 * ICP-Brasil A1 certificate. Cancellation uses event e101101 on POST /nfse/{chave}/eventos.
 *
 * Docs: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica
 */

const NFSE_NS = 'http://www.sped.fazenda.gov.br/nfse';
const NFSE_LAYOUT = '1.01';
const NFSE_APP_VERSION = 'IntegraCode-1.0';
const NFSE_ENDPOINTS = [
    'production' => ['sefin' => 'https://sefin.nfse.gov.br/SefinNacional', 'adn' => 'https://adn.nfse.gov.br'],
    'homologation' => ['sefin' => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional', 'adn' => 'https://adn.producaorestrita.nfse.gov.br'],
];

require_once __DIR__ . '/nfse_sigiss.php';

class NfseException extends RuntimeException
{
    public array $details;

    public function __construct(string $message, array $details = [], int $code = 0)
    {
        parent::__construct($message, $code);
        $this->details = $details;
    }
}

/* ------------------------------------------------------------ configuration */

function nfse_config(): array
{
    return [
        'provider' => setting('nfse_provider', 'sigiss') === 'nacional' ? 'nacional' : 'sigiss',
        'environment' => setting('nfse_provider', 'sigiss') === 'nacional' ? setting('nfse_environment', 'homologation') : 'production',
        'cnpj' => only_digits((string)setting('nfse_cnpj', only_digits(COMPANY['cnpj']))),
        'im' => only_digits((string)setting('nfse_im', '')),
        'city_code' => (string)setting('nfse_city_code', '3529005'), // Marília-SP (IBGE)
        'op_simp_nac' => (string)setting('nfse_op_simp_nac', '3'),     // 1 não optante, 2 MEI, 3 ME/EPP
        'reg_ap_trib_sn' => (string)setting('nfse_reg_ap_trib_sn', ''), // optional 1..3
        'reg_esp_trib' => (string)setting('nfse_reg_esp_trib', '0'),
        'serie' => (string)setting('nfse_serie', '1'),
        'next_number' => (int)setting('nfse_next_number', 1),
        'ctribnac' => (string)setting('nfse_ctribnac', '010101'),       // LC 116 item 1.01
        'cnbs' => (string)setting('nfse_cnbs', '115022000'),
        'aliquota' => (float)setting('nfse_aliquota', 0),
        'simples_percent' => (float)setting('nfse_simples_percent', 0),
        'default_description' => (string)setting('nfse_default_description', 'Serviços de desenvolvimento de sistemas'),
        'auto_on_payment' => setting('nfse_auto_on_payment', '0') === '1',
        // federal taxes (defaults per invoice; Simples Nacional collects them in the DAS)
        'pis_rate' => (float)setting('nfse_pis_rate', (string)setting('nfse_op_simp_nac', '3') === '1' ? '0.65' : '0'),
        'cofins_rate' => (float)setting('nfse_cofins_rate', (string)setting('nfse_op_simp_nac', '3') === '1' ? '3' : '0'),
        'csll_rate' => (float)setting('nfse_csll_rate', (string)setting('nfse_op_simp_nac', '3') === '1' ? '1' : '0'),
        'irrf_rate' => (float)setting('nfse_irrf_rate', (string)setting('nfse_op_simp_nac', '3') === '1' ? '1.5' : '0'),
        'inss_rate' => (float)setting('nfse_inss_rate', '0'),
        'pis_cofins_cst' => (string)setting('nfse_pis_cofins_cst', (string)setting('nfse_op_simp_nac', '3') === '1' ? '01' : '49'),
        'withhold_federal_pj' => setting('nfse_withhold_federal_pj', '0') === '1',
        'total_tax_pct' => (float)setting('nfse_total_tax_pct', '0'),
        'show_taxes_in_description' => setting('nfse_show_taxes', '1') === '1',
    ];
}

/**
 * Full tax calculation for an invoice (ISS + PIS/COFINS/CSLL/IRRF/INSS, discount, deductions,
 * withholdings, net amount and approximate total taxes — Lei 12.741/2012).
 * $in accepts: amount, discount_amount, deductions, iss_rate, iss_withheld, {pis,cofins,csll,irrf,inss}_rate and _withheld, pis_cofins_cst, toma_document.
 */
function nfse_taxes(array $in, array $cfg): array
{
    $n = fn($k, $def = 0) => isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? max(0, (float)str_replace(',', '.', (string)$in[$k])) : (float)$def;
    $b = fn($k, $def = false) => array_key_exists($k, $in) ? filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) : $def;
    $amount = round($n('amount'), 2);
    $discount = min($amount, round($n('discount_amount'), 2));
    $deductions = min($amount - $discount, round($n('deductions'), 2));
    $baseFed = round($amount - $discount, 2);
    $baseIss = round($amount - $discount - $deductions, 2);
    $pj = strlen(only_digits((string)($in['toma_document'] ?? ''))) === 14;
    $defWithhold = $cfg['withhold_federal_pj'] && $pj && $cfg['op_simp_nac'] === '1';
    $out = ['amount' => $amount, 'discount_amount' => $discount, 'deductions' => $deductions, 'base' => $baseIss, 'pis_cofins_cst' => preg_match('/^\d{2}$/', (string)($in['pis_cofins_cst'] ?? '')) ? $in['pis_cofins_cst'] : $cfg['pis_cofins_cst']];
    $out['iss_rate'] = round($n('iss_rate', $cfg['aliquota']), 2);
    $out['iss_amount'] = round($baseIss * $out['iss_rate'] / 100, 2);
    $out['iss_withheld'] = $b('iss_withheld') ? 1 : 0;
    $withheld = $out['iss_withheld'] ? $out['iss_amount'] : 0;
    foreach (['pis', 'cofins', 'csll', 'irrf', 'inss'] as $t) {
        $rate = round($n($t . '_rate', $cfg[$t . '_rate']), 2);
        $amt = round($baseFed * $rate / 100, 2);
        $w = $amt > 0 && $b($t . '_withheld', $defWithhold && $t !== 'inss');
        if ($t === 'irrf' && $w && $amt < 10) $w = false; // IRRF below R$ 10,00 is not withheld (DARF minimum)
        $out[$t . '_rate'] = $rate;
        $out[$t . '_amount'] = $amt;
        $out[$t . '_withheld'] = $w ? 1 : 0;
        if ($w) $withheld += $amt;
    }
    $pct = $cfg['total_tax_pct'] > 0 ? $cfg['total_tax_pct'] : (in_array($cfg['op_simp_nac'], ['2', '3'], true) && $cfg['simples_percent'] > 0 ? $cfg['simples_percent'] : $out['iss_rate'] + $out['pis_rate'] + $out['cofins_rate'] + $out['csll_rate'] + $out['irrf_rate']);
    $out['total_taxes_pct'] = round($pct, 2);
    $out['total_taxes_amount'] = round($baseFed * $pct / 100, 2);
    $out['withheld_total'] = round($withheld, 2);
    $out['net_amount'] = round($amount - $discount - $withheld, 2);
    return $out;
}

/** "Tributos aproximados" line appended to the service description (Lei 12.741/2012). */
function nfse_taxes_note(array $t): string
{
    if ($t['total_taxes_amount'] <= 0) return '';
    $ret = [];
    foreach (['iss' => 'ISS', 'pis' => 'PIS', 'cofins' => 'COFINS', 'csll' => 'CSLL', 'irrf' => 'IRRF', 'inss' => 'INSS'] as $k => $l) if (!empty($t[$k . '_withheld'])) $ret[] = $l . ' ' . money($t[$k . '_amount']);
    return "\nValor aproximado dos tributos: " . money($t['total_taxes_amount']) . ' (' . number_format($t['total_taxes_pct'], 2, ',', '.') . '%) — Lei 12.741/2012.'
        . ($ret ? "\nRetenções: " . implode(', ', $ret) . '. Valor líquido: ' . money($t['net_amount']) . '.' : '');
}

function nfse_base(string $which = 'sefin'): string
{
    return nfse_endpoint(nfse_config()['environment'], $which);
}

/** Sefin/ADN base URL; inc/config.php may override it with 'nfse_endpoints' (local simulator for tests). */
function nfse_endpoint(string $environment, string $which = 'sefin'): string
{
    $env = $environment === 'production' ? 'production' : 'homologation';
    return (string)(config('nfse_endpoints')[$env][$which] ?? NFSE_ENDPOINTS[$env][$which]);
}

/* -------------------------------------------------------------- certificate */

/**
 * Load the A1 certificate (.pfx) stored encrypted in settings.
 * @return array{cert:string,key:string,info:array}
 */
function nfse_certificate(): array
{
    $pfx = base64_decode((string)setting('nfse_cert_pfx', ''), true);
    if (!$pfx) throw new NfseException('Certificado digital A1 não configurado. Envie o arquivo .pfx em Configurações → NFS-e.');
    return nfse_read_pfx($pfx, (string)setting('nfse_cert_password', ''));
}

function nfse_read_pfx(string $pfx, string $password): array
{
    $certs = [];
    if (!@openssl_pkcs12_read($pfx, $certs, $password)) {
        while (openssl_error_string()) { /* drain the OpenSSL error queue */ }
        // Most ICP-Brasil A1 files use RC2-40/RC4, which OpenSSL 3 without the "legacy" provider
        // (Hostinger) refuses. Fall back to the pure-PHP reader instead of rejecting the file.
        require_once INC_PATH . '/pkcs12.php';
        try {
            $certs = Pkcs12::read($pfx, $password);
        } catch (Pkcs12Exception $e) {
            if ($e->reason === 'password') throw new NfseException('Senha do certificado incorreta. Confira a senha usada ao instalar/exportar o .pfx.');
            if ($e->reason === 'unsupported') throw new NfseException('Formato de criptografia do certificado não suportado (' . $e->getMessage() . '). Reexporte o .pfx pelo Windows ou pelo emissor do certificado e tente de novo.');
            throw new NfseException('Arquivo de certificado inválido. Envie o arquivo .pfx ou .p12 do certificado A1 (não o .cer ou .crt).');
        }
    }
    if (empty($certs['cert']) || empty($certs['pkey'])) throw new NfseException('O arquivo não contém o certificado com a chave privada. Exporte o A1 "com a chave privada" (.pfx).');
    $parsed = openssl_x509_parse($certs['cert']) ?: [];
    $cn = (string)($parsed['subject']['CN'] ?? '');
    preg_match('/(\d{14})/', $cn, $m);
    return [
        'cert' => $certs['cert'],
        'key' => $certs['pkey'],
        'chain' => array_values(array_filter((array)($certs['extracerts'] ?? []), 'is_string')),
        'info' => [
            'subject' => $cn,
            'cnpj' => $m[1] ?? null,
            'issuer' => (string)($parsed['issuer']['CN'] ?? ''),
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('Y-m-d', $parsed['validFrom_time_t']) : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? date('Y-m-d', $parsed['validTo_time_t']) : null,
            'expired' => isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] < time(),
        ],
    ];
}

/* ------------------------------------------------------------------ signing */

function nfse_sign(string $xml, string $tag, array $certificate): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    if (!$dom->loadXML($xml)) throw new NfseException('XML inválido para assinatura.');
    $node = $dom->getElementsByTagName($tag)->item(0);
    if (!$node instanceof DOMElement || !$node->getAttribute('Id')) throw new NfseException("Elemento <$tag> sem Id.");

    $digest = base64_encode(hash('sha1', $node->C14N(true, false), true));

    $signature = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');
    $signedInfo = $dom->createElement('SignedInfo');
    $add = function (DOMElement $parent, string $name, array $attrs = [], ?string $text = null) use ($dom) {
        $el = $dom->createElement($name);
        foreach ($attrs as $k => $v) $el->setAttribute($k, $v);
        if ($text !== null) $el->appendChild($dom->createTextNode($text));
        $parent->appendChild($el);
        return $el;
    };
    $add($signedInfo, 'CanonicalizationMethod', ['Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315']);
    $add($signedInfo, 'SignatureMethod', ['Algorithm' => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1']);
    $ref = $add($signedInfo, 'Reference', ['URI' => '#' . $node->getAttribute('Id')]);
    $transforms = $add($ref, 'Transforms');
    $add($transforms, 'Transform', ['Algorithm' => 'http://www.w3.org/2000/09/xmldsig#enveloped-signature']);
    $add($transforms, 'Transform', ['Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315']);
    $add($ref, 'DigestMethod', ['Algorithm' => 'http://www.w3.org/2000/09/xmldsig#sha1']);
    $add($ref, 'DigestValue', [], $digest);
    $signature->appendChild($signedInfo);
    $node->parentNode->appendChild($signature);

    $raw = '';
    $data = $signedInfo->C14N(true, false);
    if (!@openssl_sign($data, $raw, $certificate['key'], OPENSSL_ALGO_SHA1)) {
        // Some OpenSSL 3 builds (crypto policies) refuse SHA-1 signatures. RSA PKCS#1 v1.5 over the
        // SHA-1 DigestInfo is exactly the same signature, produced without the digest policy check.
        $err = openssl_error_string() ?: '';
        while (openssl_error_string()) { /* drain */ }
        $raw = '';
        if (!openssl_private_encrypt(hex2bin('3021300906052b0e03021a05000414') . sha1($data, true), $raw, $certificate['key'], OPENSSL_PKCS1_PADDING)) {
            throw new NfseException('Falha ao assinar o XML com o certificado digital: ' . ($err ?: (openssl_error_string() ?: 'erro desconhecido')));
        }
    }
    $add($signature, 'SignatureValue', [], base64_encode($raw));
    $keyInfo = $add($signature, 'KeyInfo');
    $x509 = $add($keyInfo, 'X509Data');
    $pem = preg_replace('/-----[^-]+-----|\s+/', '', $certificate['cert']);
    $add($x509, 'X509Certificate', [], $pem);

    return $dom->saveXML($dom->documentElement);
}

/**
 * Validate an XML against the official XSD shipped in inc/nfse-schemas.
 */
function nfse_validate_xsd(string $xml, string $schema): void
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok = $dom->schemaValidate(INC_PATH . '/nfse-schemas/' . $schema);
    $errors = array_map(fn($e) => trim($e->message) . ' (linha ' . $e->line . ')', libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors(false);
    if (!$ok) throw new NfseException('O XML não passou na validação do leiaute oficial.', array_slice($errors, 0, 8));
}

/* ------------------------------------------------ Sefin business rules (DPS) */

/** Rejections that mean "pAliq is required here" / "pAliq must not be sent here" (Anexo I, regras de alíquota). */
const NFSE_ALIQ_REQUIRED = ['E0619', 'E0621', 'E0628', 'E0640'];
const NFSE_ALIQ_FORBIDDEN = ['E0600', 'E0602', 'E0604', 'E0612', 'E0617', 'E0625', 'E0631', 'E0635'];
/** cTribNac that require the "obra" group (E0370); any other code rejects it (E0372). */
const NFSE_OBRA_CODES = ['070201', '070202', '070401', '070501', '070502', '070601', '070602', '070701', '070801', '071701', '071901', '141403', '141404'];

/**
 * Effective regApTribSN / regEspTrib for the DPS.
 * E0166: regApTribSN is mandatory for ME/EPP (default 1 = everything through the Simples) and E0162 forbids it otherwise.
 * E0172/E0174/E0175: regEspTrib must be 0 for non-taxable operations, for MEI and for ME/EPP paying ISS through the Simples.
 * @return array{0:string,1:string} [regApTribSN ('' = omit), regEspTrib]
 */
function nfse_regime(string $opSimpNac, $regAp, $regEsp, string $tribIssqn): array
{
    $regAp = $opSimpNac === '3' ? (in_array((string)$regAp, ['1', '2', '3'], true) ? (string)$regAp : '1') : '';
    $regEsp = in_array((string)$regEsp, ['0', '1', '2', '3', '4', '5', '6'], true) ? (string)$regEsp : '0';
    if ($opSimpNac === '2' || $regAp === '1' || $tribIssqn !== '1') $regEsp = '0';
    return [$regAp, $regEsp];
}

/**
 * Whether pAliq goes in the DPS, assuming the municipality of incidence is "Ativo" in the Sistema
 * Nacional (it parametrizes its own rates). E0617 (não optante), E0600 (MEI), E0602/E0604 (not taxable /
 * special regime), E0625/E0621 (ME/EPP by the Simples: only when ISS is withheld), E0635 (ME/EPP outside
 * the Simples). When the Sefin answers otherwise, nfse_aliq_from_errors() tells the caller to flip it once.
 */
function nfse_send_aliq(string $opSimpNac, string $regAp, string $regEsp, string $tribIssqn, bool $withheld): bool
{
    if ($tribIssqn !== '1' || $regEsp !== '0' || $opSimpNac === '2') return false;
    return $opSimpNac === '3' && $regAp === '1' && $withheld;
}

/** true = the Sefin demands pAliq, false = it forbids pAliq, null = the rejection is about something else. */
function nfse_aliq_from_errors(array $errors): ?bool
{
    $text = implode(' ', $errors);
    foreach (NFSE_ALIQ_REQUIRED as $c) if (strpos($text, $c) !== false) return true;
    foreach (NFSE_ALIQ_FORBIDDEN as $c) if (strpos($text, $c) !== false) return false;
    return null;
}

/**
 * totTrib group by regime: MEI → indTotTrib=0 (E0710), ME/EPP → pTotTribSN (E0712 forbids indTotTrib),
 * não optante → pTotTrib federal/estadual/municipal (E0713 forbids indTotTrib and pTotTribSN).
 * @return array{0:string,1:mixed} ['ind', '0'] | ['sn', pct] | ['p', [fed, est, mun]]
 */
function nfse_tot_trib(string $opSimpNac, float $snPct, float $totalPct, float $fedPct, float $munPct): array
{
    if ($opSimpNac === '2') return ['ind', '0'];
    if ($opSimpNac === '3') return ['sn', min(99.99, max(0, $snPct > 0 ? $snPct : $totalPct))];
    return ['p', [min(99.99, max(0, $fedPct)), 0.0, min(99.99, max(0, $munPct))]];
}

function nfse_append_tot_trib(DOMDocument $dom, DOMElement $trib, array $spec): void
{
    $num = fn($v) => number_format((float)$v, 2, '.', '');
    $tot = $dom->createElement('totTrib');
    $trib->appendChild($tot);
    if ($spec[0] === 'ind') { $tot->appendChild($dom->createElement('indTotTrib', '0')); return; }
    if ($spec[0] === 'sn') { $tot->appendChild($dom->createElement('pTotTribSN', $num($spec[1]))); return; }
    $p = $dom->createElement('pTotTrib');
    $tot->appendChild($p);
    $p->appendChild($dom->createElement('pTotTribFed', $num($spec[1][0])));
    $p->appendChild($dom->createElement('pTotTribEst', $num($spec[1][1])));
    $p->appendChild($dom->createElement('pTotTribMun', $num($spec[1][2])));
}

/** Friendly "how to fix" lines for rejections whose official text does not say what to change. */
function nfse_error_hints(array $errors): array
{
    $text = implode(' ', $errors);
    $hints = [
        'E0116' => 'Informe a inscrição municipal (IM) da empresa no cadastro do emissor.',
        'E0120' => 'Apague a inscrição municipal (IM) do cadastro do emissor: o município não a usa no Emissor Nacional.',
        'E0160' => 'A situação no Simples Nacional do cadastro não confere com a Receita: ajuste "Situação no Simples Nacional" no emissor.',
        'E0010' => 'A série não é aceita para emissão por sistema próprio: use a série 1 (ou a que o seu contador indicar) no cadastro do emissor.',
        'E0310' => 'Confira o código de tributação nacional (cTribNac) com o seu contador: ele não existe para o município nessa data.',
        'E0312' => 'Confira o código de tributação nacional (cTribNac) com o seu contador: o município não administra esse serviço.',
        'E0316' => 'Escolha um código NBS da lista sugerida para o item de serviço (tabela oficial NBS 2.0).',
        'E0008' => 'O relógio do servidor está adiantado; tente de novo em alguns minutos.',
    ];
    $out = [];
    foreach ($hints as $code => $h) if (strpos($text, $code) !== false) $out[] = 'Como resolver: ' . $h;
    return $out;
}

/** Official NBS 2.0 table (gov.br/nfse Anexo B) and its correlation with the LC 116 items (Anexo VIII). */
function nfse_tables(): array
{
    static $t = null;
    if ($t === null) $t = json_decode((string)@file_get_contents(INC_PATH . '/nfse-schemas/fh_tabelas.json'), true) ?: ['nbs' => [], 'ctribnac' => [], 'lc_nbs' => []];
    return $t;
}

/** NBS suggestions for a LC 116 item ("01.07"), as [code => description]. */
function nfse_nbs_for_lc(string $lc): array
{
    $t = nfse_tables();
    $p = preg_match('/^(\d{1,2})\.?(\d{2})/', $lc, $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . '.' . $m[2] : '';
    $out = [];
    foreach ($t['lc_nbs'][$p] ?? [] as $code) $out[$code] = $t['nbs'][$code] ?? '';
    return $out;
}

/** Problem text when the NBS code is not in the official table (E0316), or null. */
function nfse_nbs_problem(string $nbs, string $lc = ''): ?string
{
    $t = nfse_tables();
    if ($nbs === '' || !$t['nbs'] || isset($t['nbs'][$nbs])) return null;
    $sug = nfse_nbs_for_lc($lc);
    return 'O código NBS ' . $nbs . ' não existe na tabela oficial NBS 2.0 (a Sefin recusa com E0316).'
        . ($sug ? ' Para o item ' . $lc . ' use um destes: ' . implode('; ', array_map(fn($c, $d) => $c . ' — ' . mb_substr($d, 0, 70), array_keys($sug), $sug)) . '.' : '');
}

/* -------------------------------------------------------------- DPS builder */

function nfse_text(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', '', $value);
    $value = trim(preg_replace('/[ \t]+/', ' ', $value));
    return mb_substr($value, 0, $max);
}

function nfse_dps_id(array $cfg, string $serie, int $number): string
{
    return 'DPS' . $cfg['city_code'] . '2' . str_pad($cfg['cnpj'], 14, '0', STR_PAD_LEFT)
        . str_pad($serie, 5, '0', STR_PAD_LEFT) . str_pad((string)$number, 15, '0', STR_PAD_LEFT);
}

/**
 * @param array $inv invoice row (nfse_invoices) + customer fields (toma_*)
 */
function nfse_build_dps(array $inv, array $cfg, ?bool $aliq = null): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = false;
    $el = function (string $name, ?string $value = null) use ($dom): DOMElement {
        $e = $dom->createElement($name);
        if ($value !== null && $value !== '') $e->appendChild($dom->createTextNode($value));
        return $e;
    };
    $num = fn($v) => number_format((float)$v, 2, '.', '');

    $dps = $dom->createElementNS(NFSE_NS, 'DPS');
    $dps->setAttribute('versao', NFSE_LAYOUT);
    $dom->appendChild($dps);
    $inf = $el('infDPS');
    $inf->setAttribute('Id', $inv['dps_id']);
    $dps->appendChild($inf);

    $tz = new DateTimeZone('America/Sao_Paulo');
    $dhEmi = (new DateTimeImmutable('now', $tz))->modify('-60 seconds');
    $inf->appendChild($el('tpAmb', $cfg['environment'] === 'production' ? '1' : '2'));
    $inf->appendChild($el('dhEmi', $dhEmi->format('Y-m-d\TH:i:sP')));
    $inf->appendChild($el('verAplic', NFSE_APP_VERSION));
    $inf->appendChild($el('serie', $inv['dps_serie']));
    $inf->appendChild($el('nDPS', (string)$inv['dps_number']));
    $competence = min($inv['competence_date'] ?: $dhEmi->format('Y-m-d'), $dhEmi->format('Y-m-d'));
    $inf->appendChild($el('dCompet', $competence));
    $inf->appendChild($el('tpEmit', '1')); // 1 = prestador
    $inf->appendChild($el('cLocEmi', $cfg['city_code']));

    // prestador
    $prest = $el('prest');
    $prest->appendChild($el('CNPJ', $cfg['cnpj']));
    if ($cfg['im'] !== '') $prest->appendChild($el('IM', $cfg['im']));
    [$regAp, $regEsp] = nfse_regime((string)$cfg['op_simp_nac'], $cfg['reg_ap_trib_sn'] ?? '', $cfg['reg_esp_trib'] ?? '0', '1');
    $reg = $el('regTrib');
    $reg->appendChild($el('opSimpNac', $cfg['op_simp_nac']));
    if ($regAp !== '') $reg->appendChild($el('regApTribSN', $regAp));
    $reg->appendChild($el('regEspTrib', $regEsp));
    $prest->appendChild($reg);
    $inf->appendChild($prest);

    // tomador
    $doc = only_digits((string)$inv['toma_document']);
    if ($doc !== '') {
        $toma = $el('toma');
        $toma->appendChild($el(strlen($doc) === 11 ? 'CPF' : 'CNPJ', $doc));
        $toma->appendChild($el('xNome', nfse_text((string)$inv['toma_name'], 300)));
        $a = $inv['toma_address'] ?? [];
        if (strlen(only_digits((string)($a['city_ibge'] ?? ''))) === 7 && strlen(only_digits((string)($a['cep'] ?? ''))) === 8 && trim((string)($a['street'] ?? '')) !== '') {
            $end = $el('end'); // required for CNPJ takers and for withheld ISS (E0235/E0237)
            $nac = $el('endNac');
            $nac->appendChild($el('cMun', only_digits((string)$a['city_ibge'])));
            $nac->appendChild($el('CEP', only_digits((string)$a['cep'])));
            $end->appendChild($nac);
            $end->appendChild($el('xLgr', nfse_text((string)$a['street'], 255)));
            $end->appendChild($el('nro', nfse_text((string)($a['number'] ?? '') ?: 'S/N', 60)));
            if (trim((string)($a['complement'] ?? '')) !== '') $end->appendChild($el('xCpl', nfse_text((string)$a['complement'], 156)));
            $end->appendChild($el('xBairro', nfse_text((string)($a['district'] ?? '') ?: '-', 60)));
            $toma->appendChild($end);
        }
        $fone = only_digits((string)($inv['toma_phone'] ?? ''));
        if (strlen($fone) >= 6 && strlen($fone) <= 20) $toma->appendChild($el('fone', $fone));
        if (!empty($inv['toma_email']) && filter_var($inv['toma_email'], FILTER_VALIDATE_EMAIL)) $toma->appendChild($el('email', mb_substr($inv['toma_email'], 0, 80)));
        $inf->appendChild($toma);
    }

    // serviço
    $serv = $el('serv');
    $loc = $el('locPrest');
    $loc->appendChild($el('cLocPrestacao', $cfg['city_code']));
    $serv->appendChild($loc);
    $cServ = $el('cServ');
    $cServ->appendChild($el('cTribNac', $inv['service_code']));
    $cServ->appendChild($el('xDescServ', nfse_text((string)$inv['description'], 2000)));
    if (!empty($inv['nbs_code'])) $cServ->appendChild($el('cNBS', $inv['nbs_code']));
    $serv->appendChild($cServ);
    $inf->appendChild($serv);

    // valores
    $valores = $el('valores');
    $vsp = $el('vServPrest');
    $vsp->appendChild($el('vServ', $num($inv['amount'])));
    $valores->appendChild($vsp);
    if ((float)($inv['discount_amount'] ?? 0) > 0) {
        $d = $el('vDescCondIncond');
        $d->appendChild($el('vDescIncond', $num($inv['discount_amount'])));
        $valores->appendChild($d);
    }
    if ((float)($inv['deductions'] ?? 0) > 0) {
        $d = $el('vDedRed');
        $d->appendChild($el('vDR', $num($inv['deductions'])));
        $valores->appendChild($d);
    }
    $trib = $el('trib');
    $tribMun = $el('tribMun');
    $tribMun->appendChild($el('tribISSQN', '1')); // 1 = operação tributável
    $tribMun->appendChild($el('tpRetISSQN', $inv['iss_withheld'] ? '2' : '1'));
    if (($aliq ?? nfse_send_aliq((string)$cfg['op_simp_nac'], $regAp, $regEsp, '1', !empty($inv['iss_withheld']))) && (float)$inv['iss_rate'] > 0) $tribMun->appendChild($el('pAliq', $num($inv['iss_rate'])));
    $trib->appendChild($tribMun);
    // federal taxes (only when there is something to declare)
    $pis = (float)($inv['pis_amount'] ?? 0);
    $cof = (float)($inv['cofins_amount'] ?? 0);
    $hasFed = $pis > 0 || $cof > 0 || !empty($inv['inss_withheld']) || !empty($inv['irrf_withheld']) || !empty($inv['csll_withheld']);
    if ($hasFed) {
        $fed = $el('tribFed');
        if ($pis > 0 || $cof > 0) {
            $pc = $el('piscofins');
            $pc->appendChild($el('CST', (string)($inv['pis_cofins_cst'] ?: '01')));
            $pc->appendChild($el('vBCPisCofins', $num((float)$inv['amount'] - (float)($inv['discount_amount'] ?? 0))));
            if ($pis > 0) $pc->appendChild($el('pAliqPis', $num($inv['pis_rate'])));
            if ($cof > 0) $pc->appendChild($el('pAliqCofins', $num($inv['cofins_rate'])));
            if ($pis > 0) $pc->appendChild($el('vPis', $num($pis)));
            if ($cof > 0) $pc->appendChild($el('vCofins', $num($cof)));
            $map = ['111' => '3', '110' => '4', '100' => '5', '010' => '6', '011' => '7', '001' => '8', '101' => '9', '000' => '0'];
            $pc->appendChild($el('tpRetPisCofins', $map[(int)!empty($inv['pis_withheld']) . (int)!empty($inv['cofins_withheld']) . (int)!empty($inv['csll_withheld'])]));
            $fed->appendChild($pc);
        }
        if (!empty($inv['inss_withheld'])) $fed->appendChild($el('vRetCP', $num($inv['inss_amount'])));
        if (!empty($inv['irrf_withheld'])) $fed->appendChild($el('vRetIRRF', $num($inv['irrf_amount'])));
        if (!empty($inv['csll_withheld'])) $fed->appendChild($el('vRetCSLL', $num($inv['csll_amount'])));
        $trib->appendChild($fed);
    }
    $valores->appendChild($trib);
    $fedPct = (float)($inv['pis_rate'] ?? 0) + (float)($inv['cofins_rate'] ?? 0) + (float)($inv['csll_rate'] ?? 0) + (float)($inv['irrf_rate'] ?? 0);
    nfse_append_tot_trib($dom, $trib, nfse_tot_trib((string)$cfg['op_simp_nac'], (float)$cfg['simples_percent'], (float)($inv['total_taxes_pct'] ?? 0), $fedPct, (float)$inv['iss_rate']));
    $inf->appendChild($valores);

    return $dom->saveXML($dom->documentElement);
}

/* ------------------------------------------------------------------- client */

function nfse_http(string $method, string $url, ?array $body, array $certificate, string $accept = 'application/json'): array
{
    $dir = STORAGE_PATH . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $certFile = tempnam($dir, 'c');
    $keyFile = tempnam($dir, 'k');
    file_put_contents($certFile, $certificate['cert'] . implode('', $certificate['chain'] ?? [])); // leaf + intermediates for mTLS
    file_put_contents($keyFile, $certificate['key']);
    @chmod($certFile, 0600);
    @chmod($keyFile, 0600);
    try {
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSLCERT => $certFile,
                CURLOPT_SSLKEY => $keyFile,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Content-Type: application/json', 'User-Agent: ' . NFSE_APP_VERSION],
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (($raw === false || $status >= 502) && $attempt < 3) {
                usleep(1500000 * $attempt);
                continue;
            }
            if ($raw === false) throw new NfseException('Falha de conexão com o Sistema Nacional NFS-e: ' . $err);
            return ['status' => $status, 'body' => $raw, 'json' => $accept === 'application/json' ? (json_decode($raw, true) ?? []) : null];
        }
    } finally {
        @unlink($certFile);
        @unlink($keyFile);
    }
}

function nfse_gzb64(string $xml): string
{
    // The Sefin reads the encoding from the XML declaration and rejects documents without it
    // (E1229 "XML não está utilizando codificação UTF8"). The declaration is outside the signed
    // element, so adding it here keeps the XMLDSig signature valid.
    $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
    if (strncmp($xml, '<?xml', 5) !== 0) $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
    return base64_encode(gzencode($xml));
}

function nfse_ungzb64(?string $payload): ?string
{
    if (!$payload) return null;
    $bin = base64_decode($payload, true);
    return $bin === false ? null : (@gzdecode($bin) ?: null);
}

/** Collect rejection messages from any known response shape. */
function nfse_errors(array $json): array
{
    $out = [];
    foreach (['erros', 'erro', 'Erros', 'errors'] as $k) {
        $list = $json[$k] ?? null;
        if (!$list) continue;
        if (isset($list['Codigo']) || isset($list['codigo'])) $list = [$list];
        foreach ((array)$list as $e) {
            if (is_string($e)) { $out[] = $e; continue; }
            $code = $e['Codigo'] ?? $e['codigo'] ?? '';
            $desc = $e['Descricao'] ?? $e['descricao'] ?? $e['mensagem'] ?? json_encode($e, JSON_UNESCAPED_UNICODE);
            $out[] = trim(($code ? "[$code] " : '') . $desc . (isset($e['Complemento']) ? ' — ' . $e['Complemento'] : ''));
        }
    }
    if (!$out && !empty($json['message'])) $out[] = (string)$json['message'];
    return $out;
}

/* ------------------------------------------------------------------ actions */

function nfse_find(int $id): array
{
    $inv = db_one('SELECT n.*, c.name AS customer_name FROM nfse_invoices n LEFT JOIN customers c ON c.id = n.customer_id WHERE n.id = ?', [$id]);
    if (!$inv) throw new AppException('Nota fiscal não encontrada.');
    return $inv;
}

/**
 * Create a draft invoice (reserves the next DPS number).
 */
function nfse_create_draft(array $in): int
{
    $cfg = nfse_config();
    $customer = db_find('customers', (int)($in['customer_id'] ?? 0));
    if (!$customer) throw new AppException('Selecione o cliente (tomador).');
    $doc = only_digits((string)$customer['document']);
    if (!in_array(strlen($doc), [11, 14], true)) throw new AppException('O cliente precisa ter CPF ou CNPJ válido para emitir NFS-e.');
    $amount = round((float)($in['amount'] ?? 0), 2);
    if ($amount <= 0) throw new AppException('Informe o valor do serviço.');
    $desc = trim((string)($in['description'] ?? '')) ?: $cfg['default_description'];

    return db_transaction(function () use ($cfg, $customer, $doc, $amount, $desc, $in) {
        $number = max($cfg['next_number'], 1 + (int)db_value('SELECT COALESCE(MAX(dps_number), 0) FROM nfse_invoices WHERE environment = ? AND dps_serie = ?', [$cfg['environment'], $cfg['serie']]));
        set_setting('nfse_next_number', $number + 1);
        $tax = nfse_taxes($in + ['toma_document' => $doc], $cfg);
        $sig = $cfg['provider'] === 'sigiss' ? sigiss_config() : null;
        if ($cfg['show_taxes_in_description'] && empty($in['skip_tax_note'])) $desc = mb_substr($desc . nfse_taxes_note($tax), 0, 2000);
        return db_insert('nfse_invoices', [
            'provider' => $cfg['provider'],
            'customer_id' => $customer['id'],
            'charge_id' => !empty($in['charge_id']) ? (int)$in['charge_id'] : null,
            'entry_id' => !empty($in['entry_id']) ? (int)$in['entry_id'] : null,
            'environment' => $cfg['environment'],
            'dps_serie' => $cfg['serie'], 'dps_number' => $number,
            'dps_id' => nfse_dps_id($cfg, $cfg['serie'], $number),
            'toma_document' => $doc, 'toma_name' => $customer['name'], 'toma_email' => $customer['email'], 'toma_phone' => $customer['phone'],
            'service_code' => preg_replace('/\D/', '', (string)($in['service_code'] ?? '')) ?: ($sig ? $sig['servico'] : $cfg['ctribnac']),
            'nbs_code' => preg_replace('/\D/', '', (string)($in['nbs_code'] ?? '')) ?: $cfg['cnbs'],
            'description' => mb_substr($desc, 0, 2000),
            'amount' => $amount, 'contract_id' => !empty($in['contract_id']) ? (int)$in['contract_id'] : null,
            'iss_rate' => $tax['iss_rate'], 'iss_amount' => $tax['iss_amount'], 'iss_withheld' => $tax['iss_withheld'],
            'discount_amount' => $tax['discount_amount'], 'deductions' => $tax['deductions'], 'pis_cofins_cst' => $tax['pis_cofins_cst'],
            'pis_rate' => $tax['pis_rate'], 'pis_amount' => $tax['pis_amount'], 'pis_withheld' => $tax['pis_withheld'],
            'cofins_rate' => $tax['cofins_rate'], 'cofins_amount' => $tax['cofins_amount'], 'cofins_withheld' => $tax['cofins_withheld'],
            'csll_rate' => $tax['csll_rate'], 'csll_amount' => $tax['csll_amount'], 'csll_withheld' => $tax['csll_withheld'],
            'irrf_rate' => $tax['irrf_rate'], 'irrf_amount' => $tax['irrf_amount'], 'irrf_withheld' => $tax['irrf_withheld'],
            'inss_rate' => $tax['inss_rate'], 'inss_amount' => $tax['inss_amount'], 'inss_withheld' => $tax['inss_withheld'],
            'total_taxes_pct' => $tax['total_taxes_pct'], 'total_taxes_amount' => $tax['total_taxes_amount'], 'net_amount' => $tax['net_amount'],
            'competence_date' => !empty($in['competence_date']) ? $in['competence_date'] : today(),
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

/**
 * Build + validate + sign + transmit. Returns the updated invoice.
 */
function nfse_transmit(int $id): array
{
    $inv = nfse_find($id);
    if (in_array($inv['status'], ['authorized', 'canceled'], true)) throw new AppException('Esta nota já foi autorizada.');
    if (($inv['provider'] ?? 'nacional') === 'sigiss') return sigiss_transmit($id);
    $cfg = nfse_config();
    if (strlen($cfg['cnpj']) !== 14) throw new AppException('Configure o CNPJ do prestador em Configurações → NFS-e.');

    $fail = function (string $msg, array $details = []) use ($id) {
        db_update('nfse_invoices', $id, ['status' => 'rejected', 'error_message' => implode("\n", array_merge([$msg], $details)), 'updated_at' => now()]);
        throw new NfseException($msg, $details);
    };
    // Taker address (CEP → street/IBGE via ViaCEP when missing): required for CNPJ takers (E0235).
    $customer = !empty($inv['customer_id']) ? db_find('customers', (int)$inv['customer_id']) : null;
    if ($customer) {
        $customer = customer_address_complete($customer);
        $inv['toma_address'] = ['cep' => $customer['postal_code'] ?? '', 'street' => $customer['address'] ?? '', 'number' => $customer['address_number'] ?? '',
            'complement' => $customer['address_complement'] ?? '', 'district' => $customer['district'] ?? '', 'city_ibge' => $customer['city_ibge'] ?? ''];
    }
    $a = $inv['toma_address'] ?? [];
    $hasAddr = strlen(only_digits((string)($a['city_ibge'] ?? ''))) === 7 && strlen(only_digits((string)($a['cep'] ?? ''))) === 8 && trim((string)($a['street'] ?? '')) !== '';
    $problems = [];
    $tdoc = only_digits((string)$inv['toma_document']);
    if ($tdoc !== '' && $tdoc === $cfg['cnpj']) $problems[] = 'O tomador não pode ser a própria empresa (E0202).';
    if ((strlen($tdoc) === 14 || !empty($inv['iss_withheld'])) && !$hasAddr) $problems[] = 'Complete o endereço do cliente (CEP, rua e número) no cadastro: a Sefin exige o endereço do tomador com CNPJ ou com ISS retido (E0235/E0237).';
    if (!empty($inv['iss_withheld']) && $tdoc === '') $problems[] = 'Com ISS retido, o cliente precisa de CPF/CNPJ (E0204).';
    if (!empty($inv['iss_withheld']) && $cfg['op_simp_nac'] === '2') $problems[] = 'MEI não pode ter ISS retido (E0583).';
    if ($msg = nfse_nbs_problem((string)($inv['nbs_code'] ?? ''))) $problems[] = $msg;
    if ($problems) $fail('Corrija antes de emitir (a Sefin recusaria a nota):', $problems);
    try {
        $certificate = nfse_certificate();
        if ($certificate['info']['expired']) throw new NfseException('O certificado digital está vencido.');
    } catch (NfseException $e) {
        $fail($e->getMessage(), $e->details);
    }
    $aliq = null;
    for ($attempt = 1; ; $attempt++) {
        try {
            $xml = nfse_build_dps($inv, $cfg, $aliq);
            nfse_validate_xsd($xml, 'DPS_v1.01.xsd');
            $signed = nfse_sign($xml, 'infDPS', $certificate);
        } catch (NfseException $e) {
            $fail($e->getMessage(), $e->details);
        }
        db_update('nfse_invoices', $id, ['status' => 'processing', 'xml_dps' => $signed, 'error_message' => null, 'updated_at' => now()]);

        $res = nfse_http('POST', nfse_base() . '/nfse', ['dpsXmlGZipB64' => nfse_gzb64($signed)], $certificate);
        $json = $res['json'];
        $nfseXml = nfse_ungzb64($json['nfseXmlGZipB64'] ?? null);
        $key = $json['chaveAcesso'] ?? null;

        if ($res['status'] === 409 || ($res['status'] >= 400 && !$key && preg_match('/E0014|duplic/i', $res['body']))) {
            // DPS already processed earlier (e.g. timeout on our side): recover the access key.
            $dps = nfse_http('GET', nfse_base() . '/dps/' . $inv['dps_id'], null, $certificate);
            $key = $dps['json']['chaveAcesso'] ?? null;
            if ($key) {
                $q = nfse_http('GET', nfse_base() . '/nfse/' . $key, null, $certificate);
                $nfseXml = nfse_ungzb64($q['json']['nfseXmlGZipB64'] ?? null);
            }
        }
        if ($key && $res['status'] < 500) break;
        // pAliq depends on the municipal parametrization: retry once the way the Sefin asked (E06xx).
        if ($attempt === 1 && ($want = nfse_aliq_from_errors(nfse_errors($json))) !== null && !($want && (float)$inv['iss_rate'] <= 0)) { $aliq = $want; continue; }
        break;
    }

    if ($key && $res['status'] < 500) {
        $number = null;
        if ($nfseXml && preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $nfseXml, $m)) $number = $m[1];
        db_update('nfse_invoices', $id, [
            'status' => 'authorized', 'access_key' => $key, 'nfse_number' => $number,
            'xml_nfse' => $nfseXml, 'issued_at' => now(), 'error_message' => null,
            'alerts' => !empty($json['alertas']) ? json_encode($json['alertas'], JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => now(),
        ]);
        audit('authorize', 'nfse', $id, ['chave' => $key]);
        if (function_exists('mail_event_nfse_authorized')) mail_event_nfse_authorized(nfse_find($id));
        return nfse_find($id);
    }

    $errors = nfse_errors($json) ?: ['HTTP ' . $res['status'] . ': ' . mb_substr(strip_tags($res['body']), 0, 300)];
    if ($res['status'] === 403 || $res['status'] === 401) array_unshift($errors, 'Acesso negado (mTLS): confira se o certificado é e-CNPJ válido do prestador e se o município permite emissão pela API nacional.');
    $errors = array_merge($errors, nfse_error_hints($errors));
    db_update('nfse_invoices', $id, ['status' => 'rejected', 'error_message' => implode("\n", $errors), 'updated_at' => now()]);
    audit('reject', 'nfse', $id, $errors);
    throw new NfseException('A nota foi rejeitada pelo Sistema Nacional NFS-e.', $errors);
}

function nfse_cancel(int $id, int $reason, string $justification): array
{
    $inv = nfse_find($id);
    $justification = nfse_text($justification, 255);
    if (mb_strlen($justification) < 15) throw new AppException('A justificativa precisa ter pelo menos 15 caracteres.');
    if (!in_array($reason, [1, 2, 9], true)) throw new AppException('Motivo inválido.');
    if (($inv['provider'] ?? 'nacional') === 'sigiss') {
        if ($inv['status'] !== 'authorized' || !$inv['nfse_number']) throw new AppException('Somente notas autorizadas podem ser canceladas.');
        return sigiss_cancel($inv, $reason, $justification);
    }
    if ($inv['status'] !== 'authorized' || !$inv['access_key']) throw new AppException('Somente notas autorizadas podem ser canceladas.');
    $cfg = nfse_config();

    $dom = new DOMDocument('1.0', 'UTF-8');
    $root = $dom->createElementNS(NFSE_NS, 'pedRegEvento');
    $root->setAttribute('versao', NFSE_LAYOUT);
    $dom->appendChild($root);
    $info = $dom->createElement('infPedReg');
    $info->setAttribute('Id', 'PRE' . $inv['access_key'] . '101101');
    $root->appendChild($info);
    $dh = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-60 seconds')->format('Y-m-d\TH:i:sP');
    foreach ([
        'tpAmb' => $inv['environment'] === 'production' ? '1' : '2', 'verAplic' => NFSE_APP_VERSION,
        'dhEvento' => $dh, 'CNPJAutor' => $cfg['cnpj'], 'chNFSe' => $inv['access_key'],
    ] as $k => $v) {
        $e = $dom->createElement($k);
        $e->appendChild($dom->createTextNode($v));
        $info->appendChild($e);
    }
    $grp = $dom->createElement('e101101');
    foreach (['xDesc' => 'Cancelamento de NFS-e', 'cMotivo' => (string)$reason, 'xMotivo' => $justification] as $k => $v) {
        $e = $dom->createElement($k);
        $e->appendChild($dom->createTextNode($v));
        $grp->appendChild($e);
    }
    $info->appendChild($grp);
    $xml = $dom->saveXML($dom->documentElement);
    nfse_validate_xsd($xml, 'pedRegEvento_v1.01.xsd');

    $certificate = nfse_certificate();
    $signed = nfse_sign($xml, 'infPedReg', $certificate);
    $res = nfse_http('POST', nfse_base() . '/nfse/' . $inv['access_key'] . '/eventos', ['pedidoRegistroEventoXmlGZipB64' => nfse_gzb64($signed)], $certificate);
    if ($res['status'] >= 400) {
        $errors = nfse_errors($res['json']) ?: ['HTTP ' . $res['status']];
        throw new NfseException('O cancelamento foi recusado.', $errors);
    }
    db_update('nfse_invoices', $id, [
        'status' => 'canceled', 'cancel_reason' => $justification, 'canceled_at' => now(),
        'xml_cancel' => nfse_ungzb64($res['json']['eventoXmlGZipB64'] ?? null), 'updated_at' => now(),
    ]);
    audit('cancel', 'nfse', $id, ['motivo' => $reason]);
    return nfse_find($id);
}

/** Whether the configured provider has everything needed to transmit. */
function nfse_ready(): bool
{
    $cfg = nfse_config();
    if ($cfg['provider'] === 'sigiss') return sigiss_ready();
    return (bool)setting('nfse_cert_pfx') && strlen($cfg['cnpj']) === 14;
}

/** Official DANFSe PDF from the ADN (may be unavailable; caller falls back to local HTML). */
function nfse_danfse_pdf(array $inv): ?string
{
    if (!$inv['access_key']) return null;
    try {
        $res = nfse_http('GET', nfse_base('adn') . '/danfse/' . $inv['access_key'], null, nfse_certificate(), 'application/pdf');
        return $res['status'] === 200 && strncmp($res['body'], '%PDF', 4) === 0 ? $res['body'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function nfse_municipality_params(): array
{
    $cfg = nfse_config();
    $certificate = nfse_certificate();
    $tries = [nfse_base() . '/parametros_municipais/' . $cfg['city_code'] . '/convenio', nfse_base('adn') . '/parametros_municipais/' . $cfg['city_code'] . '/convenio'];
    $last = null;
    $problems = [];
    foreach ($tries as $url) {
        try {
            $res = nfse_http('GET', $url, null, $certificate);
        } catch (NfseException $e) {
            $problems[] = $e->getMessage();
            continue;
        }
        $last = $res;
        if ($res['status'] === 200) return ['ok' => true, 'url' => $url, 'data' => $res['json']];
        $problems[] = 'HTTP ' . $res['status'] . ($res['status'] === 403 ? ' (certificado não aceito: use o e-CNPJ A1 ICP-Brasil da empresa)' : '');
    }
    return ['ok' => false, 'status' => $last['status'] ?? null, 'errors' => array_merge($problems, nfse_errors($last['json'] ?? []))];
}

/** Emit automatically when an Asaas charge is paid (setting nfse_auto_on_payment). */
function nfse_auto_emit_for_charge(array $charge): void
{
    if (!nfse_config()['auto_on_payment'] || !nfse_ready()) return;
    if (db_value("SELECT id FROM nfse_invoices WHERE charge_id = ? AND status IN ('authorized','processing','draft')", [$charge['id']])) return;
    try {
        $id = nfse_create_draft([
            'customer_id' => $charge['customer_id'], 'charge_id' => $charge['id'], 'entry_id' => $charge['entry_id'],
            'amount' => $charge['amount'], 'description' => $charge['description'],
        ]);
        nfse_transmit($id);
    } catch (Throwable $e) {
        log_line('nfse', 'auto emit failed', ['charge' => $charge['id'], 'error' => $e->getMessage()]);
    }
}
