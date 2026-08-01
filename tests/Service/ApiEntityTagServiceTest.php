<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Exception\ApiPreconditionException;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use HeartPhrame\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HR: Provjerava stabilne ETag oznake i zaštitu konkurentnih izmjena.
 * EN: Verifies stable ETag validators and concurrent-write protection.
 */
#[CoversClass(ApiEntityTagService::class)]
#[UsesClass(ApiPreconditionException::class)]
final class ApiEntityTagServiceTest extends TestCase
{
    /**
     * HR: Redoslijed ključeva javnog DTO-a ne mijenja njegov ETag.
     * EN: Public DTO key order does not change its ETag.
     */
    public function testTagIsIndependentOfObjectKeyOrder(): void
    {
        $service = $this->service();

        $this->assertSame(
            $service->tag(['id' => '1', 'name' => 'Test']),
            $service->tag(['name' => 'Test', 'id' => '1']),
        );
    }

    /**
     * HR: Točan If-Match prolazi, a zastarjeli vraća 412.
     * EN: A matching If-Match succeeds while a stale one returns 412.
     */
    public function testRejectsStaleTag(): void
    {
        $service = $this->service();
        $resource = ['id' => '1', 'updated_at' => '2026-07-29T20:00:00Z'];
        $matching = (new Request('PATCH', 'https://example.test/api/v1/items/1'))
            ->withHeader('If-Match', $service->tag($resource));
        $service->assertMatches($matching, $resource);
        $this->addToAssertionCount(1);

        $stale = $matching->withHeader('If-Match', '"stale"');
        try {
            $service->assertMatches($stale, $resource);
            $this->fail('A stale ETag must be rejected.');
        } catch (ApiPreconditionException $apiPreconditionException) {
            $this->assertSame(412, $apiPreconditionException->status);
            $this->assertSame('resource_changed', $apiPreconditionException->errorCode);
        }
    }

    /**
     * HR: Kada je zaštita uključena, izmjena bez If-Match vraća 428.
     * EN: When protection is enabled, a write without If-Match returns 428.
     */
    public function testRequiresIfMatchWhenEnabled(): void
    {
        $this->expectException(ApiPreconditionException::class);
        $this->expectExceptionMessage('If-Match');

        $this->service()->assertMatches(
            new Request('DELETE', 'https://example.test/api/v1/items/1'),
            ['id' => '1'],
        );
    }

    /**
     * HR: Gradi servis sa strogo uključenom zaštitom izmjena.
     * EN: Builds the service with strict write protection enabled.
     */
    private function service(): ApiEntityTagService
    {
        return new ApiEntityTagService(
            new Config(new Helper(), ['api' => ['require_if_match' => true]]),
        );
    }
}
