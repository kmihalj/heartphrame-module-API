<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleEditorHtml\Api\EditorHtmlApiException;
use AaiEduHr\HeartPhrameModuleEditorHtml\Api\EditorHtmlApiService;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_scalar;
use function sprintf;
use function strtolower;
use function trim;

/**
 * HR: Pretvara verzionirane HTTP zahtjeve u ACL-svjesne operacije HTML Editora.
 *
 * EN: Translates versioned HTTP requests into ACL-aware HTML Editor operations.
 *
 * Početnici / Beginners:
 * HR: Ovdje nema odluka o pravima stranice. Kontroler provjerava samo scope
 * ključa, a neutralni Editor servis zatim provjerava stvarna prava korisnika.
 * EN: Page-permission decisions do not live here. The controller checks only
 * the key scope, while the neutral Editor service rechecks actual user rights.
 */
final readonly class EditorHtmlResourceController
{
    /**
     * HR: Prima zajedničke tvornice odgovora, neutralni Editor API i konfiguraciju jezika.
     * EN: Receives shared response factories, the neutral Editor API, and locale configuration.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private ResponseFactory $httpResponses,
        private EditorHtmlApiService $editor,
        private ConfigInterface $config,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća objavljene stranice vidljive vlasniku API ključa.
     * EN: Returns published pages visible to the API key owner.
     */
    public function listPages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->editor->listPages($this->language($request), $user),
                ),
        );
    }

    /**
     * HR: Kreira samostalni dokument ili neobjavljenu Workspace stranicu.
     * EN: Creates a standalone document or an unpublished Workspace page.
     */
    public function createPage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            fn(array $user): array => $this->editor->createPage($this->jsonBody($request), $user),
            201,
            'id',
        );
    }

    /**
     * HR: Vraća isključivo objavljenu verziju stranice.
     * EN: Returns only the published page version.
     */
    public function getPage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:read',
            fn(array $user): array => $this->editor->getPage(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $user,
                $this->queryFlag($request, 'rendered'),
            ),
        );
    }

    /**
     * HR: Sprema novi sadržaj ili zajednički Workspace nacrt uz optimistic lock.
     * EN: Saves new content or the shared Workspace draft with optimistic locking.
     */
    public function updatePage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editablePageState($documentId, $language, $user),
                );

                return $this->editor->updatePage(
                    $documentId,
                    $language,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Briše samostalni dokument ili Workspace stranicu prema stvarnim pravima.
     * EN: Deletes a standalone document or Workspace page according to actual rights.
     */
    public function deletePage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            function (array $user) use ($request): null {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editablePageState($documentId, $language, $user),
                );
                $this->editor->deletePage(
                    $documentId,
                    $language,
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Vraća zajednički Workspace nacrt samo ovlaštenom uredniku.
     * EN: Returns the shared Workspace draft only to an authorized editor.
     */
    public function getDraft(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            fn(array $user): array => $this->editor->getDraft(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $user,
            ),
        );
    }

    /**
     * HR: Odbacuje Workspace nacrt; novu neobjavljenu stranicu potpuno uklanja.
     * EN: Discards a Workspace draft; a new unpublished page is removed completely.
     */
    public function discardDraft(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            function (array $user) use ($request): ?array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editor->getDraft($documentId, $language, $user),
                );

                return $this->editor->discardDraft($documentId, $language, $user);
            },
            nullIsNoContent: true,
        );
    }

    /**
     * HR: Objavljuje postojeći Workspace nacrt uz zasebni publish scope i pravo.
     * EN: Publishes an existing Workspace draft with a separate scope and permission.
     */
    public function publishDraft(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:publish',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editor->getDraft($documentId, $language, $user),
                );

                return $this->editor->publishDraft($documentId, $language, $user);
            },
        );
    }

    /**
     * HR: Šalje Workspace nacrt na pregled i pokreće zajedničke obavijesti
     *     jednako kao radnja iz web sučelja.
     * EN: Submits a Workspace draft for review and triggers the same shared
     *     notifications as the web action.
     */
    public function submitDraftForReview(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editor->getDraft($documentId, $language, $user),
                );

                return $this->editor->submitDraftForReview($documentId, $language, $user);
            },
        );
    }

    /**
     * HR: Kopira sadržaj iz jednog jezika u novi ili postojeći jezični nacrt.
     * EN: Copies content from one locale into a new or existing locale draft.
     */
    public function copyTranslation(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            fn(array $user): array => $this->editor->copyTranslation(
                $this->routeString($request, 'documentId'),
                $this->jsonBody($request),
                $user,
            ),
            201,
            'id',
        );
    }

    /**
     * HR: Vraća samo objavljenu povijest tražene jezične verzije.
     * EN: Returns only the published history for the requested locale.
     */
    public function listVersions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->editor->listVersions(
                        $this->routeString($request, 'documentId'),
                        $this->language($request),
                        $user,
                    ),
                ),
        );
    }

    /**
     * HR: Vraća jednu točno određenu objavljenu verziju.
     * EN: Returns one exact published version.
     */
    public function getVersion(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:read',
            fn(array $user): array => $this->editor->getVersion(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->routeInt($request, 'versionNumber'),
                $user,
            ),
        );
    }

    /**
     * HR: Vraća staru verziju kao novu radnu verziju ili Workspace nacrt.
     * EN: Restores an old version as a new working version or Workspace draft.
     */
    public function restoreVersion(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'page:write',
            fn(array $user): array => $this->editor->restoreVersion(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->routeInt($request, 'versionNumber'),
                $user,
            ),
        );
    }

    /**
     * HR: Vraća aktivne privitke čitljive stranice.
     * EN: Returns active attachments for a readable page.
     */
    public function listAttachments(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->editor->listAttachments(
                        $this->routeString($request, 'documentId'),
                        $this->language($request),
                        $user,
                    ),
                ),
        );
    }

    /**
     * HR: Sprema jedan standardni multipart privitak.
     * EN: Stores one standard multipart attachment.
     */
    public function uploadAttachment(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            fn(array $user): array => $this->editor->uploadAttachment(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->uploadedFile($request),
                $user,
            ),
            201,
            'uuid',
        );
    }

    /**
     * HR: Sprema jedan dio velikog privitka; zadnji dio vraća završni resurs.
     * EN: Stores one large-attachment chunk; the final chunk returns the completed resource.
     */
    public function uploadAttachmentChunk(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            fn(array $user): ?array => $this->editor->uploadAttachmentChunk(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->uploadedFile($request),
                $this->formBody($request),
                $user,
            ),
            201,
            'uuid',
            false,
            202,
        );
    }

    /**
     * HR: Prekida veliki upload i briše već prenesene dijelove.
     * EN: Cancels a large upload and removes already transferred chunks.
     */
    public function cancelAttachmentUpload(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            function (array $user) use ($request): null {
                $this->editor->cancelAttachmentUpload(
                    $this->routeString($request, 'documentId'),
                    $this->language($request),
                    $this->routeString($request, 'uploadId'),
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Vraća metapodatke jednog privitka.
     * EN: Returns metadata for one attachment.
     */
    public function getAttachment(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:read',
            fn(array $user): array => $this->editor->getAttachment(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->routeString($request, 'assetUuid'),
                $user,
            ),
        );
    }

    /**
     * HR: Djelomično mijenja prikazne metapodatke privitka.
     * EN: Partially updates attachment display metadata.
     */
    public function updateAttachment(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $assetUuid = $this->routeString($request, 'assetUuid');
                $this->entityTags->assertMatches(
                    $request,
                    $this->editor->getAttachment($documentId, $language, $assetUuid, $user),
                );

                return $this->editor->updateAttachment(
                    $documentId,
                    $language,
                    $assetUuid,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Briše privitak i uklanja njegove reference iz sadržaja svih jezika.
     * EN: Deletes an attachment and removes its references from all locale content.
     */
    public function deleteAttachment(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $assetUuid = $this->routeString($request, 'assetUuid');
                $this->entityTags->assertMatches(
                    $request,
                    $this->editor->getAttachment($documentId, $language, $assetUuid, $user),
                );

                return $this->editor->deleteAttachment(
                    $documentId,
                    $language,
                    $assetUuid,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Mijenja vidljivost popisa privitaka na pregledu.
     * EN: Changes attachment-list visibility on the page view.
     */
    public function updateAttachmentVisibility(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'attachment:write',
            function (array $user) use ($request): array {
                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $this->entityTags->assertMatches(
                    $request,
                    $this->editablePageState($documentId, $language, $user),
                );
                $payload = $this->jsonBody($request);
                $visibility = is_scalar($payload['visibility'] ?? null)
                    ? trim((string)$payload['visibility'])
                    : '';

                return $this->editor->updateAttachmentVisibility(
                    $documentId,
                    $language,
                    $visibility,
                    $user,
                );
            },
        );
    }

    /**
     * HR: Streama privitak iz datotečnog sustava ili baze bez JSON/base64 sloja.
     * EN: Streams an attachment from filesystem or database without JSON/base64 wrapping.
     */
    public function attachmentContent(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('attachment:read')) {
            return $this->scopeProblem($request, 'attachment:read');
        }

        try {
            $descriptor = $this->editor->attachmentContent(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->routeString($request, 'assetUuid'),
                $identity->user,
            );
            $asset = $descriptor['asset'];
            $mime = is_scalar($asset['mime_type'] ?? null)
                ? trim((string)$asset['mime_type'])
                : 'application/octet-stream';
            $name = is_scalar($asset['original_name'] ?? null)
                ? trim((string)$asset['original_name'])
                : 'attachment';
            $downloadName = $this->downloadRequested($request) ? $name : null;
            $requestId = $this->responses->requestId($request);
            $headers = ['X-Request-Id' => $requestId];

            if (is_scalar($descriptor['path'] ?? null) && trim((string)$descriptor['path']) !== '') {
                return $this->httpResponses->file(
                    trim((string)$descriptor['path']),
                    $mime,
                    $downloadName,
                    headers: $headers,
                );
            }

            $content = $descriptor['content'] ?? null;
            if (!is_string($content)) {
                throw new RuntimeException(__('Sadržaj privitka nije pronađen.'));
            }

            if ($downloadName !== null) {
                return $this->httpResponses->download(
                    $content,
                    $downloadName,
                    $mime,
                    headers: $headers,
                );
            }

            return $this->httpResponses->html($content, headers: $headers, contentType: $mime);
        } catch (EditorHtmlApiException $exception) {
            return $this->editorProblem($request, $exception);
        } catch (Throwable) {
            return $this->internalProblem($request);
        }
    }

    /**
     * HR: Provjerava scope, izvršava operaciju i ujednačeno mapira greške.
     * EN: Checks a scope, executes an operation, and consistently maps failures.
     *
     * @param callable(array<string,mixed>):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
        int $status = 200,
        string $locationField = 'id',
        bool $nullIsNoContent = false,
        ?int $nullStatus = null,
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->scopeProblem($request, $scope);
        }

        try {
            $data = $operation($identity->user);
            if ($status === 204 || ($nullIsNoContent && $data === null)) {
                return $this->responses->noContent($request);
            }

            $responseStatus = $data === null && $nullStatus !== null ? $nullStatus : $status;
            $response = $this->responses->success(
                $request,
                $data,
                $responseStatus,
                links: ['self' => $this->responses->requestTarget($request)],
            );
            if (
                $responseStatus === 201
                && is_array($data)
                && is_scalar($data[$locationField] ?? null)
            ) {
                return $response->withHeader(
                    'Location',
                    $this->responses->childTarget($request, (string)$data[$locationField]),
                );
            }

            return $response;
        } catch (JsonException $exception) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $exception->getMessage(),
            );
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (EditorHtmlApiException $exception) {
            return $this->editorProblem($request, $exception);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return $this->responses->problem(
                $request,
                422,
                'editor_validation_failed',
                __('Editor operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->internalProblem($request);
        }
    }

    /**
     * HR: Vraća autentificirani identitet koji je postavio API middleware.
     * EN: Returns the authenticated identity attached by the API middleware.
     */
    private function identity(ServerRequestInterface $request): AuthApiIdentity
    {
        $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AuthApiIdentity) {
            throw new RuntimeException('Authenticated API identity is missing.');
        }

        return $identity;
    }

    /**
     * HR: Dekodira JSON objekt iz tijela zahtjeva.
     * EN: Decodes a JSON object from the request body.
     *
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $raw = trim((string)$request->getBody());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException(__('JSON tijelo mora biti objekt.'));
        }

        return $this->stringKeyArray($decoded);
    }

    /**
     * HR: Čita multipart polja iz PSR-7 parsed bodyja.
     * EN: Reads multipart fields from the PSR-7 parsed body.
     *
     * @return array<string,mixed>
     */
    private function formBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $this->stringKeyArray($parsed) : [];
    }

    /**
     * HR: Vraća datoteku iz standardnog multipart polja `attachment`.
     * EN: Returns the file from the standard `attachment` multipart field.
     */
    private function uploadedFile(ServerRequestInterface $request): UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['attachment'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new EditorHtmlApiException(
                'editor_validation_failed',
                __('Multipart polje "attachment" je obavezno.'),
                422,
            );
        }

        return $file;
    }

    /**
     * HR: Čita pozitivni broj verzije iz route atributa.
     * EN: Reads a positive version number from a route attribute.
     */
    private function routeInt(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) && is_numeric($value) ? (int)$value : 0;
    }

    /**
     * HR: Čita tekstualnu vrijednost route atributa.
     * EN: Reads a string value from a route attribute.
     */
    private function routeString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Vraća jezik iz queryja ili zadane aplikacijske lokalizacije.
     * EN: Returns the locale from query parameters or application defaults.
     */
    private function language(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $candidate = is_scalar($query['lang'] ?? null)
            ? trim((string)$query['lang'])
            : trim($this->config->getAsString('app.locale') ?? '');

        return preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})*$/i', $candidate) === 1
            ? strtolower($candidate)
            : 'en';
    }

    /**
     * HR: Tumači query `download` kao eksplicitni zahtjev za preuzimanje.
     * EN: Interprets the `download` query parameter as an explicit download request.
     */
    private function downloadRequested(ServerRequestInterface $request): bool
    {
        return $this->queryFlag($request, 'download');
    }

    /**
     * HR: Tumači imenovani query parametar kao eksplicitnu logičku vrijednost.
     * EN: Interprets a named query parameter as an explicit boolean value.
     */
    private function queryFlag(ServerRequestInterface $request, string $name): bool
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value)
            && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    /**
     * HR: Zadržava samo string ključeve ulaznog polja.
     * EN: Keeps only string keys from an input array.
     *
     * @param array<mixed,mixed> $values
     * @return array<string,mixed>
     */
    private function stringKeyArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Vraća nacrt kada postoji, a inače objavljenu stranicu za samostalni
     * Editor ili Workspace stranicu bez aktivnog nacrta.
     *
     * EN: Returns the draft when present, otherwise the published page for the
     * standalone Editor or a Workspace page without an active draft.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function editablePageState(
        string $documentId,
        string $language,
        array $user,
    ): array {
        try {
            return $this->editor->getDraft($documentId, $language, $user);
        } catch (EditorHtmlApiException $editorHtmlApiException) {
            if (
                $editorHtmlApiException->status !== 404
                && ($editorHtmlApiException->status !== 409
                    || $editorHtmlApiException->errorCode !== 'editor_conflict')
            ) {
                throw $editorHtmlApiException;
            }
        }

        return $this->editor->getPage($documentId, $language, $user);
    }

    /**
     * HR: Gradi problem odgovor za nedostajući scope.
     * EN: Builds a problem response for a missing scope.
     */
    private function scopeProblem(
        ServerRequestInterface $request,
        string $scope,
    ): ResponseInterface {
        return $this->responses->problem(
            $request,
            403,
            'insufficient_scope',
            __('Pristup nije dozvoljen'),
            sprintf(__('API ključ nema potreban scope "%s".'), $scope),
        );
    }

    /**
     * HR: Pretvara očekivanu domensku Editor pogrešku u RFC 9457 odgovor.
     * EN: Converts an expected Editor domain failure into an RFC 9457 response.
     */
    private function editorProblem(
        ServerRequestInterface $request,
        EditorHtmlApiException $exception,
    ): ResponseInterface {
        return $this->responses->problem(
            $request,
            $exception->status,
            $exception->errorCode,
            __('Editor operaciju nije moguće izvršiti'),
            $exception->getMessage(),
        );
    }

    /**
     * HR: Pretvara nedostajući ili zastarjeli `If-Match` u stabilni problem odgovor.
     * EN: Converts a missing or stale `If-Match` into a stable problem response.
     */
    private function preconditionProblem(
        ServerRequestInterface $request,
        ApiPreconditionException $exception,
    ): ResponseInterface {
        return $this->responses->problem(
            $request,
            $exception->status,
            $exception->errorCode,
            __('Uvjet izmjene nije ispunjen'),
            $exception->getMessage(),
        );
    }

    /**
     * HR: Skriva interne detalje neočekivane greške uz request ID za dijagnostiku.
     * EN: Conceals internal details of an unexpected failure while retaining a request ID.
     */
    private function internalProblem(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            500,
            'internal_error',
            __('Interna greška'),
            __('Zahtjev nije moguće obraditi. Obrati se administratoru uz request ID.'),
        );
    }
}
