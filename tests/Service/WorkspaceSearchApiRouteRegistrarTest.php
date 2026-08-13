<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\WorkspaceSearchApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleWorkspaceSearch\ModuleWorkspaceSearch;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da pretraga ostaje opcionalna API integracija.
 * EN: Verifies that search remains an optional API integration.
 */
#[CoversClass(WorkspaceSearchApiRouteRegistrar::class)]
final class WorkspaceSearchApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira jednu ACL-svjesnu rutu samo kada je modul dostupan.
     * EN: Registers one ACL-aware route only when the module is available.
     */
    public function testRegistersSearchRouteOnlyWhenModuleIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new WorkspaceSearchApiRouteRegistrar(
            $this->composer(true),
            $this->config([ModuleWorkspaceSearch::PACKAGE_NAME]),
            $routes,
        );

        $registrar->register();

        $named = $routes->getNamedRoutes();
        $this->assertSame(
            '/api/v1/workspace-search',
            $named['api.v1.workspace-search']['path'] ?? null,
        );
        $route = $routes->getRoutes()['GET']['/api/v1/workspace-search'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne dodaje rutu za neinstalirani ili isključeni modul.
     * EN: Adds no route for a missing or disabled module.
     */
    public function testSkipsUnavailableSearchModule(): void
    {
        foreach ([[false, [ModuleWorkspaceSearch::PACKAGE_NAME]], [true, []]] as [$installed, $enabled]) {
            $routes = new Routes();
            $registrar = new WorkspaceSearchApiRouteRegistrar(
                $this->composer($installed),
                $this->config($enabled),
                $routes,
            );

            $registrar->register();
            $this->assertSame([], $routes->getNamedRoutes());
        }
    }

    /** @param list<string> $enabled */
    private function config(array $enabled): Config
    {
        return new Config(new Helper(), ['app' => ['modules' => ['enabled' => $enabled]]]);
    }

    private function composer(bool $installed): ComposerBridge
    {
        return new class ($installed) extends ComposerBridge {
            public function __construct(private readonly bool $installed)
            {
            }

            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleWorkspaceSearch::PACKAGE_NAME && $this->installed;
            }
        };
    }
}
