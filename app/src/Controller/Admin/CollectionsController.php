<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Controller\AdminController;
use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\Support\Input;

/**
 * Collections (CAHIER_DES_CHARGES §5.9): a selection of the organization's
 * videos, in a chosen order, shared with a prospect by a link. Each video
 * shows its latest approved version, else its latest published one.
 */
final class CollectionsController
{
    public const MAX_VIDEOS = 60;

    public function __construct(private readonly Services $services)
    {
    }

    public function list(): Response
    {
        $links = $this->services->links();

        return Response::json(array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'videoCount' => (int) $row['video_count'],
            'updatedAt' => Dates::toIso((string) $row['updated_at']),
            'link' => ($link = $links->forTarget('collection', (int) $row['id'])) === null ? null : $this->services->presenter()->link($link, null),
        ], $this->services->collections()->list()));
    }

    public function show(int $id): Response
    {
        return Response::json($this->present($this->find($id)));
    }

    public function create(Request $request): Response
    {
        $fields = $this->fields(new Input($request->json()));
        $services = $this->services;
        $id = Database::transaction($services->db(), static function () use ($services, $fields): int {
            $id = $services->collections()->create($fields);
            $services->links()->create('collection', $id);

            return $id;
        });

        return Response::json($this->present($this->find($id)), 201);
    }

    /** Body: title, introduction, colours (all together), and/or videoIds (the whole ordered selection). */
    public function update(int $id, Request $request): Response
    {
        $this->find($id);
        $input = new Input($request->json());
        if ($input->has('title')) {
            $this->services->collections()->update($id, $this->fields($input));
        }
        if ($input->has('videoIds')) {
            $ids = $request->json()['videoIds'];
            if (!is_array($ids) || count($ids) > self::MAX_VIDEOS || array_filter($ids, static fn ($v) => !is_int($v)) !== []) {
                throw new HttpException(422, 'Une collection compte au plus ' . self::MAX_VIDEOS . ' vidéos.', ['field' => 'videoIds']);
            }
            foreach ($ids as $videoId) {
                // Only videos of the organization (the repository is scoped to it).
                $this->services->videos()->find($videoId) ?? throw new HttpException(422, 'Cette vidéo n’existe pas.', ['field' => 'videoIds']);
            }
            $this->services->collections()->setVideos($id, array_values($ids));
        }

        return Response::json($this->present($this->find($id)));
    }

    public function delete(int $id): Response
    {
        $collection = $this->find($id);
        $services = $this->services;
        Database::transaction($services->db(), static function () use ($services, $id): void {
            $services->links()->deleteFor('collection', [$id]);
            $services->collections()->delete($id);
        });
        $services->storage()->deleteFile($collection['logo'] === null ? null : (string) $collection['logo']);

        return Response::json(['deleted' => true]);
    }

    public function uploadLogo(int $id, Request $request): Response
    {
        $collection = $this->find($id);
        $upload = $request->files['logo'] ?? throw new HttpException(422, 'Choisissez un fichier.', ['field' => 'logo']);
        $path = $this->services->logos()->store($id, $upload);
        $this->services->collections()->setLogo($id, $path);
        $this->services->storage()->deleteFile($collection['logo'] === null ? null : (string) $collection['logo']);

        return Response::json($this->present($this->find($id)));
    }

    public function deleteLogo(int $id): Response
    {
        $collection = $this->find($id);
        $this->services->collections()->setLogo($id, null);
        $this->services->storage()->deleteFile($collection['logo'] === null ? null : (string) $collection['logo']);

        return Response::json($this->present($this->find($id)));
    }

    /**
     * @return array{title: string, introduction: string, bg_color: string, text_color: string, accent_color: string}
     */
    private function fields(Input $input): array
    {
        // Not given: the organization's own identity (§5.9).
        $brand = AdminController::theme($this->services->organizations()->find((int) $this->services->organizationId()) ?? []);

        return [
            'title' => $input->string('title', 'Titre', 160),
            'introduction' => $input->has('introduction') ? $input->text('introduction', 'Introduction', 2000) : '',
            'bg_color' => $input->has('bgColor') ? $input->color('bgColor', 'Fond') : $brand->background(),
            'text_color' => $input->has('textColor') ? $input->color('textColor', 'Texte') : $brand->text(),
            'accent_color' => $input->has('accentColor') ? $input->color('accentColor', 'Accent') : $brand->accent,
        ];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return $this->services->collections()->find($id) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function present(array $collection): array
    {
        $signer = $this->services->signer();
        $videos = [];
        foreach ($this->services->collections()->videoIds((int) $collection['id']) as $videoId) {
            $video = $this->services->videos()->find($videoId);
            if ($video === null) {
                continue;
            }
            $shown = self::shownVersion($this->services->versions()->forVideo($videoId));
            $image = $shown === null ? null : ($shown['thumb_path'] ?? $shown['poster_path']);
            $videos[] = [
                'id' => $videoId,
                'number' => (int) $video['number'],
                'title' => $video['title'],
                'clientName' => $video['client_name'],
                'projectName' => $video['project_name'],
                'version' => $shown === null ? null : (int) $shown['number'],
                'approved' => $shown !== null && $shown['status'] === 'approved',
                'thumbUrl' => $image === null ? null : $signer->url((string) $image),
            ];
        }
        $link = $this->services->links()->forTarget('collection', (int) $collection['id']);

        return [
            'id' => (int) $collection['id'],
            'title' => $collection['title'],
            'introduction' => $collection['introduction'],
            'bgColor' => $collection['bg_color'],
            'textColor' => $collection['text_color'],
            'accentColor' => $collection['accent_color'],
            'logoUrl' => $collection['logo'] === null ? null : $signer->url((string) $collection['logo']),
            'videos' => $videos,
            'link' => $link === null ? null : $this->services->presenter()->link($link, null),
        ];
    }

    /**
     * Latest approved version, else latest published; null when nothing is published (never shown).
     *
     * @param list<array<string, mixed>> $versions newest first
     * @return array<string, mixed>|null
     */
    public static function shownVersion(array $versions): ?array
    {
        $published = array_values(array_filter($versions, static fn (array $v) => (int) $v['is_published'] === 1));
        foreach ($published as $version) {
            if ($version['status'] === 'approved') {
                return $version;
            }
        }

        return $published[0] ?? null;
    }
}
