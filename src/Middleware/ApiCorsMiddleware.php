<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Middleware;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use HeartPhrame\Config\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HR: Primjenjuje strogo konfigurirani CORS bez implicitnog dopuštanja izvora.
 *
 * EN: Applies explicitly configured CORS without implicitly allowing origins.
 *
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Middleware\ApiCorsMiddlewareTest
 */
final readonly class ApiCorsMiddleware implements MiddlewareInterface
{
    private const DEFAULT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const DEFAULT_HEADERS = [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'If-Match',
        'X-Request-Id',
    ];

    /**
     * HR: Prima API konfiguraciju i tvornicu sigurnih problem odgovora.
     *
     * EN: Receives API configuration and the safe problem-response factory.
     */
    public function __construct(
        private ConfigInterface $config,
        private ApiResponseFactory $responses,
    ) {
    }

    /**
     * HR: Provjerava Origin i preflight prije autentikacije te dodaje CORS zaglavlja.
     *
     * EN: Validates Origin and preflight before authentication and adds CORS headers.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled()) {
            return $handler->handle($request);
        }

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return $handler->handle($request);
        }

        if (!$this->originAllowed($origin)) {
            return $this->responses->problem(
                $request,
                403,
                'cors_origin_denied',
                __('Izvor nije dopušten'),
                __('Ovaj API ne dopušta browser zahtjeve s navedenog izvora.'),
            )->withHeader('Vary', 'Origin');
        }

        if (!$this->preflightAllowed($request)) {
            return $this->responses->problem(
                $request,
                403,
                'cors_preflight_denied',
                __('CORS zahtjev nije dopušten'),
                __('Tražena HTTP metoda ili zaglavlje nisu dopušteni CORS postavkama.'),
            )->withHeader('Vary', 'Origin');
        }

        return $this->decorate($handler->handle($request), $origin);
    }

    /**
     * HR: Provjerava je li CORS izričito uključen.
     *
     * EN: Checks whether CORS is explicitly enabled.
     */
    private function enabled(): bool
    {
        return $this->config->getAsBoolean('api.cors.enabled', false) === true;
    }

    /**
     * HR: Uspoređuje Origin samo s administratorovim dopuštenim popisom.
     *
     * EN: Compares Origin only with the administrator-defined allowlist.
     */
    private function originAllowed(string $origin): bool
    {
        $origins = $this->stringList('api.cors.allowed_origins', []);

        return in_array($origin, $origins, true)
            || (in_array('*', $origins, true) && !$this->allowCredentials());
    }

    /**
     * HR: Validira traženu preflight metodu i sva nestandardna zaglavlja.
     *
     * EN: Validates the requested preflight method and every non-simple header.
     */
    private function preflightAllowed(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'OPTIONS') {
            return true;
        }

        $method = strtoupper(trim($request->getHeaderLine('Access-Control-Request-Method')));
        if ($method === '' || !in_array($method, $this->allowedMethods(), true)) {
            return false;
        }

        $allowed = array_map(strtolower(...), $this->allowedHeaders());
        $requested = array_filter(array_map(
            static fn(string $header): string => strtolower(trim($header)),
            explode(',', $request->getHeaderLine('Access-Control-Request-Headers')),
        ));

        foreach ($requested as $header) {
            if (!in_array($header, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * HR: Dodaje standardna CORS zaglavlja uspješnom odgovoru.
     *
     * EN: Adds standard CORS headers to a successful response.
     */
    private function decorate(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowedOrigin = in_array('*', $this->stringList('api.cors.allowed_origins', []), true)
            && !$this->allowCredentials()
            ? '*'
            : $origin;

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods()))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders()))
            ->withHeader('Access-Control-Max-Age', (string)$this->maxAge())
            ->withHeader('Vary', 'Origin');

        if ($this->allowCredentials()) {
            return $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * HR: Vraća dopuštene HTTP metode u normaliziranom obliku.
     *
     * EN: Returns allowed HTTP methods in normalized form.
     *
     * @return list<string>
     */
    private function allowedMethods(): array
    {
        return array_values(array_unique(array_map(
            strtoupper(...),
            $this->stringList('api.cors.allowed_methods', self::DEFAULT_METHODS),
        )));
    }

    /**
     * HR: Vraća dopuštena request zaglavlja.
     *
     * EN: Returns allowed request headers.
     *
     * @return list<string>
     */
    private function allowedHeaders(): array
    {
        return $this->stringList('api.cors.allowed_headers', self::DEFAULT_HEADERS);
    }

    /**
     * HR: Vraća treba li browser slati credential podatke između izvora.
     *
     * EN: Returns whether browsers may send credentials across origins.
     */
    private function allowCredentials(): bool
    {
        return $this->config->getAsBoolean('api.cors.allow_credentials', false) === true;
    }

    /**
     * HR: Ograničava browser cache preflight odgovora na razuman raspon.
     *
     * EN: Bounds the browser preflight cache lifetime to a reasonable range.
     */
    private function maxAge(): int
    {
        return max(0, min(86_400, $this->config->getAsInt('api.cors.max_age', 600) ?? 600));
    }

    /**
     * HR: Sigurno čita listu nepraznih tekstualnih vrijednosti iz konfiguracije.
     *
     * EN: Safely reads a list of non-empty string values from configuration.
     *
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(string $key, array $default): array
    {
        $values = $this->config->getAsArray($key, $default) ?? $default;

        return array_values(array_filter(array_map(
            static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
            $values,
        ), static fn(string $value): bool => $value !== ''));
    }
}
