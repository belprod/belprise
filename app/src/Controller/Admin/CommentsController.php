<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Export\MarkerEdl;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Repository\Comments;
use Belprise\Review\CommentPresenter;
use Belprise\Review\CommentService;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\Support\Input;
use Belprise\Support\Text;
use Belprise\Timecode;

/**
 * Admin side of the comments (§5.5, §5.7): replies signed "belprod.",
 * "Fait / À faire" status, deletion, read marks, "Révisions".
 */
final class CommentsController
{

    public function __construct(private readonly Services $services)
    {
    }

    public function list(int $versionId): Response
    {
        $version = $this->version($versionId);

        return Response::json([
            'version' => CommentPresenter::versionState($version),
            'comments' => array_map(
                static fn (array $row) => CommentPresenter::comment($row, null, true),
                $this->services->comments()->forVersion($versionId),
            ),
        ]);
    }

    public function create(int $versionId, Request $request): Response
    {
        $version = $this->version($versionId);
        $service = new CommentService($this->services->db(), $this->services->comments(), $this->services->versions());
        $id = $service->create($version, new Input($request->json()), $this->signature(), null, true, null);
        $row = $this->services->comments()->find($id) ?? throw HttpException::notFound();

        return Response::json(CommentPresenter::comment($row, null, true), 201);
    }

    /**
     * Body: status ("todo" / "done" / "refused", top-level revisions only)
     * and/or text. "refused" needs a reason: it is posted as a reply.
     */
    public function update(int $id, Request $request): Response
    {
        $comment = $this->services->comments()->find($id) ?? throw HttpException::notFound();
        $input = new Input($request->json());
        if ($input->has('status')) {
            $status = $input->string('status', 'Statut', 10);
            if (!in_array($status, ['todo', 'done', 'refused'], true) || $comment['parent_id'] !== null || $comment['deleted_at'] !== null) {
                throw new HttpException(422, 'Statut invalide.', ['field' => 'status']);
            }
            if ($status === 'refused') {
                $reason = $input->has('reason') ? trim($input->text('reason', 'Motif du refus', Comments::MAX_LENGTH)) : '';
                if ($reason === '') {
                    throw new HttpException(422, 'Expliquez pourquoi cette révision est refusée.', ['field' => 'reason']);
                }
                $version = $this->version((int) $comment['version_id']);
                $service = new CommentService($this->services->db(), $this->services->comments(), $this->services->versions());
                $service->create($version, new Input(['text' => $reason, 'parentId' => $id]), $this->signature(), null, true, null);
            }
            $this->services->comments()->setStatus($id, $status);
        }
        if ($input->has('text')) {
            if ((int) $comment['is_admin'] !== 1) {
                throw new HttpException(403, 'Seul son auteur peut modifier une révision client.');
            }
            $this->services->comments()->updateBody($id, $input->string('text', 'Révision', Comments::MAX_LENGTH));
        }
        $row = $this->services->comments()->find($id) ?? throw HttpException::notFound();

        return Response::json(CommentPresenter::comment($row, null, true));
    }

    public function delete(int $id): Response
    {
        $this->services->comments()->find($id) ?? throw HttpException::notFound();
        $this->services->comments()->softDelete($id);

        return Response::json(['deleted' => true]);
    }

    public function markRead(int $versionId): Response
    {
        $this->version($versionId);

        return Response::json(['marked' => $this->services->comments()->markVersionRead($versionId)]);
    }

    /**
     * "Révisions" and comment search (§5.7). Query: q (free text),
     * status (unread by default, todo, done, all), client, project, tag,
     * from / to (local dates).
     */
    public function feedback(Request $request): Response
    {
        $status = $request->queryString('status') ?? 'unread';
        $from = self::date($request->queryString('from'));
        $to = self::date($request->queryString('to'));
        $rows = $this->services->comments()->search([
            'status' => in_array($status, ['unread', 'todo', 'done', 'refused', 'all'], true) ? $status : 'unread',
            'client' => self::id($request->queryString('client')),
            'project' => self::id($request->queryString('project')),
            'tag' => ($tag = trim((string) $request->queryString('tag'))) === '' ? null : $tag,
            'from' => $from === null ? null : Dates::localDayStart($from),
            'to' => $to === null ? null : Dates::localDateToExpiry($to),
        ]);
        $query = (string) $request->queryString('q');
        if (trim($query) !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => Text::contains(
                implode(' ', [$row['body'], $row['author_name'], $row['video_title'], $row['video_number'], $row['client_name'], $row['project_name'], $row['tag_names']]),
                $query,
            )));
        }

        return Response::json(array_map(static function (array $row): array {
            $num = (int) $row['fps_num'];
            $den = (int) $row['fps_den'];
            $df = (int) $row['drop_frame'] === 1;
            $start = (int) $row['start_frame'];
            $tc = Timecode::fromFrames($start + (int) $row['position'], $num, $den, $df);
            if ($row['parent_id'] === null && $row['frame_out'] !== null) {
                $tc .= ' → ' . Timecode::fromFrames($start + (int) $row['frame_out'], $num, $den, $df);
            }

            return [
                'id' => (int) $row['id'],
                'isReply' => $row['parent_id'] !== null,
                'author' => $row['author_name'],
                'text' => $row['body'],
                'status' => $row['parent_id'] === null ? $row['status'] : null,
                'read' => (int) $row['is_read_by_admin'] === 1,
                'timecode' => $tc,
                'createdAt' => Dates::toIso((string) $row['created_at']),
                'clientName' => $row['client_name'],
                'projectName' => $row['project_name'],
                'videoId' => (int) $row['video_id'],
                'videoNumber' => (int) $row['video_number'],
                'videoTitle' => $row['video_title'],
                'versionId' => (int) $row['version_id'],
                'versionNumber' => (int) $row['version_number'],
            ];
        }, $rows));
    }

    /**
     * Comments of a version as Resolve markers (§5.6). Query: filter=todo
     * for the "À faire" comments only.
     */
    public function edl(int $versionId, Request $request): Response
    {
        $version = $this->version($versionId);
        $video = $this->services->videos()->find((int) $version['video_id']) ?? throw HttpException::notFound();
        $onlyTodo = $request->queryString('filter') === 'todo';

        $markers = [];
        foreach ($this->services->comments()->forVersion($versionId) as $row) {
            if ($row['parent_id'] !== null || $row['deleted_at'] !== null || ($onlyTodo && $row['status'] !== 'todo')) {
                continue;
            }
            $markers[] = [
                'frameIn' => (int) $row['frame_in'],
                'frameOut' => $row['frame_out'] === null ? null : (int) $row['frame_out'],
                'author' => (string) $row['author_name'],
                'text' => (string) $row['body'],
                'status' => (string) $row['status'],
            ];
        }
        $edl = MarkerEdl::build(
            $video['number'] . ' ' . $video['title'] . ' V' . $version['number'],
            (int) $version['fps_num'],
            (int) $version['fps_den'],
            (int) $version['drop_frame'] === 1,
            (int) $version['start_frame'],
            $markers,
        );
        $name = MarkerEdl::fileName((int) $video['number'], (string) $video['title'], (int) $version['number']);
        $response = new Response(200, $edl, 'application/octet-stream');
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)) ?: 'marqueurs.edl';
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    private static function id(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function version(int $id): array
    {
        return $this->services->versions()->find($id) ?? throw HttpException::notFound();
    }

    /** Replies of the organization are signed with its name. */
    private function signature(): string
    {
        $organization = $this->services->organizations()->find((int) $this->services->organizationId());

        return (string) ($organization['name'] ?? 'belprod.');
    }
}
