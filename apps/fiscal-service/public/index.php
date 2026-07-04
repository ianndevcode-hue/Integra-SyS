<?php

declare(strict_types=1);

/**
 * Integra SYS - Fiscal Service
 * Front controller: recebe as requisições internas da API Node.js
 * e delega para os controllers fiscais (NFePHP/sped-nfe).
 */

use IntegraSys\Fiscal\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = Bootstrap::create(dirname(__DIR__));
$app->run();
