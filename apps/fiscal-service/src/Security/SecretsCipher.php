<?php

declare(strict_types=1);

namespace IntegraSys\Fiscal\Security;

use IntegraSys\Fiscal\Exceptions\FiscalException;

/**
 * Decifra segredos (senha do certificado A1, CSC token) cifrados pela
 * API Node com AES-256-GCM. Formato: base64(iv):base64(tag):base64(dados).
 *
 * A senha do certificado nunca trafega nem é armazenada em texto puro.
 */
final class SecretsCipher
{
    private const ALGORITHM = 'aes-256-gcm';

    public function __construct(private readonly string $hexKey)
    {
    }

    public function decrypt(string $payload): string
    {
        $key = hex2bin($this->hexKey);
        if ($key === false || strlen($key) !== 32) {
            throw FiscalException::certificate('FISCAL_ENCRYPTION_KEY inválida: esperado 32 bytes em hex');
        }

        $parts = explode(':', $payload);
        if (count($parts) !== 3) {
            throw FiscalException::certificate('Payload cifrado inválido');
        }

        [$ivB64, $tagB64, $dataB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $data = base64_decode($dataB64, true);

        if ($iv === false || $tag === false || $data === false) {
            throw FiscalException::certificate('Payload cifrado com base64 inválido');
        }

        $plain = openssl_decrypt($data, self::ALGORITHM, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw FiscalException::certificate('Falha ao decifrar segredo (chave incorreta ou payload corrompido)');
        }

        return $plain;
    }

    public function encrypt(string $plainText): string
    {
        $key = hex2bin($this->hexKey);
        if ($key === false || strlen($key) !== 32) {
            throw FiscalException::certificate('FISCAL_ENCRYPTION_KEY inválida: esperado 32 bytes em hex');
        }

        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plainText, self::ALGORITHM, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw FiscalException::certificate('Falha ao cifrar segredo');
        }

        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($encrypted);
    }
}
