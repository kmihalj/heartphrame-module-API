<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Controller;

use AaiEduHr\HeartPhrameModuleApi\Controller\MeController;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Http\StreamFactory;
use HeartPhrame\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava da endpoint aktualnog identiteta izlaže samo javne podatke.
 *
 * EN: Verifies that the current-identity endpoint exposes only public data.
 */
#[CoversClass(MeController::class)]
#[UsesClass(ApiResponseFactory::class)]
final class MeControllerTest extends TestCase
{
    /**
     * HR: Ne dopušta da interni hash lozinke završi u `/me` odgovoru.
     *
     * EN: Prevents the internal password hash from appearing in the `/me` response.
     */
    public function testReturnsSafeIdentityWithoutPasswordHash(): void
    {
        $controller = new MeController($this->responses());
        $request = (new Request('GET', 'https://example.test/api/v1/me'))
            ->withAttribute(
                ModuleApi::IDENTITY_ATTRIBUTE,
                new AuthApiIdentity(
                    7,
                    'public-key',
                    [
                        'id' => 42,
                        'login_identifier' => 'editor',
                        'display_name' => 'Editor Example',
                        'email' => 'editor@example.test',
                        'password_hash' => 'must-not-leak',
                        'group_ids' => [2, '3', 0],
                        'group_keys' => ['writers', 'reviewers'],
                        'is_admin' => false,
                        'is_active' => true,
                        'auth_source' => 'local',
                    ],
                    ['page:read'],
                ),
            );

        $response = $controller->show($request);
        $payload = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(42, $payload['data']['user']['id'] ?? null);
        $this->assertSame([2, 3], $payload['data']['user']['group_ids'] ?? null);
        $this->assertStringNotContainsString('password', (string)$response->getBody());
        $this->assertStringNotContainsString('must-not-leak', (string)$response->getBody());
    }

    /**
     * HR: Gradi stvarnu JSON tvornicu za kontrolerski test.
     *
     * EN: Builds the real JSON factory for the controller test.
     */
    private function responses(): ApiResponseFactory
    {
        return new ApiResponseFactory(new ResponseFactory(
            new StreamFactory(),
            $this->createStub(View::class),
        ));
    }
}
