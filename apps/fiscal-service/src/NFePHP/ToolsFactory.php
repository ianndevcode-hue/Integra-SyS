<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\NFe\Tools;

/**
 * Cria instâncias de NFePHP\NFe\Tools já configuradas com o
 * certificado A1 e o contexto (ambiente/UF/CSC) da empresa.
 */
final class ToolsFactory
{
    public function __construct(
        private readonly FiscalConfigLoader $configLoader,
        private readonly CertificateManager $certificateManager,
        private readonly FiscalLogger $logger,
    ) {
    }

    /** @param '55'|'65' $model */
    public function create(CompanyContext $company, string $model = '55'): Tools
    {
        $configJson = $this->configLoader->buildConfigJson($company);
        $certificate = $this->certificateManager->load($company);

        $tools = new Tools($configJson, $certificate);
        $tools->model($model);
        $tools->setEnvironment($company->tpAmb());

        $this->logger->debug('Tools NFePHP criado', [
            'companyId' => $company->companyId,
            'model' => $model,
            'uf' => $company->uf,
            'tpAmb' => $company->tpAmb(),
        ]);

        return $tools;
    }
}
