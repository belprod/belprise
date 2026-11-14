<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Export\MarkerEdl;
use Belprise\Security\AdminAuth;
use Belprise\Security\Totp;
use Belprise\Tests\Support\AppTestCase;

/**
 * Phase 6: EDL markers (§5.6), dashboard, search, settings and TOTP (§5.7).
 */
final class PilotageTest extends AppTestCase
{
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 1500, 'timecodeSource' => 'tmcd',
    ];

    // ---- EDL ----------------------------------------------------------------------------

    public function testEdlMatchesTheReferenceFile(): void
    {
        $edl = MarkerEdl::build('1042 Film manifeste 60 s V3', 25, 1, false, 90000, [
            ['frameIn' => 110, 'frameOut' => null, 'author' => 'Claire Martin', 'text' => 'Le logo d’ouverture arrive un peu tôt, on peut le décaler ?', 'status' => 'todo'],
            ['frameIn' => 525, 'frameOut' => 687, 'author' => 'Thomas Roux', 'text' => "Sur cette séquence l’étalonnage est plus froid.\nOn aimerait retrouver la chaleur du début.", 'status' => 'todo'],
            ['frameIn' => 1155, 'frameOut' => null, 'author' => 'Claire Martin', 'text' => 'Parfait pour le texte de fin | merci !', 'status' => 'done'],
        ]);

        self::assertSame((string) file_get_contents(__DIR__ . '/../fixtures/edl/1042_Film_manifeste_V3_marqueurs.edl'), $edl);
    }

    public function testDropFrameEdlMatchesTheReferenceFile(): void
    {
        $edl = MarkerEdl::build('1043 Captation V1', 30000, 1001, true, 107862, [
            ['frameIn' => 29, 'frameOut' => null, 'author' => 'Inès', 'text' => 'Coupe ici', 'status' => 'todo'],
            ['frameIn' => 1829, 'frameOut' => 1830, 'author' => 'Inès', 'text' => 'Plage qui franchit la minute', 'status' => 'done'],
        ]);

        self::assertSame((string) file_get_contents(__DIR__ . '/../fixtures/edl/1043_Captation_V1_marqueurs_df.edl'), $edl);
    }

    public function testEdlExportThroughTheAdminApi(): void
    {
        $this->loginAdmin();
        [$video, $version, $ref, $csrf] = $this->publishedVersion();
        $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 110, 'text' => 'Point']);
        $done = $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 525, 'frameOut' => 687, 'text' => 'Plage']);
        $this->api('PATCH', '/api/admin/comments/' . $done['id'], ['status' => 'done']);

        $all = $this->request('GET', '/api/admin/versions/' . $version['id'] . '/edl');
        self::assertSame(200, $all->status);
        self::assertSame(
            "attachment; filename=\"1001_Film manifeste_V1_marqueurs.edl\"; filename*=UTF-8''1001_Film%20manifeste_V1_marqueurs.edl",
            $all->header('Content-Disposition'),
        );
        self::assertStringContainsString('001  001      V     C        01:00:04:10 01:00:04:11', $all->body);
        self::assertStringContainsString('|C:ResolveColorGreen |M:Claire Martin : Plage |D:162', $all->body);

        $todo = $this->request('GET', '/api/admin/versions/' . $version['id'] . '/edl?filter=todo')->body;
        self::assertSame(1, substr_count($todo, '|M:'));
        self::assertStringNotContainsString('Plage', $todo);
    }

    // ---- Dashboard and search -----------------------------------------------------------------

    public function testDashboardCountsAndTellsWhatMoved(): void
    {
        $this->loginAdmin();
        [$video, $version, $ref, $csrf] = $this->publishedVersion();
        $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 10, 'text' => 'Un']);
        $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 20, 'text' => 'Deux']);

        $stats = $this->api('GET', '/api/admin/dashboard');
        self::assertSame([1, 1, 1, 2, 2], [$stats['clients'], $stats['projects'], $stats['videos'], $stats['todo'], $stats['unread']]);
        self::assertGreaterThan(0, $stats['storage']['used']);
        self::assertSame(2, $this->api('GET', '/api/admin/unread')['unread']);
        $recent = $stats['recent']['videos'][0];
        self::assertSame([$video['id'], '2 révisions de Claire Martin · V1 publiée · V1 importée', $version['id']], [$recent['id'], $recent['summary'], $recent['versionId']]);
        self::assertSame($video['title'] . ' : 2 révisions de Claire Martin · V1 publiée', $stats['recent']['projects'][0]['summary']);
        self::assertSame($stats['recent']['projects'][0]['summary'], $stats['recent']['clients'][0]['summary']);

        // A status change by the team is told too; archived videos leave the dashboard.
        $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => 'approved']);
        self::assertSame('V1 validée · 2 révisions de Claire Martin · V1 importée', $this->api('GET', '/api/admin/dashboard')['recent']['videos'][0]['summary']);
        $this->api('PATCH', '/api/admin/videos/' . $video['id'], ['archived' => true]);
        $stats = $this->api('GET', '/api/admin/dashboard');
        self::assertSame([0, []], [$stats['videos'], $stats['recent']['videos']]);
    }

    public function testFeedbackSearchAndFilters(): void
    {
        $this->loginAdmin();
        [$video, $version, $ref, $csrf] = $this->publishedVersion();
        $first = $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 10, 'text' => 'L’étalonnage est froid']);
        $this->clientComment($ref, $csrf, $version['id'], ['frameIn' => 20, 'text' => 'Logo trop tôt']);
        $this->api('PATCH', '/api/admin/comments/' . $first['id'], ['status' => 'done']);

        self::assertCount(2, $this->api('GET', '/api/admin/feedback'), 'unread by default');
        self::assertSame(['L’étalonnage est froid'], array_column($this->api('GET', '/api/admin/feedback?q=etalonnage&status=all'), 'text'), 'accent-insensitive');
        self::assertSame(['Logo trop tôt'], array_column($this->api('GET', '/api/admin/feedback?status=todo'), 'text'));
        self::assertSame(['L’étalonnage est froid'], array_column($this->api('GET', '/api/admin/feedback?status=done'), 'text'));
        self::assertCount(2, $this->api('GET', '/api/admin/feedback?status=all&tag=PUB'), 'tag, case-insensitive');
        self::assertCount(0, $this->api('GET', '/api/admin/feedback?status=all&tag=corporate'));
        self::assertCount(2, $this->api('GET', '/api/admin/feedback?status=all&client=' . $video['clientId'] . '&project=' . $video['projectId']));
        self::assertCount(0, $this->api('GET', '/api/admin/feedback?status=all&client=999'));
        self::assertCount(2, $this->api('GET', '/api/admin/feedback?status=all&from=' . date('Y-m-d', time() - 86400) . '&to=' . date('Y-m-d', time() + 86400)));
        self::assertCount(0, $this->api('GET', '/api/admin/feedback?status=all&to=2020-01-01'));
        self::assertCount(2, $this->api('GET', '/api/admin/feedback?q=maison%20aurore&status=all'), 'client name');
    }

    public function testVideoSearchAndFilters(): void
    {
        $this->loginAdmin();
        [$video] = $this->publishedVersion();
        $this->api('POST', '/api/admin/videos', ['projectId' => $video['projectId'], 'title' => 'Interview fondatrice', 'tags' => ['corporate']], 201);

        self::assertSame(['Film manifeste'], array_column($this->api('GET', '/api/admin/videos?q=MANIFESTE'), 'title'));
        self::assertSame(['Film manifeste'], array_column($this->api('GET', '/api/admin/videos?q=1001'), 'title'));
        self::assertSame(['Interview fondatrice'], array_column($this->api('GET', '/api/admin/videos?tag=corporate'), 'title'));
        self::assertSame(['Film manifeste'], array_column($this->api('GET', '/api/admin/videos?status=to_review'), 'title'));
        self::assertSame(['Interview fondatrice'], array_column($this->api('GET', '/api/admin/videos?status=unpublished'), 'title'));
        self::assertCount(2, $this->api('GET', '/api/admin/videos?q=printemps'), 'project name');
        self::assertCount(0, $this->api('GET', '/api/admin/videos?to=2020-01-01'));
    }

    // ---- Settings: password and TOTP ---------------------------------------------------------

    public function testPasswordChangeNeedsTheCurrentPassword(): void
    {
        $this->loginAdmin();
        self::assertSame('current', $this->api('POST', '/api/admin/password', ['current' => 'faux', 'password' => 'nouveau-mot-de-passe', 'confirmation' => 'nouveau-mot-de-passe'], 422)['field']);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertSame('password', $this->api('POST', '/api/admin/password', ['current' => 'un-mot-de-passe-long', 'password' => 'court', 'confirmation' => 'court'], 422)['field']);
        $this->api('POST', '/api/admin/password', ['current' => 'un-mot-de-passe-long', 'password' => 'nouveau-mot-de-passe', 'confirmation' => 'nouveau-mot-de-passe']);

        $this->api('POST', '/api/admin/logout');
        self::assertSame(401, $this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => 'un-mot-de-passe-long'])->status);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertTrue($this->decode($this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => 'nouveau-mot-de-passe']))['authenticated']);
    }

    public function testTotpSetupLoginAndReplayProtection(): void
    {
        $this->loginAdmin();
        $setup = $this->api('POST', '/api/admin/totp/setup');
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        self::assertStringStartsWith('otpauth://totp/Belprise%3Ajules%40belprod.test?secret=' . $setup['secret'], $setup['uri']);

        // Enabling needs the password too: a stolen session cannot enrol its own phone.
        self::assertSame('current', $this->api('POST', '/api/admin/totp/enable', ['code' => Totp::code($setup['secret'], time()), 'password' => 'faux'], 422)['field']);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertSame('code', $this->api('POST', '/api/admin/totp/enable', ['code' => '000000', 'password' => 'un-mot-de-passe-long'], 422)['field']);
        self::assertTrue($this->api('POST', '/api/admin/totp/enable', ['code' => Totp::code($setup['secret'], time()), 'password' => 'un-mot-de-passe-long'])['totpEnabled']);
        $stored = (string) $this->db()->query("SELECT value FROM settings WHERE key = 'admin.totp_secret'")->fetchColumn();
        self::assertStringNotContainsString($setup['secret'], $stored, 'encrypted at rest');

        // Login: password, then the code.
        $this->api('POST', '/api/admin/logout');
        $step1 = $this->decode($this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => 'un-mot-de-passe-long']));
        self::assertFalse($step1['authenticated']);
        self::assertTrue($step1['totpRequired']);
        self::assertSame(401, $this->request('GET', '/api/admin/clients')->status, 'not logged in yet');

        self::assertSame(401, $this->postJson('/api/admin/login/totp', ['code' => '123456'])->status);
        $this->db()->exec('DELETE FROM login_attempts');
        // A code already used (at activation, same period) is refused.
        self::assertSame(401, $this->postJson('/api/admin/login/totp', ['code' => Totp::code($setup['secret'], time())])->status);
        $this->db()->exec('DELETE FROM login_attempts');
        $next = $this->postJson('/api/admin/login/totp', ['code' => Totp::code($setup['secret'], time() + 30)]);
        self::assertTrue($this->decode($next)['authenticated']);

        // Disabling needs the password.
        self::assertSame(422, $this->request('POST', '/api/admin/totp/disable', ['X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json'], '{"password":"faux"}')->status);
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertFalse($this->api('POST', '/api/admin/totp/disable', ['password' => 'un-mot-de-passe-long'])['totpEnabled']);
    }

    public function testWrongCodesEndThePendingLogin(): void
    {
        $this->loginAdmin();
        $setup = $this->api('POST', '/api/admin/totp/setup');
        $this->api('POST', '/api/admin/totp/enable', ['code' => Totp::code($setup['secret'], time()), 'password' => 'un-mot-de-passe-long']);
        $this->api('POST', '/api/admin/logout');
        $this->postJson('/api/admin/login', ['email' => self::ADMIN_EMAIL, 'password' => 'un-mot-de-passe-long']);

        for ($i = 1; $i < AdminAuth::TOTP_MAX_FAILURES; $i++) {
            $this->db()->exec('DELETE FROM login_attempts');
            self::assertArrayNotHasKey('restart', $this->decode($this->postJson('/api/admin/login/totp', ['code' => '000000'])));
        }
        $this->db()->exec('DELETE FROM login_attempts');
        self::assertTrue($this->decode($this->postJson('/api/admin/login/totp', ['code' => '000000']))['restart']);
        // Even the right code no longer works: the password is asked again.
        $this->db()->exec('DELETE FROM login_attempts');
        $late = $this->postJson('/api/admin/login/totp', ['code' => Totp::code($setup['secret'], time() + 30)]);
        self::assertSame(401, $late->status);
        self::assertTrue($this->decode($late)['restart']);
    }

    public function testChangingThePasswordEndsOtherSessions(): void
    {
        $this->loginAdmin();
        $otherDevice = $this->session->data;

        $this->api('POST', '/api/admin/password', ['current' => 'un-mot-de-passe-long', 'password' => 'nouveau-mot-de-passe', 'confirmation' => 'nouveau-mot-de-passe']);
        self::assertSame(200, $this->request('GET', '/api/admin/clients')->status, 'this session goes on');

        $this->session->data = $otherDevice;
        self::assertSame(401, $this->request('GET', '/api/admin/clients')->status, 'the other one is closed');
    }

    public function testBadgePollingDoesNotKeepAnIdleSessionAlive(): void
    {
        $this->loginAdmin();
        $seen = time() - 600;
        $this->session->data['admin']['seen'] = $seen;

        self::assertSame(200, $this->request('GET', '/api/admin/unread')->status);
        self::assertSame($seen, $this->session->data['admin']['seen']);
        self::assertSame(200, $this->request('GET', '/api/admin/clients')->status);
        self::assertGreaterThan($seen, $this->session->data['admin']['seen']);
    }

    public function testTotpCodesFollowRfc6238(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $code) {
            self::assertSame($code, Totp::code($secret, $time, 8), (string) $time);
        }
        self::assertSame('12345678901234567890', Totp::base32Decode($secret));
        self::assertNotNull(Totp::verify($secret, Totp::code($secret, 1000), 1000 + 25), 'previous period accepted');
        self::assertNull(Totp::verify($secret, Totp::code($secret, 1000), 1000 + 95), 'too old');
    }

    // ---- Helpers ------------------------------------------------------------------------------

    /**
     * @return array{array<string, mixed>, array<string, mixed>, string, string}
     */
    private function publishedVersion(): array
    {
        $fixture = $this->createClientProjectVideo();
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath('m.mp4'));
        $version = $this->attach(['name' => 'm.mp4', 'videoId' => $fixture['video']['id'], 'meta' => self::META], 201);
        $version = $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['published' => true]);
        $path = $fixture['video']['links']['client']['path'];
        preg_match('#id="belprise-boot">(.*?)</script>#s', $this->request('GET', $path)->body, $m);

        return [$fixture['video'], $version, substr($path, 3), json_decode($m[1], true)['csrf']];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function clientComment(string $ref, string $csrf, int $versionId, array $data): array
    {
        $response = $this->request('POST', '/api/v/' . $ref . '/versions/' . $versionId . '/comments', [
            'Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf, 'X-Author-Token' => str_repeat('a', 64),
        ], json_encode($data + ['author' => 'Claire Martin'], JSON_THROW_ON_ERROR));
        self::assertSame(201, $response->status, $response->body);

        return $this->decode($response);
    }
}
