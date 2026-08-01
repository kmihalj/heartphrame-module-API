<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * HR: Objavljuje sigurni profil vlasnika aktualnog API ključa.
 *
 * EN: Exposes the safe profile of the current API-key owner.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Controller\MeControllerTest
 */
final readonly class MeController
{
    /**
     * HR: Prima zajedničku tvornicu verzioniranih API odgovora.
     *
     * EN: Receives the shared versioned API response factory.
     */
    public function __construct(private ApiResponseFactory $responses)
    {
    }

    /**
     * HR: Vraća identitet, scopeove i javne korisničke podatke bez lozinke.
     *
     * EN: Returns identity, scopes, and public user data without password fields.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AuthApiIdentity) {
            throw new RuntimeException('Authenticated API identity is missing.');
        }

        $user = $identity->user;

        return $this->responses->success(
            $request,
            [
                'key' => [
                    'id' => $identity->keyId,
                    'public_id' => $identity->publicId,
                    'scopes' => $identity->scopes,
                ],
                'user' => [
                    'id' => $identity->userId(),
                    'login_identifier' => $this->stringValue($user, 'login_identifier'),
                    'display_name' => $this->stringValue($user, 'display_name'),
                    'email' => $this->stringValue($user, 'email'),
                    'first_name' => $this->stringValue($user, 'first_name'),
                    'last_name' => $this->stringValue($user, 'last_name'),
                    'group_ids' => $this->integerList($user['group_ids'] ?? null),
                    'group_keys' => $this->stringList($user['group_keys'] ?? null),
                    'is_admin' => $identity->isAdmin(),
                    'is_active' => (bool)($user['is_active'] ?? false),
                    'auth_source' => $this->stringValue($user, 'auth_source'),
                ],
            ],
            links: ['self' => $this->responses->requestTarget($request)],
        );
    }

    /**
     * HR: Sigurno čita tekstualno polje iz internog korisničkog payload-a.
     *
     * EN: Safely reads a string field from the internal user payload.
     *
     * @param array<string,mixed> $user
     */
    private function stringValue(array $user, string $key): string
    {
        return is_scalar($user[$key] ?? null) ? (string)$user[$key] : '';
    }

    /**
     * HR: Normalizira javni popis numeričkih identifikatora grupa.
     *
     * EN: Normalizes the public list of numeric group identifiers.
     *
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): int => is_numeric($item) ? (int)$item : 0,
            $value,
        ), static fn(int $item): bool => $item > 0));
    }

    /**
     * HR: Normalizira javni popis stabilnih ključeva grupa.
     *
     * EN: Normalizes the public list of stable group keys.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value,
        ), static fn(string $item): bool => $item !== ''));
    }
}
