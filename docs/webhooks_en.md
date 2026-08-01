# Webhook API and worker

## Purpose

Webhooks notify another HTTPS application after a successful HeartPhrame API
mutation. They are asynchronous: the original business request stores an
outbox record and returns normally, while a CLI worker performs delivery.
A webhook failure can therefore never roll back a page publication, calendar
change, or other completed operation.

## Scopes and ownership

- `webhooks:read`: list visible subscriptions and their delivery history.
- `webhooks:manage`: create, update, delete, rotate, and retry.

A non-administrator sees only subscriptions created by the current API key.
An administrator key with the matching scope may manage all subscriptions.

## Endpoints

```text
GET    /api/v1/webhooks
POST   /api/v1/webhooks
GET    /api/v1/webhooks/{uuid}
PATCH  /api/v1/webhooks/{uuid}
DELETE /api/v1/webhooks/{uuid}
POST   /api/v1/webhooks/{uuid}/rotate-secret
GET    /api/v1/webhooks/{uuid}/deliveries
GET    /api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}
POST   /api/v1/webhooks/{uuid}/deliveries/{deliveryUuid}/retry
```

Create example:

```json
{
  "name": "Publishing integration",
  "target_url": "https://consumer.example/hooks/heartphrame",
  "events": ["pages.*", "calendar_events.created"],
  "active": true
}
```

The response contains `secret` once. The public subscription representation
never contains plaintext or encrypted secret material. Update, delete,
rotation, and manual retry use the normal `ETag`/`If-Match` contract.

Selectors may be exact (`pages.published`), namespace wildcards (`pages.*`),
or all events (`*`). Emitted families include users, groups, workspaces,
workspace nodes/tree, pages/workflow, attachments, calendars/events, tasks,
and notifications.

## Payload and signature

The payload is a JSON envelope with an event ID, event name, creation time, and
sanitized mutation metadata. Request and response bodies are deliberately
excluded.

Each POST includes:

```text
Content-Type: application/json
X-HeartPhrame-Webhook-Id: <event UUID>
X-HeartPhrame-Webhook-Event: <event name>
X-HeartPhrame-Webhook-Timestamp: <Unix seconds>
X-HeartPhrame-Webhook-Signature: v1=<hex HMAC-SHA256>
```

Verification pseudocode:

```php
$signed = $timestamp . '.' . $rawRequestBody;
$expected = hash_hmac('sha256', $signed, $secret);
$valid = hash_equals('v1=' . $expected, $receivedSignature);
```

Verify the raw body before decoding it. Reject stale timestamps according to
the consumer's risk policy and process each webhook ID only once.

## Worker operation

One batch:

```text
vendor/bin/hph api webhooks:worker --batch-size=20
```

Continuous foreground worker:

```text
vendor/bin/hph api webhooks:worker --watch --sleep=5
```

Queue status:

```text
vendor/bin/hph api webhooks:status
```

Production should run the watch command under systemd, supervisord, launchd,
or another process manager. Multiple workers are safe because rows are claimed
transactionally. Temporary failures use capped exponential backoff; terminal
failures remain available through the delivery endpoints for diagnosis and
manual retry.

## Configuration

`api.webhooks` supports:

- `enabled`
- `max_attempts`
- `base_retry_seconds`
- `max_retry_seconds`
- `timeout_seconds`
- `allow_insecure_http`
- `allow_private_networks`
- `allowed_hosts`

Keep insecure HTTP and private networks disabled in production. A hostname
allowlist is recommended when all consumers are known in advance.
