# Maintenance report ingest

Compact update and page-check reports are stored on the maintenance hub and shown in the ReactWoo account. The connector signs the report the same way it signs a heartbeat. The account site checks that signature, then forwards the JSON to the hub on the existing portal signature.

This note matches the example in the maintenance plan. A versioned schema may replace it later. The parser keeps a report when optional fields are missing and ignores keys it does not know.

## Connector request

`POST /reactwoo/v1/sites/maintenance-report` on the account site.

Headers are the same as the heartbeat:

- `X-RW-Site-Id`: portal site id
- `X-RW-Timestamp`
- `X-RW-Nonce`
- `X-RW-Signature`

Sign `POST|/reactwoo/v1/sites/maintenance-report|{timestamp}|{nonce}|{sha256 of the raw body}` with the site secret. The body must be a JSON object of at most 65536 bytes.

```json
{
  "site_id": "42",
  "timestamp": "2026-11-09T12:00:00Z",
  "updates": [
    {
      "slug": "woocommerce",
      "from": "8.0.0",
      "to": "8.0.1",
      "is_security": true,
      "changelog_excerpt": "Fixes a checkout auth check.",
      "severity_hint": "critical"
    }
  ],
  "audit": {
    "pages_checked": 42,
    "failures": [
      { "url": "/checkout/", "status": 500, "note": "empty response" }
    ]
  },
  "backup_ref": "rwsnap_20261109T120000Z_abcd1234",
  "wordfence": {
    "version": "8.0.1",
    "pro": true,
    "template_applied": "v3",
    "conflicts_removed": []
  }
}
```

`site_id` may be the portal site id. When it is numeric it must match `X-RW-Site-Id`. A non-numeric `site_id` is kept as a label. `portal_site_id` and `mainwp_site_id` are optional. The hub stores the report on the client account already mapped from that MainWP site.

Optional fields the parser understands:

- `phase`, `report_type`, or `kind`: `exposure`, `post_patch`, or `status`. If omitted, updates that are not marked applied are treated as waiting exposure. A page check with no updates is treated as a post-update result.
- `applied` on an update, or `state` of `applied`, `installed`, or `updated`.
- `current` and `latest` are accepted as aliases of `from` and `to`.

`backup_ref` is kept only when it matches `rwsnap_YYYYMMDDThhmmssZ_` plus 8 to 32 hex characters, the same shape as an on-site snapshot. Anything else is ignored and the rest of the report is stored.

## Hub route

The account site forwards the same object to `POST /reactwoo-maint/v1/maintenance-report` with the portal signature. The hub also accepts that route directly. History, triage, and the customer actions are read with `maintenance-reports` and `maintenance-report-action`.

## Emails

Scheduled emails are off until an administrator enables them. The sender is `portal` (the account site) or `maint` (the hub). The default sender is the account site and the default notice period is 24 hours. A notice is sent only by the site named on it, and only when that site's switch is on. Notices still waiting after 7 days are expired and are not sent.

The customer switch for maintenance summaries also applies. When that is off, the notice is stored as skipped.
