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
 * Consultas à SEFAZ: status do serviço por UF, situação da nota
 * pela chave de acesso e processamento de recibo de lote.
 */
final class InvoiceStatusService
{
    public function __construct(
        private readonly ToolsFactory $toolsFactory,
        private readonly FiscalStorageService $storage,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function serviceStatus(CompanyContext $company, string $uf): array
    {
        $tools = $this->toolsFactory->create($company, '55');
        $startedAt = microtime(true);

        try {
            $response = $tools->sefazStatus(strtoupper($uf));
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao consultar status do serviço: ' . $exception->getMessage());
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $this->storage->storeWebserviceLog('NfeStatusServico', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $code = (string) ($std->cStat ?? '');

        return [
            'success' => true,
            'uf' => strtoupper($uf),
            'environment' => $company->environment,
            'online' => $code === '107',
            'sefazCode' => $code,
            'sefazMessage' => (string) ($std->xMotivo ?? ''),
            'averageResponseTimeMs' => $durationMs,
            'checkedAt' => date(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function queryInvoice(CompanyContext $company, string $accessKey): array
    {
        $tools = $this->toolsFactory->create($company, '55');

        try {
            $response = $tools->sefazConsultaChave($accessKey);
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao consultar nota: ' . $exception->getMessage());
        }

        $this->storage->storeWebserviceLog('NfeConsultaProtocolo', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $code = (string) ($std->cStat ?? '');

        $status = match (true) {
            in_array($code, ['100', '150'], true) => 'AUTHORIZED',
            $code === '101' => 'CANCELED',
            $code === '110', $code === '301', $code === '302', $code === '303' => 'DENIED',
            default => 'REJECTED',
        };

        $events = [];
        if (isset($std->procEventoNFe)) {
            $rawEvents = is_array($std->procEventoNFe) ? $std->procEventoNFe : [$std->procEventoNFe];
            foreach ($rawEvents as $event) {
                $inf = $event->evento->infEvento ?? null;
                $ret = $event->retEvento->infEvento ?? null;
                if ($inf !== null) {
                    $events[] = [
                        'type' => (string) ($inf->tpEvento ?? ''),
                        'sequence' => (int) ($inf->nSeqEvento ?? 1),
                        'protocol' => (string) ($ret->nProt ?? ''),
                        'registeredAt' => (string) ($ret->dhRegEvento ?? ''),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'accessKey' => $accessKey,
            'status' => $status,
            'protocol' => isset($std->protNFe->infProt->nProt) ? (string) $std->protNFe->infProt->nProt : null,
            'sefazCode' => $code,
            'sefazMessage' => (string) ($std->xMotivo ?? ''),
            'events' => $events,
        ];
    }

    /** @return array<string,mixed> */
    public function queryReceipt(CompanyContext $company, string $receiptNumber): array
    {
        $tools = $this->toolsFactory->create($company, '55');

        try {
            $response = $tools->sefazConsultaRecibo($receiptNumber);
        } catch (Throwable $exception) {
            throw FiscalException::sefaz('Falha ao consultar recibo: ' . $exception->getMessage());
        }

        $this->storage->storeWebserviceLog('NfeRetAutorizacao', 'response', $response);

        $std = (new Standardize($response))->toStd();
        $code = (string) ($std->cStat ?? '');

        $prot = null;
        if (isset($std->protNFe)) {
            $prot = is_array($std->protNFe) ? $std->protNFe[0] : $std->protNFe;
        }

        return [
            'success' => true,
            'receiptNumber' => $receiptNumber,
            // 105 = lote em processamento
            'processed' => $code !== '105',
            'sefazCode' => isset($prot->infProt->cStat) ? (string) $prot->infProt->cStat : $code,
            'sefazMessage' => isset($prot->infProt->xMotivo) ? (string) $prot->infProt->xMotivo : (string) ($std->xMotivo ?? ''),
            'protocol' => isset($prot->infProt->nProt) ? (string) $prot->infProt->nProt : null,
            'accessKey' => isset($prot->infProt->chNFe) ? (string) $prot->infProt->chNFe : null,
        ];
    }
}
