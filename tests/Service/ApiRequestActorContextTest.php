<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Service;

use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestActorContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** HR: Provjerava request-local API identitet i koordinaciju audit zapisa. EN: Verifies request-local API identity and audit-record coordination. */
#[CoversClass(ApiRequestActorContext::class)]
final class ApiRequestActorContextTest extends TestCase
{
    /** HR: Kontekst ne smije zadržati identitet ni oznaku nakon čišćenja. EN: The context must retain neither identity nor marker after clearing. */
    public function testItStoresAndClearsSafeRequestState(): void
    {
        $context = new ApiRequestActorContext();

        $this->assertNull($context->current());
        $this->assertFalse($context->hasBusinessEventRecorded());

        $context->useApiActor(42, ' API User ', ' request-1 ');
        $this->assertSame(42, $context->current()['id'] ?? null);
        $this->assertSame('API User', $context->current()['label'] ?? null);
        $this->assertSame('request-1', $context->current()['request_id'] ?? null);

        $context->markBusinessEventRecorded();
        $this->assertTrue($context->hasBusinessEventRecorded());

        $context->clear();
        $this->assertNull($context->current());
        $this->assertFalse($context->hasBusinessEventRecorded());
    }
}
