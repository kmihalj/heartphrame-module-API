<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\NotificationResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

/**
 * HR: Uvjetno registrira Notification API samo kada je modul instaliran i uključen.
 * EN: Conditionally registers the Notification API only when the module is installed and enabled.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\NotificationApiRouteRegistrarTest
 */
final readonly class NotificationApiRouteRegistrar
{
    private const PACKAGE = 'aaieduhr/heartphrame-module-notification';

    private const SERVICE = \AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService::class;

    /**
     * HR: Prima Composer stanje, konfiguraciju modula i zajednički router.
     * EN: Receives Composer state, module configuration, and the shared router.
     */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
        private Routes $routes,
    ) {
    }

    /**
     * HR: Dodaje inbox rute nakon učitavanja svih opcionalnih modula.
     * EN: Adds inbox routes after all optional modules have loaded.
     */
    public function register(): void
    {
        if (!$this->available()) {
            return;
        }

        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $this->routes->addRoute(
                $method,
                $path,
                NotificationResourceController::class . '@' . $action,
                $name,
                [ApiAuthenticationMiddleware::class],
            );
        }
    }

    /**
     * HR: Provjerava instalaciju, uključenost i autoload poslovnog servisa.
     * EN: Checks installation, enablement, and business-service autoload.
     */
    private function available(): bool
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return $this->composer->isInstalled(self::PACKAGE)
            && in_array(self::PACKAGE, $enabled, true)
            && class_exists(self::SERVICE);
    }

    /**
     * HR: Vraća stabilne Notification API rute.
     * EN: Returns stable Notification API routes.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/notifications', 'listNotifications', 'api.v1.notifications.list'],
            ['POST', '/api/v1/notifications/read-all', 'markAllRead', 'api.v1.notifications.read-all'],
            ['GET', '/api/v1/notifications/{uuid}', 'getNotification', 'api.v1.notifications.get'],
            ['PATCH', '/api/v1/notifications/{uuid}', 'updateNotification', 'api.v1.notifications.update'],
            ['DELETE', '/api/v1/notifications/{uuid}', 'deleteNotification', 'api.v1.notifications.delete'],
        ];
    }
}
