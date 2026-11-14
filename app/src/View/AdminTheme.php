<?php

declare(strict_types=1);

namespace Belprise\View;

/**
 * Visual identity of an organization in its back office (CAHIER_DES_CHARGES
 * §5.7, §8): an accent colour and a dark or light background. Everything
 * derived from them is computed here, once, for the shell (no flash on
 * load) and for the front end (after signing in):
 * - the text on accent buttons (black or white, whichever reads, AA);
 * - the accent used as text (moved towards the text colour until 5:1);
 * - on the light background, the palette and status colours as ink.
 */
final class AdminTheme implements Theme
{
    public const DEFAULT_ACCENT = '#e8515f';
    public const THEMES = ['dark', 'light'];

    /** Accent against the background: non-text contrast (WCAG 1.4.11) for focus rings, borders, switches. */
    public const MIN_ACCENT_CONTRAST = 3.0;

    private const DARK = [
        '--bg' => '#0a0a0b',
        '--text' => '#fafafa',
    ];

    private const LIGHT = [
        '--bg' => '#f4f4f5',
        '--surface-1' => '#ffffff',
        '--surface-2' => '#ffffff',
        '--surface-3' => '#e9e9ec',
        '--border' => '#dcdce0',
        '--border-strong' => '#c2c2c8',
        '--text' => '#141416',
        '--text-muted' => '#55555c',
        '--text-soft' => '#2a2a2e',
        // Status colours are made for a dark background: as text they are mixed with the ink.
        '--status-ink' => '42%',
    ];

    /** Surfaces the accent may be written on (panels, hovered rows, the review's raised blocks). */
    private const SURFACES = [
        'dark' => ['#0f0f11', '#141416', '#18181b'],
        'light' => ['#ffffff', '#e9e9ec', '#e0e0e3'],
    ];

    public readonly string $accent;
    public readonly string $theme;

    public function __construct(string $accent = self::DEFAULT_ACCENT, string $theme = 'dark')
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $accent) !== 1) {
            throw new \InvalidArgumentException('Invalid colour: expected #rrggbb.');
        }
        $this->accent = strtolower($accent);
        $this->theme = in_array($theme, self::THEMES, true) ? $theme : 'dark';
    }

    public function background(): string
    {
        return $this->theme === 'light' ? self::LIGHT['--bg'] : self::DARK['--bg'];
    }

    public function text(): string
    {
        return $this->theme === 'light' ? self::LIGHT['--text'] : self::DARK['--text'];
    }

    /** Contrast of the accent against the background (≥ 3:1 required). */
    public function accentContrast(): float
    {
        return self::ratio($this->accent, $this->background());
    }

    /**
     * CSS variables of this theme (the default dark theme with the default accent gives none).
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $vars = $this->theme === 'light' ? self::LIGHT : [];
        if ($this->theme === 'light' || $this->accent !== self::DEFAULT_ACCENT) {
            $vars['--accent'] = $this->accent;
            $vars['--on-accent'] = $this->onAccent();
            $vars['--accent-text'] = $this->accentText();
        }
        if ($vars !== []) {
            $vars['color-scheme'] = $this->colorScheme();
        }

        return $vars;
    }

    public function css(): string
    {
        $rules = [];
        foreach ($this->variables() as $name => $value) {
            $rules[] = $name . ':' . $value;
        }

        return $rules === [] ? '' : ':root{' . implode(';', $rules) . '}';
    }

    public function colorScheme(): string
    {
        return $this->theme;
    }

    /** Black (the brand default) unless white reads better on this accent. */
    public function onAccent(): string
    {
        return self::ratio($this->accent, '#0a0a0b') >= 4.5 || self::ratio($this->accent, '#0a0a0b') >= self::ratio($this->accent, '#ffffff')
            ? '#0a0a0b' : '#ffffff';
    }

    /**
     * The accent as text: mixed towards the text colour until it reads at
     * 5:1 on the background and at 4.5:1 (AA) on every surface.
     */
    public function accentText(): string
    {
        for ($share = 100; $share >= 0; $share -= 5) {
            $candidate = self::mix($this->accent, $this->text(), $share / 100);
            if (self::ratio($candidate, $this->background()) < 5.0) {
                continue;
            }
            foreach (self::SURFACES[$this->theme] as $surface) {
                if (self::ratio($candidate, $surface) < 4.5) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return $this->text();
    }

    public static function ratio(string $a, string $b): float
    {
        $la = ClientTheme::luminance($a);
        $lb = ClientTheme::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function mix(string $a, string $b, float $share): string
    {
        $out = '#';
        for ($i = 1; $i <= 5; $i += 2) {
            $out .= sprintf('%02x', (int) round(hexdec(substr($a, $i, 2)) * $share + hexdec(substr($b, $i, 2)) * (1 - $share)));
        }

        return $out;
    }
}
