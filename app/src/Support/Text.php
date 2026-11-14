<?php

declare(strict_types=1);

namespace Belprise\Support;

final class Text
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        '’' => "'", '‘' => "'",
    ];

    /** Lower case, without accents: for searches. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        $needle = trim(self::fold($needle));

        return $needle === '' || str_contains(self::fold($haystack), $needle);
    }
}
