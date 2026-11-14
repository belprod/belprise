<?php

declare(strict_types=1);

namespace Belprise\Mail;

/** Keeps the messages instead of sending them (tests, demo). */
final class Outbox implements Mailer
{
    /** @var list<Message> */
    public array $messages = [];

    public function __construct(private readonly bool $accept = true)
    {
    }

    public function send(Message $message): bool
    {
        $this->messages[] = $message;

        return $this->accept;
    }
}
