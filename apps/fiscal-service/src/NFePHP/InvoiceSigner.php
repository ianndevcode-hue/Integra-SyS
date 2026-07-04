<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\NFePHP;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Tools;
use Throwable;

/**
 * Assina o XML com o certificado A1 e valida contra o schema oficial
 * (a validação de schema é executada internamente pelo Tools::signNFe).
 */
final class InvoiceSigner
{
    public function __construct(private readonly FiscalLogger $logger)
    {
    }

    public function sign(Tools $tools, string $xml): string
    {
        try {
            $signed = $tools->signNFe($xml);
        } catch (Throwable $exception) {
            $this->logger->error('Falha na assinatura/validação do XML', ['message' => $exception->getMessage()]);
            throw FiscalException::validation('Falha ao assinar/validar XML: ' . $exception->getMessage());
        }

        return $signed;
    }

    /** Converte resposta SOAP em objeto padrão do NFePHP. */
    public static function standardize(string $response): object
    {
        return (new Standardize($response))->toStd();
    }
}
