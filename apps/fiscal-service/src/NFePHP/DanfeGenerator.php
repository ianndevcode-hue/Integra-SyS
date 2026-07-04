<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\DA\NFe\Danfe;
use Throwable;

/**
 * Gera o DANFE (PDF A4) da NF-e modelo 55 a partir do XML autorizado,
 * usando NFePHP/sped-da.
 */
final class DanfeGenerator
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
            $danfe = new Danfe($xml);
            $danfe->debugMode(false);
            $danfe->creditsIntegratorFooter('Integra SYS - www.integrasys.local');
            $pdfBinary = $danfe->render();
        } catch (Throwable $exception) {
            $this->logger->error('Falha ao gerar DANFE', ['accessKey' => $accessKey, 'message' => $exception->getMessage()]);
            throw new FiscalException('DANFE_ERROR', 'Falha ao gerar DANFE: ' . $exception->getMessage(), 500);
        }

        $pdfPath = $this->storage->storePdf($environment, $accessKey, $pdfBinary);

        return ['pdfBinary' => $pdfBinary, 'pdfPath' => $pdfPath];
    }
}
