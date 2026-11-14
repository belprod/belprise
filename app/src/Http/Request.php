<?php

declare(strict_types=1);

namespace Belprise\Http;

final class Request
{
    /** Largest JSON or form body read (bigger ones get a 413 without being read). */
    public const MAX_BODY = 1024 * 1024;

    /** @var array<string, string> lower-cased header names */
    private readonly array $headers;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, string> $cookies
     * @param array<string, array{name: string, tmp_name: string, size: int, error: int}> $files
     * @param array<string, mixed>  $post  form fields of a multipart body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        public readonly array $query = [],
        private ?string $body = '',
        public readonly string $remoteAddr = '',
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $post = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($name, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        $files = [];
        foreach ($_FILES as $field => $file) {
            // Single-file fields only.
            if (is_array($file) && is_string($file['tmp_name'] ?? null)) {
                $files[(string) $field] = [
                    'name' => (string) $file['name'],
                    'tmp_name' => $file['tmp_name'],
                    'size' => (int) $file['size'],
                    'error' => (int) $file['error'],
                ];
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $headers,
            $_GET,
            null, // read on demand, within a size limit (see body())
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            array_filter($_COOKIE, 'is_string'),
            $files,
            $_POST,
        );
    }

    /**
     * Raw body, read only when a handler asks for it: nothing is buffered
     * for requests refused earlier (bots, missing session, unknown route).
     */
    public function body(int $maxBytes = self::MAX_BODY): string
    {
        if ($this->body === null) {
            $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($declared > $maxBytes) {
                throw self::tooLarge();
            }
            $input = fopen('php://input', 'rb');
            $this->body = $input === false ? '' : (string) stream_get_contents($input, $maxBytes + 1);
        }
        if (strlen($this->body) > $maxBytes) {
            throw self::tooLarge();
        }

        return $this->body;
    }

    private static function tooLarge(): HttpException
    {
        return new HttpException(413, 'Requête trop volumineuse.');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function queryString(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Decoded JSON body; an empty body is an empty object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $body = $this->body();
        if (trim($body) === '') {
            return [];
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'Requête invalide.');
        }
        if (!is_array($data)) {
            throw new HttpException(400, 'Requête invalide.');
        }

        return $data;
    }

    /**
     * Form fields: URL-encoded body, or the text fields of a multipart body.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        if ($this->post !== []) {
            return $this->post;
        }
        parse_str($this->body(), $data);

        return $data;
    }

    /**
     * Network a client is counted in for rate limits: the IPv4 address, or
     * the /64 prefix of an IPv6 address (a single subscriber line).
     */
    public static function network(string $ip): string
    {
        if (!str_contains($ip, ':')) {
            return $ip;
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")) {
            return (string) inet_ntop(substr($packed, 12)); // IPv4-mapped
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Client IP, honouring X-Forwarded-For only from trusted proxies.
     *
     * @param list<string> $trustedProxies
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $ip = $this->remoteAddr;
        if (!in_array($ip, $trustedProxies, true)) {
            return $ip;
        }
        $forwarded = array_map('trim', explode(',', $this->header('x-forwarded-for') ?? ''));
        // Walk from the closest hop; the first untrusted address is the client.
        foreach (array_reverse($forwarded) as $hop) {
            if ($hop !== '' && !in_array($hop, $trustedProxies, true)) {
                return $hop;
            }
        }

        return $ip;
    }
}
