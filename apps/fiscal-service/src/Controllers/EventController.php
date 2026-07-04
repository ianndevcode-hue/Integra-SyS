<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\Services\CorrectionLetterService;
use IntegraSys\Fiscal\Services\InvoiceCancelService;
use IntegraSys\Fiscal\Services\InvoiceInutilizationService;

final class EventController
{
    public function __construct(
        private readonly InvoiceCancelService $cancelService,
        private readonly InvoiceInutilizationService $inutilizationService,
        private readonly CorrectionLetterService $correctionService,
    ) {
    }

    /** POST /internal/fiscal/invoices/{id}/cancel */
    public function cancel(Request $request): Response
    {
        $company = $this->companyFromRequest($request);
        $accessKey = (string) $request->input('accessKey', '');
        $protocol = (string) $request->input('protocol', '');
        $justification = (string) $request->input('justification', '');

        if (strlen(preg_replace('/\D/', '', $accessKey) ?? '') !== 44) {
            throw FiscalException::validation('Chave de acesso inválida (44 dígitos)');
        }

        return Response::json($this->cancelService->cancel($company, $accessKey, $protocol, $justification));
    }

    /** POST /internal/fiscal/invoices/inutilize */
    public function inutilize(Request $request): Response
    {
        $company = $this->companyFromRequest($request);

        return Response::json($this->inutilizationService->inutilize(
            $company,
            (string) $request->input('model', '55'),
            (int) $request->input('series', 1),
            (int) $request->input('startNumber', 0),
            (int) $request->input('endNumber', 0),
            (string) $request->input('justification', ''),
        ));
    }

    /** POST /internal/fiscal/invoices/{id}/correction-letter */
    public function correctionLetter(Request $request): Response
    {
        $company = $this->companyFromRequest($request);
        $accessKey = (string) $request->input('accessKey', '');

        if (strlen(preg_replace('/\D/', '', $accessKey) ?? '') !== 44) {
            throw FiscalException::validation('Chave de acesso inválida (44 dígitos)');
        }

        return Response::json($this->correctionService->register(
            $company,
            $accessKey,
            (string) $request->input('correctionText', ''),
            max(1, (int) $request->input('sequence', 1)),
        ));
    }

    private function companyFromRequest(Request $request): CompanyContext
    {
        $company = $request->input('company');
        if (!is_array($company)) {
            throw FiscalException::validation("Campo 'company' (contexto fiscal) é obrigatório");
        }

        return CompanyContext::fromArray($company);
    }
}
