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

final class ProjectsController
{
    public function __construct(private readonly Services $services)
    {
    }

    public function list(Request $request): Response
    {
        $clientId = $request->queryString('client');
        $rows = $this->services->projects()->list(
            $clientId !== null && ctype_digit($clientId) ? (int) $clientId : null,
            $request->queryString('archived') === '1',
        );

        return Response::json(array_map($this->services->presenter()->project(...), $rows));
    }

    public function show(int $id): Response
    {
        return Response::json($this->services->presenter()->project($this->find($id)));
    }

    public function create(Request $request): Response
    {
        $input = new Input($request->json());
        $clientId = $input->int('clientId', 'Client');
        $name = $input->string('name', 'Nom', 160);
        if ($this->services->clients()->find($clientId) === null) {
            throw new HttpException(422, 'Ce client n’existe pas.', ['field' => 'clientId']);
        }
        $id = $this->services->projects()->create($clientId, $name);

        return Response::json($this->services->presenter()->project($this->find($id)), 201);
    }

    public function update(int $id, Request $request): Response
    {
        $this->find($id);
        $input = new Input($request->json());
        if ($input->has('name')) {
            $this->services->projects()->rename($id, $input->string('name', 'Nom', 160));
        }
        if ($input->has('archived')) {
            $this->services->projects()->setArchived($id, $input->bool('archived', 'Archivé'));
        }

        return Response::json($this->services->presenter()->project($this->find($id)));
    }

    /** Deletes the project with its videos, their links and files. */
    public function delete(int $id): Response
    {
        $project = $this->find($id);
        $services = $this->services;
        $videos = $services->videos()->forProjects([$id]);
        $parts = $services->uploads()->idsForVideos(array_map('intval', array_column($videos, 'id')));
        Database::transaction($services->db(), function () use ($services, $id, $videos): void {
            $videoIds = array_column($videos, 'id');
            $services->links()->deleteFor('client', $videoIds);
            $services->links()->deleteFor('view', $videoIds);
            $services->projects()->delete($id);
            $services->tags()->pruneUnused();
        });
        foreach ($videos as $video) {
            $services->storage()->deleteTree(Storage::videoMediaDir((int) $project['client_id'], $video['number']));
        }
        foreach ($parts as $part) {
            $services->storage()->deleteFile(Storage::uploadPart($part));
        }

        return Response::json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->projects()->find($id) ?? throw HttpException::notFound();
    }
}
