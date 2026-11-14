<?php

declare(strict_types=1);

namespace Belprise\Controller;

use Belprise\Controller\Admin\CollectionsController;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Security\Keys;
use Belprise\Security\LinkAccess;
use Belprise\Services;
use Belprise\Support\Dates;
use Belprise\View\ClientTheme;
use Belprise\View\PublicPages;

/**
 * Client-facing pages: client space (/{key}) and video links
 * (/v/{number}{key}). Any failure is the neutral 404.
 */
final class PublicController
{
    public const ENTRY = 'src/client/main.ts';

    public function __construct(
        private readonly Services $services,
        private readonly string $clientIp,
    ) {
    }

    public function space(Request $request, string $key, string $nonce): Response
    {
        if (!Keys::isValid($key)) {
            throw HttpException::notFound();
        }
        $link = $this->services->links()->findByKey($key);
        if ($link !== null && $link['type'] === 'collection') {
            return $this->collection($request, $link, $key, $nonce);
        }
        if ($link === null || $link['type'] !== 'space') {
            throw HttpException::notFound();
        }
        $client = $this->services->clients()->find((int) $link['target_id']) ?? throw HttpException::notFound();

        return $this->gate($request, $link, '/' . $key, fn () => $this->page(
            (string) $client['name'],
            ['page' => 'space', 'client' => $this->client($client, true), 'projects' => $this->spaceProjects($client), 'library' => $this->library($client)],
            $client,
            $nonce,
        ));
    }

    /**
     * Projects of the client space (§5.3), most recent first. A video shows
     * once it has a published version and a usable client link (opening it
     * uses the rights of that link). Archived projects and videos are left out.
     *
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>>
     */
    private function spaceProjects(array $client): array
    {
        $access = $this->services->linkAccess();
        $projects = [];
        foreach ($this->services->projects()->list((int) $client['id'], false) as $project) {
            $cards = [];
            foreach ($this->services->videos()->list(null, (int) $project['id'], false) as $video) {
                $card = $this->card($video, $access);
                if ($card !== null) {
                    $cards[] = $card;
                }
            }
            if ($cards === []) {
                continue;
            }
            usort($cards, static fn (array $a, array $b): int => strcmp((string) $b['publishedAt'], (string) $a['publishedAt']));
            $projects[] = ['name' => $project['name'], 'updatedAt' => $cards[0]['publishedAt'], 'videos' => $cards];
        }
        usort($projects, static fn (array $a, array $b): int => strcmp((string) $b['updatedAt'], (string) $a['updatedAt']));

        return $projects;
    }

    /**
     * Collection for a prospect (§5.9): its videos in order, each with its
     * latest approved version (else latest published). Watch only: no
     * revisions, no download. Same link rules as the other links.
     *
     * @param array<string, mixed> $link
     */
    private function collection(Request $request, array $link, string $key, string $nonce): Response
    {
        $collection = $this->services->collections()->find((int) $link['target_id']) ?? throw HttpException::notFound();

        return $this->gate($request, $link, '/' . $key, function () use ($collection, $nonce): Response {
            $videos = [];
            foreach ($this->services->collections()->videoIds((int) $collection['id']) as $videoId) {
                $video = $this->services->videos()->find($videoId);
                $shown = $video === null ? null : CollectionsController::shownVersion($this->services->versions()->forVideo($videoId));
                if ($shown === null) {
                    continue; // never published: never shown
                }
                $seconds = (int) $shown['duration_frames'] * (int) $shown['fps_den'] / max(1, (int) $shown['fps_num']);
                $image = $shown['thumb_path'] ?? $shown['poster_path'];
                $videos[] = [
                    'title' => $video['title'],
                    'duration' => self::clock($seconds),
                    'thumbUrl' => $image === null ? null : $this->services->signer()->url((string) $image),
                    'version' => $this->version($shown, false),
                ];
            }
            // Without its own logo, the collection shows the organization's (§5.9).
            $organization = $this->services->organizations()->find((int) $collection['organization_id']) ?? [];
            $logo = $collection['logo'] ?? ($organization['logo'] ?? null);
            $brand = [
                'name' => (string) $collection['title'],
                'logo' => $logo,
                'bg_color' => $collection['bg_color'],
                'text_color' => $collection['text_color'],
                'accent_color' => $collection['accent_color'],
            ];

            return $this->page((string) $collection['title'], [
                'page' => 'collection',
                // Without a logo, the header names the organization behind the selection.
                'client' => ['name' => (string) ($organization['name'] ?? ''), 'logoUrl' => $logo === null ? null : $this->services->signer()->url((string) $logo)],
                'collection' => ['title' => $collection['title'], 'introduction' => $collection['introduction']],
                'videos' => $videos,
            ], $brand, $nonce);
        });
    }

    /**
     * Library of the client space (§5.3): archived videos, newest archive
     * first, each opened with the client link (watch and download only).
     *
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>>
     */
    private function library(array $client): array
    {
        $access = $this->services->linkAccess();
        $cards = [];
        foreach ($this->services->videos()->list((int) $client['id'], null, true) as $video) {
            if ($video['archived_at'] === null) {
                continue;
            }
            $card = $this->card($video, $access);
            if ($card !== null) {
                $cards[] = $card + ['projectName' => $video['project_name'], 'archivedAt' => Dates::toIso((string) $video['archived_at'])];
            }
        }
        usort($cards, static fn (array $a, array $b): int => strcmp((string) $b['archivedAt'], (string) $a['archivedAt']));

        return $cards;
    }

    /**
     * A video card of the space, or null when it has nothing to show or no usable link.
     *
     * @param array<string, mixed> $video
     * @return array<string, mixed>|null
     */
    private function card(array $video, LinkAccess $access): ?array
    {
        $link = $this->services->links()->forTarget('client', (int) $video['id']);
        $published = array_values(array_filter(
            $this->services->versions()->forVideo((int) $video['id']),
            static fn (array $v): bool => (int) $v['is_published'] === 1,
        ));
        $current = $published[0] ?? null;
        if ($current === null || $link === null || !$access->isUsable($link)) {
            return null;
        }
        $seconds = (int) $current['duration_frames'] * (int) $current['fps_den'] / max(1, (int) $current['fps_num']);
        $image = $current['thumb_path'] ?? $current['poster_path'];

        return [
            'number' => (int) $video['number'],
            'title' => $video['title'],
            'href' => '/v/' . $video['number'] . $link['key'],
            // The light thumbnail when there is one, else the poster.
            'posterUrl' => $image === null ? null : $this->services->signer()->url((string) $image),
            'duration' => self::clock($seconds),
            'version' => (int) $current['number'],
            'status' => $current['status'],
            'publishedAt' => $current['published_at'] === null ? null : Dates::toIso((string) $current['published_at']),
        ];
    }

    /** "1:05", "12:40", "1:02:03" */
    private static function clock(float $seconds): string
    {
        $total = (int) round($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    public function video(Request $request, string $ref, string $nonce): Response
    {
        $parsed = Keys::parseVideoRef($ref) ?? throw HttpException::notFound();
        $link = $this->services->links()->findByKey($parsed['key']);
        if ($link === null || !in_array($link['type'], ['client', 'view'], true)) {
            throw HttpException::notFound();
        }
        $video = $this->services->videos()->find((int) $link['target_id']);
        if ($video === null || (int) $video['number'] !== $parsed['number']) {
            throw HttpException::notFound();
        }
        $client = $this->services->clients()->find((int) $video['client_id']) ?? throw HttpException::notFound();

        // Only what the link may show is built, and only once the link is unlocked.
        $render = function () use ($link, $client, $video, $nonce): Response {
            $published = array_values(array_filter(
                $this->services->versions()->forVideo((int) $video['id']),
                static fn (array $v): bool => (int) $v['is_published'] === 1,
            ));
            $boot = [
                'page' => $link['type'] === 'client' ? 'review' : 'view',
                'client' => $this->client($client, false),
                'video' => ['number' => (int) $video['number'], 'title' => $video['title']],
            ];
            if ($link['type'] === 'client') {
                $boot['video']['downloadAllowed'] = (int) $video['download_allowed'] === 1;
            }
            if ($link['type'] === 'client' && $video['archived_at'] !== null) {
                // Library (§5.3): the version kept by the archiving, to watch and download.
                $boot['page'] = 'library';
                $boot['project'] = ['name' => $video['project_name']];
                $boot['versions'] = $published === [] ? [] : [$this->version($published[0], true)];
                $boot['archivedAt'] = Dates::toIso((string) $video['archived_at']);
            } elseif ($link['type'] === 'client') {
                // Every published version, newest first (§5.1).
                $boot['project'] = ['name' => $video['project_name']];
                $boot['csrf'] = $this->services->linkAccess()->csrfToken($link);
                $boot['versions'] = array_map(fn (array $v) => $this->version($v, true), $published);
            } else {
                // Latest approved version, else latest published; nothing else (§5.2).
                $approved = array_values(array_filter($published, static fn (array $v): bool => $v['status'] === 'approved'));
                $shown = $approved[0] ?? $published[0] ?? null;
                $boot['versions'] = $shown === null ? [] : [$this->version($shown, false)];
            }

            return $this->page((string) $video['title'], $boot, $client, $nonce);
        };

        return $this->gate($request, $link, '/v/' . $ref, $render);
    }

    /**
     * Applies the link rules, then renders the page or the password form.
     *
     * @param array<string, mixed> $link
     * @param callable(): Response $render
     */
    private function gate(Request $request, array $link, string $path, callable $render): Response
    {
        $access = $this->services->linkAccess();
        if (!$access->isUsable($link)) {
            throw HttpException::notFound();
        }

        $unlocked = !$access->needsPassword($link)
            || $access->hasValidToken($link, $request->cookie($access->cookieName($link)));

        if ($request->method === 'POST') {
            return $unlocked ? Response::redirect($path) : $this->checkPassword($request, $link, $path);
        }
        if (!$unlocked) {
            return $this->passwordForm(null, 200);
        }

        return $render();
    }

    /**
     * @param array<string, mixed> $link
     */
    private function checkPassword(Request $request, array $link, string $path): Response
    {
        $limiter = $this->services->rateLimiter();
        $key = $this->services->clientKey($this->clientIp . '|link:' . $link['id']);

        $wait = $limiter->attempt(LinkAccess::THROTTLE_SCOPE, $key);
        if ($wait > 0) {
            return $this->passwordForm('Trop de tentatives. Réessayez dans ' . self::delay($wait) . '.', 429);
        }

        $password = $request->form()['password'] ?? '';
        if (!is_string($password) || !password_verify($password, (string) $link['password_hash'])) {
            $delay = $limiter->failed(LinkAccess::THROTTLE_SCOPE, $key);
            $message = 'Mot de passe incorrect.';
            if ($delay > 1) {
                $message .= ' Nouvel essai possible dans ' . self::delay($delay) . '.';
            }

            return $this->passwordForm($message, 200);
        }

        $limiter->clear(LinkAccess::THROTTLE_SCOPE, $key);
        $access = $this->services->linkAccess();
        $response = Response::redirect($path);
        // Lax: the cookie must follow a link opened from an e-mail.
        $response->setCookie($access->cookieName($link), $access->issueToken($link), ['samesite' => 'Lax']);

        return $response;
    }

    private function passwordForm(?string $error, int $status): Response
    {
        $response = Response::html(PublicPages::password($this->services->vite->cssUrl('src/styles/base.css'), $error), $status);
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $boot
     * @param array<string, mixed> $client
     */
    private function page(string $title, array $boot, array $client, string $nonce): Response
    {
        $theme = new ClientTheme((string) $client['bg_color'], (string) $client['text_color'], (string) $client['accent_color']);
        $response = Response::html($this->services->shell()->render(self::ENTRY, $title, $boot, $nonce, $theme));
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function client(array $client, bool $withWelcome): array
    {
        $out = [
            'name' => $client['name'],
            'logoUrl' => $client['logo'] === null ? null : $this->services->signer()->url((string) $client['logo']),
        ];
        if ($withWelcome) {
            $out['welcomeText'] = $client['welcome_text'];
        }

        return $out;
    }

    /**
     * A version as the client pages see it: what the player needs, plus the
     * status and technical details on the review page only.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function version(array $row, bool $details): array
    {
        $signer = $this->services->signer();
        $sprite = $row['sprite_meta'] === null || $row['sprite_path'] === null
            ? null
            : ['url' => $signer->url((string) $row['sprite_path'])] + json_decode((string) $row['sprite_meta'], true);
        $out = [
            'id' => (int) $row['id'],
            'number' => (int) $row['number'],
            'fpsNum' => (int) $row['fps_num'],
            'fpsDen' => (int) $row['fps_den'],
            'dropFrame' => (int) $row['drop_frame'] === 1,
            'startFrame' => (int) $row['start_frame'],
            'durationFrames' => (int) $row['duration_frames'],
            'videoUrl' => $signer->url((string) $row['file_path']),
            'hlsUrl' => ($row['hls_path'] ?? null) === null ? null : $signer->url((string) $row['hls_path']),
            'posterUrl' => $row['poster_path'] === null ? null : $signer->url((string) $row['poster_path']),
            'sprite' => $sprite,
        ];
        if ($details) {
            $out += [
                'status' => $row['status'],
                'publishedAt' => $row['published_at'] === null ? null : Dates::toIso((string) $row['published_at']),
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'size' => (int) $row['size_bytes'],
            ];
        }

        return $out;
    }

    private static function delay(int $seconds): string
    {
        return $seconds < 60 ? $seconds . ' s' : (int) ceil($seconds / 60) . ' min';
    }
}
