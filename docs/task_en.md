# Task HTTP API

Croatian version: [task_hr.md](task_hr.md)

Routes exist only while Task is installed and enabled. They operate on task
definitions in the current published page, never on a draft.

## Scopes and routes

- `task:read`: task list, one task, and audit history.
- `task:write`: idempotent state change.

```text
GET /api/v1/pages/{documentId}/tasks?lang=en
GET /api/v1/pages/{documentId}/tasks/{taskUuid}?lang=en
PUT /api/v1/pages/{documentId}/tasks/{taskUuid}/state?lang=en
GET /api/v1/pages/{documentId}/tasks/{taskUuid}/history?lang=en&limit=50
```

State request:

```json
{"completed": true}
```

The API-key owner must read the page. An `editors` task additionally requires
page edit permission; a `viewers` task may be changed by any authenticated
reader. A real state transition records user identity and time. Repeating the
same desired value creates no duplicate event and no page version.

Use the HTML Editor page API to create or change task definitions.
