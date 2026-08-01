<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\WorkspaceResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiService;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

/**
 * HR: Uvjetno registrira Workspace API rute samo kada je Workspace stvarno dostupan.
 *
 * EN: Conditionally registers Workspace API routes only when Workspace is actually available.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\WorkspaceApiRouteRegistrarTest
 */
final readonly class WorkspaceApiRouteRegistrar
{
    /**
     * HR: Prima Composer stanje, konfiguraciju uključenih modula i zajednički router.
     * EN: Receives Composer state, enabled-module configuration, and the shared router.
     */
    public function __construct(
        private ComposerBridge $composer,
        private ConfigInterface $config,
        private Routes $routes,
    ) {
    }

    /**
     * HR: Dodaje Workspace endpointove nakon učitavanja svih modula.
     * EN: Adds Workspace endpoints after all modules have loaded.
     */
    public function register(): void
    {
        if (!$this->workspaceAvailable()) {
            return;
        }

        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $this->routes->addRoute(
                $method,
                $path,
                WorkspaceResourceController::class . '@' . $action,
                $name,
                [ApiAuthenticationMiddleware::class],
            );
        }
    }

    /**
     * HR: Provjerava instalaciju, uključivanje i autoload domenskog Workspace servisa.
     * EN: Checks installation, enablement, and autoload availability of the Workspace domain service.
     */
    private function workspaceAvailable(): bool
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return $this->composer->isInstalled(ModuleWorkspace::PACKAGE_NAME)
            && in_array(ModuleWorkspace::PACKAGE_NAME, $enabled, true)
            && class_exists(WorkspaceApiService::class);
    }

    /**
     * HR: Vraća stabilan popis ruta; posebne `deleted` rute dolaze prije dinamičkog sluga.
     * EN: Returns the stable route list; specific `deleted` routes precede the dynamic slug.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/workspaces', 'listWorkspaces', 'api.v1.workspaces.list'],
            ['POST', '/api/v1/workspaces', 'createWorkspace', 'api.v1.workspaces.create'],
            [
                'GET',
                '/api/v1/workspaces/deleted',
                'listDeletedWorkspaces',
                'api.v1.workspaces.deleted.list',
            ],
            [
                'POST',
                '/api/v1/workspaces/deleted/{workspaceId}/restore',
                'restoreWorkspace',
                'api.v1.workspaces.deleted.restore',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}', 'getWorkspace', 'api.v1.workspaces.get'],
            [
                'PATCH',
                '/api/v1/workspaces/{workspaceSlug}',
                'updateWorkspace',
                'api.v1.workspaces.update',
            ],
            [
                'DELETE',
                '/api/v1/workspaces/{workspaceSlug}',
                'deleteWorkspace',
                'api.v1.workspaces.delete',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}/tree', 'getTree', 'api.v1.workspaces.tree'],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/tree/order',
                'reorderTree',
                'api.v1.workspaces.tree.order',
            ],
            ['GET', '/api/v1/workspaces/{workspaceSlug}/acl', 'getWorkspaceAcl', 'api.v1.workspaces.acl'],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/acl',
                'replaceWorkspaceAcl',
                'api.v1.workspaces.acl.replace',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/acl/subjects',
                'searchAclSubjects',
                'api.v1.workspaces.acl.subjects',
            ],
            [
                'POST',
                '/api/v1/workspaces/{workspaceSlug}/nodes',
                'createLinkNode',
                'api.v1.workspaces.nodes.create',
            ],
            [
                'PATCH',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}',
                'updateNode',
                'api.v1.workspaces.nodes.update',
            ],
            [
                'DELETE',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}',
                'deleteLinkNode',
                'api.v1.workspaces.nodes.delete',
            ],
            [
                'GET',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}/acl',
                'getNodeAcl',
                'api.v1.workspaces.nodes.acl',
            ],
            [
                'PUT',
                '/api/v1/workspaces/{workspaceSlug}/nodes/{nodeId}/acl',
                'replaceNodeAcl',
                'api.v1.workspaces.nodes.acl.replace',
            ],
        ];
    }
}
