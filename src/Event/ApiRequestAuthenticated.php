<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Event;

/**
 * HR: Neutralni događaj uspješno autentificiranog API zahtjeva. Sadrži samo
 *     javni identitet izvršitelja i nikada ne sadrži Bearer ključ ili scopeove.
 * EN: Neutral event for a successfully authenticated API request. It contains
 *     only the actor's public identity and never the Bearer key or scopes.
 */
final readonly class ApiRequestAuthenticated
{
    /** HR: Sprema sigurni identitet zahtjeva. EN: Stores the request's safe identity. */
    public function __construct(
        public int $userId,
        public ?string $actorLabel,
        public string $requestId,
    ) {
    }
}
