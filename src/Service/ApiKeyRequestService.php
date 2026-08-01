<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use DateTimeImmutable;
use HeartPhrame\Encryption\EncryptionInterface;
use PDO;
use RuntimeException;

/**
 * HR: Upravlja zahtjevima korisnika za API ključ, administratorskom odlukom i
 *     jednokratnim sigurnim preuzimanjem odobrene tajne.
 *
 * EN: Manages user API-key requests, the administrator decision, and secure
 *     one-time retrieval of an approved secret.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiKeyRequestServiceTest
 */
final readonly class ApiKeyRequestService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * HR: Prima ORM bazu, Auth servise i aplikacijski servis za šifriranje.
     *
     * EN: Receives the ORM database, Auth services, and application encryption service.
     */
    public function __construct(
        private Database $database,
        private AuthApiKeyService $apiKeyService,
        private AuthUserService $userService,
        private EncryptionInterface $encryption,
    ) {
    }

    /**
     * HR: Provjerava postoji li tablica zahtjeva iz početne API migracije.
     *
     * EN: Checks whether the request table from the initial API migration exists.
     */
    public function isSchemaReady(): bool
    {
        return $this->database->schema()->hasTable(ModuleApi::TABLE_KEY_REQUESTS);
    }

    /**
     * HR: Sprema jedan zahtjev aktivnog korisnika. Korisnik može istodobno imati
     *     samo jedan zahtjev koji čeka odluku.
     *
     * EN: Stores one request for an active user. A user may have only one
     *     request awaiting a decision at a time.
     *
     * @param list<string> $scopes
     * @param list<string> $allowedIps
     * @return array<string,mixed>
     */
    public function request(
        int $userId,
        string $name,
        string $description,
        array $scopes,
        array $allowedIps,
        ?string $expiresAt,
    ): array {
        $this->assertSchemaReady();
        if (!is_array($this->userService->findById($userId))) {
            throw new RuntimeException(__('API ključ može zatražiti samo aktivan korisnik.'));
        }

        $existing = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('user_id', '=', $userId)
            ->where('status', '=', self::STATUS_PENDING)
            ->first();
        if (is_array($existing)) {
            throw new RuntimeException(__('Već imate zahtjev za API ključ koji čeka odluku administratora.'));
        }

        $name = $this->requiredName($name);
        $scopes = $this->normalizeStringList($scopes);
        if ($scopes === []) {
            throw new RuntimeException(__('Odaberi barem jedan API scope.'));
        }

        $allowedIps = $this->normalizeAllowedIps($allowedIps);
        $expiresAt = $this->normalizeFutureExpiry($expiresAt);
        $now = date('Y-m-d H:i:s');
        $uuid = $this->uuid();
        $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)->insert([
            'uuid' => $uuid,
            'user_id' => $userId,
            'name' => $name,
            'description' => $this->nullableText($description, 2000),
            'scopes_json' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'allowed_ips_json' => $allowedIps !== []
                ? json_encode($allowedIps, JSON_THROW_ON_ERROR)
                : null,
            'expires_at' => $expiresAt,
            'status' => self::STATUS_PENDING,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'decision_note' => null,
            'api_key_id' => null,
            'encrypted_token' => null,
            'token_revealed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireByUuid($uuid);
    }

    /**
     * HR: Vraća sve zahtjeve zadanog statusa za administratorski ekran.
     *
     * EN: Returns all requests with the supplied status for the administration screen.
     *
     * @return list<array<string,mixed>>
     */
    public function listByStatus(string $status): array
    {
        if (!$this->isSchemaReady()) {
            return [];
        }

        $status = $this->normalizeStatus($status);
        $rows = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('status', '=', $status)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $this->normalizeRowsWithUsers($rows);
    }

    /**
     * HR: Vraća zahtjeve jednog korisnika, najnovije prvo.
     *
     * EN: Returns one user's requests, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->isSchemaReady()) {
            return [];
        }

        $rows = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('user_id', '=', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return $this->normalizeRows($rows);
    }

    /**
     * HR: Odobrava zahtjev, izdaje ključ i do prvog korisnikova pregleda čuva
     *     samo šifriranu jednokratnu kopiju pune tajne.
     *
     * EN: Approves a request, issues the key, and stores only an encrypted
     *     one-time copy of the full secret until the user first views it.
     *
     * @return array<string,mixed>
     */
    public function approve(int $requestId, int $administratorUserId): array
    {
        $this->assertSchemaReady();

        /** @var array<string,mixed> $approved */
        $approved = $this->database->transaction(function (
            Database $database,
            PDO $pdo,
        ) use (
            $requestId,
            $administratorUserId,
        ): array {
            unset($database, $pdo);
            $request = $this->requirePending($requestId);
            $result = $this->apiKeyService->issue(
                $this->intField($request, 'user_id'),
                $this->stringField($request, 'name'),
                $this->stringField($request, 'description'),
                is_array($request['scopes'] ?? null) ? $request['scopes'] : [],
                is_array($request['allowed_ips'] ?? null) ? $request['allowed_ips'] : [],
                is_string($request['expires_at'] ?? null) ? $request['expires_at'] : null,
                $administratorUserId,
            );
            $now = date('Y-m-d H:i:s');
            $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
                ->where('id', '=', $requestId)
                ->where('status', '=', self::STATUS_PENDING)
                ->update([
                    'status' => self::STATUS_APPROVED,
                    'decided_by_user_id' => $administratorUserId,
                    'decided_at' => $now,
                    'api_key_id' => $this->intField($result->key, 'id'),
                    'encrypted_token' => $this->encryption->encrypt($result->plainTextToken),
                    'updated_at' => $now,
                ]);

            return $this->requireById($requestId);
        });

        return $approved;
    }

    /**
     * HR: Odbija zahtjev bez izdavanja ključa i sprema opcionalnu napomenu.
     *
     * EN: Rejects a request without issuing a key and stores an optional note.
     *
     * @return array<string,mixed>
     */
    public function reject(int $requestId, int $administratorUserId, string $note = ''): array
    {
        $request = $this->requirePending($requestId);
        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('id', '=', $requestId)
            ->where('status', '=', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_REJECTED,
                'decided_by_user_id' => $administratorUserId,
                'decided_at' => $now,
                'decision_note' => $this->nullableText($note, 2000),
                'updated_at' => $now,
            ]);

        return $this->requireById($this->intField($request, 'id'));
    }

    /**
     * HR: Vlasniku vraća odobreni token samo jednom te odmah uklanja njegovu
     *     šifriranu kopiju iz baze.
     *
     * EN: Returns the approved token to its owner only once and immediately
     *     removes its encrypted copy from the database.
     *
     * @return array{request:array<string,mixed>,token:string}
     */
    public function revealToken(int $userId, string $uuid): array
    {
        $this->assertSchemaReady();
        $row = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('uuid', '=', trim($uuid))
            ->where('user_id', '=', $userId)
            ->first();
        if (!is_array($row)) {
            throw new RuntimeException(__('Zahtjev za API ključ nije pronađen.'));
        }

        $request = $this->normalizeRow($row);
        $encrypted = is_string($row['encrypted_token'] ?? null) ? trim($row['encrypted_token']) : '';
        if (
            $request['status'] !== self::STATUS_APPROVED
            || $encrypted === ''
            || $request['token_revealed_at'] !== null
        ) {
            throw new RuntimeException(__('API secret je već prikazan ili više nije dostupan.'));
        }

        $token = $this->encryption->decrypt($encrypted);
        if (!is_string($token) || trim($token) === '') {
            throw new RuntimeException(__('API secret nije moguće sigurno učitati.'));
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('id', '=', $this->intField($request, 'id'))
            ->whereNull('token_revealed_at')
            ->update([
                'encrypted_token' => null,
                'token_revealed_at' => $now,
                'updated_at' => $now,
            ]);
        $request['token_revealed_at'] = $now;

        return ['request' => $request, 'token' => $token];
    }

    /**
     * HR: Zaustavlja rad jasnom porukom kada početna migracija nije primijenjena.
     *
     * EN: Stops with a clear message when the initial migration has not been applied.
     */
    private function assertSchemaReady(): void
    {
        if (!$this->isSchemaReady()) {
            throw new RuntimeException(__('Tablica zahtjeva za API ključeve nije kreirana.'));
        }
    }

    /**
     * HR: Dohvaća zahtjev koji još čeka odluku.
     *
     * EN: Fetches a request that is still awaiting a decision.
     *
     * @return array<string,mixed>
     */
    private function requirePending(int $requestId): array
    {
        $request = $this->requireById($requestId);
        if ($request['status'] !== self::STATUS_PENDING) {
            throw new RuntimeException(__('O zahtjevu za API ključ već je odlučeno.'));
        }

        return $request;
    }

    /**
     * HR: Dohvaća zahtjev prema internom ID-u.
     *
     * EN: Fetches a request by its internal ID.
     *
     * @return array<string,mixed>
     */
    private function requireById(int $requestId): array
    {
        if ($requestId <= 0) {
            throw new RuntimeException(__('Zahtjev za API ključ nije pronađen.'));
        }

        $row = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('id', '=', $requestId)
            ->first();
        if (!is_array($row)) {
            throw new RuntimeException(__('Zahtjev za API ključ nije pronađen.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * HR: Dohvaća zahtjev prema javnom UUID-u.
     *
     * EN: Fetches a request by its public UUID.
     *
     * @return array<string,mixed>
     */
    private function requireByUuid(string $uuid): array
    {
        $row = $this->database->table(ModuleApi::TABLE_KEY_REQUESTS)
            ->where('uuid', '=', trim($uuid))
            ->first();
        if (!is_array($row)) {
            throw new RuntimeException(__('Zahtjev za API ključ nije pronađen.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * HR: Normalizira više DB redaka bez učitavanja povezanih korisnika.
     *
     * EN: Normalizes multiple database rows without loading related users.
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
     * HR: Normalizira retke i pridružuje javni prikaz vlasnika zahtjeva.
     *
     * EN: Normalizes rows and attaches the public request-owner representation.
     *
     * @param array<mixed> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRowsWithUsers(array $rows): array
    {
        $normalized = $this->normalizeRows($rows);
        foreach ($normalized as &$request) {
            $request['user'] = $this->userService->findByIdIncludingInactive(
                $this->intField($request, 'user_id'),
            );
        }

        unset($request);

        return $normalized;
    }

    /**
     * HR: Pretvara spremljeni zahtjev u sigurni javni oblik bez šifrirane tajne.
     *
     * EN: Converts a stored request into a safe public shape without the encrypted secret.
     *
     * @param array<mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => is_numeric($row['id'] ?? null) ? (int)$row['id'] : 0,
            'uuid' => is_scalar($row['uuid'] ?? null) ? (string)$row['uuid'] : '',
            'user_id' => is_numeric($row['user_id'] ?? null) ? (int)$row['user_id'] : 0,
            'name' => is_scalar($row['name'] ?? null) ? (string)$row['name'] : '',
            'description' => is_scalar($row['description'] ?? null) ? (string)$row['description'] : '',
            'scopes' => $this->decodeList($row['scopes_json'] ?? null),
            'allowed_ips' => $this->decodeList($row['allowed_ips_json'] ?? null),
            'expires_at' => is_scalar($row['expires_at'] ?? null) ? (string)$row['expires_at'] : null,
            'status' => is_scalar($row['status'] ?? null) ? (string)$row['status'] : '',
            'decided_by_user_id' => is_numeric($row['decided_by_user_id'] ?? null)
                ? (int)$row['decided_by_user_id']
                : null,
            'decided_at' => is_scalar($row['decided_at'] ?? null) ? (string)$row['decided_at'] : null,
            'decision_note' => is_scalar($row['decision_note'] ?? null)
                ? (string)$row['decision_note']
                : '',
            'api_key_id' => is_numeric($row['api_key_id'] ?? null) ? (int)$row['api_key_id'] : null,
            'token_available' => is_scalar($row['encrypted_token'] ?? null)
                && trim((string)$row['encrypted_token']) !== '',
            'token_revealed_at' => is_scalar($row['token_revealed_at'] ?? null)
                ? (string)$row['token_revealed_at']
                : null,
            'created_at' => is_scalar($row['created_at'] ?? null) ? (string)$row['created_at'] : null,
            'updated_at' => is_scalar($row['updated_at'] ?? null) ? (string)$row['updated_at'] : null,
        ];
    }

    /**
     * HR: Sigurno čita cijeli broj iz servisnog ili baznog retka.
     *
     * EN: Safely reads an integer from a service or database row.
     *
     * @param array<mixed> $row
     */
    private function intField(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int)$row[$key] : 0;
    }

    /**
     * HR: Sigurno čita tekst iz servisnog ili baznog retka.
     *
     * EN: Safely reads text from a service or database row.
     *
     * @param array<mixed> $row
     */
    private function stringField(array $row, string $key): string
    {
        return is_scalar($row[$key] ?? null) ? (string)$row[$key] : '';
    }

    /**
     * HR: Normalizira i validira status filtra.
     *
     * EN: Normalizes and validates the status filter.
     */
    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new RuntimeException(__('Status zahtjeva za API ključ nije valjan.'));
        }

        return $status;
    }

    /**
     * HR: Normalizira obavezni naziv zahtjeva.
     *
     * EN: Normalizes the required request name.
     */
    private function requiredName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException(__('Naziv API ključa je obavezan.'));
        }

        return mb_substr($name, 0, 190);
    }

    /**
     * HR: Normalizira listu nepraznih jedinstvenih stringova.
     *
     * EN: Normalizes a list of non-empty unique strings.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * HR: Validira IP adrese i CIDR mreže prije spremanja zahtjeva.
     *
     * EN: Validates IP addresses and CIDR networks before storing the request.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeAllowedIps(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
            $valid = filter_var($address, FILTER_VALIDATE_IP) !== false;
            if ($prefix !== null) {
                $maximum = str_contains((string) $address, ':') ? 128 : 32;
                $valid = $valid && ctype_digit($prefix) && (int)$prefix >= 0 && (int)$prefix <= $maximum;
            }

            if (!$valid) {
                throw new RuntimeException(__('Neispravna IP adresa ili CIDR mreža: ') . $value);
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * HR: Normalizira opcionalni budući datum isteka.
     *
     * EN: Normalizes an optional future expiry date.
     */
    private function normalizeFutureExpiry(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date instanceof DateTimeImmutable || $date->getTimestamp() <= time()) {
            throw new RuntimeException(__('Datum isteka API ključa mora biti u budućnosti.'));
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * HR: Ograničava opcionalni tekst na sigurnu duljinu.
     *
     * EN: Limits optional text to a safe length.
     */
    private function nullableText(string $value, int $maximumLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maximumLength);
    }

    /**
     * HR: Dekodira spremljenu JSON listu.
     *
     * EN: Decodes a stored JSON list.
     *
     * @return list<string>
     */
    private function decodeList(mixed $value): array
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return [];
        }

        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeStringList(array_values(array_filter(
            $decoded,
            is_string(...),
        )));
    }

    /**
     * HR: Generira prenosivi UUID v4 bez vanjske biblioteke.
     *
     * EN: Generates a portable UUID v4 without an external library.
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
            substr($hex, 20, 12),
        );
    }
}
