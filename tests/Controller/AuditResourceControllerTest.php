<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Controller;

use AaiEduHr\HeartPhrameModuleApi\Controller\AuditResourceController;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiCollectionPage;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAuditLogService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Http\StreamFactory;
use HeartPhrame\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuditResourceController::class)]
#[UsesClass(ApiCollectionPage::class)]
#[UsesClass(ApiResponseFactory::class)]
#[UsesClass(ApiCursorPaginator::class)]
final class AuditResourceControllerTest extends TestCase
{
    /**
     * HR: Neadministratorski ključ odbija čak i kada nosi audit scope.
     * EN: Rejects a non-administrator key even when it carries the audit scope.
     */
    public function testAuditScopeWithoutAdministratorOwnerIsRejected(): void
    {
        $audit = new AuthAuditLogService($this->database());
        $controller = new AuditResourceController(
            $this->responses(),
            $audit,
            new ApiCursorPaginator(),
        );
        $request = (new Request('GET', 'https://example.test/api/v1/audit'))
            ->withAttribute(
                ModuleApi::IDENTITY_ATTRIBUTE,
                new AuthApiIdentity(5, 'limited', ['id' => 5, 'is_admin' => false], ['audit:read']),
            );

        $response = $controller->listEvents($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('audit_access_denied', (string)$response->getBody());
    }

    /**
     * HR: Administratorski ključ sa scopeom dobiva filtrirani rezultat servisa.
     * EN: Allows an administrator key with the scope to receive the filtered service result.
     */
    public function testAdministratorWithAuditScopeCanListEvents(): void
    {
        $audit = new AuthAuditLogService($this->database());
        $audit->logEvent('api_user_created', 7, 9, ['login' => 'example']);

        $controller = new AuditResourceController(
            $this->responses(),
            $audit,
            new ApiCursorPaginator(),
        );
        $request = (new Request(
            'GET',
            'https://example.test/api/v1/audit?page=2&page_size=25&event_key=api_user_created'
                . '&actor_user_id=7&target_user_id=9',
        ))->withQueryParams([
            'page' => '2',
            'page_size' => '25',
            'event_key' => 'api_user_created',
            'actor_user_id' => '7',
            'target_user_id' => '9',
        ])->withAttribute(
            ModuleApi::IDENTITY_ATTRIBUTE,
            new AuthApiIdentity(1, 'admin', ['id' => 1, 'is_admin' => true], ['audit:read']),
        );

        $response = $controller->listEvents($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"total":1', (string)$response->getBody());
    }

    /**
     * HR: Gradi stvarnu tvornicu JSON odgovora bez pokretanja aplikacije.
     * EN: Builds the real JSON response factory without booting the application.
     */
    private function responses(): ApiResponseFactory
    {
        $responseFactory = new ResponseFactory(
            new StreamFactory(),
            $this->createStub(View::class),
        );

        return new ApiResponseFactory($responseFactory);
    }

    /**
     * HR: Priprema prijenosnu Auth shemu za stvarni audit servis.
     * EN: Prepares the portable Auth schema for the real audit service.
     */
    private function database(): Database
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
        $migration = require dirname(__DIR__, 2)
            . '/vendor/aaieduhr/heartphrame-module-auth/resources/migrations/initial_auth_schema.php';
        $this->assertInstanceOf(ReversibleMigrationInterface::class, $migration);
        $migration->up($database);

        return $database;
    }
}
