<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Review\ActivitySummary;
use PHPUnit\Framework\TestCase;

final class ActivitySummaryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function e(string $type, int $version = 1, string $actor = '', bool $admin = false, string $details = ''): array
    {
        return ['type' => $type, 'version_number' => $version, 'actor' => $actor, 'is_admin' => $admin ? 1 : 0, 'details' => $details];
    }

    public function testTheLatestKindOfEventComesFirst(): void
    {
        self::assertSame('V3 importée', ActivitySummary::line([self::e('version', 3)]));
        self::assertSame('2 révisions de Claire · V2 importée', ActivitySummary::line([self::e('revision', 2, 'Claire'), self::e('revision', 2, 'Claire'), self::e('version', 2)]));
        self::assertSame('V2 validée par Marc · 1 réponse de Claire', ActivitySummary::line([self::e('approved', 2, 'Marc'), self::e('reply', 2, 'Claire')]));
    }

    public function testPeopleAndVersionsAreGrouped(): void
    {
        self::assertSame('3 révisions de Claire et Marc', ActivitySummary::line([self::e('revision', 1, 'Claire'), self::e('revision', 1, 'Marc'), self::e('revision', 1, 'Claire')]));
        self::assertSame('2 révisions d’Inès et Julien', ActivitySummary::line([self::e('revision', 1, 'Inès'), self::e('revision', 1, 'Julien')]));
        self::assertSame('3 révisions (3 personnes)', ActivitySummary::line([self::e('revision', 1, 'A'), self::e('revision', 1, 'B'), self::e('revision', 1, 'C')]));
        self::assertSame('V2 et V3 importées', ActivitySummary::line([self::e('version', 3), self::e('version', 2)]));
        self::assertSame('V1, V2 et V3 importées', ActivitySummary::line([self::e('version', 3), self::e('version', 1), self::e('version', 2)]));
    }

    public function testTeamRepliesAndStatusesAreToldOnce(): void
    {
        self::assertSame('2 réponses envoyées', ActivitySummary::line([self::e('reply', 1, 'belprod', true), self::e('reply', 1, 'belprod', true)]));
        self::assertSame('V1 : modifications demandées', ActivitySummary::line([self::e('status', 1, 'Jules', true, 'changes_requested'), self::e('approved', 1, 'Claire')]));
        self::assertSame('V2 publiée · V2 importée', ActivitySummary::line([self::e('status', 2, 'Jules', true, 'to_review'), self::e('version', 2)]));
        self::assertSame('V1 importée · 1 révision de Paul', ActivitySummary::line([self::e('version'), self::e('revision', 1, 'Paul'), self::e('reply', 1, 'B'), self::e('approved')], 2));
        self::assertSame('', ActivitySummary::line([]));
    }
}
