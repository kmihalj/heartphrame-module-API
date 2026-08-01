# Audit HTTP API

```text
GET /api/v1/audit
```

The endpoint requires `audit:read` and an active administrator as the API-key
owner. Scope possession alone never elevates a regular user.

Optional query parameters filter by event, actor, target user, and time range.
Pagination uses bounded `page` and `per_page` values. Returned context is
redacted by Auth before it reaches the HTTP adapter; passwords, API secrets,
tokens, cookies, and comparable sensitive values are not exposed.

Audit is read-only over HTTP. Every mutating API operation continues to create
its domain/Auth audit event through the same service used by the web interface.
