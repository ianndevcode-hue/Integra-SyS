<?php
declare(strict_types=1);

/**
 * AES-256-GCM encryption for secrets stored in the database (e.g. Asaas API key).
 */

function app_key(): string
{
    $key = (string)config('app_key', '');
    if ($key === '') throw new RuntimeException('APP_KEY not configured');
    return hash('sha256', $key, true);
}

function encrypt_secret(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(?string $payload): string
{
    if (!$payload) return '';
    if (strpos($payload, 'enc:') !== 0) return $payload;
    $raw = base64_decode(substr($payload, 4), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function mask_secret(string $secret): string
{
    $len = strlen($secret);
    if ($len <= 8) return str_repeat('•', $len);
    return substr($secret, 0, 6) . str_repeat('•', 8) . substr($secret, -4);
}
