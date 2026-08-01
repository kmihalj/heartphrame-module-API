<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Exception\WebhookApiException;

use function array_column;
use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function dns_get_record;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function strtolower;
use function trim;

/**
 * HR: Štiti webhook transport od nevaljanih URL-ova i SSRF pristupa lokalnim,
 *     privatnim ili rezerviranim mrežama.
 * EN: Protects webhook transport from invalid URLs and SSRF access to local,
 *     private, or reserved networks.
 */
final readonly class WebhookTargetPolicy
{
    /**
     * HR: Prima ograničenu webhook konfiguraciju.
     * EN: Receives bounded webhook configuration.
     */
    public function __construct(private WebhookConfig $config)
    {
    }

    /**
     * HR: Validira i normalizira ciljni URL pri spremanju i prije svake isporuke.
     * EN: Validates and normalizes a target URL when saving and before every delivery.
     */
    public function assertAllowed(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw $this->invalid(__('Webhook URL nije valjan.'));
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(trim(is_string($parts['host'] ?? null) ? $parts['host'] : ''));
        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw $this->invalid(__('Webhook URL mora sadržavati valjani HTTPS host.'));
        }

        if ($scheme !== 'https' && !$this->config->allowsInsecureHttp()) {
            throw $this->invalid(__('Webhook cilj mora koristiti HTTPS.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw $this->invalid(__('Webhook URL ne smije sadržavati korisničke podatke.'));
        }

        $allowedHosts = $this->config->allowedHosts();
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            throw $this->invalid(__('Webhook host nije na popisu dopuštenih hostova.'));
        }

        if (!$this->config->allowsPrivateNetworks()) {
            foreach ($this->resolveAddresses($host) as $address) {
                if (!$this->isPublicAddress($address)) {
                    throw $this->invalid(
                        __('Webhook cilj ne smije koristiti privatnu ili rezerviranu mrežnu adresu.'),
                    );
                }
            }
        }

        return $url;
    }

    /**
     * HR: Razrješava IPv4 i IPv6 adrese hosta kako DNS ime ne bi zaobišlo SSRF zaštitu.
     * EN: Resolves IPv4 and IPv6 addresses so a DNS name cannot bypass SSRF protection.
     *
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            throw $this->invalid(__('Webhook host nije moguće razriješiti.'));
        }

        $ipv4 = array_filter(array_column($records, 'ip'), is_string(...));
        $ipv6 = array_filter(array_column($records, 'ipv6'), is_string(...));
        $addresses = array_values(array_unique(array_merge($ipv4, $ipv6)));
        if ($addresses === []) {
            throw $this->invalid(__('Webhook host nema dostupnu mrežnu adresu.'));
        }

        return $addresses;
    }

    /**
     * HR: Provjerava da je adresa javno usmjeriva i nije rezervirana.
     * EN: Checks that an address is publicly routable and not reserved.
     */
    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * HR: Gradi stabilnu 422 pogrešku za neispravan ili zabranjen cilj.
     * EN: Builds a stable 422 error for an invalid or forbidden target.
     */
    private function invalid(string $message): WebhookApiException
    {
        return new WebhookApiException(422, 'invalid_webhook_target', $message);
    }
}
