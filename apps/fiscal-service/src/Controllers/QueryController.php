<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\DTO\CompanyContext;
use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\Services\InvoiceStatusService;

final class QueryController
{
    public function __construct(private readonly InvoiceStatusService $statusService)
    {
    }

    /** GET|POST /internal/fiscal/status/{uf} */
    public function sefazStatus(Request $request): Response
    {
        $company = $this->companyFromRequest($request);
        $uf = $request->param('uf');

        return Response::json($this->statusService->serviceStatus($company, $uf));
    }

    /** GET|POST /internal/fiscal/invoices/{id}/query */
    public function queryInvoice(Request $request): Response
    {
        $company = $this->companyFromRequest($request);
        $accessKey = (string) ($request->input('accessKey') ?? $request->queryParam('accessKey') ?? '');

        if (strlen(preg_replace('/\D/', '', $accessKey) ?? '') !== 44) {
            throw FiscalException::validation('Chave de acesso inválida (44 dígitos)');
        }

        return Response::json($this->statusService->queryInvoice($company, $accessKey));
    }

    /** GET|POST /internal/fiscal/receipts/{receiptId}/query */
    public function queryReceipt(Request $request): Response
    {
        $company = $this->companyFromRequest($request);
        $receiptId = $request->param('receiptId');

        return Response::json($this->statusService->queryReceipt($company, $receiptId));
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
