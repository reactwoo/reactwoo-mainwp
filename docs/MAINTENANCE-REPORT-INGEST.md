# Maintenance report ingest

The payload contract is schema 1.0.0, published with the connector reporter as `docs/maintenance-report-1.0.0.schema.json` and `docs/maintenance-report-1.0.0.md`. This note does not copy that schema. It only records how the account site and the maintenance hub handle a report that matches it.

## Transport

The connector posts to:

`POST {portal}/index.php?rest_route=/reactwoo/v1/sites/maintenance-report`

The signed path is `/reactwoo/v1/sites/maintenance-report`, with no `/wp-json` prefix. The account site registers that route and checks the signature with the same rules as the heartbeat. The body cap on that route is 64 KiB.

The account site then forwards the object to `POST /reactwoo-maint/v1/maintenance-report` on the existing portal signature. The hub stores it against the client account already mapped from the MainWP site.

## What the parser keeps

Schema 1.0.0 fields are kept: `schema_version`, string `site_id`, `timestamp`, update rows (`slug`, `type`, `current`, `latest`, `from`, `to`, `is_security`, `changelog_excerpt`, `severity_hint`), `audit.pages_checked`, failures (`url`, `status`, `note` only), `backup_ref`, and `wordfence`.

`severity_hint` may be `unknown`. Core rows use the slug `wordpress`. `wordfence` may be null, which is stored as null. An empty `backup_ref` is valid. Page checks are capped at 20. Load time and the content flag are not read, even if a later payload adds them.

A numeric `site_id` must match `X-RW-Site-ID`. Keys the schema does not name are ignored, so a missing optional block in an older body does not reject the report.

Schema 1.0.0 does not name a phase. Rows are available updates, so they are triaged as exposure unless a row is explicitly marked applied. A page check with no update rows is triaged as a post-update result.

## Emails

Scheduled emails stay off until an administrator enables them. The sender is `portal` (the account site) or `maint` (the hub). The default sender is the account site and the default notice period is 24 hours. A notice is sent only by the site named on it, and only when that site's switch is on. Notices still waiting after 7 days expire and are not sent.

The customer switch for maintenance summaries also applies. When that is off, the notice is stored as skipped.
