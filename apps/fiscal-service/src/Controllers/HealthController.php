<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Controllers;

use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;

final class HealthController
{
    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'integra-fiscal-service',
            'time' => date(DATE_ATOM),
        ]);
    }
}
