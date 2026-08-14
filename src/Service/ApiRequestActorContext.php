<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

/**
 * HR: Čuva sigurni identitet trenutačnog stateless API zahtjeva. Kontekst ne
 *     sadrži Bearer ključ, scopeove ni druge vjerodajnice i prepisuje se na
 *     početku svakoga zahtjeva, što ga čini sigurnim i za dugotrajne runtimeove.
 * EN: Stores the safe identity of the current stateless API request. The
 *     context never contains the Bearer key, scopes, or other credentials and
 *     is overwritten at the start of every request, making it safe for
 *     long-running runtimes as well.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiRequestActorContextTest
 */
final class ApiRequestActorContext
{
    /** @var array{id:int,label:?string,type:string,auth_method:string,channel:string,request_id:?string}|null */
    private ?array $actor = null;

    /** HR: Pamti postoji li precizniji domenski zapis zahtjeva. EN: Remembers whether the request already has a precise domain record. */
    private bool $businessEventRecorded = false;

    /**
     * HR: Sprema samo javni identitet korisnika nakon uspješne autentikacije.
     * EN: Stores only the user's public identity after successful authentication.
     */
    public function useApiActor(int $userId, ?string $label, ?string $requestId = null): void
    {
        if ($userId < 1) {
            $this->clear();

            return;
        }

        $cleanLabel = is_string($label) && trim($label) !== ''
            ? mb_substr(trim($label), 0, 190)
            : null;
        $cleanRequestId = is_string($requestId) && trim($requestId) !== ''
            ? mb_substr(trim($requestId), 0, 128)
            : null;

        $this->actor = [
            'id' => $userId,
            'label' => $cleanLabel,
            'type' => 'user',
            'auth_method' => 'api_key',
            'channel' => 'api',
            'request_id' => $cleanRequestId,
        ];
        $this->businessEventRecorded = false;
    }

    /**
     * HR: Vraća identitet za opcionalne module poput Audit modula.
     * EN: Returns the identity to optional modules such as Audit.
     *
     * @return array{id:int,label:?string,type:string,auth_method:string,channel:string,request_id:?string}|null
     */
    public function current(): ?array
    {
        return $this->actor;
    }

    /** HR: Označava da je poslovni modul već zabilježio zahtjev. EN: Marks that a business module already recorded the request. */
    public function markBusinessEventRecorded(): void
    {
        if ($this->actor !== null) {
            $this->businessEventRecorded = true;
        }
    }

    /** HR: Provjerava treba li preskočiti generički duplikat. EN: Checks whether a generic duplicate should be skipped. */
    public function hasBusinessEventRecorded(): bool
    {
        return $this->businessEventRecorded;
    }

    /** HR: Uklanja identitet prethodnog zahtjeva. EN: Clears the previous request identity. */
    public function clear(): void
    {
        $this->actor = null;
        $this->businessEventRecorded = false;
    }
}
