<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Contract;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use HeartPhrame\Routing\Routes;
use InvalidArgumentException;
use RuntimeException;

/**
 * HR: Sigurno registrira domenske API rute i automatski primjenjuje zajedničku
 *     API autentikaciju. CORS i OPTIONS dodaju se nakon svih proširenja.
 *
 * EN: Safely registers domain API routes and automatically applies shared API
 *     authentication. CORS and OPTIONS are added after all extensions.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Contract\ApiRouteRegistryTest
 */
final readonly class ApiRouteRegistry
{
    /** HR: Prima zajednički framework router. EN: Receives the shared framework router. */
    public function __construct(private Routes $routes)
    {
    }

    /**
     * HR: Dodaje jednu verzioniranu API rutu uz zaštitu od kolizija.
     * EN: Adds one versioned API route while protecting against collisions.
     *
     * @param class-string $controller
     */
    public function add(
        string $method,
        string $path,
        string $controller,
        string $action,
        string $name,
    ): void {
        $method = strtoupper(trim($method));
        $path = '/' . ltrim(trim($path), '/');
        $name = trim($name);
        $action = trim($action);

        if ($method === '' || $action === '' || $name === '') {
            throw new InvalidArgumentException('API route method, action, and name are required.');
        }

        if ($path !== '/api/v1' && !str_starts_with($path, '/api/v1/')) {
            throw new InvalidArgumentException('API extension routes must use the /api/v1 prefix.');
        }

        if (!class_exists($controller)) {
            throw new RuntimeException('API route controller is not available: ' . $controller);
        }

        $existing = $this->routes->getRoutes()[$method][$path] ?? null;
        if (is_array($existing)) {
            throw new RuntimeException('Duplicate API route: ' . $method . ' ' . $path);
        }

        if (isset($this->routes->getNamedRoutes()[$name])) {
            throw new RuntimeException('Duplicate API route name: ' . $name);
        }

        $this->routes->addRoute(
            $method,
            $path,
            $controller . '@' . $action,
            $name,
            [ApiAuthenticationMiddleware::class],
        );
    }
}
