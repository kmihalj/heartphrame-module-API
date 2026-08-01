<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

/**
 * HR: Definira zamjenjivi HTTP transport kako worker ne bi ovisio o cURL-u ili
 *     vanjskoj biblioteci.
 * EN: Defines a replaceable HTTP transport so the worker does not depend on
 *     cURL or an external library.
 */
interface WebhookTransportInterface
{
    /**
     * HR: Šalje sirovi JSON na prethodno validiran URL s potpisnim zaglavljima.
     * EN: Sends raw JSON to a previously validated URL with signature headers.
     *
     * @param array<string,string> $headers
     */
    public function send(
        string $url,
        string $payload,
        array $headers,
        int $timeoutSeconds,
    ): WebhookDeliveryResult;
}
