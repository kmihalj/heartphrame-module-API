<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use Throwable;

use function array_merge;
use function fclose;
use function fopen;
use function implode;
use function is_resource;
use function preg_match;
use function stream_context_create;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;

/**
 * HR: Šalje potpisane webhook zahtjeve ugrađenim PHP stream transportom uz
 *     obaveznu TLS provjeru, bez vanjskih paketa.
 * EN: Sends signed webhook requests through PHP's built-in stream transport
 *     with mandatory TLS verification and no external packages.
 */
final class StreamWebhookTransport implements WebhookTransportInterface
{
    private const MAX_RESPONSE_BYTES = 65_536;

    /**
     * HR: Izvršava jedan ograničeni POST pokušaj i vraća siguran rezultat.
     * EN: Executes one bounded POST attempt and returns a safe result.
     *
     * @param array<string,string> $headers
     */
    public function send(
        string $url,
        string $payload,
        array $headers,
        int $timeoutSeconds,
    ): WebhookDeliveryResult {
        $headerLines = [];
        foreach (
            array_merge(
                [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Content-Length' => (string)strlen($payload),
                    'User-Agent' => 'HeartPhrame-Webhook/1.0',
                ],
                $headers,
            ) as $name => $value
        ) {
            $headerLines[] = $name . ': ' . trim($value);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $payload,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $http_response_header = [];
        try {
            $stream = @fopen($url, 'rb', false, $context);
            if (!is_resource($stream)) {
                return new WebhookDeliveryResult(null, '', __('Webhook cilj nije dostupan.'));
            }

            $body = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            fclose($stream);
            $body = is_string($body) ? $body : '';
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                $body = substr($body, 0, self::MAX_RESPONSE_BYTES);
            }

            return new WebhookDeliveryResult(
                $this->responseStatus($http_response_header),
                $body,
            );
        } catch (Throwable $throwable) {
            return new WebhookDeliveryResult(null, '', $throwable->getMessage());
        }
    }

    /**
     * HR: Čita završni HTTP status iz stream zaglavlja.
     * EN: Reads the final HTTP status from stream response headers.
     *
     * @param list<string> $headers
     */
    private function responseStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/iD', $header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }

        return $status;
    }
}
