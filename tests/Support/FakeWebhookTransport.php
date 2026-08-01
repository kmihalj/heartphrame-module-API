<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Support;

use AaiEduHr\HeartPhrameModuleApi\Service\WebhookDeliveryResult;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTransportInterface;

/**
 * HR: Bilježi webhook zahtjeve i vraća unaprijed zadan rezultat bez mreže.
 * EN: Records webhook requests and returns a predefined result without networking.
 */
final class FakeWebhookTransport implements WebhookTransportInterface
{
    /**
     * @var list<array{
     *     url:string,
     *     payload:string,
     *     headers:array<string,string>,
     *     timeout:int
     * }>
     */
    public array $requests = [];

    /**
     * HR: Prima rezultat koji treba vratiti za svaki pokušaj.
     * EN: Receives the result returned for every delivery attempt.
     */
    public function __construct(private readonly WebhookDeliveryResult $result)
    {
    }

    /**
     * HR: Sprema sve argumente pokušaja i vraća kontrolirani rezultat.
     * EN: Stores all attempt arguments and returns the controlled result.
     *
     * @param array<string,string> $headers
     */
    public function send(
        string $url,
        string $payload,
        array $headers,
        int $timeoutSeconds,
    ): WebhookDeliveryResult {
        $this->requests[] = [
            'url' => $url,
            'payload' => $payload,
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
        ];

        return $this->result;
    }
}
