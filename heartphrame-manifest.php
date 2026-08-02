<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleApi\Account\ApiKeyAccountSectionProvider;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiKeyController;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiKeyRequestController;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiPreflightController;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiRootController;
use AaiEduHr\HeartPhrameModuleApi\Controller\AuditResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\AuthResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\MeController;
use AaiEduHr\HeartPhrameModuleApi\Controller\OpenApiController;
use AaiEduHr\HeartPhrameModuleApi\Controller\WebhookResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCorsRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiMenuIntegration;
use AaiEduHr\HeartPhrameModuleApi\Service\CalendarApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\EditorHtmlApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\NotificationApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\TaskApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\WorkspaceApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAdminOrBootstrapMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Command\CommandDefinition;
use HeartPhrame\Config\ConfigInterface;
use Psr\Container\ContainerInterface;

return new class extends \HeartPhrame\Module\AbstractModuleManifest {
    private const AUTH_PACKAGE = 'aaieduhr/heartphrame-module-auth';

    private const ORM_PACKAGE = 'aaieduhr/heartphrame-module-orm';

    /**
     * HR: Zaustavlja učitavanje ako obavezni Auth modul nije instaliran,
     * uključen i registriran prije API modula.
     *
     * EN: Stops loading unless the required Auth module is installed, enabled,
     * and registered before the API module.
     */
    public function canLoad(ContainerInterface $container): bool
    {
        $composer = $container->get(ComposerBridge::class);
        if (!($composer instanceof ComposerBridge)) {
            throw new RuntimeException('API module requires ComposerBridge.');
        }

        if (!$composer->isInstalled(self::AUTH_PACKAGE) || !class_exists(ModuleAuth::class)) {
            throw new RuntimeException('API module requires the installed Auth module.');
        }

        if (!$composer->isInstalled(self::ORM_PACKAGE) || !class_exists(Database::class)) {
            throw new RuntimeException('API module requires the installed ORM module.');
        }

        $config = $container->get(ConfigInterface::class);
        if (!($config instanceof ConfigInterface)) {
            throw new RuntimeException('API module requires ConfigInterface.');
        }

        $enabled = $config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];
        $authPosition = array_search(self::AUTH_PACKAGE, $enabled, true);
        $ormPosition = array_search(self::ORM_PACKAGE, $enabled, true);
        $apiPosition = array_search(ModuleApi::PACKAGE_NAME, $enabled, true);
        if (
            $authPosition === false
            || $ormPosition === false
            || ($apiPosition !== false
                && ($authPosition > $apiPosition || $ormPosition > $apiPosition))
        ) {
            throw new RuntimeException('API module requires Auth and ORM to be enabled before API.');
        }

        return true;
    }

    /**
     * HR: Odgađa registraciju dok Auth ne izloži servis za ključeve i domenski API.
     *
     * EN: Defers registration until Auth exposes its key and domain API services.
     */
    public function requiresDeferredLoading(): bool
    {
        return true;
    }

    /**
     * HR: Učitava servisne definicije API modula.
     *
     * EN: Loads API-module service definitions.
     */
    public function getServices(): array
    {
        $services = require __DIR__ . '/config/services.php';
        if (!is_array($services)) {
            throw new RuntimeException('API config/services.php must return an array.');
        }

        return $services;
    }

    /**
     * HR: Registrira verzionirane JSON rute i uvjetni ekran API ključeva.
     *
     * EN: Registers versioned JSON routes and the conditional API-key screen.
     */
    public function getBaseRoutes(): array
    {
        $api = [ApiAuthenticationMiddleware::class];
        $admin = [RequireAdminOrBootstrapMiddleware::class];
        $authenticated = [RequireAuthenticatedUserMiddleware::class];

        $routes = [
            ['GET', '/api/v1', ApiRootController::class . '@index', 'api.v1', $api],
            ['GET', '/api/v1/me', MeController::class . '@show', 'api.v1.me', $api],
            [
                'GET',
                '/api/v1/openapi.json',
                OpenApiController::class . '@show',
                'api.v1.openapi',
                $api,
            ],
            ['GET', '/api/v1/audit', AuditResourceController::class . '@listEvents', 'api.v1.audit.list', $api],
            ['GET', '/api/v1/users', AuthResourceController::class . '@listUsers', 'api.v1.users.list', $api],
            ['POST', '/api/v1/users', AuthResourceController::class . '@createUser', 'api.v1.users.create', $api],
            ['GET', '/api/v1/users/{userId}', AuthResourceController::class . '@getUser', 'api.v1.users.get', $api],
            [
                'PATCH',
                '/api/v1/users/{userId}',
                AuthResourceController::class . '@updateUser',
                'api.v1.users.update',
                $api,
            ],
            [
                'DELETE',
                '/api/v1/users/{userId}',
                AuthResourceController::class . '@deleteUser',
                'api.v1.users.delete',
                $api,
            ],
            [
                'PUT',
                '/api/v1/users/{userId}/groups',
                AuthResourceController::class . '@replaceUserGroups',
                'api.v1.users.groups.replace',
                $api,
            ],
            ['GET', '/api/v1/groups', AuthResourceController::class . '@listGroups', 'api.v1.groups.list', $api],
            [
                'POST',
                '/api/v1/groups',
                AuthResourceController::class . '@createGroup',
                'api.v1.groups.create',
                $api,
            ],
            [
                'GET',
                '/api/v1/groups/{groupId}',
                AuthResourceController::class . '@getGroup',
                'api.v1.groups.get',
                $api,
            ],
            [
                'PATCH',
                '/api/v1/groups/{groupId}',
                AuthResourceController::class . '@updateGroup',
                'api.v1.groups.update',
                $api,
            ],
            [
                'DELETE',
                '/api/v1/groups/{groupId}',
                AuthResourceController::class . '@deleteGroup',
                'api.v1.groups.delete',
                $api,
            ],
            [
                'GET',
                '/api/v1/webhooks',
                WebhookResourceController::class . '@listSubscriptions',
                'api.v1.webhooks.list',
                $api,
            ],
            [
                'POST',
                '/api/v1/webhooks',
                WebhookResourceController::class . '@createSubscription',
                'api.v1.webhooks.create',
                $api,
            ],
            [
                'GET',
                '/api/v1/webhooks/{uuid}',
                WebhookResourceController::class . '@getSubscription',
                'api.v1.webhooks.get',
                $api,
            ],
            [
                'PATCH',
                '/api/v1/webhooks/{uuid}',
                WebhookResourceController::class . '@updateSubscription',
                'api.v1.webhooks.update',
                $api,
            ],
            [
                'DELETE',
                '/api/v1/webhooks/{uuid}',
                WebhookResourceController::class . '@deleteSubscription',
                'api.v1.webhooks.delete',
                $api,
            ],
            [
                'POST',
                '/api/v1/webhooks/{uuid}/rotate-secret',
                WebhookResourceController::class . '@rotateSecret',
                'api.v1.webhooks.rotate-secret',
                $api,
            ],
            [
                'GET',
                '/api/v1/webhooks/{uuid}/deliveries',
                WebhookResourceController::class . '@listDeliveries',
                'api.v1.webhooks.deliveries.list',
                $api,
            ],
            [
                'GET',
                '/api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}',
                WebhookResourceController::class . '@getDelivery',
                'api.v1.webhooks.deliveries.get',
                $api,
            ],
            [
                'POST',
                '/api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}/retry',
                WebhookResourceController::class . '@retryDelivery',
                'api.v1.webhooks.deliveries.retry',
                $api,
            ],
            ['GET', '/settings/auth/api-keys', ApiKeyController::class . '@index', 'auth.setup.api-keys', $admin],
            [
                'POST',
                '/settings/auth/api-keys/create',
                ApiKeyController::class . '@create',
                'auth.setup.api-keys.create',
                $admin,
            ],
            [
                'POST',
                '/settings/auth/api-keys/rotate',
                ApiKeyController::class . '@rotate',
                'auth.setup.api-keys.rotate',
                $admin,
            ],
            [
                'POST',
                '/settings/auth/api-keys/revoke',
                ApiKeyController::class . '@revoke',
                'auth.setup.api-keys.revoke',
                $admin,
            ],
            [
                'POST',
                '/settings/auth/api-keys/delete',
                ApiKeyController::class . '@delete',
                'auth.setup.api-keys.delete',
                $admin,
            ],
            [
                'GET',
                '/settings/auth/api-keys/users',
                ApiKeyController::class . '@searchUsers',
                'auth.setup.api-keys.users',
                $admin,
            ],
            [
                'POST',
                '/settings/auth/api-keys/requests/approve',
                ApiKeyRequestController::class . '@approve',
                'auth.setup.api-keys.requests.approve',
                $admin,
            ],
            [
                'POST',
                '/settings/auth/api-keys/requests/reject',
                ApiKeyRequestController::class . '@reject',
                'auth.setup.api-keys.requests.reject',
                $admin,
            ],
            [
                'POST',
                '/account/api-keys/requests',
                ApiKeyRequestController::class . '@create',
                'api.key-request.create',
                $authenticated,
            ],
            [
                'GET',
                '/account/api-keys/requests/{uuid}/secret',
                ApiKeyRequestController::class . '@reveal',
                'api.key-request.reveal',
                $authenticated,
            ],
        ];

        // HR: Framework dodaje bazne rute tek nakon bootstrap callbackova. Zato
        //     bazne API rute ovdje izravno dobivaju CORS i OPTIONS, dok registrar
        //     tijekom bootstrapa i dalje obrađuje ranije dodane opcionalne rute.
        // EN: The framework adds base routes only after bootstrap callbacks. Base
        //     API routes therefore receive CORS and OPTIONS here, while the
        //     bootstrap registrar still handles earlier optional routes.
        $preflightPaths = [];
        foreach ($routes as &$route) {
            $path = $route[1];
            if (!str_starts_with($path, '/api/v1')) {
                continue;
            }

            array_unshift($route[4], ApiCorsMiddleware::class);
            $preflightPaths[$path] = true;
        }

        unset($route);

        foreach (array_keys($preflightPaths) as $path) {
            $routes[] = [
                'OPTIONS',
                $path,
                ApiPreflightController::class . '@handle',
                null,
                [ApiCorsMiddleware::class],
            ];
        }

        return $routes;
    }

    /**
     * HR: Nakon bootstrapa opcionalno dodaje API ključeve u zajednički settings meni.
     *
     * EN: Optionally adds API keys to the shared settings menu after bootstrap.
     *
     * @return mixed[]
     */
    public function getBootstrapCallables(): array
    {
        return [
            static function (ContainerInterface $container): void {
                $registrar = $container->get(CalendarApiRouteRegistrar::class);
                if ($registrar instanceof CalendarApiRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $registrar = $container->get(TaskApiRouteRegistrar::class);
                if ($registrar instanceof TaskApiRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $registrar = $container->get(NotificationApiRouteRegistrar::class);
                if ($registrar instanceof NotificationApiRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $registrar = $container->get(EditorHtmlApiRouteRegistrar::class);
                if ($registrar instanceof EditorHtmlApiRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $registrar = $container->get(WorkspaceApiRouteRegistrar::class);
                if ($registrar instanceof WorkspaceApiRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $registrar = $container->get(ApiCorsRouteRegistrar::class);
                if ($registrar instanceof ApiCorsRouteRegistrar) {
                    $registrar->register();
                }
            },
            static function (ContainerInterface $container): void {
                $integration = $container->get(ApiMenuIntegration::class);
                if ($integration instanceof ApiMenuIntegration) {
                    $integration->registerSettingsMenuItem();
                }
            },
            static function (ContainerInterface $container): void {
                $registry = $container->get(AuthAccountSectionRegistry::class);
                $provider = $container->get(ApiKeyAccountSectionProvider::class);
                if (
                    $registry instanceof AuthAccountSectionRegistry
                    && $provider instanceof ApiKeyAccountSectionProvider
                ) {
                    $registry->register($provider);
                }
            },
        ];
    }

    /**
     * HR: Registrira pomoćne naredbe za kopiranje jedine početne API migracije.
     * EN: Registers helper commands for copying the single initial API migration.
     */
    public function getCommands(): array
    {
        return [
            new CommandDefinition(
                'api',
                'API module helper command.',
                [\AaiEduHr\HeartPhrameModuleApi\Command\HpApiCommand::class, 'run'],
            ),
            new CommandDefinition(
                'api:install-migration',
                'Copy initial API migration into the host application.',
                [\AaiEduHr\HeartPhrameModuleApi\Command\HpApiCommand::class, 'installMigration'],
            ),
        ];
    }

    /**
     * HR: Vraća direktorij prikaza API modula.
     *
     * EN: Returns the API module view directory.
     */
    public function getViewsPath(): string
    {
        return __DIR__ . '/views';
    }
};
