<?php

declare(strict_types=1);

namespace Belprise\Review;

/**
 * One line saying what happened (CAHIER_DES_CHARGES §5.7): « V3 importée ·
 * 2 révisions de Claire · V2 validée par Marc ». Events come newest first;
 * the most recent kind of event comes first in the line.
 */
final class ActivitySummary
{
    /** A status set by the team: publishing is « V2 publiée », and so on. */
    private const STATUS_LABELS = [
        'in_progress' => ' dépubliée',
        'to_review' => ' publiée',
        'changes_requested' => ' : modifications demandées',
        'approved' => ' validée',
    ];

    /**
     * @param list<array<string, mixed>> $events newest first
     */
    public static function line(array $events, int $maxParts = 3): string
    {
        $parts = [];
        $versions = [];
        $revisions = [];
        $replies = [];
        $teamReplies = 0;
        foreach ($events as $event) {
            $type = (string) $event['type'];
            $actor = trim((string) $event['actor']);
            $admin = (int) $event['is_admin'] === 1;
            $key = match (true) {
                $type === 'version' => 'version',
                $type === 'revision' && !$admin => 'revision',
                $type === 'reply' && !$admin => 'reply',
                $type === 'revision' || $type === 'reply' => 'team',
                default => $type,
            };
            match ($key) {
                'version' => $versions[] = (int) $event['version_number'],
                'revision' => $revisions[] = $actor,
                'reply' => $replies[] = $actor,
                'team' => $teamReplies++,
                default => null,
            };
            if (!isset($parts[$key]) && ($key !== 'approved' && $key !== 'status' || !isset($parts['approved']) && !isset($parts['status']))) {
                // Only the latest validation or status change is told.
                $parts[$key] = $event;
            }
        }

        $words = [];
        foreach ($parts as $key => $event) {
            $words[] = match ($key) {
                'version' => self::versions($versions),
                'revision' => self::people(count($revisions), 'révision', $revisions),
                'reply' => self::people(count($replies), 'réponse', $replies),
                'team' => $teamReplies . ' ' . ($teamReplies > 1 ? 'réponses envoyées' : 'réponse envoyée'),
                'approved' => 'V' . (int) $event['version_number'] . ' validée' . (trim((string) $event['actor']) === '' ? '' : ' par ' . trim((string) $event['actor'])),
                'status' => 'V' . (int) $event['version_number'] . (self::STATUS_LABELS[(string) $event['details']] ?? ' : ' . (string) $event['details']),
                default => '',
            };
        }

        return implode(' · ', array_slice(array_filter($words), 0, $maxParts));
    }

    /**
     * @param list<int> $numbers
     */
    private static function versions(array $numbers): string
    {
        $numbers = array_values(array_unique($numbers));
        sort($numbers);
        if (count($numbers) === 1) {
            return 'V' . $numbers[0] . ' importée';
        }
        $labels = array_map(static fn (int $n) => 'V' . $n, $numbers);
        $last = array_pop($labels);

        return implode(', ', $labels) . ' et ' . $last . ' importées';
    }

    /**
     * @param list<string> $names
     */
    private static function people(int $count, string $noun, array $names): string
    {
        $names = array_values(array_unique(array_filter($names)));
        $text = $count . ' ' . $noun . ($count > 1 ? 's' : '');
        if ($names === []) {
            return $text;
        }
        if (count($names) > 2) {
            return $text . ' (' . count($names) . ' personnes)';
        }

        // « d’Inès », « de Claire ».
        $elided = preg_match('/^[aeiouyàâäéèêëîïôöùûü]/iu', $names[0]) === 1;

        return $text . ($elided ? ' d’' : ' de ') . implode(' et ', $names);
    }
}
