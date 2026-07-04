<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\DTO;

use IntegraSys\Fiscal\Exceptions\FiscalException;

/**
 * Contexto fiscal da empresa/tenant enviado pela API Node em cada chamada.
 * O fiscal-service é stateless em relação ao banco: tudo o que precisa
 * (emitente, certificado, CSC, ambiente) chega neste contexto.
 */
final class CompanyContext
{
    /** @param array<string,mixed> $issuer @param array<string,mixed>|null $csc */
    private function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $branchId,
        public readonly string $environment,
        public readonly string $uf,
        public readonly string $certificatePath,
        public readonly string $encryptedCertificatePassword,
        public readonly array $issuer,
        public readonly ?array $csc,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['tenantId', 'companyId', 'environment', 'uf', 'certificate', 'issuer'] as $field) {
            if (empty($data[$field])) {
                throw FiscalException::validation("Contexto da empresa incompleto: campo '{$field}' obrigatório");
            }
        }

        $certificate = $data['certificate'];
        if (empty($certificate['path']) || empty($certificate['encryptedPassword'])) {
            throw FiscalException::validation('Certificado A1 não configurado para a empresa (path/senha ausentes)');
        }

        $issuer = $data['issuer'];
        foreach (['cnpj', 'corporateName', 'crt', 'address'] as $field) {
            if (empty($issuer[$field])) {
                throw FiscalException::validation("Emitente incompleto: campo '{$field}' obrigatório");
            }
        }

        $environment = strtolower((string) $data['environment']);
        if (!in_array($environment, ['homologation', 'production'], true)) {
            throw FiscalException::validation("Ambiente inválido: '{$environment}' (use homologation ou production)");
        }

        return new self(
            (string) $data['tenantId'],
            (string) $data['companyId'],
            isset($data['branchId']) ? (string) $data['branchId'] : null,
            $environment,
            strtoupper((string) $data['uf']),
            (string) $certificate['path'],
            (string) $certificate['encryptedPassword'],
            $issuer,
            isset($data['csc']) && is_array($data['csc']) ? $data['csc'] : null,
        );
    }

    /** tpAmb: 1 = produção, 2 = homologação */
    public function tpAmb(): int
    {
        return $this->environment === 'production' ? 1 : 2;
    }
}
