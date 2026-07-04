<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\NFePHP\DanfceGenerator;
use IntegraSys\Fiscal\NFePHP\DanfeGenerator;
use IntegraSys\Fiscal\Storage\FiscalStorageService;

final class FileController
{
    public function __construct(
        private readonly FiscalStorageService $storage,
        private readonly DanfeGenerator $danfeGenerator,
        private readonly DanfceGenerator $danfceGenerator,
    ) {
    }

    /** GET /internal/fiscal/invoices/{id}/xml?accessKey=... */
    public function downloadXml(Request $request): Response
    {
        $accessKey = $this->accessKey($request);

        $xmlPath = $this->storage->findInvoiceXml($accessKey);
        if ($xmlPath === null) {
            throw FiscalException::notFound('XML não encontrado para a chave ' . $accessKey);
        }

        $xml = $this->storage->readXml($xmlPath);

        return Response::json([
            'success' => true,
            'fileName' => $accessKey . '.xml',
            'xmlBase64' => base64_encode($xml),
        ]);
    }

    /** GET /internal/fiscal/invoices/{id}/danfe?accessKey=... */
    public function danfe(Request $request): Response
    {
        $accessKey = $this->accessKey($request);
        if (substr($accessKey, 20, 2) !== '55') {
            throw FiscalException::validation('DANFE é exclusivo do modelo 55 (NF-e). Use /danfce para NFC-e.');
        }

        $environment = $request->queryParam('environment') ?? 'homologation';
        $generated = $this->danfeGenerator->generate($accessKey, $environment);

        return Response::json([
            'success' => true,
            'fileName' => $accessKey . '-danfe.pdf',
            'pdfBase64' => base64_encode($generated['pdfBinary']),
        ]);
    }

    /** GET /internal/fiscal/invoices/{id}/danfce?accessKey=... */
    public function danfce(Request $request): Response
    {
        $accessKey = $this->accessKey($request);
        if (substr($accessKey, 20, 2) !== '65') {
            throw FiscalException::validation('DANFCE é exclusivo do modelo 65 (NFC-e). Use /danfe para NF-e.');
        }

        $environment = $request->queryParam('environment') ?? 'homologation';
        $generated = $this->danfceGenerator->generate($accessKey, $environment);

        return Response::json([
            'success' => true,
            'fileName' => $accessKey . '-danfce.pdf',
            'pdfBase64' => base64_encode($generated['pdfBinary']),
        ]);
    }

    private function accessKey(Request $request): string
    {
        $accessKey = preg_replace('/\D/', '', (string) ($request->queryParam('accessKey') ?? '')) ?? '';
        if (strlen($accessKey) !== 44) {
            throw FiscalException::validation('Parâmetro accessKey inválido (44 dígitos)');
        }

        return $accessKey;
    }
}
