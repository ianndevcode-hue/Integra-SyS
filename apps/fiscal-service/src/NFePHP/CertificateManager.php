<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Security\SecretsCipher;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\Common\Certificate;
use Throwable;

/**
 * Gerencia certificados digitais A1 (.pfx/.p12):
 * armazenamento, leitura, validação e extração de metadados.
 */
final class CertificateManager
{
    public function __construct(
        private readonly FiscalStorageService $storage,
        private readonly SecretsCipher $cipher,
        private readonly FiscalLogger $logger,
    ) {
    }

    /**
     * Carrega o objeto Certificate do NFePHP a partir do contexto da empresa.
     * A senha chega cifrada (AES-256-GCM) e é decifrada apenas em memória.
     */
    public function load(CompanyContext $company): Certificate
    {
        $pfxBinary = $this->storage->readCertificate($company->certificatePath);
        $password = $this->cipher->decrypt($company->encryptedCertificatePassword);

        try {
            $certificate = Certificate::readPfx($pfxBinary, $password);
        } catch (Throwable $exception) {
            throw FiscalException::certificate('Falha ao abrir certificado A1: ' . $exception->getMessage());
        }

        if ($certificate->isExpired()) {
            throw FiscalException::certificate(
                'Certificado A1 expirado em ' . $certificate->getValidTo()->format('d/m/Y') . '. Emissão bloqueada.',
            );
        }

        return $certificate;
    }

    /**
     * Recebe upload (.pfx base64 + senha cifrada), valida e armazena.
     *
     * @return array<string,mixed>
     */
    public function storeAndInspect(string $tenantId, string $companyId, string $pfxBase64, string $encryptedPassword): array
    {
        $pfxBinary = base64_decode($pfxBase64, true);
        if ($pfxBinary === false || $pfxBinary === '') {
            throw FiscalException::certificate('Arquivo de certificado inválido (base64)');
        }

        $password = $this->cipher->decrypt($encryptedPassword);

        try {
            $certificate = Certificate::readPfx($pfxBinary, $password);
        } catch (Throwable $exception) {
            throw FiscalException::certificate('Certificado ou senha inválidos: ' . $exception->getMessage());
        }

        $path = $this->storage->storeCertificate($tenantId, $companyId, $pfxBinary);
        $this->logger->info('Certificado A1 armazenado', ['tenantId' => $tenantId, 'companyId' => $companyId, 'path' => $path]);

        return array_merge(['certificatePath' => $path], $this->describe($certificate));
    }

    /** @return array<string,mixed> */
    public function inspect(string $certificatePath, string $encryptedPassword): array
    {
        $pfxBinary = $this->storage->readCertificate($certificatePath);
        $password = $this->cipher->decrypt($encryptedPassword);

        try {
            $certificate = Certificate::readPfx($pfxBinary, $password);
        } catch (Throwable $exception) {
            throw FiscalException::certificate('Certificado ou senha inválidos: ' . $exception->getMessage());
        }

        return $this->describe($certificate);
    }

    public function remove(string $certificatePath): void
    {
        $this->storage->removeCertificate($certificatePath);
        $this->logger->info('Certificado A1 removido', ['path' => $certificatePath]);
    }

    /** @return array<string,mixed> */
    private function describe(Certificate $certificate): array
    {
        $validFrom = $certificate->getValidFrom();
        $validTo = $certificate->getValidTo();
        $now = new \DateTime();
        $daysToExpire = (int) $now->diff($validTo)->format('%r%a');

        $status = 'ACTIVE';
        if ($certificate->isExpired()) {
            $status = 'EXPIRED';
        } elseif ($daysToExpire <= 30) {
            $status = 'EXPIRING';
        }

        return [
            'success' => true,
            'subjectCnpj' => $certificate->getCnpj(),
            'serialNumber' => method_exists($certificate, 'getSerialNumber') ? (string) $certificate->getSerialNumber() : null,
            'validFrom' => $validFrom->format(DATE_ATOM),
            'validUntil' => $validTo->format(DATE_ATOM),
            'daysToExpire' => $daysToExpire,
            'status' => $status,
        ];
    }
}
