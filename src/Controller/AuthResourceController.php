<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAdministrationApiService;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Pretvara verzionirane HTTP zahtjeve u pozive Auth administracijskog servisa.
 *
 * EN: Translates versioned HTTP requests into Auth administration service calls.
 */
final readonly class AuthResourceController
{
    /**
     * HR: Inicijalizira HTTP adapter za Auth administratorske resurse.
     *
     * EN: Initializes the HTTP adapter for Auth administration resources.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private AuthAdministrationApiService $administration,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća cursor-paginirani popis lokalnih korisnika.
     *
     * EN: Returns a cursor-paginated list of local users.
     */
    public function listUsers(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'users:read');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $page = is_array($query['page'] ?? null) ? $query['page'] : [];
        $limit = is_numeric($page['limit'] ?? null) ? (int)$page['limit'] : 50;
        $after = $this->decodeCursor($page['after'] ?? null);

        try {
            $page = $this->administration->listUsers($limit, $after, $this->identity($request)->userId());
            $nextCursor = $page['has_more'] && $page['last_id'] > 0
                ? $this->encodeCursor($page['last_id'])
                : null;

            return $this->responses->success(
                $request,
                $page['items'],
                meta: [
                    'page' => [
                        'limit' => max(1, min(100, $limit)),
                        'has_more' => $page['has_more'],
                        'next_cursor' => $nextCursor,
                    ],
                ],
                links: [
                    'self' => $this->responses->requestTarget($request),
                    'next' => $nextCursor !== null
                        ? $request->getUri()->getPath() . '?page[limit]=' . max(1, min(100, $limit))
                            . '&page[after]=' . rawurlencode($nextCursor)
                        : null,
                ],
            );
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Vraća jednog lokalnog korisnika.
     *
     * EN: Returns one local user.
     */
    public function getUser(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'users:read');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $user = $this->administration->findUser(
                $this->routeId($request, 'userId'),
                $this->identity($request)->userId(),
            );
            if ($user === null) {
                return $this->notFound($request, __('Korisnik nije pronađen.'));
            }

            return $this->responses->success(
                $request,
                $user,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Kreira lokalnog korisnika.
     *
     * EN: Creates a local user.
     */
    public function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'users:create');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $user = $this->administration->createUser(
                $this->jsonBody($request),
                $this->identity($request)->userId(),
            );
            $userId = $this->payloadId($user);
            $location = $this->responses->childTarget($request, $userId);

            return $this->responses->success(
                $request,
                $user,
                201,
                links: ['self' => $location],
            )->withHeader('Location', $location);
        } catch (JsonException $exception) {
            return $this->invalidJson($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Djelomično mijenja lokalnog korisnika.
     *
     * EN: Partially updates a local user.
     */
    public function updateUser(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'users:update');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $this->assertUserMatches($request);
            $user = $this->administration->updateUser(
                $this->routeId($request, 'userId'),
                $this->jsonBody($request),
                $this->identity($request)->userId(),
            );

            return $this->responses->success(
                $request,
                $user,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (JsonException $exception) {
            return $this->invalidJson($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Deaktivira lokalnog korisnika i opoziva njegove API ključeve.
     *
     * EN: Deactivates a local user and revokes their API keys.
     */
    public function deleteUser(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'users:delete');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $this->assertUserMatches($request);
            $this->administration->deactivateUser(
                $this->routeId($request, 'userId'),
                $this->identity($request)->userId(),
            );

            return $this->responses->noContent($request);
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Zamjenjuje ručna članstva lokalnog korisnika.
     *
     * EN: Replaces a local user's manual memberships.
     */
    public function replaceUserGroups(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:manage');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $this->assertUserMatches($request);
            $payload = $this->jsonBody($request);
            $user = $this->administration->replaceUserGroups(
                $this->routeId($request, 'userId'),
                $payload['group_ids'] ?? null,
                $this->identity($request)->userId(),
            );

            return $this->responses->success(
                $request,
                $user,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (JsonException $exception) {
            return $this->invalidJson($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Vraća popis lokalnih grupa.
     *
     * EN: Returns the local group list.
     */
    public function listGroups(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:read');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            return $this->responses->success(
                $request,
                $this->paginator->paginate(
                    $request,
                    $this->administration->listGroups($this->identity($request)->userId()),
                ),
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Vraća jednu lokalnu grupu.
     *
     * EN: Returns one local group.
     */
    public function getGroup(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:read');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $group = $this->administration->findGroup(
                $this->routeId($request, 'groupId'),
                $this->identity($request)->userId(),
            );
            if ($group === null) {
                return $this->notFound($request, __('Grupa nije pronađena.'));
            }

            return $this->responses->success(
                $request,
                $group,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Kreira lokalnu nesistemsku grupu.
     *
     * EN: Creates a local non-system group.
     */
    public function createGroup(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:manage');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $group = $this->administration->createGroup(
                $this->jsonBody($request),
                $this->identity($request)->userId(),
            );
            $groupId = $this->payloadId($group);
            $location = $this->responses->childTarget($request, $groupId);

            return $this->responses->success(
                $request,
                $group,
                201,
                links: ['self' => $location],
            )->withHeader('Location', $location);
        } catch (JsonException $exception) {
            return $this->invalidJson($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Mijenja lokalnu nesistemsku grupu.
     *
     * EN: Updates a local non-system group.
     */
    public function updateGroup(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:manage');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $this->assertGroupMatches($request);
            $group = $this->administration->updateGroup(
                $this->routeId($request, 'groupId'),
                $this->jsonBody($request),
                $this->identity($request)->userId(),
            );

            return $this->responses->success(
                $request,
                $group,
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (JsonException $exception) {
            return $this->invalidJson($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Briše lokalnu nesistemsku grupu i njezina članstva.
     *
     * EN: Deletes a local non-system group and its memberships.
     */
    public function deleteGroup(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->authorize($request, 'groups:manage');
        if ($denied instanceof \Psr\Http\Message\ResponseInterface) {
            return $denied;
        }

        try {
            $this->assertGroupMatches($request);
            $this->administration->deleteGroup(
                $this->routeId($request, 'groupId'),
                $this->identity($request)->userId(),
            );

            return $this->responses->noContent($request);
        } catch (ApiPreconditionException $exception) {
            return $this->preconditionProblem($request, $exception);
        } catch (Throwable $throwable) {
            return $this->domainFailure($request, $throwable);
        }
    }

    /**
     * HR: Provjerava scope i administratorski status vlasnika ključa.
     *
     * EN: Checks the scope and the key owner's administrator status.
     */
    private function authorize(ServerRequestInterface $request, string $scope): ?ResponseInterface
    {
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

        if (!$identity->isAdmin()) {
            return $this->responses->problem(
                $request,
                403,
                'administrator_required',
                __('Pristup nije dozvoljen'),
                __('Operacija zahtijeva aktivnog lokalnog administratora.'),
            );
        }

        return null;
    }

    /**
     * HR: Vraća autentificirani identitet koji je postavio middleware.
     *
     * EN: Returns the authenticated identity attached by middleware.
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
     * HR: Uspoređuje If-Match s trenutačnim sigurnim DTO-om korisnika.
     * EN: Compares If-Match with the user's current safe DTO.
     *
     * @throws ApiPreconditionException
     */
    private function assertUserMatches(ServerRequestInterface $request): void
    {
        $current = $this->administration->findUser(
            $this->routeId($request, 'userId'),
            $this->identity($request)->userId(),
        );
        if ($current === null) {
            throw new RuntimeException(__('Korisnik nije pronađen.'));
        }

        $this->entityTags->assertMatches($request, $current);
    }

    /**
     * HR: Uspoređuje If-Match s trenutačnim sigurnim DTO-om grupe.
     * EN: Compares If-Match with the group's current safe DTO.
     *
     * @throws ApiPreconditionException
     */
    private function assertGroupMatches(ServerRequestInterface $request): void
    {
        $current = $this->administration->findGroup(
            $this->routeId($request, 'groupId'),
            $this->identity($request)->userId(),
        );
        if ($current === null) {
            throw new RuntimeException(__('Grupa nije pronađena.'));
        }

        $this->entityTags->assertMatches($request, $current);
    }

    /**
     * HR: Pretvara precondition iznimku u stabilni problem odgovor.
     * EN: Converts a precondition exception into a stable problem response.
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
     * HR: Dekodira i validira JSON objekt iz tijela zahtjeva.
     *
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

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Čita pozitivni ID iz sigurnog servisnog DTO-a.
     *
     * EN: Reads a positive ID from a safe service DTO.
     *
     * @param array<string,mixed> $payload
     */
    private function payloadId(array $payload): int
    {
        return is_numeric($payload['id'] ?? null) ? max(0, (int)$payload['id']) : 0;
    }

    /**
     * HR: Čita pozitivni numerički ID iz route atributa.
     *
     * EN: Reads a positive numeric ID from a route attribute.
     */
    private function routeId(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /**
     * HR: Kodira interni numerički cursor u neprozirnu javnu oznaku.
     *
     * EN: Encodes an internal numeric cursor into an opaque public token.
     */
    private function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode('id:' . $id), '+/', '-_'), '=');
    }

    /**
     * HR: Dekodira neprozirni cursor; nevaljana vrijednost počinje od početka.
     *
     * EN: Decodes an opaque cursor; an invalid value starts from the beginning.
     */
    private function decodeCursor(mixed $cursor): int
    {
        if (!is_scalar($cursor) || trim((string)$cursor) === '') {
            return 0;
        }

        $encoded = strtr(trim((string)$cursor), '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);

        return is_string($decoded) && preg_match('/^id:(\d+)$/D', $decoded, $matches) === 1
            ? (int)$matches[1]
            : 0;
    }

    /**
     * HR: Pretvara neispravni JSON u stabilni problem odgovor.
     *
     * EN: Converts invalid JSON into a stable problem response.
     */
    private function invalidJson(ServerRequestInterface $request, JsonException $exception): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            400,
            'invalid_json',
            __('Neispravan JSON'),
            $exception->getMessage(),
        );
    }

    /**
     * HR: Pretvara očekivanu domensku grešku u 422, a neočekivanu u sigurni 500.
     *
     * EN: Converts an expected domain failure into 422 and an unexpected one into a safe 500.
     */
    private function domainFailure(ServerRequestInterface $request, Throwable $throwable): ResponseInterface
    {
        if ($throwable instanceof RuntimeException) {
            return $this->responses->problem(
                $request,
                422,
                'domain_validation_failed',
                __('Operaciju nije moguće izvršiti'),
                $throwable->getMessage(),
            );
        }

        return $this->responses->problem(
            $request,
            500,
            'internal_error',
            __('Interna greška'),
            __('Zahtjev nije moguće obraditi. Obrati se administratoru uz request ID.'),
        );
    }

    /**
     * HR: Vraća standardni 404 problem odgovor.
     *
     * EN: Returns the standard 404 problem response.
     */
    private function notFound(ServerRequestInterface $request, string $detail): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            404,
            'resource_not_found',
            __('Resurs nije pronađen'),
            $detail,
        );
    }
}
