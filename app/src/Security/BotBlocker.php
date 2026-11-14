<?php

declare(strict_types=1);

namespace Belprise\Security;

/**
 * Refuses known crawlers, AI agents and link-preview bots (403).
 * The same list is in public/.htaccess and public/robots.txt (kept in sync
 * by a test).
 */
final class BotBlocker
{
    /** User-agent substrings, matched case-insensitively. */
    public const DEFAULT_PATTERNS = [
        // AI crawlers and agents
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
        'ClaudeBot', 'Claude-Web', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
        'CCBot', 'PerplexityBot', 'Perplexity-User', 'Bytespider',
        'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'Amazonbot', 'Applebot', 'Applebot-Extended',
        'Google-Extended', 'Google-CloudVertexBot', 'cohere-ai', 'Diffbot', 'YouBot',
        'MistralAI-User', 'DuckAssistBot', 'AI2Bot', 'Timpibot', 'ImagesiftBot', 'Omgilibot',
        // Search engines
        'Googlebot', 'bingbot', 'YandexBot', 'Baiduspider', 'DuckDuckBot', 'PetalBot',
        'SemrushBot', 'AhrefsBot',
        // Link previews
        'facebookexternalhit', 'Twitterbot', 'Slackbot', 'LinkedInBot', 'Discordbot',
        'TelegramBot', 'WhatsApp',
    ];

    /** @param list<string> $patterns */
    public function __construct(private readonly array $patterns = self::DEFAULT_PATTERNS)
    {
    }

    public function isBlocked(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        foreach ($this->patterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
