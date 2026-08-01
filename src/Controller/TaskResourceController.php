<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleTask\Api\TaskApiException;
use AaiEduHr\HeartPhrameModuleTask\Api\TaskApiService;
use HeartPhrame\Config\ConfigInterface;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: HTTP adapter za taskove ugrađene u objavljene Editor stranice.
 * EN: HTTP adapter for tasks embedded in published Editor pages.
 */
final readonly class TaskResourceController
{
    /**
     * HR: Prima API odgovore, neutralni Task servis i jezičnu konfiguraciju.
     * EN: Receives API responses, the neutral Task service, and locale configuration.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private TaskApiService $tasks,
        private ConfigInterface $config,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća sve taskove iz aktualne objave stranice.
     * EN: Returns all tasks from the page's current publication.
     */
    public function listTasks(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'task:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->tasks->listTasks(
                        $this->routeString($request, 'documentId'),
                        $this->language($request),
                        $user,
                    ),
                ),
        );
    }

    /**
     * HR: Vraća jedan task i njegovo aktualno stanje.
     * EN: Returns one task and its current state.
     */
    public function getTask(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'task:read',
            fn(array $user): array => $this->tasks->getTask(
                $this->routeString($request, 'documentId'),
                $this->language($request),
                $this->routeString($request, 'taskUuid'),
                $user,
            ),
        );
    }

    /**
     * HR: Postavlja status taska bez stvaranja nove verzije stranice.
     * EN: Sets task state without creating a new page version.
     */
    public function setState(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'task:write',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);
                if (!is_bool($payload['completed'] ?? null)) {
                    throw new TaskApiException(
                        'task_validation_failed',
                        __('Polje "completed" mora biti true ili false.'),
                        422,
                    );
                }

                $documentId = $this->routeString($request, 'documentId');
                $language = $this->language($request);
                $taskUuid = $this->routeString($request, 'taskUuid');
                $this->entityTags->assertMatches(
                    $request,
                    $this->tasks->getTask($documentId, $language, $taskUuid, $user),
                );

                return $this->tasks->setState(
                    $documentId,
                    $language,
                    $taskUuid,
                    $payload['completed'],
                    $user,
                );
            },
        );
    }

    /**
     * HR: Vraća audit promjena statusa jednog taska.
     * EN: Returns one task's state-change audit.
     */
    public function history(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'task:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->tasks->history(
                        $this->routeString($request, 'documentId'),
                        $this->language($request),
                        $this->routeString($request, 'taskUuid'),
                        100,
                        $user,
                    ),
                ),
        );
    }

    /**
     * HR: Provjerava scope, poziva Task operaciju i mapira pogreške.
     * EN: Checks a scope, invokes a Task operation, and maps failures.
     *
     * @param callable(array<string,mixed>):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
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
            return $this->responses->success(
                $request,
                $operation($identity->user),
                links: ['self' => $this->responses->requestTarget($request)],
            );
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
        } catch (TaskApiException $exception) {
            return $this->responses->problem(
                $request,
                $exception->status,
                $exception->errorCode,
                __('Task operaciju nije moguće izvršiti'),
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
     * HR: Vraća API identitet koji je postavio middleware.
     * EN: Returns the API identity attached by middleware.
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

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Čita tekstualni route parametar.
     * EN: Reads a textual route parameter.
     */
    private function routeString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Vraća siguran jezik iz queryja ili aplikacijske konfiguracije.
     * EN: Returns a safe locale from query or application configuration.
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
}
