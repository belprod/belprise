<?php

declare(strict_types=1);

namespace Belprise\Mail;

/** Plain-text e-mail in French (CAHIER_DES_CHARGES §5.14): no remote image, no tracking. */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
    ) {
    }
}
