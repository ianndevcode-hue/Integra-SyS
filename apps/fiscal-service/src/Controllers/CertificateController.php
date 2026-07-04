<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\NFePHP\CertificateManager;

final class CertificateController
{
    public function __construct(private readonly CertificateManager $certificateManager)
    {
    }

    /** POST /internal/fiscal/certificates/upload */
    public function upload(Request $request): Response
    {
        foreach (['tenantId', 'companyId', 'fileBase64', 'encryptedPassword'] as $field) {
            if (empty($request->input($field))) {
                throw FiscalException::validation("Campo '{$field}' obrigatório no upload de certificado");
            }
        }

        $result = $this->certificateManager->storeAndInspect(
            (string) $request->input('tenantId'),
            (string) $request->input('companyId'),
            (string) $request->input('fileBase64'),
            (string) $request->input('encryptedPassword'),
        );

        return Response::json($result);
    }

    /** POST /internal/fiscal/certificates/test */
    public function test(Request $request): Response
    {
        foreach (['certificatePath', 'encryptedPassword'] as $field) {
            if (empty($request->input($field))) {
                throw FiscalException::validation("Campo '{$field}' obrigatório no teste de certificado");
            }
        }

        $result = $this->certificateManager->inspect(
            (string) $request->input('certificatePath'),
            (string) $request->input('encryptedPassword'),
        );

        return Response::json($result);
    }

    /** POST /internal/fiscal/certificates/remove */
    public function remove(Request $request): Response
    {
        $path = (string) $request->input('certificatePath', '');
        if ($path === '') {
            throw FiscalException::validation("Campo 'certificatePath' obrigatório");
        }

        $this->certificateManager->remove($path);

        return Response::json(['success' => true]);
    }
}
