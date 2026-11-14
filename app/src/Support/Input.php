<?php

declare(strict_types=1);

namespace Belprise\Support;

use Belprise\Http\HttpException;

/**
 * Typed access to a decoded request body. Validation errors are 422
 * responses with a French message and the offending field name.
 */
final class Input
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, string $label, int $maxLength = 200): string
    {
        $value = $this->data[$key] ?? '';
        if (!is_string($value)) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }
        $value = trim($value);
        if ($value === '') {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est obligatoire.');
        }
        if (mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » ne doit pas dépasser ' . $maxLength . ' caractères.');
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }

        return $value;
    }

    /** Free text that may be empty (line breaks allowed). */
    public function text(string $key, string $label, int $maxLength = 2000): string
    {
        $value = $this->data[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value) > $maxLength) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » ne doit pas dépasser ' . $maxLength . ' caractères.');
        }

        return $value;
    }

    public function bool(string $key, string $label): bool
    {
        $value = $this->data[$key] ?? null;
        if (!is_bool($value)) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }

        return $value;
    }

    public function int(string $key, string $label): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est obligatoire.');
        }

        return $value;
    }

    public function intRange(string $key, string $label, int $min, int $max): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid($key, 'La valeur « ' . $label . ' » est invalide.');
        }

        return $value;
    }

    /** Non-negative integer, or null when absent or null. */
    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw self::invalid($key, 'Valeur invalide.');
        }

        return $value;
    }

    /** Nested object, as another Input. */
    public function object(string $key, string $label): self
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }

        return new self($value);
    }

    /** "#rrggbb", returned in lower case. */
    public function color(string $key, string $label): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
            throw self::invalid($key, 'La couleur « ' . $label . ' » doit être au format #rrggbb.');
        }

        return strtolower($value);
    }

    /**
     * Calendar date "YYYY-MM-DD", or null when absent, null or empty.
     */
    public function date(string $key, string $label): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw self::invalid($key, 'La date « ' . $label . ' » est invalide.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key, string $label, int $maxItems, int $maxLength): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || !mb_check_encoding($item, 'UTF-8')) {
                throw self::invalid($key, 'Le champ « ' . $label . ' » est invalide.');
            }
            $item = trim(preg_replace('/\s+/u', ' ', $item) ?? '');
            if ($item === '') {
                continue;
            }
            if (mb_strlen($item) > $maxLength) {
                throw self::invalid($key, 'Chaque élément de « ' . $label . ' » doit faire au plus ' . $maxLength . ' caractères.');
            }
            $out[mb_strtolower($item)] ??= $item;
        }

        return array_values($out);
    }

    private static function invalid(string $field, string $message): HttpException
    {
        return new HttpException(422, $message, ['field' => $field]);
    }
}
