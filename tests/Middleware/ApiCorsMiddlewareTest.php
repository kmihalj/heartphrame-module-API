<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Middleware;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Tests\Support\FixedResponseHandler;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Http\StreamFactory;
use HeartPhrame\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava sigurne zadane CORS postavke i eksplicitni allowlist.
 *
 * EN: Verifies secure CORS defaults and the explicit allowlist.
 */
#[CoversClass(ApiCorsMiddleware::class)]
#[UsesClass(ApiResponseFactory::class)]
final class ApiCorsMiddlewareTest extends TestCase
{
    /**
     * HR: Isključeni CORS ne mijenja odgovor ni kada browser pošalje Origin.
     *
     * EN: Disabled CORS leaves a response unchanged even when the browser sends Origin.
     */
    public function testCorsIsDisabledByDefault(): void
    {
        $response = $this->middleware([])
            ->process(
                new Request('GET', 'https://api.example.test/api/v1', ['Origin' => 'https://app.test']),
                new FixedResponseHandler($this->responseFactory()->json(['ok' => true])),
            );

        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * HR: Dopušteni Origin dobiva zaglavlja, a nepoznati Origin jasan 403 problem.
     *
     * EN: An allowed Origin receives headers while an unknown Origin gets a clear 403 problem.
     */
    public function testAllowsConfiguredOriginAndRejectsUnknownOrigin(): void
    {
        $middleware = $this->middleware([
            'enabled' => true,
            'allowed_origins' => ['https://app.example.test'],
        ]);
        $handler = new FixedResponseHandler($this->responseFactory()->json(['ok' => true]));

        $allowed = $middleware->process(
            new Request('GET', 'https://api.example.test/api/v1', ['Origin' => 'https://app.example.test']),
            $handler,
        );
        $denied = $middleware->process(
            new Request('GET', 'https://api.example.test/api/v1', ['Origin' => 'https://other.test']),
            $handler,
        );

        $this->assertSame('https://app.example.test', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertStringContainsString('cors_origin_denied', (string)$denied->getBody());
    }

    /**
     * HR: Preflight odbija metodu koja nije na dopuštenom popisu.
     *
     * EN: Preflight rejects a method absent from the allowlist.
     */
    public function testRejectsUnsupportedPreflightMethod(): void
    {
        $middleware = $this->middleware([
            'enabled' => true,
            'allowed_origins' => ['https://app.example.test'],
            'allowed_methods' => ['GET', 'OPTIONS'],
        ]);
        $request = new Request(
            'OPTIONS',
            'https://api.example.test/api/v1',
            [
                'Origin' => 'https://app.example.test',
                'Access-Control-Request-Method' => 'DELETE',
            ],
        );

        $response = $middleware->process(
            $request,
            new FixedResponseHandler($this->responseFactory()->createResponse(204)),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('cors_preflight_denied', (string)$response->getBody());
    }

    /**
     * HR: Gradi middleware s kontroliranom API konfiguracijom.
     *
     * EN: Builds middleware with controlled API configuration.
     *
     * @param array<string,mixed> $cors
     */
    private function middleware(array $cors): ApiCorsMiddleware
    {
        $factory = $this->responseFactory();

        return new ApiCorsMiddleware(
            new Config(new Helper(), ['api' => ['cors' => $cors]]),
            new ApiResponseFactory($factory),
        );
    }

    /**
     * HR: Vraća stvarnu framework HTTP tvornicu.
     *
     * EN: Returns the real framework HTTP response factory.
     */
    private function responseFactory(): ResponseFactory
    {
        return new ResponseFactory(
            new StreamFactory(),
            $this->createStub(View::class),
        );
    }
}
