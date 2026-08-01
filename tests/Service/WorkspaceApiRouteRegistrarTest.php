<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\WorkspaceApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da opcionalna Workspace integracija ne stvara skrivenu obveznu ovisnost.
 * EN: Verifies that optional Workspace integration creates no hidden mandatory dependency.
 */
#[CoversClass(WorkspaceApiRouteRegistrar::class)]
final class WorkspaceApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira puni verzionirani Workspace API kada je modul instaliran i uključen.
     * EN: Registers the complete versioned Workspace API when the module is installed and enabled.
     */
    public function testRegistersWorkspaceRoutesOnlyWhenModuleIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new WorkspaceApiRouteRegistrar(
            $this->composer(true),
            new Config(new Helper(), [
                'app' => [
                    'modules' => [
                        'enabled' => [ModuleWorkspace::PACKAGE_NAME],
                    ],
                ],
            ]),
            $routes,
        );

        $registrar->register();

        $namedRoutes = $routes->getNamedRoutes();
        $this->assertCount(17, $namedRoutes);
        $this->assertSame(
            '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}/acl',
            $namedRoutes['api.v1.workspaces.nodes.acl.replace']['path'] ?? null,
        );

        $workspaceRoutes = $routes->getRoutes();
        $route = $workspaceRoutes['GET']['/api/v1/workspaces'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne registrira Workspace rute kada paket nije instaliran ili nije uključen.
     * EN: Registers no Workspace routes when the package is missing or disabled.
     */
    public function testSkipsRoutesWhenWorkspaceIsUnavailable(): void
    {
        foreach (
            [
                [false, [ModuleWorkspace::PACKAGE_NAME]],
                [true, []],
            ] as [$installed, $enabled]
        ) {
            $routes = new Routes();
            $registrar = new WorkspaceApiRouteRegistrar(
                $this->composer($installed),
                new Config(new Helper(), [
                    'app' => [
                        'modules' => [
                            'enabled' => $enabled,
                        ],
                    ],
                ]),
                $routes,
            );

            $registrar->register();
            $this->assertSame([], $routes->getNamedRoutes());
        }
    }

    /**
     * HR: Vraća kontrolirani Composer most za simulaciju instaliranog paketa.
     * EN: Returns a controlled Composer bridge for simulating an installed package.
     */
    private function composer(bool $workspaceInstalled): ComposerBridge
    {
        return new class ($workspaceInstalled) extends ComposerBridge {
            /**
             * HR: Sprema treba li test prijaviti Workspace kao instaliran.
             * EN: Stores whether the test should report Workspace as installed.
             */
            public function __construct(private readonly bool $workspaceInstalled)
            {
            }

            /**
             * HR: Vraća kontrolirano stanje samo za Workspace paket.
             * EN: Returns the controlled state only for the Workspace package.
             */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleWorkspace::PACKAGE_NAME
                    && $this->workspaceInstalled;
            }
        };
    }
}
