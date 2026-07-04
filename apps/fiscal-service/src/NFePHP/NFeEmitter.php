<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\EmitInvoiceRequest;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;

/**
 * Fluxo completo de emissão de NF-e (modelo 55):
 * monta XML -> assina com A1 -> valida schema -> envia à SEFAZ -> armazena.
 */
final class NFeEmitter
{
    public function __construct(
        private readonly ToolsFactory $toolsFactory,
        private readonly InvoiceXmlBuilder $xmlBuilder,
        private readonly InvoiceSigner $signer,
        private readonly InvoiceSender $sender,
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function emit(EmitInvoiceRequest $request): array
    {
        $this->logger->info('Emissão NF-e iniciada', [
            'invoiceId' => $request->invoiceId,
            'companyId' => $request->company->companyId,
            'series' => $request->series,
            'number' => $request->number,
            'environment' => $request->company->environment,
        ]);

        $tools = $this->toolsFactory->create($request->company, '55');

        $built = $this->xmlBuilder->build($request);
        $signedXml = $this->signer->sign($tools, $built['xml']);
        $result = $this->sender->send($tools, $signedXml, $built['accessKey']);

        $response = [
            'success' => $result['authorized'],
            'invoiceId' => $request->invoiceId,
            'accessKey' => $built['accessKey'],
            'sefazCode' => $result['sefazCode'],
            'sefazMessage' => $result['sefazMessage'],
            'protocol' => $result['protocol'],
            'receiptNumber' => $result['receiptNumber'],
            'authorizedAt' => $result['authorizedAt'],
            'durationMs' => $result['durationMs'],
        ];

        if ($result['authorized'] && $result['protocoledXml'] !== null) {
            $xmlPath = $this->storage->storeAuthorizedXml('55', $request->company->environment, $built['accessKey'], $result['protocoledXml']);
            $response['status'] = 'AUTHORIZED';
            $response['xmlPath'] = $xmlPath;
            $response['xmlBase64'] = base64_encode($result['protocoledXml']);

            $this->logger->info('NF-e autorizada', [
                'invoiceId' => $request->invoiceId,
                'accessKey' => $built['accessKey'],
                'protocol' => $result['protocol'],
            ]);
        } else {
            $response['status'] = 'REJECTED';
            $response['rejectionCode'] = $result['sefazCode'];
            $response['rejectionMessage'] = $result['sefazMessage'];

            $this->logger->warning('NF-e rejeitada', [
                'invoiceId' => $request->invoiceId,
                'accessKey' => $built['accessKey'],
                'code' => $result['sefazCode'],
                'message' => $result['sefazMessage'],
            ]);
        }

        return $response;
    }
}
