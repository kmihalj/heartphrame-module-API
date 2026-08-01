<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookConfig;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookDeliveryResult;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookOutboxWorker;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookSubscriptionService;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTargetPolicy;
use AaiEduHr\HeartPhrameModuleApi\Tests\Support\FakeWebhookTransport;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Encryption\Encryption;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function hash_hmac;

#[CoversClass(WebhookOutboxWorker::class)]
#[UsesClass(WebhookConfig::class)]
#[UsesClass(WebhookDeliveryResult::class)]
#[UsesClass(WebhookSubscriptionService::class)]
#[UsesClass(WebhookTargetPolicy::class)]
final class WebhookOutboxWorkerTest extends TestCase
{
    /**
     * HR: Dokazuje uspješnu isporuku i HMAC potpis nad točno poslanim JSON-om.
     * EN: Proves successful delivery and the HMAC signature over the exact JSON sent.
     */
    public function testDeliversSignedPayload(): void
    {
        [$database, $subscriptions, $config, $policy] = $this->services();
        $created = $this->subscription($subscriptions);
        $subscriptions->publish('pages.published', ['page_id' => 81]);
        $transport = new FakeWebhookTransport(new WebhookDeliveryResult(204, ''));
        $worker = new WebhookOutboxWorker(
            $database,
            $subscriptions,
            $policy,
            $transport,
            $config,
        );

        $this->assertSame(
            ['processed' => 1, 'delivered' => 1, 'retried' => 0, 'failed' => 0],
            $worker->workBatch(),
        );
        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $timestamp = $request['headers']['X-HeartPhrame-Webhook-Timestamp'];
        $expected = hash_hmac('sha256', $timestamp . '.' . $request['payload'], $created['secret']);
        $this->assertSame(
            'v1=' . $expected,
            $request['headers']['X-HeartPhrame-Webhook-Signature'],
        );
        $this->assertSame('pages.published', $request['headers']['X-HeartPhrame-Webhook-Event']);
        $this->assertSame(
            ['pending' => 0, 'sending' => 0, 'delivered' => 1, 'failed' => 0],
            $worker->status(),
        );
    }

    /**
     * HR: Dokazuje da se privremena HTTP pogreška vraća u red uz odgodu.
     * EN: Proves a temporary HTTP failure is requeued with a delay.
     */
    public function testRequeuesTemporaryFailure(): void
    {
        [$database, $subscriptions, $config, $policy] = $this->services();
        $this->subscription($subscriptions);
        $subscriptions->publish('pages.updated', ['page_id' => 91]);
        $worker = new WebhookOutboxWorker(
            $database,
            $subscriptions,
            $policy,
            new FakeWebhookTransport(new WebhookDeliveryResult(503, 'Unavailable')),
            $config,
        );

        $this->assertSame(
            ['processed' => 1, 'delivered' => 0, 'retried' => 1, 'failed' => 0],
            $worker->workBatch(),
        );
        $delivery = $database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)->first();
        $this->assertIsArray($delivery);
        $this->assertSame('pending', $delivery['status'] ?? null);
        $this->assertSame(1, $delivery['attempts'] ?? null);
        $this->assertSame(503, $delivery['response_status'] ?? null);
        $this->assertNotNull($delivery['available_at'] ?? null);
    }

    /**
     * HR: Gradi stvarne webhook servise nad prijenosnom SQLite shemom.
     * EN: Builds real webhook services over the portable SQLite schema.
     *
     * @return array{
     *     Database,
     *     WebhookSubscriptionService,
     *     WebhookConfig,
     *     WebhookTargetPolicy
     * }
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
            'api' => [
                'webhooks' => [
                    'enabled' => true,
                    'max_attempts' => 3,
                    'base_retry_seconds' => 1,
                    'max_retry_seconds' => 5,
                    'timeout_seconds' => 2,
                    'allow_insecure_http' => true,
                    'allow_private_networks' => true,
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
        $policy = new WebhookTargetPolicy($webhookConfig);

        return [
            $database,
            new WebhookSubscriptionService($database, $encryption, $policy, $webhookConfig),
            $webhookConfig,
            $policy,
        ];
    }

    /**
     * HR: Kreira pretplatu za događaje stranica i vraća tajnu testa.
     * EN: Creates a page-event subscription and returns its test secret.
     *
     * @return array{subscription:array<string,mixed>,secret:string}
     */
    private function subscription(WebhookSubscriptionService $subscriptions): array
    {
        return $subscriptions->create(
            new AuthApiIdentity(
                17,
                'webhook-worker-key',
                ['id' => 3, 'is_admin' => true],
                ['webhooks:read', 'webhooks:manage'],
            ),
            [
                'name' => 'Worker target',
                'target_url' => 'http://127.0.0.1/webhook',
                'events' => ['pages.*'],
            ],
        );
    }
}
