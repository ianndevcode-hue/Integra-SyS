<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Security;

use IntegraSys\Fiscal\Http\Request;

/**
 * Autenticação interna: toda requisição da API Node deve trazer o
 * header X-INTEGRA-INTERNAL-KEY com o valor definido em FISCAL_INTERNAL_KEY.
 */
final class InternalKeyGuard
{
    public function __construct(private readonly string $internalKey)
    {
    }

    public function allows(Request $request): bool
    {
        if ($this->internalKey === '') {
            // Chave não configurada: nega tudo em vez de liberar tudo.
            return false;
        }

        $provided = $request->header('x-integra-internal-key') ?? '';

        return $provided !== '' && hash_equals($this->internalKey, $provided);
    }
}
