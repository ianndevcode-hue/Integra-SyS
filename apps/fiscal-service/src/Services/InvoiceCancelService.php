<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Services;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\NFePHP\ToolsFactory;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use Throwable;

/**
 * Cancelamento de NF-e/NFC-e autorizada (evento 110111).
 * Exige justificativa com no mínimo 15 caracteres.
 */
final class InvoiceCancelService
{
    private const MIN_JUSTIFICATION = 15;

    public function __construct(
        private readonly ToolsFactory $toolsFactory,
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function cancel(CompanyContext $company, string $accessKey, string $protocol, string $justification): array
    {
        $justification = trim($justification);
        if (mb_strlen($justification) < self::MIN_JUSTIFICATION) {
            throw FiscalException::validation('Cancelamento exige justificativa com no mínimo 15 caracteres');
        }
        if ($protocol === '') {
            throw FiscalException::validation('Protocolo de autorização é obrigatório para cancelar');
        }

        $model = substr($accessKey, 20, 2) === '65' ? '65' : '55';
        $tools = $this->toolsFactory->create($company, $model);

        try {
            $response = $tools->sefazCancela($accessKey, $justification, $protocol);
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao enviar cancelamento: ' . $exception->getMessage());
        }

        $this->storage->storeWebserviceLog('RecepcaoEvento-Cancelamento', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $eventCode = isset($std->retEvento->infEvento->cStat) ? (string) $std->retEvento->infEvento->cStat : (string) ($std->cStat ?? '');
        $eventMessage = isset($std->retEvento->infEvento->xMotivo) ? (string) $std->retEvento->infEvento->xMotivo : (string) ($std->xMotivo ?? '');

        // 135 = evento registrado e vinculado, 155 = registrado fora de prazo
        $accepted = in_array($eventCode, ['135', '155'], true);

        $result = [
            'success' => $accepted,
            'status' => $accepted ? 'CANCELED' : 'REJECTED',
            'sefazCode' => $eventCode,
            'sefazMessage' => $eventMessage,
            'protocol' => isset($std->retEvento->infEvento->nProt) ? (string) $std->retEvento->infEvento->nProt : null,
        ];

        if ($accepted) {
            // Protocola o XML do evento de cancelamento e armazena
            try {
                $originalXmlPath = $this->storage->findInvoiceXml($accessKey);
                $eventXml = $response;
                if ($originalXmlPath !== null) {
                    $eventXml = Complements::toAuthorize($this->lastEventRequest($tools), $response);
                }
                $xmlPath = $this->storage->storeCancellationXml($company->environment, $accessKey, $eventXml);
                $result['xmlPath'] = $xmlPath;
                $result['xmlBase64'] = base64_encode($eventXml);
            } catch (Throwable $exception) {
                $this->logger->warning('Cancelamento aceito, mas houve falha ao protocolar XML do evento', [
                    'accessKey' => $accessKey,
                    'message' => $exception->getMessage(),
                ]);
                $xmlPath = $this->storage->storeCancellationXml($company->environment, $accessKey, $response);
                $result['xmlPath'] = $xmlPath;
                $result['xmlBase64'] = base64_encode($response);
            }

            $this->logger->info('Nota cancelada', ['accessKey' => $accessKey, 'protocol' => $result['protocol']]);
        } else {
            $this->logger->warning('Cancelamento rejeitado', [
                'accessKey' => $accessKey,
                'code' => $eventCode,
                'message' => $eventMessage,
            ]);
        }

        return $result;
    }

    private function lastEventRequest(\NFePHP\NFe\Tools $tools): string
    {
        return $tools->lastRequest ?? '';
    }
}
