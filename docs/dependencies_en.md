# API module dependencies

The API module requires Framework, Auth, and ORM. Auth owns API keys and their
owner identity; ORM stores rate-limit, idempotency, webhook subscription, and
outbox state portably. Webhook transport uses PHP streams and needs no external
mailer or HTTP-client package.

Domain integrations are optional and discovered only from enabled modules:

- Workspace contributes `workspace:*` and keeps ACL/tree rules.
- HTML Editor contributes `page:*` and `attachment:*`; when Workspace is
  enabled, page operations follow Workspace publication and inherited ACL.
- Calendar contributes `calendar:*` and rechecks Calendar ACL.
- Task contributes `task:*` and rechecks page visibility and task-list policy.
- Notification contributes `notifications:*` for the key owner's inbox.

The dependency direction remains domain-neutral: these modules expose public
services and `config/api.php`, but do not require the API module. API owns the
HTTP adapters and may be removed without breaking web workflows.

Menu and Theme are optional web integrations only. E-mail is an optional
internal transport. None of these three modules contributes API resources.
