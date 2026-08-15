<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Contract;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use HeartPhrame\Routing\Routes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** HR: Pokriva sigurnu registraciju domenskih ruta. EN: Covers secure domain-route registration. */
#[CoversClass(ApiRouteRegistry::class)]
final class ApiRouteRegistryTest extends TestCase
{
    /** HR: Ruta automatski dobiva autentikaciju. EN: A route receives authentication automatically. */
    public function testAddsAuthenticatedVersionedRoute(): void
    {
        $routes = new Routes();
        $registry = new ApiRouteRegistry($routes);

        $registry->add('get', '/api/v1/example', TestApiController::class, 'index', 'api.v1.example');

        $route = $routes->getRoutes()['GET']['/api/v1/example'] ?? [];
        $this->assertSame(TestApiController::class . '@index', $route['handler'] ?? null);
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware']);
    }

    /** HR: Odbija putanju izvan verzioniranog API-ja. EN: Rejects a path outside the versioned API. */
    public function testRejectsInvalidPrefix(): void
    {
        $registry = new ApiRouteRegistry(new Routes());

        $this->expectException(InvalidArgumentException::class);
        $registry->add('GET', '/api/v10/example', TestApiController::class, 'index', 'api.v10.example');
    }

    /** HR: Odbija koliziju metode i putanje. EN: Rejects a method-and-path collision. */
    public function testRejectsDuplicateRoute(): void
    {
        $registry = new ApiRouteRegistry(new Routes());
        $registry->add('GET', '/api/v1/example', TestApiController::class, 'index', 'api.v1.example');

        $this->expectException(RuntimeException::class);
        $registry->add('GET', '/api/v1/example', TestApiController::class, 'show', 'api.v1.example.show');
    }
}
