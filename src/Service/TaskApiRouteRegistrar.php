<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\TaskResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleTask\Api\TaskApiService;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

/**
 * HR: Uvjetno registrira Task API samo kada je modul instaliran i uključen.
 * EN: Conditionally registers the Task API only when its module is installed and enabled.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\TaskApiRouteRegistrarTest
 */
final readonly class TaskApiRouteRegistrar
{
    private const PACKAGE = 'aaieduhr/heartphrame-module-task';

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
     * HR: Dodaje Task rute nakon učitavanja opcionalnih modula.
     * EN: Adds Task routes after optional modules have loaded.
     */
    public function register(): void
    {
        if (!$this->taskAvailable()) {
            return;
        }

        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $this->routes->addRoute(
                $method,
                $path,
                TaskResourceController::class . '@' . $action,
                $name,
                [ApiAuthenticationMiddleware::class],
            );
        }
    }

    /**
     * HR: Provjerava instalaciju, uključivanje i autoload Task API servisa.
     * EN: Checks installation, enablement, and Task API service autoload.
     */
    private function taskAvailable(): bool
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return $this->composer->isInstalled(self::PACKAGE)
            && in_array(self::PACKAGE, $enabled, true)
            && class_exists(TaskApiService::class);
    }

    /**
     * HR: Vraća stabilni popis Task ruta pod stranicom koja sadrži definiciju.
     * EN: Returns the stable Task routes beneath the page containing the definition.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/pages/{documentId}/tasks', 'listTasks', 'api.v1.pages.tasks'],
            [
                'GET',
                '/api/v1/pages/{documentId}/tasks/{taskUuid}',
                'getTask',
                'api.v1.pages.tasks.get',
            ],
            [
                'PUT',
                '/api/v1/pages/{documentId}/tasks/{taskUuid}/state',
                'setState',
                'api.v1.pages.tasks.state',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/tasks/{taskUuid}/history',
                'history',
                'api.v1.pages.tasks.history',
            ],
        ];
    }
}
