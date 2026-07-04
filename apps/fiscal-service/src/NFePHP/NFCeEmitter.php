<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\EmitInvoiceRequest;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;

/**
 * Fluxo completo de emissão de NFC-e (modelo 65):
 * monta XML -> assina -> adiciona QRCode/CSC -> envia à SEFAZ -> armazena.
 */
final class NFCeEmitter
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
        if ($request->company->csc === null) {
            throw FiscalException::validation('NFC-e exige CSC/Token configurado');
        }

        $this->logger->info('Emissão NFC-e iniciada', [
            'invoiceId' => $request->invoiceId,
            'companyId' => $request->company->companyId,
            'series' => $request->series,
            'number' => $request->number,
            'environment' => $request->company->environment,
        ]);

        $tools = $this->toolsFactory->create($request->company, '65');

        $built = $this->xmlBuilder->build($request);
        $signedXml = $this->signer->sign($tools, $built['xml']);

        // QRCode + urlChave obrigatórios na NFC-e (usa CSC configurado no Tools)
        $signedXml = $tools->qrCodeNFCe($signedXml);

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
            $xmlPath = $this->storage->storeAuthorizedXml('65', $request->company->environment, $built['accessKey'], $result['protocoledXml']);
            $response['status'] = 'AUTHORIZED';
            $response['xmlPath'] = $xmlPath;
            $response['xmlBase64'] = base64_encode($result['protocoledXml']);

            $this->logger->info('NFC-e autorizada', [
                'invoiceId' => $request->invoiceId,
                'accessKey' => $built['accessKey'],
                'protocol' => $result['protocol'],
            ]);
        } else {
            $response['status'] = 'REJECTED';
            $response['rejectionCode'] = $result['sefazCode'];
            $response['rejectionMessage'] = $result['sefazMessage'];

            $this->logger->warning('NFC-e rejeitada', [
                'invoiceId' => $request->invoiceId,
                'accessKey' => $built['accessKey'],
                'code' => $result['sefazCode'],
                'message' => $result['sefazMessage'],
            ]);
        }

        return $response;
    }
}
