<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\NotificationApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleNotification\ModuleNotification;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Dokazuje da Notification API ostaje potpuno opcionalan.
 * EN: Proves that the Notification API remains entirely optional.
 */
#[CoversClass(NotificationApiRouteRegistrar::class)]
final class NotificationApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira inbox rute samo kada je Notification instaliran i uključen.
     * EN: Registers inbox routes only when Notification is installed and enabled.
     */
    public function testRegistersRoutesOnlyWhenNotificationIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new NotificationApiRouteRegistrar(
            $this->composer(true),
            new Config(new Helper(), [
                'app' => [
                    'modules' => [
                        'enabled' => [ModuleNotification::PACKAGE_NAME],
                    ],
                ],
            ]),
            $routes,
        );

        $registrar->register();

        $namedRoutes = $routes->getNamedRoutes();
        $this->assertCount(5, $namedRoutes);
        $this->assertSame(
            '/api/v1/notifications/{uuid}',
            $namedRoutes['api.v1.notifications.delete']['path'] ?? null,
        );
        $route = $routes->getRoutes()['GET']['/api/v1/notifications'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne registrira nijednu rutu kada paket nedostaje ili je isključen.
     * EN: Registers no routes when the package is missing or disabled.
     */
    public function testSkipsRoutesWhenNotificationIsUnavailable(): void
    {
        foreach (
            [
                [false, [ModuleNotification::PACKAGE_NAME]],
                [true, []],
            ] as [$installed, $enabled]
        ) {
            $routes = new Routes();
            $registrar = new NotificationApiRouteRegistrar(
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
     * HR: Vraća kontrolirani Composer most za simulaciju prisutnosti modula.
     * EN: Returns a controlled Composer bridge for simulating module availability.
     */
    private function composer(bool $notificationInstalled): ComposerBridge
    {
        return new class ($notificationInstalled) extends ComposerBridge {
            /**
             * HR: Sprema treba li test prijaviti Notification kao instaliran.
             * EN: Stores whether the test should report Notification as installed.
             */
            public function __construct(private readonly bool $notificationInstalled)
            {
            }

            /**
             * HR: Vraća kontrolirano stanje samo za Notification paket.
             * EN: Returns controlled installation state only for the Notification package.
             */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleNotification::PACKAGE_NAME
                    && $this->notificationInstalled;
            }
        };
    }
}
