<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Http;

use IntegraSys\Fiscal\Exceptions\FiscalException;
use IntegraSys\Fiscal\Security\InternalKeyGuard;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use Throwable;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:callable}> */
    private array $routes = [];

    public function __construct(
        private readonly InternalKeyGuard $guard,
        private readonly FiscalLogger $logger,
    ) {
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        // Todos os endpoints internos exigem X-INTEGRA-INTERNAL-KEY,
        // exceto o healthcheck usado pelo orquestrador.
        if ($request->path !== '/internal/fiscal/health' && !$this->guard->allows($request)) {
            $this->logger->warning('Requisição rejeitada: chave interna inválida', ['path' => $request->path]);

            return Response::json([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Chave interna inválida ou ausente (X-INTEGRA-INTERNAL-KEY)'],
            ], 401);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $matches)) {
                $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $startedAt = microtime(true);
                try {
                    $response = ($route['handler'])($request);
                    $this->logger->info('Requisição processada', [
                        'method' => $request->method,
                        'path' => $request->path,
                        'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                    ]);

                    return $response;
                } catch (FiscalException $exception) {
                    $this->logger->error('Erro fiscal', [
                        'path' => $request->path,
                        'code' => $exception->code(),
                        'message' => $exception->getMessage(),
                    ]);

                    return Response::json([
                        'success' => false,
                        'error' => [
                            'code' => $exception->code(),
                            'message' => $exception->getMessage(),
                            'details' => $exception->details(),
                        ],
                    ], $exception->httpStatus());
                } catch (Throwable $exception) {
                    $this->logger->error('Erro inesperado', [
                        'path' => $request->path,
                        'message' => $exception->getMessage(),
                        'trace' => $exception->getTraceAsString(),
                    ]);

                    return Response::json([
                        'success' => false,
                        'error' => ['code' => 'INTERNAL_ERROR', 'message' => $exception->getMessage()],
                    ], 500);
                }
            }
        }

        return Response::json([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Rota não encontrada: ' . $request->method . ' ' . $request->path],
        ], 404);
    }
}
