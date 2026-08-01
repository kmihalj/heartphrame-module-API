<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: HTTP adapter za inbox vlasnika API ključa. Nijedna radnja ne može
 *     pristupiti obavijestima drugog korisnika.
 * EN: HTTP adapter for the API-key owner's inbox. No action can access another
 *     user's notifications.
 */
final readonly class NotificationResourceController
{
    /**
     * HR: Prima zajedničke API odgovore i neutralni Notification servis.
     * EN: Receives shared API responses and the neutral Notification service.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private NotificationService $notifications,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća paginirani inbox uz opcionalni `state=all|read|unread` filtar.
     * EN: Returns a paginated inbox with an optional `state=all|read|unread` filter.
     */
    public function listNotifications(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'notifications:read',
            function (int $userId) use ($request): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage {
                $parameters = $this->paginator->parameters($request);
                $result = $this->notifications->inbox(
                    $userId,
                    intdiv($parameters['offset'], $parameters['limit']) + 1,
                    $parameters['limit'],
                    $this->queryString($request, 'state', 'all'),
                );

                return $this->paginator->pageFromWindow(
                    $request,
                    $result['items'],
                    $result['total'],
                );
            },
        );
    }

    /**
     * HR: Vraća jednu obavijest koja pripada vlasniku ključa.
     * EN: Returns one notification owned by the key owner.
     */
    public function getNotification(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'notifications:read',
            function (int $userId) use ($request): array {
                $item = $this->notifications->findForUserByUuid(
                    $userId,
                    $this->routeString($request, 'uuid'),
                );
                if (!is_array($item)) {
                    throw new RuntimeException(__('Obavijest nije pronađena.'));
                }

                return $item;
            },
        );
    }

    /**
     * HR: Postavlja pročitano/nepročitano stanje jedne vlastite obavijesti.
     * EN: Sets the read/unread state of one owned notification.
     */
    public function updateNotification(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'notifications:write',
            function (int $userId) use ($request): array {
                $payload = $this->jsonBody($request);
                if (!is_bool($payload['read'] ?? null)) {
                    throw new RuntimeException(__('Polje "read" mora biti true ili false.'));
                }

                $uuid = $this->routeString($request, 'uuid');
                $current = $this->notifications->findForUserByUuid($userId, $uuid);
                if (!is_array($current)) {
                    throw new RuntimeException(__('Obavijest nije pronađena.'));
                }

                $this->entityTags->assertMatches($request, $current);
                $item = $payload['read']
                    ? $this->notifications->markRead($userId, $uuid)
                    : $this->notifications->markUnread($userId, $uuid);
                if (!is_array($item)) {
                    throw new RuntimeException(__('Obavijest nije pronađena.'));
                }

                return $item;
            },
        );
    }

    /**
     * HR: Označava sve obavijesti vlasnika ključa pročitanima.
     * EN: Marks all notifications owned by the key owner as read.
     */
    public function markAllRead(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'notifications:write',
            fn(int $userId): array => [
                'updated' => $this->notifications->markAllRead($userId),
            ],
        );
    }

    /**
     * HR: Trajno uklanja jednu vlastitu pročitanu obavijest.
     * EN: Permanently removes one owned read notification.
     */
    public function deleteNotification(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('notifications:write')) {
            return $this->scopeProblem($request, 'notifications:write');
        }

        $uuid = $this->routeString($request, 'uuid');
        $current = $this->notifications->findForUserByUuid($identity->userId(), $uuid);
        if (!is_array($current)) {
            return $this->responses->problem(
                $request,
                404,
                'notification_not_found',
                __('Obavijest nije pronađena'),
                __('Tražena obavijest ne postoji.'),
            );
        }

        try {
            $this->entityTags->assertMatches($request, $current);
        } catch (ApiPreconditionException $apiPreconditionException) {
            return $this->responses->problem(
                $request,
                $apiPreconditionException->status,
                $apiPreconditionException->errorCode,
                __('Uvjet izmjene nije ispunjen'),
                $apiPreconditionException->getMessage(),
            );
        }

        if (
            !$this->notifications->deleteRead(
                $identity->userId(),
                $uuid,
            )
        ) {
            return $this->responses->problem(
                $request,
                409,
                'notification_not_read',
                __('Obavijest nije uklonjena'),
                __('Ukloniti se može samo postojeća pročitana obavijest vlasnika ključa.'),
            );
        }

        return $this->responses->noContent($request);
    }

    /**
     * HR: Provjerava scope, izvršava operaciju i ujednačeno mapira pogreške.
     * EN: Checks the scope, executes an operation, and consistently maps failures.
     *
     * @param callable(int):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->scopeProblem($request, $scope);
        }

        try {
            return $this->responses->success(
                $request,
                $operation($identity->userId()),
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
        } catch (RuntimeException $exception) {
            $notFound = str_contains(strtolower($exception->getMessage()), 'prona');

            return $this->responses->problem(
                $request,
                $notFound ? 404 : 422,
                $notFound ? 'notification_not_found' : 'notification_validation_failed',
                $notFound ? __('Obavijest nije pronađena') : __('Zahtjev nije valjan'),
                $exception->getMessage(),
            );
        } catch (Throwable) {
            return $this->responses->problem(
                $request,
                500,
                'notification_operation_failed',
                __('Operacija nije uspjela'),
                __('Operaciju nad obavijestima trenutačno nije moguće izvršiti.'),
            );
        }
    }

    /**
     * HR: Dohvaća identitet koji je postavio autentikacijski middleware.
     * EN: Returns the identity attached by the authentication middleware.
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
     * HR: Vraća standardnu zabranu za nedostajući scope.
     * EN: Returns the standard denial response for a missing scope.
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
     * HR: Dekodira JSON objekt iz tijela zahtjeva.
     * EN: Decodes a JSON object from the request body.
     *
     * @return array<string,mixed>
     */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $body = trim((string)$request->getBody());
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('JSON tijelo mora biti objekt.'));
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException(__('JSON tijelo mora biti objekt.'));
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * HR: Čita obavezni string parametar rute.
     * EN: Reads a required string route parameter.
     */
    private function routeString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_scalar($value) || trim((string)$value) === '') {
            throw new RuntimeException(__('Identifikator resursa nije valjan.'));
        }

        return trim((string)$value);
    }

    /**
     * HR: Čita kratki string iz queryja uz zadanu vrijednost.
     * EN: Reads a short query string with a default value.
     */
    private function queryString(
        ServerRequestInterface $request,
        string $name,
        string $default,
    ): string {
        $value = $request->getQueryParams()[$name] ?? $default;

        return is_scalar($value) ? trim((string)$value) : $default;
    }
}
