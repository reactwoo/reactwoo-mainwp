ReactWoo Support Portal

This repository contains the ReactWoo Support Portal WordPress plugin scaffold
and a companion client connector plugin.
The portal plugin provides database tables and REST endpoints for
subscription-bound site onboarding, token verification, and client identity
synchronization. The connector plugin (./connector) handles enrollment and
signed heartbeats from client sites. The maintenance bridge plugin
(./maint-bridge) registers maintenance hub tables and REST endpoints for
enrollment and lifecycle actions.

Configuration notes:
- Portal -> maintenance hub: set options `rw_portal_maint_url` and
  `rw_portal_maint_secret` (shared secret, stored as SHA-256). Filter overrides
  are available via `rw_portal_maint_url` and `rw_portal_maint_secret`.
- Maintenance hub: set `rw_maint_portal_secret` (same secret) or provide a
  `rw_maint_portal_secret` filter. Secrets are stored as SHA-256.
Settings UI:
- Portal: Settings -> ReactWoo Portal.
- Maintenance hub: Settings -> ReactWoo Maintenance.

Maintenance hub automation:
- Hook `rw_maint_mainwp_create_site` to create MainWP child sites and return the
  MainWP site ID (stored on the maint site row).
- Hooks `rw_maint_mainwp_suspend_site`, `rw_maint_mainwp_resume_site`,
  `rw_maint_mainwp_disconnect_site`, and `rw_maint_mainwp_purge_site` allow
  MainWP lifecycle actions.
- Disconnected sites are purged after 14 days by the daily cleanup task.

Client portal UI:
- My Account -> Maintenance lists subscriptions and sites.
- Clients can create sites, generate enrollment tokens, resync identity, and
  update report email preferences.
- Heartbeats update the last seen timestamp displayed in the portal.
It also registers subscription lifecycle hooks (including payment-failed grace
period handling) and scheduled cleanup for expired enrollment tokens.
