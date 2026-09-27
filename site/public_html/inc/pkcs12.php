<?php
/**
 * Pure-PHP PKCS#12 (.pfx/.p12) reader (RFC 7292).
 *
 * Fallback for servers whose OpenSSL 3 has no "legacy" provider: most ICP-Brasil A1 certificates
 * protect the certificate bags with RC2-40 (and some with RC4), which OpenSSL 3 refuses to decrypt
 * ("unsupported"). Supports the PKCS#12 PBE family (RC2, RC4, 2/3-key 3DES), PBES2/PBKDF2 (AES,
 * 3DES), the HMAC integrity check (SHA-1/SHA-2) and BER indefinite lengths (Java/Windows exports).
 *
 * Pkcs12::read($pfx, $password) returns the same shape as openssl_pkcs12_read():
 * ['cert' => PEM, 'pkey' => PEM (PKCS#8), 'extracerts' => [PEM, ...]].
 */
declare(strict_types=1);

final class Pkcs12Exception extends RuntimeException
{
    /** password | unsupported | invalid */
    public string $reason;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}

final class Pkcs12
{
    private const OID_DATA = '1.2.840.113549.1.7.1';
    private const OID_ENCRYPTED_DATA = '1.2.840.113549.1.7.6';
    private const OID_KEY_BAG = '1.2.840.113549.1.12.10.1.1';
    private const OID_SHROUDED_KEY_BAG = '1.2.840.113549.1.12.10.1.2';
    private const OID_CERT_BAG = '1.2.840.113549.1.12.10.1.3';
    private const OID_SAFE_CONTENTS_BAG = '1.2.840.113549.1.12.10.1.6';
    private const OID_X509 = '1.2.840.113549.1.9.22.1';
    private const OID_LOCAL_KEY_ID = '1.2.840.113549.1.9.21';
    private const OID_PBES2 = '1.2.840.113549.1.5.13';
    private const OID_PBKDF2 = '1.2.840.113549.1.5.12';
    private const MAX_ITERATIONS = 2000000;

    /** PKCS#12 PBE: oid => [cipher, key length, IV length, RC2 effective bits] */
    private const PBE = [
        '1.2.840.113549.1.12.1.1' => ['rc4', 16, 0, 0],
        '1.2.840.113549.1.12.1.2' => ['rc4', 5, 0, 0],
        '1.2.840.113549.1.12.1.3' => ['3des', 24, 8, 0],
        '1.2.840.113549.1.12.1.4' => ['3des', 16, 8, 0],
        '1.2.840.113549.1.12.1.5' => ['rc2', 16, 8, 128],
        '1.2.840.113549.1.12.1.6' => ['rc2', 5, 8, 40],
    ];
    private const PBES2_CIPHERS = [
        '2.16.840.1.101.3.4.1.2' => ['aes-128-cbc', 16],
        '2.16.840.1.101.3.4.1.22' => ['aes-192-cbc', 24],
        '2.16.840.1.101.3.4.1.42' => ['aes-256-cbc', 32],
        '1.2.840.113549.3.7' => ['des-ede3-cbc', 24],
    ];
    private const HMACS = [
        '1.2.840.113549.2.7' => 'sha1', '1.2.840.113549.2.8' => 'sha224', '1.2.840.113549.2.9' => 'sha256',
        '1.2.840.113549.2.10' => 'sha384', '1.2.840.113549.2.11' => 'sha512',
    ];
    private const DIGESTS = [
        '1.3.14.3.2.26' => 'sha1', '2.16.840.1.101.3.4.2.4' => 'sha224', '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384', '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    /** RC2 PITABLE (RFC 2268, section 2). */
    private const PITABLE = [
        0xd9, 0x78, 0xf9, 0xc4, 0x19, 0xdd, 0xb5, 0xed, 0x28, 0xe9, 0xfd, 0x79, 0x4a, 0xa0, 0xd8, 0x9d,
        0xc6, 0x7e, 0x37, 0x83, 0x2b, 0x76, 0x53, 0x8e, 0x62, 0x4c, 0x64, 0x88, 0x44, 0x8b, 0xfb, 0xa2,
        0x17, 0x9a, 0x59, 0xf5, 0x87, 0xb3, 0x4f, 0x13, 0x61, 0x45, 0x6d, 0x8d, 0x09, 0x81, 0x7d, 0x32,
        0xbd, 0x8f, 0x40, 0xeb, 0x86, 0xb7, 0x7b, 0x0b, 0xf0, 0x95, 0x21, 0x22, 0x5c, 0x6b, 0x4e, 0x82,
        0x54, 0xd6, 0x65, 0x93, 0xce, 0x60, 0xb2, 0x1c, 0x73, 0x56, 0xc0, 0x14, 0xa7, 0x8c, 0xf1, 0xdc,
        0x12, 0x75, 0xca, 0x1f, 0x3b, 0xbe, 0xe4, 0xd1, 0x42, 0x3d, 0xd4, 0x30, 0xa3, 0x3c, 0xb6, 0x26,
        0x6f, 0xbf, 0x0e, 0xda, 0x46, 0x69, 0x07, 0x57, 0x27, 0xf2, 0x1d, 0x9b, 0xbc, 0x94, 0x43, 0x03,
        0xf8, 0x11, 0xc7, 0xf6, 0x90, 0xef, 0x3e, 0xe7, 0x06, 0xc3, 0xd5, 0x2f, 0xc8, 0x66, 0x1e, 0xd7,
        0x08, 0xe8, 0xea, 0xde, 0x80, 0x52, 0xee, 0xf7, 0x84, 0xaa, 0x72, 0xac, 0x35, 0x4d, 0x6a, 0x2a,
        0x96, 0x1a, 0xd2, 0x71, 0x5a, 0x15, 0x49, 0x74, 0x4b, 0x9f, 0xd0, 0x5e, 0x04, 0x18, 0xa4, 0xec,
        0xc2, 0xe0, 0x41, 0x6e, 0x0f, 0x51, 0xcb, 0xcc, 0x24, 0x91, 0xaf, 0x50, 0xa1, 0xf4, 0x70, 0x39,
        0x99, 0x7c, 0x3a, 0x85, 0x23, 0xb8, 0xb4, 0x7a, 0xfc, 0x02, 0x36, 0x5b, 0x25, 0x55, 0x97, 0x31,
        0x2d, 0x5d, 0xfa, 0x98, 0xe3, 0x8a, 0x92, 0xae, 0x05, 0xdf, 0x29, 0x10, 0x67, 0x6c, 0xba, 0xc9,
        0xd3, 0x00, 0xe6, 0xcf, 0xe1, 0x9e, 0xa8, 0x2c, 0x63, 0x16, 0x01, 0x3f, 0x58, 0xe2, 0x89, 0xa9,
        0x0d, 0x38, 0x34, 0x1b, 0xab, 0x33, 0xff, 0xb0, 0xbb, 0x48, 0x0c, 0x5f, 0xb9, 0xb1, 0xcd, 0x2e,
        0xc5, 0xf3, 0xdb, 0x47, 0xe5, 0xa5, 0x9c, 0x77, 0x0a, 0xa6, 0x20, 0x68, 0xfe, 0x7f, 0xc1, 0xad,
    ];

    public static function read(string $pfx, string $password): array
    {
        try {
            return self::parsePfx($pfx, $password);
        } catch (Pkcs12Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new Pkcs12Exception('invalid', $e->getMessage());
        }
    }

    private static function parsePfx(string $pfx, string $password): array
    {
        $root = self::der($pfx);
        self::expect($root, 0x30);
        $authSafe = $root['c'][1] ?? null;
        $macData = $root['c'][2] ?? null;
        self::expect($authSafe, 0x30);
        if (self::oid($authSafe['c'][0] ?? null) !== self::OID_DATA) throw new Pkcs12Exception('unsupported', 'PFX com integridade por chave pública (signedData)');
        $content = self::octets(self::explicit($authSafe['c'][1] ?? null));

        // Password candidates: BMPString (UTF-16BE + NUL) for the PKCS#12 KDF, raw bytes for PBES2.
        // An empty password may be encoded as "\0\0" or as nothing; non-ASCII ones as UTF-16 or byte-wise.
        $cands = [['bmp' => mb_convert_encoding($password, 'UTF-16BE', 'UTF-8') . "\0\0", 'raw' => $password]];
        if (preg_match('/[\x80-\xff]/', $password)) $cands[] = ['bmp' => preg_replace('/(.)/s', "\0$1", $password) . "\0\0", 'raw' => $password];
        if ($password === '') $cands[] = ['bmp' => '', 'raw' => ''];
        if ($macData) {
            $pw = null;
            $mac = self::macParams($macData);
            if ($mac === null) {
                $pw = $cands[0]; // unknown MAC scheme (e.g. PBMAC1): rely on the decryption checks
            } else {
                foreach ($cands as $c) if (self::macOk($mac, $content, $c['bmp'])) { $pw = $c; break; }
                if ($pw === null) throw new Pkcs12Exception('password');
            }
            return self::extract($content, $pw);
        }
        $last = null;
        foreach ($cands as $c) {
            try { return self::extract($content, $c); } catch (Pkcs12Exception $e) { $last = $e; }
        }
        throw $last ?? new Pkcs12Exception('invalid');
    }

    /* ------------------------------------------------------------ contents */

    private static function extract(string $content, array $pw): array
    {
        $safe = self::der($content);
        self::expect($safe, 0x30);
        $found = ['keys' => [], 'certs' => []];
        foreach ($safe['c'] as $ci) {
            $type = self::oid($ci['c'][0] ?? null);
            $body = self::explicit($ci['c'][1] ?? null);
            if ($type === self::OID_DATA) {
                $bags = self::der(self::octets($body));
            } elseif ($type === self::OID_ENCRYPTED_DATA) {
                // EncryptedData ::= SEQUENCE { version, EncryptedContentInfo { contentType, algorithm, [0] IMPLICIT encryptedContent } }
                $eci = $body['c'][1] ?? null;
                self::expect($eci, 0x30);
                if (!isset($eci['c'][2])) continue;
                $bags = self::der(self::decrypt($eci['c'][1], self::octets($eci['c'][2]), $pw));
            } else {
                throw new Pkcs12Exception('unsupported', 'conteúdo ' . $type);
            }
            self::bags($bags, $pw, $found);
        }
        if (!$found['keys']) throw new Pkcs12Exception('invalid', 'o arquivo não contém a chave privada');
        if (!$found['certs']) throw new Pkcs12Exception('invalid', 'o arquivo não contém o certificado');

        $keyPem = self::pem('PRIVATE KEY', $found['keys'][0]['der']);
        $key = openssl_pkey_get_private($keyPem);
        if ($key === false) throw new Pkcs12Exception('invalid', 'chave privada ilegível');
        $leaf = null;
        $keyId = $found['keys'][0]['id'];
        if ($keyId !== null) foreach ($found['certs'] as $i => $c) if ($c['id'] === $keyId) { $leaf = $i; break; }
        if ($leaf === null) foreach ($found['certs'] as $i => $c) if (@openssl_x509_check_private_key(self::pem('CERTIFICATE', $c['der']), $key)) { $leaf = $i; break; }
        $leaf ??= 0;
        $extra = [];
        foreach ($found['certs'] as $i => $c) if ($i !== $leaf) $extra[] = self::pem('CERTIFICATE', $c['der']);
        while (openssl_error_string()) { /* drain the OpenSSL error queue */ }
        return ['cert' => self::pem('CERTIFICATE', $found['certs'][$leaf]['der']), 'pkey' => $keyPem, 'extracerts' => $extra];
    }

    private static function bags(array $safeContents, array $pw, array &$found): void
    {
        self::expect($safeContents, 0x30);
        foreach ($safeContents['c'] as $bag) {
            $id = self::oid($bag['c'][0] ?? null);
            $val = self::explicit($bag['c'][1] ?? null);
            $keyId = null;
            foreach (($bag['c'][2]['c'] ?? []) as $attr) {
                if (self::oid($attr['c'][0] ?? null) === self::OID_LOCAL_KEY_ID && isset($attr['c'][1]['c'][0])) $keyId = self::octets($attr['c'][1]['c'][0]);
            }
            if ($id === self::OID_CERT_BAG) {
                if (self::oid($val['c'][0] ?? null) === self::OID_X509) $found['certs'][] = ['der' => self::octets(self::explicit($val['c'][1] ?? null)), 'id' => $keyId];
            } elseif ($id === self::OID_SHROUDED_KEY_BAG) {
                $found['keys'][] = ['der' => self::decrypt($val['c'][0] ?? null, self::octets($val['c'][1] ?? null), $pw), 'id' => $keyId];
            } elseif ($id === self::OID_KEY_BAG) {
                $found['keys'][] = ['der' => $val['raw'], 'id' => $keyId];
            } elseif ($id === self::OID_SAFE_CONTENTS_BAG) {
                self::bags($val, $pw, $found);
            }
        }
    }

    /* ------------------------------------------------------------- crypto */

    private static function macParams(array $macData): ?array
    {
        // MacData ::= SEQUENCE { mac DigestInfo { AlgorithmIdentifier, digest }, macSalt, iterations DEFAULT 1 }
        $di = $macData['c'][0] ?? null;
        self::expect($di, 0x30);
        $hash = self::DIGESTS[self::oid($di['c'][0]['c'][0] ?? null)] ?? null;
        if ($hash === null) return null;
        return ['hash' => $hash, 'digest' => self::octets($di['c'][1] ?? null), 'salt' => self::octets($macData['c'][1] ?? null),
            'iter' => isset($macData['c'][2]) ? self::iterations(self::int($macData['c'][2])) : 1];
    }

    private static function macOk(array $mac, string $data, string $bmp): bool
    {
        $len = strlen(hash($mac['hash'], '', true));
        $key = self::kdf($mac['hash'], $bmp, $mac['salt'], $mac['iter'], 3, $len);
        return hash_equals($mac['digest'], hash_hmac($mac['hash'], $data, $key, true));
    }

    private static function decrypt(?array $alg, string $data, array $pw): string
    {
        self::expect($alg, 0x30);
        $oid = self::oid($alg['c'][0] ?? null);
        $params = $alg['c'][1] ?? null;
        if (isset(self::PBE[$oid])) {
            [$cipher, $keyLen, $ivLen, $bits] = self::PBE[$oid];
            self::expect($params, 0x30);
            $salt = self::octets($params['c'][0] ?? null);
            $iter = self::iterations(self::int($params['c'][1] ?? null));
            $key = self::kdf('sha1', $pw['bmp'], $salt, $iter, 1, $keyLen);
            $iv = $ivLen ? self::kdf('sha1', $pw['bmp'], $salt, $iter, 2, $ivLen) : '';
            if ($cipher === 'rc4') return self::rc4($key, $data);
            if ($cipher === 'rc2') return self::rc2Cbc($key, $bits, $iv, $data);
            return self::openssl('des-ede3-cbc', strlen($key) === 16 ? $key . substr($key, 0, 8) : $key, $iv, $data);
        }
        if ($oid === self::OID_PBES2) {
            // PBES2-params ::= SEQUENCE { keyDerivationFunc (PBKDF2), encryptionScheme }
            $kdf = $params['c'][0] ?? null;
            $enc = $params['c'][1] ?? null;
            self::expect($kdf, 0x30);
            self::expect($enc, 0x30);
            if (self::oid($kdf['c'][0] ?? null) !== self::OID_PBKDF2) throw new Pkcs12Exception('unsupported', 'derivação ' . self::oid($kdf['c'][0] ?? null));
            $kp = $kdf['c'][1]['c'] ?? [];
            $salt = self::octets($kp[0] ?? null);
            $iter = self::iterations(self::int($kp[1] ?? null));
            $keyLen = null;
            $prf = 'sha1';
            foreach (array_slice($kp, 2) as $n) {
                if ($n['t'] === 0x02) $keyLen = self::int($n);
                if ($keyLen !== null && ($keyLen < 1 || $keyLen > 64)) throw new Pkcs12Exception('invalid', 'tamanho de chave inválido');
                elseif ($n['t'] === 0x30) $prf = self::HMACS[self::oid($n['c'][0] ?? null)] ?? throw new Pkcs12Exception('unsupported', 'PRF ' . self::oid($n['c'][0] ?? null));
            }
            $encOid = self::oid($enc['c'][0] ?? null);
            [$cipher, $defLen] = self::PBES2_CIPHERS[$encOid] ?? throw new Pkcs12Exception('unsupported', 'cifra ' . $encOid);
            $key = hash_pbkdf2($prf, $pw['raw'], $salt, $iter, $keyLen ?? $defLen, true);
            return self::openssl($cipher, $key, self::octets($enc['c'][1] ?? null), $data);
        }
        throw new Pkcs12Exception('unsupported', 'algoritmo ' . $oid);
    }

    private static function openssl(string $cipher, string $key, string $iv, string $data): string
    {
        if (!in_array($cipher, array_map('strtolower', openssl_get_cipher_methods()), true)) throw new Pkcs12Exception('unsupported', $cipher . ' indisponível no OpenSSL do servidor');
        $out = openssl_decrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        while (openssl_error_string()) { /* drain */ }
        if ($out === false) throw new Pkcs12Exception('password');
        return $out;
    }

    /**
     * Iteration counts come from the (untrusted) uploaded file and the KDF here runs in PHP:
     * real certificates use 1–600 000 iterations, so anything far above that is rejected
     * instead of pinning the PHP worker.
     */
    private static function iterations(int $n): int
    {
        if ($n < 1 || $n > self::MAX_ITERATIONS) throw new Pkcs12Exception('invalid', 'número de iterações fora do padrão (' . $n . ')');
        return $n;
    }

    /** PKCS#12 key derivation (RFC 7292, appendix B.2). $id: 1 key, 2 IV, 3 MAC key. */
    public static function kdf(string $hash, string $bmp, string $salt, int $iter, int $id, int $n): string
    {
        $u = strlen(hash($hash, '', true));
        $v = in_array($hash, ['sha384', 'sha512'], true) ? 128 : 64;
        $fill = static function (string $s) use ($v): string {
            if ($s === '') return '';
            $len = $v * (int)ceil(strlen($s) / $v);
            return substr(str_repeat($s, intdiv($len, strlen($s)) + 1), 0, $len);
        };
        $D = str_repeat(chr($id), $v);
        $I = $fill($salt) . $fill($bmp);
        $out = '';
        for ($i = 1, $c = (int)ceil($n / $u); $i <= $c; $i++) {
            $A = hash($hash, $D . $I, true);
            for ($j = 1; $j < $iter; $j++) $A = hash($hash, $A, true);
            $out .= $A;
            if ($i < $c) {
                $B = substr(str_repeat($A, intdiv($v, $u) + 1), 0, $v);
                $next = '';
                for ($k = 0, $len = strlen($I); $k < $len; $k += $v) {
                    $blk = substr($I, $k, $v);
                    $carry = 1;
                    for ($x = $v - 1; $x >= 0; $x--) {
                        $s = ord($blk[$x]) + ord($B[$x]) + $carry;
                        $blk[$x] = chr($s & 0xff);
                        $carry = $s >> 8;
                    }
                    $next .= $blk;
                }
                $I = $next;
            }
        }
        return substr($out, 0, $n);
    }

    private static function rc4(string $key, string $data): string
    {
        $S = range(0, 255);
        $kl = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $S[$i] + ord($key[$i % $kl])) & 0xff;
            [$S[$i], $S[$j]] = [$S[$j], $S[$i]];
        }
        $out = '';
        for ($k = 0, $i = 0, $j = 0, $n = strlen($data); $k < $n; $k++) {
            $i = ($i + 1) & 0xff;
            $j = ($j + $S[$i]) & 0xff;
            [$S[$i], $S[$j]] = [$S[$j], $S[$i]];
            $out .= chr(ord($data[$k]) ^ $S[($S[$i] + $S[$j]) & 0xff]);
        }
        return $out;
    }

    /** RC2 key expansion (RFC 2268, section 2) → 64 16-bit subkeys. */
    public static function rc2Keys(string $key, int $bits): array
    {
        $T = strlen($key);
        $L = array_values(unpack('C*', $key));
        for ($i = $T; $i < 128; $i++) $L[$i] = self::PITABLE[($L[$i - 1] + $L[$i - $T]) & 0xff];
        $T8 = ($bits + 7) >> 3;
        $L[128 - $T8] = self::PITABLE[$L[128 - $T8] & (0xff >> (8 * $T8 - $bits))];
        for ($i = 127 - $T8; $i >= 0; $i--) $L[$i] = self::PITABLE[$L[$i + 1] ^ $L[$i + $T8]];
        $K = [];
        for ($i = 0; $i < 64; $i++) $K[$i] = $L[2 * $i] | ($L[2 * $i + 1] << 8);
        return $K;
    }

    /** RC2 block decryption (RFC 2268, section 4). */
    public static function rc2DecryptBlock(array $K, string $block): string
    {
        $w = unpack('v4', $block);
        [$r0, $r1, $r2, $r3] = [$w[1], $w[2], $w[3], $w[4]];
        $j = 63;
        for ($round = 0; $round < 16; $round++) {
            $r3 = (($r3 << 11) | ($r3 >> 5)) & 0xffff;
            $r3 = ($r3 - $K[$j--] - ($r2 & $r1) - (~$r2 & $r0)) & 0xffff;
            $r2 = (($r2 << 13) | ($r2 >> 3)) & 0xffff;
            $r2 = ($r2 - $K[$j--] - ($r1 & $r0) - (~$r1 & $r3)) & 0xffff;
            $r1 = (($r1 << 14) | ($r1 >> 2)) & 0xffff;
            $r1 = ($r1 - $K[$j--] - ($r0 & $r3) - (~$r0 & $r2)) & 0xffff;
            $r0 = (($r0 << 15) | ($r0 >> 1)) & 0xffff;
            $r0 = ($r0 - $K[$j--] - ($r3 & $r2) - (~$r3 & $r1)) & 0xffff;
            if ($round === 4 || $round === 10) {
                $r3 = ($r3 - $K[$r2 & 63]) & 0xffff;
                $r2 = ($r2 - $K[$r1 & 63]) & 0xffff;
                $r1 = ($r1 - $K[$r0 & 63]) & 0xffff;
                $r0 = ($r0 - $K[$r3 & 63]) & 0xffff;
            }
        }
        return pack('v4', $r0, $r1, $r2, $r3);
    }

    private static function rc2Cbc(string $key, int $bits, string $iv, string $data): string
    {
        if ($data === '' || strlen($data) % 8) throw new Pkcs12Exception('invalid', 'bloco RC2 incompleto');
        $K = self::rc2Keys($key, $bits);
        $out = '';
        $prev = $iv;
        for ($i = 0, $n = strlen($data); $i < $n; $i += 8) {
            $c = substr($data, $i, 8);
            $out .= self::rc2DecryptBlock($K, $c) ^ $prev;
            $prev = $c;
        }
        $pad = ord($out[-1]);
        if ($pad < 1 || $pad > 8 || substr($out, -$pad) !== str_repeat(chr($pad), $pad)) throw new Pkcs12Exception('password');
        return substr($out, 0, -$pad);
    }

    /* ---------------------------------------------------------------- DER */

    /** Parse one BER/DER element: ['t' => tag byte, 'v' => primitive content, 'c' => children, 'raw' => encoding]. */
    private static function der(string $d): array
    {
        $p = 0;
        $node = self::node($d, $p, strlen($d));
        return $node;
    }

    private static function node(string $d, int &$p, int $end): array
    {
        $start = $p;
        if ($p + 2 > $end) throw new Pkcs12Exception('invalid', 'ASN.1 truncado');
        $tag = ord($d[$p++]);
        if (($tag & 0x1f) === 0x1f) throw new Pkcs12Exception('invalid', 'tag ASN.1 inesperada');
        $len = ord($d[$p++]);
        $cons = ($tag & 0x20) !== 0;
        if ($len === 0x80) {
            if (!$cons) throw new Pkcs12Exception('invalid', 'comprimento indefinido em tipo primitivo');
            $children = [];
            while (true) {
                if ($p + 2 > $end) throw new Pkcs12Exception('invalid', 'ASN.1 truncado');
                if ($d[$p] === "\0" && $d[$p + 1] === "\0") { $p += 2; break; }
                $children[] = self::node($d, $p, $end);
            }
            return ['t' => $tag, 'v' => null, 'c' => $children, 'raw' => substr($d, $start, $p - $start)];
        }
        if ($len & 0x80) {
            $n = $len & 0x7f;
            if ($n < 1 || $n > 4 || $p + $n > $end) throw new Pkcs12Exception('invalid', 'comprimento ASN.1 inválido');
            $len = 0;
            for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($d[$p++]);
        }
        if ($p + $len > $end) throw new Pkcs12Exception('invalid', 'ASN.1 truncado');
        $body = $p;
        $p += $len;
        if (!$cons) return ['t' => $tag, 'v' => substr($d, $body, $len), 'c' => null, 'raw' => substr($d, $start, $p - $start)];
        $children = [];
        for ($q = $body; $q < $p;) $children[] = self::node($d, $q, $p);
        return ['t' => $tag, 'v' => null, 'c' => $children, 'raw' => substr($d, $start, $p - $start)];
    }

    private static function expect(?array $n, int $tag): void
    {
        if ($n === null || $n['t'] !== $tag) throw new Pkcs12Exception('invalid', sprintf('estrutura inesperada (tag %02x)', $n['t'] ?? 0));
    }

    /** [n] EXPLICIT wrapper → inner element. */
    private static function explicit(?array $n): array
    {
        if ($n === null || ($n['t'] & 0xe0) !== 0xa0 || empty($n['c'])) throw new Pkcs12Exception('invalid', 'conteúdo ausente');
        return $n['c'][0];
    }

    /** OCTET STRING content, joining BER constructed chunks (also for [0] IMPLICIT OCTET STRING). */
    private static function octets(?array $n): string
    {
        if ($n === null) throw new Pkcs12Exception('invalid', 'OCTET STRING ausente');
        if ($n['c'] === null) return $n['v'];
        return implode('', array_map([self::class, 'octets'], $n['c']));
    }

    private static function oid(?array $n): string
    {
        if ($n === null || $n['t'] !== 0x06 || $n['v'] === '') throw new Pkcs12Exception('invalid', 'OID ausente');
        $parts = [];
        $val = 0;
        foreach (unpack('C*', $n['v']) as $b) {
            $val = ($val << 7) | ($b & 0x7f);
            if (!($b & 0x80)) { $parts[] = $val; $val = 0; }
        }
        $first = array_shift($parts);
        array_unshift($parts, $first < 80 ? intdiv($first, 40) : 2, $first < 80 ? $first % 40 : $first - 80);
        return implode('.', $parts);
    }

    private static function int(?array $n): int
    {
        if ($n === null || $n['t'] !== 0x02 || $n['v'] === '' || strlen(ltrim($n['v'], "\0")) > 7) throw new Pkcs12Exception('invalid', 'inteiro ASN.1 inválido');
        $v = 0;
        foreach (unpack('C*', $n['v']) as $b) $v = ($v << 8) | $b;
        return $v;
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }
}
