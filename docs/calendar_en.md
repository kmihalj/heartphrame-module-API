# Calendar HTTP API

Croatian version: [calendar_hr.md](calendar_hr.md)

Routes exist only while Calendar is installed and enabled. Every request needs
a Bearer key with the listed scope, and the key owner must also pass Calendar
ACL.

## Scopes

- `calendar:read`: visible calendars, events, and ICS export.
- `calendar:write`: calendar/event CRUD and complete ACL replacement.

## Routes

```text
GET    /api/v1/calendars
POST   /api/v1/calendars
POST   /api/v1/calendars/import
GET    /api/v1/calendars/{calendarUuid}
PATCH  /api/v1/calendars/{calendarUuid}
DELETE /api/v1/calendars/{calendarUuid}
PUT    /api/v1/calendars/{calendarUuid}/acl
GET    /api/v1/calendars/{calendarUuid}/export.ics
GET    /api/v1/calendars/{calendarUuid}/events?from=YYYY-MM-DD&to=YYYY-MM-DD
POST   /api/v1/calendars/{calendarUuid}/events
GET    /api/v1/calendars/{calendarUuid}/events/{eventId}
PATCH  /api/v1/calendars/{calendarUuid}/events/{eventId}
DELETE /api/v1/calendars/{calendarUuid}/events/{eventId}
```

## cURL example and response

```bash
curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Accept: application/json' \
  "$HPH_API_URL/calendars"
```

Representative response:

```json
{
  "data": [{"uuid": "6d5e...", "name": "Team calendar"}],
  "meta": {"request_id": "req_..."},
  "links": {"self": "/api/v1/calendars"}
}
```

Only calendars readable by the API-key owner appear. Use the common PHP client
and error handling from [API quick start](quickstart_en.md).

Event ranges are inclusive and may use `expand_recurring=0` to return source
events without expanded recurrence instances. ACL replacement accepts a
`rules` JSON list of user/group subjects and read/write flags. ICS returns
`text/calendar`; the remaining successful resources use the common JSON
envelope.

An Editor calendar embed stores UUIDs and display parameters. Use these routes
to manage the live calendar data rendered by that page.

The import route accepts the same payload as the Calendar web import and
rechecks the API-key owner's write rights. Minimal example:

```bash
curl --fail-with-body --silent --show-error \
  --request POST \
  --header "Authorization: Bearer $HPH_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"name":"Imported agenda","ics":"BEGIN:VCALENDAR\\r\\nVERSION:2.0\\r\\nEND:VCALENDAR\\r\\n"}' \
  "$HPH_API_URL/calendars/import"
```

The `ics` field is mandatory. Calendar name/UUID options and imported counters
follow the domain import contract; invalid iCalendar input returns the common
validation problem response.

`DELETE /api/v1/calendars/{calendarUuid}` permanently removes the calendar,
events, subscriptions, and ACL; there is no soft-delete or restore route. An
existing Editor block remains in its document, and its rendered output reports
the deleted calendar.
