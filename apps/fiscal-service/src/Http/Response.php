<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly array $headers,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
