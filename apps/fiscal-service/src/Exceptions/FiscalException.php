<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Exceptions;

use RuntimeException;

class FiscalException extends RuntimeException
{
    /** @param array<string,mixed>|null $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 422,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message, ?array $details = null): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $details);
    }

    public static function certificate(string $message): self
    {
        return new self('CERTIFICATE_ERROR', $message, 422);
    }

    public static function sefaz(string $message, ?array $details = null): self
    {
        return new self('SEFAZ_ERROR', $message, 502, $details);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }
}
