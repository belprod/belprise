<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Mail\NativeMailer;
use PHPUnit\Framework\TestCase;

/** The envelope sender passed to mail() (-f): only a plain address, never anything a shell could read. */
final class NativeMailerTest extends TestCase
{
    public function testTheEnvelopeSenderIsTheFromAddress(): void
    {
        self::assertSame('no-reply@belprise.fr', NativeMailer::address('belprise <no-reply@belprise.fr>'));
        self::assertSame('no-reply@belprise.fr', NativeMailer::address('no-reply@belprise.fr'));
        self::assertSame('contact+beta@belprise.fr', NativeMailer::address('"Belprise" <contact+beta@belprise.fr>'));
    }

    public function testAnythingElseGivesNoEnvelopeSender(): void
    {
        foreach (['belprise', 'x <pas une adresse>', 'a@b.fr -X/tmp/log', "a@b.fr\n-X", 'x <a@b.fr -oQ/tmp>', 'x <"a b"@b.fr>'] as $from) {
            self::assertNull(NativeMailer::address($from), $from);
        }
    }
}
