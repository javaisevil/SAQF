<?php
declare(strict_types=1);

namespace Saqf\Security;

/**
 * Verifies signed JSON Web Tokens (OpenID Connect ID tokens) against a JSON Web Key Set.
 * Asymmetric algorithms only (RS256/384/512, ES256/384) — "none" and shared-secret HS* are
 * refused, so a token cannot be forged by switching algorithms. Claims are checked by Oidc.
 */
final class Jwt
{
    public const ALGORITHMS = [
        'RS256' => ['RSA', OPENSSL_ALGO_SHA256], 'RS384' => ['RSA', OPENSSL_ALGO_SHA384], 'RS512' => ['RSA', OPENSSL_ALGO_SHA512],
        'ES256' => ['EC', OPENSSL_ALGO_SHA256], 'ES384' => ['EC', OPENSSL_ALGO_SHA384],
    ];

    /**
     * @return array the verified payload
     * @throws JwtKeyNotFound when no key in the set matches (caller may refresh the key set once)
     * @throws SsoException on any other failure
     */
    public static function verify(string $jwt, array $jwks): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('Malformed ID token.');
        }
        [$h, $p, $sig] = $parts;
        $header = json_decode(self::b64($h), true);
        $payload = json_decode(self::b64($p), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new SsoException('Malformed ID token.');
        }
        $alg = (string) ($header['alg'] ?? '');
        if (!isset(self::ALGORITHMS[$alg])) {
            throw new SsoException("ID token algorithm \"$alg\" is not accepted.");
        }
        [$kty, $hash] = self::ALGORITHMS[$alg];
        $key = null;
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kty'] ?? '') !== $kty || ($jwk['use'] ?? 'sig') !== 'sig' || (isset($jwk['alg']) && $jwk['alg'] !== $alg)) {
                continue;
            }
            if (isset($header['kid']) && ($jwk['kid'] ?? null) !== $header['kid']) {
                continue;
            }
            $key = $jwk;
            break;
        }
        if ($key === null) {
            throw new JwtKeyNotFound('No signing key matches the ID token (kid ' . ($header['kid'] ?? 'none') . ').');
        }
        $signature = self::b64($sig);
        if ($kty === 'EC') {
            $signature = self::ecdsaDer($signature, $alg === 'ES256' ? 32 : 48);
        }
        $ok = openssl_verify($h . '.' . $p, $signature, self::pem($key), $hash);
        if ($ok !== 1) {
            throw new SsoException('The ID token signature is not valid.');
        }
        return $payload;
    }

    /** PEM public key from a JWK (RSA modulus/exponent or EC P-256/P-384 point). */
    public static function pem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') === 'RSA') {
            $rsa = self::seq(self::int(self::b64((string) $jwk['n'])) . self::int(self::b64((string) $jwk['e'])));
            $spki = self::seq(self::seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00") . self::bits($rsa));
        } elseif (($jwk['kty'] ?? '') === 'EC') {
            $curves = ['P-256' => "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07", 'P-384' => "\x06\x05\x2B\x81\x04\x00\x22"];
            if (!isset($curves[$jwk['crv'] ?? ''])) {
                throw new SsoException('Unsupported elliptic curve in the identity provider keys.');
            }
            $point = "\x04" . self::b64((string) $jwk['x']) . self::b64((string) $jwk['y']);
            $spki = self::seq(self::seq("\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . $curves[$jwk['crv']]) . self::bits($point));
        } else {
            throw new SsoException('Unsupported key type in the identity provider keys.');
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), false);
    }

    /** JOSE ECDSA signatures are raw r||s; OpenSSL wants ASN.1 DER. */
    private static function ecdsaDer(string $raw, int $size): string
    {
        if (strlen($raw) !== 2 * $size) {
            throw new SsoException('The ID token signature is not valid.');
        }
        return self::seq(self::int(substr($raw, 0, $size)) . self::int(substr($raw, $size)));
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) >= 0x80) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . self::len(strlen($bytes)) . $bytes;
    }

    private static function seq(string $content): string
    {
        return "\x30" . self::len(strlen($content)) . $content;
    }

    private static function bits(string $content): string
    {
        return "\x03" . self::len(strlen($content) + 1) . "\0" . $content;
    }
}

/** No key in the identity provider's key set matches the token (keys may have rotated). */
final class JwtKeyNotFound extends \RuntimeException
{
}
