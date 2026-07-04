<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;
use Throwable;

/**
 * Envia o XML assinado para a SEFAZ (envio síncrono, lote unitário),
 * trata o retorno e protocola o XML autorizado.
 */
final class InvoiceSender
{
    public function __construct(
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /**
     * @return array{
     *   authorized: bool,
     *   sefazCode: string,
     *   sefazMessage: string,
     *   protocol: ?string,
     *   receiptNumber: ?string,
     *   authorizedAt: ?string,
     *   protocoledXml: ?string
     * }
     */
    public function send(Tools $tools, string $signedXml, string $accessKey): array
    {
        $startedAt = microtime(true);

        try {
            // Envio síncrono (indSinc=1) — recomendado para lotes unitários
            $response = $tools->sefazEnviaLote([$signedXml], (string) time(), 1);
        } catch (Throwable $exception) {
            $this->logger->error('Falha de comunicação com a SEFAZ', ['accessKey' => $accessKey, 'message' => $exception->getMessage()]);
            throw FiscalException::sefaz('Falha de comunicação com a SEFAZ: ' . $exception->getMessage());
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $this->storage->storeWebserviceLog('NfeAutorizacao', 'response', $response);

        $std = (new Standardize($response))->toStd();

        // Lote processado de forma síncrona: protNFe já vem na resposta
        $protStd = null;
        if (isset($std->protNFe)) {
            $protStd = is_array($std->protNFe) ? $std->protNFe[0] : $std->protNFe;
        }

        $loteCode = (string) ($std->cStat ?? '');
        $loteMessage = (string) ($std->xMotivo ?? '');

        if ($protStd === null) {
            // Lote rejeitado antes de processar a nota (ex: 225, 215...)
            return [
                'authorized' => false,
                'sefazCode' => $loteCode,
                'sefazMessage' => $loteMessage,
                'protocol' => null,
                'receiptNumber' => isset($std->infRec->nRec) ? (string) $std->infRec->nRec : null,
                'authorizedAt' => null,
                'protocoledXml' => null,
                'durationMs' => $durationMs,
            ];
        }

        $noteCode = (string) ($protStd->infProt->cStat ?? '');
        $noteMessage = (string) ($protStd->infProt->xMotivo ?? '');
        $protocol = isset($protStd->infProt->nProt) ? (string) $protStd->infProt->nProt : null;
        $authorizedAt = isset($protStd->infProt->dhRecbto) ? (string) $protStd->infProt->dhRecbto : null;

        // 100 = autorizado, 150 = autorizado fora de prazo
        $authorized = in_array($noteCode, ['100', '150'], true);

        $protocoledXml = null;
        if ($authorized) {
            try {
                $protocoledXml = Complements::toAuthorize($signedXml, $response);
            } catch (Throwable $exception) {
                $this->logger->error('Falha ao protocolar XML autorizado', ['accessKey' => $accessKey, 'message' => $exception->getMessage()]);
                throw FiscalException::sefaz('Nota autorizada mas houve falha ao protocolar XML: ' . $exception->getMessage());
            }
        }

        return [
            'authorized' => $authorized,
            'sefazCode' => $noteCode,
            'sefazMessage' => $noteMessage,
            'protocol' => $protocol,
            'receiptNumber' => isset($std->infRec->nRec) ? (string) $std->infRec->nRec : null,
            'authorizedAt' => $authorizedAt,
            'protocoledXml' => $protocoledXml,
            'durationMs' => $durationMs,
        ];
    }
}
