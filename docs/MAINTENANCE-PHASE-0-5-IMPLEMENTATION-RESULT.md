# Maintenance phases 0–5 — implementation result

**Date:** 2026-10-16  
**Scope:** source changes for Essential, Standard, and Business, then the support ledger, additional support products, and the empty bundled-licence path.

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

## Staging validation — 2026-10-16

- Unique-id add succeeded without an admin password (MainWP site id 24), then the site and the disposable WordPress were removed.
- Bridge 0.3.0 is active on the maintenance host. Database version is 1.1. The idempotency table was added. Site and audit tables were already empty and were not dropped.
- Portal 0.3.0 and API Manager 2.1.16 are active on the staging store. Production API Manager remains 2.1.14. Portal database version was already 1.5.
- Draft products: Essential 3634 (£1/month), Standard 3635 (£2/month), Business 3636 (£3/month). Licence package and plugin slug are empty.
- Subscription 3637 is the Standard staging test. Grace, on-hold-with-grace, and pending-cancel were exercised, then the subscription was returned to active. Subscriptions 2297, 1293, 1203, and 2293 were unchanged.
- Portal hub test first returned HTTP 401 because the portal secret and the bridge secret did not match.

## Staging enrol — 2026-10-16, later the same day

- The bridge secret was copied onto the portal option. Hub test then returned HTTP 200.
- The stored MainWP bearer matched no API key. An enabled read/write key was created and saved on the bridge. A sites list returned HTTP 200.
- Same-site calls use internal REST, and a MainWP `success: 0` body is now a failure.
- Disposable child `https://reactwoo.com/staging/rw-child-test`, password authentication off, unique id only. Portal enrol for subscription 3637: maint site 2, MainWP site 25, connected, check, and sync all yes.
- That MainWP site was removed. Portal and bridge rows were marked disconnected. The child WordPress, database, database user, and password files were deleted. The URL returns HTTP 404.
- Subscription 3637 is still active. 2297, 1293, and 1203 are still active. 2293 is still pending.

## Connect WordPress — 2026-10-16

- Enrol from the portal waits 180 seconds. A real enrol takes about a minute.
- WordPress 7 returns plugin ids without `.php`. The portal now compares those ids with the same normaliser used for the activation route.
- A disposable child at `https://reactwoo.com/staging/rw-child-test` completed Connect WordPress for subscription 3637: connected, with check and sync required before that state. The child, its database, and its database user were then removed. The URL returns HTTP 404. The MainWP site created for that child was removed. Other MainWP sites were left in place.
- Portal and bridge rows for that test were marked disconnected. Subscription 3637 stayed active. 2297, 1293, and 1203 stayed active. 2293 stayed pending.

## Support allowance and credits — 2026-10-16

- Portal 0.4.0 on staging, database 1.6, table `rw_support_ledger`.
- Subscription 3637 received one +60 plan allowance. A second grant inserted nothing. Included remainder was 60.
- Draft hidden products 3638 (60 minutes) and 3639 (300 minutes) use `_rw_support_minutes`. They are not subscriptions and have no licence package.
- Completed test order 3640 credited 60 purchased minutes once. A repeat credit did not insert another row. Included stayed 60 and purchased was 60.
- API Manager on staging skips a licence package id when the product is a Maintenance plan. An empty `reactwoo_product_entitlements` list makes no provision call. The third-party premium list is empty.
- The licence server looks up an existing licence by subscription id and package id, and sync updates every movable row. That change is tested locally and is not deployed to `license.reactwoo.com`.

## Not done

- Pretty permalinks were not repaired. Clients keep `index.php?rest_route=`.
- Nothing was pushed to GitHub, and API Manager `origin` (production SSH) was not pushed.
- The licence-server change is not on `license.reactwoo.com`.

## Tests

`php tests/run.php` passed, including plan identity, connected-state rules, grace rules, redaction, the query REST URL, unique-id auth mode, HMAC replay, and enrol idempotency.

## Deferred

No further Maintenance phases from this plan. Bundled ReactWoo plugin licences stay empty until a commercial decision adds package slugs. Third-party premium plugins stay off the licence server until counsel confirms a list.

## Rollback

Do not install portal 0.3.0 against bridge 0.1.0. If a later staging install of 0.3.0 must be undone, put back the Git revision that was running before that install. Do not delete bridge rows to undo a connection. There is no down migration for schema 1.5 or 1.1.
