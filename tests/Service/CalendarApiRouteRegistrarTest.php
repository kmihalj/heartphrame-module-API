<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\CalendarApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Routing\Routes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da Calendar API ostaje opcionalan i registrira potpuni skup ruta.
 * EN: Verifies that the Calendar API remains optional and registers its complete route set.
 */
#[CoversClass(CalendarApiRouteRegistrar::class)]
final class CalendarApiRouteRegistrarTest extends TestCase
{
    /**
     * HR: Registrira Calendar, event, ACL i ICS rute kada je modul dostupan.
     * EN: Registers Calendar, event, ACL, and ICS routes when the module is available.
     */
    public function testRegistersCalendarRoutesOnlyWhenModuleIsAvailable(): void
    {
        $routes = new Routes();
        $registrar = new CalendarApiRouteRegistrar(
            $this->composer(true),
            new Config(new Helper(), [
                'app' => [
                    'modules' => [
                        'enabled' => [ModuleCalendar::PACKAGE_NAME],
                    ],
                ],
            ]),
            $routes,
        );

        $registrar->register();

        $namedRoutes = $routes->getNamedRoutes();
        $this->assertCount(13, $namedRoutes);
        $this->assertSame(
            '/api/v1/calendars/import',
            $namedRoutes['api.v1.calendars.import']['path'] ?? null,
        );
        $this->assertSame(
            '/api/v1/calendars/{calendarUuid}/export.ics',
            $namedRoutes['api.v1.calendars.export']['path'] ?? null,
        );
        $route = $routes->getRoutes()['POST']['/api/v1/calendars/{calendarUuid}/events'] ?? [];
        $this->assertContains(ApiAuthenticationMiddleware::class, $route['middleware'] ?? []);
    }

    /**
     * HR: Ne registrira rute kada Calendar nije instaliran ili nije uključen.
     * EN: Registers no routes when Calendar is missing or disabled.
     */
    public function testSkipsRoutesWhenCalendarIsUnavailable(): void
    {
        foreach (
            [
                [false, [ModuleCalendar::PACKAGE_NAME]],
                [true, []],
            ] as [$installed, $enabled]
        ) {
            $routes = new Routes();
            $registrar = new CalendarApiRouteRegistrar(
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
     * HR: Vraća kontrolirani Composer most za simulaciju prisutnosti Calendara.
     * EN: Returns a controlled Composer bridge for simulating Calendar availability.
     */
    private function composer(bool $calendarInstalled): ComposerBridge
    {
        return new class ($calendarInstalled) extends ComposerBridge {
            /**
             * HR: Sprema treba li test prijaviti Calendar kao instaliran.
             * EN: Stores whether the test should report Calendar as installed.
             */
            public function __construct(private readonly bool $calendarInstalled)
            {
            }

            /**
             * HR: Vraća kontrolirano stanje samo za Calendar paket.
             * EN: Returns controlled installation state only for the Calendar package.
             */
            public function isInstalled(string $packageName): bool
            {
                return $packageName === ModuleCalendar::PACKAGE_NAME
                    && $this->calendarInstalled;
            }
        };
    }
}
