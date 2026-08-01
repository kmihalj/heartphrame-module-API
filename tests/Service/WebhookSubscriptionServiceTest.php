<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Exception\WebhookApiException;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookConfig;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookSubscriptionService;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTargetPolicy;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Encryption\Encryption;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookSubscriptionService::class)]
#[CoversClass(WebhookTargetPolicy::class)]
#[UsesClass(WebhookApiException::class)]
#[UsesClass(WebhookConfig::class)]
final class WebhookSubscriptionServiceTest extends TestCase
{
    /**
     * HR: Dokazuje da se tajna prikazuje samo pri kreiranju, u bazi je
     *     šifrirana, a wildcard pretplata stvara trajnu outbox isporuku.
     *
     * EN: Proves the secret is shown only on creation, encrypted at rest,
     *     and a wildcard subscription creates a durable outbox delivery.
     */
    public function testCreatesEncryptedSubscriptionAndQueuesMatchingEvent(): void
    {
        [$database, $service] = $this->services(true, true);
        $identity = $this->identity();
        $created = $service->create($identity, [
            'name' => 'Content events',
            'target_url' => 'http://127.0.0.1/webhooks/content',
            'events' => ['pages.*', 'calendar_events.created'],
        ]);

        $this->assertStringStartsWith('whsec_', $created['secret']);
        $this->assertArrayNotHasKey('secret', $created['subscription']);
        $this->assertArrayNotHasKey('encrypted_secret', $created['subscription']);
        $this->assertSame(['pages.*', 'calendar_events.created'], $created['subscription']['events']);

        $stored = $database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)->first();
        $this->assertIsArray($stored);
        $this->assertNotSame($created['secret'], $stored['encrypted_secret'] ?? null);
        $this->assertSame($created['secret'], $service->decryptSecret($stored));

        $this->assertSame(1, $service->publish('pages.updated', ['page_id' => 42]));
        $this->assertSame(0, $service->publish('users.updated', ['user_id' => 7]));
        $this->assertSame(
            ['pending' => 1, 'sending' => 0, 'delivered' => 0, 'failed' => 0],
            $service->deliveryStatus(),
        );

        $deliveries = $service->listDeliveries(
            $identity,
            (string)$created['subscription']['uuid'],
        );
        $this->assertCount(1, $deliveries);
        $this->assertSame('pages.updated', $deliveries[0]['event']);
    }

    /**
     * HR: Dokazuje da zadana sigurnosna politika odbija HTTP i privatne adrese.
     * EN: Proves the default security policy rejects HTTP and private addresses.
     */
    public function testRejectsInsecureAndPrivateWebhookTargets(): void
    {
        [, $service] = $this->services(false, false);

        try {
            $service->create($this->identity(), [
                'name' => 'Insecure target',
                'target_url' => 'http://example.com/webhook',
                'events' => ['pages.updated'],
            ]);
            self::fail('An insecure webhook target should have been rejected.');
        } catch (WebhookApiException $webhookApiException) {
            $this->assertSame('invalid_webhook_target', $webhookApiException->errorCode);
        }

        $this->expectException(WebhookApiException::class);
        $service->create($this->identity(), [
            'name' => 'Private target',
            'target_url' => 'https://127.0.0.1/webhook',
            'events' => ['pages.updated'],
        ]);
    }

    /**
     * HR: Wildcard je dopušten samo kao cijeli selektor ili na kraju namespacea.
     * EN: A wildcard is allowed only as the whole selector or at the namespace end.
     */
    public function testRejectsWildcardInsideEventSelector(): void
    {
        [, $service] = $this->services(true, true);

        $this->expectException(WebhookApiException::class);
        $service->create($this->identity(), [
            'name' => 'Invalid wildcard',
            'target_url' => 'https://example.com/webhook',
            'events' => ['pages.*.published'],
        ]);
    }

    /**
     * HR: Gradi stvarni servis nad prijenosnom SQLite shemom.
     * EN: Builds the real service over the portable SQLite schema.
     *
     * @return array{Database,WebhookSubscriptionService}
     */
    private function services(
        bool $allowInsecureHttp,
        bool $allowPrivateNetworks,
    ): array {
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
                'webhooks' => [
                    'enabled' => true,
                    'allow_insecure_http' => $allowInsecureHttp,
                    'allow_private_networks' => $allowPrivateNetworks,
                ],
            ],
        ]);
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__, 2) . '/resources/migrations/initial_api_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        $encryption = new Encryption();
        $encryption->setKey($encryption->generateKey());

        $webhookConfig = new WebhookConfig($config);

        return [
            $database,
            new WebhookSubscriptionService(
                $database,
                $encryption,
                new WebhookTargetPolicy($webhookConfig),
                $webhookConfig,
            ),
        ];
    }

    /**
     * HR: Vraća administratorski API identitet s webhook scopeovima.
     * EN: Returns an administrator API identity with webhook scopes.
     */
    private function identity(): AuthApiIdentity
    {
        return new AuthApiIdentity(
            17,
            'webhook-test-key',
            ['id' => 3, 'is_admin' => true],
            ['webhooks:read', 'webhooks:manage'],
        );
    }
}
