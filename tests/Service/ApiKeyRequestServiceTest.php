<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Encryption\Encryption;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ApiKeyRequestService::class)]
final class ApiKeyRequestServiceTest extends TestCase
{
    /**
     * HR: Dokazuje cijeli tijek od korisničkog zahtjeva do odobrenja,
     *     autentikacije i samo jednog prikaza pune tajne.
     *
     * EN: Proves the complete flow from user request through approval,
     *     authentication, and a single display of the full secret.
     */
    public function testApprovesRequestAndRevealsSecretOnlyOnce(): void
    {
        [$database, $service, $apiKeyService] = $this->services();
        $request = $service->request(
            2,
            'Mobile client',
            'Integration test request.',
            ['page:read'],
            ['192.0.2.0/24'],
            date('Y-m-d\TH:i', time() + 3600),
        );

        $this->assertSame(ApiKeyRequestService::STATUS_PENDING, $request['status']);
        $this->assertCount(1, $service->listByStatus(ApiKeyRequestService::STATUS_PENDING));
        $this->assertSame('api-user', $service->listByStatus(
            ApiKeyRequestService::STATUS_PENDING,
        )[0]['user']['login_identifier']);

        try {
            $service->request(2, 'Duplicate', '', ['page:read'], [], null);
            self::fail('A second pending request should have been rejected.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame(
                'Već imate zahtjev za API ključ koji čeka odluku administratora.',
                $runtimeException->getMessage(),
            );
        }

        $approved = $service->approve((int)$request['id'], 1);
        $this->assertSame(ApiKeyRequestService::STATUS_APPROVED, $approved['status']);
        $this->assertTrue((bool)$approved['token_available']);
        $this->assertNotNull($approved['api_key_id']);

        $revealed = $service->revealToken(2, (string)$request['uuid']);
        $this->assertStringStartsWith('hfp_live_', $revealed['token']);
        $this->assertInstanceOf(
            AuthApiIdentity::class,
            $apiKeyService->authenticate($revealed['token'], '192.0.2.10'),
        );
        $this->assertNotInstanceOf(
            AuthApiIdentity::class,
            $apiKeyService->authenticate($revealed['token'], '198.51.100.10'),
        );

        $stored = $database->table('api_key_requests')->where('id', '=', (int)$request['id'])->first();
        $this->assertIsArray($stored);
        $this->assertNull($stored['encrypted_token'] ?? null);
        $this->assertNotNull($stored['token_revealed_at'] ?? null);

        $this->expectException(RuntimeException::class);
        $service->revealToken(2, (string)$request['uuid']);
    }

    /**
     * HR: Dokazuje da odbijeni zahtjev ne kreira ključ te čuva napomenu za korisnika.
     *
     * EN: Proves that a rejected request creates no key and retains the user note.
     */
    public function testRejectsRequestWithoutIssuingKey(): void
    {
        [, $service, $apiKeyService] = $this->services();
        $request = $service->request(2, 'Rejected client', '', ['calendar:read'], [], null);
        $rejected = $service->reject((int)$request['id'], 1, 'Not required for this account.');

        $this->assertSame(ApiKeyRequestService::STATUS_REJECTED, $rejected['status']);
        $this->assertSame('Not required for this account.', $rejected['decision_note']);
        $this->assertFalse((bool)$rejected['token_available']);
        $this->assertSame([], $apiKeyService->listKeys());
    }

    /**
     * HR: Kreira stvarne Auth i API servise nad prijenosnom SQLite shemom.
     *
     * EN: Creates real Auth and API services over the portable SQLite schema.
     *
     * @return array{Database,ApiKeyRequestService,AuthApiKeyService}
     */
    private function services(): array
    {
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
        ]);
        $database = new Database($config, $helper);
        $authMigration = require dirname(__DIR__, 2)
            . '/vendor/aaieduhr/heartphrame-module-auth/resources/migrations/initial_auth_schema.php';
        $apiMigration = require dirname(__DIR__, 2) . '/resources/migrations/initial_api_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $authMigration);
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $apiMigration);
        $authMigration->up($database);
        $apiMigration->up($database);
        $this->insertUser($database, 1, 'api-admin', true);
        $this->insertUser($database, 2, 'api-user', false);

        $userService = new AuthUserService($database);
        $apiKeyService = new AuthApiKeyService($database, $userService);
        $encryption = new Encryption();
        $encryption->setKey($encryption->generateKey());

        return [
            $database,
            new ApiKeyRequestService($database, $apiKeyService, $userService, $encryption),
            $apiKeyService,
        ];
    }

    /**
     * HR: Dodaje minimalnog aktivnog korisnika u stvarnu Auth tablicu.
     *
     * EN: Inserts a minimal active user into the real Auth table.
     */
    private function insertUser(Database $database, int $id, string $login, bool $administrator): void
    {
        $now = date('Y-m-d H:i:s');
        $database->table(ModuleAuth::TABLE_AUTH_USERS)->insert([
            'id' => $id,
            'login_identifier' => $login,
            'password_hash' => null,
            'is_admin' => $administrator ? 1 : 0,
            'is_active' => 1,
            'auth_source' => 'local',
            'last_login_at' => null,
            'must_change_password' => 0,
            'force_local_password_reset_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
