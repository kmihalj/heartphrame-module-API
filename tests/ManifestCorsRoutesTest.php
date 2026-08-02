<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests;

use AaiEduHr\HeartPhrameModuleApi\Controller\ApiPreflightController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava CORS zaštitu baznih ruta prije nego ih framework registrira.
 * EN: Verifies base-route CORS coverage before the framework registers them.
 */
#[CoversNothing]
final class ManifestCorsRoutesTest extends TestCase
{
    /**
     * HR: Svaka bazna API putanja ima CORS middleware i jedan preflight.
     * EN: Every base API path has CORS middleware and one preflight route.
     */
    public function testEveryBaseApiPathHasCorsMiddlewareAndPreflight(): void
    {
        $manifest = require dirname(__DIR__) . '/heartphrame-manifest.php';
        $routes = $manifest->getBaseRoutes();
        $apiPaths = [];
        $preflightPaths = [];

        foreach ($routes as $route) {
            $method = is_string($route[0] ?? null) ? $route[0] : '';
            $path = is_string($route[1] ?? null) ? $route[1] : '';
            if (!str_starts_with($path, '/api/v1')) {
                continue;
            }

            $middleware = is_array($route[4] ?? null) ? $route[4] : [];
            $this->assertContains(ApiCorsMiddleware::class, $middleware, $method . ' ' . $path);
            if ($method === 'OPTIONS') {
                $this->assertSame(ApiPreflightController::class . '@handle', $route[2] ?? null);
                $preflightPaths[$path] = ($preflightPaths[$path] ?? 0) + 1;
                continue;
            }

            $apiPaths[$path] = true;
        }

        $this->assertNotEmpty($apiPaths);
        foreach (array_keys($apiPaths) as $path) {
            $this->assertSame(1, $preflightPaths[$path] ?? 0, $path);
        }
    }
}
