<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HR: Objavljuje početni discovery dokument verzioniranog API-ja.
 *
 * EN: Publishes the entry discovery document for the versioned API.
 */
final readonly class ApiRootController
{
    /**
     * HR: Inicijalizira discovery endpoint API-ja.
     *
     * EN: Initializes the API discovery endpoint.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private ApiScopeRegistry $scopeRegistry,
        private WebhookConfig $webhooks,
    ) {
    }

    /**
     * HR: Vraća verziju, dostupne resurse i scopeove uključenih modula.
     *
     * EN: Returns the version, available resources, and enabled-module scopes.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $scopeGroups = $this->scopeRegistry->grouped();

        return $this->responses->success(
            $request,
            [
                'name' => 'HeartPhrame API',
                'version' => 'v1',
                'resources' => array_values(array_map(
                    static fn(array $group): string => $group['resource'],
                    $scopeGroups,
                )),
                'scopes' => $this->scopeRegistry->all(),
                'scope_groups' => $scopeGroups,
                'security' => [
                    'authentication' => 'bearer',
                    'rate_limit_headers' => true,
                    'idempotency_key_for_writes' => true,
                    'problem_format' => 'RFC 9457',
                    'webhooks' => [
                        'enabled' => $this->webhooks->enabled(),
                        'delivery' => 'asynchronous',
                        'signature' => 'hmac-sha256',
                    ],
                ],
            ],
            links: ['self' => $this->responses->requestTarget($request)],
        );
    }
}
