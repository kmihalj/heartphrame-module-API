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
services and `config/api.php`, and keep the API package in `require-dev` only
for adapter tests. Each domain module owns its optional HTTP controller and
extension. Runtime service definitions are guarded by `interface_exists`, so
removing API removes the HTTP boundary without breaking web workflows.

Menu and Theme are optional web integrations only. E-mail is an optional
internal transport. None of these three modules contributes API resources.
