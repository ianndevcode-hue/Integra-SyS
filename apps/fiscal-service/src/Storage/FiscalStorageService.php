<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Storage;

use IntegraSys\Fiscal\Exceptions\FiscalException;

/**
 * Armazenamento seguro de artefatos fiscais.
 *
 * Estrutura:
 *   storage/certificates/{tenantId}/{companyId}/certificado.pfx
 *   storage/xml/nfe/{ambiente}/{chave}.xml
 *   storage/xml/nfce/{ambiente}/{chave}.xml
 *   storage/xml/cancelamentos/{ambiente}/{chave}-canc.xml
 *   storage/xml/inutilizacoes/{ambiente}/{id}.xml
 *   storage/xml/cce/{ambiente}/{chave}-cce-{seq}.xml
 *   storage/danfe/{ambiente}/{chave}.pdf
 *
 * XML autorizado é imutável: nunca é sobrescrito.
 */
final class FiscalStorageService
{
    public function __construct(private readonly string $basePath)
    {
        foreach ([
            'certificates',
            'xml/nfe',
            'xml/nfce',
            'xml/cancelamentos',
            'xml/inutilizacoes',
            'xml/cce',
            'danfe',
            'logs',
        ] as $dir) {
            $full = $this->basePath . '/' . $dir;
            if (!is_dir($full)) {
                mkdir($full, 0770, true);
            }
        }
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    // ------------------------------------------------------------------
    // Certificados
    // ------------------------------------------------------------------

    public function storeCertificate(string $tenantId, string $companyId, string $pfxBinary): string
    {
        $dir = sprintf('%s/certificates/%s/%s', $this->basePath, $this->sanitize($tenantId), $this->sanitize($companyId));
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        $relative = sprintf('certificates/%s/%s/certificado-%s.pfx', $this->sanitize($tenantId), $this->sanitize($companyId), date('YmdHis'));
        $this->writeFile($relative, $pfxBinary);
        chmod($this->basePath . '/' . $relative, 0640);

        return $relative;
    }

    public function readCertificate(string $relativePath): string
    {
        return $this->readFile($relativePath);
    }

    public function removeCertificate(string $relativePath): void
    {
        $full = $this->resolve($relativePath);
        if (is_file($full)) {
            unlink($full);
        }
    }

    // ------------------------------------------------------------------
    // XMLs
    // ------------------------------------------------------------------

    public function storeAuthorizedXml(string $model, string $environment, string $accessKey, string $xml): string
    {
        $folder = $model === '65' ? 'nfce' : 'nfe';
        $relative = sprintf('xml/%s/%s/%s.xml', $folder, $this->envFolder($environment), $this->sanitize($accessKey));

        // Imutabilidade: XML autorizado não pode ser alterado.
        if (is_file($this->resolve($relative))) {
            return $relative;
        }

        $this->writeFile($relative, $xml);

        return $relative;
    }

    public function storeCancellationXml(string $environment, string $accessKey, string $xml): string
    {
        $relative = sprintf('xml/cancelamentos/%s/%s-canc-%s.xml', $this->envFolder($environment), $this->sanitize($accessKey), date('YmdHis'));
        $this->writeFile($relative, $xml);

        return $relative;
    }

    public function storeInutilizationXml(string $environment, string $identifier, string $xml): string
    {
        $relative = sprintf('xml/inutilizacoes/%s/%s.xml', $this->envFolder($environment), $this->sanitize($identifier));
        $this->writeFile($relative, $xml);

        return $relative;
    }

    public function storeCorrectionLetterXml(string $environment, string $accessKey, int $sequence, string $xml): string
    {
        $relative = sprintf('xml/cce/%s/%s-cce-%03d.xml', $this->envFolder($environment), $this->sanitize($accessKey), $sequence);
        $this->writeFile($relative, $xml);

        return $relative;
    }

    public function findInvoiceXml(string $accessKey): ?string
    {
        $key = $this->sanitize($accessKey);
        foreach (['nfe', 'nfce'] as $folder) {
            foreach (['homologacao', 'producao'] as $env) {
                $relative = sprintf('xml/%s/%s/%s.xml', $folder, $env, $key);
                if (is_file($this->resolve($relative))) {
                    return $relative;
                }
            }
        }

        return null;
    }

    public function readXml(string $relativePath): string
    {
        return $this->readFile($relativePath);
    }

    // ------------------------------------------------------------------
    // DANFE / DANFCE
    // ------------------------------------------------------------------

    public function storePdf(string $environment, string $accessKey, string $pdfBinary): string
    {
        $relative = sprintf('danfe/%s/%s.pdf', $this->envFolder($environment), $this->sanitize($accessKey));
        $this->writeFile($relative, $pdfBinary);

        return $relative;
    }

    public function findPdf(string $accessKey): ?string
    {
        $key = $this->sanitize($accessKey);
        foreach (['homologacao', 'producao'] as $env) {
            $relative = sprintf('danfe/%s/%s.pdf', $env, $key);
            if (is_file($this->resolve($relative))) {
                return $relative;
            }
        }

        return null;
    }

    public function readBinary(string $relativePath): string
    {
        return $this->readFile($relativePath);
    }

    // ------------------------------------------------------------------
    // Logs de webservice (request/response SOAP)
    // ------------------------------------------------------------------

    public function storeWebserviceLog(string $service, string $direction, string $content): string
    {
        $relative = sprintf('logs/ws/%s/%s-%s-%s.xml', date('Y-m-d'), $service, $direction, uniqid('', true));
        $this->writeFile($relative, $content);

        return $relative;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function envFolder(string $environment): string
    {
        return strtolower($environment) === 'production' ? 'producao' : 'homologacao';
    }

    private function sanitize(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', $value) ?? '';
        if ($clean === '') {
            throw FiscalException::validation('Identificador inválido para armazenamento');
        }

        return $clean;
    }

    private function resolve(string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw FiscalException::validation('Caminho de arquivo inválido');
        }

        return $this->basePath . '/' . ltrim($relativePath, '/');
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $full = $this->resolve($relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        if (file_put_contents($full, $content) === false) {
            throw new FiscalException('STORAGE_ERROR', 'Falha ao gravar arquivo: ' . $relativePath, 500);
        }
    }

    private function readFile(string $relativePath): string
    {
        $full = $this->resolve($relativePath);
        if (!is_file($full)) {
            throw FiscalException::notFound('Arquivo não encontrado: ' . $relativePath);
        }

        $content = file_get_contents($full);
        if ($content === false) {
            throw new FiscalException('STORAGE_ERROR', 'Falha ao ler arquivo: ' . $relativePath, 500);
        }

        return $content;
    }
}
