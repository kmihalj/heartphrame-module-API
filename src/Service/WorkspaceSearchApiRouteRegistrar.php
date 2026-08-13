<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\WorkspaceSearchResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleWorkspaceSearch\Service\WorkspaceSearchService;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

/**
 * HR: Uvjetno registrira API pretrage samo uz uključeni Workspace Search modul.
 * EN: Conditionally registers search API routes only with Workspace Search enabled.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\WorkspaceSearchApiRouteRegistrarTest
 */
final readonly class WorkspaceSearchApiRouteRegistrar
{
    private const PACKAGE = 'aaieduhr/heartphrame-module-workspace-search';

    /**
     * HR: Prima instalacijsko stanje, konfiguraciju i zajednički router.
     * EN: Receives installation state, configuration, and the shared router.
     */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
        private Routes $routes,
    ) {
    }

    /**
     * HR: Dodaje stabilni endpoint pretrage nakon učitavanja opcionalnih modula.
     * EN: Adds the stable search endpoint after optional modules are loaded.
     */
    public function register(): void
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];
        if (
            !$this->composer->isInstalled(self::PACKAGE)
            || !in_array(self::PACKAGE, $enabled, true)
            || !class_exists(WorkspaceSearchService::class)
        ) {
            return;
        }

        $this->routes->addRoute(
            'GET',
            '/api/v1/workspace-search',
            WorkspaceSearchResourceController::class . '@search',
            'api.v1.workspace-search',
            [ApiAuthenticationMiddleware::class],
        );
    }
}
