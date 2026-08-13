# HTML Editor HTTP API

Croatian version: [editor_html_hr.md](editor_html_hr.md)

The routes below are registered only when
`aaieduhr/heartphrame-module-editor-html` is installed and enabled. All routes
require `Authorization: Bearer <token>`. The required scope limits the key, and
the key owner must independently pass Editor or Workspace ACL.

## Scopes

| Scope | Operations |
| --- | --- |
| `page:read` | List and read published pages and versions |
| `page:write` | Create, update, delete, translate, restore, and discard drafts |
| `page:publish` | Publish a Workspace draft |
| `attachment:read` | List, inspect, and download attachments |
| `attachment:write` | Upload, update, delete, cancel chunks, and change visibility |

`page:write` intentionally includes creation because Workspace creation starts
as a new draft. Publication remains a separate permission.

## Page routes

```text
GET    /api/v1/pages
POST   /api/v1/pages
GET    /api/v1/pages/{documentId}
PATCH  /api/v1/pages/{documentId}
DELETE /api/v1/pages/{documentId}
GET    /api/v1/pages/{documentId}/draft
DELETE /api/v1/pages/{documentId}/draft
POST   /api/v1/pages/{documentId}/publish
POST   /api/v1/pages/{documentId}/translations
```

Use the `lang` query parameter when a route reads one language. Workspace page
creation requires `workspace_slug` and may include `parent_id`. A Workspace
draft update must send the latest `draft_revision`; stale edits return
`409 Conflict`. The normal page endpoint always returns published content.

Create accepts `contents_visibility` with `inherit` (default), `shown`, or
`hidden`. Update may change the same field. It controls the page's initial
contents-card state and overrides the Workspace default. Page, draft, and
version DTOs return the stored policy for Workspace-owned documents;
standalone documents return `null`.

In standalone mode successful writes publish immediately and draft or publish
routes return a conflict. In Workspace mode creation and editing update one
shared draft until an authorized publisher calls the publish route.

Create and update accept exactly one of `html` or ordered `content`. Structured
content supports any mixture of:

- `{"type":"html","html":"..."}`;
- `{"type":"calendar", ...}` with one or more `calendar_uuids`;
- `{"type":"task_list", ...}` with one or more task `items`.

Returned page, draft, and version resources include canonical `html`, ordered
round-trippable `content`, and a flat `embeds` list. Add `rendered=1` to an
individual published-page read to receive ACL-aware `rendered_html` with
current calendars and task state. See the Editor module's API integration
guide for the complete block schema.

A Calendar entry in `embeds` returns `available`, `forbidden`, `deleted`, or
`partial` for a mixed block, plus each UUID's status. A reference to a calendar
deleted later remains in `content` and `html`; `rendered_html` reports that the
calendar is no longer available. A new block cannot insert an unavailable UUID.

## Version routes

```text
GET  /api/v1/pages/{documentId}/versions
GET  /api/v1/pages/{documentId}/versions/{versionNumber}
POST /api/v1/pages/{documentId}/versions/{versionNumber}/restore
```

Workspace history contains only published versions. Restore creates a draft in
Workspace mode and an immediately published version in standalone mode.

## Attachment routes

```text
GET    /api/v1/pages/{documentId}/attachments
POST   /api/v1/pages/{documentId}/attachments
POST   /api/v1/pages/{documentId}/attachments/chunks
DELETE /api/v1/pages/{documentId}/attachments/chunks/{uploadId}
PUT    /api/v1/pages/{documentId}/attachment-visibility
GET    /api/v1/pages/{documentId}/attachments/{assetUuid}
GET    /api/v1/pages/{documentId}/attachments/{assetUuid}/content
PATCH  /api/v1/pages/{documentId}/attachments/{assetUuid}
DELETE /api/v1/pages/{documentId}/attachments/{assetUuid}
```

Normal and chunk uploads use `multipart/form-data`. Intermediate chunks return
`202 Accepted`; the final chunk and a normal upload return `201 Created`.
Cancellation returns `204 No Content` and removes uploaded chunks. The content
route streams the original bytes with their stored media type and filename.

## Responses and errors

### cURL read example

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/pages"
```

Representative response:

```json
{
  "data": [{"id": 42, "document_key": "welcome", "language": "en"}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/pages"}
}
```

The result is ACL-filtered. Use [API quick start](quickstart_en.md) for the PHP
client, idempotent write example, and problem response handling.

JSON resources use the common API envelope:

```json
{
  "data": {},
  "meta": {"request_id": "..."},
  "links": {"self": "/hfc/api/v1/pages/42"}
}
```

Validation, missing resources, permissions, conflicts, and internal failures
use the common RFC 9457 problem response with stable `code` and `request_id`.
Typical statuses are `401`, `403`, `404`, `409`, and `422`.

The complete standalone and Workspace business behavior is documented in the
HTML Editor module under **API integration**.
