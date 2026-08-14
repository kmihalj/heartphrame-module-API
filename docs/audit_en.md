# Audit HTTP API

```text
GET /api/v1/audit
```

The endpoint requires `audit:read` and an active administrator as the API-key
owner. Scope possession alone never elevates a regular user.

When the optional Audit module is enabled, query parameters can filter by
`event_key`, `module`, `action`, `outcome`, `channel`, `actor_user_id`,
`workspace_id`, `page_id`, `target`, `created_from`, and `created_to`.
Pagination uses bounded `page` and `per_page` values. The central service
redacts passwords, API secrets, tokens, cookies, document bodies, and comparable
sensitive values before persistence and therefore before the HTTP response.

Without the Audit module, API remains compatible with the smaller Auth audit
and supports its event, actor, target-user, and time filters.

Audit is read-only over HTTP. Every mutating API operation continues to create
its domain or HTTP audit event through the same service used by the web
interface. Technical PSR-3 logs are deliberately not available through this
endpoint.
