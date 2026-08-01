# Brzi početak API-ja: cURL, PHP i odgovori

## Prije prvog zahtjeva

1. Uključi ORM, Auth i API tim redom.
2. Pokreni `vendor/bin/hph api:install-migration` i
   `vendor/bin/hph orm-migrate:up`.
3. Kreiraj API ključ u **Postavke > Korisnici > API ključevi**. Kopiraj tajnu
   kada se prikaže jer je aplikacija više ne može prikazati.
4. Ključu dodijeli samo scopeove koji su klijentu nužni.

Primjeri koriste varijable okoliša kako se stvarna tajna ne bi spremila u shell
povijest ili izvorni kod:

```bash
export HPH_API_URL='https://example.test/api/v1'
read -r -s HPH_API_TOKEN
export HPH_API_TOKEN
```

## Čitanje korisnika cURL-om

Vlasnik ključa mora biti aktivni administrator, a ključ treba `users:read`.

```bash
curl --fail-with-body --silent --show-error \
  --get "$HPH_API_URL/users" \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  --data-urlencode 'page[limit]=2'
```

Primjer odgovora (identifikatori i request ID razlikuju se po instalaciji):

```json
{
  "data": [
    {
      "id": 7,
      "login_identifier": "ana@example.test",
      "display_name": "Ana Primjer",
      "email": "ana@example.test",
      "email_aliases": [],
      "attributes": {},
      "is_admin": false,
      "is_active": true,
      "auth_source": "local",
      "provider_access": {"local": true},
      "groups": [],
      "last_login_at": null,
      "must_change_password": true
    }
  ],
  "meta": {
    "request_id": "req_...",
    "page": {"limit": 2, "has_more": false, "next_cursor": null}
  },
  "links": {"self": "/api/v1/users?page[limit]=2", "next": null}
}
```

Lozinke, hashevi lozinki, tajne providera i tajne API ključeva nikada se ne
vraćaju.

## Kreiranje korisnika cURL-om

Ključ treba `users:create`, a vlasnik i dalje mora biti administrator. Za svaku
logičku write operaciju koristi novi `Idempotency-Key` duljine 8-190 znakova.

```bash
curl --fail-with-body --silent --show-error \
  --request POST "$HPH_API_URL/users" \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: user-create-ana-20260801' \
  --data '{
    "login_identifier": "ana@example.test",
    "password": "zamijeni-generiranom-privremenom-lozinkom",
    "is_active": true,
    "is_admin": false,
    "provider_access": {"local": true},
    "attributes": {
      "display_name": "Ana Primjer",
      "email": "ana@example.test"
    }
  }'
```

Uspjeh vraća HTTP `201`, zaglavlje `Location` i sigurni korisnički objekt u
`data`. Ponavljanje potpuno istog zahtjeva s istim ključem ponavlja spremljeni
odgovor i dodaje `Idempotency-Replayed: true`; promijenjeni zahtjev s već
iskorištenim ključem vraća HTTP `409`.

## Poziv iz običnog PHP-a

Primjer ne zahtijeva vanjski HTTP paket:

```php
<?php

declare(strict_types=1);

$baseUrl = rtrim((string)getenv('HPH_API_URL'), '/');
$token = (string)getenv('HPH_API_TOKEN');
if ($baseUrl === '' || $token === '') {
    throw new RuntimeException('Postavi HPH_API_URL i HPH_API_TOKEN.');
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        'ignore_errors' => true,
        'timeout' => 10,
    ],
]);
$body = file_get_contents($baseUrl . '/users?page%5Blimit%5D=2', false, $context);
if ($body === false) {
    throw new RuntimeException('API zahtjev nije moguće poslati.');
}

$payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
if (isset($payload['code'])) {
    throw new RuntimeException((string)($payload['detail'] ?? $payload['title']));
}

foreach ($payload['data'] ?? [] as $user) {
    printf("%d: %s\n", (int)$user['id'], (string)$user['login_identifier']);
}
```

Očekivani konzolni izlaz za gornji odgovor:

```text
7: ana@example.test
```

## Odgovor greške

Greške koriste RFC 9457 `application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Zabranjeno",
  "status": 403,
  "detail": "API ključ nema potreban scope.",
  "code": "insufficient_scope",
  "request_id": "req_..."
}
```

Pri prijavi problema zapiši `request_id`, ali nikada Bearer token.
