<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Media\VideoArchiver;
use Belprise\Services;
use Belprise\Storage;
use Belprise\Support\Dates;
use Belprise\Support\Input;
use Belprise\Support\Text;

final class VideosController
{
    private const MAX_TAGS = 20;
    private const MAX_TAG_LENGTH = 40;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Query: client, project, archived=1, and the search (§5.7): q (title,
     * n°, client, project, tag), tag, status (a version status or
     * "unpublished"), from / to (local dates of the latest version).
     */
    public function list(Request $request): Response
    {
        $rows = $this->services->videos()->list(
            self::id($request->queryString('client')),
            self::id($request->queryString('project')),
            $request->queryString('archived') === '1',
        );
        $query = (string) $request->queryString('q');
        $tag = Text::fold(trim((string) $request->queryString('tag')));
        $status = (string) $request->queryString('status');
        $from = self::date($request->queryString('from'));
        $to = self::date($request->queryString('to'));

        $rows = array_values(array_filter($rows, static function (array $row) use ($query, $tag, $status, $from, $to): bool {
            if ($query !== '' && !Text::contains(
                implode(' ', [$row['title'], $row['number'], $row['client_name'], $row['project_name'], ...$row['tags']]),
                $query,
            )) {
                return false;
            }
            if ($tag !== '' && !in_array($tag, array_map(Text::fold(...), $row['tags']), true)) {
                return false;
            }
            if ($status !== '' && ($status === 'unpublished' ? $row['status'] !== null : $row['status'] !== $status)) {
                return false;
            }
            $activity = (string) ($row['last_version_at'] ?? $row['created_at']);
            if ($from !== null && $activity < Dates::localDayStart($from)) {
                return false;
            }

            return $to === null || $activity <= Dates::localDateToExpiry($to);
        }));

        return Response::json(array_map(fn (array $row) => $this->services->presenter()->video($row), $rows));
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? $value : null;
    }

    public function show(int $id): Response
    {
        return Response::json($this->present($this->find($id)));
    }

    public function create(Request $request): Response
    {
        $input = new Input($request->json());
        $projectId = $input->int('projectId', 'Projet');
        $title = $input->string('title', 'Titre', 160);
        $tags = $input->stringList('tags', 'Tags', self::MAX_TAGS, self::MAX_TAG_LENGTH);
        $download = $input->has('downloadAllowed') ? $input->bool('downloadAllowed', 'Téléchargement autorisé') : true;
        if ($this->services->projects()->find($projectId) === null) {
            throw new HttpException(422, 'Ce projet n’existe pas.', ['field' => 'projectId']);
        }

        $services = $this->services;
        $id = Database::transaction($services->db(), function () use ($services, $projectId, $title, $tags, $download): int {
            $id = $services->videos()->create($projectId, $title, $download);
            $services->tags()->sync($id, $tags);
            $services->links()->create('client', $id);
            $services->links()->create('view', $id);

            return $id;
        });

        return Response::json($this->present($this->find($id)), 201);
    }

    public function update(int $id, Request $request): Response
    {
        $video = $this->find($id);
        $input = new Input($request->json());
        $services = $this->services;
        Database::transaction($services->db(), function () use ($services, $input, $id, $video): void {
            if ($input->has('title') || $input->has('downloadAllowed')) {
                $services->videos()->update(
                    $id,
                    $input->has('title') ? $input->string('title', 'Titre', 160) : (string) $video['title'],
                    $input->has('downloadAllowed')
                        ? $input->bool('downloadAllowed', 'Téléchargement autorisé')
                        : (int) $video['download_allowed'] === 1,
                );
            }
            if ($input->has('tags')) {
                $services->tags()->sync($id, $input->stringList('tags', 'Tags', self::MAX_TAGS, self::MAX_TAG_LENGTH));
            }
        });
        // Archiving is not a flag: it keeps one version and deletes the rest (§5.13).
        if ($input->has('archived')) {
            if ($input->bool('archived', 'Archivé')) {
                if ($video['archived_at'] === null) {
                    $this->archiver()->execute($video, (string) ($services->user()['name'] ?? ''));
                }
            } else {
                $services->videos()->setArchived($id, false);
            }
        }

        return Response::json($this->present($this->find($id)));
    }

    /** What archiving would keep and delete (shown before confirming). */
    public function archivePlan(int $id): Response
    {
        $video = $this->find($id);
        $plan = $this->archiver()->plan($video);

        return Response::json([
            'possible' => $plan['keep'] !== null,
            'keep' => $plan['keep'] === null ? null : ['number' => (int) $plan['keep']['number'], 'status' => $plan['keep']['status']],
            'delete' => array_map(static fn (array $v) => (int) $v['number'], array_reverse($plan['delete'])),
            'revisions' => $plan['revisions'],
            'freedBytes' => $plan['freedBytes'],
        ]);
    }

    public function archive(int $id): Response
    {
        $video = $this->find($id);
        if ($video['archived_at'] === null) {
            $this->archiver()->execute($video, (string) ($this->services->user()['name'] ?? ''));
        }

        return Response::json($this->present($this->find($id)));
    }

    private function archiver(): VideoArchiver
    {
        return new VideoArchiver($this->services->db(), $this->services->storage());
    }

    public function delete(int $id): Response
    {
        $video = $this->find($id);
        $services = $this->services;
        $parts = $services->uploads()->idsForVideos([$id]);
        Database::transaction($services->db(), function () use ($services, $id): void {
            $services->links()->deleteFor('client', [$id]);
            $services->links()->deleteFor('view', [$id]);
            $services->videos()->delete($id);
            $services->tags()->pruneUnused();
        });
        $services->storage()->deleteTree(Storage::videoMediaDir((int) $video['client_id'], (int) $video['number']));
        foreach ($parts as $part) {
            $services->storage()->deleteFile(Storage::uploadPart($part));
        }

        return Response::json(['deleted' => true]);
    }

    public function tags(): Response
    {
        return Response::json($this->services->tags()->names());
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->videos()->find($id) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    private function present(array $video): array
    {
        $links = [];
        foreach (['client', 'view'] as $type) {
            $link = $this->services->links()->forTarget($type, (int) $video['id']);
            if ($link !== null) {
                $links[$type] = $link;
            }
        }

        return $this->services->presenter()->video($video, $links);
    }

    private static function id(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
