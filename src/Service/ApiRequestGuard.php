<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_intersect_key;
use function date;
use function hash;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function strtoupper;
use function time;
use function trim;
use function usleep;

/**
 * HR: Primjenjuje zajednička sigurnosna pravila na svaki autentificirani API zahtjev.
 *
 * EN: Applies shared security rules to every authenticated API request.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiRequestGuardTest
 */
final readonly class ApiRequestGuard
{
    private const DEFAULT_RATE_LIMIT = 120;

    private const DEFAULT_MAX_JSON_BYTES = 2_097_152;

    private const DEFAULT_IDEMPOTENCY_TTL = 86_400;

    private const MAX_STORED_RESPONSE_BYTES = 1_048_576;

    private const RATE_LIMIT_TRANSACTION_ATTEMPTS = 3;

    /**
     * HR: Prima prenosivu bazu, konfiguraciju i tvornice odgovora.
     *
     * EN: Receives the portable database, configuration, and response factories.
     */
    public function __construct(
        private Database $database,
        private ConfigInterface $config,
        private ApiResponseFactory $responses,
        private ResponseFactory $responseFactory,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * HR: Validira payload, ograničava promet i sigurno ponavlja write odgovor.
     *
     * EN: Validates the payload, limits traffic, and safely replays a write response.
     */
    public function handle(
        ServerRequestInterface $request,
        AuthApiIdentity $identity,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $invalid = $this->validateRequest($request);
        if ($invalid instanceof ResponseInterface) {
            return $invalid;
        }

        if (!$this->schemaReady()) {
            return $this->responses->problem(
                $request,
                503,
                'api_security_schema_missing',
                __('API zaštita nije spremna'),
                __('Pokrenite početnu migraciju API modula prije korištenja API-ja.'),
            );
        }

        try {
            $rate = $this->consumeRateLimit($identity);
        } catch (Throwable $throwable) {
            $this->logger?->error('API rate limiter is unavailable.', [
                'exception' => $throwable,
                'api_key_id' => $identity->keyId,
            ]);

            return $this->responses->problem(
                $request,
                503,
                'api_rate_limit_unavailable',
                __('API zaštita trenutačno nije dostupna'),
                __('Pokušajte ponovno kasnije.'),
            );
        }

        if (!$rate['allowed']) {
            return $this->withRateHeaders(
                $this->responses->problem(
                    $request,
                    429,
                    'rate_limit_exceeded',
                    __('Previše API zahtjeva'),
                    __('Pričekajte prije sljedećeg zahtjeva.'),
                )->withHeader('Retry-After', (string)$rate['retry_after']),
                $rate,
            );
        }

        try {
            $idempotency = $this->beginIdempotentRequest($request, $identity);
        } catch (Throwable $throwable) {
            $this->logger?->error('API idempotency storage is unavailable.', [
                'exception' => $throwable,
                'api_key_id' => $identity->keyId,
            ]);

            return $this->withRateHeaders(
                $this->responses->problem(
                    $request,
                    503,
                    'idempotency_unavailable',
                    __('Zaštita ponavljanja nije dostupna'),
                    __('Pokušajte ponovno kasnije bez promjene izvornog zahtjeva.'),
                ),
                $rate,
            );
        }

        if ($idempotency['response'] instanceof ResponseInterface) {
            return $this->withRateHeaders($idempotency['response'], $rate);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $throwable) {
            $this->forgetIdempotentRequest($idempotency['record_id']);
            throw $throwable;
        }

        $this->completeIdempotentRequest($idempotency['record_id'], $response);

        return $this->withRateHeaders($response, $rate);
    }

    /**
     * HR: Odbija prevelik JSON i write payload neprikladnog sadržajnog tipa.
     *
     * EN: Rejects oversized JSON and write payloads with an unsuitable content type.
     */
    private function validateRequest(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->isUnsafeMethod($request->getMethod())) {
            return null;
        }

        $contentLength = trim($request->getHeaderLine('Content-Length'));
        $streamSize = $request->getBody()->getSize();
        $bodySize = is_numeric($contentLength)
            ? (int)$contentLength
            : (is_int($streamSize) ? $streamSize : null);
        if ($bodySize === null) {
            $bodySize = strlen((string)$request->getBody());
        }

        $contentType = strtolower(trim($request->getHeaderLine('Content-Type')));
        $isMultipart = str_contains($contentType, 'multipart/form-data');
        $isBinary = str_contains($contentType, 'application/octet-stream');

        if ($isMultipart || $isBinary) {
            if (trim($request->getHeaderLine('Idempotency-Key')) !== '') {
                return $this->responses->problem(
                    $request,
                    422,
                    'idempotency_not_supported_for_upload',
                    __('Idempotency-Key nije podržan za upload'),
                    __('Veliki i višedijelni uploadi koriste vlastite identifikatore nastavka i prekida.'),
                );
            }

            return null;
        }

        if ($bodySize === 0) {
            return null;
        }

        if (
            !str_contains($contentType, 'application/json')
            && !str_contains($contentType, '+json')
        ) {
            return $this->responses->problem(
                $request,
                415,
                'unsupported_media_type',
                __('Nepodržan tip sadržaja'),
                __('API write zahtjevi moraju koristiti application/json ili multipart upload.'),
            );
        }

        if ($bodySize > $this->maxJsonBytes()) {
            return $this->responses->problem(
                $request,
                413,
                'json_payload_too_large',
                __('JSON zahtjev je prevelik'),
                sprintf(
                    __('Najveća dopuštena veličina JSON zahtjeva je %d bajtova.'),
                    $this->maxJsonBytes(),
                ),
            );
        }

        return null;
    }

    /**
     * HR: Atomski troši jedno mjesto u minutnom prozoru API ključa.
     *
     * EN: Atomically consumes one slot in the API key's minute window.
     *
     * @return array{allowed:bool,limit:int,remaining:int,reset:int,retry_after:int}
     */
    private function consumeRateLimit(AuthApiIdentity $identity): array
    {
        $now = time();
        $windowEpoch = $now - ($now % 60);
        $windowStart = date('Y-m-d H:i:s', $windowEpoch);
        $expiresAt = date('Y-m-d H:i:s', $windowEpoch + 120);
        $limit = $this->rateLimit();

        $count = null;
        $lastFailure = null;
        for ($attempt = 1; $attempt <= self::RATE_LIMIT_TRANSACTION_ATTEMPTS; ++$attempt) {
            try {
                $count = $this->consumeRateLimitWindow($identity, $windowStart, $expiresAt);
                break;
            } catch (Throwable $throwable) {
                $lastFailure = $throwable;
                if ($attempt < self::RATE_LIMIT_TRANSACTION_ATTEMPTS) {
                    usleep($attempt * 10_000);
                }
            }
        }

        if (!is_int($count)) {
            throw new \RuntimeException(
                'Rate-limit transaction returned an invalid count.',
                0,
                $lastFailure,
            );
        }

        $remaining = max(0, $limit - $count);
        $reset = $windowEpoch + 60;

        return [
            'allowed' => $count <= $limit,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset,
            'retry_after' => max(1, $reset - $now),
        ];
    }

    /**
     * HR: U jednom pokušaju zaključava postojeći minutni prozor ili ga kreira.
     *     Pozivatelj ponavlja cijelu transakciju kada se istodobni prvi zahtjevi
     *     sudare na prijenosnom jedinstvenom indeksu.
     *
     * EN: In one attempt, locks the existing minute window or creates it. The
     *     caller retries the whole transaction when concurrent first requests
     *     collide on the portable unique index.
     */
    private function consumeRateLimitWindow(
        AuthApiIdentity $identity,
        string $windowStart,
        string $expiresAt,
    ): int {
        $count = $this->database->transaction(
            function (Database $database) use ($identity, $windowStart, $expiresAt): int {
                $row = $this->associativeRow(
                    $database
                        ->table(ModuleApi::TABLE_RATE_LIMITS)
                        ->where('api_key_id', '=', $identity->keyId)
                        ->where('window_start', '=', $windowStart)
                        ->lockForUpdate()
                        ->first(),
                );
                $now = date('Y-m-d H:i:s');

                if (is_array($row) && $this->rowInt($row, 'id') > 0) {
                    $count = max(0, $this->rowInt($row, 'request_count')) + 1;
                    $database
                        ->table(ModuleApi::TABLE_RATE_LIMITS)
                        ->where('id', '=', $this->rowInt($row, 'id'))
                        ->update([
                            'request_count' => $count,
                            'expires_at' => $expiresAt,
                            'updated_at' => $now,
                        ]);

                    return $count;
                }

                // HR: Čišćenje je održavanje pa ga izvodimo samo pri stvaranju
                //     novog minutnog prozora, a ne pri svakom API zahtjevu.
                // EN: Cleanup is maintenance, so run it only while creating a
                //     new minute window instead of on every API request.
                $database
                    ->table(ModuleApi::TABLE_RATE_LIMITS)
                    ->where('expires_at', '<', $now)
                    ->delete();

                $database->table(ModuleApi::TABLE_RATE_LIMITS)->insert([
                    'api_key_id' => $identity->keyId,
                    'window_start' => $windowStart,
                    'request_count' => 1,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return 1;
            },
        );
        if (!is_int($count)) {
            throw new \RuntimeException('Rate-limit transaction returned an invalid count.');
        }

        return $count;
    }

    /**
     * HR: Rezervira idempotency ključ ili vraća ranije spremljeni odgovor.
     *
     * EN: Reserves an idempotency key or returns the previously stored response.
     *
     * @return array{record_id:?int,response:?ResponseInterface}
     */
    private function beginIdempotentRequest(
        ServerRequestInterface $request,
        AuthApiIdentity $identity,
    ): array {
        if (!$this->isUnsafeMethod($request->getMethod())) {
            return ['record_id' => null, 'response' => null];
        }

        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            return ['record_id' => null, 'response' => null];
        }

        if (preg_match('/^[A-Za-z0-9._:-]{8,190}$/D', $key) !== 1) {
            return [
                'record_id' => null,
                'response' => $this->responses->problem(
                    $request,
                    422,
                    'invalid_idempotency_key',
                    __('Neispravan Idempotency-Key'),
                    __('Ključ mora imati 8 do 190 slova, brojki ili znakova . _ : -.'),
                ),
            ];
        }

        $fingerprint = $this->requestFingerprint($request);
        $nowEpoch = time();
        $now = date('Y-m-d H:i:s', $nowEpoch);
        $expiresAt = date('Y-m-d H:i:s', $nowEpoch + $this->idempotencyTtl());

        $result = $this->database->transaction(
            function (Database $database) use (
                $identity,
                $key,
                $fingerprint,
                $now,
                $expiresAt,
                $request,
            ): array {
                $row = $this->associativeRow(
                    $database
                        ->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)
                        ->where('api_key_id', '=', $identity->keyId)
                        ->where('idempotency_key', '=', $key)
                        ->lockForUpdate()
                        ->first(),
                );

                if (
                    is_array($row)
                    && $this->rowString($row, 'expires_at') !== ''
                    && $this->rowString($row, 'expires_at') < $now
                ) {
                    $database
                        ->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)
                        ->where('id', '=', $this->rowInt($row, 'id'))
                        ->delete();
                    $row = null;
                }

                if (is_array($row)) {
                    if ($this->rowString($row, 'request_fingerprint') !== $fingerprint) {
                        return [
                            'record_id' => null,
                            'response' => $this->responses->problem(
                                $request,
                                409,
                                'idempotency_key_reused',
                                __('Idempotency-Key je već iskorišten'),
                                __('Isti ključ ne smije se koristiti za različite zahtjeve.'),
                            ),
                        ];
                    }

                    if (!is_numeric($row['response_status'] ?? null)) {
                        return [
                            'record_id' => null,
                            'response' => $this->responses->problem(
                                $request,
                                409,
                                'idempotency_request_in_progress',
                                __('Zahtjev s ovim ključem još traje'),
                                __('Pričekajte završetak izvornog zahtjeva i pokušajte ponovno.'),
                            ),
                        ];
                    }

                    return [
                        'record_id' => null,
                        'response' => $this->replayResponse($row),
                    ];
                }

                $database->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)->insert([
                    'api_key_id' => $identity->keyId,
                    'idempotency_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'response_status' => null,
                    'response_headers_json' => null,
                    'response_body' => null,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $stored = $this->associativeRow(
                    $database
                        ->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)
                        ->where('api_key_id', '=', $identity->keyId)
                        ->where('idempotency_key', '=', $key)
                        ->first(),
                );

                return [
                    'record_id' => is_array($stored) && $this->rowInt($stored, 'id') > 0
                        ? $this->rowInt($stored, 'id')
                        : null,
                    'response' => null,
                ];
            },
        );
        $normalized = $this->associativeRow(is_array($result) ? $result : null);
        if (!is_array($normalized)) {
            throw new \RuntimeException('Idempotency transaction returned an invalid result.');
        }

        $recordId = $normalized['record_id'] ?? null;
        $response = $normalized['response'] ?? null;

        return [
            'record_id' => is_int($recordId) ? $recordId : null,
            'response' => $response instanceof ResponseInterface ? $response : null,
        ];
    }

    /**
     * HR: Sprema završni odgovor koji idući jednaki zahtjev može ponoviti.
     *
     * EN: Stores the final response that a later identical request can replay.
     */
    private function completeIdempotentRequest(?int $recordId, ResponseInterface $response): void
    {
        if ($recordId === null) {
            return;
        }

        $body = (string)$response->getBody();
        if ($response->getStatusCode() >= 500 || strlen($body) > self::MAX_STORED_RESPONSE_BYTES) {
            $this->forgetIdempotentRequest($recordId);
            return;
        }

        $headers = array_intersect_key(
            $response->getHeaders(),
            [
                'Content-Type' => true,
                'Location' => true,
                'ETag' => true,
                'X-Request-Id' => true,
            ],
        );

        try {
            $encodedHeaders = json_encode($headers, JSON_THROW_ON_ERROR);
            $this->database
                ->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)
                ->where('id', '=', $recordId)
                ->update([
                    'response_status' => $response->getStatusCode(),
                    'response_headers_json' => $encodedHeaders,
                    'response_body' => $body,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (Throwable) {
            $this->forgetIdempotentRequest($recordId);
        }
    }

    /**
     * HR: Uklanja nedovršenu rezervaciju nakon greške ili nepamtljivog odgovora.
     *
     * EN: Removes an unfinished reservation after an error or non-storable response.
     */
    private function forgetIdempotentRequest(?int $recordId): void
    {
        if ($recordId === null) {
            return;
        }

        try {
            $this->database
                ->table(ModuleApi::TABLE_IDEMPOTENCY_KEYS)
                ->where('id', '=', $recordId)
                ->delete();
        } catch (Throwable) {
            // HR: Izvorna greška zahtjeva važnija je od čišćenja pomoćnog zapisa.
            // EN: The original request error is more important than auxiliary-record cleanup.
        }
    }

    /**
     * HR: Obnavlja status, tijelo i sigurne zaglavlja prethodnog odgovora.
     *
     * EN: Rebuilds the status, body, and safe headers of a previous response.
     *
     * @param array<string,mixed> $row
     */
    private function replayResponse(array $row): ResponseInterface
    {
        $status = max(100, min(599, $this->rowInt($row, 'response_status', 500)));
        $response = $this->responseFactory
            ->createResponse($status)
            ->withBody(
                $this->responseFactory
                    ->streamFactory()
                    ->createStream(is_string($row['response_body'] ?? null) ? $row['response_body'] : ''),
            );
        $headers = json_decode($this->rowString($row, 'response_headers_json'), true);
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (!is_string($name)) {
                    continue;
                }

                if (!is_array($values)) {
                    continue;
                }

                $response = $response->withHeader(
                    $name,
                    $this->headerValues($values),
                );
            }
        }

        return $response->withHeader('Idempotency-Replayed', 'true');
    }

    /**
     * HR: Računa stabilan otisak metode, cilja i JSON tijela zahtjeva.
     *
     * EN: Computes a stable fingerprint of the method, target, and JSON request body.
     */
    private function requestFingerprint(ServerRequestInterface $request): string
    {
        return hash(
            'sha256',
            strtoupper($request->getMethod())
                . "\n"
                . $request->getUri()->getPath()
                . "\n"
                . $request->getUri()->getQuery()
                . "\n"
                . hash('sha256', (string)$request->getBody()),
        );
    }

    /**
     * HR: Pretvara ORM redak u mapu sa string ključevima.
     *
     * EN: Converts an ORM row into a string-keyed map.
     *
     * @param mixed[]|null $row
     * @return array<string,mixed>|null
     */
    private function associativeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * HR: Čita cijeli broj iz prenosivog ORM retka bez pretpostavke PDO tipa.
     *
     * EN: Reads an integer from a portable ORM row without assuming a PDO type.
     *
     * @param array<string,mixed> $row
     */
    private function rowInt(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int)$value;
        }

        return $default;
    }

    /**
     * HR: Čita tekst iz prenosivog ORM retka.
     *
     * EN: Reads text from a portable ORM row.
     *
     * @param array<string,mixed> $row
     */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * HR: Zadržava samo tekstualne vrijednosti sigurnih HTTP zaglavlja.
     *
     * EN: Keeps only textual values from safe HTTP headers.
     *
     * @param mixed[] $values
     * @return list<string>
     */
    private function headerValues(array $values): array
    {
        $headers = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $headers[] = $value;
            }
        }

        return $headers;
    }

    /**
     * HR: Dodaje standardna zaglavlja potrošnje ograničenja.
     *
     * EN: Adds standard rate-limit consumption headers.
     *
     * @param array{allowed:bool,limit:int,remaining:int,reset:int,retry_after:int} $rate
     */
    private function withRateHeaders(ResponseInterface $response, array $rate): ResponseInterface
    {
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$rate['limit'])
            ->withHeader('X-RateLimit-Remaining', (string)$rate['remaining'])
            ->withHeader('X-RateLimit-Reset', (string)$rate['reset']);
    }

    /**
     * HR: Provjerava jesu li obje sigurnosne tablice migrirane.
     *
     * EN: Checks whether both security tables have been migrated.
     */
    private function schemaReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleApi::TABLE_RATE_LIMITS)
            && $schema->hasTable(ModuleApi::TABLE_IDEMPOTENCY_KEYS);
    }

    /**
     * HR: Prepoznaje metode koje mogu mijenjati stanje.
     *
     * EN: Recognizes methods that can mutate state.
     */
    private function isUnsafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * HR: Vraća konfigurirano minutno ograničenje u sigurnom rasponu.
     *
     * EN: Returns the configured per-minute limit within a safe range.
     */
    private function rateLimit(): int
    {
        return max(
            1,
            min(
                10_000,
                $this->config->getAsInt('api.rate_limit_per_minute', self::DEFAULT_RATE_LIMIT)
                    ?? self::DEFAULT_RATE_LIMIT,
            ),
        );
    }

    /**
     * HR: Vraća najveću dopuštenu veličinu JSON tijela.
     *
     * EN: Returns the maximum permitted JSON body size.
     */
    private function maxJsonBytes(): int
    {
        return max(
            1_024,
            min(
                16_777_216,
                $this->config->getAsInt('api.max_json_body_bytes', self::DEFAULT_MAX_JSON_BYTES)
                    ?? self::DEFAULT_MAX_JSON_BYTES,
            ),
        );
    }

    /**
     * HR: Vraća vrijeme čuvanja idempotency odgovora.
     *
     * EN: Returns the retention period for idempotency responses.
     */
    private function idempotencyTtl(): int
    {
        return max(
            300,
            min(
                604_800,
                $this->config->getAsInt('api.idempotency_ttl_seconds', self::DEFAULT_IDEMPOTENCY_TTL)
                    ?? self::DEFAULT_IDEMPOTENCY_TTL,
            ),
        );
    }
}
