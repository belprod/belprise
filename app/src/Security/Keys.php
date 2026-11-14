<?php

declare(strict_types=1);

namespace Belprise\Security;

/**
 * Link keys: 10 lower-case letters, without the ambiguous "l" and "o"
 * (§4). A key never starts with a reserved path prefix.
 */
final class Keys
{
    public const LENGTH = 10;
    public const ALPHABET = 'abcdefghijkmnpqrstuvwxyz';
    public const RESERVED_PREFIXES = ['admin', 'superadmin', 'v', 'api', 'media', 'assets'];

    /** Characters allowed in a key, for regular expressions. */
    public const CHAR_CLASS = '[a-km-np-z]';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        do {
            $key = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $key .= self::ALPHABET[random_int(0, $max)];
            }
        } while (self::isReserved($key));

        return $key;
    }

    public static function isValid(string $key): bool
    {
        return preg_match('/^' . self::CHAR_CLASS . '{' . self::LENGTH . '}$/D', $key) === 1 && !self::isReserved($key);
    }

    public static function isReserved(string $key): bool
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits "/v/{n°}{clé}" at the first letter (§4).
     *
     * @return array{number: int, key: string}|null
     */
    public static function parseVideoRef(string $ref): ?array
    {
        if (preg_match('/^([1-9][0-9]{3,8})([a-z]+)$/D', $ref, $m) !== 1 || !self::isValid($m[2])) {
            return null;
        }

        return ['number' => (int) $m[1], 'key' => $m[2]];
    }
}
