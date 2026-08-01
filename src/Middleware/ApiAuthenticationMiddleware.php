<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Middleware;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestGuard;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiWebhookPublisher;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
    ) {
    }

    /**
     * HR: Provjerava Bearer token i dodaje Auth API identitet u request.
     *
     * EN: Verifies the Bearer token and attaches the Auth API identity to the request.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
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

        $response = $this->requestGuard instanceof ApiRequestGuard
            ? $this->requestGuard->handle($request, $identity, $handler)
            : $handler->handle($request);

        if ($this->webhookPublisher instanceof ApiWebhookPublisher) {
            $this->webhookPublisher->publish($request, $response);
        }

        return $response;
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
