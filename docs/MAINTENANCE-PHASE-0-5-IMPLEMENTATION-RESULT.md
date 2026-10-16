# Maintenance phases 0–5 — implementation result

**Date:** 2026-10-16  
**Scope:** source changes for Essential, Standard, and Business. Phases 6, 7, and 8 were not started.

## What landed in source

- Environment files stay ignored. The local maintenance access file was not read and is not part of the commit set.
- `_rw_maintenance_plan_key` is an API Manager product field: `none`, `essential`, `standard`, `business`. Empty stores `none`. It does not set a licence package.
- `RW_Maintenance_Plans` resolves the plan. Site limit is 1. Standard includes 60 support minutes a service month. Business includes 120. Essential includes 0. No price. Product entitlements are empty. Business performance monitoring is false until Page Speed delivery is proven.
- The portal lists a subscription only when that resolver says it is Maintenance, and only when `rw_maintenance_portal_v2` is on or a Maintenance product exists and the flag is not explicitly off.
- Token verification does not mark a site connected unless enrol returns a MainWP site id plus a successful check and sync.
- The bridge accepts admin plus unique id as the default automated path. A password is sent only when that is the available method, or when the request explicitly asks for password or both.
- Enrol stays idempotent on `portal_site_id`.
- Renewal failure uses `woocommerce_subscription_renewal_payment_failed`, starts a 3-day grace, and does not suspend while that marker exists. Manual on-hold still suspends. Recovery clears the grace. Final end suspends and schedules offboard 14 days later. Offboard removes MainWP access before the bridge row is marked disconnected.
- Pending-cancel with a future end date stays entitled. A missing end date does not start offboarding.
- The portal no longer loads `RW_License_Client`.

## REST

Pretty `/wp-json/` is still HTTP 500 on the store and HTTP 404 on the maintenance host. Both hosts answer HTTP 200 on `index.php?rest_route=`. The portal uses that query form from one builder, `RW_Rest_Url`. Server rewrites were not changed.

## Not done

- No staging Maintenance products were created. The new field is not installed on the store.
- No disposable WordPress child was available, so the live unique-id `sites/add` test was not run.
- Bridge 0.1.0 is still what the maintenance host runs. Portal 0.2.1 is still what the store runs. Neither was replaced from this pass.
- No subscription lifecycle test was run against WooCommerce. Subscriptions 2297, 1293, 1203, and 2293 were not touched.
- Staging database versions were not read. The schema upgrade remains the existing `dbDelta` path (portal 1.5, bridge 1.1) and must be confirmed on staging before install.

## Tests

`php tests/run.php` passed, including plan identity, connected-state rules, grace rules, redaction, the query REST URL, unique-id auth mode, HMAC replay, and enrol idempotency.

## Deferred

Phase 6 support ledger, Phase 7 support-hour products, and Phase 8 bundled ReactWoo licences. Included minutes are stored on the entitlement snapshot only. They are not granted by a ledger.

## Rollback

Do not install portal 0.3.0 against bridge 0.1.0. If a later staging install of 0.3.0 must be undone, put back the Git revision that was running before that install. Do not delete bridge rows to undo a connection. There is no down migration for schema 1.5 or 1.1.
