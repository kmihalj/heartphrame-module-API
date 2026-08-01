# API quick start: cURL, PHP, and responses

## Before the first request

1. Enable ORM, Auth, and API in that order.
2. Run `vendor/bin/hph api:install-migration` and
   `vendor/bin/hph orm-migrate:up`.
3. Create an API key under **Settings > Users > API keys**. Copy the secret when
   it is shown; the application cannot display it again.
4. Give the key only the scopes needed by the client.

The examples use environment variables so a real secret is not stored in shell
history or source code:

```bash
export HPH_API_URL='https://example.test/api/v1'
read -r -s HPH_API_TOKEN
export HPH_API_TOKEN
```

## Read users with cURL

The key owner must be an active administrator and the key needs `users:read`.

```bash
curl --fail-with-body --silent --show-error \
  --get "$HPH_API_URL/users" \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  --data-urlencode 'page[limit]=2'
```

Example response (identifiers and request IDs vary):

```json
{
  "data": [
    {
      "id": 7,
      "login_identifier": "ana@example.test",
      "display_name": "Ana Example",
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

Passwords, password hashes, provider secrets, and API-key secrets are never
returned.

## Create a user with cURL

The key needs `users:create`; the owner must still be an administrator. Use a
new 8-190 character idempotency key for each logical write operation.

```bash
curl --fail-with-body --silent --show-error \
  --request POST "$HPH_API_URL/users" \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: user-create-ana-20260801' \
  --data '{
    "login_identifier": "ana@example.test",
    "password": "replace-with-a-generated-temporary-password",
    "is_active": true,
    "is_admin": false,
    "provider_access": {"local": true},
    "attributes": {
      "display_name": "Ana Example",
      "email": "ana@example.test"
    }
  }'
```

A successful response has HTTP `201`, a `Location` header, and the safe user
object in `data`. Repeating the exact request with the same idempotency key
replays the saved response and adds `Idempotency-Replayed: true`; changing the
request while reusing the key returns HTTP `409`.

## Call the API from plain PHP

This example needs no third-party HTTP client:

```php
<?php

declare(strict_types=1);

$baseUrl = rtrim((string)getenv('HPH_API_URL'), '/');
$token = (string)getenv('HPH_API_TOKEN');
if ($baseUrl === '' || $token === '') {
    throw new RuntimeException('Set HPH_API_URL and HPH_API_TOKEN.');
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
    throw new RuntimeException('The API request could not be sent.');
}

$payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
if (isset($payload['code'])) {
    throw new RuntimeException((string)($payload['detail'] ?? $payload['title']));
}

foreach ($payload['data'] ?? [] as $user) {
    printf("%d: %s\n", (int)$user['id'], (string)$user['login_identifier']);
}
```

Expected console output for the sample response:

```text
7: ana@example.test
```

## Error response

Failures use RFC 9457 `application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Forbidden",
  "status": 403,
  "detail": "The API key does not grant the required scope.",
  "code": "insufficient_scope",
  "request_id": "req_..."
}
```

Log `request_id` when reporting a problem, but never log the Bearer token.
