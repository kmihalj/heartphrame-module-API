<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\CalendarResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleCalendar\Api\CalendarApiService;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

/**
 * HR: Uvjetno registrira Calendar API samo kada je modul instaliran i uključen.
 * EN: Conditionally registers the Calendar API only when its module is installed and enabled.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\CalendarApiRouteRegistrarTest
 */
final readonly class CalendarApiRouteRegistrar
{
    private const PACKAGE = 'aaieduhr/heartphrame-module-calendar';

    /**
     * HR: Prima stanje paketa, konfiguraciju i zajednički router.
     * EN: Receives package state, configuration, and the shared router.
     */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
        private Routes $routes,
    ) {
    }

    /**
     * HR: Dodaje Calendar rute nakon učitavanja opcionalnih modula.
     * EN: Adds Calendar routes after optional modules have loaded.
     */
    public function register(): void
    {
        if (!$this->calendarAvailable()) {
            return;
        }

        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $this->routes->addRoute(
                $method,
                $path,
                CalendarResourceController::class . '@' . $action,
                $name,
                [ApiAuthenticationMiddleware::class],
            );
        }
    }

    /**
     * HR: Provjerava instalaciju, uključivanje i autoload Calendar API servisa.
     * EN: Checks installation, enablement, and Calendar API service autoload.
     */
    private function calendarAvailable(): bool
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return $this->composer->isInstalled(self::PACKAGE)
            && in_array(self::PACKAGE, $enabled, true)
            && class_exists(CalendarApiService::class);
    }

    /**
     * HR: Vraća stabilni popis Calendar i event ruta.
     * EN: Returns the stable Calendar and event route list.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/calendars', 'listCalendars', 'api.v1.calendars.list'],
            ['POST', '/api/v1/calendars', 'createCalendar', 'api.v1.calendars.create'],
            ['POST', '/api/v1/calendars/import', 'importCalendar', 'api.v1.calendars.import'],
            ['GET', '/api/v1/calendars/{calendarUuid}', 'getCalendar', 'api.v1.calendars.get'],
            ['PATCH', '/api/v1/calendars/{calendarUuid}', 'updateCalendar', 'api.v1.calendars.update'],
            ['DELETE', '/api/v1/calendars/{calendarUuid}', 'deleteCalendar', 'api.v1.calendars.delete'],
            [
                'PUT',
                '/api/v1/calendars/{calendarUuid}/acl',
                'replaceAccessRules',
                'api.v1.calendars.acl.replace',
            ],
            [
                'GET',
                '/api/v1/calendars/{calendarUuid}/export.ics',
                'exportCalendar',
                'api.v1.calendars.export',
            ],
            [
                'GET',
                '/api/v1/calendars/{calendarUuid}/events',
                'listEvents',
                'api.v1.calendars.events',
            ],
            [
                'POST',
                '/api/v1/calendars/{calendarUuid}/events',
                'createEvent',
                'api.v1.calendars.events.create',
            ],
            [
                'GET',
                '/api/v1/calendars/{calendarUuid}/events/{eventId}',
                'getEvent',
                'api.v1.calendars.events.get',
            ],
            [
                'PATCH',
                '/api/v1/calendars/{calendarUuid}/events/{eventId}',
                'updateEvent',
                'api.v1.calendars.events.update',
            ],
            [
                'DELETE',
                '/api/v1/calendars/{calendarUuid}/events/{eventId}',
                'deleteEvent',
                'api.v1.calendars.events.delete',
            ],
        ];
    }
}
