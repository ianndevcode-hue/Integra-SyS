<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Utils;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Logger fiscal: grava logs rotativos em storage/logs e também em stderr
 * (visível no docker logs). Cada operação fiscal relevante é registrada.
 */
final class FiscalLogger
{
    private readonly Logger $logger;

    public function __construct(string $logDirectory)
    {
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0770, true);
        }

        $formatter = new LineFormatter(null, 'Y-m-d H:i:s', true, true);

        $fileHandler = new RotatingFileHandler($logDirectory . '/fiscal.log', 30, Level::Debug);
        $fileHandler->setFormatter($formatter);

        $stderrHandler = new StreamHandler('php://stderr', Level::Info);
        $stderrHandler->setFormatter($formatter);

        $this->logger = new Logger('fiscal-service');
        $this->logger->pushHandler($fileHandler);
        $this->logger->pushHandler($stderrHandler);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }
}
