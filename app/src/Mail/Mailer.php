<?php

declare(strict_types=1);

namespace Belprise\Mail;

/** Sends one e-mail; false when the server refused it. */
interface Mailer
{
    public function send(Message $message): bool;
}
