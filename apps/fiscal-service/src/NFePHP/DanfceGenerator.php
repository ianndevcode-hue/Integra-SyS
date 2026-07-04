<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\DA\NFe\Danfce;
use Throwable;

/**
 * Gera o DANFCE (cupom PDF 80mm) da NFC-e modelo 65 a partir do XML
 * autorizado, usando NFePHP/sped-da.
 */
final class DanfceGenerator
{
    public function __construct(
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array{pdfBinary:string, pdfPath:string} */
    public function generate(string $accessKey, string $environment): array
    {
        $xmlPath = $this->storage->findInvoiceXml($accessKey);
        if ($xmlPath === null) {
            throw FiscalException::notFound('XML autorizado não encontrado para a chave ' . $accessKey);
        }

        $xml = $this->storage->readXml($xmlPath);

        try {
            $danfce = new Danfce($xml);
            $danfce->debugMode(false);
            $danfce->creditsIntegratorFooter('Integra SYS - www.integrasys.local');
            $pdfBinary = $danfce->render();
        } catch (Throwable $exception) {
            $this->logger->error('Falha ao gerar DANFCE', ['accessKey' => $accessKey, 'message' => $exception->getMessage()]);
            throw new FiscalException('DANFCE_ERROR', 'Falha ao gerar DANFCE: ' . $exception->getMessage(), 500);
        }

        $pdfPath = $this->storage->storePdf($environment, $accessKey, $pdfBinary);

        return ['pdfBinary' => $pdfBinary, 'pdfPath' => $pdfPath];
    }
}
