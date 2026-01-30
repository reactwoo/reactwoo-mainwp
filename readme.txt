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
  `rw_portal_maint_secret` (shared secret). Filter overrides are available via
  `rw_portal_maint_url` and `rw_portal_maint_secret`.
- Maintenance hub: set `rw_maint_portal_secret` (same secret) or provide a
  `rw_maint_portal_secret` filter.
It also registers subscription lifecycle hooks (including payment-failed grace
period handling) and scheduled cleanup for expired enrollment tokens.
