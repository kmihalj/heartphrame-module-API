<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\Exception\WebhookApiException;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use HeartPhrame\Encryption\EncryptionInterface;

use function bin2hex;
use function count;
use function date;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * HR: Upravlja webhook pretplatama, šifriranim tajnama i trajnim redom događaja
 *     koristeći isključivo prijenosni ORM.
 * EN: Manages webhook subscriptions, encrypted secrets, and the durable event
 *     queue using only the portable ORM.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\WebhookSubscriptionServiceTest
 */
final readonly class WebhookSubscriptionService
{
    private const SECRET_CONTEXT_PREFIX = 'heartphrame-api-webhook:';

    /**
     * HR: Prima ORM, šifriranje, sigurnosnu politiku cilja i konfiguraciju.
     * EN: Receives the ORM, encryption, target security policy, and configuration.
     */
    public function __construct(
        private Database $database,
        private EncryptionInterface $encryption,
        private WebhookTargetPolicy $targetPolicy,
        private WebhookConfig $config,
    ) {
    }

    /**
     * HR: Provjerava jesu li obje webhook tablice dostupne.
     * EN: Checks whether both webhook tables are available.
     */
    public function isSchemaReady(): bool
    {
        return $this->database->schema()->hasTable(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            && $this->database->schema()->hasTable(ModuleApi::TABLE_WEBHOOK_DELIVERIES);
    }

    /**
     * HR: Vraća pretplate vidljive identitetu; administrator vidi sve, a ostali
     *     samo pretplate vezane uz aktualni API ključ.
     * EN: Returns subscriptions visible to the identity; an administrator sees
     *     all, while others see only subscriptions owned by the current API key.
     *
     * @return list<array<string,mixed>>
     */
    public function listForIdentity(AuthApiIdentity $identity): array
    {
        $this->assertAvailable();
        $query = $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');
        if (!$identity->isAdmin()) {
            $query->where('owner_api_key_id', '=', $identity->keyId);
        }

        return $this->normalizeRows($query->get());
    }

    /**
     * HR: Kreira pretplatu i vraća tajnu samo u ovom odgovoru.
     * EN: Creates a subscription and returns its secret only in this response.
     *
     * @param array<string,mixed> $payload
     * @return array{subscription:array<string,mixed>,secret:string}
     */
    public function create(AuthApiIdentity $identity, array $payload): array
    {
        $this->assertAvailable();
        $name = $this->requiredName($payload['name'] ?? null);
        $targetUrl = $this->targetPolicy->assertAllowed($this->stringValue($payload['target_url'] ?? ''));
        $events = $this->normalizeEvents($payload['events'] ?? null);
        $uuid = $this->uuid();
        $secret = $this->newSecret();
        $now = date('Y-m-d H:i:s');

        $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)->insert([
            'uuid' => $uuid,
            'owner_api_key_id' => $identity->keyId,
            'owner_user_id' => $identity->userId(),
            'name' => $name,
            'target_url' => $targetUrl,
            'events_json' => json_encode($events, JSON_THROW_ON_ERROR),
            'encrypted_secret' => $this->encryption->encrypt(
                $secret,
                context: self::SECRET_CONTEXT_PREFIX . $uuid,
            ),
            'is_active' => $this->boolValue($payload['active'] ?? true) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'subscription' => $this->requireForIdentity($identity, $uuid),
            'secret' => $secret,
        ];
    }

    /**
     * HR: Dohvaća jednu dopuštenu pretplatu prema javnom UUID-u.
     * EN: Fetches one permitted subscription by public UUID.
     *
     * @return array<string,mixed>
     */
    public function requireForIdentity(AuthApiIdentity $identity, string $uuid): array
    {
        $this->assertAvailable();
        $row = $this->rawForIdentity($identity, $uuid);
        if ($row === null) {
            throw new WebhookApiException(
                404,
                'webhook_not_found',
                __('Webhook pretplata nije pronađena.'),
            );
        }

        return $this->normalizeRow($row);
    }

    /**
     * HR: Mijenja naziv, cilj, događaje ili aktivno stanje postojeće pretplate.
     * EN: Updates the name, target, events, or active state of an existing subscription.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function update(
        AuthApiIdentity $identity,
        string $uuid,
        array $payload,
    ): array {
        $current = $this->rawForIdentity($identity, $uuid);
        if ($current === null) {
            throw new WebhookApiException(404, 'webhook_not_found', __('Webhook pretplata nije pronađena.'));
        }

        $changes = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('name', $payload)) {
            $changes['name'] = $this->requiredName($payload['name']);
        }

        if (array_key_exists('target_url', $payload)) {
            $changes['target_url'] = $this->targetPolicy->assertAllowed(
                $this->stringValue($payload['target_url']),
            );
        }

        if (array_key_exists('events', $payload)) {
            $changes['events_json'] = json_encode(
                $this->normalizeEvents($payload['events']),
                JSON_THROW_ON_ERROR,
            );
        }

        if (array_key_exists('active', $payload)) {
            if (!is_bool($payload['active'])) {
                throw new WebhookApiException(
                    422,
                    'invalid_webhook_payload',
                    __('Polje "active" mora biti true ili false.'),
                );
            }

            $changes['is_active'] = $payload['active'] ? 1 : 0;
        }

        $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('id', '=', $this->intValue($current['id'] ?? 0))
            ->update($changes);

        return $this->requireForIdentity($identity, $uuid);
    }

    /**
     * HR: Trajno uklanja pretplatu i pripadajuću tehničku povijest isporuke.
     * EN: Permanently removes a subscription and its technical delivery history.
     */
    public function delete(AuthApiIdentity $identity, string $uuid): void
    {
        $current = $this->rawForIdentity($identity, $uuid);
        if ($current === null) {
            throw new WebhookApiException(404, 'webhook_not_found', __('Webhook pretplata nije pronađena.'));
        }

        $id = $this->intValue($current['id'] ?? 0);
        $this->database->transaction(function (Database $database) use ($id): void {
            $database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
                ->where('subscription_id', '=', $id)
                ->delete();
            $database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
                ->where('id', '=', $id)
                ->delete();
        });
    }

    /**
     * HR: Zamjenjuje potpisnu tajnu i novu vrijednost vraća samo jednom.
     * EN: Replaces the signing secret and returns the new value only once.
     *
     * @return array{subscription:array<string,mixed>,secret:string}
     */
    public function rotateSecret(AuthApiIdentity $identity, string $uuid): array
    {
        $current = $this->rawForIdentity($identity, $uuid);
        if ($current === null) {
            throw new WebhookApiException(404, 'webhook_not_found', __('Webhook pretplata nije pronađena.'));
        }

        $secret = $this->newSecret();
        $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('id', '=', $this->intValue($current['id'] ?? 0))
            ->update([
                'encrypted_secret' => $this->encryption->encrypt(
                    $secret,
                    context: self::SECRET_CONTEXT_PREFIX . trim($uuid),
                ),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'subscription' => $this->requireForIdentity($identity, $uuid),
            'secret' => $secret,
        ];
    }

    /**
     * HR: Vraća tehničku povijest isporuke jedne dopuštene pretplate.
     * EN: Returns the technical delivery history for one permitted subscription.
     *
     * @return list<array<string,mixed>>
     */
    public function listDeliveries(AuthApiIdentity $identity, string $uuid): array
    {
        $subscription = $this->rawForIdentity($identity, $uuid);
        if ($subscription === null) {
            throw new WebhookApiException(404, 'webhook_not_found', __('Webhook pretplata nije pronađena.'));
        }

        $rows = $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('subscription_id', '=', $this->intValue($subscription['id'] ?? 0))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return $this->normalizeDeliveryRows($rows);
    }

    /**
     * HR: Vraća jednu isporuku ako pripada dopuštenoj pretplati.
     * EN: Returns one delivery when it belongs to a permitted subscription.
     *
     * @return array<string,mixed>
     */
    public function requireDelivery(
        AuthApiIdentity $identity,
        string $subscriptionUuid,
        string $deliveryUuid,
    ): array {
        $subscription = $this->rawForIdentity($identity, $subscriptionUuid);
        if ($subscription === null) {
            throw new WebhookApiException(404, 'webhook_not_found', __('Webhook pretplata nije pronađena.'));
        }

        $row = $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('subscription_id', '=', $this->intValue($subscription['id'] ?? 0))
            ->where('uuid', '=', trim($deliveryUuid))
            ->first();
        if (!is_array($row)) {
            throw new WebhookApiException(
                404,
                'webhook_delivery_not_found',
                __('Webhook isporuka nije pronađena.'),
            );
        }

        return $this->normalizeDeliveryRow($row);
    }

    /**
     * HR: Vraća neuspjelu ili završenu isporuku u red za ručni ponovni pokušaj.
     * EN: Requeues a failed or completed delivery for a manual retry.
     *
     * @return array<string,mixed>
     */
    public function retryDelivery(
        AuthApiIdentity $identity,
        string $subscriptionUuid,
        string $deliveryUuid,
    ): array {
        $delivery = $this->requireDelivery($identity, $subscriptionUuid, $deliveryUuid);
        if ($delivery['status'] === 'sending') {
            throw new WebhookApiException(
                409,
                'webhook_delivery_busy',
                __('Webhook isporuka se upravo obrađuje.'),
            );
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)
            ->where('id', '=', $this->intValue($delivery['id'] ?? 0))
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'locked_at' => null,
                'delivered_at' => null,
                'response_status' => null,
                'response_body' => null,
                'last_error' => null,
                'updated_at' => $now,
            ]);

        return $this->requireDelivery($identity, $subscriptionUuid, $deliveryUuid);
    }

    /**
     * HR: Dodaje događaj u outbox svake aktivne pretplate čiji selektor odgovara.
     * EN: Adds an event to the outbox of every active subscription whose selector matches.
     *
     * @param array<string,mixed> $payload
     */
    public function publish(string $eventName, array $payload): int
    {
        if (!$this->config->enabled() || !$this->isSchemaReady()) {
            return 0;
        }

        $eventName = $this->normalizeEventName($eventName);
        $eventUuid = $this->uuid();
        $occurredAt = date(DATE_ATOM);
        $envelope = [
            'id' => $eventUuid,
            'type' => $eventName,
            'occurred_at' => $occurredAt,
            'data' => $payload,
        ];
        $payloadJson = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $now = date('Y-m-d H:i:s');
        $created = 0;
        $subscriptions = $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('is_active', '=', 1)
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            if (!$this->matches($subscription, $eventName)) {
                continue;
            }

            $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)->insert([
                'uuid' => $this->uuid(),
                'subscription_id' => $this->intValue($subscription['id'] ?? 0),
                'event_uuid' => $eventUuid,
                'event_name' => $eventName,
                'payload_json' => $payloadJson,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'locked_at' => null,
                'delivered_at' => null,
                'response_status' => null,
                'response_body' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            ++$created;
        }

        return $created;
    }

    /**
     * HR: Vraća dešifriranu tajnu samo internom workeru.
     * EN: Returns the decrypted secret only to the internal worker.
     *
     * @param array<string,mixed> $subscription
     */
    public function decryptSecret(array $subscription): string
    {
        $uuid = $this->stringValue($subscription['uuid'] ?? '');
        $encrypted = $this->stringValue($subscription['encrypted_secret'] ?? '');
        $secret = $this->encryption->decrypt(
            $encrypted,
            context: self::SECRET_CONTEXT_PREFIX . $uuid,
        );
        if (!is_string($secret) || trim($secret) === '') {
            throw new WebhookApiException(
                500,
                'webhook_secret_unavailable',
                __('Webhook tajnu nije moguće učitati.'),
            );
        }

        return $secret;
    }

    /**
     * HR: Vraća broj isporuka po statusu za nadzor workera.
     * EN: Returns delivery counts by status for worker monitoring.
     *
     * @return array<string,int>
     */
    public function deliveryStatus(): array
    {
        $status = ['pending' => 0, 'sending' => 0, 'delivered' => 0, 'failed' => 0];
        if (!$this->isSchemaReady()) {
            return $status;
        }

        $rows = $this->database->table(ModuleApi::TABLE_WEBHOOK_DELIVERIES)->get();
        foreach ($rows as $row) {
            $name = is_array($row) ? $this->stringValue($row['status'] ?? '') : '';
            if (array_key_exists($name, $status)) {
                ++$status[$name];
            }
        }

        return $status;
    }

    /**
     * HR: Provjerava konfiguraciju i početnu migraciju prije upravljačkih radnji.
     * EN: Checks configuration and the initial migration before management actions.
     */
    private function assertAvailable(): void
    {
        if (!$this->config->enabled()) {
            throw new WebhookApiException(503, 'webhooks_disabled', __('Webhookovi nisu uključeni.'));
        }

        if (!$this->isSchemaReady()) {
            throw new WebhookApiException(
                503,
                'webhook_schema_missing',
                __('Početna API migracija s webhook tablicama nije primijenjena.'),
            );
        }
    }

    /**
     * HR: Dohvaća sirovi redak uz provjeru vlasništva API ključa.
     * EN: Fetches a raw row while enforcing API-key ownership.
     *
     * @return array<string,mixed>|null
     */
    private function rawForIdentity(AuthApiIdentity $identity, string $uuid): ?array
    {
        $query = $this->database->table(ModuleApi::TABLE_WEBHOOK_SUBSCRIPTIONS)
            ->where('uuid', '=', trim($uuid));
        if (!$identity->isAdmin()) {
            $query->where('owner_api_key_id', '=', $identity->keyId);
        }

        $row = $query->first();

        return is_array($row) ? $this->stringKeyedRow($row) : null;
    }

    /**
     * HR: Pretvara retke pretplate u siguran javni oblik bez šifrirane tajne.
     * EN: Converts subscription rows to a safe public shape without the encrypted secret.
     *
     * @param array<mixed> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $this->normalizeRow($row);
            }
        }

        return $normalized;
    }

    /**
     * HR: Normalizira jedan javni prikaz webhook pretplate.
     * EN: Normalizes one public webhook subscription representation.
     *
     * @param array<mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row = $this->stringKeyedRow($row);

        return [
            'id' => $this->intValue($row['id'] ?? 0),
            'uuid' => $this->stringValue($row['uuid'] ?? ''),
            'owner_api_key_id' => $this->intValue($row['owner_api_key_id'] ?? 0),
            'owner_user_id' => $this->intValue($row['owner_user_id'] ?? 0),
            'name' => $this->stringValue($row['name'] ?? ''),
            'target_url' => $this->stringValue($row['target_url'] ?? ''),
            'events' => $this->decodeStringList($row['events_json'] ?? null),
            'active' => $this->intValue($row['is_active'] ?? 0) === 1,
            'created_at' => $this->nullableString($row['created_at'] ?? null),
            'updated_at' => $this->nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * HR: Pretvara retke isporuka u javni oblik.
     * EN: Converts delivery rows to their public representation.
     *
     * @param array<mixed> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeDeliveryRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $this->normalizeDeliveryRow($row);
            }
        }

        return $normalized;
    }

    /**
     * HR: Normalizira jedan pokušaj isporuke bez dupliciranja cijelog payload-a.
     * EN: Normalizes one delivery attempt without duplicating the full payload.
     *
     * @param array<mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeDeliveryRow(array $row): array
    {
        $row = $this->stringKeyedRow($row);

        return [
            'id' => $this->intValue($row['id'] ?? 0),
            'uuid' => $this->stringValue($row['uuid'] ?? ''),
            'event_id' => $this->stringValue($row['event_uuid'] ?? ''),
            'event' => $this->stringValue($row['event_name'] ?? ''),
            'status' => $this->stringValue($row['status'] ?? ''),
            'attempts' => $this->intValue($row['attempts'] ?? 0),
            'available_at' => $this->nullableString($row['available_at'] ?? null),
            'delivered_at' => $this->nullableString($row['delivered_at'] ?? null),
            'response_status' => is_numeric($row['response_status'] ?? null)
                ? (int)$row['response_status']
                : null,
            'response_body' => $this->nullableString($row['response_body'] ?? null),
            'last_error' => $this->nullableString($row['last_error'] ?? null),
            'created_at' => $this->nullableString($row['created_at'] ?? null),
            'updated_at' => $this->nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * HR: Provjerava odgovara li događaj exact ili wildcard selektoru pretplate.
     * EN: Checks whether an event matches an exact or wildcard subscription selector.
     *
     * @param array<mixed> $subscription
     */
    private function matches(array $subscription, string $eventName): bool
    {
        foreach ($this->decodeStringList($subscription['events_json'] ?? null) as $selector) {
            if (
                $selector === '*'
                || $selector === $eventName
                || (str_ends_with($selector, '.*')
                    && str_starts_with($eventName, substr($selector, 0, -1)))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * HR: Validira listu selektora događaja.
     * EN: Validates the list of event selectors.
     *
     * @return list<string>
     */
    private function normalizeEvents(mixed $value): array
    {
        if (!is_array($value)) {
            throw new WebhookApiException(
                422,
                'invalid_webhook_events',
                __('Polje "events" mora biti neprazna lista događaja.'),
            );
        }

        $events = [];
        foreach ($value as $event) {
            if (!is_scalar($event)) {
                continue;
            }

            $event = $this->normalizeEventName((string)$event);
            if (!in_array($event, $events, true)) {
                $events[] = $event;
            }
        }

        if ($events === [] || count($events) > 100) {
            throw new WebhookApiException(
                422,
                'invalid_webhook_events',
                __('Odaberi između 1 i 100 valjanih događaja.'),
            );
        }

        return $events;
    }

    /**
     * HR: Normalizira i validira jedan naziv događaja ili wildcard selektor.
     * EN: Normalizes and validates one event name or wildcard selector.
     */
    private function normalizeEventName(string $value): string
    {
        $value = strtolower(trim($value));
        if (
            $value === ''
            || strlen($value) > 190
            || preg_match(
                '/^(?:\*|[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*(?:\.\*)?)$/D',
                $value,
            ) !== 1
        ) {
            throw new WebhookApiException(
                422,
                'invalid_webhook_event',
                __('Naziv webhook događaja nije valjan.'),
            );
        }

        return $value;
    }

    /**
     * HR: Čita i ograničava obavezni naziv pretplate.
     * EN: Reads and bounds a required subscription name.
     */
    private function requiredName(mixed $value): string
    {
        $name = $this->stringValue($value);
        if ($name === '' || strlen($name) > 190) {
            throw new WebhookApiException(
                422,
                'invalid_webhook_name',
                __('Naziv webhook pretplate mora sadržavati od 1 do 190 znakova.'),
            );
        }

        return $name;
    }

    /**
     * HR: Dekodira JSON listu stringova iz baze.
     * EN: Decodes a JSON string list from storage.
     *
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $items[] = trim((string)$item);
            }
        }

        return $items;
    }

    /**
     * HR: Pretvara generički ORM redak u polje sa string ključevima.
     * EN: Converts a generic ORM row into a string-keyed array.
     *
     * @param array<mixed> $row
     * @return array<string,mixed>
     */
    private function stringKeyedRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
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
     * HR: Pretvara vrijednost u opcionalni neprazni string.
     * EN: Converts a value into an optional non-empty string.
     */
    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value !== '' ? $value : null;
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
     * HR: Pretvara bool ili uobičajenu tekstualnu vrijednost u bool.
     * EN: Converts a boolean or common textual value to a boolean.
     */
    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower($this->stringValue($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * HR: Generira javni UUID bez vanjske biblioteke.
     * EN: Generates a public UUID without an external library.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    /**
     * HR: Generira dovoljno dugu jednokratno prikazanu potpisnu tajnu.
     * EN: Generates a sufficiently long signing secret shown only once.
     */
    private function newSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(32));
    }
}
