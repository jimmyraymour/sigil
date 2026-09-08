<?php
declare(strict_types=1);

namespace Sigil\Api;

use Sigil\Util\ApiException;

/**
 * Minimal regex-based router. No framework, no magic — just method + pattern.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function dispatch(string $method, string $path, array $params): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $m)) {
                array_shift($m); // drop the full-match element
                call_user_func($route['handler'], $m, $params);
                return;
            }
        }
        throw ApiException::notFound('Endpoint not found');
    }
}
