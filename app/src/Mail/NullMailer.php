<?php

declare(strict_types=1);

namespace Belprise\Mail;

/** The public demo sends no e-mail (§5.16): every message is dropped. */
final class NullMailer implements Mailer
{
    public function send(Message $message): bool
    {
        return true;
    }
}
