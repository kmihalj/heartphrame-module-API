<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCorsRouteRegistrar;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava automatsko CORS omatanje baznih i opcionalnih API ruta.
 *
 * EN: Verifies automatic CORS decoration of base and optional API routes.
 */
#[CoversClass(ApiCorsRouteRegistrar::class)]
final class ApiCorsRouteRegistrarTest extends TestCase
{
    /**
     * HR: Čuva naziv rute, dodaje CORS prvi i registrira OPTIONS bez autentikacije.
     *
     * EN: Preserves the route name, adds CORS first, and registers unauthenticated OPTIONS.
     */
    public function testDecoratesEveryApiRouteAndAddsPreflight(): void
    {
        $routes = new Routes();
        $routes->addRoute(
            'GET',
            '/api/v1/widgets/{widgetId}',
            'ExampleController@show',
            'api.v1.widgets.show',
            [ApiAuthenticationMiddleware::class],
        );
        $routes->addRoute('GET', '/health', 'HealthController@show', 'health');

        (new ApiCorsRouteRegistrar($routes))->register();

        $api = $routes->getRoutes()['GET']['/api/v1/widgets/{widgetId}'] ?? [];
        $preflight = $routes->getRoutes()['OPTIONS']['/api/v1/widgets/{widgetId}'] ?? [];
        $health = $routes->getRoutes()['GET']['/health'] ?? [];

        $this->assertSame(ApiCorsMiddleware::class, $api['middleware'][0] ?? null);
        $this->assertContains(ApiAuthenticationMiddleware::class, $api['middleware']);
        $this->assertSame([ApiCorsMiddleware::class], $preflight['middleware'] ?? []);
        $this->assertSame([], $health['middleware'] ?? []);
        $this->assertSame(
            '/api/v1/widgets/{widgetId}',
            $routes->getNamedRoutes()['api.v1.widgets.show']['path'] ?? null,
        );
    }
}
