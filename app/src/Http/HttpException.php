<?php

declare(strict_types=1);

namespace Belprise\Http;

/**
 * An expected HTTP error. The message is shown to the user (French).
 * A 404 is always rendered as the neutral page, whatever the message.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $extra additional JSON fields
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $extra = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(): self
    {
        return new self(404);
    }
}
