<?php

declare(strict_types=1);

namespace Belprise\Mail;

/**
 * PHP mail() through the host's mail server (no library). Headers are
 * built here only; addresses and subject never carry line breaks.
 */
final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $from)
    {
    }

    public function send(Message $message): bool
    {
        if (!self::isSafe($message->to) || !self::isSafe($this->from) || filter_var($message->to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $headers = [
            'From' => $this->from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'Auto-Submitted' => 'auto-generated',
        ];
        $subject = mb_encode_mimeheader(str_replace(["\r", "\n"], ' ', $message->subject), 'UTF-8', 'Q');

        // Envelope sender (Return-Path) on the From domain: SPF and DKIM then align
        // with it, as DMARC requires; otherwise the host puts its own address.
        $envelope = self::address($this->from);

        return $envelope === null
            ? @mail($message->to, $subject, str_replace("\r\n", "\n", $message->text), $headers)
            : @mail($message->to, $subject, str_replace("\r\n", "\n", $message->text), $headers, '-f' . $envelope);
    }

    /** "belprise <no-reply@belprise.fr>" → "no-reply@belprise.fr"; null when it is not a plain, safe address. */
    public static function address(string $from): ?string
    {
        $address = preg_match('/<([^<>]+)>\s*$/', $from, $m) === 1 ? $m[1] : trim($from);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+$/D', $address) !== 1) {
            return null;
        }

        return $address;
    }

    private static function isSafe(string $value): bool
    {
        return !preg_match('/[\r\n\0]/', $value);
    }
}
