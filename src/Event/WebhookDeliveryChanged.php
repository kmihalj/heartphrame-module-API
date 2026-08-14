<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Event;

/** HR: Opisuje rezultat webhook isporuke bez URL-a, potpisa i tijela. EN: Describes a webhook delivery outcome without URL, signature, or body. */
final readonly class WebhookDeliveryChanged
{
    /** HR: Stvara sigurni opis jednog ishoda webhook isporuke. EN: Creates a safe description of one webhook delivery outcome. */
    public function __construct(
        public string $deliveryUuid,
        public string $eventUuid,
        public string $eventName,
        public string $outcome,
        public int $attempts,
        public ?int $responseStatus = null,
    ) {
    }
}
