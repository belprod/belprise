<?php

declare(strict_types=1);

namespace Belprise\Repository;

use PDO;

/**
 * Two-factor recovery codes (CAHIER_DES_CHARGES §5.7, §5.10): ten codes shown
 * once when two-factor authentication is turned on; each opens one session
 * in place of the authenticator code. Stored hashed, single use.
 */
final class RecoveryCodes
{
    public const COUNT = 10;
    /** No 0/o, 1/l/i: read aloud or typed from paper without doubt. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * New set of codes; the previous ones stop working.
     *
     * @return list<string> the codes in clear ("abcde-fghjk"), to show once
     */
    public function replace(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        $this->delete($userId);
        $insert = $this->db->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $code) {
            $insert->execute([$userId, self::hash($code)]);
        }

        return $codes;
    }

    /** Uses up a code: true only once, even for two requests at the same time. */
    public function consume(int $userId, string $code): bool
    {
        $stmt = $this->db->prepare("UPDATE recovery_codes SET used_at = datetime('now') WHERE user_id = ? AND code_hash = ? AND used_at IS NULL");
        $stmt->execute([$userId, self::hash($code)]);

        return $stmt->rowCount() === 1;
    }

    public function remaining(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $userId): void
    {
        $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
    }

    /** Looks like a recovery code (10 characters), not a 6-digit authenticator code? */
    public static function looksLikeCode(string $input): bool
    {
        return preg_match('/^[a-z0-9]{5}-?[a-z0-9]{5}$/D', self::normalize($input)) === 1;
    }

    private static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/\s+/', '', $code));
    }

    private static function hash(string $code): string
    {
        $plain = str_replace('-', '', self::normalize($code));

        return hash('sha256', 'recovery:' . $plain);
    }
}
