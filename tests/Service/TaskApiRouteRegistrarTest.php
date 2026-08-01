<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\TaskApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleTask\ModuleTask;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da Task API ostaje opcionalan i registrira potpuni skup ruta.
 * EN: Verifies that the Task API remains optional and registers its complete route set.
 */
#[CoversClass(TaskApiRouteRegistrar::class)]
final class TaskApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira read, state i history rute kada je Task modul dostupan.
     * EN: Registers read, state, and history routes when the Task module is available.
     */
    public function testRegistersTaskRoutesOnlyWhenModuleIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new TaskApiRouteRegistrar(
            $this->composer(true),
            new Config(new Helper(), [
                'app' => [
                    'modules' => [
                        'enabled' => [ModuleTask::PACKAGE_NAME],
                    ],
                ],
            ]),
            $routes,
        );

        $registrar->register();

        $namedRoutes = $routes->getNamedRoutes();
        $this->assertCount(4, $namedRoutes);
        $this->assertSame(
            '/api/v1/pages/{documentId}/tasks/{taskUuid}/state',
            $namedRoutes['api.v1.pages.tasks.state']['path'] ?? null,
        );
        $route = $routes->getRoutes()['GET']['/api/v1/pages/{documentId}/tasks'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne registrira rute kada Task nije instaliran ili nije uključen.
     * EN: Registers no routes when Task is missing or disabled.
     */
    public function testSkipsRoutesWhenTaskIsUnavailable(): void
    {
        foreach (
            [
                [false, [ModuleTask::PACKAGE_NAME]],
                [true, []],
            ] as [$installed, $enabled]
        ) {
            $routes = new Routes();
            $registrar = new TaskApiRouteRegistrar(
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
     * HR: Vraća kontrolirani Composer most za simulaciju prisutnosti Task modula.
     * EN: Returns a controlled Composer bridge for simulating Task module availability.
     */
    private function composer(bool $taskInstalled): ComposerBridge
    {
        return new class ($taskInstalled) extends ComposerBridge {
            /**
             * HR: Sprema treba li test prijaviti Task kao instaliran.
             * EN: Stores whether the test should report Task as installed.
             */
            public function __construct(private readonly bool $taskInstalled)
            {
            }

            /**
             * HR: Vraća kontrolirano stanje samo za Task paket.
             * EN: Returns controlled installation state only for the Task package.
             */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleTask::PACKAGE_NAME
                    && $this->taskInstalled;
            }
        };
    }
}
