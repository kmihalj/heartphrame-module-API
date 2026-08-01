# HeartPhrame API module

[Hrvatska verzija](README_hr.md)

`heartphrame-module-api` provides the shared, versioned HTTP boundary for
HeartPhrame applications. The module is intentionally separate from domain
modules: it owns Bearer authentication, JSON envelopes, problem responses,
request IDs, scope discovery, and API-key administration, while Auth and later
domain modules keep their business rules.

## Requirements

- PHP 8.2 or newer
- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-auth` enabled before this module
- `aaieduhr/heartphrame-module-orm`

Theme and Menu are optional. The API-key screen uses Bootstrap-compatible
markup and framework fallbacks when Theme is absent. When Menu is installed,
the module adds **API keys** below the Auth settings group.

## API-key workflow

Administrators can issue keys directly. The owner selector uses a bounded
server-side search instead of loading every account into the page. Existing
keys are separated into active and revoked/expired sections; the list shows
localized last-use and expiry timestamps and supports rotation, revocation,
and permanent deletion.

An authenticated user can request a key from their profile. Administrators see
pending requests on the same key screen and may approve or reject them. A
decision creates an in-app notification when Notification is enabled. An
approved secret is encrypted only until its owner opens the secure one-time
retrieval page; persistent notifications never contain plaintext secrets.

## Core Auth endpoints

```text
GET    /api/v1
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{userId}
PATCH  /api/v1/users/{userId}
DELETE /api/v1/users/{userId}
PUT    /api/v1/users/{userId}/groups
GET    /api/v1/groups
POST   /api/v1/groups
GET    /api/v1/groups/{groupId}
PATCH  /api/v1/groups/{groupId}
DELETE /api/v1/groups/{groupId}
GET    /api/v1/audit
```

Every endpoint requires `Authorization: Bearer <token>`. Local user and group
administration additionally requires an active administrator as the key owner.
A scope can restrict an administrator key but can never grant application
rights that its owner does not have.

Route examples are relative to the application base path. If HeartPhrame is
installed below `/hfc`, response links and `Location` headers use
`/hfc/api/v1/...` automatically.

## Modular scopes

Each domain module may expose a neutral `config/api.php` descriptor without
depending on this module. API reads descriptors only from modules listed in
`app.modules.enabled`, then builds the scope catalog and key GUI dynamically.
Removing or disabling a domain module also removes its scopes from new keys.

The optional Workspace integration adds `/api/v1/workspaces` routes and the
`workspace:read` and `workspace:manage` scopes. The HTTP adapter remains in
this module, while Workspace owns ACL and business rules. Disabling Workspace
removes those routes without breaking the API module.

The optional HTML Editor integration adds 22 `/api/v1/pages` routes and the
`page:*` and `attachment:*` scopes. Editor owns sanitization, versions,
attachments, and the standalone/Workspace behavior; this module only adapts
those operations to HTTP.

The optional Calendar integration adds `calendar:read` and `calendar:write`
routes for calendar/event CRUD, ACL, and ICS. The optional Task integration adds
`task:read` and `task:write` routes for published task state and audit. Editor
structured content can combine any number of Calendar and Task embeds.

The optional Notification integration exposes only the API-key owner's inbox.
It never allows creating arbitrary messages. A Workspace draft submitted
through the API uses the same domain workflow as the web interface and
therefore notifies effective publishers. E-mail copies remain an internal,
best-effort transport and are sent only when the recipient enabled them.

Menu, Theme, and E-mail intentionally have no public HTTP API. Menu and Theme
configure the web application, while E-mail is a transport used by services.

Write requests are rate-limited and support an `Idempotency-Key` header.
Discovery at `GET /api/v1` returns active scope groups and security
capabilities. See the security and dependency guides for operational details.

## Webhooks

The API module owns durable webhook subscriptions and asynchronous delivery.
`webhooks:read` lists subscriptions and delivery history, while
`webhooks:manage` creates, changes, rotates, retries, and deletes them. Signing
secrets are encrypted at rest and shown only in the create or rotate response.

Successful API mutations enqueue sanitized events such as `pages.published`,
`calendar_events.updated`, or `workspaces.acl_changed`. Payloads never copy the
request or response body. A CLI worker signs the exact JSON with HMAC-SHA256,
retries temporary failures with bounded exponential backoff, and keeps terminal
failures available for inspection.

```text
vendor/bin/hph api webhooks:worker --batch-size=20
vendor/bin/hph api webhooks:worker --watch --sleep=5
vendor/bin/hph api webhooks:status
```

See the [webhook guide](docs/webhooks_en.md) for endpoints, signature
verification, SSRF protection, and process-manager setup.

## Dependency policy

The Framework and internal HeartPhrame modules are required from the moving
`dev-main` branch. This module does not commit `composer.lock`; GitHub CI
resolves the latest development heads on PHP 8.2-8.5 and runs the complete
`composer on-commit` suite. Do not add a hard-coded root-package `version`
field or a fixed-version substitute.

See [English documentation](docs/index_en.md) and
[Croatian documentation](docs/index_hr.md).
