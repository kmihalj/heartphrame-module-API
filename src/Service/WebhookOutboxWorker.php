<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use Throwable;

use function date;
use function hash_hmac;
use function is_array;
use function is_numeric;
use function is_scalar;
use function max;
use function min;
use function sprintf;
use function time;
use function trim;

/**
 * HR: Pouzdano preuzima webhook outbox retke, potpisuje točan JSON payload i
 *     neuspjele isporuke vraća u red uz eksponencijalnu odgodu.
 * EN: Reliably claims webhook outbox rows, signs the exact JSON payload, and
 *     requeues failed deliveries with exponential backoff.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\WebhookOutboxWorkerTest
 */
final readonly class WebhookOutboxWorker
{
    private const STALE_LOCK_SECONDS = 900;

    /**
     * HR: Prima ORM, pretplate, politiku cilja, transport i ograničenu konfiguraciju.
     * EN: Receives the ORM, subscriptions, target policy, transport, and bounded configuration.
     */
    public function __construct(
        private Database $database,
        private WebhookSubscriptionService $subscriptions,
        private WebhookTargetPolicy $targetPolicy,
        private WebhookTransportInterface $transport,
        private WebhookConfig $config,
    ) {
    }

    /**
     * HR: Obrađuje ograničen broj dostupnih isporuka i vraća sažetak batcha.
     * EN: Processes a bounded number of available deliveries and returns a batch summary.
     *
     * @return array{processed:int,delivered:int,retried:int,failed:int}
     */
    public function workBatch(int $limit = 20): array
    {
        $this->assertAvailable();
        $limit = min(100, max(1, $limit));
        $this->recoverStaleLocks();
        $summary = ['processed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];

        for ($index = 0; $index < $limit; ++$index) {
            $delivery = $this->claimNext();
            if ($delivery === null) {
                break;
            }

            ++$summary['processed'];
            $outcome = $this->deliver($delivery);
            ++$summary[$outcome];
        }

        return $summary;
    }

    /**
     * HR: Vraća broj redaka po statusu za CLI nadzor.
     * EN: Returns row counts by status for CLI monitoring.
     *
     * @return array<string,int>
     */
    public function status(): array
    {
        return $this->subscriptions->deliveryStatus();
    }

    /**
     * HR: Transakcijski zaključava i preuzima sljedeću dostupnu isporuku.
     * EN: Transactionally locks and claims the next available delivery.
     *
     * @return array<string,mixed>|null
     */
    private function claimNext(): ?array
    {
        $now = date('Y-m-d H:i:s');
        $claimed = $this->database->transaction(function (Database $database) use ($now): ?array {
            $candidate = $database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
                ->where('status', '=', 'pending')
                ->where('available_at', '<=', $now)
                ->orderBy('available_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->lockForUpdate()
                ->first();
            $candidate = $this->row($candidate);
            if ($candidate === null) {
                return null;
            }

            $attempts = $this->intValue($candidate['attempts'] ?? 0) + 1;
            $database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
                ->where('id', '=', $this->intValue($candidate['id'] ?? 0))
                ->where('status', '=', 'pending')
                ->update([
                    'status' => 'sending',
                    'attempts' => $attempts,
                    'locked_at' => $now,
                    'updated_at' => $now,
                ]);
            $candidate['status'] = 'sending';
            $candidate['attempts'] = $attempts;
            $candidate['locked_at'] = $now;

            return $candidate;
        });

        return $this->row($claimed);
    }

    /**
     * HR: Šalje preuzetu isporuku te zapisuje delivered, retry ili failed rezultat.
     * EN: Sends a claimed delivery and records a delivered, retry, or failed result.
     *
     * @param array<string,mixed> $delivery
     * @return 'delivered'|'retried'|'failed'
     */
    private function deliver(array $delivery): string
    {
        $subscription = $this->subscription(
            $this->intValue($delivery['subscription_id'] ?? 0),
        );
        if ($subscription === null || $this->intValue($subscription['is_active'] ?? 0) !== 1) {
            $this->markFailed($delivery, null, '', __('Webhook pretplata nije aktivna.'));

            return 'failed';
        }

        try {
            $targetUrl = $this->targetPolicy->assertAllowed(
                $this->stringValue($subscription['target_url'] ?? ''),
            );
            $payload = $this->stringValue($delivery['payload_json'] ?? '');
            $timestamp = (string)time();
            $secret = $this->subscriptions->decryptSecret($subscription);
            $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
            $result = $this->transport->send(
                $targetUrl,
                $payload,
                [
                    'X-HeartPhrame-Webhook-Id' => $this->stringValue($delivery['event_uuid'] ?? ''),
                    'X-HeartPhrame-Webhook-Event' => $this->stringValue($delivery['event_name'] ?? ''),
                    'X-HeartPhrame-Webhook-Timestamp' => $timestamp,
                    'X-HeartPhrame-Webhook-Signature' => 'v1=' . $signature,
                ],
                $this->config->timeoutSeconds(),
            );

            if ($result->succeeded()) {
                $this->markDelivered($delivery, $result);

                return 'delivered';
            }

            if ($result->status === 410) {
                $this->deactivateSubscription($subscription);
            }

            if ($this->shouldRetry($delivery, $result)) {
                $this->markRetry($delivery, $result);

                return 'retried';
            }

            $this->markFailed(
                $delivery,
                $result->status,
                $result->body,
                $result->error ?? $this->httpFailure($result->status),
            );

            return 'failed';
        } catch (Throwable $throwable) {
            if ($this->hasAttemptsLeft($delivery)) {
                $this->markRetry(
                    $delivery,
                    new WebhookDeliveryResult(null, '', $throwable->getMessage()),
                );

                return 'retried';
            }

            $this->markFailed($delivery, null, '', $throwable->getMessage());

            return 'failed';
        }
    }

    /**
     * HR: Zapisuje uspješnu isporuku i uklanja podatke prethodne pogreške.
     * EN: Records a successful delivery and clears previous error data.
     *
     * @param array<string,mixed> $delivery
     */
    private function markDelivered(
        array $delivery,
        WebhookDeliveryResult $result,
    ): void {
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('id', '=', $this->intValue($delivery['id'] ?? 0))
            ->update([
                'status' => 'delivered',
                'locked_at' => null,
                'delivered_at' => $now,
                'response_status' => $result->status,
                'response_body' => $this->nullableText($result->body, 65_536),
                'last_error' => null,
                'updated_at' => $now,
            ]);
    }

    /**
     * HR: Vraća isporuku u red s ograničenom eksponencijalnom odgodom.
     * EN: Requeues a delivery with bounded exponential backoff.
     *
     * @param array<string,mixed> $delivery
     */
    private function markRetry(
        array $delivery,
        WebhookDeliveryResult $result,
    ): void {
        $attempts = $this->intValue($delivery['attempts'] ?? 1);
        $delay = min(
            $this->config->maxRetrySeconds(),
            $this->config->baseRetrySeconds() * (2 ** min(20, max(0, $attempts - 1))),
        );
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('id', '=', $this->intValue($delivery['id'] ?? 0))
            ->update([
                'status' => 'pending',
                'available_at' => date('Y-m-d H:i:s', time() + $delay),
                'locked_at' => null,
                'response_status' => $result->status,
                'response_body' => $this->nullableText($result->body, 65_536),
                'last_error' => $this->nullableText(
                    $result->error ?? $this->httpFailure($result->status),
                    2000,
                ),
                'updated_at' => $now,
            ]);
    }

    /**
     * HR: Trajno označava isporuku neuspjelom nakon terminalne pogreške.
     * EN: Permanently marks a delivery failed after a terminal error.
     *
     * @param array<string,mixed> $delivery
     */
    private function markFailed(
        array $delivery,
        ?int $status,
        string $body,
        string $error,
    ): void {
        $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('id', '=', $this->intValue($delivery['id'] ?? 0))
            ->update([
                'status' => 'failed',
                'locked_at' => null,
                'response_status' => $status,
                'response_body' => $this->nullableText($body, 65_536),
                'last_error' => $this->nullableText($error, 2000),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * HR: Oporavlja retke koje je prekinuti worker ostavio u stanju sending.
     * EN: Recovers rows left in sending state by an interrupted worker.
     */
    private function recoverStaleLocks(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS);
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('status', '=', 'sending')
            ->where('locked_at', '<=', $threshold)
            ->update([
                'status' => 'pending',
                'locked_at' => null,
                'available_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * HR: Odlučuje može li se privremena transportna ili HTTP pogreška ponoviti.
     * EN: Decides whether a temporary transport or HTTP failure can be retried.
     *
     * @param array<string,mixed> $delivery
     */
    private function shouldRetry(
        array $delivery,
        WebhookDeliveryResult $result,
    ): bool {
        if (!$this->hasAttemptsLeft($delivery)) {
            return false;
        }

        if ($result->status === null) {
            return true;
        }

        return in_array($result->status, [408, 425, 429], true) || $result->status >= 500;
    }

    /**
     * HR: Provjerava nije li isporuka potrošila dopušteni broj pokušaja.
     * EN: Checks whether the delivery still has attempts available.
     *
     * @param array<string,mixed> $delivery
     */
    private function hasAttemptsLeft(array $delivery): bool
    {
        return $this->intValue($delivery['attempts'] ?? 0) < $this->config->maxAttempts();
    }

    /**
     * HR: Dohvaća internu pretplatu za worker.
     * EN: Fetches an internal subscription for the worker.
     *
     * @return array<string,mixed>|null
     */
    private function subscription(int $id): ?array
    {
        $row = $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('id', '=', $id)
            ->first();

        return $this->row($row);
    }

    /**
     * HR: Isključuje pretplatu kada odredište trajno odgovori statusom 410.
     * EN: Disables a subscription when the destination permanently responds with 410.
     *
     * @param array<string,mixed> $subscription
     */
    private function deactivateSubscription(array $subscription): void
    {
        $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('id', '=', $this->intValue($subscription['id'] ?? 0))
            ->update([
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * HR: Zaustavlja worker kada funkcionalnost ili migracija nije dostupna.
     * EN: Stops the worker when the feature or migration is unavailable.
     */
    private function assertAvailable(): void
    {
        if (!$this->config->enabled()) {
            throw new \RuntimeException(__('Webhookovi nisu uključeni.'));
        }

        if (!$this->subscriptions->isSchemaReady()) {
            throw new \RuntimeException(__('Početna API migracija s webhook tablicama nije primijenjena.'));
        }
    }

    /**
     * HR: Gradi kratku javnu poruku za HTTP neuspjeh.
     * EN: Builds a concise public message for an HTTP failure.
     */
    private function httpFailure(?int $status): string
    {
        return $status !== null
            ? sprintf(__('Webhook cilj odgovorio je HTTP statusom %d.'), $status)
            : __('Webhook cilj nije dostupan.');
    }

    /**
     * HR: Pretvara generički ORM rezultat u redak sa string ključevima.
     * EN: Converts a generic ORM result into a string-keyed row.
     *
     * @return array<string,mixed>|null
     */
    private function row(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $row = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $row[$key] = $item;
            }
        }

        return $row;
    }

    /**
     * HR: Pretvara skalarnu vrijednost u podrezani string.
     * EN: Converts a scalar value to a trimmed string.
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * HR: Pretvara numeričku vrijednost u integer.
     * EN: Converts a numeric value to an integer.
     */
    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * HR: Ograničava opcionalni tekst prije spremanja rezultata odredišta.
     * EN: Bounds optional text before storing destination results.
     */
    private function nullableText(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }
}
