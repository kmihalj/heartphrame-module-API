<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiWebhookPublisher;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookConfig;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookSubscriptionService;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTargetPolicy;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Encryption\Encryption;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiWebhookPublisher::class)]
#[UsesClass(WebhookConfig::class)]
#[UsesClass(WebhookSubscriptionService::class)]
#[UsesClass(WebhookTargetPolicy::class)]
final class ApiWebhookPublisherTest extends TestCase
{
    /**
     * HR: Objavljuje samo završenu poslovnu mutaciju, bez tijela zahtjeva i
     *     bez dupliciranja idempotentno replayanih ili webhook upravljačkih poziva.
     *
     * EN: Publishes only a completed business mutation, without request bodies
     *     and without duplicating idempotency replays or webhook administration calls.
     */
    public function testPublishesOnlyEligibleMutation(): void
    {
        [$database, $subscriptions] = $this->services();
        $identity = new AuthApiIdentity(
            17,
            'publisher-key',
            ['id' => 3, 'is_admin' => true],
            ['webhooks:manage'],
        );
        $subscriptions->create($identity, [
            'name' => 'All events',
            'target_url' => 'http://127.0.0.1/webhook',
            'events' => ['*'],
        ]);
        $publisher = new ApiWebhookPublisher($subscriptions);
        $request = (new Request('POST', 'https://example.test/api/v1/pages'))
            ->withAttribute(ModuleApi::IDENTITY_ATTRIBUTE, $identity)
            ->withAttribute(ModuleApi::REQUEST_ID_ATTRIBUTE, 'request-42');
        $response = new Response(
            201,
            ['Location' => '/api/v1/pages/42', 'ETag' => '"page-42"'],
        );

        $publisher->publish($request, $response);
        $publisher->publish($request, $response->withHeader('Idempotency-Replayed', 'true'));
        $publisher->publish(
            new Request('POST', 'https://example.test/api/v1/webhooks'),
            $response,
        );

        $rows = $database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)->get();
        $this->assertCount(1, $rows);
        $this->assertIsArray($rows[0] ?? null);
        $this->assertSame('pages.created', $rows[0]['event_name'] ?? null);
        $payload = json_decode((string)($rows[0]['payload_json'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $payload['data']['actor']['user_id'] ?? null);
        $this->assertSame('/api/v1/pages', $payload['data']['request']['path'] ?? null);
        $this->assertArrayNotHasKey('body', $payload['data']['request'] ?? []);
    }

    /**
     * HR: Gradi izdavača i pretplate nad stvarnom prijenosnom shemom.
     * EN: Builds the publisher and subscriptions over the real portable schema.
     *
     * @return array{Database,WebhookSubscriptionService}
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
        $subscriptions = new WebhookSubscriptionService(
            $database,
            $encryption,
            new WebhookTargetPolicy($webhookConfig),
            $webhookConfig,
        );

        return [$database, $subscriptions];
    }
}
