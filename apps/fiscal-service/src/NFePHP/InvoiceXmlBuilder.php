<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\EmitInvoiceRequest;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use NFePHP\Common\Keys;
use NFePHP\NFe\Make;
use stdClass;

/**
 * Monta o XML da NF-e (55) / NFC-e (65) usando NFePHP\NFe\Make.
 * Layout 4.00.
 */
final class InvoiceXmlBuilder
{
    /** @return array{xml:string, accessKey:string} */
    public function build(EmitInvoiceRequest $request): array
    {
        $make = new Make();
        $company = $request->company;
        $issuer = $company->issuer;

        $issuerCnpj = preg_replace('/\D/', '', (string) $issuer['cnpj']) ?? '';
        $issueDate = new \DateTime($request->issueDate);
        $cUF = $this->ufCode($company->uf);
        $cNF = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $accessKey = Keys::build(
            $cUF,
            $issueDate->format('y'),
            $issueDate->format('m'),
            $issuerCnpj,
            (int) $request->model,
            $request->series,
            $request->number,
            $request->emissionType,
            $cNF,
        );
        $cDV = (int) substr($accessKey, -1);

        // infNFe
        $inf = new stdClass();
        $inf->Id = 'NFe' . $accessKey;
        $inf->versao = '4.00';
        $make->taginfNFe($inf);

        // ide
        $ide = new stdClass();
        $ide->cUF = (int) $cUF;
        $ide->cNF = $cNF;
        $ide->natOp = mb_substr($request->operationNature, 0, 60);
        $ide->mod = (int) $request->model;
        $ide->serie = $request->series;
        $ide->nNF = $request->number;
        $ide->dhEmi = $issueDate->format('Y-m-d\TH:i:sP');
        $ide->tpNF = $request->operationType;
        $ide->idDest = $request->destinationIndicator;
        $ide->cMunFG = (int) ($issuer['address']['cityCode'] ?? 0);
        $ide->tpImp = $request->model === '65' ? 4 : 1; // 4 = DANFE NFC-e
        $ide->tpEmis = $request->emissionType;
        $ide->cDV = $cDV;
        $ide->tpAmb = $company->tpAmb();
        $ide->finNFe = 1;
        $ide->indFinal = $request->model === '65' ? 1 : 1;
        $ide->indPres = $request->presenceIndicator;
        $ide->indIntermed = 0;
        $ide->procEmi = 0;
        $ide->verProc = 'IntegraSYS 1.0';
        $make->tagide($ide);

        // emit
        $emit = new stdClass();
        $emit->xNome = mb_substr((string) $issuer['corporateName'], 0, 60);
        if (!empty($issuer['tradeName'])) {
            $emit->xFant = mb_substr((string) $issuer['tradeName'], 0, 60);
        }
        $ieRaw = strtoupper(trim((string) ($issuer['stateRegistration'] ?? '')));
        $emit->IE = $ieRaw === '' ? 'ISENTO' : preg_replace('/[^0-9]/', '', $ieRaw);
        if ($ieRaw === 'ISENTO') {
            $emit->IE = 'ISENTO';
        }
        if (!empty($issuer['municipalRegistration'])) {
            $emit->IM = (string) $issuer['municipalRegistration'];
        }
        $emit->CRT = (int) $issuer['crt'];
        $emit->CNPJ = $issuerCnpj;
        $make->tagemit($emit);

        // enderEmit
        $address = $issuer['address'];
        $enderEmit = new stdClass();
        $enderEmit->xLgr = mb_substr((string) ($address['street'] ?? ''), 0, 60);
        $enderEmit->nro = (string) ($address['number'] ?? 'S/N');
        if (!empty($address['complement'])) {
            $enderEmit->xCpl = mb_substr((string) $address['complement'], 0, 60);
        }
        $enderEmit->xBairro = mb_substr((string) ($address['district'] ?? ''), 0, 60);
        $enderEmit->cMun = (int) ($address['cityCode'] ?? 0);
        $enderEmit->xMun = (string) ($address['cityName'] ?? '');
        $enderEmit->UF = $company->uf;
        $enderEmit->CEP = preg_replace('/\D/', '', (string) ($address['zipCode'] ?? ''));
        $enderEmit->cPais = 1058;
        $enderEmit->xPais = 'BRASIL';
        if (!empty($address['phone'])) {
            $enderEmit->fone = preg_replace('/\D/', '', (string) $address['phone']);
        }
        $make->tagenderEmit($enderEmit);

        // dest
        $this->buildRecipient($make, $request, $company->tpAmb());

        // itens + totais
        $totals = $this->buildItems($make, $request);

        // ICMSTot
        $icmsTot = new stdClass();
        $icmsTot->vBC = $this->money($totals['icmsBase']);
        $icmsTot->vICMS = $this->money($totals['icmsValue']);
        $icmsTot->vICMSDeson = '0.00';
        $icmsTot->vFCP = '0.00';
        $icmsTot->vBCST = '0.00';
        $icmsTot->vST = '0.00';
        $icmsTot->vFCPST = '0.00';
        $icmsTot->vFCPSTRet = '0.00';
        $icmsTot->vProd = $this->money($totals['products']);
        $icmsTot->vFrete = $this->money($totals['freight']);
        $icmsTot->vSeg = '0.00';
        $icmsTot->vDesc = $this->money($totals['discount']);
        $icmsTot->vII = '0.00';
        $icmsTot->vIPI = '0.00';
        $icmsTot->vIPIDevol = '0.00';
        $icmsTot->vPIS = $this->money($totals['pisValue']);
        $icmsTot->vCOFINS = $this->money($totals['cofinsValue']);
        $icmsTot->vOutro = '0.00';
        $icmsTot->vNF = $this->money($totals['invoice']);
        $make->tagICMSTot($icmsTot);

        // transp
        $transp = new stdClass();
        $transp->modFrete = $request->freight['modality'] ?? 9;
        $make->tagtransp($transp);

        // pag + detPag
        $pag = new stdClass();
        $pag->vTroco = null;
        $make->tagpag($pag);

        foreach ($request->payments as $payment) {
            $detPag = new stdClass();
            $detPag->indPag = 0;
            $detPag->tPag = (string) $payment['method'];
            $detPag->vPag = $this->money((float) $payment['amount']);
            if (in_array((string) $payment['method'], ['03', '04'], true)) {
                $detPag->tpIntegra = (int) ($payment['integrationType'] ?? 2);
                if (!empty($payment['authorizationCode'])) {
                    $detPag->cAut = (string) $payment['authorizationCode'];
                }
            }
            $make->tagdetPag($detPag);
        }

        // infAdic
        $additional = trim((string) ($request->additionalInformation ?? ''));
        if ($company->tpAmb() === 2) {
            $notice = 'EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
            $additional = $additional === '' ? $notice : $notice . '. ' . $additional;
        }
        if ($additional !== '') {
            $infAdic = new stdClass();
            $infAdic->infCpl = mb_substr($additional, 0, 5000);
            $make->taginfAdic($infAdic);
        }

        // infRespTec (opcional, se enviado no contexto)
        if (!empty($issuer['technician']) && is_array($issuer['technician'])) {
            $tech = $issuer['technician'];
            if (!empty($tech['cnpj']) && !empty($tech['email'])) {
                $respTec = new stdClass();
                $respTec->CNPJ = preg_replace('/\D/', '', (string) $tech['cnpj']);
                $respTec->xContato = (string) ($tech['name'] ?? 'Responsavel Tecnico');
                $respTec->email = (string) $tech['email'];
                $respTec->fone = preg_replace('/\D/', '', (string) ($tech['phone'] ?? '')) ?: '0000000000';
                $make->taginfRespTec($respTec);
            }
        }

        $xml = $make->getXML();
        $errors = $make->getErrors();
        if (!empty($errors)) {
            throw FiscalException::validation('Erros ao montar XML da nota', ['errors' => $errors]);
        }

        return ['xml' => $xml, 'accessKey' => $accessKey];
    }

    private function buildRecipient(Make $make, EmitInvoiceRequest $request, int $tpAmb): void
    {
        $recipient = $request->recipient;

        if ($recipient === null || (empty($recipient['document']) && $request->model === '65')) {
            // NFC-e permite consumidor não identificado
            return;
        }

        $dest = new stdClass();
        $document = preg_replace('/\D/', '', (string) ($recipient['document'] ?? ''));

        $name = (string) ($recipient['name'] ?? 'CONSUMIDOR');
        if ($tpAmb === 2) {
            // Em homologação a SEFAZ exige esta razão social no destinatário da NF-e
            $name = $request->model === '55'
                ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
                : $name;
        }
        $dest->xNome = mb_substr($name, 0, 60);

        if ($request->model === '55') {
            $dest->indIEDest = (int) ($recipient['stateRegistrationIndicator'] ?? 9);
            if (!empty($recipient['stateRegistration']) && $dest->indIEDest === 1) {
                $dest->IE = (string) $recipient['stateRegistration'];
            }
        } else {
            $dest->indIEDest = 9;
        }

        if (!empty($recipient['email'])) {
            $dest->email = (string) $recipient['email'];
        }

        if (strlen($document) === 14) {
            $dest->CNPJ = $document;
        } elseif (strlen($document) === 11) {
            $dest->CPF = $document;
        }

        $make->tagdest($dest);

        if ($request->model === '55' && !empty($recipient['address']) && is_array($recipient['address'])) {
            $addr = $recipient['address'];
            $enderDest = new stdClass();
            $enderDest->xLgr = mb_substr((string) ($addr['street'] ?? ''), 0, 60);
            $enderDest->nro = (string) ($addr['number'] ?? 'S/N');
            if (!empty($addr['complement'])) {
                $enderDest->xCpl = mb_substr((string) $addr['complement'], 0, 60);
            }
            $enderDest->xBairro = mb_substr((string) ($addr['district'] ?? ''), 0, 60);
            $enderDest->cMun = (int) ($addr['cityCode'] ?? 0);
            $enderDest->xMun = (string) ($addr['cityName'] ?? '');
            $enderDest->UF = strtoupper((string) ($addr['uf'] ?? ''));
            $enderDest->CEP = preg_replace('/\D/', '', (string) ($addr['zipCode'] ?? ''));
            $enderDest->cPais = 1058;
            $enderDest->xPais = 'BRASIL';
            $make->tagenderDest($enderDest);
        }
    }

    /** @return array<string,float> */
    private function buildItems(Make $make, EmitInvoiceRequest $request): array
    {
        $totals = [
            'products' => 0.0,
            'discount' => 0.0,
            'freight' => (float) ($request->freight['value'] ?? 0),
            'invoice' => 0.0,
            'icmsBase' => 0.0,
            'icmsValue' => 0.0,
            'pisValue' => 0.0,
            'cofinsValue' => 0.0,
        ];

        foreach ($request->items as $index => $item) {
            $nItem = (int) ($item['itemNumber'] ?? $index + 1);
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unitPrice'];
            $totalPrice = (float) $item['totalPrice'];
            $discount = (float) ($item['discount'] ?? 0);

            $prod = new stdClass();
            $prod->item = $nItem;
            $prod->cProd = (string) ($item['productCode'] ?? $item['code'] ?? (string) $nItem);
            $prod->cEAN = !empty($item['ean']) ? (string) $item['ean'] : 'SEM GTIN';
            $prod->xProd = mb_substr((string) $item['description'], 0, 120);
            $prod->NCM = preg_replace('/\D/', '', (string) $item['ncm']);
            if (!empty($item['cest'])) {
                $prod->CEST = preg_replace('/\D/', '', (string) $item['cest']);
            }
            $prod->CFOP = preg_replace('/\D/', '', (string) $item['cfop']);
            $prod->uCom = mb_substr((string) $item['unit'], 0, 6);
            $prod->qCom = number_format($quantity, 4, '.', '');
            $prod->vUnCom = number_format($unitPrice, 4, '.', '');
            $prod->vProd = $this->money($totalPrice);
            $prod->cEANTrib = $prod->cEAN;
            $prod->uTrib = $prod->uCom;
            $prod->qTrib = $prod->qCom;
            $prod->vUnTrib = $prod->vUnCom;
            if ($discount > 0) {
                $prod->vDesc = $this->money($discount);
            }
            $prod->indTot = 1;
            $make->tagprod($prod);

            $imposto = new stdClass();
            $imposto->item = $nItem;
            $make->tagimposto($imposto);

            $origin = (int) ($item['origin'] ?? 0);
            $icmsRate = (float) ($item['icmsRate'] ?? 0);

            if (!empty($item['icmsCsosn'])) {
                // Simples Nacional
                $icmsSN = new stdClass();
                $icmsSN->item = $nItem;
                $icmsSN->orig = $origin;
                $icmsSN->CSOSN = (string) $item['icmsCsosn'];
                if ((string) $item['icmsCsosn'] === '900' && $icmsRate > 0) {
                    $icmsSN->modBC = 3;
                    $icmsSN->vBC = $this->money($totalPrice - $discount);
                    $icmsSN->pICMS = number_format($icmsRate, 4, '.', '');
                    $icmsSN->vICMS = $this->money(($totalPrice - $discount) * $icmsRate / 100);
                }
                $make->tagICMSSN($icmsSN);
            } else {
                $icms = new stdClass();
                $icms->item = $nItem;
                $icms->orig = $origin;
                $icms->CST = (string) ($item['icmsCst'] ?? '00');
                if (in_array($icms->CST, ['00', '20'], true)) {
                    $base = $totalPrice - $discount;
                    $icms->modBC = 3;
                    $icms->vBC = $this->money($base);
                    $icms->pICMS = number_format($icmsRate, 4, '.', '');
                    $icms->vICMS = $this->money($base * $icmsRate / 100);
                    $totals['icmsBase'] += $base;
                    $totals['icmsValue'] += $base * $icmsRate / 100;
                }
                $make->tagICMS($icms);
            }

            // PIS
            $pisRate = (float) ($item['pisRate'] ?? 0);
            $pis = new stdClass();
            $pis->item = $nItem;
            $pis->CST = (string) ($item['pisCst'] ?? '49');
            if (in_array($pis->CST, ['01', '02'], true)) {
                $base = $totalPrice - $discount;
                $pis->vBC = $this->money($base);
                $pis->pPIS = number_format($pisRate, 4, '.', '');
                $pis->vPIS = $this->money($base * $pisRate / 100);
                $totals['pisValue'] += $base * $pisRate / 100;
            } else {
                $pis->qBCProd = null;
                $pis->vAliqProd = null;
                $pis->vPIS = null;
            }
            $make->tagPIS($pis);

            // COFINS
            $cofinsRate = (float) ($item['cofinsRate'] ?? 0);
            $cofins = new stdClass();
            $cofins->item = $nItem;
            $cofins->CST = (string) ($item['cofinsCst'] ?? '49');
            if (in_array($cofins->CST, ['01', '02'], true)) {
                $base = $totalPrice - $discount;
                $cofins->vBC = $this->money($base);
                $cofins->pCOFINS = number_format($cofinsRate, 4, '.', '');
                $cofins->vCOFINS = $this->money($base * $cofinsRate / 100);
                $totals['cofinsValue'] += $base * $cofinsRate / 100;
            } else {
                $cofins->qBCProd = null;
                $cofins->vAliqProd = null;
                $cofins->vCOFINS = null;
            }
            $make->tagCOFINS($cofins);

            $totals['products'] += $totalPrice;
            $totals['discount'] += $discount;
        }

        $totals['invoice'] = $totals['products'] - $totals['discount'] + $totals['freight'];

        return $totals;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function ufCode(string $uf): string
    {
        $codes = [
            'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
            'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
            'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
            'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
        ];

        $code = $codes[strtoupper($uf)] ?? null;
        if ($code === null) {
            throw FiscalException::validation("UF inválida: {$uf}");
        }

        return $code;
    }
}
