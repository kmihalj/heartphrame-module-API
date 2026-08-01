<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\ApiPreflightController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use HeartPhrame\Routing\Routes;

/**
 * HR: Naknadno omata sve aktivne API rute CORS slojem i dodaje OPTIONS rute.
 *
 * EN: Decorates every active API route with CORS and adds matching OPTIONS routes.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiCorsRouteRegistrarTest
 */
final readonly class ApiCorsRouteRegistrar
{
    /**
     * HR: Prima zajednički registar u kojem su već aktivirane opcionalne rute.
     *
     * EN: Receives the shared registry after optional routes have been activated.
     */
    public function __construct(private Routes $routes)
    {
    }

    /**
     * HR: Dodaje CORS kao prvi middleware i po jedan preflight za svaku putanju.
     *
     * EN: Adds CORS as the first middleware and one preflight route per path.
     */
    public function register(): void
    {
        $named = $this->routeNames();
        $paths = [];

        foreach ($this->routes->getRoutes() as $method => $routes) {
            foreach ($routes as $path => $route) {
                if (!str_starts_with((string)$path, '/api/v1')) {
                    continue;
                }

                if ($method === 'OPTIONS') {
                    continue;
                }

                $middleware = $this->middleware($route['middleware'] ?? []);
                $this->routes->addRoute(
                    $method,
                    $path,
                    $route['handler'],
                    $named[$method . ' ' . $path] ?? null,
                    $middleware,
                );
                $paths[$path] = true;
            }
        }

        foreach (array_keys($paths) as $path) {
            $this->routes->addRoute(
                'OPTIONS',
                $path,
                ApiPreflightController::class . '@handle',
                null,
                [ApiCorsMiddleware::class],
            );
        }
    }

    /**
     * HR: Gradi mapu HTTP metode i putanje prema stabilnom imenu rute.
     *
     * EN: Builds an HTTP-method and path map to each stable route name.
     *
     * @return array<string,string>
     */
    private function routeNames(): array
    {
        $names = [];
        foreach ($this->routes->getNamedRoutes() as $name => $route) {
            $names[$route['method'] . ' ' . $route['path']] = $name;
        }

        return $names;
    }

    /**
     * HR: Čuva postojeći redoslijed middlewarea i CORS postavlja na vanjski rub.
     *
     * EN: Preserves existing middleware order and places CORS at the outer edge.
     *
     * @param array<string|\Psr\Http\Server\MiddlewareInterface> $middleware
     * @return array<string|\Psr\Http\Server\MiddlewareInterface>
     */
    private function middleware(array $middleware): array
    {
        foreach ($middleware as $item) {
            if ($item === ApiCorsMiddleware::class || $item instanceof ApiCorsMiddleware) {
                return $middleware;
            }
        }

        array_unshift($middleware, ApiCorsMiddleware::class);

        return $middleware;
    }
}
