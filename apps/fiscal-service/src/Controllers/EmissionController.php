<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\DTO\EmitInvoiceRequest;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\NFePHP\NFCeEmitter;
use IntegraSys\Fiscal\NFePHP\NFeEmitter;

final class EmissionController
{
    public function __construct(
        private readonly NFeEmitter $nfeEmitter,
        private readonly NFCeEmitter $nfceEmitter,
    ) {
    }

    /** POST /internal/fiscal/nfe/emit */
    public function emitNfe(Request $request): Response
    {
        $payload = EmitInvoiceRequest::fromArray($request->body, '55');

        return Response::json($this->nfeEmitter->emit($payload));
    }

    /** POST /internal/fiscal/nfce/emit */
    public function emitNfce(Request $request): Response
    {
        $payload = EmitInvoiceRequest::fromArray($request->body, '65');

        return Response::json($this->nfceEmitter->emit($payload));
    }
}
