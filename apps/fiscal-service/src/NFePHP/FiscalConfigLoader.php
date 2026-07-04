<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Security\SecretsCipher;

/**
 * Monta o JSON de configuração exigido pelo NFePHP\NFe\Tools
 * a partir do contexto da empresa enviado pela API principal.
 */
final class FiscalConfigLoader
{
    public function __construct(
        private readonly string $defaultEnvironment,
        private readonly SecretsCipher $cipher,
    ) {
    }

    public function buildConfigJson(CompanyContext $company): string
    {
        $issuer = $company->issuer;

        $config = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => $company->tpAmb(),
            'razaosocial' => (string) $issuer['corporateName'],
            'cnpj' => preg_replace('/\D/', '', (string) $issuer['cnpj']),
            'ie' => preg_replace('/[^0-9A-Za-z]/', '', (string) ($issuer['stateRegistration'] ?? '')),
            'siglaUF' => $company->uf,
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
            'tokenIBPT' => '',
            'CSC' => '',
            'CSCid' => '',
            'proxyConf' => [
                'proxyIp' => '',
                'proxyPort' => '',
                'proxyUser' => '',
                'proxyPass' => '',
            ],
        ];

        if ($company->csc !== null && !empty($company->csc['encryptedToken'])) {
            $config['CSC'] = $this->cipher->decrypt((string) $company->csc['encryptedToken']);
            $config['CSCid'] = (string) ($company->csc['id'] ?? '');
        }

        return json_encode($config, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function defaultEnvironment(): string
    {
        return $this->defaultEnvironment;
    }
}
