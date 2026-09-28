<?php
declare(strict_types=1);

/**
 * NFS-e through the SIGISS municipal web service (Marília-SP and other SIGISS cities).
 * Manual: https://marilia.sigiss.com.br/marilia/download/manual%20webservice%202.0%20marilia.pdf
 * SOAP rpc/encoded, authenticated with CCM (inscrição municipal) + CNPJ + portal password.
 * No digital certificate required. Notes issued here are real (there is no test environment).
 */

const SIGISS_DEFAULT_URL = 'https://marilia.sigiss.com.br/marilia/ws/sigiss_ws.php';
const SIGISS_SITUACOES = ['tp' => 'Tributada no prestador', 'tt' => 'Tributada no tomador (ISS retido)', 'is' => 'Isenta', 'im' => 'Imune', 'nt' => 'Não tributada'];

function sigiss_config(): array
{
    return [
        'url' => (string)setting('nfse_sigiss_url', SIGISS_DEFAULT_URL) ?: SIGISS_DEFAULT_URL,
        'ccm' => only_digits((string)setting('nfse_im', '')),
        'cnpj' => only_digits((string)setting('nfse_cnpj', only_digits(COMPANY['cnpj']))),
        'password' => (string)setting('nfse_sigiss_password', ''),
        'servico' => only_digits((string)setting('nfse_sigiss_servico', '101')),
        'situacao' => (string)setting('nfse_sigiss_situacao', 'tp'),
    ];
}

function sigiss_ready(): bool
{
    $c = sigiss_config();
    return $c['ccm'] !== '' && strlen($c['cnpj']) === 14 && $c['password'] !== '' && $c['servico'] !== '';
}

/** "1500.5" → "1500,50" (SIGISS rejects dots). */
function sigiss_money($v): string
{
    return number_format((float)$v, 2, ',', '');
}

/** Undo UTF-8-read-as-Latin-1 mojibake occasionally present in SIGISS responses. */
function sigiss_fix_text(string $s): string
{
    if (preg_match('/Ã[\x{0080}-\x{00BF}]|Ã§|Ã£|Ã©/u', $s)) {
        $fixed = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if ($fixed !== false && mb_check_encoding($fixed, 'UTF-8')) return $fixed;
    }
    return $s;
}

/**
 * Call a SIGISS operation. $params: ['ParamName' => ['type' => 'tns:...', 'fields' => [name => [xsdType, value]]]]
 * or ['ParamName' => ['type' => 'xsd:int', 'value' => 1]].
 * @return DOMXPath over the response
 */
function sigiss_call(string $operation, array $params, ?string $url = null): DOMXPath
{
    $body = '';
    foreach ($params as $name => $p) {
        if (isset($p['fields'])) {
            $inner = '';
            foreach ($p['fields'] as $field => [$type, $value]) {
                if ($value === null || $value === '') continue;
                $inner .= "<$field xsi:type=\"$type\">" . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$field>";
            }
            $body .= "<$name xsi:type=\"{$p['type']}\">$inner</$name>";
        } else {
            $body .= "<$name xsi:type=\"{$p['type']}\">" . htmlspecialchars((string)$p['value'], ENT_XML1, 'UTF-8') . "</$name>";
        }
    }
    // UTF-8 end to end: the SIGISS (Reforma Tributária update) rejects Latin-1 text such as "Marília"
    // in the tomador fields. Invalid byte sequences are dropped so the payload is always valid UTF-8.
    $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:tns="urn:sigiss_ws"'
        . ' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><SOAP-ENV:Body>'
        . "<tns:$operation>$body</tns:$operation></SOAP-ENV:Body></SOAP-ENV:Envelope>";

    $endpoint = $url ?: sigiss_config()['url'];
    $attempt = 0;
    while (true) {
        $attempt++;
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'SOAPAction: "urn:sigiss_ws#' . $operation . '"'],
            CURLOPT_POSTFIELDS => $envelope,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (($raw === false || $status >= 502) && $attempt < 3) { usleep(1500000 * $attempt); continue; }
        if ($raw === false) throw new NfseException('Falha de conexão com o SIGISS da prefeitura: ' . $err);
        break;
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($raw)) {
        log_line('nfse', 'sigiss invalid response', ['op' => $operation, 'status' => $status, 'body' => mb_substr($raw, 0, 500)]);
        throw new NfseException('Resposta inválida do SIGISS (HTTP ' . $status . '). O sistema da prefeitura pode estar instável; tente novamente em instantes.');
    }
    $xp = new DOMXPath($dom);
    $fault = $xp->query('//*[local-name()="faultstring"]')->item(0);
    if ($fault) throw new NfseException('O SIGISS recusou a chamada: ' . sigiss_fix_text(trim($fault->textContent)));
    return $xp;
}

function sigiss_value(DOMXPath $xp, string $name): string
{
    $n = $xp->query('//*[local-name()="' . $name . '"]')->item(0);
    return $n ? sigiss_fix_text(trim($n->textContent)) : '';
}

function sigiss_errors(DOMXPath $xp): array
{
    $out = [];
    foreach ($xp->query('//*[local-name()="DescricaoErro"]') as $n) {
        $t = sigiss_fix_text(trim($n->textContent));
        if ($t !== '') $out[] = $t;
    }
    return array_values(array_unique($out));
}

/**
 * Errors that mean the login itself failed (CCM/CNPJ/password). Errors about the consulted note
 * ("nota não encontrada para o prestador", "nota inexistente"...) prove the login worked: the
 * credential test consults note nº 1, which most companies never issued through the web service.
 */
function sigiss_auth_errors(array $errors): array
{
    return array_values(array_filter($errors, function (string $e): bool {
        $t = mb_strtolower($e);
        if (preg_match('/senha|autentic|acesso negado|n[aã]o autorizad|permiss[aã]o|bloquead/u', $t)) return true;
        if (preg_match('/\bnota\b|\brps\b|n[uú]mero/u', $t)) return false;
        return (bool)preg_match('/ccm|inscri[cç][aã]o|cnpj|prestador|contribuinte/u', $t);
    }));
}

function sigiss_prestador_fields(array $cfg): array
{
    return ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]];
}

/** Verify CCM/CNPJ/password without issuing anything. */
function sigiss_test_credentials(): array
{
    $cfg = sigiss_config();
    if (!sigiss_ready()) throw new AppException('Preencha CNPJ, inscrição municipal (CCM), senha do SIGISS e código do serviço.');
    $xp = sigiss_call('ConsultarNotaPrestador', [
        'DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => sigiss_prestador_fields($cfg)],
        'Nota' => ['type' => 'xsd:int', 'value' => 1],
    ]);
    $authFail = sigiss_auth_errors(sigiss_errors($xp));
    return ['ok' => !$authFail, 'messages' => $authFail ?: ['Acesso ao SIGISS de Marília confirmado.'], 'sample_note' => sigiss_value($xp, 'nota')];
}

/* ------------------------------------------------------------- addresses */

/** Look up a Brazilian CEP (ViaCEP). Returns [logradouro, bairro, localidade, uf, ibge] or null. */
function cep_lookup(string $cep): ?array
{
    $cep = only_digits($cep);
    if (strlen($cep) !== 8) return null;
    $ch = curl_init(rtrim((string)config('cep_api_url', 'https://viacep.com.br/ws'), '/') . "/$cep/json/");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) && empty($j['erro']) && !empty($j['ibge']) ? $j : null;
}

/** Complete missing customer address data from the CEP (persisted). */
function customer_address_complete(array $customer): array
{
    if ((empty($customer['city_ibge']) || empty($customer['district']) || empty($customer['address'])) && !empty($customer['postal_code'])) {
        if ($cep = cep_lookup((string)$customer['postal_code'])) {
            $upd = array_filter([
                'city_ibge' => $customer['city_ibge'] ?: $cep['ibge'],
                'district' => $customer['district'] ?: ($cep['bairro'] ?? ''),
                'address' => $customer['address'] ?: ($cep['logradouro'] ?? ''),
                'city' => $customer['city'] ?: ($cep['localidade'] ?? ''),
                'state' => $customer['state'] ?: ($cep['uf'] ?? ''),
            ], fn($v) => $v !== '' && $v !== null);
            if ($upd) {
                db_update('customers', (int)$customer['id'], $upd + ['updated_at' => now()]);
                $customer = array_merge($customer, $upd);
            }
        }
    }
    return $customer;
}

/* ----------------------------------------------------------------- emit */

function sigiss_transmit(int $id): array
{
    $inv = nfse_find($id);
    $cfg = sigiss_config();
    if (!sigiss_ready()) throw new AppException('Configure o SIGISS em Configurações → NFS-e (CCM, senha e código do serviço).');
    $customer = db_find('customers', (int)$inv['customer_id']);
    if (!$customer) throw new AppException('Cliente (tomador) não encontrado.');
    $customer = customer_address_complete($customer);

    $doc = only_digits((string)$inv['toma_document']);
    $isCpf = strlen($doc) === 11;
    $tipo = $isCpf ? 2 : ((string)($customer['city_ibge'] ?? '') === '3529005' ? 3 : 4);
    $missing = [];
    foreach (['address' => 'endereço', 'address_number' => 'número', 'district' => 'bairro', 'postal_code' => 'CEP', 'city_ibge' => 'código IBGE da cidade (preenchido pelo CEP)'] as $k => $label) {
        if (trim((string)($customer[$k] ?? '')) === '') $missing[] = $label;
    }
    if ($missing) {
        $msg = 'Complete o endereço do cliente antes de emitir: ' . implode(', ', $missing) . '.';
        db_update('nfse_invoices', $id, ['status' => 'rejected', 'error_message' => $msg, 'updated_at' => now()]);
        throw new NfseException($msg, ['Abra o cadastro do cliente, informe o CEP (o endereço é preenchido automaticamente) e o número.']);
    }

    $conf = nfse_config();
    $situacao = $inv['iss_withheld'] ? 'tt' : $cfg['situacao'];
    $issBase = (float)$inv['amount'] - (float)($inv['discount_amount'] ?? 0) - (float)($inv['deductions'] ?? 0);
    $issValue = round($issBase * (float)$inv['iss_rate'] / 100, 2);
    $date = strtotime($inv['created_at']);
    $fields = sigiss_prestador_fields($cfg) + [
        'aliquota_simples' => ['xsd:string', $conf['op_simp_nac'] !== '1' && (float)$inv['iss_rate'] > 0 ? sigiss_money($inv['iss_rate']) : ''],
        'id_sis_legado' => ['xsd:string', (string)$inv['id']],
        'servico' => ['xsd:int', $cfg['servico']],
        'situacao' => ['xsd:string', $situacao],
        'valor' => ['xsd:string', sigiss_money($inv['amount'])],
        'base' => ['xsd:string', sigiss_money((float)$inv['amount'] - (float)($inv['discount_amount'] ?? 0) - (float)($inv['deductions'] ?? 0))],
        'descricaoNF' => ['xsd:string', nfse_text((string)$inv['description'], 1500)],
        'tomador_tipo' => ['xsd:int', (string)$tipo],
        'tomador_cnpj' => ['xsd:string', $doc],
        'tomador_email' => ['xsd:string', (string)($inv['toma_email'] ?? '')],
        'tomador_razao' => ['xsd:string', nfse_text((string)$inv['toma_name'], 150)],
        'tomador_fantasia' => ['xsd:string', nfse_text((string)($customer['trade_name'] ?? ''), 150)],
        'tomador_endereco' => ['xsd:string', nfse_text((string)$customer['address'], 120)],
        'tomador_numero' => ['xsd:string', nfse_text((string)$customer['address_number'], 10)],
        'tomador_bairro' => ['xsd:string', nfse_text((string)$customer['district'], 60)],
        'tomador_CEP' => ['xsd:string', only_digits((string)$customer['postal_code'])],
        'tomador_cod_cidade' => ['xsd:string', (string)$customer['city_ibge']],
        'tomador_fone' => ['xsd:string', only_digits((string)($customer['phone'] ?? ''))],
        'rps_num' => ['xsd:int', (string)$inv['dps_number']],
        'rps_serie' => ['xsd:string', (string)$inv['dps_serie']],
        'rps_dia' => ['xsd:int', date('j', $date)],
        'rps_mes' => ['xsd:int', date('n', $date)],
        'rps_ano' => ['xsd:int', date('Y', $date)],
        'retencao_iss' => ['xsd:string', $inv['iss_withheld'] ? sigiss_money($issValue) : ''],
        'pis' => ['xsd:string', (float)($inv['pis_amount'] ?? 0) > 0 ? sigiss_money($inv['pis_amount']) : ''],
        'cofins' => ['xsd:string', (float)($inv['cofins_amount'] ?? 0) > 0 ? sigiss_money($inv['cofins_amount']) : ''],
        'inss' => ['xsd:string', !empty($inv['inss_withheld']) ? sigiss_money($inv['inss_amount']) : ''],
        'irrf' => ['xsd:string', !empty($inv['irrf_withheld']) ? sigiss_money($inv['irrf_amount']) : ''],
        'csll' => ['xsd:string', (float)($inv['csll_amount'] ?? 0) > 0 ? sigiss_money($inv['csll_amount']) : ''],
        'valor_total_tributos' => ['xsd:string', (float)($inv['total_taxes_amount'] ?? 0) > 0 ? sigiss_money($inv['total_taxes_amount']) : ''],
        'xnbs' => ['xsd:string', ''], // NBS description (optional); the code goes in dps_serv_cnbs
        'dps_serv_cnbs' => ['xsd:string', only_digits((string)($inv['nbs_code'] ?? ''))],
    ];

    // Withholding flags (optional ints) must sit between "csll" and "valor_total_tributos" (WSDL sequence).
    $flags = array_filter(['retencao_pis' => !empty($inv['pis_withheld']), 'retencao_cofins' => !empty($inv['cofins_withheld']), 'retencao_csll' => !empty($inv['csll_withheld'])]);
    if ($flags) {
        $ordered = [];
        foreach ($fields as $k => $v) {
            if ($k === 'valor_total_tributos') foreach ($flags as $fk => $_) $ordered[$fk] = ['xsd:int', '1'];
            $ordered[$k] = $v;
        }
        $fields = $ordered;
    }

    db_update('nfse_invoices', $id, ['status' => 'processing', 'error_message' => null, 'updated_at' => now()]);
    try {
        $xp = sigiss_call('GerarNota', ['DescricaoRps' => ['type' => 'tns:tcDescricaoRps', 'fields' => $fields]]);
    } catch (NfseException $e) {
        db_update('nfse_invoices', $id, ['status' => 'rejected', 'error_message' => $e->getMessage(), 'updated_at' => now()]);
        throw $e;
    }
    $ok = sigiss_value($xp, 'Resultado') === '1' && (int)sigiss_value($xp, 'Nota') > 0;
    if (!$ok) {
        $errors = sigiss_errors($xp) ?: ['O SIGISS não gerou a nota e não informou o motivo.'];
        db_update('nfse_invoices', $id, ['status' => 'rejected', 'error_message' => implode("\n", $errors), 'updated_at' => now()]);
        audit('reject', 'nfse', $id, $errors);
        throw new NfseException('A prefeitura (SIGISS) recusou a nota.', $errors);
    }
    $number = sigiss_value($xp, 'Nota');
    $update = [
        'status' => 'authorized', 'nfse_number' => $number, 'print_url' => sigiss_value($xp, 'LinkImpressao') ?: null,
        'verification_code' => sigiss_value($xp, 'autenticidade') ?: null, 'issued_at' => now(), 'error_message' => null,
        'iss_amount' => $issValue, 'updated_at' => now(),
    ];
    // Best effort: fetch full note data (national access key, verification code).
    try {
        $q = sigiss_call('ConsultarNotaPrestador', [
            'DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => sigiss_prestador_fields($cfg)],
            'Nota' => ['type' => 'xsd:int', 'value' => (int)$number],
        ]);
        if ($k = only_digits(sigiss_value($q, 'chaveacesso'))) $update['access_key'] = $k;
        if ($a = sigiss_value($q, 'autenticidade')) $update['verification_code'] = $a;
        if (!$update['print_url'] && ($l = sigiss_value($q, 'LinkImpressao'))) $update['print_url'] = $l;
    } catch (Throwable $e) {
        log_line('nfse', 'sigiss consult after emit failed', ['id' => $id, 'error' => $e->getMessage()]);
    }
    db_update('nfse_invoices', $id, $update);
    audit('authorize', 'nfse', $id, ['nota' => $number, 'provider' => 'sigiss']);
    if (function_exists('mail_event_nfse_authorized')) mail_event_nfse_authorized(nfse_find($id));
    return nfse_find($id);
}

function sigiss_cancel(array $inv, int $reason, string $justification): array
{
    $cfg = sigiss_config();
    $xp = sigiss_call('CancelarNota', ['DadosCancelaNota' => ['type' => 'tns:tcDadosCancelaNota', 'fields' => sigiss_prestador_fields($cfg) + [
        'nota' => ['xsd:int', (string)$inv['nfse_number']],
        'cMotivo' => ['xsd:int', (string)$reason],
        'xMotivo' => ['xsd:string', $justification],
        'email' => ['xsd:string', (string)($inv['toma_email'] ?? '')],
    ]]]);
    if (sigiss_value($xp, 'Resultado') !== '1') {
        throw new NfseException('A prefeitura (SIGISS) recusou o cancelamento.', sigiss_errors($xp) ?: ['Motivo não informado pelo SIGISS.']);
    }
    db_update('nfse_invoices', (int)$inv['id'], ['status' => 'canceled', 'cancel_reason' => $justification, 'canceled_at' => now(), 'updated_at' => now()]);
    audit('cancel', 'nfse', $inv['id'], ['motivo' => $reason, 'provider' => 'sigiss']);
    return nfse_find((int)$inv['id']);
}
