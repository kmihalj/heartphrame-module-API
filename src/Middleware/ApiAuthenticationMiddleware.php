<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Middleware;

use AaiEduHr\HeartPhrameModuleApi\Event\ApiRequestAuthenticated;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestActorContext;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestGuard;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiWebhookPublisher;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HR: Autenticira stateless API zahtjeve Bearer ključem prije poziva kontrolera.
 *
 * EN: Authenticates stateless API requests with a Bearer key before invoking controllers.
 */
final readonly class ApiAuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * HR: Inicijalizira stateless Bearer autentikaciju API zahtjeva.
     *
     * EN: Initializes stateless Bearer authentication for API requests.
     */
    public function __construct(
        private AuthApiKeyService $apiKeyService,
        private ApiResponseFactory $responses,
        private ?ApiRequestGuard $requestGuard = null,
        private ?ApiWebhookPublisher $webhookPublisher = null,
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
        private ?ApiRequestActorContext $actorContext = null,
    ) {
    }

    /**
     * HR: Provjerava Bearer token i dodaje Auth API identitet u request.
     *
     * EN: Verifies the Bearer token and attaches the Auth API identity to the request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // HR: Novi zahtjev nikada ne smije naslijediti izvršitelja prethodnoga.
        // EN: A new request must never inherit the previous request's actor.
        $this->actorContext?->clear();

        $authorization = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(.+)$/iD', $authorization, $matches) !== 1) {
            return $this->unauthorized($request);
        }

        $server = $request->getServerParams();
        $remoteIp = is_scalar($server['REMOTE_ADDR'] ?? null) ? trim((string)$server['REMOTE_ADDR']) : null;
        $identity = $this->apiKeyService->authenticate(trim($matches[1]), $remoteIp);
        if (!$identity instanceof \AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity) {
            return $this->unauthorized($request);
        }

        $requestId = $this->responses->requestId($request);

        $request = $request
            ->withAttribute(ModuleApi::REQUEST_ID_ATTRIBUTE, $requestId)
            ->withAttribute(ModuleApi::IDENTITY_ATTRIBUTE, $identity);

        $user = $identity->user;
        $label = $user['display_name'] ?? $user['login_identifier'] ?? null;
        $actorLabel = is_scalar($label) && trim((string)$label) !== '' ? trim((string)$label) : null;
        $this->actorContext?->useApiActor($identity->userId(), $actorLabel, $requestId);
        $this->publishAuthenticatedActor($identity, $requestId);

        $response = $this->requestGuard instanceof ApiRequestGuard
            ? $this->requestGuard->handle($request, $identity, $handler)
            : $handler->handle($request);

        if ($this->webhookPublisher instanceof ApiWebhookPublisher) {
            $this->webhookPublisher->publish($request, $response);
        }

        return $response;
    }

    /**
     * HR: Objavljuje sigurni identitet drugim opcionalnim modulima. Neuspjeh
     *     audit integracije nikada ne prekida valjani API zahtjev.
     * EN: Publishes the safe identity to optional modules. An audit integration
     *     failure never interrupts an otherwise valid API request.
     */
    private function publishAuthenticatedActor(
        \AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity $identity,
        string $requestId,
    ): void {
        if (!$this->events instanceof EventDispatcherInterface) {
            return;
        }

        $user = $identity->user;
        $label = $user['display_name'] ?? $user['login_identifier'] ?? null;

        try {
            $this->events->dispatch(new ApiRequestAuthenticated(
                $identity->userId(),
                is_scalar($label) && trim((string)$label) !== '' ? trim((string)$label) : null,
                $requestId,
            ));
        } catch (Throwable $throwable) {
            $this->logger?->error('Unable to publish authenticated API actor context.', [
                'module' => 'api',
                'request_id' => $requestId,
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Vraća jednaku 401 poruku za sve neuspješne autentikacijske razloge.
     *
     * EN: Returns the same 401 response for every authentication failure reason.
     */
    private function unauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            401,
            'invalid_api_key',
            __('Autentikacija nije uspjela'),
            __('Pošalji valjani aktivni API ključ kao Bearer token.'),
        )->withHeader('WWW-Authenticate', 'Bearer realm="HeartPhrame API"');
    }
}
