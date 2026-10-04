<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;

/**
 * WebAuthn (passkey) verification, narrowly scoped and free of any database or session code so that it can be
 * tested with hostile inputs:
 *   - registration with attestation "none" and assertions, nothing else (no attestation statements are trusted);
 *   - ES256 (ECDSA P-256 with SHA-256) public keys only;
 *   - user presence AND user verification (PIN / biometric on the device) are both required;
 *   - the challenge, origin and relying-party id are checked exactly; a signature counter that does not move
 *     forward (when the authenticator uses one) is refused as a possible cloned credential.
 * Everything cryptographic is done by OpenSSL; this class parses the structures and enforces the checks.
 * It is hand-written, has not been independently reviewed, and a university should have it reviewed (or
 * replace it with a maintained WebAuthn library) before relying on it for administrators.
 *
 * Spec: https://www.w3.org/TR/webauthn-2/ (registration §7.1, authentication §7.2).
 */
final class WebAuthn
{
    public const FLAG_UP = 0x01;
    public const FLAG_UV = 0x04;
    public const FLAG_AT = 0x40;
    public const FLAG_ED = 0x80;

    /** DER prefix of an uncompressed P-256 public key (SubjectPublicKeyInfo) up to the 0x04 point marker. */
    private const P256_SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Strict base64url (no padding, no other characters); null when malformed. */
    public static function unb64u(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $s) || strlen($s) % 4 === 1) {
            return null;
        }
        $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $bin === false ? null : $bin;
    }

    /**
     * Parses authenticator data (§6.1).
     * @return array{rp_id_hash:string,flags:int,sign_count:int,aaguid?:string,credential_id?:string,cose_key?:array}
     */
    public static function parseAuthData(string $ad, bool $attested): array
    {
        if (strlen($ad) < 37) {
            throw new InvalidArgumentException('Authenticator data is too short.');
        }
        $out = ['rp_id_hash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'sign_count' => unpack('N', substr($ad, 33, 4))[1]];
        $hasAt = ($out['flags'] & self::FLAG_AT) !== 0;
        if (!$attested) {
            if ($hasAt) {
                throw new InvalidArgumentException('An assertion must not carry a new credential.');
            }
            return $out;
        }
        if (!$hasAt) {
            throw new InvalidArgumentException('The registration carries no credential.');
        }
        if (strlen($ad) < 55) {
            throw new InvalidArgumentException('Credential data is too short.');
        }
        $out['aaguid'] = substr($ad, 37, 16);
        $idLen = unpack('n', substr($ad, 53, 2))[1];
        if ($idLen < 16 || $idLen > 1023 || strlen($ad) < 55 + $idLen) {
            throw new InvalidArgumentException('The credential id has an invalid length.');
        }
        $out['credential_id'] = substr($ad, 55, $idLen);
        $o = 55 + $idLen;
        $key = Cbor::decodePrefix($ad, $o);
        if (!is_array($key)) {
            throw new InvalidArgumentException('The credential public key is not a COSE map.');
        }
        $out['cose_key'] = $key;
        if ($o !== strlen($ad) && ($out['flags'] & self::FLAG_ED) === 0) {
            throw new InvalidArgumentException('Unexpected data after the credential public key.');
        }
        return $out;
    }

    /** COSE EC2 / ES256 / P-256 key → PEM public key. Anything else is refused. */
    public static function coseToPem(array $k): string
    {
        $x = $k[-2] ?? null;
        $y = $k[-3] ?? null;
        if (($k[1] ?? null) !== 2 || ($k[3] ?? null) !== -7 || ($k[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new InvalidArgumentException('Only ES256 (P-256) passkeys are supported.');
        }
        $der = self::P256_SPKI_PREFIX . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $res = openssl_pkey_get_public($pem);
        if ($res === false) {
            throw new InvalidArgumentException('The public key is not a valid P-256 point.');
        }
        $d = openssl_pkey_get_details($res);
        if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($d['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new InvalidArgumentException('The public key is not on the P-256 curve.');
        }
        return $pem;
    }

    /** Checks the client data (§7.1 steps 7–9 / §7.2 steps 11–13). @return string SHA-256 of the raw client data */
    private static function checkClientData(string $clientDataJson, string $type, string $expectedChallenge, string $expectedOrigin): string
    {
        $cd = json_decode($clientDataJson, true);
        if (!is_array($cd)) {
            throw new InvalidArgumentException('The client data is not valid JSON.');
        }
        if (($cd['type'] ?? null) !== $type) {
            throw new InvalidArgumentException('Wrong ceremony type.');
        }
        if (!is_string($cd['challenge'] ?? null) || !hash_equals($expectedChallenge, $cd['challenge'])) {
            throw new InvalidArgumentException('The challenge does not match (expired, reused or forged).');
        }
        if (!is_string($cd['origin'] ?? null) || !hash_equals($expectedOrigin, $cd['origin'])) {
            throw new InvalidArgumentException('The origin does not match this site.');
        }
        if (($cd['crossOrigin'] ?? false) === true) {
            throw new InvalidArgumentException('Cross-origin use is not accepted.');
        }
        return hash('sha256', $clientDataJson, true);
    }

    private static function checkRpAndFlags(array $ad, string $rpId): void
    {
        if (!hash_equals(hash('sha256', $rpId, true), $ad['rp_id_hash'])) {
            throw new InvalidArgumentException('The relying party id does not match this site.');
        }
        if (($ad['flags'] & self::FLAG_UP) === 0) {
            throw new InvalidArgumentException('User presence was not confirmed.');
        }
        if (($ad['flags'] & self::FLAG_UV) === 0) {
            throw new InvalidArgumentException('The device did not verify the user (PIN or biometric).');
        }
    }

    /**
     * Verifies a registration. $in: clientDataJSON and attestationObject, both base64url as sent by the browser.
     * @return array{credential_id:string,public_key_pem:string,sign_count:int,aaguid:string}
     */
    public static function verifyRegistration(array $in, string $expectedChallenge, string $expectedOrigin, string $rpId): array
    {
        $cdj = self::unb64u((string) ($in['clientDataJSON'] ?? ''));
        $att = self::unb64u((string) ($in['attestationObject'] ?? ''));
        if ($cdj === null || $att === null || strlen($cdj) > 4096 || strlen($att) > 8192) {
            throw new InvalidArgumentException('The registration data is malformed.');
        }
        self::checkClientData($cdj, 'webauthn.create', $expectedChallenge, $expectedOrigin);
        $obj = Cbor::decode($att);
        if (!is_array($obj) || ($obj['fmt'] ?? null) !== 'none' || ($obj['attStmt'] ?? null) !== [] || !is_string($obj['authData'] ?? null)) {
            throw new InvalidArgumentException('Only attestation "none" is accepted.');
        }
        $ad = self::parseAuthData($obj['authData'], true);
        self::checkRpAndFlags($ad, $rpId);
        return ['credential_id' => $ad['credential_id'], 'public_key_pem' => self::coseToPem($ad['cose_key']), 'sign_count' => $ad['sign_count'], 'aaguid' => $ad['aaguid']];
    }

    /**
     * Verifies an assertion against a stored key. $in: clientDataJSON, authenticatorData and signature (base64url).
     * @return array{sign_count:int}
     */
    public static function verifyAssertion(array $in, string $expectedChallenge, string $expectedOrigin, string $rpId, string $publicKeyPem, int $storedCount): array
    {
        $cdj = self::unb64u((string) ($in['clientDataJSON'] ?? ''));
        $adRaw = self::unb64u((string) ($in['authenticatorData'] ?? ''));
        $sig = self::unb64u((string) ($in['signature'] ?? ''));
        if ($cdj === null || $adRaw === null || $sig === null || strlen($cdj) > 4096 || strlen($adRaw) > 2048 || strlen($sig) > 512) {
            throw new InvalidArgumentException('The sign-in data is malformed.');
        }
        $hash = self::checkClientData($cdj, 'webauthn.get', $expectedChallenge, $expectedOrigin);
        $ad = self::parseAuthData($adRaw, false);
        self::checkRpAndFlags($ad, $rpId);
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('The stored key cannot be read.');
        }
        if (openssl_verify($adRaw . $hash, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('The signature is not valid.');
        }
        if (($ad['sign_count'] !== 0 || $storedCount !== 0) && $ad['sign_count'] <= $storedCount) {
            throw new InvalidArgumentException('The signature counter did not advance: the passkey may have been cloned.');
        }
        return ['sign_count' => $ad['sign_count']];
    }
}
