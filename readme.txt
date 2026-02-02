ReactWoo Support Portal

This repository contains the ReactWoo Support Portal WordPress plugin scaffold
and companion maintenance + connector plugins.
The portal plugin provides database tables and REST endpoints for
subscription-bound site onboarding, token verification, and client identity
synchronization. The connector plugin (./reactwoo-support-connector) handles enrollment and
signed heartbeats from client sites. The maintenance bridge plugin
(./reactwoo-maint-bridge) registers maintenance hub tables and REST endpoints for
enrollment and lifecycle actions. The portal plugin lives under
./reactwoo-support-portal and the connector plugin under ./reactwoo-support-connector.

Configuration notes:
- Portal -> maintenance hub: set options `rw_portal_maint_url` and
  `rw_portal_maint_secret` (shared secret, stored as SHA-256). Filter overrides
  are available via `rw_portal_maint_url` and `rw_portal_maint_secret`.
- Optional: enable "Require Site URL Match" to enforce enrollment URL checks.
- Per-site override is available in My Account -> Maintenance.
- Maintenance hub: set `rw_maint_portal_secret` (same secret) or provide a
  `rw_maint_portal_secret` filter. Secrets are stored as SHA-256.
Settings UI:
- Portal: Settings -> ReactWoo Portal.
- Maintenance hub: Settings -> ReactWoo Maintenance.
Audit UI:
- Portal: Tools -> ReactWoo Portal Audit.
- Maintenance hub: Tools -> ReactWoo Maintenance Audit.
Managed Sites UI:
- Portal: Tools -> ReactWoo Managed Sites.
Audit tables display action/error (portal) and action/result (maintenance) columns.
Both audit screens support filtering by action value when available.
Audit message cells are collapsible using details/summary toggles.
Quick filter buttons are available for common actions (check, sync, reconnect).
Audit screens include CSV export buttons for filtered data (nonce-protected).
Dashboard widgets:
- Portal: ReactWoo Stale Sites + Recent Audit Events.
- Maintenance hub: ReactWoo Maintenance Recent Audit Events.
Dashboard configuration:
- Stale site threshold is configurable under Settings -> ReactWoo Portal.

Maintenance hub automation:
- Hook `rw_maint_mainwp_create_site` to create MainWP child sites and return the
  MainWP site ID (stored on the maint site row).
- Hooks `rw_maint_mainwp_suspend_site`, `rw_maint_mainwp_resume_site`,
  `rw_maint_mainwp_disconnect_site`, `rw_maint_mainwp_purge_site`,
  `rw_maint_mainwp_check_site`, `rw_maint_mainwp_sync_site`, and
  `rw_maint_mainwp_reconnect_site` allow MainWP lifecycle actions.
- Disconnected sites are purged after 14 days by the daily cleanup task.

MainWP REST integration:
- Configure MainWP API base URL + credentials under Settings -> ReactWoo Maintenance.
- Default API base path is `wp-json/mainwp/v2` (override via `rw_maint_mainwp_api_path`).
- Auth defaults to HTTP Basic; query-param auth is available via settings and
  `rw_maint_mainwp_query_params` filter. Query payloads are filterable via
  `rw_maint_mainwp_create_query` and `rw_maint_mainwp_reporting_query`.
- Site creation uses the MainWP `sites/add` endpoint and requires admin
  credentials. Provide them in the enrollment payload under `mainwp` or via the
  `rw_maint_mainwp_credentials` filter.
- Group/tag mapping uses `rw_maint_mainwp_group_ids` to convert logical groups
  to MainWP tag IDs.
- If tag IDs are not supplied, the bridge attempts to create missing tags via
  `tags/add` and caches tag IDs for one hour. Override tag colors with
  `rw_maint_mainwp_tag_color`.
- Lifecycle endpoints used: `/sites/{id}/check`, `/sites/{id}/sync`,
  `/sites/{id}/reconnect`, `/sites/{id}/suspend`, `/sites/{id}/unsuspend`,
  `/sites/{id}/disconnect`, `/sites/{id}/remove`.
- MainWP action responses (job/task IDs when present) are logged to the
  maintenance audit log under `mainwp_action_sent`.

Client portal UI:
- My Account -> Maintenance lists subscriptions and sites.
- Clients can create sites, generate enrollment tokens, resync identity, and
  update report email preferences.
- Heartbeats update the last seen timestamp displayed in the portal.
- Manual actions include MainWP check, sync, and reconnect.
- Last check and last sync timestamps are recorded when actions are triggered.
- Health column shows a freshness indicator based on the last heartbeat.

Identity sync:
- Automatic sync runs on profile updates, billing address updates, and checkout
  updates. Subscription owner changes sync the subscription's sites to the new
  owner and update maintenance hub identity data.
It also registers subscription lifecycle hooks (including payment-failed grace
period handling) and scheduled cleanup for expired enrollment tokens.
