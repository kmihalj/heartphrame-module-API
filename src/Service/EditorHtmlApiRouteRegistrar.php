<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Controller\EditorHtmlResourceController;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleEditorHtml\Api\EditorHtmlApiService;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Routing\Routes;

use function class_exists;
use function in_array;

/**
 * HR: Uvjetno registrira HTML Editor API samo kada je Editor instaliran i uključen.
 *
 * EN: Conditionally registers the HTML Editor API only when the Editor is installed and enabled.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\EditorHtmlApiRouteRegistrarTest
 */
final readonly class EditorHtmlApiRouteRegistrar
{
    private const PACKAGE = 'aaieduhr/heartphrame-module-editor-html';

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
     * HR: Dodaje Editor endpointove nakon što su svi opcionalni moduli učitani.
     * EN: Adds Editor endpoints after all optional modules have loaded.
     */
    public function register(): void
    {
        if (!$this->editorAvailable()) {
            return;
        }

        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $this->routes->addRoute(
                $method,
                $path,
                EditorHtmlResourceController::class . '@' . $action,
                $name,
                [ApiAuthenticationMiddleware::class],
            );
        }
    }

    /**
     * HR: Provjerava paket, konfiguraciju i autoload neutralnog Editor API servisa.
     * EN: Checks the package, configuration, and neutral Editor API service autoload.
     */
    private function editorAvailable(): bool
    {
        $enabled = $this->config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];

        return $this->composer->isInstalled(self::PACKAGE)
            && in_array(self::PACKAGE, $enabled, true)
            && class_exists(EditorHtmlApiService::class);
    }

    /**
     * HR: Vraća stabilne rute; posebne draft, version i chunk rute prethode
     * dinamičkim identifikatorima privitaka.
     *
     * EN: Returns stable routes; specific draft, version, and chunk routes
     * precede dynamic attachment identifiers.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/pages', 'listPages', 'api.v1.pages.list'],
            ['POST', '/api/v1/pages', 'createPage', 'api.v1.pages.create'],
            ['GET', '/api/v1/pages/{documentId}', 'getPage', 'api.v1.pages.get'],
            ['PATCH', '/api/v1/pages/{documentId}', 'updatePage', 'api.v1.pages.update'],
            ['DELETE', '/api/v1/pages/{documentId}', 'deletePage', 'api.v1.pages.delete'],
            ['GET', '/api/v1/pages/{documentId}/draft', 'getDraft', 'api.v1.pages.draft'],
            [
                'DELETE',
                '/api/v1/pages/{documentId}/draft',
                'discardDraft',
                'api.v1.pages.draft.discard',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/review',
                'submitDraftForReview',
                'api.v1.pages.review',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/publish',
                'publishDraft',
                'api.v1.pages.publish',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/translations',
                'copyTranslation',
                'api.v1.pages.translations.create',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/versions',
                'listVersions',
                'api.v1.pages.versions',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/versions/{versionNumber}',
                'getVersion',
                'api.v1.pages.versions.get',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/versions/{versionNumber}/restore',
                'restoreVersion',
                'api.v1.pages.versions.restore',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/attachments',
                'listAttachments',
                'api.v1.pages.attachments',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/attachments',
                'uploadAttachment',
                'api.v1.pages.attachments.create',
            ],
            [
                'POST',
                '/api/v1/pages/{documentId}/attachments/chunks',
                'uploadAttachmentChunk',
                'api.v1.pages.attachments.chunks.create',
            ],
            [
                'DELETE',
                '/api/v1/pages/{documentId}/attachments/chunks/{uploadId}',
                'cancelAttachmentUpload',
                'api.v1.pages.attachments.chunks.cancel',
            ],
            [
                'PUT',
                '/api/v1/pages/{documentId}/attachment-visibility',
                'updateAttachmentVisibility',
                'api.v1.pages.attachments.visibility',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/attachments/{assetUuid}/content',
                'attachmentContent',
                'api.v1.pages.attachments.content',
            ],
            [
                'GET',
                '/api/v1/pages/{documentId}/attachments/{assetUuid}',
                'getAttachment',
                'api.v1.pages.attachments.get',
            ],
            [
                'PATCH',
                '/api/v1/pages/{documentId}/attachments/{assetUuid}',
                'updateAttachment',
                'api.v1.pages.attachments.update',
            ],
            [
                'DELETE',
                '/api/v1/pages/{documentId}/attachments/{assetUuid}',
                'deleteAttachment',
                'api.v1.pages.attachments.delete',
            ],
        ];
    }
}
