<?php

declare(strict_types=1);

namespace Belprise\Http;

/**
 * Minimal router. Patterns are full regular expressions matched against the
 * path; named groups become route parameters. GET routes also answer HEAD.
 */
final class Router
{
    /** @var list<array{string, string, callable(Request, array<string, string>): Response}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function put(string $pattern, callable $handler): void
    {
        $this->routes[] = ['PUT', $pattern, $handler];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function patch(string $pattern, callable $handler): void
    {
        $this->routes[] = ['PATCH', $pattern, $handler];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function delete(string $pattern, callable $handler): void
    {
        $this->routes[] = ['DELETE', $pattern, $handler];
    }

    /**
     * @return array{callable(Request, array<string, string>): Response, array<string, string>}|null
     */
    public function match(Request $request): ?array
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod === $method && preg_match($pattern, $request->path, $m) === 1) {
                $params = array_filter($m, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                return [$handler, $params];
            }
        }

        return null;
    }
}
