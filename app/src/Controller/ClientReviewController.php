<?php

declare(strict_types=1);

namespace Belprise\Controller;

use Belprise\Http\FileResponse;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Media\MasterFormat;
use Belprise\Repository\Comments;
use Belprise\Review\CommentPresenter;
use Belprise\Review\CommentService;
use Belprise\Security\Keys;
use Belprise\Services;
use Belprise\Support\Input;

/**
 * Comments and validation from the client review page (/api/v/{n°}{key}/…).
 * Only a usable "client" link opens it (view links never do); anything
 * else is the neutral 404. No account: the author is recognised by a token
 * kept in the browser (localStorage), stored here as an HMAC only.
 */
final class ClientReviewController
{
    public const TOKEN_HEADER = 'X-Author-Token';

    public function __construct(
        private readonly Services $services,
        private readonly string $clientIp,
    ) {
    }

    public function list(Request $request, string $ref, int $versionId): Response
    {
        [, $video] = $this->resolve($request, $ref, true);
        $version = $this->version($video, $versionId);
        $token = $this->tokenHash($request);

        return Response::json([
            'version' => CommentPresenter::versionState($version),
            'comments' => array_map(
                static fn (array $row) => CommentPresenter::comment($row, $token, false),
                $this->services->comments()->forVersion($versionId),
            ),
        ]);
    }

    public function create(Request $request, string $ref, int $versionId): Response
    {
        [$link, $video] = $this->resolve($request, $ref, true);
        $this->checkCsrf($request, $link);
        $version = $this->version($video, $versionId);
        $token = $this->tokenHash($request) ?? throw new HttpException(400, 'Requête invalide.');
        $input = new Input($request->json());

        $id = $this->service()->create(
            $version,
            $input,
            CommentService::authorName($input),
            $token,
            false,
            $this->services->clientKey($this->clientIp),
        );
        $row = $this->services->comments()->find($id) ?? throw HttpException::notFound();

        return Response::json(CommentPresenter::comment($row, $token, false), 201);
    }

    public function update(Request $request, string $ref, int $commentId): Response
    {
        [$link, $video] = $this->resolve($request, $ref, true);
        $this->checkCsrf($request, $link);
        $comment = $this->ownComment($request, $video, $commentId);
        $text = (new Input($request->json()))->string('text', 'Révision', Comments::MAX_LENGTH);
        $this->services->comments()->updateBody($commentId, $text);
        $row = $this->services->comments()->find($commentId) ?? throw HttpException::notFound();

        return Response::json(CommentPresenter::comment($row, $comment['author_token_hash'], false));
    }

    public function delete(Request $request, string $ref, int $commentId): Response
    {
        [$link, $video] = $this->resolve($request, $ref, true);
        $this->checkCsrf($request, $link);
        $this->ownComment($request, $video, $commentId);
        $this->services->comments()->softDelete($commentId);

        return Response::json(['deleted' => true]);
    }

    public function approve(Request $request, string $ref, int $versionId): Response
    {
        [$link, $video] = $this->resolve($request, $ref, true);
        $this->checkCsrf($request, $link);
        $version = $this->version($video, $versionId);
        if ($version['status'] === 'approved') {
            throw new HttpException(409, 'Cette version est déjà validée.');
        }
        $this->service()->approve($version, CommentService::authorName(new Input($request->json())));

        return Response::json(CommentPresenter::versionState($this->services->versions()->find($versionId) ?? []));
    }

    /**
     * "Télécharger le master" (§5.1): the original file as an attachment,
     * with Range so that a large download can resume; only when the video
     * allows downloads.
     */
    public function download(Request $request, string $ref, int $versionId): Response
    {
        // The master stays downloadable from the client's library (§5.3).
        [, $video] = $this->resolve($request, $ref, false);
        $version = $this->version($video, $versionId);
        if ((int) $video['download_allowed'] !== 1 || $version['file_path'] === null) {
            throw HttpException::notFound();
        }
        $file = $this->services->storage()->absolute((string) $version['file_path']);
        if (!is_file($file) || is_link($file)) {
            throw HttpException::notFound();
        }

        $name = self::downloadName((int) $video['number'], (string) $video['title'], (int) $version['number'], pathinfo($file, PATHINFO_EXTENSION));
        $response = new FileResponse($file, MasterFormat::contentType($file) ?? 'application/octet-stream', $request->header('Range'), $this->services->config->xsendfile);
        $response->setHeader('Content-Disposition', sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            self::asciiName($name),
            rawurlencode($name),
        ));
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    /** "1042_Film manifeste 60 s_V3.mp4" (§5.6 style), without characters forbidden in file names. */
    public static function downloadName(int $number, string $title, int $version, string $extension = 'mp4'): string
    {
        $title = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#u', ' ', $title) ?? '');

        return $number . '_' . ($title === '' ? 'video' : $title) . '_V' . $version . '.' . (preg_match('/^[a-z0-9]{2,4}$/', $extension) === 1 ? $extension : 'mp4');
    }

    public static function asciiName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return preg_replace('/[^A-Za-z0-9._ -]+/', '', $ascii === false ? '' : $ascii) ?: 'master.mp4';
    }

    /**
     * The link and its video, if this request may use the client link
     * ($reviewing: revisions and validation, closed once the video is archived).
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function resolve(Request $request, string $ref, bool $reviewing): array
    {
        $parsed = Keys::parseVideoRef($ref) ?? throw HttpException::notFound();
        $link = $this->services->links()->findByKey($parsed['key']);
        $access = $this->services->linkAccess();
        if ($link === null || $link['type'] !== 'client' || !$access->isUsable($link)) {
            throw HttpException::notFound();
        }
        if ($access->needsPassword($link) && !$access->hasValidToken($link, $request->cookie($access->cookieName($link)))) {
            throw HttpException::notFound();
        }
        $video = $this->services->videos()->find((int) $link['target_id']);
        if ($video === null || (int) $video['number'] !== $parsed['number']) {
            throw HttpException::notFound();
        }
        // An archived video is only watched and downloaded: no more revisions.
        if ($reviewing && $video['archived_at'] !== null) {
            throw HttpException::notFound();
        }

        return [$link, $video];
    }

    /**
     * A published version of this video.
     *
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    private function version(array $video, int $versionId): array
    {
        $version = $this->services->versions()->find($versionId);
        if ($version === null || (int) $version['video_id'] !== (int) $video['id'] || (int) $version['is_published'] !== 1) {
            throw HttpException::notFound();
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    private function ownComment(Request $request, array $video, int $commentId): array
    {
        $comment = $this->services->comments()->find($commentId);
        if ($comment === null || (int) $comment['video_id'] !== (int) $video['id'] || (int) $comment['is_published'] !== 1) {
            throw HttpException::notFound();
        }
        $token = $this->tokenHash($request);
        if ($token === null || $comment['author_token_hash'] === null || !hash_equals((string) $comment['author_token_hash'], $token)) {
            throw new HttpException(403, 'Vous ne pouvez modifier que vos propres révisions.');
        }

        return $comment;
    }

    /**
     * @param array<string, mixed> $link
     */
    private function checkCsrf(Request $request, array $link): void
    {
        $given = (string) $request->header('X-CSRF-Token');
        if (!hash_equals($this->services->linkAccess()->csrfToken($link), $given)) {
            throw new HttpException(403, 'Page expirée : rechargez-la.');
        }
    }

    /** HMAC of the browser token (64 hex characters), or null. */
    private function tokenHash(Request $request): ?string
    {
        $token = $request->header(self::TOKEN_HEADER);
        if ($token === null || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }

        return hash_hmac('sha256', 'author:' . $token, $this->services->config->secret);
    }

    private function service(): CommentService
    {
        return new CommentService($this->services->db(), $this->services->comments(), $this->services->versions());
    }
}
