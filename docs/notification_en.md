# Notification HTTP API

Notification routes exist only when the Notification module is installed and
enabled:

```text
GET    /api/v1/notifications
GET    /api/v1/notifications/{uuid}
PATCH  /api/v1/notifications/{uuid}
POST   /api/v1/notifications/read-all
DELETE /api/v1/notifications/{uuid}
```

## cURL example and response

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/notifications"
```

```json
{
  "data": [{"uuid": "79aa...", "title": "Review requested", "is_read": false}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/notifications"}
}
```

The endpoint always uses the API-key owner; a user ID cannot be supplied to
read another inbox. See [API quick start](quickstart_en.md) for PHP usage.

`notifications:read` permits listing and reading the API-key owner's messages.
`notifications:write` permits changing read state and removing an owned message
after it is read. An API key can never inspect or modify another user's inbox.

The API deliberately has no route for creating arbitrary notifications.
Domain modules create them as a consequence of real business operations. For
example, submitting a Workspace draft through the Editor API notifies effective
publishers exactly as the web action does.

E-mail copies are not an API resource. They are an optional internal transport
and are attempted only when the recipient enabled them in personal account
settings and the E-mail module is available.
