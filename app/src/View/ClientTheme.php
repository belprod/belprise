<?php

declare(strict_types=1);

namespace Belprise\View;

/**
 * Client colours, injected as CSS variables in the HTML shell
 * (--client-bg, --client-text, --client-accent).
 */
final class ClientTheme implements Theme
{
    public readonly string $background;
    public readonly string $text;
    public readonly string $accent;

    public function __construct(string $background, string $text, string $accent)
    {
        $this->background = self::color($background);
        $this->text = self::color($text);
        $this->accent = self::color($accent);
    }

    public function css(): string
    {
        return ':root{--client-bg:' . $this->background
            . ';--client-text:' . $this->text
            . ';--client-accent:' . $this->accent
            . ';--client-on-accent:' . $this->onAccent()
            . ';--client-accent-text:' . $this->accentText()
            . ($this->isLight() ? ';--status-ink:35%;color-scheme:light' : ';--status-ink:75%;color-scheme:dark') . '}';
    }

    public function colorScheme(): string
    {
        return $this->isLight() ? 'light' : 'dark';
    }

    /**
     * The accent used as text (timecodes, highlights): moved towards the
     * text colour until it reads at 5:1 on the background (WCAG AA + margin).
     */
    public function accentText(): string
    {
        $bg = self::luminance($this->background);
        for ($share = 100; $share >= 0; $share -= 5) {
            $candidate = self::mix($this->accent, $this->text, $share / 100);
            $l = self::luminance($candidate);
            if ((max($l, $bg) + 0.05) / (min($l, $bg) + 0.05) >= 5.0) {
                return $candidate;
            }
        }

        return $this->text;
    }

    private static function mix(string $a, string $b, float $share): string
    {
        $out = '#';
        for ($i = 1; $i <= 5; $i += 2) {
            $out .= sprintf('%02x', (int) round(hexdec(substr($a, $i, 2)) * $share + hexdec(substr($b, $i, 2)) * (1 - $share)));
        }

        return $out;
    }

    /**
     * Button text on the accent colour: black (the brand default) unless
     * white reads better on a dark accent (WCAG AA).
     */
    public function onAccent(): string
    {
        $accent = self::luminance($this->accent);
        $onBlack = ($accent + 0.05) / 0.05;
        $onWhite = 1.05 / ($accent + 0.05);

        return $onBlack >= 4.5 || $onBlack >= $onWhite ? '#0a0a0b' : '#ffffff';
    }

    /**
     * On a light background the status colours (yellow, green…) are too pale
     * as text: their text is then mixed with the client's text colour.
     */
    public function isLight(): bool
    {
        return self::luminance($this->background) > 0.4;
    }

    /** WCAG relative luminance of "#rrggbb". */
    public static function luminance(string $hex): float
    {
        $channel = static function (string $part): float {
            $c = hexdec($part) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    }

    /** Only #rrggbb is accepted: the value is written into a <style> block. */
    private static function color(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid colour: expected #rrggbb.');
        }

        return strtolower($value);
    }
}
