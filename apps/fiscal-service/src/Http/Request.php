<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Http;

final class Request
{
    /** @param array<string,string> $headers @param array<string,mixed> $body @param array<string,string> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $body,
        public readonly array $query,
        /** @var array<string,string> parâmetros de rota ({id}, {uf}...) */
        public array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        $body = [];
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $query = [];
        parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $query);

        return new self($method, $path, $headers, $body, $query);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    /** @return mixed */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        return $value === null ? null : (string) $value;
    }
}
