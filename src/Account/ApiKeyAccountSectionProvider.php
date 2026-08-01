<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Account;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestService;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountSectionProviderInterface;
use HeartPhrame\Routing\UrlGenerator;

/**
 * HR: Dodaje zahtjeve za osobni API ključ u proširivi Auth profil samo kada je
 *     API modul instaliran i njegova početna migracija spremna.
 *
 * EN: Adds personal API-key requests to the extensible Auth profile only when
 *     the API module is installed and its initial migration is ready.
 */
final readonly class ApiKeyAccountSectionProvider implements AuthAccountSectionProviderInterface
{
    /**
     * HR: Prima servis zahtjeva, dinamički katalog scopeova i generator ruta.
     *
     * EN: Receives the request service, dynamic scope catalog, and route generator.
     */
    public function __construct(
        private ApiKeyRequestService $requestService,
        private ApiScopeRegistry $scopeRegistry,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Vraća opis partiala s korisnikovim zahtjevima i formom za novi zahtjev.
     *
     * EN: Returns the partial descriptor with the user's requests and new-request form.
     *
     * @return array{key:string,package:string,partial:string,data:array<string,mixed>}|null
     */
    public function sectionForUser(int $userId): ?array
    {
        if ($userId <= 0 || !$this->requestService->isSchemaReady()) {
            return null;
        }

        $requests = $this->requestService->listForUser($userId);
        $hasPending = false;
        foreach ($requests as $request) {
            if (($request['status'] ?? null) === ApiKeyRequestService::STATUS_PENDING) {
                $hasPending = true;
                break;
            }
        }

        return [
            'key' => 'api-key-requests',
            'package' => ModuleApi::PACKAGE_NAME,
            'partial' => 'api/account_key_requests',
            'data' => [
                'requests' => $requests,
                'hasPendingRequest' => $hasPending,
                'scopeGroups' => $this->scopeRegistry->grouped(),
                'requestPath' => $this->path(
                    'api.key-request.create',
                    '/account/api-keys/requests',
                ),
                'revealPathTemplate' => $this->path(
                    'api.key-request.reveal',
                    '/account/api-keys/requests/__UUID__/secret',
                    ['uuid' => '__UUID__'],
                ),
            ],
        ];
    }

    /**
     * HR: Vraća imenovanu rutu ili sigurnu fallback putanju.
     *
     * EN: Returns a named route or a safe fallback path.
     *
     * @param array<string,string> $parameters
     */
    private function path(string $route, string $fallback, array $parameters = []): string
    {
        return $this->urlGenerator->namedRouteExists($route)
            ? $this->urlGenerator->getPathFor($route, $parameters)
            : rtrim($this->urlGenerator->getBasePath(), '/') . $fallback;
    }
}
