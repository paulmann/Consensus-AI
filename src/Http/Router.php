<?php
/**
 * Router: Minimal, framework-agnostic HTTP router with path parameters and CORS-friendly OPTIONS.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Class Router
 *
 * Simple regex-based router supporting:
 * - HTTP methods: GET, POST, DELETE, OPTIONS
 * - Path parameters: /api/skills/{id}, /api/council/session/{id}/step/{stepId}
 * - Basic CORS preflight handling via OPTIONS.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,variables:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function options(string $path, callable $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    /**
     * Dispatch incoming HTTP request.
     */
    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'OPTIONS') {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $vars = [];
            foreach ($route['variables'] as $index => $name) {
                $vars[$name] = $matches[$index + 1] ?? null;
            }

            if ($method === 'OPTIONS') {
                // CORS preflight can be handled by user-defined handler or simple default.
                ($route['handler'])(...array_values($vars));
                return;
            }

            ($route['handler'])(...array_values($vars));
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Route not found'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Register a route with HTTP method and path pattern.
     */
    private function addRoute(string $method, string $path, callable $handler): void
    {
        [$regex, $variables] = $this->compilePathPattern($path);

        $this->routes[] = [
            'method'    => strtoupper($method),
            'pattern'   => $path,
            'regex'     => $regex,
            'variables' => $variables,
            'handler'   => $handler,
        ];
    }

    /**
     * Compile a path pattern like "/api/skills/{id}" into a regex.
     *
     * Supports multiple parameters: /session/{id}/step/{stepId}.
     *
     * @return array{0:string,1:array<int,string>} [regex, variables]
     */
    private function compilePathPattern(string $path): array
    {
        $pattern   = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $variables = [];

        if ($pattern === null) {
            throw new RuntimeException('Failed to compile path pattern: ' . $path);
        }

        if (preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $path, $matches)) {
            $variables = $matches[1];
        }

        $regex = '#^' . $pattern . '$#';

        return [$regex, array_values($variables)];
    }
}
