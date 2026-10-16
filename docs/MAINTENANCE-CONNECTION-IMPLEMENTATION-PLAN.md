# Maintenance connection — implementation plan

**Audit:** [MAINTENANCE-CONNECTION-CURRENT-STATE.md](./MAINTENANCE-CONNECTION-CURRENT-STATE.md)  
**Date:** 2026-10-16  
**Status:** Phases 0–5 are in Git and installed on staging (portal 0.3.0, bridge 0.3.0, API Manager 2.1.16). Production API Manager is still 2.1.14. A disposable unique-id child enrolled through the portal client and reached management connected, check, and sync, then was removed. Phases 6–8 are not started. Staging notes are in [MAINTENANCE-PHASE-0-5-IMPLEMENTATION-RESULT.md](./MAINTENANCE-PHASE-0-5-IMPLEMENTATION-RESULT.md).

Resolved on 2026-10-16:

- The Maintenance plan field lives in API Manager as `_rw_maintenance_plan_key`.
- Maintenance products get no licence package.
- API Manager remains the only licence HTTP client. The portal licence client is not loaded.
- Phase 8 bundled licences stay deferred.
- Included support minutes follow the service anniversary, not a calendar month and not the Woo billing cycle.

The uncommitted working tree already contains a partial V2 attempt (portal 0.2.1 on the store, bridge 0.2.0 only on disk). That attempt is not the specification below. In particular it must not ship as-is:

- `verify_token()` still marks `connected` before MainWP succeeds
- entitlements are a free-form JSON blob, not `_rw_maintenance_plan_key`
- `support_reset_period` defaults to `billing_cycle`
- the portal includes its own licence HTTP client
- the dashboard is still bridge 0.1.0, and pretty `/wp-json/` on that host returns 404

Reuse, do not replace:

- API Manager `ReactWoo_License_Server_API::provision_license_v1()` and `sync_subscription_v1()`
- `license.reactwoo.com` as the only licence authority
- MainWP Dashboard 6.3 as the operations engine
- HMAC signing in `RW_Maint_Client` / `RW_Maint_Security`
- bridge site identity `portal_site_id` → `mainwp_site_id`

Do not create a Maintenance licence package. Do not put `_reactwoo_license_package_id` on the Maintenance product.

Each item lists repository, files, change, dependency, risk, test, and acceptance.

---

## PHASE 0 — Security

### 0.1 Commit the env ignore rules

- **Repo:** `reactwoo-mainwp`
- **Files:** `.gitignore`
- **Change:** Keep the working-tree rules and commit them: `*.zip`, `.env`, `.env.*`, `*.env`, `!.env.example`. GitHub `main` today ignores only `*.zip`.
- **Dependency:** none
- **Risk:** low. The access file is already untracked and is not in history.
- **Test:** `git check-ignore -v` on `reactwoo-support-connector/reactwoo-maintenance-access.env`; `git ls-files` must not list it.
- **Acceptance:** a fresh clone ignores env files. No credential value is in the commit.

### 0.2 Stop treating token verify as "connected"

- **Repo:** `reactwoo-mainwp`
- **Files:** `reactwoo-support-portal/includes/rest/class-rw-rest-controller.php`
- **Change:** `verify_token()` must not set `status=connected` before the bridge returns a MainWP-backed result. Enrol failure leaves the site pending or attention, and does not return a success payload that implies management is live.
- **Dependency:** Phase 3 operational definition of connected
- **Risk:** P0 behaviour change for any site already using the V1 token path
- **Test:** unit test with bridge enrol forced to fail; status stays not connected
- **Acceptance:** connected is written only after the operational check in Phase 3

### 0.3 Secret handling

- **Repo:** `reactwoo-mainwp`
- **Files:** bridge and portal audit loggers, `class-rw-maint-mainwp-client.php`
- **Change:** audit rows must not store admin passwords, application passwords, Unique Security IDs, HMAC secrets, or licence keys. Keep the 10-minute encrypted vault pattern only if Phase 3 still needs a short-lived bootstrap secret.
- **Dependency:** none
- **Risk:** over-redacting operational ids (`portal_site_id`, `mainwp_site_id` are allowed)
- **Test:** fail a sites/add call and assert the audit payload has no credential fields
- **Acceptance:** logs and REST errors identify the site without echoing secrets

---

## PHASE 1 — Maintenance product identity

### 1.1 Plan key on the Woo product

- **Repo:** `reactwoo-api-manager`
- **Files:** `includes/class-product-meta.php`
- **Change:** explicit `_rw_maintenance_plan_key`: `none` (default), `essential`, `standard`, `business`. Empty and `none` mean "not Maintenance". Do not infer from title, SKU, package id, or `rw_allowed_sites`. A variation override applies only when it stores essential, standard, or business. Empty inherits the parent.
- **Dependency:** Phase 0 gitignore commit is independent and should land first
- **Risk:** existing Geo, Atomic, Reviews, and Decision Cloud products must stay `none`
- **Test:** save each value; confirm products 2294, 2891, 2893, 3494, 3166 stay `none`
- **Acceptance:** an admin can mark only the new Maintenance products, and the value survives a product update

### 1.2 Catalogue

- **Repo:** store catalogue, not Git
- **Files:** none in Git until the field exists
- **Change:** create Essential, Standard, and Business as WooCommerce subscription products when implementation is approved. Not in this audit. Prices and billing intervals stay in WooCommerce. No prices in the entitlement resolver.
- **Dependency:** 1.1
- **Risk:** creating them on `reactwoo.com/staging` affects the shared host's staging subdirectory
- **Test:** products queryable by meta, not by name
- **Acceptance:** three subscription products exist with keys `essential`, `standard`, `business`, and `_reactwoo_license_package_id` empty

### 1.3 Entitlement resolver

- **Repo:** store side (`reactwoo.com`), class name `RW_Maintenance_Entitlements`
- **Files:** replace the uncommitted JSON resolver. Do not keep `rw_maintenance_entitlements` as the source of truth.
- **Change:** resolve from `_rw_maintenance_plan_key` only. Suggested contract, limited to extensions actually installed on MainWP 6.3 (Dashboard, UpdraftPlus, Client/Pro Reports, Page Speed, Wordfence, Maintenance extension). Configuration of those extensions is still **NOT VERIFIED**, so flags start as "plan intends this" and a later phase may turn one off if the extension cannot do it.

```json
{
  "is_maintenance": true,
  "plan_key": "standard",
  "site_limit": 1,
  "managed_updates": true,
  "backup_profile": "standard",
  "reports": true,
  "uptime": true,
  "ssl": true,
  "domain_monitoring": true,
  "vulnerability_monitoring": true,
  "performance_monitoring": false,
  "priority_support": false,
  "technical_support_minutes_monthly": 60,
  "reactwoo_product_entitlements": [],
  "third_party_premium_entitlements": []
}
```

Plan defaults:

| Key | Sites | Monthly support minutes | Performance | Priority |
| --- | --- | --- | --- | --- |
| essential | 1 | 0 | no | no |
| standard | 1 | 60 | no | no |
| business | 1 | 120 | yes, if Page Speed can be driven | yes |

Support cadence is **monthly**. It does not follow the Woo billing period. Annual Standard is still 60 minutes per month, not 720 at purchase, and unused minutes do not roll.

- **Dependency:** 1.1
- **Risk:** the uncommitted class defaults reset to `billing_cycle` and fails closed on missing JSON. Replace that behaviour rather than extending it.
- **Test:** essential / standard / business / `none`; annual and monthly products with the same key return the same monthly minutes
- **Acceptance:** resolver output does not contain a price. `none` is not Maintenance.

### 1.4 Snapshot

- **Repo:** store
- **Files:** subscription meta `_rw_maintenance_entitlements` written at purchase and at plan change, not edited by later catalogue tweaks
- **Change:** snapshot the resolver output onto the subscription when it is created or switched. Existing non-maintenance subscriptions get no snapshot and stay invisible to the portal.
- **Dependency:** 1.3
- **Risk:** a later edit to the PHP defaults must not rewrite old contracts
- **Test:** change the PHP default after snapshot; the stored subscription is unchanged
- **Acceptance:** portal reads the snapshot for existing contracts

### 1.5 Backfill

- **Repo:** store
- **Change:** no automatic backfill. The audit found zero Maintenance subscriptions. Geo, Atomic, Reviews, WHMCS, and Decision Cloud subscriptions must remain non-maintenance.
- **Dependency:** 1.1
- **Risk:** a name-based migration would mis-label Atomic "Business" and Decision Cloud plans
- **Test:** dry-run report of products whose key is not `none` is empty until the new products exist
- **Acceptance:** subscriptions 2297, 1293, 1203, and 2293 do not gain a plan key

---

## PHASE 2 — Customer portal

### 2.1 Show only Maintenance subscriptions

- **Repo:** `reactwoo-mainwp`
- **Files:** `reactwoo-support-portal/includes/class-rw-portal-account.php`
- **Change:** list a subscription only when the resolver says `is_maintenance`. Remove the generic "Maintenance Plans" heading over every Woo subscription.
- **Dependency:** Phase 1
- **Risk:** customers who only own Geo or Reviews see an empty Maintenance page. That is correct.
- **Test:** user with subscription 2297 sees no Maintenance plan; user with a Standard test subscription sees one
- **Acceptance:** product title is not used to decide

### 2.2 Status and benefits

- **Repo:** `reactwoo-mainwp`
- **Files:** portal account templates
- **Change:** show plan key, site limit, operational status, and the monthly support allowance. Do not show a price copied from the resolver. Link billing to the existing Woo subscription view.
- **Dependency:** 2.1
- **Risk:** copy that names MainWP. Customer copy should say maintenance, not the dashboard product name.
- **Test:** essential shows 0 minutes; standard shows 60 per month; business shows 120 per month
- **Acceptance:** benefits match the snapshot, including an annual subscription still described as a monthly allowance

---

## PHASE 3 — Passwordless connection

### 3.1 Application password authorisation

- **Repo:** `reactwoo-mainwp` connector and portal
- **Files:** `reactwoo-support-connector/includes/class-rw-connector-bootstrap.php`, portal orchestrator
- **Change:** customer authorises a short-lived application password for an administrator user. Do not ask for their normal WordPress password. Store it only in the short-lived vault, then revoke it.
- **Dependency:** Phase 2 can ship read-only before this; connection depends on Phase 4 enrol
- **Risk:** application passwords disabled on the customer site
- **Test:** authorise, confirm the password is not in audit JSON, confirm revoke removes it
- **Acceptance:** no permanent administrator is created

### 3.2 MainWP Child and Unique Security ID

- **Repo:** connector
- **Files:** `class-rw-mainwp-child-adapter.php`
- **Change:** ensure MainWP Child is active and read `uniqueid`. Fail closed if the child has neither Unique Security ID nor an explicitly allowed password method.
- **Dependency:** controlled test in the current-state document, item 1
- **Risk:** Child 6.x on the customer may still require the password until Unique Security ID is enabled
- **Test:** bootstrap payload contains `admin` and `uniqueid` and does not contain the customer's login password
- **Acceptance:** enrol request can be built without `adminpassword` when the child allows it

### 3.3 Connected means operational

- **Repo:** portal
- **Files:** connection orchestrator, `class-rw-rest-controller.php`
- **Change:** set `connected` only after the bridge reports the MainWP site id, a successful check, and a successful sync. The V1 `verify_token()` early write is removed or gated behind the same rule.
- **Dependency:** 0.2, Phase 4
- **Risk:** customers stuck in "setting up" if check/sync is slow. That is preferable to a false connected state.
- **Test:** fail check → not connected; pass check and sync → connected
- **Acceptance:** a failed MainWP add never shows the connected dashboard

---

## PHASE 4 — MainWP operational lifecycle

### 4.1 Fix the add-site gate

- **Repo:** `reactwoo-mainwp`
- **Files:** `reactwoo-maint-bridge/includes/class-rw-maint-mainwp-client.php`
- **Change:** call `POST /mainwp/v2/sites/add` when `url` and `admin` are present and at least one of `uniqueid` or `adminpassword` is present. Prefer `uniqueid`. Do not require `adminpassword` when `uniqueid` is present, once test 1 has passed on Dashboard 6.3.
- **Dependency:** Phase 0.3, controlled test
- **Risk:** a child with password auth still enabled will reject uniqueid-only. The connector must read the child setting or send the method the child has enabled.
- **Test:** contract test against a stub and one disposable 6.3 child
- **Acceptance:** deployed bridge version is bumped and the 0.1.0 password-only gate is gone

### 4.2 Permalinks

- **Repo:** server config on `maint.reactwoo.com`, and possibly the store
- **Files:** none, unless the client must grow a `rest_route` fallback
- **Change:** make `/wp-json/mainwp/v2/` and `/wp-json/reactwoo-maint/v1/` return the REST index instead of 404. Same for the store, where `/wp-json/` returns 500. Do this before any enrol test.
- **Dependency:** none
- **Risk:** rewriting permalinks on a live dashboard
- **Test:** unauthenticated GET of the namespace index is 200, not 404 or 500
- **Acceptance:** `RW_Maint_Client::endpoint_url()` can reach the bridge without a code workaround

### 4.3 Lifecycle methods

- **Repo:** bridge
- **Files:** `class-rw-maint-mainwp-client.php`, `class-rw-maint-sites.php`, REST controller
- **Change:** keep enrol, check, sync, suspend, resume, disconnect. Add an explicit offboard that disconnects or removes the MainWP child after the grace window, and only then allows purge. Map portal `connected` to MainWP unsuspend/resume, not to a second "connected" status inside MainWP.
- **Dependency:** 4.1, 4.2
- **Risk:** `remove` is destructive. It must not run on the first failed payment.
- **Test:** suspend then resume a disposable site; disconnect does not delete the portal commercial row
- **Acceptance:** each action is idempotent on `portal_site_id`

### 4.4 Deploy order inside this phase

Ship the bridge to the dashboard **before** the portal starts sending `uniqueid`-only enrol payloads. Today the dashboard is 0.1.0 and would skip those calls.

---

## PHASE 5 — Failed payment and cancellation

### 5.1 Three-day payment grace

- **Repo:** `reactwoo-mainwp`
- **Files:** `reactwoo-support-portal/includes/class-rw-subscriptions.php`
- **Change:** hook `woocommerce_subscription_renewal_payment_failed` (and the on-hold transition that follows it). Start the 3-day grace there. Do not suspend inside `handle_on_hold()` while the grace marker is in force. Remove reliance on `woocommerce_subscription_status_payment_failed`, which is not a WooCommerce Subscriptions 9.3 status.
- **Dependency:** Phase 4 suspend/resume
- **Risk:** a manual on-hold by staff would also be delayed if it is not distinguished from a failed renewal. Staff on-hold can keep the immediate suspend path when no grace marker is set.
- **Test:** failed renewal leaves the site operational for 72 hours; payment success clears the scheduled suspend; no payment suspends once
- **Acceptance:** the site row is not deleted

### 5.2 Pending cancellation

- **Repo:** portal
- **Files:** `class-rw-subscriptions.php`
- **Change:** `pending-cancel` keeps current operational access until the subscription end date. Do not treat it as `cancelled`. Align with the licence server, which already keeps pending-cancel active until period end.
- **Dependency:** 5.1
- **Risk:** a pending-cancel with an empty end date. Fail safe by keeping service until an end date is known, and alert staff.
- **Test:** pending-cancel with a future end does not suspend; the day after end, Phase 5.3 runs
- **Acceptance:** prepaid time is honoured

### 5.3 Final expiry and 14-day offboarding

- **Repo:** portal and bridge
- **Files:** `class-rw-subscriptions.php`, `class-rw-maint-cleanup.php`
- **Change:** when the paid entitlement has actually ended (`cancelled` after the prepaid period, or `expired`): suspend operational access, set an offboarding clock of **14 days** (`DISCONNECT_GRACE_DAYS`), then disconnect MainWP, revoke the connector secret, and purge the operational row. Keep the Woo subscription and portal audit row.
- **Dependency:** 4.3, 5.2
- **Risk:** today's purge deletes `disconnected` rows and does not itself remove MainWP. Wire remove/disconnect **before** the row is deleted.
- **Test:** clock short-circuited in a unit test; disposable site removed from MainWP; subscription 2297 untouched
- **Acceptance:** cancellation of a prepaid period does not jump straight to purge

---

## PHASE 6 — Support allowance

### 6.1 Monthly grant

- **Repo:** store
- **Files:** new scheduler beside the resolver, not Woo billing webhooks
- **Change:** once per service-entitlement month, for an active or pending-cancel Maintenance subscription, append `plan_allowance` of 0, +60, or +120. The period is anchored to the subscription start, not the calendar month and not the Woo billing cycle. Example: start 16 October gives 16 Oct–15 Nov, then 16 Nov–15 Dec. Do not grant 12 months up front. Do not roll unused allowance. Expire the previous period's allowance with an `expiry` row.
- **Dependency:** Phase 1 snapshot
- **Risk:** double grant if cron and a webhook both fire. Idempotency key: `subscription_id + entitlement_period_start + plan_allowance`
- **Test:** annual Standard grants 60 at each service anniversary, not 720 on purchase and not 60 once per calendar year; a second run for the same period does not insert another +60
- **Acceptance:** balance for a new annual Standard in the first service period is 60, not 720. This phase is not implemented.

### 6.2 Ledger

- **Repo:** store database owned by the portal or API Manager. One table, not user meta.
- **Change:** append-only columns: `id`, `user_id`, `subscription_id`, `managed_site_id` nullable, `order_id` nullable, `order_item_id` nullable, `minutes_delta`, `credit_type` (`plan_allowance`, `purchased`, `usage`, `adjustment`, `expiry`), `reference`, `expires_at` nullable, `created_at`, `created_by`.
- **Dependency:** 6.1
- **Risk:** a mutable "remaining minutes" meta would drift. Do not add one as the source of truth.
- **Test:** balance is the sum of unexpired rows; usage writes a negative row; staff adjustment stores the actor
- **Acceptance:** rebuilding the balance from the table matches the UI

### 6.3 Display

- **Repo:** portal admin and My Account
- **Change:** show this month's included remainder and purchased remainder separately. Purchased minutes are not mixed into the monthly expiry.
- **Dependency:** 6.2
- **Test:** use 30 of 60 included; display shows 30 included and any purchased pack untouched
- **Acceptance:** Essential shows no included minutes and can still show a purchased pack if Phase 7 allows it

---

## PHASE 7 — Additional support products

### 7.1 Simple virtual products

- **Repo:** store catalogue plus a small order listener
- **Change:** WooCommerce simple virtual products, not subscriptions and not licences:
  - Additional Support — 1 Hour (`_rw_product_type=support_credit`, `_rw_support_minutes=60`, `_rw_requires_maintenance=yes`, `_rw_credit_validity_days=365`)
  - Additional Support — 5 Hours (`300` minutes, same flags)
  - Optional later: Technical Support 1 Hour and 5 Hours with `_rw_requires_maintenance=no` for customers without Maintenance
- **Dependency:** Phase 6 ledger
- **Risk:** inferring minutes from the title. The listener must read `_rw_support_minutes` only.
- **Test:** order item with meta 60 credits 60; a retried webhook for the same `order_item_id` does not credit again
- **Acceptance:** nothing is posted to `license.reactwoo.com`

### 7.2 Pricing

- **Change:** member and non-member prices, and regional price books, live in WooCommerce. The ledger stores minutes, not money.
- **Dependency:** 7.1
- **Risk:** hard-coding GBP amounts from this audit
- **Test:** two prices can exist without a code change
- **Acceptance:** no price constant in the entitlement resolver or the ledger

---

## PHASE 8 — Bundled ReactWoo product licences

Do this only if a Maintenance plan will include ReactWoo plugin licences. It is not required for Essential/Standard/Business as operations-only plans. The audit recommendation is to launch Maintenance **without** a licence package on the Maintenance product.

### 8.1 Keep API Manager as the client

- **Repo:** `reactwoo-api-manager`
- **Files:** `includes/class-subscription-handler.php`, `includes/class-license-server-api.php`
- **Change:** the Maintenance resolver returns `reactwoo_product_entitlements` (package slugs). API Manager provisions each slug. Delete or do not deploy `class-rw-license-client.php` in the portal.
- **Dependency:** Phase 1, and an explicit product decision
- **Risk:** a second HTTP client will drift from sync rules
- **Test:** Maintenance with an empty entitlement list performs zero provision calls
- **Acceptance:** portal code does not call `/v1/licenses/provision`

### 8.2 Idempotency key

- **Repo:** `react-license`
- **Files:** `controllers/provisioningController.js`, `utils/subscriptionContract.js`, a migration only if a composite lookup needs a column
- **Change:** find an existing licence by `wc_subscription_id` **and** `package_id` / package slug, not `source_ref` alone with `LIMIT 1`. `sync-subscription` updates **every** movable row for that subscription id. Existing single-licence rows keep working because they are the only row for that subscription and package.
- **Dependency:** 8.1
- **Risk:** a naive unique index on `source_ref` would reject the second package. The current index is non-unique; do not add a unique index on `source_ref` alone.
- **Test:** subscription 1234 provisions Reviews and Geo Core Pro as two rows; a repeat call returns both existing rows; cancel updates both; a legacy subscription with one row still syncs
- **Acceptance:** provisioning package B does not return package A's key

### 8.3 Third-party premium plugins

- **Change:** eligibility for Elementor Pro, Rank Math Pro, and similar is a commercial/legal list on the entitlement, not a ReactWoo licence. Do not send those to the licence server.
- **Dependency:** legal confirmation (approval list)
- **Risk:** assuming an agency licence covers customer sites
- **Test:** third-party slugs never appear in `/v1/licenses/provision` bodies
- **Acceptance:** documented list, empty until counsel confirms

---

## PHASE 9 — Tests

| Area | What to automate | Pass |
| --- | --- | --- |
| Unit | plan key, resolver, grace scheduling, ledger sum, provision lookup | no network |
| Integration | API Manager calls a stub licence server; bridge calls a stub MainWP | password not required when uniqueid is set |
| Staging E2E | one test subscription, disposable child | connected only after check and sync |
| Failure | enrol 404, MainWP 401, renewal failure | site not deleted; status not connected |
| Idempotency | repeat enrol, repeat order credit, repeat provision | one row each |
| Security | audit JSON, git ignore, unsigned bridge POST | 401 and no secret in the body that is logged |
| Subscription lifecycle | active, on-hold, pending-cancel, cancelled, expired, resume | table in Phase 5 |
| Licence lifecycle | only if Phase 8 is in scope | two packages, one subscription |

`php` tests already exist under the uncommitted `tests/` directory. Extend those rather than adding a second runner. Licence changes use the existing Node tests in `react-license`.

---

## PHASE 10 — Release

### Order

1. Commit `.gitignore` (Phase 0.1) on `reactwoo-mainwp`.
2. Fix permalinks on `maint.reactwoo.com` and confirm `/wp-json/` on the store (Phase 4.2). No plugin release required if the server rewrite is enough.
3. Release the **bridge** first, with the credential gate and offboard action, tagged on `main`. Confirm the dashboard is no longer 0.1.0.
4. Release the **portal** second, with plan filtering, grace, and the connected-state fix. The store is already on an uncommitted 0.2.1; replace that deploy from Git, do not keep editing the server copy.
5. API Manager 2.1.16 adds the Maintenance plan field on top of the already-committed local 2.1.15 Geo Elementor commit. Do not push `origin` (that remote is production SSH). Do not fold 2.1.15 into the Maintenance commit.
6. Release **react-license** only with Phase 8. No Maintenance package migration.

### Flags

- `rw_maintenance_portal_v2` default off until a real plan key exists on a product.
- Bridge `uniqueid` gate can ship dark: old callers that still send `adminpassword` keep working.

### Rollback

- Portal: previous 0.1.0 Git tag if 0.2.1 must be removed. Taking the store back to 0.1.0 drops uncommitted schema 1.5 columns only if a down migration is written. Prefer forward-fix.
- Bridge: 0.1.0 remains the running version until the new tag is installed. Do not point the portal at uniqueid-only enrol before that tag is on the dashboard.
- Licence server: no rollback needed if Phase 8 is not released.

### Production verification

- `.gitignore` on GitHub contains the env rules.
- Store `/wp-json/` and dashboard `/wp-json/reactwoo-maint/v1` respond.
- A test Maintenance subscription is the only row in My Account → Maintenance.
- Disposable child connects with admin + uniqueid, then is removed.
- A failed test renewal does not delete the child inside three days.

---

## Suggested order

Phase 0 → permalinks (4.2) → product key and resolver (1) → portal filtering (2) → bridge gate (4) → connection (3) → payment and offboarding (5) → support ledger (6) → support products (7) → bundled licences (8) only if the commercial offer includes ReactWoo plugins.

Phases 6 and 7 can follow the first paid launch if the first offer has no support minutes. Phase 8 can wait. Phases 0, 1, 3, 4, and 5 cannot, if customers will connect sites.
