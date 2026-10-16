# Maintenance connection — current state

**Audit date:** 2026-10-16  
**Spec:** [MAINTENANCE-CONNECTION-AND-ONBOARDING-V2.md](./MAINTENANCE-CONNECTION-AND-ONBOARDING-V2.md)  
**Method:** read-only. No products, subscriptions, licences, MainWP sites, or production configuration were changed.

This document replaces the 2026-10-06 code-only note. Where a fact was not observed, it is marked **NOT VERIFIED**.

# Executive Summary

ReactWoo already has three separate systems, and they must stay separate:

- `https://reactwoo.com/staging` is the WooCommerce store used for this audit (subdirectory install on the `reactwoo.com` host, currency GBP).
- `https://maint.reactwoo.com` is a MainWP Dashboard 6.3 site with the ReactWoo Maintenance Bridge.
- `https://license.reactwoo.com` is the only licence authority. API Manager on the store already provisions and syncs product licences there.

Maintenance as a managed service does **not** exist yet as a Woo product, a plan key, or a licence-server package. The My Account screen still treats a customer's Woo subscriptions as "Maintenance Plans". There is no support-minute ledger.

The deployed maintenance bridge is GitHub `main` at **0.1.0**. The store's support portal is **0.2.1**, which matches the uncommitted local working tree and is **not** on GitHub. Those two deployed versions cannot complete the V2 passwordless flow together.

MainWP 6.3 publishes `admin`, `adminpassword`, and `uniqueid` on `POST /mainwp/v2/sites/add`. The **deployed** bridge still refuses to call that endpoint unless `admin` and `adminpassword` are both present. A local uncommitted edit accepts `uniqueid` instead of the password, but that edit is not what is running on `maint.reactwoo.com`.

A failed renewal does **not** get a working three-day grace period. Cancellation suspends immediately and never starts the 14-day offboarding purge. One Woo subscription can provision only one ReactWoo licence.

# Audit Scope

In scope: repository state, staging WooCommerce catalogue and subscriptions, installed plugin versions, maintenance-server plugins and REST schemas, the public licence-package catalogue, and the provisioning contract in source.

Out of scope, and not performed:

- creating or editing Woo products or subscriptions
- provisioning, syncing, or revoking licences
- adding, editing, suspending, or removing MainWP child sites
- disconnecting a customer site
- database migrations
- reading or printing credential values

Access used: WooCommerce REST (read), WordPress application-password REST on the maintenance host (plugin list), unauthenticated REST indexes. The maintenance application password is **not** a MainWP API bearer token, so managed-site rows were not listed.

# Repository Versions Reviewed

Local uncommitted work was left in place. `reactwoo-mainwp` was already even with `origin/main`, so nothing was pulled.

| Repository | Commit used | Remote relationship |
| --- | --- | --- |
| `reactwoo/reactwoo-mainwp` | `fffdcf996cb547d5c46d4be7e70d5db33aec8004` | `main` equals `origin/main`. Large uncommitted working tree. |
| `reactwoo/reactwoo-api-manager` | local `47d1be249747c1f2df318b7b65a2e7e36d3cbe1e`; GitHub `main` `932e6d6365cd9f550a45626b13b8f4a514ac99ed` | Local `main` is **1 commit ahead** of `github/main` (`47d1be2`, version 2.1.15). Not pushed. |
| `reactwoo/react-license` | `523ca20708c30ce071479c93e16d4ad88aff5f2b` | `main` equals `origin/main`. Clean. |

Plugin versions in Git versus the working tree:

| Plugin | GitHub `main` | Uncommitted working tree |
| --- | --- | --- |
| Support portal | 0.1.0, DB constant 1.3 | 0.2.1, DB constant 1.5 |
| Support connector | 0.2.0 | 0.2.0, with local edits |
| Maintenance bridge | 0.1.0, DB constant 1.0 | 0.2.0, DB constant 1.1 |

The working tree adds, and GitHub does not contain: entitlement resolver, connection orchestrator, credential vault, licence client inside the portal, idempotency table, and `/reactwoo-maint/v1/test`.

# Staging Environment

Store: `https://reactwoo.com/staging`  
WordPress 7.1.3, PHP 8.2.34, WooCommerce 11.2.1, WooCommerce Subscriptions **9.3.0** (active), currency **GBP**.

Pretty REST `https://reactwoo.com/staging/wp-json/` returns **HTTP 500**. Reads succeeded through `index.php?rest_route=`. WordPress application passwords on this store returned **HTTP 401** for `/wp/v2/users/me` and `/wp/v2/plugins`. Catalogue data below is from the WooCommerce REST key.

## Deployed plugins

Active on the store (filtered):

| Plugin | Version | Active |
| --- | --- | --- |
| ReactWoo API Manager | **2.1.14** | yes |
| ReactWoo Support Portal | **0.2.1** | yes |
| WooCommerce | 11.2.1 | yes |
| WooCommerce Subscriptions | 9.3.0 | yes |
| MainWP Child | 6.3 | yes |
| MainWP Child Reports | 2.3.1 | yes |
| ReactWoo Geo Core | 1.9.8 | yes |
| ReactWoo Atomic Pro | 0.8.3 | yes |
| ReactWoo Flow | 0.6.1 | yes |
| ReactWoo WHMCS Bridge | 1.1.5.5 | yes |

Not installed on this store (absent from active and inactive lists): ReactWoo Maintenance Bridge, ReactWoo Support Connector.

`rw_portal_db_version` was **NOT VERIFIED**. There is no read endpoint for that option. The 0.2.1 header only proves the version string, not a file-by-file match with the local working tree.

Public namespace `reactwoo/v1` is registered. Support-portal routes in the working tree live in that namespace (`/sites/create`, `/tokens/verify`, `/connection/confirm-challenge`, `/sites/heartbeat`). Whether the deployed 0.2.1 files match the working tree was **NOT VERIFIED** after the route dump process failed. Re-check with `index.php?rest_route=/reactwoo/v1` before implementation.

## Woo products

13 products. **No product is a Maintenance plan.** None of the names, slugs, or meta keys are Essential / Standard / Business maintenance, managed WordPress, or support hours.

There is no `_rw_maintenance_plan_key`, no `rw_maintenance_entitlements`, no `_rw_product_type=support_credit`, and no `_rw_support_minutes`.

Keyword hits are other products that happen to contain "Business", "Standard" is absent, "support" is absent:

| ID | Name | Type | Status | Price (GBP) | Licence package | Plugin slug |
| --- | --- | --- | --- | --- | --- | --- |
| 3527 | ReactWoo Atomic | simple | publish | 0 | 0 | `reactwoo-atomic` |
| 3494 | ReactWoo Atomic Pro | variable-subscription | publish | from 49 / year | parent **2270** | `reactwoo-atomic-pro` |
| 3495 | variation Personal (1 site) | variation | publish | 49 / year | none on the variation | SKU `reactwoo-atomic-pro-personal` |
| 3496 | variation Business (5 sites) | variation | publish | 99 / year | none on the variation | SKU `reactwoo-atomic-pro-business` |
| 3497 | variation Agency (25 sites) | variation | publish | 199 / year | none on the variation | SKU `reactwoo-atomic-pro-agency` |
| 3166 | ReactWoo Decision Cloud | variable-subscription | publish | from 39 | parent **0** | none |
| 3172–3177 | Starter / Growth / Scale × monthly / annual | variations | publish | 39–2490 | none | `_rw_cloud_plan` present; this is Decision Cloud, not Maintenance |
| 2893 | Geo Commerce | subscription | publish | 98 (regular 149) | **2268** | `reactwoo-geo-commerce` |
| 2891 | Geo Optimise | subscription | publish | 99 (regular 149) | **2267** | `reactwoo-geo-optimise` |
| 2388 | Geo Core | simple | publish | 0 | 0 | none |
| 2294 | Geo Core - Pro | subscription | publish | 69 / year | **2269** | none |
| 1266 | WHMCS-CPT Pro | subscription | draft | 69 | **2265** | none |
| 1151 | Pro (Google Reviews) | subscription | draft | 49 | **2093** | none |
| 1111 | Enterprise (Google Reviews) | variable-subscription | draft | empty | not in the maintenance keyword set | |
| 1110 | Professional | subscription | draft | 79 | 0 | |
| 1107 | Basic | subscription | draft | 29.99 | not in the detailed meta set | |
| 1264 | WHMCS CPT Bridge | simple | draft | empty | | |

Atomic Pro variations do **not** carry their own `_reactwoo_license_package_id`. The parent is package 2270. The licence server also has separate packages 2272, 2273, and 2274 for Personal, Business, and Agency. That split is an existing Atomic issue, not a Maintenance plan.

## Existing subscriptions

Four subscriptions. None are Maintenance. Statuses in use: **active (3)**, **pending (1)**. No on-hold, cancelled, expired, or pending-cancel rows were present, so those live transitions were **NOT VERIFIED**.

| ID | Status | Item | Created (UTC) | Next payment (UTC) | Licence id | Package id on the subscription |
| --- | --- | --- | --- | --- | --- | --- |
| 2297 | active | Geo Core - Pro (2294), 69 GBP / year | 2026-06-12 | 2027-06-12 | 485 | absent |
| 1293 | active | WHMCS-CPT Pro (1266), 69 GBP / year | 2026-02-06 | empty | 355 | absent |
| 1203 | active | Pro (Google Reviews) (1151), 49 GBP / year | 2026-01-12 | empty | 316 | 2093 |
| 2293 | pending | no line items | 2026-06-12 | empty | absent | absent |

Meta that is **absent** on every subscription: `rw_allowed_sites`, `allowed_sites`, `_rw_payment_failed_grace`, `_rw_maintenance_plan_key`, `_rw_maintenance_entitlements`.

Three subscriptions have a licence key stored. Values were not recorded.

`rw_allowed_sites` missing does **not** mean the code enforces a limit. `RW_Subscriptions::get_allowed_sites()` returns 0 when the meta is empty, and the site-create guard runs only when the value is **greater than 0**. Empty therefore means unlimited on the committed and working-tree REST create path.

## Maintenance plan identification

There is no explicit Maintenance discriminator on any product or subscription.

The support portal loads `wcs_get_users_subscriptions()` and renders every subscription under the Maintenance account endpoint. The uncommitted entitlement class refuses to connect when `rw_maintenance_entitlements` JSON is missing, but it still lists the subscription. That JSON field is not on any live product. GitHub `main` has no entitlement class at all.

## API Manager state

Deployed API Manager is **2.1.14**, matching GitHub `main` (`932e6d6`). Local source is 2.1.15 and has not been pushed.

Product mapping is `_reactwoo_license_package_id` plus optional `_reactwoo_plugin_slug` (`includes/class-product-meta.php`). Provisioning is `POST /v1/licenses/provision`. Lifecycle sync is `POST /v1/licenses/sync-subscription` (`includes/class-license-server-api.php`, `includes/class-subscription-handler.php`).

`create_license_for_subscription()` stops at the **first** subscription item that has a package id. It does not issue a second package for the same subscription.

# Maintenance Server

Host: `https://maint.reactwoo.com`  
Pretty `https://maint.reactwoo.com/wp-json/` returns **HTTP 404**, including `/wp-json/reactwoo-maint/v1` and `/wp-json/mainwp/v2/sites`. The same routes respond through `index.php?rest_route=`.

The portal client always calls `{rw_portal_maint_url}/wp-json/reactwoo-maint/v1/{action}` (`RW_Maint_Client::endpoint_url()`). Against this host, that URL 404s. The saved option value was **NOT VERIFIED** (it is not exposed by a read API). If it is the site root, portal-to-bridge calls cannot succeed until permalinks are fixed or the client is pointed at `rest_route`.

## Bridge state

| Check | Result |
| --- | --- |
| Installed | yes, `reactwoo-maint-bridge/reactwoo-maint-bridge.php` |
| Active | yes |
| Version | **0.1.0** (GitHub `main`, not the local 0.2.0 tree) |
| DB/schema option `rw_maint_db_version` | **NOT VERIFIED** |
| REST routes | `POST` `/enroll`, `/suspend`, `/resume`, `/disconnect`, `/check`, `/sync`, `/reconnect` |
| `/test` | **404**. That route exists only in the uncommitted tree |
| HMAC | unsigned `POST` to `/test` was 404 because the route is absent. Unsigned calls to the real routes were not sent. Whether a shared secret is saved was **NOT VERIFIED** |
| Managed-site rows | **NOT VERIFIED**. Listing them needs the MainWP bearer token, which is not in the access file |
| `portal_site_id` / `subscription_id` / `mainwp_site_id` mappings | **NOT VERIFIED** |
| Site statuses, check/sync timestamps | **NOT VERIFIED** on the live tables |

Code schema (GitHub bridge 1.0 / working tree 1.1) stores `portal_site_id`, `subscription_id`, `user_id`, `site_url`, `mainwp_site_id`, and `status` (`active`, `suspended`, `disconnected`, `error`). The bridge table has `created_at` and `updated_at` only. It does not store a separate check timestamp. The working-tree portal schema does (`last_check_at`, `last_sync_at`). That portal schema is not what GitHub `main` ships.

Cleanup: `RW_Maint_Cleanup` schedules a daily cron and deletes bridge rows already in status `disconnected` whose `updated_at` is older than **14 days**. It does not call MainWP remove by itself in that loop beyond the `rw_maint_before_purge` action. Whether the cron is scheduled on the server was **NOT VERIFIED**.

## MainWP version and API

| Component | Version | State |
| --- | --- | --- |
| MainWP Dashboard | **6.3** | active |
| MainWP Child on the store | **6.3** | active |
| REST | `/mainwp/v2`, 179 routes in the public index | index is public; `GET /sites` with the WordPress application password returned **401** |

Extensions installed on the dashboard (active unless noted): Bulk Settings Manager 5.0.4, Cache Control 5.3, Client Reports 4.0.14, Code Snippets 5.0.4, Comments 5.1.2, Google Search Console 6.2.1, Maintenance 4.1.2, Page Speed 4.0.3, Pro Reports 5.1.5, Staging 5.1, UpdraftPlus 5.0.2, White Label 5.0.3, WooCommerce Status 5.1, Wordfence 5.2.2. Broken Links Checker 4.0 is inactive.

These extensions mean the dashboard **can** host updates, UpdraftPlus backups, reports, page speed, and Wordfence signals. They do **not** prove ReactWoo turns those features on per plan. Extension configuration was **NOT VERIFIED**.

MainWP auth mode stored in `rw_maint_mainwp_auth` / key / URL was **NOT VERIFIED**. Bridge code defaults the mode to `bearer` and calls `wp-json/mainwp/v2`.

# Licence Server

`GET https://license.reactwoo.com/v1/packages` returned **17 active packages**. `GET /v1/plans` returned an empty list. Admin `/admin/packages` requires a session and was not called. Inactive packages were **NOT VERIFIED**.

## Package catalogue summary

No active package name or slug contains maintenance, reactwoo-maintenance, wordpress-maintenance, or support-maintenance.

| ID | Slug | Name |
| --- | --- | --- |
| 2272 | `reactwoo-atomic-pro-personal` | ReactWoo Atomic Pro Personal |
| 2273 | `reactwoo-atomic-pro-business` | ReactWoo Atomic Pro Business |
| 2274 | `reactwoo-atomic-pro-agency` | ReactWoo Atomic Pro Agency |
| 2271 | `reactwoo-decision-cloud` | ReactWoo Decision Cloud |
| 2270 | `reactwoo-atomic-pro` | ReactWoo Atomic Pro |
| 2269 | `reactwoo-geo-core-pro` | Reactwoo Geo Core Pro |
| 2268 | `reactwoo-geo-commerce` | ReactWoo Geo Commerce |
| 2267 | `reactwoo-geo-optimise` | ReactWoo Geo Optimise |
| 2265 | `whmcs-cpt-bridge-pro` | WHMCS CPT Bridge - Pro |
| 2094 | `goorev-enterprise` | ReactWoo Reviews Enterprise |
| 2093 | `goorev-pro` | ReactWoo Reviews Pro |
| 2092 | `goorev-free` | ReactWoo Reviews - Free |
| 1946 | `geoelementor-pro` | GeoElementor - Pro |
| 1945 | `geoelementor-free` | GeoElementor - Free |
| 3 | `wooaliai-enterprise` | WooAliAi - Enterprise |
| 2 | `wooaliai-pro` | WooAliAi - Pro |
| 1 | `wooaliai-basic` | WooAliAi - Basic |

**A Maintenance package does not exist** in the active catalogue. Do not create one for the Maintenance service itself.

Source migrations account for Geo satellites (including historical `reactwoo-geo-ai`), Atomic Pro and its three seat packages, Decision Cloud, and `goorev-*`. `geoelementor-*` and `wooaliai-*` are live and are not created by the current migration set that was searched (`basic` / `pro` / `enterprise` in `create_tables.sql` and `enhance_packages.sql` are different slugs). Those rows were created outside the current migration files. `reactwoo-geo-ai` was not in the active list (the migrate script deprecates it; inactive state **NOT VERIFIED**).

No licence counts were queried. Keys were not requested.

## Provisioning architecture

`POST /v1/licenses/provision` is idempotent on `licenses.source_ref` = `wc_subscription_id` via `findBySourceRef()`, which is `LIMIT 1`. If a row exists, the handler returns that licence even when the new call asks for a different package. It does not insert a second row.

`source_ref` is indexed (`idx_source_ref` in `migrations/add_license_types_and_plans.sql`). It is **not** a unique constraint in that migration. The application still behaves as one subscription, one licence.

`POST /v1/licenses/sync-subscription` loads that same single row and updates it. Status map in `utils/subscriptionContract.js`:

| Woo status | Licence status |
| --- | --- |
| active | active |
| pending-cancel | active until the supplied period end is in the past, then cancelled |
| on-hold | suspended |
| cancelled, switched | cancelled |
| expired | expired |

`revoked`, `refunded`, and `banned` are left unchanged. `payment_failed` is not a sync status. WooCommerce puts a failed renewal on **on-hold**, which the licence server maps to **suspended** immediately. There is no three-day licence grace.

API Manager should remain the only storefront HTTP client for this API. The uncommitted portal `RW_License_Client` would be a second client. Do not ship that duplication.

# Current End-to-End Flow

What exists today:

```
PURCHASE
  WooCommerce subscription on reactwoo.com/staging
    → API Manager 2.1.14 (first package id on the subscription product)
      → license.reactwoo.com POST /v1/licenses/provision
        → one licence row, source_ref = subscription id

Later status changes
  → API Manager
    → POST /v1/licenses/sync-subscription
      → that one licence row
```

```
PURCHASE
  → My Account endpoint "maintenance"
    → every Woo subscription for that user, labelled as a Maintenance plan
      → portal site row + one-time token
        → connector on the customer site (not installed on the store or the dashboard)
          → POST portal /reactwoo/v1/tokens/verify
            → portal status set to connected BEFORE the bridge enroll result
              → signed POST {hub}/wp-json/reactwoo-maint/v1/enroll
                → bridge 0.1.0 creates a local site row
                  → MainWP POST /mainwp/v2/sites/add
                    only if admin AND adminpassword are both present
```

The second chain is not operational end to end: there is no Maintenance product, pretty `/wp-json/` on the dashboard returns 404, and the deployed bridge will not add a site with only a Unique Security ID.

# Subscription Lifecycle

Implemented in `RW_Subscriptions` on GitHub `main` and in the working tree:

| Event | Portal behaviour |
| --- | --- |
| Status becomes `active` | Clears the grace marker and sets portal sites to `connected`, which the bridge maps to **resume** |
| Status becomes `on-hold` | If a grace marker exists, do nothing. Otherwise set sites to **suspended** immediately |
| Status becomes `cancelled` | Clear grace and **suspend** immediately. No check of a prepaid end date |
| Hook `woocommerce_subscription_status_payment_failed` | Sets `_rw_payment_failed_grace` and schedules `rw_portal_suspend_subscription` for **3 days** |
| `pending-cancel` | **No handler.** New enrolment requires `active`, so a pending-cancel subscription cannot connect a new site. Existing sites are left as they are until the status becomes `cancelled` |
| `expired` | **No handler** |
| Reactivation | The `active` handler resumes sites. It does not know whether MainWP still has the child |

WooCommerce Subscriptions 9.3 does not use a `payment_failed` status. The renewal failure action is `woocommerce_subscription_renewal_payment_failed`, which this class does not hook. A failed renewal becomes `on-hold`. Because the grace marker was never set, `handle_on_hold()` suspends at once.

The 14-day purge runs only for bridge rows already marked `disconnected`. Nothing in the cancel or payment-failed path sets `disconnected`. Suspended sites stay suspended. MainWP removal, credential revoke, and commercial-record retention are not connected to subscription end.

Licence sync, by contrast, keeps `pending-cancel` active until the period end. Maintenance and licensing therefore disagree.

Live confirmation of these transitions was **NOT VERIFIED**. The four staging subscriptions are active or empty-pending. A controlled test subscription is required.

# Payment Failure Behaviour

| Expectation | Code | Live |
| --- | --- | --- |
| Three-day grace, site stays up | Constant `GRACE_PERIOD_DAYS = 3` exists | The hook that starts it does not match WooCommerce Subscriptions. **Does not work** on the path Woo actually uses |
| Recovery clears pending suspension | `active` unschedules the grace event | Would work only if the grace event had been scheduled |
| Still unpaid after grace → suspend | `suspend_subscription()` | Not reached by a normal failed renewal |
| Failed renewal must not delete the site | Suspend is not delete | Met, but suspension is immediate rather than after three days |
| Cancel during prepaid time keeps service | Not implemented | Suspends on `cancelled` with no end-date check |
| Final offboarding, 14-day window, then remove MainWP access | Purge of `disconnected` rows after 14 days | Never started by expiry or cancellation |

# MainWP Connection Contract

Live schema from MainWP Dashboard **6.3**, `POST /mainwp/v2/sites/add` (public REST index, 2026-10-16):

The route accepts, among other fields:

- `url` (schema `required: false`, description "Site url")
- `admin` (schema `required: false`, description "Site administrator username")
- `adminpassword` (schema `required: false`, description "Site administrator password")
- `uniqueid` (schema `required: false`, description "Unique security ID")
- `uniqueId` (also advertised, empty description)

WordPress marks every argument `required: false`. That does **not** mean the handler accepts an empty body. Current MainWP documentation for this API says the URL and administrator username are always required, and then:

- `adminpassword` when the child has Password Authentication enabled
- `uniqueid` when Unique Security ID is enabled
- both when both child methods are enabled

The child cannot disable both methods. Password authentication is the child default.

**NOT VERIFIED by a live add.** No site was created or removed. A disposable child with Password Authentication off and Unique Security ID on is the controlled test that would prove `admin` + `uniqueid` is sufficient on this 6.3 build.

### Deployed bridge (0.1.0, GitHub `main`)

`create_site()` returns before the HTTP call unless **both** `admin` and `adminpassword` are non-empty. `uniqueid` is copied onto the query only as an extra. Passwordless onboarding cannot succeed on the deployed plugin.

### Uncommitted working tree (not deployed)

The gate is `admin` and (`adminpassword` or `uniqueid`). That matches the intended correction and is **not** running on `maint.reactwoo.com`.

Exact change still required on the deployed line, after the controlled child test:

- keep `admin` mandatory
- send `uniqueid` when the child uses Unique Security ID
- send `adminpassword` only when that child still requires password authentication
- do not require the customer's normal WordPress password for the automated path

# Support Hours / Credits

Searched `reactwoo-mainwp`, `reactwoo-api-manager`, and `react-license` for support minutes, support hours, support credit, and time credit.

No ledger, no order hook, no simple virtual support product, and no balance exists.

The uncommitted `RW_Maintenance_Entitlements` class can read a `technical_support_minutes` number from product JSON. Its default `support_reset_period` is `billing_cycle`. That would grant minutes once per bill, so an annual Standard plan would not receive 60 minutes each month. That class is not on GitHub `main`, and no product has the JSON. Do not ship the billing-cycle default.

Included minutes must be a monthly grant that does not roll. Purchased packs are not implemented.

# Security Findings

| ID | Severity | Finding |
| --- | --- | --- |
| S1 | P0 | GitHub `.gitignore` is only `*.zip`. The working tree already ignores `.env`, `.env.*`, `*.env`, and keeps `!.env.example`, but that change is **not committed**. |
| S2 | P0, contained | `reactwoo-support-connector/reactwoo-maintenance-access.env` is present locally. `git ls-files` does not track it. `git log --all` has no `.env` or `*.env` path. Credentials do not need rotation for a historical Git leak. They must not be committed. |
| S3 | P0 | `verify_token()` sets portal `status=connected` and stores a site secret **before** bridge enrol, and it still returns success if enrol fails. Present on GitHub `main` and in the working tree. |
| S4 | P1 | Dashboard and store pretty REST are broken (404 and 500). The client hard-codes `wp-json/`. |
| S5 | P2 | Audit payloads reviewed do not write `adminpassword` or `uniqueid`. The sites/add request must still send them to MainWP. Admin setting fields echo stored secrets into password inputs for a logged-in administrator. |
| S6 | P2, uncommitted only | `RW_Credential_Vault` keeps bootstrap secrets in a 10-minute encrypted transient and deletes on read. It is not in the deployed 0.1.0 bridge and is not on GitHub. |

No secret values are included in this document.

# Confirmed Working

- WooCommerce Subscriptions is active on the store. API Manager 2.1.14 is active and maps real products to real licence packages.
- Three staging subscriptions already carry a licence id from `license.reactwoo.com`. Provision and sync for a **single** package per subscription are in production use.
- Licence sync keeps `pending-cancel` entitled until the period end, and it will not overwrite revoked, refunded, or banned rows.
- MainWP Dashboard 6.3 and MainWP Child 6.3 are installed. The v2 route index includes add, check, sync, suspend, unsuspend, disconnect, reconnect, and remove.
- The deployed bridge exposes enrol, suspend, resume, disconnect, check, sync, and reconnect.
- The bridge site table is keyed so a portal site id can map to a MainWP site id, a subscription id, and a user id.
- There is no Maintenance licence package, which is the correct commercial boundary. Do not add one for the service itself.

# Partially Working

- Three-day grace exists in code and is not attached to the WooCommerce renewal-failure action.
- 14-day purge exists only after something else has already set `disconnected`.
- Portal account UI is deployed at 0.2.1 while the bridge it must call is deployed at 0.1.0.
- Atomic Pro has three licence packages (2272–2274) and one parent product package id (2270). Variations do not carry package ids.
- MainWP accepts `uniqueid` in the published schema. The deployed client will not send a request that relies on it.
- Uncommitted V2 orchestrator waits for `management_connected`, an initial check, and an initial sync before it sets `connected`. The old `verify_token()` path beside it still marks connected early.

# Missing

- Maintenance products and `_rw_maintenance_plan_key` (`none`, `essential`, `standard`, `business`).
- A store-owned entitlement resolver used by the portal. The uncommitted JSON blob is not that resolver, and it is not deployed as product data.
- Monthly support allowance, purchased support packs, and an append-only ledger.
- Passwordless onboarding on the **deployed** bridge.
- Subscription end → suspend → 14-day offboarding → disconnect MainWP → revoke connector credentials, while keeping the commercial record.
- Multi-package provisioning for one Woo subscription (`wc_subscription_id` + `package_id`).
- Any proof of live managed-site rows.

# Broken / Blocking

- P0: connected is recorded before MainWP accepts the site.
- P1: deployed `sites/add` requires the WordPress password.
- P1: pretty REST on `maint.reactwoo.com` returns 404 for the exact URL the portal client builds.
- P1: pretty REST on the store returns 500.
- P1: no explicit Maintenance product, so the portal cannot truthfully show Maintenance plans.
- P1: failed renewal suspends immediately.
- P1: one subscription cannot provision two ReactWoo product licences. Required only if a Maintenance plan bundles product licences at launch. Not required for a Maintenance service that includes no ReactWoo plugin licences.

# Repository vs Deployed Drift

| Component | GitHub `main` | Uncommitted tree | Deployed |
| --- | --- | --- | --- |
| Support portal | 0.1.0 | 0.2.1 | store **0.2.1** |
| Maintenance bridge | 0.1.0 | 0.2.0 | dashboard **0.1.0** |
| API Manager | 2.1.14 (`932e6d6`) | local 2.1.15 not pushed | store **2.1.14** |
| react-license | `523ca20` | clean | public catalogue matches the packages above; process version on the server was **NOT VERIFIED** |
| Connector | 0.2.0 | edited, still 0.2.0 | not on the store or the dashboard |

Do not deploy the uncommitted portal further while the dashboard still runs bridge 0.1.0. Do not treat the uncommitted tree as released.

# Implementation status — 2026-10-16, phases 0–5

This section records what changed in source after the audit. It does not replace the evidence above. Staging hosts were checked again the same day and were unchanged:

- `https://reactwoo.com/staging/wp-json/` still returns HTTP 500.
- `https://maint.reactwoo.com/wp-json/` still returns HTTP 404.
- `index.php?rest_route=` still returns HTTP 200 on both hosts.

The portal client now builds maintenance calls with `index.php?rest_route=` by default (`rw_portal_rest_style=query`). HMAC still signs the REST route. Pretty permalinks were not changed on the servers.

Local versions in the working tree, not deployed:

- Support portal 0.3.0
- Maintenance bridge 0.3.0
- API Manager 2.1.16 on top of the local 2.1.15 commit

No Maintenance Woo products were created. No disposable MainWP child was available, so the unique-id add was not run. No subscription was modified.

# Staging install — 2026-10-16

Installed from Git after the unique-id test passed. Production `public_html` API Manager is still header 2.1.14. Backups of the three replaced plugin directories are in `/home/reactwoo/tmp/plugin-backups-20261016/`.

- Bridge 0.3.0 is active on `maint.reactwoo.com`. `rw_maint_db_version` moved from 1.0 to 1.1. The new table is `rw_maint_idempotency`. Existing bridge tables stayed at 0 rows.
- Portal 0.3.0 is active on the staging store. `rw_portal_db_version` was already 1.5, so `dbDelta` did not rewrite it. `rw_managed_sites` stayed at 0 rows. The audit log was not truncated.
- API Manager 2.1.16 is active on the staging store only.

Unique-id add, on a disposable child that was deleted afterwards: password authentication off, unique id length 12, `MainWP_Rest_Sites_Controller::create_item` (the `POST /mainwp/v2/sites/add` handler) with url, admin, and unique id and no admin password. Result: success, MainWP site id 24, no error. The site was then removed (`status=success`, remaining id 0). The child directory, database, database user, and password files were deleted. There is no MainWP bearer in the access file, so this was the controller method rather than an external HTTP call.

Draft staging products, licence package empty, plugin slug empty, hidden, monthly test prices:

- 3634 Essential, £1.00
- 3635 Standard, £2.00
- 3636 Business, £3.00

New subscription 3637 for customer user 18 (`rw-maint-staging`) on Standard. It resolved as Maintenance, 60 minutes, site limit 1, and the entitlement snapshot is stored. Renewal-failure grace was set and a suspend event was scheduled 3 days out. On-hold while that marker existed did not write a suspend audit. Returning to active cleared the grace and the schedule. Pending-cancel with an end after the next payment wrote `subscription_pending_cancel_kept` and did not schedule offboard. The subscription was put back to active, next payment 2026-11-16, no end date, no suspend or offboard schedule. Subscriptions 2297, 1293, and 1203 stayed active. 2293 stayed pending.

Portal-to-bridge `test_connection` returned HTTP 401. Both secret options are set, and they do not match. The secret was not read, copied, or rotated. A full portal enrol was not run. The bridge MainWP URL option is empty; the client falls back to this site's REST root when MainWP is local. Pretty `/wp-json/` was not changed.

# Open Questions Requiring Controlled Testing

1. **MainWP add with Unique Security ID only.** Disposable child, Password Authentication off, Unique Security ID on. `POST /mainwp/v2/sites/add` with `url`, `admin`, and `uniqueid`, and no `adminpassword`. Then remove that test site. Do not use a customer site.
2. **Saved hub URL and HMAC secret.** Read `rw_portal_maint_url` in a staging admin session (do not copy the secret into a ticket). Confirm whether it uses `/wp-json/` and whether a signed `POST /enroll` is accepted by the deployed 0.1.0 bridge.
3. **Bridge schema version.** Read the `rw_maint_db_version` and `rw_portal_db_version` options. Do not run an upgrade as part of the read.
4. **Managed sites.** With the MainWP bearer token, `GET /mainwp/v2/sites` and the bridge `rw_maint_sites` table. Record counts and whether `portal_site_id` is populated. Do not print the token.
5. **Payment failure.** A test subscription only: fail a renewal, confirm status becomes `on-hold`, confirm whether the site suspends the same day, then pay and confirm resume. Do not use subscription 2297, 1293, or 1203.
6. **Pending cancel.** Test subscription: pending-cancel through the prepaid end, then confirm when Maintenance would suspend. Compare with the licence staying active until that end.
7. **Inactive licence packages.** Admin session on the licence server, list inactive rows, confirm no hidden Maintenance package.
8. **Deployed file identity.** Hash the installed portal 0.2.1 against the uncommitted tree before any further staging deploy.
