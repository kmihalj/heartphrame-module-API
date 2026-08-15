<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * HR: Opcionalno povezuje workflow API ključeva s Notification modulom bez
 *     obavezne Composer ovisnosti API modula o kanalu obavijesti.
 *
 * EN: Optionally connects the API-key workflow to the Notification module
 *     without making the notification channel a required Composer dependency.
 */
final readonly class ApiKeyRequestNotifier
{
    /**
     * HR: Class-string ostaje literal jer je Notification namjerno opcionalna integracija.
     * EN: The class string remains literal because Notification is intentionally optional.
     */
    private const NOTIFICATION_SERVICE =
        'AaiEduHr\\HeartPhrameModuleNotification\\Service\\NotificationService';

    /**
     * HR: Prima spremnik, korisnički servis i generator sigurnih poveznica.
     *
     * EN: Receives the container, user service, and secure-link generator.
     */
    public function __construct(
        private ContainerInterface $container,
        private AuthUserService $userService,
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * HR: Obavještava sve aktivne administratore o novom zahtjevu.
     *
     * EN: Notifies every active administrator about a new request.
     *
     * @param array<string,mixed> $request
     */
    public function requestCreated(array $request): void
    {
        $userId = $this->intField($request, 'user_id');
        $user = $this->userService->findByIdIncludingInactive($userId);
        $label = $this->userLabel($user);
        $uuid = is_scalar($request['uuid'] ?? null) ? (string)$request['uuid'] : '';
        $this->notifyUsers(
            $this->userService->listActiveAdministratorIds(),
            'api.key_request.created',
            __('Novi zahtjev za API ključ'),
            sprintf(__('%s je zatražio API ključ "%s".'), $label, $this->stringField($request, 'name')),
            $this->administrationPath() . '#api-key-requests',
            $uuid,
            'api-key-request-pending:' . $uuid,
            ['request_uuid' => $uuid, 'request_user_id' => $userId],
        );
    }

    /**
     * HR: Obavještava korisnika da je zahtjev odobren i vodi ga na
     *     jednokratno sigurno preuzimanje tajne.
     *
     * EN: Notifies the user that the request was approved and links to the
     *     secure one-time secret retrieval screen.
     *
     * @param array<string,mixed> $request
     */
    public function requestApproved(array $request): void
    {
        $uuid = is_scalar($request['uuid'] ?? null) ? (string)$request['uuid'] : '';
        $this->notifyUsers(
            [$this->intField($request, 'user_id')],
            'api.key_request.approved',
            __('Zahtjev za API ključ je odobren'),
            __('Otvorite sigurnu poveznicu kako biste API key i secret prikazali samo jednom.'),
            $this->revealPath($uuid),
            $uuid,
            'api-key-request-approved:' . $uuid,
            ['request_uuid' => $uuid, 'api_key_id' => $this->intField($request, 'api_key_id')],
        );
    }

    /**
     * HR: Obavještava korisnika o odbijenom zahtjevu i uključuje administratorsku
     *     napomenu kada postoji.
     *
     * EN: Notifies the user about a rejected request and includes the
     *     administrator note when one exists.
     *
     * @param array<string,mixed> $request
     */
    public function requestRejected(array $request): void
    {
        $uuid = is_scalar($request['uuid'] ?? null) ? (string)$request['uuid'] : '';
        $note = is_scalar($request['decision_note'] ?? null) ? trim((string)$request['decision_note']) : '';
        $message = __('Vaš zahtjev za API pristup nije odobren.');
        if ($note !== '') {
            $message .= ' ' . __('Napomena administratora:') . ' ' . $note;
        }

        $this->notifyUsers(
            [$this->intField($request, 'user_id')],
            'api.key_request.rejected',
            __('Zahtjev za API ključ je odbijen'),
            $message,
            $this->profilePath(),
            $uuid,
            'api-key-request-rejected:' . $uuid,
            ['request_uuid' => $uuid],
        );
    }

    /**
     * HR: Poziva Notification servis kada je instaliran; neuspjeh opcionalnog
     *     kanala ne poništava već spremljenu poslovnu odluku.
     *
     * EN: Calls the Notification service when installed; a failure of the
     *     optional channel does not undo an already stored business decision.
     *
     * @param list<int> $userIds
     * @param array<string,mixed> $data
     */
    private function notifyUsers(
        array $userIds,
        string $notificationKey,
        string $title,
        string $message,
        string $linkUrl,
        string $sourceReference,
        string $dedupKey,
        array $data,
    ): void {
        if (!class_exists(self::NOTIFICATION_SERVICE) || !$this->container->has(self::NOTIFICATION_SERVICE)) {
            return;
        }

        try {
            $service = $this->container->get(self::NOTIFICATION_SERVICE);
            $callback = is_object($service) ? [$service, 'notifyUsers'] : null;
            if (!is_callable($callback)) {
                return;
            }

            $callback(
                $userIds,
                $notificationKey,
                $title,
                $message,
                $linkUrl,
                'api',
                $sourceReference,
                $dedupKey,
                $data,
                true,
            );
        } catch (Throwable) {
            // HR: Zahtjev ostaje vidljiv administratoru i kada opcionalni kanal zakaže.
            // EN: The request remains visible to administrators if the optional channel fails.
        }
    }

    /**
     * HR: Gradi čitljiv naziv korisnika za administratorsku poruku.
     *
     * EN: Builds a readable user label for the administrator message.
     *
     * @param array<string,mixed>|null $user
     */
    private function userLabel(?array $user): string
    {
        if (!is_array($user)) {
            return __('Nepoznat korisnik');
        }

        $display = is_scalar($user['display_name'] ?? null) ? trim((string)$user['display_name']) : '';
        $login = is_scalar($user['login_identifier'] ?? null) ? trim((string)$user['login_identifier']) : '';

        return $display !== '' ? $display : ($login !== '' ? $login : __('Nepoznat korisnik'));
    }

    /**
     * HR: Sigurno čita broj iz normaliziranog zahtjeva.
     *
     * EN: Safely reads an integer from a normalized request.
     *
     * @param array<string,mixed> $request
     */
    private function intField(array $request, string $key): int
    {
        return is_numeric($request[$key] ?? null) ? (int)$request[$key] : 0;
    }

    /**
     * HR: Sigurno čita tekst iz normaliziranog zahtjeva.
     *
     * EN: Safely reads text from a normalized request.
     *
     * @param array<string,mixed> $request
     */
    private function stringField(array $request, string $key): string
    {
        return is_scalar($request[$key] ?? null) ? (string)$request[$key] : '';
    }

    /**
     * HR: Vraća administratorsku putanju API ključeva.
     *
     * EN: Returns the API-key administration path.
     */
    private function administrationPath(): string
    {
        return $this->urlGenerator->namedRouteExists('auth.setup.api-keys')
            ? $this->urlGenerator->getPathFor('auth.setup.api-keys')
            : rtrim($this->urlGenerator->getBasePath(), '/') . '/settings/auth/api-keys';
    }

    /**
     * HR: Vraća osobni profil korisnika.
     *
     * EN: Returns the user's personal profile path.
     */
    private function profilePath(): string
    {
        return $this->urlGenerator->namedRouteExists('auth.account.profile')
            ? $this->urlGenerator->getPathFor('auth.account.profile')
            : rtrim($this->urlGenerator->getBasePath(), '/') . '/account/profile';
    }

    /**
     * HR: Gradi poveznicu za jednokratno otkrivanje odobrene tajne.
     *
     * EN: Builds the one-time approved-secret reveal link.
     */
    private function revealPath(string $uuid): string
    {
        return $this->urlGenerator->namedRouteExists('api.key-request.reveal')
            ? $this->urlGenerator->getPathFor('api.key-request.reveal', ['uuid' => $uuid])
            : rtrim($this->urlGenerator->getBasePath(), '/') . '/account/api-keys/requests/'
                . rawurlencode($uuid) . '/secret';
    }
}
