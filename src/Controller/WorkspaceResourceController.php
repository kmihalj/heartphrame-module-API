<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiException;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiService;
use HeartPhrame\Config\ConfigInterface;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Pretvara verzionirane HTTP zahtjeve u ACL-svjesne Workspace operacije.
 *
 * EN: Translates versioned HTTP requests into ACL-aware Workspace operations.
 */
final readonly class WorkspaceResourceController
{
    /**
     * HR: Inicijalizira HTTP adapter zajedničkom tvornicom odgovora i Workspace servisom.
     *
     * EN: Initializes the HTTP adapter with the shared response factory and Workspace service.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private WorkspaceApiService $workspaces,
        private ConfigInterface $config,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća područja vidljiva vlasniku API ključa.
     * EN: Returns workspaces visible to the API key owner.
     */
    public function listWorkspaces(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->workspaces->listWorkspaces($user),
                ),
        );
    }

    /**
     * HR: Vraća jedno područje ako ga vlasnik ključa smije vidjeti.
     * EN: Returns one Workspace when the key owner may view it.
     */
    public function getWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): array => $this->workspaces->getWorkspace(
                $this->routeString($request, 'workspaceSlug'),
                $user,
            ),
        );
    }

    /**
     * HR: Vraća filtrirano stablo područja i efektivna prava čvorova.
     * EN: Returns the filtered Workspace tree and effective node permissions.
     */
    public function getTree(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:read',
            fn(array $user): array => $this->workspaces->getTree(
                $this->routeString($request, 'workspaceSlug'),
                $user,
                $this->language($request),
            ),
        );
    }

    /**
     * HR: Kreira novo područje uz aplikacijsko pravilo o dozvoli kreiranja.
     * EN: Creates a new Workspace subject to the application's creation policy.
     */
    public function createWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->createWorkspace(
                $this->jsonBody($request),
                $user,
            ),
            201,
        );
    }

    /**
     * HR: Djelomično mijenja područje uz efektivno can_manage pravo.
     * EN: Partially updates a Workspace with the effective can_manage permission.
     */
    public function updateWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $slug = $this->routeString($request, 'workspaceSlug');
                $this->entityTags->assertMatches(
                    $request,
                    $this->workspaces->getWorkspace($slug, $user),
                );

                return $this->workspaces->updateWorkspace(
                    $slug,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Soft-briše područje uz efektivno can_manage pravo.
     * EN: Soft-deletes a Workspace with the effective can_manage permission.
     */
    public function deleteWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): null {
                $slug = $this->routeString($request, 'workspaceSlug');
                $this->entityTags->assertMatches(
                    $request,
                    $this->workspaces->getWorkspace($slug, $user),
                );
                $this->workspaces->deleteWorkspace(
                    $slug,
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Vraća administratorski popis soft-obrisanih područja.
     * EN: Returns the administrator-only list of soft-deleted workspaces.
     */
    public function listDeletedWorkspaces(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->workspaces->listDeletedWorkspaces($user),
                ),
        );
    }

    /**
     * HR: Vraća soft-obrisano područje pod slobodnim slugom.
     * EN: Restores a soft-deleted Workspace under an available slug.
     */
    public function restoreWorkspace(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);

                return $this->workspaces->restoreWorkspace(
                    $this->routeId($request, 'workspaceId'),
                    is_scalar($payload['slug'] ?? null) ? trim((string)$payload['slug']) : '',
                    $user,
                );
            },
        );
    }

    /**
     * HR: Vraća izravni ACL područja korisniku koji njime smije upravljati.
     * EN: Returns the direct Workspace ACL to a user who may manage it.
     */
    public function getWorkspaceAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getWorkspaceAcl(
                $this->routeString($request, 'workspaceSlug'),
                $user,
            ),
        );
    }

    /**
     * HR: Zamjenjuje potpuni ACL područja.
     * EN: Replaces the complete Workspace ACL.
     */
    public function replaceWorkspaceAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->replaceWorkspaceAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * HR: Pretražuje korisnike ili grupe za Workspace ACL picker.
     * EN: Searches users or groups for the Workspace ACL picker.
     */
    public function searchAclSubjects(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = is_scalar($query['category'] ?? null) ? trim((string)$query['category']) : '';
        $search = is_scalar($query['q'] ?? null) ? trim((string)$query['q']) : '';

        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->searchAclSubjects(
                $this->routeString($request, 'workspaceSlug'),
                $category,
                $search,
                $user,
            ),
        );
    }

    /**
     * HR: Dodaje interni ili vanjski link u stablo.
     * EN: Adds an internal or external link to the tree.
     */
    public function createLinkNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->createLinkNode(
                $this->routeString($request, 'workspaceSlug'),
                $this->jsonBody($request),
                $user,
            ),
            201,
            'id',
        );
    }

    /**
     * HR: Mijenja strukturne podatke jednog čvora stabla.
     * EN: Updates structural data for one tree node.
     */
    public function updateNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->updateNode(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * HR: Briše link-čvor; dokumenti ostaju odgovornost Editor API-ja.
     * EN: Deletes a link node; documents remain the Editor API's responsibility.
     */
    public function deleteLinkNode(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): null {
                $this->workspaces->deleteLinkNode(
                    $this->routeString($request, 'workspaceSlug'),
                    $this->routeId($request, 'nodeId'),
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Sprema potpuni poredak i hijerarhiju stabla.
     * EN: Stores the complete tree order and hierarchy.
     */
    public function reorderTree(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);
                $placements = $payload['placements'] ?? null;
                if (!is_array($placements) || !array_is_list($placements)) {
                    throw $this->validationError(__('Polje "placements" mora biti JSON lista.'));
                }

                $normalized = [];
                foreach ($placements as $placement) {
                    if (!is_array($placement)) {
                        throw $this->validationError(
                            __('Svaki raspored čvora mora biti JSON objekt.'),
                        );
                    }

                    $normalized[] = $this->stringKeyArray($placement);
                }

                $slug = $this->routeString($request, 'workspaceSlug');
                $this->workspaces->reorderTree($slug, $normalized, $user);

                return $this->workspaces->getTree($slug, $user, $this->language($request));
            },
        );
    }

    /**
     * HR: Vraća izravna ACL ograničenja jednog čvora.
     * EN: Returns direct ACL restrictions for one node.
     */
    public function getNodeAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->getNodeAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $user,
            ),
        );
    }

    /**
     * HR: Zamjenjuje izravna ACL ograničenja jednog čvora.
     * EN: Replaces direct ACL restrictions for one node.
     */
    public function replaceNodeAcl(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'workspace:manage',
            fn(array $user): array => $this->workspaces->replaceNodeAcl(
                $this->routeString($request, 'workspaceSlug'),
                $this->routeId($request, 'nodeId'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * HR: Provjerava scope, poziva operaciju i ujednačeno mapira očekivane greške.
     * EN: Checks the scope, invokes the operation, and consistently maps expected failures.
     *
     * @param callable(array<string,mixed>):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
        int $status = 200,
        string $locationField = 'slug',
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->responses->problem(
                $request,
                403,
                'insufficient_scope',
                __('Pristup nije dozvoljen'),
                sprintf(__('API ključ nema potreban scope "%s".'), $scope),
            );
        }

        try {
            $data = $operation($identity->user);
            if ($status === 204) {
                return $this->responses->noContent($request);
            }

            $response = $this->responses->success(
                $request,
                $data,
                $status,
                links: ['self' => $this->responses->requestTarget($request)],
            );
            if ($status === 201 && is_array($data) && is_scalar($data[$locationField] ?? null)) {
                return $response->withHeader(
                    'Location',
                    $this->responses->childTarget($request, (string)$data[$locationField]),
                );
            }

            return $response;
        } catch (ApiPreconditionException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Uvjet izmjene nije ispunjen'),
                $exception->getMessage(),
            );
        } catch (JsonException $exception) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $exception->getMessage(),
            );
        } catch (WorkspaceApiException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
            return $this->responses->problem(
                $request,
                422,
                'workspace_validation_failed',
                __('Workspace operaciju nije moguće izvršiti'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                'internal_error',
                __('Interna greška'),
                __('Zahtjev nije moguće obraditi. Obrati se administratoru uz request ID.'),
            );
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
     * HR: Dekodira i validira JSON objekt iz tijela zahtjeva.
     * EN: Decodes and validates a JSON object from the request body.
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
     * HR: Čita pozitivni numerički ID iz route atributa.
     * EN: Reads a positive numeric ID from a route attribute.
     */
    private function routeId(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_numeric($value) ? max(0, (int)$value) : 0;
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
     * HR: Vraća sigurni jezik stabla iz queryja ili zadane aplikacijske lokalizacije.
     * EN: Returns a safe tree language from the query or the application default locale.
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
     * HR: Gradi stabilnu validacijsku grešku za neispravnu strukturu zahtjeva.
     * EN: Builds a stable validation failure for an invalid request structure.
     */
    private function validationError(string $message): WorkspaceApiException
    {
        return new WorkspaceApiException('workspace_validation_failed', $message, 422);
    }
}
