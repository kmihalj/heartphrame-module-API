<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestService;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiModuleViewRenderer;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use HeartPhrame\Alert\Alert;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\CodeBook\AlertLevelEnum;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * HR: Pruža administratorsko web sučelje za životni ciklus API ključeva.
 *
 * EN: Provides the administration web interface for the API-key lifecycle.
 */
final readonly class ApiKeyController
{
    private const SESSION_KEY_ONE_TIME_TOKEN = 'auth_api_key_one_time_token';

    private const MENU_RENDERER_CLASS = \AaiEduHr\HeartPhrameModuleMenu\Service\MenuRenderer::class;

    /**
     * HR: Inicijalizira administratorski ekran API ključeva.
     *
     * EN: Initializes the API-key administration screen.
     */
    public function __construct(
        private ResponseFactory $responseFactory,
        private UrlGenerator $urlGenerator,
        private ApiModuleViewRenderer $moduleViewRenderer,
        private AuthApiKeyService $apiKeyService,
        private ApiKeyRequestService $requestService,
        private ApiScopeRegistry $scopeRegistry,
        private AuthUserService $userService,
        private AuthnHandlerInterface $authnHandler,
        private SessionInterface $session,
        private AlertHandler $alertHandler,
        private ContainerInterface $container,
    ) {
    }

    /**
     * HR: Prikazuje administratorski popis, dinamičke scopeove i formu ključa.
     *
     * EN: Renders the administration list, dynamic scopes, and key form.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $oneTimeToken = $this->session->get(self::SESSION_KEY_ONE_TIME_TOKEN);
        $this->session->remove(self::SESSION_KEY_ONE_TIME_TOKEN);
        $query = $request->getQueryParams();
        $language = is_scalar($query['lang'] ?? null) && strtolower((string)$query['lang']) === 'en'
            ? 'en'
            : 'hr';

        return $this->moduleViewRenderer->render('api/keys', [
            'title' => __('API ključevi'),
            'schema_ready' => $this->apiKeyService->isSchemaReady(),
            'settings_menu_html' => $this->renderSharedSettingsMenu(),
            'keys' => $this->apiKeyService->listKeys(),
            'pending_requests' => $this->requestService->listByStatus(ApiKeyRequestService::STATUS_PENDING),
            'scope_groups' => $this->scopeRegistry->grouped(),
            'one_time_token' => is_array($oneTimeToken) ? $oneTimeToken : null,
            'language' => $language,
            'csrf_token_name' => $this->session->getCsrfTokenName(),
            'csrf_token' => $this->session->getOrGenerateCsrfToken(),
        ]);
    }

    /**
     * HR: Izdaje ključ nakon provjere scopeova dostupnih instaliranim modulima.
     *
     * EN: Issues a key after validating scopes exposed by installed modules.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $scopes = $this->scopeRegistry->normalize(is_array($data['scopes'] ?? null) ? $data['scopes'] : []);
            $result = $this->apiKeyService->issue(
                $this->intValue($data, 'user_id'),
                $this->stringValue($data, 'name'),
                $this->stringValue($data, 'description'),
                $scopes,
                $this->allowedIpValues($this->stringValue($data, 'allowed_ips')),
                $this->nullableStringValue($data, 'expires_at'),
                $this->currentUserId(),
            );
            $this->session->set(self::SESSION_KEY_ONE_TIME_TOKEN, [
                'token' => $result->plainTextToken,
                'name' => $this->scalarString($result->key['name'] ?? null),
            ]);
            $this->alertHandler->add(new Alert(
                __('API ključ je kreiran. Tajna je prikazana samo jednom.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToIndex();
    }

    /**
     * HR: Rotira tajnu odabranog ključa.
     *
     * EN: Rotates the selected key secret.
     */
    public function rotate(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $result = $this->apiKeyService->rotate($this->intValue($data, 'key_id'), $this->currentUserId());
            $this->session->set(self::SESSION_KEY_ONE_TIME_TOKEN, [
                'token' => $result->plainTextToken,
                'name' => $this->scalarString($result->key['name'] ?? null),
            ]);
            $this->alertHandler->add(new Alert(
                __('API ključ je rotiran. Prethodna tajna više ne vrijedi.'),
                AlertLevelEnum::Success,
            ));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToIndex();
    }

    /**
     * HR: Opoziva odabrani ključ bez brisanja audit zapisa.
     *
     * EN: Revokes the selected key without deleting its audit record.
     */
    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $this->apiKeyService->revoke($this->intValue($data, 'key_id'), $this->currentUserId());
            $this->alertHandler->add(new Alert(__('API ključ je opozvan.'), AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToIndex();
    }

    /**
     * HR: Trajno briše odabrani ključ, dok zaseban audit događaj ostaje sačuvan.
     *
     * EN: Permanently deletes the selected key while retaining a separate audit event.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->text(__('Pristup nije dozvoljen'), 403);
        }

        $data = $this->parsedBody($request);
        try {
            $this->apiKeyService->delete($this->intValue($data, 'key_id'), $this->currentUserId());
            $this->alertHandler->add(new Alert(__('API ključ je obrisan.'), AlertLevelEnum::Success));
        } catch (Throwable $throwable) {
            $this->alertHandler->add(new Alert($throwable->getMessage(), AlertLevelEnum::Danger));
        }

        return $this->redirectToIndex();
    }

    /**
     * HR: Vraća ograničene rezultate aktivnih korisnika za pretraživi combobox
     *     bez učitavanja cijele korisničke tablice u HTML.
     *
     * EN: Returns bounded active-user results for the searchable combobox
     *     without loading the full user table into HTML.
     */
    public function searchUsers(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUserIsAdmin()) {
            return $this->responseFactory->json(['ok' => false, 'items' => []], 403);
        }

        $query = $request->getQueryParams();
        $search = is_scalar($query['q'] ?? null) ? trim((string)$query['q']) : '';
        $users = $this->userService->searchActiveUsers($search, 25);
        $items = [];
        foreach ($users as $user) {
            $id = is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
            if ($id <= 0) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'label' => $this->userLabel($user),
                'login' => $this->scalarString($user['login_identifier'] ?? null),
            ];
        }

        return $this->responseFactory->json(['ok' => true, 'items' => $items]);
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
     * HR: Čita trimani string iz forme.
     *
     * EN: Reads a trimmed string from the form.
     *
     * @param array<string,mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string)$data[$key]) : '';
    }

    /**
     * HR: Sigurno pretvara skalarnu servisnu vrijednost u tekst.
     *
     * EN: Safely converts a scalar service value to text.
     */
    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * HR: Gradi čitljivu oznaku korisnika za udaljeni combobox.
     *
     * EN: Builds a readable user label for the remote combobox.
     *
     * @param array<string,mixed> $user
     */
    private function userLabel(array $user): string
    {
        $displayName = trim($this->scalarString($user['display_name'] ?? null));
        $login = trim($this->scalarString($user['login_identifier'] ?? null));

        return $displayName !== ''
            ? $displayName . ($login !== '' ? ' (' . $login . ')' : '')
            : $login;
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
     * HR: Rastavlja IP allow-listu po retcima i uobičajenim separatorima.
     *
     * EN: Splits the IP allow-list by lines and common separators.
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
     * HR: Vraća ID aktualnog administratora.
     *
     * EN: Returns the current administrator ID.
     */
    private function currentUserId(): int
    {
        $user = $this->authnHandler->user();

        return is_array($user) && is_numeric($user['id'] ?? null) ? (int)$user['id'] : 0;
    }

    /**
     * HR: Provjerava administratorski status aktualnog session korisnika.
     *
     * EN: Checks the current session user's administrator status.
     */
    private function currentUserIsAdmin(): bool
    {
        $user = $this->authnHandler->user();

        return is_array($user) && (bool)($user['is_admin'] ?? false);
    }

    /**
     * HR: Preusmjerava na ekran API ključeva.
     *
     * EN: Redirects to the API-key screen.
     */
    private function redirectToIndex(): ResponseInterface
    {
        $path = $this->urlGenerator->namedRouteExists('auth.setup.api-keys')
            ? $this->urlGenerator->getPathFor('auth.setup.api-keys')
            : '/settings/auth/api-keys';

        return $this->responseFactory->redirect($path);
    }

    /**
     * HR: Renderira zajednički settings meni kada je Menu modul dostupan.
     *
     * EN: Renders the shared settings menu when the Menu module is available.
     */
    private function renderSharedSettingsMenu(): string
    {
        if (!class_exists(self::MENU_RENDERER_CLASS)) {
            return '';
        }

        try {
            $renderer = $this->container->get(self::MENU_RENDERER_CLASS);
            if (!is_object($renderer) || !method_exists($renderer, 'renderSettingsMenu')) {
                return '';
            }

            $html = $renderer->renderSettingsMenu('');

            return is_string($html) ? $html : '';
        } catch (Throwable) {
            return '';
        }
    }
}
