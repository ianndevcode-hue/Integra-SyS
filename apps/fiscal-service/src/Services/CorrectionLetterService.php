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
 * Carta de Correção Eletrônica - CC-e (evento 110110).
 * Disponível apenas para NF-e (modelo 55).
 */
final class CorrectionLetterService
{
    private const MIN_TEXT = 15;
    private const MAX_TEXT = 1000;

    public function __construct(
        private readonly ToolsFactory $toolsFactory,
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function register(CompanyContext $company, string $accessKey, string $correctionText, int $sequence): array
    {
        $correctionText = trim($correctionText);
        $length = mb_strlen($correctionText);
        if ($length < self::MIN_TEXT || $length > self::MAX_TEXT) {
            throw FiscalException::validation('Carta de correção exige texto entre 15 e 1000 caracteres');
        }

        if (substr($accessKey, 20, 2) === '65') {
            throw FiscalException::validation('Carta de correção não é permitida para NFC-e (modelo 65)');
        }

        $tools = $this->toolsFactory->create($company, '55');

        try {
            $response = $tools->sefazCCe($accessKey, $correctionText, $sequence);
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao enviar carta de correção: ' . $exception->getMessage());
        }

        $this->storage->storeWebserviceLog('RecepcaoEvento-CCe', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $code = isset($std->retEvento->infEvento->cStat) ? (string) $std->retEvento->infEvento->cStat : (string) ($std->cStat ?? '');
        $message = isset($std->retEvento->infEvento->xMotivo) ? (string) $std->retEvento->infEvento->xMotivo : (string) ($std->xMotivo ?? '');

        $accepted = in_array($code, ['135', '136'], true);

        $result = [
            'success' => $accepted,
            'sequence' => $sequence,
            'sefazCode' => $code,
            'sefazMessage' => $message,
            'protocol' => isset($std->retEvento->infEvento->nProt) ? (string) $std->retEvento->infEvento->nProt : null,
        ];

        if ($accepted) {
            $xmlPath = $this->storage->storeCorrectionLetterXml($company->environment, $accessKey, $sequence, $response);
            $result['xmlPath'] = $xmlPath;
            $result['xmlBase64'] = base64_encode($response);

            $this->logger->info('Carta de correção registrada', ['accessKey' => $accessKey, 'sequence' => $sequence]);
        } else {
            $this->logger->warning('Carta de correção rejeitada', ['accessKey' => $accessKey, 'code' => $code, 'message' => $message]);
        }

        return $result;
    }
}
