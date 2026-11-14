<?php

declare(strict_types=1);

namespace Belprise\Security;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30 s, 6 digits),
 * compatible with any authenticator app. No external service.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 160-bit secret, base32 (what the app stores). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, int $time, int $digits = self::DIGITS): string
    {
        return self::codeAt(self::base32Decode($secret), intdiv($time, self::PERIOD), $digits);
    }

    /**
     * Accepts the code of the current period or its neighbours (clock drift),
     * never a period already used (replay).
     *
     * @return int|null the period that matched
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        $now = intdiv($time, self::PERIOD);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($lastUsedStep === null || $step > $lastUsedStep) && hash_equals(self::codeAt($key, $step, self::DIGITS), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** otpauth:// URI shown as a QR code. */
    public static function uri(string $secret, string $account, string $issuer = 'Belprise'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function codeAt(string $key, int $step, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % (10 ** $digits);

        return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid base32.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
