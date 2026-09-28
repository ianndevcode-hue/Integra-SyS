<?php
declare(strict_types=1);

/**
 * Asaas API v3 client.
 * Docs: https://docs.asaas.com/reference
 * When no API key is configured, callers run in "demo mode" (see finance.php).
 */

class AsaasException extends RuntimeException
{
    public array $errors;

    public function __construct(string $message, int $code = 0, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }
}

class AsaasClient
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $environment = null)
    {
        $this->apiKey = $apiKey ?? (string)setting('asaas_api_key', '');
        $environment = $environment ?? (string)setting('asaas_environment', 'sandbox');
        $this->baseUrl = $environment === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';
    }

    public static function isConfigured(): bool
    {
        return (string)setting('asaas_api_key', '') !== '';
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Fetch every page of a list endpoint (limit 100 per page).
     */
    public function all(string $path, array $query = [], int $maxPages = 50): array
    {
        $items = [];
        $offset = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $res = $this->get($path, $query + ['offset' => $offset, 'limit' => 100]);
            $items = array_merge($items, $res['data'] ?? []);
            if (empty($res['hasMore'])) break;
            $offset += 100;
        }
        return $items;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->apiKey === '') throw new AsaasException('Chave da API Asaas não configurada.');

        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            $headers = [
                'accept: application/json',
                'content-type: application/json',
                'user-agent: IntegraCode/1.0 (+https://integra-code.tech)',
                'access_token: ' . $this->apiKey,
            ];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $retryable = $raw === false || $status === 429 || $status >= 500;
            if ($retryable && $attempt < 3) {
                usleep(400000 * $attempt);
                continue;
            }
            if ($raw === false) {
                log_line('asaas', 'network error', ['path' => $path, 'error' => $curlError]);
                throw new AsaasException('Falha de conexão com o Asaas: ' . $curlError);
            }

            $data = json_decode($raw, true) ?? [];
            if ($status >= 400) {
                $errors = $data['errors'] ?? [];
                $msg = $errors[0]['description'] ?? ('Erro HTTP ' . $status . ' no Asaas');
                if ($status === 401) $msg = 'Chave da API Asaas inválida para o ambiente selecionado.';
                log_line('asaas', 'api error', ['path' => $path, 'status' => $status, 'errors' => $errors]);
                throw new AsaasException($msg, $status, $errors);
            }
            return $data;
        }
    }
}
