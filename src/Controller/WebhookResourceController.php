<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Exception\WebhookApiException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookSubscriptionService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

use function is_array;
use function is_scalar;
use function json_decode;
use function sprintf;
use function trim;

/**
 * HR: Izlaže upravljanje vlastitim webhook pretplatama i tehničkom poviješću
 *     isporuke kroz verzionirani API.
 * EN: Exposes management of owned webhook subscriptions and technical delivery
 *     history through the versioned API.
 */
final readonly class WebhookResourceController
{
    /**
     * HR: Prima zajedničke API odgovore, webhook domenu, paginator i ETag zaštitu.
     * EN: Receives shared API responses, the webhook domain, paginator, and ETag protection.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private WebhookSubscriptionService $webhooks,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća paginirani popis pretplata dostupnih aktualnom ključu.
     * EN: Returns a paginated list of subscriptions available to the current key.
     */
    public function listSubscriptions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:read',
            fn(AuthApiIdentity $identity): mixed => $this->paginator->paginate(
                $request,
                $this->webhooks->listForIdentity($identity),
            ),
        );
    }

    /**
     * HR: Kreira pretplatu i samo u ovom odgovoru vraća novu potpisnu tajnu.
     * EN: Creates a subscription and returns its new signing secret only in this response.
     */
    public function createSubscription(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('webhooks:manage')) {
            return $this->scopeProblem($request, 'webhooks:manage');
        }

        try {
            $created = $this->webhooks->create($identity, $this->jsonBody($request));
            $subscription = $created['subscription'] ?? null;
            $uuidValue = is_array($subscription) ? ($subscription['uuid'] ?? null) : null;
            $uuid = is_scalar($uuidValue) ? trim((string)$uuidValue) : '';
            if ($uuid === '') {
                throw new RuntimeException('Created webhook subscription has no UUID.');
            }

            $location = $this->responses->childTarget($request, $uuid);

            return $this->responses->success(
                $request,
                $created,
                201,
                links: ['self' => $location],
            )->withHeader('Location', $location);
        } catch (Throwable $throwable) {
            return $this->problemFromThrowable($request, $throwable);
        }
    }

    /**
     * HR: Vraća jednu dopuštenu pretplatu bez potpisne tajne.
     * EN: Returns one permitted subscription without its signing secret.
     */
    public function getSubscription(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:read',
            fn(AuthApiIdentity $identity): array => $this->webhooks->requireForIdentity(
                $identity,
                $this->routeString($request, 'uuid'),
            ),
        );
    }

    /**
     * HR: Mijenja pretplatu samo ako klijent pošalje aktualni If-Match.
     * EN: Updates a subscription only when the client sends the current If-Match.
     */
    public function updateSubscription(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:manage',
            function (AuthApiIdentity $identity) use ($request): array {
                $uuid = $this->routeString($request, 'uuid');
                $current = $this->webhooks->requireForIdentity($identity, $uuid);
                $this->entityTags->assertMatches($request, $current);

                return $this->webhooks->update($identity, $uuid, $this->jsonBody($request));
            },
        );
    }

    /**
     * HR: Trajno uklanja pretplatu uz optimističku zaštitu.
     * EN: Permanently removes a subscription with optimistic concurrency protection.
     */
    public function deleteSubscription(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('webhooks:manage')) {
            return $this->scopeProblem($request, 'webhooks:manage');
        }

        try {
            $uuid = $this->routeString($request, 'uuid');
            $current = $this->webhooks->requireForIdentity($identity, $uuid);
            $this->entityTags->assertMatches($request, $current);
            $this->webhooks->delete($identity, $uuid);

            return $this->responses->noContent($request);
        } catch (Throwable $throwable) {
            return $this->problemFromThrowable($request, $throwable);
        }
    }

    /**
     * HR: Rotira tajnu uz If-Match i novu vrijednost vraća samo jednom.
     * EN: Rotates the secret with If-Match and returns the new value only once.
     */
    public function rotateSecret(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:manage',
            function (AuthApiIdentity $identity) use ($request): array {
                $uuid = $this->routeString($request, 'uuid');
                $current = $this->webhooks->requireForIdentity($identity, $uuid);
                $this->entityTags->assertMatches($request, $current);

                return $this->webhooks->rotateSecret($identity, $uuid);
            },
        );
    }

    /**
     * HR: Vraća paginiranu povijest isporuka jedne pretplate.
     * EN: Returns paginated delivery history for one subscription.
     */
    public function listDeliveries(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:read',
            fn(AuthApiIdentity $identity): mixed => $this->paginator->paginate(
                $request,
                $this->webhooks->listDeliveries(
                    $identity,
                    $this->routeString($request, 'uuid'),
                ),
            ),
        );
    }

    /**
     * HR: Vraća jedan pokušaj isporuke.
     * EN: Returns one delivery attempt.
     */
    public function getDelivery(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:read',
            fn(AuthApiIdentity $identity): array => $this->webhooks->requireDelivery(
                $identity,
                $this->routeString($request, 'uuid'),
                $this->routeString($request, 'deliveryUuid'),
            ),
        );
    }

    /**
     * HR: Vraća postojeću isporuku u outbox uz provjeru njezina ETag-a.
     * EN: Requeues an existing delivery after checking its ETag.
     */
    public function retryDelivery(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'webhooks:manage',
            function (AuthApiIdentity $identity) use ($request): array {
                $subscriptionUuid = $this->routeString($request, 'uuid');
                $deliveryUuid = $this->routeString($request, 'deliveryUuid');
                $current = $this->webhooks->requireDelivery(
                    $identity,
                    $subscriptionUuid,
                    $deliveryUuid,
                );
                $this->entityTags->assertMatches($request, $current);

                return $this->webhooks->retryDelivery(
                    $identity,
                    $subscriptionUuid,
                    $deliveryUuid,
                );
            },
        );
    }

    /**
     * HR: Provjerava scope, izvršava operaciju i ujednačeno mapira pogreške.
     * EN: Checks the scope, executes an operation, and consistently maps failures.
     *
     * @param callable(AuthApiIdentity):mixed $operation
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
                $operation($identity),
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable $throwable) {
            return $this->problemFromThrowable($request, $throwable);
        }
    }

    /**
     * HR: Pretvara poznate domenske, JSON i ETag pogreške u RFC problem odgovor.
     * EN: Converts known domain, JSON, and ETag errors into an RFC problem response.
     */
    private function problemFromThrowable(
        ServerRequestInterface $request,
        Throwable $throwable,
    ): ResponseInterface {
        if ($throwable instanceof ApiPreconditionException) {
            return $this->responses->problem(
                $request,
                $throwable->status,
                $throwable->errorCode,
                __('Uvjet izmjene nije ispunjen'),
                $throwable->getMessage(),
            );
        }

        if ($throwable instanceof WebhookApiException) {
            return $this->responses->problem(
                $request,
                $throwable->status,
                $throwable->errorCode,
                __('Webhook operacija nije uspjela'),
                $throwable->getMessage(),
            );
        }

        if ($throwable instanceof JsonException) {
            return $this->responses->problem(
                $request,
                400,
                'invalid_json',
                __('Neispravan JSON'),
                $throwable->getMessage(),
            );
        }

        return $this->responses->problem(
            $request,
            500,
            'webhook_operation_failed',
            __('Webhook operacija nije uspjela'),
            __('Webhook operaciju trenutačno nije moguće izvršiti.'),
        );
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
            throw new WebhookApiException(
                422,
                'invalid_webhook_payload',
                __('JSON tijelo mora biti objekt.'),
            );
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new WebhookApiException(
                    422,
                    'invalid_webhook_payload',
                    __('JSON tijelo mora biti objekt.'),
                );
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
            throw new WebhookApiException(
                422,
                'invalid_webhook_identifier',
                __('Webhook identifikator nije valjan.'),
            );
        }

        return trim((string)$value);
    }
}
