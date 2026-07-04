<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\DTO;

use IntegraSys\Fiscal\Exceptions\FiscalException;

/**
 * Payload de emissão validado (NF-e 55 / NFC-e 65).
 */
final class EmitInvoiceRequest
{
    /**
     * @param array<string,mixed>|null $recipient
     * @param array<int,array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $payments
     * @param array<string,mixed>|null $freight
     */
    private function __construct(
        public readonly string $invoiceId,
        public readonly string $model,
        public readonly int $series,
        public readonly int $number,
        public readonly string $operationNature,
        public readonly string $issueDate,
        public readonly CompanyContext $company,
        public readonly ?array $recipient,
        public readonly array $items,
        public readonly array $payments,
        public readonly ?array $freight,
        public readonly ?string $additionalInformation,
        public readonly int $destinationIndicator,
        public readonly int $presenceIndicator,
        public readonly int $operationType,
        public readonly int $emissionType,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $expectedModel): self
    {
        foreach (['invoiceId', 'model', 'series', 'number', 'operationNature', 'issueDate', 'company', 'items', 'payments'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === []) {
                throw FiscalException::validation("Campo obrigatório ausente na emissão: '{$field}'");
            }
        }

        $model = (string) $data['model'];
        if ($model !== $expectedModel) {
            throw FiscalException::validation("Modelo inválido para este endpoint: esperado {$expectedModel}, recebido {$model}");
        }

        $company = CompanyContext::fromArray($data['company']);

        if ($model === '65' && ($company->csc === null || empty($company->csc['id']) || empty($company->csc['encryptedToken']))) {
            throw FiscalException::validation('NFC-e exige CSC/Token configurado para a empresa');
        }

        $items = $data['items'];
        foreach ($items as $index => $item) {
            $position = $index + 1;
            foreach (['description', 'ncm', 'cfop', 'unit', 'quantity', 'unitPrice', 'totalPrice'] as $field) {
                if (!isset($item[$field]) || $item[$field] === '') {
                    throw FiscalException::validation("Item {$position}: campo '{$field}' obrigatório");
                }
            }
            if (empty($item['icmsCst']) && empty($item['icmsCsosn'])) {
                throw FiscalException::validation("Item {$position}: informe CST ou CSOSN do ICMS");
            }
        }

        $payments = $data['payments'];
        foreach ($payments as $index => $payment) {
            if (empty($payment['method']) || !isset($payment['amount'])) {
                throw FiscalException::validation('Pagamento ' . ($index + 1) . ': método e valor são obrigatórios');
            }
        }

        if ($model === '55' && (empty($data['recipient']) || empty($data['recipient']['document']))) {
            throw FiscalException::validation('NF-e (modelo 55) exige destinatário com CPF/CNPJ');
        }

        return new self(
            (string) $data['invoiceId'],
            $model,
            (int) $data['series'],
            (int) $data['number'],
            (string) $data['operationNature'],
            (string) $data['issueDate'],
            $company,
            isset($data['recipient']) && is_array($data['recipient']) ? $data['recipient'] : null,
            $items,
            $payments,
            isset($data['freight']) && is_array($data['freight']) ? $data['freight'] : null,
            isset($data['additionalInformation']) ? (string) $data['additionalInformation'] : null,
            (int) ($data['destinationIndicator'] ?? 1),
            (int) ($data['presenceIndicator'] ?? ($model === '65' ? 1 : 9)),
            (int) ($data['operationType'] ?? 1),
            (int) ($data['emissionType'] ?? 1),
        );
    }
}
