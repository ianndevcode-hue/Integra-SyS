<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Services;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\NFePHP\ToolsFactory;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\NFe\Common\Standardize;
use Throwable;

/**
 * Inutilização de faixa de numeração (NF-e/NFC-e).
 * Exige justificativa com no mínimo 15 caracteres.
 */
final class InvoiceInutilizationService
{
    private const MIN_JUSTIFICATION = 15;

    public function __construct(
        private readonly ToolsFactory $toolsFactory,
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function inutilize(
        CompanyContext $company,
        string $model,
        int $series,
        int $startNumber,
        int $endNumber,
        string $justification,
    ): array {
        $justification = trim($justification);
        if (mb_strlen($justification) < self::MIN_JUSTIFICATION) {
            throw FiscalException::validation('Inutilização exige justificativa com no mínimo 15 caracteres');
        }
        if ($endNumber < $startNumber) {
            throw FiscalException::validation('Número final deve ser maior ou igual ao inicial');
        }

        $tools = $this->toolsFactory->create($company, $model === '65' ? '65' : '55');

        try {
            $response = $tools->sefazInutiliza($series, $startNumber, $endNumber, $justification);
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao enviar inutilização: ' . $exception->getMessage());
        }

        $this->storage->storeWebserviceLog('NfeInutilizacao', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $code = isset($std->infInut->cStat) ? (string) $std->infInut->cStat : (string) ($std->cStat ?? '');
        $message = isset($std->infInut->xMotivo) ? (string) $std->infInut->xMotivo : (string) ($std->xMotivo ?? '');

        // 102 = inutilização homologada
        $accepted = $code === '102';

        $result = [
            'success' => $accepted,
            'sefazCode' => $code,
            'sefazMessage' => $message,
            'protocol' => isset($std->infInut->nProt) ? (string) $std->infInut->nProt : null,
        ];

        $identifier = sprintf('inut-%s-serie%d-%d-a-%d-%s', $model, $series, $startNumber, $endNumber, date('YmdHis'));
        $xmlPath = $this->storage->storeInutilizationXml($company->environment, $identifier, $response);
        $result['xmlPath'] = $xmlPath;
        $result['xmlBase64'] = base64_encode($response);

        if ($accepted) {
            $this->logger->info('Numeração inutilizada', [
                'companyId' => $company->companyId,
                'model' => $model,
                'series' => $series,
                'range' => "{$startNumber}-{$endNumber}",
            ]);
        } else {
            $this->logger->warning('Inutilização rejeitada', ['code' => $code, 'message' => $message]);
        }

        return $result;
    }
}
