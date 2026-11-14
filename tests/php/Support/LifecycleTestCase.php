<?php

declare(strict_types=1);

namespace Belprise\Tests\Support;

use Belprise\Http\Response;
use Belprise\Repository\Superadmins;
use Belprise\Security\SecretBox;
use Belprise\Security\Totp;
use DateTimeImmutable;
use DateTimeZone;

/** Downgrades and deletions: the operator, the administrator and a simulated clock. */
abstract class LifecycleTestCase extends AppTestCase
{
    protected const SUPER_EMAIL = 'exploitation@belprise.fr';
    protected const SUPER_PASSWORD = 'mot-de-passe-exploitant';
    protected const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'timecodeSource' => 'tmcd',
    ];

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = Totp::generateSecret();
        $this->loginAdmin();
        (new Superadmins($this->db()))->create(
            self::SUPER_EMAIL,
            'Exploitant',
            password_hash(self::SUPER_PASSWORD, PASSWORD_ARGON2ID),
            (new SecretBox(str_repeat('s', 64), 'totp'))->seal($this->secret),
            0,
        );
    }

    protected function asOperator(): void
    {
        $this->session->data = [];
        $this->db()->exec('DELETE FROM login_attempts');
        $this->db()->exec('UPDATE superadmins SET totp_last_step = 0'); // a code is single-use: the same one again
        $csrf = (string) $this->decode($this->request('GET', '/api/superadmin/session'))['csrf'];
        $this->operator('POST', '/api/superadmin/login', ['email' => self::SUPER_EMAIL, 'password' => self::SUPER_PASSWORD], $csrf);
        $state = $this->decode($this->operator('POST', '/api/superadmin/login/totp', ['code' => Totp::code($this->secret, time())], $csrf));
        self::assertTrue($state['authenticated']);
    }

    /** @param array<string, mixed>|null $data */
    protected function operator(string $method, string $path, ?array $data = null, ?string $csrf = null): Response
    {
        return $this->request($method, $path, [
            'X-CSRF-Token' => $csrf ?? (string) ($this->session->data['csrf'] ?? ''), 'Content-Type' => 'application/json',
        ], $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected function asAdmin(string $email = self::ADMIN_EMAIL, string $password = self::ADMIN_PASSWORD): Response
    {
        $this->session->data = [];
        $this->db()->exec('DELETE FROM login_attempts');

        return $this->postJson('/api/admin/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * A published version whose master weighs $bytes (a sparse file: nothing is written on the disk).
     *
     * @return array<string, mixed>
     */
    protected function versionOf(int $videoId, int $bytes): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        copy(__DIR__ . '/../../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));
        $version = $this->attach(['name' => $name, 'videoId' => $videoId, 'meta' => self::META], 201);
        $version = $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => 'to_review']);
        $path = (string) $this->db()->query('SELECT file_path FROM versions WHERE id = ' . (int) $version['id'])->fetchColumn();
        $handle = fopen($this->tmp . '/data/' . $path, 'r+');
        self::assertNotFalse($handle);
        ftruncate($handle, $bytes);
        fclose($handle);

        return $version;
    }

    protected static function at(string $utc, string $modify = '+0 seconds'): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify($modify);
    }

    /** @return list<string> */
    protected function subjects(): array
    {
        return array_map(static fn ($m) => $m->subject, $this->outbox->messages);
    }
}
