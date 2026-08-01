# API security and reliability

## Authentication and authorization

Every route below `/api/v1` requires a Bearer key except discovery where
configured otherwise. A scope limits what the key may request; each domain
service additionally rechecks the real permissions of the key owner.

## Rate limiting

The default limit is 120 requests per minute per API key. Responses include
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`.
Exhaustion returns `429` with `Retry-After`.

The counter uses a portable ORM transaction. Concurrent first requests in a
new time window retry a conflicting insert briefly; repeated persistence
failure is logged and fails closed instead of silently bypassing the limit.

## Idempotent writes

Clients should send an 8-190 character `Idempotency-Key` on
`POST`, `PUT`, `PATCH`, and `DELETE`. The key is bound to the HTTP method,
path, query, and body fingerprint. A completed safe response can be replayed
with `Idempotency-Replayed: true`; reusing a key for a different request returns
`409`.

Uploads do not accept the header because multipart and streamed payloads have a
separate retry protocol. Stored replay bodies are size-bounded, expire, and do
not include server errors.

## Input and failures

JSON writes accept `application/json` and are size-limited before decoding.
Failures use RFC 9457 problem JSON and a request ID. If the security persistence
schema is unavailable, write protection fails closed with `503`.

## Webhook delivery

Webhook targets require HTTPS by default. Embedded credentials, unresolved
hosts, and private or reserved network addresses are rejected to reduce SSRF
risk. Development-only HTTP or private-network access must be enabled
explicitly. An optional hostname allowlist can narrow delivery further.

The signing secret is encrypted at rest. Every attempt signs
`<unix-timestamp>.<exact-json-body>` with HMAC-SHA256. Consumers should reject
old timestamps, compare signatures with a constant-time function, and
deduplicate by `X-HeartPhrame-Webhook-Id`.

The worker retries transport failures, `408`, `425`, `429`, and `5xx`.
Other `4xx` responses are terminal; `410` also disables the subscription.
Retries use capped exponential backoff and interrupted worker locks are
recovered after 15 minutes.
