# Backup integration

The optional Backup integration registers the `api` provider for API-key requests and webhook subscriptions. Owners and keys are referenced through Auth portable identities, so numeric IDs can change during restore.

Webhook delivery attempts, idempotency records, rate-limit counters, audit delivery queues, and temporary request state are deliberately excluded. They are runtime state and replaying them could send old webhooks twice.

The provider supports full-site and component archives. It depends on the Auth dataset and therefore restores after Auth. Use preflight to detect a missing Auth provider before any target data is changed.

After restore, issue a new test request and a signed webhook test instead of replaying archived delivery rows. See [webhooks](webhooks_en.md) and [security](security_en.md).
