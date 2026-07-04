<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal;

use Dotenv\Dotenv;
use IntegraSys\Fiscal\Controllers\CertificateController;
use IntegraSys\Fiscal\Controllers\EmissionController;
use IntegraSys\Fiscal\Controllers\EventController;
use IntegraSys\Fiscal\Controllers\FileController;
use IntegraSys\Fiscal\Controllers\HealthController;
use IntegraSys\Fiscal\Controllers\QueryController;
use IntegraSys\Fiscal\Http\Request;
use IntegraSys\Fiscal\Http\Response;
use IntegraSys\Fiscal\Http\Router;
use IntegraSys\Fiscal\NFePHP\CertificateManager;
use IntegraSys\Fiscal\NFePHP\DanfceGenerator;
use IntegraSys\Fiscal\NFePHP\DanfeGenerator;
use IntegraSys\Fiscal\NFePHP\FiscalConfigLoader;
use IntegraSys\Fiscal\NFePHP\InvoiceSender;
use IntegraSys\Fiscal\NFePHP\InvoiceSigner;
use IntegraSys\Fiscal\NFePHP\InvoiceXmlBuilder;
use IntegraSys\Fiscal\NFePHP\NFCeEmitter;
use IntegraSys\Fiscal\NFePHP\NFeEmitter;
use IntegraSys\Fiscal\NFePHP\ToolsFactory;
use IntegraSys\Fiscal\Security\InternalKeyGuard;
use IntegraSys\Fiscal\Security\SecretsCipher;
use IntegraSys\Fiscal\Services\CorrectionLetterService;
use IntegraSys\Fiscal\Services\InvoiceCancelService;
use IntegraSys\Fiscal\Services\InvoiceInutilizationService;
use IntegraSys\Fiscal\Services\InvoiceStatusService;
use IntegraSys\Fiscal\Storage\FiscalStorageService;
use IntegraSys\Fiscal\Utils\FiscalLogger;
use Throwable;

final class Bootstrap
{
    private function __construct(private readonly Router $router)
    {
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    public static function create(string $basePath): self
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        $storagePath = self::env('FISCAL_STORAGE_PATH', $basePath . '/storage');
        if (!str_starts_with($storagePath, '/')) {
            $storagePath = $basePath . '/' . ltrim($storagePath, './');
        }

        $logger = new FiscalLogger($storagePath . '/logs');
        $storage = new FiscalStorageService($storagePath);
        $cipher = new SecretsCipher(self::env('FISCAL_ENCRYPTION_KEY'));
        $certificateManager = new CertificateManager($storage, $cipher, $logger);
        $configLoader = new FiscalConfigLoader(self::env('FISCAL_DEFAULT_ENVIRONMENT', 'homologation'), $cipher);
        $toolsFactory = new ToolsFactory($configLoader, $certificateManager, $logger);

        $xmlBuilder = new InvoiceXmlBuilder();
        $signer = new InvoiceSigner($logger);
        $sender = new InvoiceSender($storage, $logger);

        $nfeEmitter = new NFeEmitter($toolsFactory, $xmlBuilder, $signer, $sender, $storage, $logger);
        $nfceEmitter = new NFCeEmitter($toolsFactory, $xmlBuilder, $signer, $sender, $storage, $logger);

        $statusService = new InvoiceStatusService($toolsFactory, $storage, $logger);
        $cancelService = new InvoiceCancelService($toolsFactory, $storage, $logger);
        $inutilizationService = new InvoiceInutilizationService($toolsFactory, $storage, $logger);
        $correctionService = new CorrectionLetterService($toolsFactory, $storage, $logger);

        $danfeGenerator = new DanfeGenerator($storage, $logger);
        $danfceGenerator = new DanfceGenerator($storage, $logger);

        $guard = new InternalKeyGuard(self::env('FISCAL_INTERNAL_KEY'));
        $router = new Router($guard, $logger);

        $health = new HealthController();
        $emission = new EmissionController($nfeEmitter, $nfceEmitter);
        $events = new EventController($cancelService, $inutilizationService, $correctionService);
        $query = new QueryController($statusService);
        $files = new FileController($storage, $danfeGenerator, $danfceGenerator);
        $certificates = new CertificateController($certificateManager);

        $router->get('/internal/fiscal/health', [$health, 'health']);

        $router->post('/internal/fiscal/nfe/emit', [$emission, 'emitNfe']);
        $router->post('/internal/fiscal/nfce/emit', [$emission, 'emitNfce']);

        $router->post('/internal/fiscal/invoices/inutilize', [$events, 'inutilize']);
        $router->post('/internal/fiscal/invoices/{id}/cancel', [$events, 'cancel']);
        $router->post('/internal/fiscal/invoices/{id}/correction-letter', [$events, 'correctionLetter']);

        $router->get('/internal/fiscal/status/{uf}', [$query, 'sefazStatus']);
        $router->post('/internal/fiscal/status/{uf}', [$query, 'sefazStatus']);
        $router->post('/internal/fiscal/invoices/{id}/query', [$query, 'queryInvoice']);
        $router->get('/internal/fiscal/invoices/{id}/query', [$query, 'queryInvoice']);
        $router->post('/internal/fiscal/receipts/{receiptId}/query', [$query, 'queryReceipt']);
        $router->get('/internal/fiscal/receipts/{receiptId}/query', [$query, 'queryReceipt']);

        $router->get('/internal/fiscal/invoices/{id}/xml', [$files, 'downloadXml']);
        $router->get('/internal/fiscal/invoices/{id}/danfe', [$files, 'danfe']);
        $router->get('/internal/fiscal/invoices/{id}/danfce', [$files, 'danfce']);

        $router->post('/internal/fiscal/certificates/upload', [$certificates, 'upload']);
        $router->post('/internal/fiscal/certificates/test', [$certificates, 'test']);
        $router->post('/internal/fiscal/certificates/remove', [$certificates, 'remove']);

        return new self($router);
    }

    public function run(): void
    {
        $request = Request::fromGlobals();

        try {
            $response = $this->router->dispatch($request);
        } catch (Throwable $exception) {
            $response = Response::json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => $exception->getMessage(),
                ],
            ], 500);
        }

        $response->send();
    }
}
