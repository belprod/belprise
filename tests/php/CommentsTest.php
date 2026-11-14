<?php

declare(strict_types=1);

namespace Belprise\Tests;

use Belprise\Http\Response;
use Belprise\Review\CommentService;
use Belprise\Tests\Support\AppTestCase;

/**
 * Comments and validation (§5.1, §5.4, §5.5): rights of each kind of
 * access, and automatic status changes.
 */
final class CommentsTest extends AppTestCase
{
    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const META = [
        'codec' => 'avc1.64000c', 'width' => 320, 'height' => 180, 'fpsNum' => 25, 'fpsDen' => 1,
        'dropFrame' => false, 'startFrame' => 90000, 'durationFrames' => 50, 'timecodeSource' => 'tmcd',
    ];

    private array $fixture;
    private array $version;
    private string $ref;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->fixture = $this->createClientProjectVideo();
        $this->version = $this->addVersion();
        $this->ref = substr($this->fixture['video']['links']['client']['path'], 3);
        $this->csrf = $this->boot($this->fixture['video']['links']['client']['path'])['csrf'];
    }

    private function addVersion(string $status = 'to_review'): array
    {
        $name = 'v' . bin2hex(random_bytes(3)) . '.mp4';
        copy(__DIR__ . '/../fixtures/media/tc25-01000000.mp4', $this->stagingPath($name));
        $version = $this->attach(['name' => $name, 'videoId' => $this->fixture['video']['id'], 'meta' => self::META], 201);

        return $this->api('PATCH', '/api/admin/versions/' . $version['id'], ['status' => $status]);
    }

    private function boot(string $path, array $cookies = []): array
    {
        $response = $this->request('GET', $path, [], '', $cookies);
        self::assertSame(1, preg_match('#id="belprise-boot">(.*?)</script>#s', $response->body, $m), $response->body);

        return json_decode($m[1], true, 16, JSON_THROW_ON_ERROR);
    }

    /** Request to the client review API, as the browser sends it. */
    private function client(string $method, string $path, ?array $data = null, string $token = self::TOKEN_A, ?string $csrf = null, string $ref = '', array $cookies = []): Response
    {
        return $this->request($method, '/api/v/' . ($ref ?: $this->ref) . $path, [
            'Content-Type' => 'application/json',
            'X-Author-Token' => $token,
            'X-CSRF-Token' => $csrf ?? $this->csrf,
        ], $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR), $cookies);
    }

    private function comment(array $data = [], string $token = self::TOKEN_A): array
    {
        $response = $this->client('POST', '/versions/' . $this->version['id'] . '/comments', $data + ['author' => 'Claire Martin', 'text' => 'Logo trop tôt', 'frameIn' => 10], $token);
        self::assertSame(201, $response->status, $response->body);

        return $this->decode($response);
    }

    private function versionStatus(): string
    {
        return $this->api('GET', '/api/admin/videos/' . $this->fixture['video']['id'] . '/versions')[0]['status'];
    }

    // ---- Status rules (§5.4) -------------------------------------------------------

    public function testTheFirstClientCommentTurnsToReviewIntoChangesRequested(): void
    {
        $instant = $this->comment();
        $range = $this->comment(['text' => 'Étalonnage froid', 'frameIn' => 20, 'frameOut' => 30]);

        self::assertSame('todo', $instant['status']);
        self::assertNull($instant['frameOut']);
        self::assertSame(30, $range['frameOut']);
        self::assertTrue($instant['mine']);
        self::assertSame('changes_requested', $this->versionStatus());
    }

    public function testRepliesAndAdminCommentsDoNotChangeTheStatus(): void
    {
        $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/comments', ['text' => 'Note interne', 'frameIn' => 5], 201);
        self::assertSame('to_review', $this->versionStatus());

        $this->api('PATCH', '/api/admin/versions/' . $this->version['id'], ['status' => 'changes_requested']);
        $parent = $this->comment();
        $this->api('PATCH', '/api/admin/versions/' . $this->version['id'], ['status' => 'to_review']);
        $reply = $this->comment(['parentId' => $parent['id'], 'text' => 'Merci !']);

        self::assertSame($parent['frameIn'], $reply['frameIn']);
        self::assertNull($reply['status']);
        self::assertSame('to_review', $this->versionStatus());
    }

    public function testValidationRecordsTheNameAndDateAndTheAdminCanCancelIt(): void
    {
        $response = $this->client('POST', '/versions/' . $this->version['id'] . '/approve', ['author' => 'Claire Martin']);
        $state = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertSame('approved', $state['status']);
        self::assertSame('Claire Martin', $state['approvedBy']);
        self::assertNotNull($state['approvedAt']);
        self::assertSame(409, $this->client('POST', '/versions/' . $this->version['id'] . '/approve', ['author' => 'X'])->status);

        $cancelled = $this->api('PATCH', '/api/admin/versions/' . $this->version['id'], ['status' => 'to_review']);
        self::assertSame('to_review', $cancelled['status']);
        self::assertNull($cancelled['approvedBy']);
        self::assertNull($cancelled['approvedAt']);
    }

    public function testValidationWorksFromAnyStatus(): void
    {
        $this->comment();
        self::assertSame('changes_requested', $this->versionStatus());
        $this->client('POST', '/versions/' . $this->version['id'] . '/approve', ['author' => 'Thomas Roux']);

        self::assertSame('approved', $this->versionStatus());
    }

    // ---- Comment rules ----------------------------------------------------------------

    public function testRepliesHaveOneLevelOnly(): void
    {
        $parent = $this->comment();
        $reply = $this->comment(['parentId' => $parent['id']]);

        $nested = $this->client('POST', '/versions/' . $this->version['id'] . '/comments', ['author' => 'A', 'text' => 'x', 'parentId' => $reply['id']]);
        self::assertSame(422, $nested->status);
    }

    public function testInputIsValidated(): void
    {
        $url = '/versions/' . $this->version['id'] . '/comments';
        $base = ['author' => 'Claire', 'text' => 'Texte', 'frameIn' => 10];

        self::assertSame('frameOut', $this->decode($this->client('POST', $url, ['frameOut' => 10] + $base))['field']);
        self::assertSame('frameIn', $this->decode($this->client('POST', $url, ['frameIn' => 50] + $base))['field'], 'past the last frame');
        self::assertSame('text', $this->decode($this->client('POST', $url, ['text' => '  '] + $base))['field']);
        self::assertSame('author', $this->decode($this->client('POST', $url, ['author' => ''] + $base))['field']);
        self::assertSame(400, $this->client('POST', $url, $base, 'not-a-token')->status, 'browser token required');
    }

    public function testARefusalNeedsAReasonPostedAsAReply(): void
    {
        $comment = $this->comment();
        $path = '/api/admin/comments/' . $comment['id'];

        self::assertSame('reason', $this->api('PATCH', $path, ['status' => 'refused'], 422)['field']);
        self::assertSame('reason', $this->api('PATCH', $path, ['status' => 'refused', 'reason' => '   '], 422)['field']);
        self::assertSame('refused', $this->api('PATCH', $path, ['status' => 'refused', 'reason' => 'Le plan n’existe qu’en une prise.'])['status']);

        // The client sees the status and the explanation, signed belprod.
        $list = $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments'];
        self::assertSame(['refused', null], array_column($list, 'status'));
        self::assertSame(['Le plan n’existe qu’en une prise.', true], [$list[1]['text'], $list[1]['isAdmin']]);

        // Refused revisions are their own filter and a red EDL marker; they can go back to "À faire".
        self::assertSame([$comment['id']], array_column($this->api('GET', '/api/admin/feedback?status=refused'), 'id'));
        $edl = $this->request('GET', '/api/admin/versions/' . $this->version['id'] . '/edl');
        self::assertStringContainsString('|C:ResolveColorRed', $edl->body);
        self::assertSame('todo', $this->api('PATCH', $path, ['status' => 'todo'])['status']);
        // A client cannot refuse.
        $this->client('PATCH', '/comments/' . $comment['id'], ['status' => 'refused', 'reason' => 'x']);
        self::assertSame('todo', $this->api('GET', '/api/admin/versions/' . $this->version['id'] . '/comments')['comments'][0]['status']);
    }

    public function testAuthorsEditAndDeleteOnlyTheirOwnComments(): void
    {
        $mine = $this->comment();
        $other = $this->comment([], self::TOKEN_B);

        self::assertSame(403, $this->client('PATCH', '/comments/' . $other['id'], ['text' => 'piraté'])->status);
        self::assertSame(403, $this->client('DELETE', '/comments/' . $other['id'])->status);

        $edited = $this->decode($this->client('PATCH', '/comments/' . $mine['id'], ['text' => 'Corrigé']));
        self::assertSame('Corrigé', $edited['text']);

        $list = $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments'];
        self::assertSame([true, false], array_column($list, 'mine'));

        // A client cannot change the "Fait / À faire" status.
        $this->client('PATCH', '/comments/' . $mine['id'], ['status' => 'done', 'text' => 'Corrigé']);
        self::assertSame('todo', $this->api('GET', '/api/admin/versions/' . $this->version['id'] . '/comments')['comments'][0]['status']);

        self::assertSame(200, $this->client('DELETE', '/comments/' . $mine['id'])->status);
        self::assertCount(1, $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments']);
    }

    public function testADeletedCommentWithRepliesStaysAsAPlaceholder(): void
    {
        $parent = $this->comment();
        $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/comments', ['text' => 'Corrigé', 'parentId' => $parent['id']], 201);
        $this->client('DELETE', '/comments/' . $parent['id']);

        $list = $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments'];
        self::assertCount(2, $list);
        self::assertTrue($list[0]['deleted']);
        self::assertSame('', $list[0]['text']);
        self::assertSame('belprod', $list[1]['author']);
    }

    public function testCommentsAreOrderedByTimecodeWithTheirReplies(): void
    {
        $late = $this->comment(['frameIn' => 40]);
        $early = $this->comment(['frameIn' => 5]);
        $this->comment(['parentId' => $late['id'], 'text' => 'Réponse']);

        $list = $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments'];
        self::assertSame([$early['id'], $late['id']], array_values(array_column(array_filter($list, fn ($c) => $c['parentId'] === null), 'id')));
        self::assertSame($late['id'], $list[2]['parentId']);
    }

    public function testAbuseLimitPerAddress(): void
    {
        for ($i = 0; $i < CommentService::ABUSE_LIMIT; $i++) {
            $this->comment(['frameIn' => $i % 50]);
        }
        $response = $this->client('POST', '/versions/' . $this->version['id'] . '/comments', ['author' => 'A', 'text' => 'x', 'frameIn' => 1]);

        self::assertSame(429, $response->status);
        $hash = $this->db()->query('SELECT ip_hash FROM comments LIMIT 1')->fetchColumn();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        self::assertStringNotContainsString('203.0.113.7', (string) $hash);
    }

    // ---- Access rights ---------------------------------------------------------------

    public function testTheViewLinkHasNoCommentsApi(): void
    {
        $viewRef = substr($this->fixture['video']['links']['view']['path'], 3);
        $reference = $this->request('GET', '/nope')->body;

        foreach ([
            ['GET', '/versions/' . $this->version['id'] . '/comments', null],
            ['POST', '/versions/' . $this->version['id'] . '/comments', ['author' => 'A', 'text' => 'x', 'frameIn' => 1]],
            ['POST', '/versions/' . $this->version['id'] . '/approve', ['author' => 'A']],
        ] as [$method, $path, $data]) {
            $response = $this->client($method, $path, $data, self::TOKEN_A, $this->csrf, $viewRef);
            self::assertSame(404, $response->status);
            self::assertSame($reference, $response->body);
        }
        self::assertArrayNotHasKey('csrf', $this->boot($this->fixture['video']['links']['view']['path']));
    }

    public function testDisabledExpiredAndGuessedLinksGetTheNeutral404(): void
    {
        $linkId = $this->fixture['video']['links']['client']['id'];
        $list = '/versions/' . $this->version['id'] . '/comments';

        self::assertSame(404, $this->client('GET', $list, null, self::TOKEN_A, null, '1001zzzzzzzzzz')->status);
        self::assertSame(404, $this->client('GET', $list, null, self::TOKEN_A, null, '1002' . substr($this->ref, 4))->status);

        $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => false]);
        self::assertSame(404, $this->client('GET', $list)->status);

        $this->api('PATCH', '/api/admin/links/' . $linkId, ['isActive' => true, 'expiresOn' => '2020-01-01']);
        self::assertSame(404, $this->client('GET', $list)->status);

        $this->api('PATCH', '/api/admin/links/' . $linkId, ['expiresOn' => null]);
        self::assertSame(200, $this->client('GET', $list)->status);
    }

    public function testAPasswordProtectedLinkNeedsItsCookie(): void
    {
        $link = $this->fixture['video']['links']['client'];
        $this->api('PATCH', '/api/admin/links/' . $link['id'], ['password' => 'aurore2027']);
        $list = '/versions/' . $this->version['id'] . '/comments';

        self::assertSame(404, $this->client('GET', $list)->status);

        $unlock = $this->request('POST', $link['path'], ['Content-Type' => 'application/x-www-form-urlencoded'], 'password=aurore2027');
        $cookie = ['belprise_link_' . $link['id'] => $unlock->cookies()['belprise_link_' . $link['id']]['value']];
        self::assertSame(200, $this->client('GET', $list, null, self::TOKEN_A, null, '', $cookie)->status);
    }

    public function testUnpublishedVersionsAndOtherVideosAreOutOfReach(): void
    {
        $draft = $this->addVersion('in_progress');
        self::assertSame(404, $this->client('GET', '/versions/' . $draft['id'] . '/comments')->status);
        self::assertSame(404, $this->client('POST', '/versions/' . $draft['id'] . '/approve', ['author' => 'A'])->status);

        $other = $this->api('POST', '/api/admin/videos', ['projectId' => $this->fixture['project']['id'], 'title' => 'Autre'], 201);
        self::assertSame(404, $this->client('GET', '/versions/' . $this->version['id'] . '/comments', null, self::TOKEN_A, null, substr($other['links']['client']['path'], 3))->status);
    }

    public function testStateChangesNeedTheLinkCsrfToken(): void
    {
        $url = '/versions/' . $this->version['id'] . '/comments';
        self::assertSame(403, $this->client('POST', $url, ['author' => 'A', 'text' => 'x', 'frameIn' => 1], self::TOKEN_A, 'forged')->status);
        self::assertSame(403, $this->client('POST', '/versions/' . $this->version['id'] . '/approve', ['author' => 'A'], self::TOKEN_A, '')->status);
        self::assertSame('to_review', $this->versionStatus());
    }

    // ---- Admin side -------------------------------------------------------------------

    public function testAdminRepliesAreSignedAndCanSetStatusAndDelete(): void
    {
        $comment = $this->comment();
        $reply = $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/comments', ['text' => 'C’est fait', 'parentId' => $comment['id']], 201);

        self::assertSame('belprod', $reply['author']);
        self::assertTrue($reply['isAdmin']);
        self::assertSame('done', $this->api('PATCH', '/api/admin/comments/' . $comment['id'], ['status' => 'done'])['status']);
        self::assertSame(422, $this->request('PATCH', '/api/admin/comments/' . $reply['id'], [
            'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/json',
        ], '{"status":"done"}')->status, 'replies have no status');

        // The client sees the reply and the new status.
        $list = $this->decode($this->client('GET', '/versions/' . $this->version['id'] . '/comments'))['comments'];
        self::assertSame(['done', null], array_column($list, 'status'));
        self::assertSame('C’est fait', $list[1]['text']);

        $this->api('DELETE', '/api/admin/comments/' . $comment['id']);
        self::assertTrue($this->api('GET', '/api/admin/versions/' . $this->version['id'] . '/comments')['comments'][0]['deleted']);
    }

    public function testFeedbackListsUnreadClientCommentsUntilTheVersionIsOpened(): void
    {
        $comment = $this->comment(['frameIn' => 20, 'frameOut' => 30]);
        $this->comment(['parentId' => $comment['id'], 'text' => 'Précision']);
        $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/comments', ['text' => 'Admin', 'frameIn' => 3], 201);

        $feedback = $this->api('GET', '/api/admin/feedback');
        self::assertCount(2, $feedback, 'client comment and reply, not the admin comment');
        self::assertSame('01:00:00:20 → 01:00:01:05', $feedback[1]['timecode']);
        self::assertTrue($feedback[0]['isReply']);
        self::assertSame(1001, $feedback[0]['videoNumber']);

        $this->api('POST', '/api/admin/versions/' . $this->version['id'] . '/comments/read');
        self::assertSame([], $this->api('GET', '/api/admin/feedback'));
    }
}
