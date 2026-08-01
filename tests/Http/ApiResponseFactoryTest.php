<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Http;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use HeartPhrame\Http\Request;
use HeartPhrame\Http\ResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiResponseFactory::class)]
final class ApiResponseFactoryTest extends TestCase
{
    /**
     * HR: Dokazuje da poveznice zadržavaju baznu putanju i query aplikacije.
     *
     * EN: Proves that links preserve the application's base path and query.
     */
    public function testBuildsRequestTargetBelowApplicationBasePath(): void
    {
        $factory = new ApiResponseFactory($this->createStub(ResponseFactory::class));
        $request = new Request('GET', 'https://example.test/hfc/api/v1/users?page%5Blimit%5D=25');

        $this->assertSame('/hfc/api/v1/users?page%5Blimit%5D=25', $factory->requestTarget($request));
    }

    /**
     * HR: Dokazuje da Location novog resursa ostaje unutar instalacijske putanje.
     *
     * EN: Proves that a new resource Location remains within the installation path.
     */
    public function testBuildsChildTargetBelowApplicationBasePath(): void
    {
        $factory = new ApiResponseFactory($this->createStub(ResponseFactory::class));
        $request = new Request('POST', 'https://example.test/hfc/api/v1/groups');

        $this->assertSame('/hfc/api/v1/groups/42', $factory->childTarget($request, 42));
    }
}
