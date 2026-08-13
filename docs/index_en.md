# API module: architecture and usage

New users should begin with the complete
[cURL and PHP quick start](quickstart_en.md).

## Responsibilities

The API module handles HTTP but never reads another module's tables:

1. `ApiAuthenticationMiddleware` verifies a Bearer key through Auth's public service.
2. `ApiScopeRegistry` reads scope descriptors from enabled modules.
3. A controller checks the scope and delegates to a domain service.
4. The domain module applies business rules and returns a safe DTO.
5. `ApiResponseFactory` produces consistent JSON or `application/problem+json`.

This separation shows beginners where new behavior belongs and lets advanced
developers replace the HTTP boundary without duplicating domain rules.

## Keys

Create a key under **Settings > Users > API keys**. Its secret is shown only
once and only a secure hash is stored. Rotation immediately invalidates the
old secret, while revocation keeps the auditable record. Permanent deletion
removes the key row but leaves the separate audit event.

The owner field is a server-side searchable combobox and therefore does not
render thousands of users. Active keys and revoked/expired keys are shown in
separate collapsible sections. Last-use and expiry values follow the active
locale; missing values are reported as **Never** and **No expiry**.

Users may submit a key request from their Auth profile. A user can have one
pending request at a time. Administrators approve or reject requests on the key
screen. Approval issues the real Auth key and sends a one-time retrieval link;
rejection sends the decision and optional note. The encrypted temporary secret
is cleared at first retrieval, so it cannot be displayed again.

Auth owns keys and identities. The screen and routes exist only while
`heartphrame-module-api` is enabled.

## Responses

A successful response:

```json
{
  "data": {},
  "meta": {"request_id": "…"},
  "links": {"self": "/hfc/api/v1/users/1"}
}
```

Links include the application's real base path. Failures use RFC 9457 problem
JSON with `type: "about:blank"`, a stable `code`, and `request_id`. Internal
stack traces, SQL, and secrets are never returned to clients.

`GET /api/v1` also returns `scope_groups` and a `security` object. Clients can
therefore discover only resources contributed by modules that are currently
enabled instead of hard-coding an installation profile.

## Auth scopes

- `users:read`, `users:create`, `users:update`, `users:delete`
- `groups:read`, `groups:manage`
- `audit:read`

`groups:manage` covers creating, updating, and deleting non-system groups and
changing memberships. User deletion intentionally deactivates the account and
revokes keys; the user identity remains available for audit.

`audit:read` is additionally administrator-only and exposes a redacted,
paginated security/management event stream. See the
[Audit API guide](audit_en.md).

## Workspace scopes and routes

When Workspace is installed and enabled, API registers 30 additional routes.
In addition to CRUD, tree, ACL, summaries, and HTML export, the contract covers
administrator homepage policy, the key owner's personal selection, and
Workspace-scoped themes. Theme and asset uploads use standard
`multipart/form-data`; private-theme export remains administrator-only.
`workspace:read` lists visible Workspaces, reads one Workspace, and reads its
ACL-filtered, language-aware tree and published summaries. `workspace:manage`
covers metadata changes, soft deletion/restoration, Workspace ACL, link nodes,
tree order, direct node restrictions, and portable HTML export.

Scope is necessary but not sufficient. The API key owner must also have the
same effective rights they would need in the web interface. A read-only member
therefore receives `403` when using a key that contains `workspace:manage`.
Document creation and attachment operations are deferred to the HTML Editor API.
Workspace and page DTOs also expose `tree_visibility` and
`contents_visibility`; Editor create/update accepts the page-level override.

The complete route reference and domain behavior are documented by the
Workspace module under **API integration**.

## HTML Editor scopes and routes

When HTML Editor is installed and enabled, API conditionally registers 22 page,
version, translation, draft, and attachment routes. Their scopes are
`page:read`, `page:write`, `page:publish`, `attachment:read`, and
`attachment:write`.

Published reads, drafts, history, uploads, and permissions intentionally behave
differently when Workspace owns a document. See the complete
[HTML Editor HTTP API reference](editor_html_en.md).

Submitting a Workspace-owned draft through
`POST /api/v1/pages/{documentId}/review` calls the same Workspace business
method as the web interface. Effective publishers receive the same deduplicated
in-app notification.

## Calendar scopes and routes

When Calendar is enabled, API adds 13 calendar, event, ACL, and ICS routes with
`calendar:read` and `calendar:write`. Calendar ACL is always rechecked. See the
[Calendar HTTP API reference](calendar_en.md).

## Workspace Search scope and route

When Workspace Search is installed and enabled, API adds
`GET /api/v1/workspace-search` with `workspace-search:read`. The endpoint
searches titles, published text, and authors and supports Workspace, author,
date, locale, and pagination filters. Counts, snippets, and results are all
computed only after the API-key owner's Workspace and inherited page ACL have
been applied. See the [Workspace Search reference](workspace_search_en.md).

## Task scopes and routes

When Task is enabled, API adds four page-task routes with `task:read` and
`task:write`. Definitions live in published Editor HTML; state and audit remain
separate. See the [Task HTTP API reference](task_en.md).

## Notification scopes and routes

When Notification is enabled, API adds five routes with
`notifications:read` and `notifications:write`. They operate only on the
API-key owner's inbox. See the
[Notification HTTP API reference](notification_en.md).

## Webhook scopes and routes

The API module contributes `webhooks:read` and `webhooks:manage`. Subscriptions
belong to the API key that created them; administrators may inspect all
subscriptions. Delivery is performed by a durable CLI worker, not inside the
business request. See the [Webhook guide](webhooks_en.md).

## Security and module boundaries

Rate limits, idempotent writes, JSON size limits, and replay behavior are
documented in the [security guide](security_en.md). Required and optional
module relationships are listed in the
[dependency guide](dependencies_en.md).

Menu, Theme, and E-mail intentionally expose no routes. E-mail delivery remains
an internal optional transport. A recipient controls e-mail copies through
their own account settings; an SMTP failure never rolls back an in-app
notification or its originating business operation.

## Adding another module

A domain module adds `config/api.php` with resources, bilingual labels, and
scopes. The descriptor must not import API-module classes. The API module may
register an optional controller that calls a public domain service, never the
domain module's private tables.

## Backup and restore

The optional provider, its intentional exclusions, and post-restore checks are
documented in [Backup integration](backup_en.md).
