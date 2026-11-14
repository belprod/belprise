<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Services;
use Belprise\Storage;
use Belprise\Support\Input;

final class ClientsController
{
    public function __construct(private readonly Services $services)
    {
    }

    public function list(Request $request): Response
    {
        $rows = $this->services->clients()->list($request->queryString('archived') === '1');
        $links = $this->services->links();

        return Response::json(array_map(
            fn (array $row) => $this->services->presenter()->client($row, $links->forTarget('space', (int) $row['id'])),
            $rows,
        ));
    }

    public function show(int $id): Response
    {
        return Response::json($this->present($this->find($id)));
    }

    public function create(Request $request): Response
    {
        $fields = $this->fields(new Input($request->json()));
        $id = Database::transaction($this->services->db(), function () use ($fields): int {
            $id = $this->services->clients()->create($fields);
            $this->services->links()->create('space', $id);

            return $id;
        });

        return Response::json($this->present($this->find($id)), 201);
    }

    public function update(int $id, Request $request): Response
    {
        $this->find($id);
        $input = new Input($request->json());
        if ($input->has('archived')) {
            $this->services->clients()->setArchived($id, $input->bool('archived', 'Archivé'));
        }
        if ($input->has('name')) {
            $this->services->clients()->update($id, $this->fields($input));
        }

        return Response::json($this->present($this->find($id)));
    }

    /**
     * Deletes the client with its projects, videos, links and files.
     * The client name must be typed again (reinforced confirmation).
     */
    public function delete(int $id, Request $request): Response
    {
        $client = $this->find($id);
        $confirmation = (new Input($request->json()))->text('confirm', 'Confirmation', 200);
        if (mb_strtolower($confirmation) !== mb_strtolower((string) $client['name'])) {
            throw new HttpException(422, 'Le nom saisi ne correspond pas au client.', ['field' => 'confirm']);
        }

        $services = $this->services;
        $parts = $services->uploads()->idsForVideos(array_map('intval', array_column($services->videos()->forProjects($services->projects()->idsForClient($id)), 'id')));
        Database::transaction($services->db(), function () use ($services, $id): void {
            $projectIds = $services->projects()->idsForClient($id);
            $videoIds = array_column($services->videos()->forProjects($projectIds), 'id');
            $services->links()->deleteFor('space', [$id]);
            $services->links()->deleteFor('client', $videoIds);
            $services->links()->deleteFor('view', $videoIds);
            $services->clients()->delete($id);
            $services->tags()->pruneUnused();
        });
        $services->storage()->deleteFile($client['logo'] === null ? null : (string) $client['logo']);
        $services->storage()->deleteTree(Storage::clientMediaDir($id));
        foreach ($parts as $part) {
            $services->storage()->deleteFile(Storage::uploadPart($part));
        }

        return Response::json(['deleted' => true]);
    }

    public function uploadLogo(int $id, Request $request): Response
    {
        $client = $this->find($id);
        $upload = $request->files['logo'] ?? null;
        if ($upload === null) {
            throw new HttpException(422, 'Choisissez un fichier.', ['field' => 'logo']);
        }
        $path = $this->services->logos()->store($id, $upload);
        $this->services->clients()->setLogo($id, $path);
        $this->services->storage()->deleteFile($client['logo'] === null ? null : (string) $client['logo']);

        return Response::json($this->present($this->find($id)));
    }

    public function deleteLogo(int $id): Response
    {
        $client = $this->find($id);
        $this->services->clients()->setLogo($id, null);
        $this->services->storage()->deleteFile($client['logo'] === null ? null : (string) $client['logo']);

        return Response::json($this->present($this->find($id)));
    }

    /**
     * @return array{name: string, bg_color: string, text_color: string, accent_color: string, welcome_text: string}
     */
    private function fields(Input $input): array
    {
        return [
            'name' => $input->string('name', 'Nom', 120),
            'bg_color' => $input->color('bgColor', 'Fond'),
            'text_color' => $input->color('textColor', 'Texte'),
            'accent_color' => $input->color('accentColor', 'Accent'),
            'welcome_text' => $input->text('welcomeText', 'Texte d’accueil', 2000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->clients()->find($id) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function present(array $client): array
    {
        return $this->services->presenter()->client(
            $client,
            $this->services->links()->forTarget('space', (int) $client['id']),
        );
    }
}
