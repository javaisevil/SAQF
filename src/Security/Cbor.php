<?php
declare(strict_types=1);

namespace Saqf\Security;

use InvalidArgumentException;

/**
 * A deliberately small, strict CBOR decoder: only what a WebAuthn attestation object and a COSE public key
 * contain (unsigned and negative integers, byte and text strings, arrays, maps, booleans, null).
 * It refuses everything else (tags, floats, indefinite lengths, duplicate map keys, oversized or deeply
 * nested input) rather than interpreting it, and it never reads past the end of its input.
 * Hand-written parsers of untrusted input are risky: this one is kept tiny, and tests/passkey_test.php feeds
 * it hostile and truncated data. It has not been independently reviewed.
 */
final class Cbor
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 256;

    /** Decodes exactly one item; trailing bytes are an error. */
    public static function decode(string $data)
    {
        $o = 0;
        $v = self::item($data, $o, 0);
        if ($o !== strlen($data)) {
            throw new InvalidArgumentException('Unexpected data after the CBOR item.');
        }
        return $v;
    }

    /** Decodes one item from $offset and advances it (a COSE key inside authenticator data is followed by other bytes). */
    public static function decodePrefix(string $data, int &$offset)
    {
        return self::item($data, $offset, 0);
    }

    private static function take(string $d, int &$o, int $n): string
    {
        if ($n < 0 || $o + $n > strlen($d)) {
            throw new InvalidArgumentException('CBOR data ends too early.');
        }
        $s = substr($d, $o, $n);
        $o += $n;
        return $s;
    }

    private static function item(string $d, int &$o, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('CBOR is nested too deeply.');
        }
        $b = ord(self::take($d, $o, 1));
        $major = $b >> 5;
        $ai = $b & 31;
        if ($major === 7) {
            switch ($ai) {
                case 20:
                    return false;
                case 21:
                    return true;
                case 22:
                    return null;
            }
            throw new InvalidArgumentException('Unsupported CBOR simple value.');
        }
        if ($major === 6) {
            throw new InvalidArgumentException('CBOR tags are not accepted.');
        }
        if ($ai < 24) {
            $arg = $ai;
        } elseif ($ai === 24) {
            $arg = ord(self::take($d, $o, 1));
        } elseif ($ai === 25) {
            $arg = unpack('n', self::take($d, $o, 2))[1];
        } elseif ($ai === 26) {
            $arg = unpack('N', self::take($d, $o, 4))[1];
        } elseif ($ai === 27) {
            $hi = unpack('N', self::take($d, $o, 4))[1];
            $lo = unpack('N', self::take($d, $o, 4))[1];
            if ($hi > 0x7FFFFFFF) {
                throw new InvalidArgumentException('CBOR integer too large.');
            }
            $arg = ($hi << 32) | $lo;
        } else {
            throw new InvalidArgumentException('Indefinite or reserved CBOR lengths are not accepted.');
        }
        switch ($major) {
            case 0:
                return $arg;
            case 1:
                return -1 - $arg;
            case 2:
                return self::take($d, $o, $arg);
            case 3:
                $s = self::take($d, $o, $arg);
                if (!mb_check_encoding($s, 'UTF-8')) {
                    throw new InvalidArgumentException('CBOR text is not valid UTF-8.');
                }
                return $s;
            case 4:
                if ($arg > self::MAX_ITEMS) {
                    throw new InvalidArgumentException('CBOR array too long.');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    $out[] = self::item($d, $o, $depth + 1);
                }
                return $out;
            case 5:
                if ($arg > self::MAX_ITEMS) {
                    throw new InvalidArgumentException('CBOR map too large.');
                }
                $out = [];
                for ($i = 0; $i < $arg; $i++) {
                    // Keys are integers or TEXT only: a byte string that happens to spell a key must not pass as one.
                    if ($o >= strlen($d) || !in_array(ord($d[$o]) >> 5, [0, 1, 3], true)) {
                        throw new InvalidArgumentException('CBOR map keys must be integers or text.');
                    }
                    $k = self::item($d, $o, $depth + 1);
                    if (array_key_exists($k, $out)) {
                        throw new InvalidArgumentException('Duplicate CBOR map key.');
                    }
                    $out[$k] = self::item($d, $o, $depth + 1);
                }
                return $out;
        }
        throw new InvalidArgumentException('Unsupported CBOR item.');
    }
}
