<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use HeartPhrame\Config\ConfigInterface;

use function is_scalar;
use function max;
use function min;
use function strtolower;
use function trim;

/**
 * HR: Pretvara opću aplikacijsku konfiguraciju u ograničene i sigurne webhook
 *     postavke koje jednako koriste HTTP sloj i CLI worker.
 * EN: Converts general application configuration into bounded, safe webhook
 *     settings shared by the HTTP layer and CLI worker.
 */
final readonly class WebhookConfig
{
    /**
     * HR: Prima konfiguraciju host aplikacije.
     * EN: Receives the host application configuration.
     */
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * HR: Vraća je li spremanje i isporuka webhookova uključena.
     * EN: Returns whether webhook storage and delivery are enabled.
     */
    public function enabled(): bool
    {
        return $this->config->getAsBoolean('api.webhooks.enabled', false) === true;
    }

    /**
     * HR: Vraća najveći dopušteni broj pokušaja jedne isporuke.
     * EN: Returns the maximum number of attempts for one delivery.
     */
    public function maxAttempts(): int
    {
        return min(50, max(1, $this->config->getAsInt('api.webhooks.max_attempts', 8) ?? 8));
    }

    /**
     * HR: Vraća početnu odgodu prije ponovnog pokušaja u sekundama.
     * EN: Returns the base retry delay in seconds.
     */
    public function baseRetrySeconds(): int
    {
        return min(
            86_400,
            max(1, $this->config->getAsInt('api.webhooks.base_retry_seconds', 30) ?? 30),
        );
    }

    /**
     * HR: Vraća najveću odgodu između pokušaja u sekundama.
     * EN: Returns the maximum delay between attempts in seconds.
     */
    public function maxRetrySeconds(): int
    {
        return min(
            604_800,
            max(
                $this->baseRetrySeconds(),
                $this->config->getAsInt('api.webhooks.max_retry_seconds', 3600) ?? 3600,
            ),
        );
    }

    /**
     * HR: Vraća vremensko ograničenje jednog HTTP pokušaja.
     * EN: Returns the timeout for one HTTP attempt.
     */
    public function timeoutSeconds(): int
    {
        return min(120, max(1, $this->config->getAsInt('api.webhooks.timeout_seconds', 15) ?? 15));
    }

    /**
     * HR: Vraća smije li razvojna instalacija koristiti nešifrirani HTTP.
     * EN: Returns whether a development installation may use unencrypted HTTP.
     */
    public function allowsInsecureHttp(): bool
    {
        return $this->config->getAsBoolean('api.webhooks.allow_insecure_http', false) === true;
    }

    /**
     * HR: Vraća smiju li ciljevi završiti na privatnim ili rezerviranim mrežama.
     * EN: Returns whether targets may resolve to private or reserved networks.
     */
    public function allowsPrivateNetworks(): bool
    {
        return $this->config->getAsBoolean('api.webhooks.allow_private_networks', false) === true;
    }

    /**
     * HR: Vraća opcionalni popis izričito dopuštenih hostova.
     * EN: Returns the optional list of explicitly allowed hosts.
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $configured = $this->config->getAsArray('api.webhooks.allowed_hosts', []) ?? [];
        $hosts = [];
        foreach ($configured as $host) {
            if (!is_scalar($host)) {
                continue;
            }

            $host = strtolower(trim((string)$host));
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
