# Maintenance report 1.0.0

The machine contract is [`maintenance-report-1.0.0.schema.json`](maintenance-report-1.0.0.schema.json). This note is only the transport and the choices that the schema cannot express.

## Transport

The connector posts the JSON to the enrolled portal with the existing site secret:

`POST {portal}/index.php?rest_route=%2Freactwoo%2Fv1%2Fsites%2Fmaintenance-report`

Headers are `Content-Type: application/json` plus `X-RW-Site-ID`, `X-RW-Timestamp`, `X-RW-Nonce`, and `X-RW-Signature`. The signed path is `/reactwoo/v1/sites/maintenance-report`, with no `/wp-json` prefix. Verification can use the same rules as the heartbeat route.

The portal route is not registered in the connector. The server-side ingest owns that.

## When it runs

A daily cron event, `rw_connector_maintenance_report`, scans and audits, then sends. After WordPress finishes a core, plugin, or theme update, `rw_connector_maintenance_after_update` is queued for two minutes so a bulk update produces one report. Neither event runs inside a front-end page view. No scripts are added to the public site.

## What stays on the site

The auditor keeps, for each finished page, only the path, HTTP status, load time in milliseconds, and a content-present flag. The response body is discarded in that same request. Those four fields are not part of the posted JSON. Failures in the posted JSON use `url`, `status`, and `note`.

The Wordfence feed is not stored. A cache option keeps at most five short findings for each slug that was installed or awaiting an update, for 12 hours.

## Wordfence block

`wordfence` is `null` unless another task returns a partial object from the `rw_connector_maintenance_wordfence` filter. Expected keys, all optional, are `version`, `pro`, `template_applied`, and `conflicts_removed`.

The Intelligence API key is read from the `rw_connector_wordfence_intelligence_key` option or the `rw_connector_wordfence_intelligence_key` filter. This connector does not create that key. Without it, updates are still reported and `is_security` is false.
