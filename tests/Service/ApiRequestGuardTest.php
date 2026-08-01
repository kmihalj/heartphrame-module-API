<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestGuard;
use AaiEduHr\HeartPhrameModuleApi\Tests\Support\FixedResponseHandler;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Http\StreamFactory;
use HeartPhrame\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ApiRequestGuard::class)]
#[CoversClass(ApiResponseFactory::class)]
final class ApiRequestGuardTest extends TestCase
{
    private Database $database;

    private ResponseFactory $responseFactory;

    /**
     * HR: Priprema stvarnu prenosivu API shemu u SQLite memoriji.
     *
     * EN: Prepares the real portable API schema in in-memory SQLite.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $helper = new Helper();
        $config = new Config($helper, [
            'database' => [
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
            'api' => [
                'rate_limit_per_minute' => 10,
                'max_json_body_bytes' => 1_024,
                'idempotency_ttl_seconds' => 3_600,
            ],
        ]);
        $this->database = new Database($config, $helper);
        $migration = require dirname(__DIR__, 2) . '/resources/migrations/initial_api_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($this->database);
        $this->responseFactory = new ResponseFactory(
            new StreamFactory(),
            $this->createStub(View::class),
        );
    }

    /**
     * HR: Dokazuje da ponovljeni jednaki write zahtjev vraća spremljeni odgovor.
     *
     * EN: Proves that a repeated identical write request returns the stored response.
     */
    public function testReplaysCompletedIdempotentRequest(): void
    {
        $guard = $this->guard([
            'rate_limit_per_minute' => 10,
            'max_json_body_bytes' => 1_024,
            'idempotency_ttl_seconds' => 3_600,
        ]);
        $request = $this->jsonRequest('{"name":"first"}', 'repeat-key-0001');
        $identity = $this->identity();

        $first = $guard->handle(
            $request,
            $identity,
            new FixedResponseHandler($this->responseFactory->json(['result' => 'first'], 201)),
        );
        $second = $guard->handle(
            $request,
            $identity,
            new FixedResponseHandler($this->responseFactory->json(['result' => 'second'], 202)),
        );

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame('true', $second->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame((string)$first->getBody(), (string)$second->getBody());
    }

    /**
     * HR: Dokazuje da isti idempotency ključ ne može opisivati drugi payload.
     *
     * EN: Proves that the same idempotency key cannot describe a different payload.
     */
    public function testRejectsReusedIdempotencyKeyForDifferentPayload(): void
    {
        $guard = $this->guard(['rate_limit_per_minute' => 10]);
        $identity = $this->identity();
        $handler = new FixedResponseHandler($this->responseFactory->json(['ok' => true], 201));

        $guard->handle($this->jsonRequest('{"name":"first"}', 'repeat-key-0002'), $identity, $handler);
        $conflict = $guard->handle(
            $this->jsonRequest('{"name":"second"}', 'repeat-key-0002'),
            $identity,
            $handler,
        );

        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertStringContainsString('idempotency_key_reused', (string)$conflict->getBody());
    }

    /**
     * HR: Dokazuje minutno ograničenje i standardna zaglavlja preostalog prometa.
     *
     * EN: Proves the per-minute limit and standard remaining-traffic headers.
     */
    public function testRejectsRequestsAboveConfiguredRateLimit(): void
    {
        $guard = $this->guard(['rate_limit_per_minute' => 2]);
        $identity = $this->identity();
        $handler = new FixedResponseHandler($this->responseFactory->json(['ok' => true]));
        $request = new Request('GET', 'https://example.test/api/v1');

        $first = $guard->handle($request, $identity, $handler);
        $second = $guard->handle($request, $identity, $handler);
        $third = $guard->handle($request, $identity, $handler);

        $this->assertSame('1', $first->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame('0', $second->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame(429, $third->getStatusCode());
        $this->assertNotSame('', $third->getHeaderLine('Retry-After'));
    }

    /**
     * HR: Dokazuje odbijanje prevelikog JSON-a i pogrešnog sadržajnog tipa.
     *
     * EN: Proves rejection of oversized JSON and an incorrect content type.
     */
    public function testRejectsUnsafePayloadBeforeControllerExecution(): void
    {
        $guard = $this->guard(['max_json_body_bytes' => 1_024]);
        $handler = new FixedResponseHandler($this->responseFactory->json(['ok' => true]));
        $oversized = new Request(
            'POST',
            'https://example.test/api/v1/users',
            ['Content-Type' => 'application/json'],
            '{"content":"' . str_repeat('x', 1_100) . '"}',
        );
        $wrongType = new Request(
            'PATCH',
            'https://example.test/api/v1/users/1',
            ['Content-Type' => 'text/plain'],
            'name=value',
        );

        $this->assertSame(413, $guard->handle($oversized, $this->identity(), $handler)->getStatusCode());
        $this->assertSame(415, $guard->handle($wrongType, $this->identity(), $handler)->getStatusCode());
    }

    /**
     * HR: Sastavlja guard s testnom konfiguracijom i stvarnom bazom.
     *
     * EN: Builds the guard with test configuration and the real database.
     *
     * @param array<string,mixed> $apiConfig
     */
    private function guard(array $apiConfig): ApiRequestGuard
    {
        $helper = new Helper();
        $config = new Config($helper, ['api' => $apiConfig]);

        return new ApiRequestGuard(
            $this->database,
            $config,
            new ApiResponseFactory($this->responseFactory),
            $this->responseFactory,
        );
    }

    /**
     * HR: Gradi JSON zahtjev s jedinstvenim ključem ponavljanja.
     *
     * EN: Builds a JSON request with an idempotency key.
     */
    private function jsonRequest(string $body, string $key): ServerRequestInterface
    {
        return new Request(
            'POST',
            'https://example.test/api/v1/groups',
            [
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $key,
            ],
            $body,
        );
    }

    /**
     * HR: Gradi aktivni testni API identitet.
     *
     * EN: Builds an active test API identity.
     */
    private function identity(): AuthApiIdentity
    {
        return new AuthApiIdentity(
            7,
            'test-key',
            ['id' => 42, 'is_admin' => true],
            ['*'],
        );
    }
}
