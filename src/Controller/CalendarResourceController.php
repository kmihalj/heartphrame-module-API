<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleCalendar\Api\CalendarApiException;
use AaiEduHr\HeartPhrameModuleCalendar\Api\CalendarApiService;
use HeartPhrame\Http\ResponseFactory;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: HTTP adapter za ACL-svjesne Calendar resurse.
 * EN: HTTP adapter for ACL-aware Calendar resources.
 */
final readonly class CalendarResourceController
{
    /**
     * HR: Prima zajedničke API odgovore, HTTP tvornicu i neutralni Calendar servis.
     * EN: Receives shared API responses, the HTTP factory, and neutral Calendar service.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private ResponseFactory $httpResponses,
        private CalendarApiService $calendars,
        private ApiCursorPaginator $paginator,
        private ApiEntityTagService $entityTags,
    ) {
    }

    /**
     * HR: Vraća kalendare vidljive vlasniku API ključa.
     * EN: Returns calendars visible to the API-key owner.
     */
    public function listCalendars(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->calendars->listCalendars($user),
                ),
        );
    }

    /**
     * HR: Kreira novi kalendar uz domensku provjeru prava.
     * EN: Creates a new calendar with domain permission checks.
     */
    public function createCalendar(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            fn(array $user): array => $this->calendars->createCalendar(
                $this->jsonBody($request),
                $user,
            ),
            201,
            'uuid',
        );
    }

    /**
     * HR: Uvozi iCalendar tekst u postojeći ili novi kalendar.
     * EN: Imports iCalendar text into an existing or new calendar.
     */
    public function importCalendar(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            fn(array $user): array => $this->calendars->importCalendar(
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * HR: Dohvaća jedan kalendar po javnom UUID-u.
     * EN: Fetches one calendar by its public UUID.
     */
    public function getCalendar(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:read',
            fn(array $user): array => $this->calendars->getCalendar(
                $this->routeString($request, 'calendarUuid'),
                $user,
            ),
        );
    }

    /**
     * HR: Djelomično mijenja jedan kalendar.
     * EN: Partially updates one calendar.
     */
    public function updateCalendar(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            function (array $user) use ($request): array {
                $uuid = $this->routeString($request, 'calendarUuid');
                $this->entityTags->assertMatches(
                    $request,
                    $this->calendars->getCalendar($uuid, $user),
                );

                return $this->calendars->updateCalendar(
                    $uuid,
                    $this->jsonBody($request),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Briše jedan kalendar kada domenski ACL to dopušta.
     * EN: Deletes one calendar when its domain ACL permits it.
     */
    public function deleteCalendar(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            function (array $user) use ($request): null {
                $uuid = $this->routeString($request, 'calendarUuid');
                $this->entityTags->assertMatches(
                    $request,
                    $this->calendars->getCalendar($uuid, $user),
                );
                $this->calendars->deleteCalendar(
                    $uuid,
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Zamjenjuje sva user/group ACL pravila kalendara.
     * EN: Replaces all user/group ACL rules of a calendar.
     */
    public function replaceAccessRules(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            function (array $user) use ($request): array {
                $payload = $this->jsonBody($request);
                $rules = $payload['rules'] ?? null;
                if (!is_array($rules) || !array_is_list($rules)) {
                    throw new CalendarApiException(
                        'calendar_validation_failed',
                        __('Polje "rules" mora biti JSON lista.'),
                        422,
                    );
                }

                return $this->calendars->replaceAccessRules(
                    $this->routeString($request, 'calendarUuid'),
                    $this->listOfObjects($rules),
                    $user,
                );
            },
        );
    }

    /**
     * HR: Vraća događaje kalendara u zadanom rasponu.
     * EN: Returns calendar events inside the requested range.
     */
    public function listEvents(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:read',
            fn(array $user): \AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage =>
                $this->paginator->paginate(
                    $request,
                    $this->calendars->listEvents(
                        $this->routeString($request, 'calendarUuid'),
                        $this->queryString($request, 'from'),
                        $this->queryString($request, 'to'),
                        $user,
                        $this->queryBool($request, 'expand_recurring', true),
                    ),
                ),
        );
    }

    /**
     * HR: Kreira događaj u kalendaru.
     * EN: Creates an event in a calendar.
     */
    public function createEvent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            fn(array $user): array => $this->calendars->createEvent(
                $this->routeString($request, 'calendarUuid'),
                $this->jsonBody($request),
                $user,
            ),
            201,
            'id',
        );
    }

    /**
     * HR: Dohvaća jedan događaj iz kalendara navedenog u URL-u.
     * EN: Fetches one event from the calendar named in the URL.
     */
    public function getEvent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:read',
            fn(array $user): array => $this->calendars->getEvent(
                $this->routeString($request, 'calendarUuid'),
                $this->routeInt($request, 'eventId'),
                $user,
            ),
        );
    }

    /**
     * HR: Djelomično mijenja jedan događaj.
     * EN: Partially updates one event.
     */
    public function updateEvent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            fn(array $user): array => $this->calendars->updateEvent(
                $this->routeString($request, 'calendarUuid'),
                $this->routeInt($request, 'eventId'),
                $this->jsonBody($request),
                $user,
            ),
        );
    }

    /**
     * HR: Briše jedan događaj iz kalendara.
     * EN: Deletes one event from a calendar.
     */
    public function deleteEvent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->execute(
            $request,
            'calendar:write',
            function (array $user) use ($request): null {
                $this->calendars->deleteEvent(
                    $this->routeString($request, 'calendarUuid'),
                    $this->routeInt($request, 'eventId'),
                    $user,
                );

                return null;
            },
            204,
        );
    }

    /**
     * HR: Preuzima čitljivi kalendar kao standardnu ICS datoteku.
     * EN: Downloads a readable calendar as a standard ICS file.
     */
    public function exportCalendar(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('calendar:read')) {
            return $this->scopeProblem($request, 'calendar:read');
        }

        try {
            $export = $this->calendars->exportCalendar(
                $this->routeString($request, 'calendarUuid'),
                $identity->user,
            );
            $content = $export['content'] ?? null;
            if (!is_string($content)) {
                throw new RuntimeException(__('ICS sadržaj nije dostupan.'));
            }

            $filename = is_scalar($export['filename'] ?? null)
                ? trim((string)$export['filename'])
                : 'calendar.ics';

            return $this->httpResponses->download(
                $content,
                $filename !== '' ? $filename : 'calendar.ics',
                'text/calendar; charset=utf-8',
                headers: ['X-Request-Id' => $this->responses->requestId($request)],
            );
        } catch (CalendarApiException $exception) {
            return $this->calendarProblem($request, $exception);
        } catch (Throwable) {
            return $this->internalProblem($request);
        }
    }

    /**
     * HR: Provjerava scope, poziva operaciju i mapira očekivane greške.
     * EN: Checks a scope, invokes an operation, and maps expected failures.
     *
     * @param callable(array<string,mixed>):mixed $operation
     */
    private function execute(
        ServerRequestInterface $request,
        string $scope,
        callable $operation,
        int $status = 200,
        string $locationField = 'id',
    ): ResponseInterface {
        $identity = $this->identity($request);
        if (!$identity->permits($scope)) {
            return $this->scopeProblem($request, $scope);
        }

        try {
            $data = $operation($identity->user);
            if ($status === 204) {
                return $this->responses->noContent($request);
            }

            $response = $this->responses->success(
                $request,
                $data,
                $status,
                links: ['self' => $this->responses->requestTarget($request)],
            );
            if ($status === 201 && is_array($data) && is_scalar($data[$locationField] ?? null)) {
                return $response->withHeader(
                    'Location',
                    $this->responses->childTarget($request, (string)$data[$locationField]),
                );
            }

            return $response;
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
        } catch (CalendarApiException $exception) {
            return $this->calendarProblem($request, $exception);
        } catch (Throwable) {
            return $this->internalProblem($request);
        }
    }

    /**
     * HR: Vraća API identitet koji je postavio autentikacijski middleware.
     * EN: Returns the API identity attached by authentication middleware.
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

        return $this->stringKeyArray($decoded);
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
     * HR: Čita pozitivni numerički route parametar.
     * EN: Reads a positive numeric route parameter.
     */
    private function routeInt(ServerRequestInterface $request, string $name): int
    {
        $value = $request->getAttribute($name);

        return is_scalar($value) && is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /**
     * HR: Čita tekstualni query parametar.
     * EN: Reads a textual query parameter.
     */
    private function queryString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Čita logički query parametar uz zadanu vrijednost.
     * EN: Reads a boolean query parameter with a default value.
     */
    private function queryBool(
        ServerRequestInterface $request,
        string $name,
        bool $default,
    ): bool {
        $value = $request->getQueryParams()[$name] ?? null;
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value)
            && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    /**
     * HR: Normalizira JSON listu objekata u string-keyed polja.
     * EN: Normalizes a JSON list of objects into string-keyed arrays.
     *
     * @param array<mixed,mixed> $values
     * @return list<array<string,mixed>>
     */
    private function listOfObjects(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new CalendarApiException(
                    'calendar_validation_failed',
                    __('Svako ACL pravilo mora biti JSON objekt.'),
                    422,
                );
            }

            $normalized[] = $this->stringKeyArray($value);
        }

        return $normalized;
    }

    /**
     * HR: Zadržava samo string ključeve ulaznog polja.
     * EN: Keeps only string keys from an input array.
     *
     * @param array<mixed,mixed> $values
     * @return array<string,mixed>
     */
    private function stringKeyArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Vraća standardnu zabranu za nedostajući scope.
     * EN: Returns the standard denial for a missing scope.
     */
    private function scopeProblem(ServerRequestInterface $request, string $scope): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            403,
            'insufficient_scope',
            __('Pristup nije dozvoljen'),
            sprintf(__('API ključ nema potreban scope "%s".'), $scope),
        );
    }

    /**
     * HR: Pretvara očekivanu Calendar pogrešku u RFC 9457 odgovor.
     * EN: Converts an expected Calendar failure into an RFC 9457 response.
     */
    private function calendarProblem(
        ServerRequestInterface $request,
        CalendarApiException $exception,
    ): ResponseInterface {
        return $this->responses->problem(
            $request,
            $exception->status,
            $exception->errorCode,
            __('Calendar operaciju nije moguće izvršiti'),
            $exception->getMessage(),
        );
    }

    /**
     * HR: Skriva detalje neočekivane greške i zadržava request ID.
     * EN: Conceals unexpected failure details while retaining a request ID.
     */
    private function internalProblem(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->problem(
            $request,
            500,
            'internal_error',
            __('Interna greška'),
            __('Zahtjev nije moguće obraditi. Obrati se administratoru uz request ID.'),
        );
    }
}
