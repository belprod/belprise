<?php

declare(strict_types=1);

namespace Belprise\Tests\Support;

use Belprise\App;
use Belprise\Config;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Mail\Outbox;
use PHPUnit\Framework\TestCase;

/**
 * Boots the application on a temporary data directory and a fake Vite build.
 */
abstract class AppTestCase extends TestCase
{
    protected const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';

    protected string $tmp;
    protected ArraySession $session;
    protected App $app;
    protected Outbox $outbox;

    protected const ADMIN_EMAIL = 'jules@belprod.test';
    protected const ADMIN_PASSWORD = 'un-mot-de-passe-long';

    protected function setUp(): void
    {
        $this->tmp = TempDir::create();
        \Belprise\Security\AccountReset::$forgotMinimumSeconds = 0.0;
        mkdir($this->tmp . '/public/assets/.vite', 0700, true);
        file_put_contents($this->tmp . '/public/assets/.vite/manifest.json', json_encode([
            'src/admin/main.ts' => ['file' => 'admin-AAAAAAAA.js', 'isEntry' => true, 'css' => ['admin-BBBBBBBB.css']],
            'src/styles/base.css' => ['file' => 'base-CCCCCCCC.css', 'isEntry' => true],
            'src/client/main.ts' => ['file' => 'client-DDDDDDDD.js', 'isEntry' => true, 'css' => ['client-EEEEEEEE.css']],
        ]));

        $this->session = new ArraySession();
        $this->app = new App(
            Config::fromArray(['data_dir' => $this->tmp . '/data', 'secret' => str_repeat('s', 64)] + $this->extraConfig()),
            $this->tmp . '/public',
            dirname(__DIR__, 3) . '/app/migrations',
            $this->session,
        );
        \Belprise\DataDir::prepare($this->tmp . '/data');
        $this->outbox = new Outbox();
        $this->app->services()->useMailer($this->outbox);
    }

    /** @return array<string, mixed> configuration values added by a test class */
    protected function extraConfig(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, array{name: string, tmp_name: string, size: int, error: int}> $files
     * @param array<string, string> $post multipart text fields
     */
    /** Address of the simulated visitor. */
    protected string $remoteAddr = '203.0.113.7';

    protected function request(
        string $method,
        string $path,
        array $headers = [],
        string $body = '',
        array $cookies = [],
        array $files = [],
        array $post = [],
    ): Response {
        $headers += ['User-Agent' => self::BROWSER_UA];
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }

        return $this->app->handle(new Request($method, $path, $headers, $query, $body, $this->remoteAddr, $cookies, $files, $post));
    }

    /** Creates the admin account; the session is then logged in. */
    protected function loginAdmin(): void
    {
        $response = $this->postJson('/api/admin/setup', [
            'organization' => 'belprod', 'name' => 'Jules', 'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD, 'confirmation' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(200, $response->status);
    }

    /**
     * Admin API call with the CSRF token; returns the decoded body, after
     * checking the expected status.
     *
     * @param array<string, mixed>|null $data
     * @return array<mixed>
     */
    protected function api(string $method, string $path, ?array $data = null, int $expectedStatus = 200): array
    {
        $headers = ['X-CSRF-Token' => (string) ($this->session->data['csrf'] ?? $this->csrfToken())];
        $body = '';
        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data, JSON_THROW_ON_ERROR);
        }
        $response = $this->request($method, $path, $headers, $body);
        self::assertSame($expectedStatus, $response->status, $method . ' ' . $path . ' → ' . $response->body);

        return $this->decode($response);
    }

    /** Local folder where test masters are prepared before being sent like a browser does. */
    protected function stagingPath(string $name = ''): string
    {
        $dir = $this->tmp . '/staging';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir . ($name === '' ? '' : '/' . $name);
    }

    /**
     * Sends a prepared master the way the browser does (start, chunks, completion)
     * and returns the decoded answer of the last step.
     *
     * @param array{name: string, videoId: int|string, meta: array<string, mixed>} $data
     * @return array<mixed>
     */
    protected function attach(array $data, int $expectedStatus = 201): array
    {
        $name = $data['name'];
        $path = $this->stagingPath($name);
        $size = (int) filesize($path);
        $start = $this->request('POST', '/api/admin/uploads', [
            'X-CSRF-Token' => (string) ($this->session->data['csrf'] ?? $this->csrfToken()), 'Content-Type' => 'application/json',
        ], json_encode([
            'videoId' => (int) $data['videoId'], 'name' => $name, 'size' => $size,
            'fingerprint' => \Belprise\Controller\Admin\UploadsController::fingerprint($path, $name),
        ], JSON_THROW_ON_ERROR));
        if ($start->status !== 201 && $start->status !== 200) {
            self::assertSame($expectedStatus, $start->status, $start->body);

            return $this->decode($start);
        }
        $id = (int) $this->decode($start)['id'];
        $handle = fopen($path, 'rb');
        for ($offset = 0; $offset < $size; $offset += $chunk) {
            $bytes = (string) fread($handle, \Belprise\Controller\Admin\UploadsController::CHUNK_SIZE);
            $chunk = strlen($bytes);
            $put = $this->request('PUT', '/api/admin/uploads/' . $id . '?offset=' . $offset, [
                'X-CSRF-Token' => (string) $this->session->data['csrf'], 'Content-Type' => 'application/octet-stream',
                'X-Chunk-Sha256' => hash('sha256', $bytes),
            ], $bytes);
            self::assertSame(200, $put->status, $put->body);
        }
        fclose($handle);

        return $this->api('POST', '/api/admin/uploads/' . $id . '/complete', ['meta' => $data['meta']], $expectedStatus);
    }

    protected function db(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->tmp . '/data/belprise.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }

    /** A client with one project and one video, through the admin API. */
    protected function createClientProjectVideo(): array
    {
        $client = $this->api('POST', '/api/admin/clients', [
            'name' => 'Maison Aurore', 'bgColor' => '#F3EDE4', 'textColor' => '#1c1915',
            'accentColor' => '#a8481f', 'welcomeText' => 'Bienvenue',
        ], 201);
        $project = $this->api('POST', '/api/admin/projects', ['clientId' => $client['id'], 'name' => 'Printemps'], 201);
        $video = $this->api('POST', '/api/admin/videos', [
            'projectId' => $project['id'], 'title' => 'Film manifeste', 'tags' => ['pub'],
        ], 201);

        return ['client' => $client, 'project' => $project, 'video' => $video];
    }

    /**
     * POST JSON to the admin API with the current CSRF token.
     *
     * @param array<string, mixed> $data
     */
    protected function postJson(string $path, array $data = [], ?string $csrf = null): Response
    {
        $csrf ??= $this->csrfToken();

        return $this->request('POST', $path, [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrf,
        ], json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected function csrfToken(): string
    {
        return (string) $this->decode($this->request('GET', '/api/admin/session'))['csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        return json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
    }
}
