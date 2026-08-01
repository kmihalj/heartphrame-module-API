<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestNotifier;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestService;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiModuleViewRenderer;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * HR: Povezuje korisnički zahtjev, administratorsku odluku i jednokratno
 *     otkrivanje API secreta s web sučeljem.
 *
 * EN: Connects the user request, administrator decision, and one-time API
 *     secret reveal to the web interface.
 */
final readonly class ApiKeyRequestController
{
    /**
     * HR: Prima web, autentikacijske i poslovne servise workflowa zahtjeva.
     *
     * EN: Receives the web, authentication, and request-workflow business services.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private UrlGenerator $urlGenerator,
        private ApiModuleViewRenderer $moduleViewRenderer,
        private ApiKeyRequestService $requestService,
        private ApiScopeRegistry $scopeRegistry,
        private ApiKeyRequestNotifier $notifier,
        private AuthnHandlerInterface $authnHandler,
        private AlertHandler $alertHandler,
    ) {
    }

    /**
     * HR: Sprema zahtjev prijavljenog korisnika i obavještava administratore.
     *
     * EN: Stores the authenticated user's request and notifies administrators.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $created = $this->requestService->request(
                $userId,
                $this->stringValue($data, 'name'),
                $this->stringValue($data, 'description'),
                $this->scopeRegistry->normalize(
                    is_array($data['scopes'] ?? null) ? $data['scopes'] : [],
                ),
                $this->allowedIpValues($this->stringValue($data, 'allowed_ips')),
                $this->nullableStringValue($data, 'expires_at'),
            );
            $this->notifier->requestCreated($created);
            $this->alertHandler->add(new Alert(
                __('Zahtjev za API ključ poslan je administratoru.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToProfile();
    }

    /**
     * HR: Administrator odobrava zahtjev, a vlasniku se šalje sigurna poveznica
     *     za jednokratno preuzimanje ključa.
     *
     * EN: An administrator approves a request and the owner receives a secure
     *     one-time key retrieval link.
     */
    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        try {
            $approved = $this->requestService->approve(
                $this->intValue($this->parsedBody($request), 'request_id'),
                $this->currentUserId(),
            );
            $this->notifier->requestApproved($approved);
            $this->alertHandler->add(new Alert(
                __('Zahtjev je odobren, a korisnik je obaviješten.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToAdministration();
    }

    /**
     * HR: Administrator odbija zahtjev i korisniku šalje obavijest.
     *
     * EN: An administrator rejects a request and notifies the user.
     */
    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $rejected = $this->requestService->reject(
                $this->intValue($data, 'request_id'),
                $this->currentUserId(),
                $this->stringValue($data, 'decision_note'),
            );
            $this->notifier->requestRejected($rejected);
            $this->alertHandler->add(new Alert(
                __('Zahtjev je odbijen, a korisnik je obaviješten.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToAdministration();
    }

    /**
     * HR: Prikazuje vlasniku puni token samo jednom, bez spremanja u session,
     *     access log ili trajnu obavijest.
     *
     * EN: Shows the full token to its owner only once, without storing it in
     *     the session, access log, or persistent notification.
     *
     */
    public function reveal(string $uuid): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        try {
            $revealed = $this->requestService->revealToken(
                $userId,
                $uuid,
            );

            return $this->moduleViewRenderer->render('api/key_secret', [
                'title' => __('Odobreni API ključ'),
                'token' => $revealed['token'],
                'request' => $revealed['request'],
                'profile_path' => $this->profilePath(),
            ]);
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));

            return $this->redirectToProfile();
        }
    }

    /**
     * HR: Vraća normalizirano POST tijelo.
     *
     * EN: Returns a normalized POST body.
     *
     * @return array<string,mixed>
     */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }

        $normalized = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Čita trimanu string vrijednost iz forme.
     *
     * EN: Reads a trimmed string value from the form.
     *
     * @param array<string,mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string)$data[$key]) : '';
    }

    /**
     * HR: Čita opcionalni string iz forme.
     *
     * EN: Reads an optional string from the form.
     *
     * @param array<string,mixed> $data
     */
    private function nullableStringValue(array $data, string $key): ?string
    {
        $value = $this->stringValue($data, $key);

        return $value !== '' ? $value : null;
    }

    /**
     * HR: Čita cijeli broj iz forme.
     *
     * EN: Reads an integer from the form.
     *
     * @param array<string,mixed> $data
     */
    private function intValue(array $data, string $key): int
    {
        return is_numeric($data[$key] ?? null) ? (int)$data[$key] : 0;
    }

    /**
     * HR: Rastavlja IP allow-listu po uobičajenim separatorima.
     *
     * EN: Splits the IP allow-list by common separators.
     *
     * @return list<string>
     */
    private function allowedIpValues(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value);

        return is_array($parts)
            ? array_values(array_filter($parts, static fn(string $part): bool => trim($part) !== ''))
            : [];
    }

    /**
     * HR: Vraća ID prijavljenog korisnika.
     *
     * EN: Returns the authenticated user ID.
     */
    private function currentUserId(): int
    {
        $user = $this->authnHandler->user();

        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /**
     * HR: Provjerava je li prijavljeni korisnik administrator.
     *
     * EN: Checks whether the authenticated user is an administrator.
     */
    private function currentUserIsAdmin(): bool
    {
        $user = $this->authnHandler->user();

        return is_array($user) && (bool)($user['is_admin'] ?? false);
    }

    /**
     * HR: Preusmjerava na administratorski ekran ključeva.
     *
     * EN: Redirects to the key administration screen.
     */
    private function redirectToAdministration(): ResponseInterface
    {
        return $this->responseFactory->redirect($this->administrationPath());
    }

    /**
     * HR: Preusmjerava na osobni profil.
     *
     * EN: Redirects to the personal profile.
     */
    private function redirectToProfile(): ResponseInterface
    {
        return $this->responseFactory->redirect($this->profilePath());
    }

    /**
     * HR: Vraća administratorsku putanju ključeva.
     *
     * EN: Returns the key administration path.
     */
    private function administrationPath(): string
    {
        return $this->urlGenerator->namedRouteExists('auth.setup.api-keys')
            ? $this->urlGenerator->getPathFor('auth.setup.api-keys')
            : rtrim($this->urlGenerator->getBasePath(), '/') . '/settings/auth/api-keys';
    }

    /**
     * HR: Vraća putanju osobnog profila.
     *
     * EN: Returns the personal profile path.
     */
    private function profilePath(): string
    {
        return $this->urlGenerator->namedRouteExists('auth.account.profile')
            ? $this->urlGenerator->getPathFor('auth.account.profile')
            : rtrim($this->urlGenerator->getBasePath(), '/') . '/account/profile';
    }
}
