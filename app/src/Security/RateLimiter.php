<?php

declare(strict_types=1);

namespace Belprise\Security;

use Closure;
use PDO;

/**
 * Failed-attempt throttling: after the n-th consecutive failure, the next
 * attempt is refused for 2^(n-1) seconds (1, 2, 4… capped at 15 min).
 * Failures older than a day are forgotten.
 */
final class RateLimiter
{
    public const MAX_DELAY = 900;
    private const FORGET_AFTER = 86400;

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now */
    public function __construct(
        private readonly PDO $db,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public static function delayFor(int $failures): int
    {
        if ($failures <= 0) {
            return 0;
        }

        return $failures > 10 ? self::MAX_DELAY : min(2 ** ($failures - 1), self::MAX_DELAY);
    }

    /** Seconds before a new attempt is allowed (0 = allowed now). */
    public function retryAfter(string $scope, string $keyHash): int
    {
        $row = $this->row($scope, $keyHash);

        return $row === null ? 0 : max(0, $row['blocked_until'] - ($this->now)());
    }

    /**
     * Claims an attempt, atomically: refused (returns the wait) while the key
     * is blocked; otherwise counted as a failure right away (returns 0), so
     * that parallel requests cannot all get through. A success then calls
     * clear().
     */
    public function attempt(string $scope, string $keyHash): int
    {
        if ($this->record($scope, $keyHash, true)) {
            return 0;
        }

        return max(1, $this->retryAfter($scope, $keyHash));
    }

    /** Records a failure; returns the delay now imposed. */
    public function fail(string $scope, string $keyHash): int
    {
        $this->record($scope, $keyHash, false);

        return self::delayFor($this->row($scope, $keyHash)['failures'] ?? 0);
    }

    /**
     * After a failed check (the attempt was already counted by attempt()):
     * the delay starts again from now, so a slow password check does not
     * eat into it. Returns the delay imposed.
     */
    public function failed(string $scope, string $keyHash): int
    {
        $delay = self::delayFor($this->row($scope, $keyHash)['failures'] ?? 0);
        $this->db->prepare('UPDATE login_attempts SET blocked_until = MAX(blocked_until, ?) WHERE scope = ? AND key_hash = ?')
            ->execute([($this->now)() + $delay, $scope, $keyHash]);

        return $delay;
    }

    /**
     * One UPSERT statement, so concurrent failures are never lost.
     *
     * @return bool false when $onlyIfAllowed and the key is still blocked
     */
    private function record(string $scope, string $keyHash, bool $onlyIfAllowed): bool
    {
        $failures = 'CASE WHEN last_failure_at < :forget THEN 1 ELSE failures + 1 END';
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (scope, key_hash, failures, last_failure_at, blocked_until)
             VALUES (:scope, :key, 1, :now, :now + 1)
             ON CONFLICT (scope, key_hash) DO UPDATE SET
                failures = ' . $failures . ',
                last_failure_at = :now,
                blocked_until = :now + CASE WHEN ' . $failures . ' > 10 THEN ' . self::MAX_DELAY
                . ' ELSE 1 << (' . $failures . ' - 1) END'
            . ($onlyIfAllowed ? ' WHERE blocked_until <= :now' : '')
        );
        $now = ($this->now)();
        $stmt->execute(['scope' => $scope, 'key' => $keyHash, 'now' => $now, 'forget' => $now - self::FORGET_AFTER]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Fixed window: at most $limit requests per $window seconds (e.g. e-mails
     * sent on request). Counts this request; returns the seconds to wait when
     * over the limit, 0 when allowed.
     */
    public function hit(string $scope, string $keyHash, int $limit, int $window): int
    {
        $now = ($this->now)();
        $this->db->prepare(
            'INSERT INTO login_attempts (scope, key_hash, failures, last_failure_at, blocked_until)
             VALUES (:scope, :key, 1, :now, 0)
             ON CONFLICT (scope, key_hash) DO UPDATE SET
                failures = CASE WHEN last_failure_at <= :start THEN 1 ELSE failures + 1 END,
                last_failure_at = CASE WHEN last_failure_at <= :start THEN :now ELSE last_failure_at END'
        )->execute(['scope' => $scope, 'key' => $keyHash, 'now' => $now, 'start' => $now - $window]);
        $row = $this->row($scope, $keyHash);
        if ($row === null || $row['failures'] <= $limit) {
            return 0;
        }

        return max(1, $row['last_failure_at'] + $window - $now);
    }

    public function clear(string $scope, string $keyHash): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?')
            ->execute([$scope, $keyHash]);
    }

    /**
     * @return array{failures: int, blocked_until: int, last_failure_at: int}|null
     */
    private function row(string $scope, string $keyHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT failures, blocked_until, last_failure_at FROM login_attempts WHERE scope = ? AND key_hash = ?'
        );
        $stmt->execute([$scope, $keyHash]);
        $row = $stmt->fetch();
        if ($row === false || $row['last_failure_at'] < ($this->now)() - self::FORGET_AFTER) {
            return null;
        }

        return ['failures' => (int) $row['failures'], 'blocked_until' => (int) $row['blocked_until'], 'last_failure_at' => (int) $row['last_failure_at']];
    }
}
