<?php

declare(strict_types=1);

namespace Belprise\Controller\Admin;

use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\Support\Input;

/**
 * Options of a link: active, password, expiry, regeneration (§2).
 */
final class LinksController
{
    public const MIN_PASSWORD_LENGTH = 4;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Body fields, all optional: isActive (bool), password (string to set,
     * null to remove), expiresOn ("YYYY-MM-DD" or null).
     */
    public function update(int $id, Request $request): Response
    {
        $link = $this->find($id);
        $input = new Input($request->json());
        $fields = [];

        if ($input->has('isActive')) {
            $fields['is_active'] = $input->bool('isActive', 'Actif') ? 1 : 0;
        }
        if ($input->has('password')) {
            $fields['password_hash'] = null;
            if ($request->json()['password'] !== null) {
                $password = $input->string('password', 'Mot de passe', 200);
                if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
                    throw new HttpException(
                        422,
                        'Le mot de passe doit compter au moins ' . self::MIN_PASSWORD_LENGTH . ' caractères.',
                        ['field' => 'password'],
                    );
                }
                $fields['password_hash'] = password_hash($password, PASSWORD_ARGON2ID);
            }
        }
        if ($input->has('expiresOn')) {
            $date = $input->date('expiresOn', 'Expiration');
            $fields['expires_at'] = $date === null ? null : Dates::localDateToExpiry($date);
        }

        $this->services->links()->update($id, $fields);

        return Response::json($this->present($this->find((int) $link['id'])));
    }

    public function regenerate(int $id): Response
    {
        $this->find($id);
        $this->services->links()->regenerate($id);

        return Response::json($this->present($this->find($id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->services->links()->find($id) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private function present(array $link): array
    {
        $number = null;
        if (!in_array($link['type'], ['space', 'collection'], true)) {
            $video = $this->services->videos()->find((int) $link['target_id']) ?? throw HttpException::notFound();
            $number = (int) $video['number'];
        }

        return $this->services->presenter()->link($link, $number);
    }
}
