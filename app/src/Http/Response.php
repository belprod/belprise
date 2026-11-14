<?php

declare(strict_types=1);

namespace Belprise\Http;

class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, array{value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(
        public int $status = 200,
        public string $body = '',
        string $contentType = 'text/html; charset=utf-8',
    ) {
        $this->setHeader('Content-Type', $contentType);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'application/json; charset=utf-8',
        );
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, $text, 'text/plain; charset=utf-8');
    }

    public static function redirect(string $location): self
    {
        $response = new self(303);
        $response->setHeader('Location', $location);

        return $response;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $name . ': ' . $value;

        return $this;
    }

    public function header(string $name): ?string
    {
        $line = $this->headers[strtolower($name)] ?? null;

        return $line === null ? null : substr($line, strlen($name) + 2);
    }

    /**
     * @return array<string, string> header name => value
     */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * Cookie always HttpOnly and Secure; path "/" unless given.
     *
     * @param array{expires?: int, path?: string, samesite?: string} $options
     */
    public function setCookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[$name] = [
            'value' => $value,
            'options' => $options + ['expires' => 0, 'path' => '/', 'samesite' => 'Strict']
                + ['secure' => true, 'httponly' => true],
        ];

        return $this;
    }

    /**
     * @return array<string, array{value: string, options: array<string, mixed>}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(bool $withBody = true): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $line) {
            header($line);
        }
        foreach ($this->cookies as $name => $cookie) {
            setcookie($name, $cookie['value'], $cookie['options']);
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}
