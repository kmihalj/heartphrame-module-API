<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

/**
 * HR: Neizmjenjivi rezultat jednog HTTP pokušaja webhook isporuke.
 * EN: Immutable result of one webhook HTTP delivery attempt.
 */
final readonly class WebhookDeliveryResult
{
    /**
     * HR: Sprema status, ograničeno tijelo odgovora i opcionalnu transportnu pogrešku.
     * EN: Stores the status, bounded response body, and optional transport error.
     */
    public function __construct(
        public ?int $status,
        public string $body,
        public ?string $error = null,
    ) {
    }

    /**
     * HR: Vraća je li odredište prihvatilo događaj statusom 2xx.
     * EN: Returns whether the destination accepted the event with a 2xx status.
     */
    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
