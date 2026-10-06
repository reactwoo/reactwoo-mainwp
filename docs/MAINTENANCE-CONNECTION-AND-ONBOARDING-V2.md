# ReactWoo Maintenance — Product, Onboarding & Connection V2

**Status:** Design review / implementation planning  
**Repository:** `reactwoo/reactwoo-mainwp`  
**Commerce & customer domain:** `reactwoo.com`  
**Maintenance operations domain:** `maint.reactwoo.com`  
**ReactWoo product licensing authority:** `license.reactwoo.com`

## Figma design source of truth

Review the onboarding design before implementation:

- **Design review group:** https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=398-58
- **Desktop onboarding:** https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=398-67
- **Mobile onboarding:** https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=398-252
- **Approval panel:** https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=398-231
- **Cursor implementation notes:** https://www.figma.com/design/BZFmgpDMSm0OMtnC19lNQ4/Reactwoo?node-id=398-242

The Figma review group is currently marked **AWAITING APPROVAL**. Cursor must not treat visual copy/layout as final until the design status has been approved.

---

# 1. Product objective

ReactWoo Maintenance is a managed WordPress maintenance service, not a plugin the customer has to configure.

The ideal journey is:

```text
Purchase maintenance on reactwoo.com
→ understand plan value and included benefits
→ confirm website
→ Connect WordPress
→ sign into the customer's own WordPress site
→ approve ReactWoo Maintenance
→ ReactWoo performs secure bootstrap
→ maint.reactwoo.com establishes the operational maintenance connection
→ initial checks/monitoring/backups are configured
→ customer sees "Your website is protected"
```

Customers should not normally need to:

- give ReactWoo their normal WordPress password;
- create a permanent ReactWoo WordPress Administrator;
- manually install the maintenance management plugin;
- manually install the ReactWoo connector;
- copy enrolment tokens;
- understand internal maintenance infrastructure;
- configure API keys;
- email credentials.

Temporary WordPress access is a fallback, not the default.

---

# 2. Non-negotiable domain responsibilities

## reactwoo.com

`reactwoo.com` is the commercial and customer-facing source of truth.

It owns:

- WooCommerce sale;
- WooCommerce Subscriptions;
- customer account;
- maintenance product/plan;
- entitlement to maintenance;
- allowed site count;
- support allowance;
- customer-facing onboarding;
- customer-facing maintenance dashboard;
- connection state shown to the customer;
- onboarding email/reminders;
- customer reporting preferences;
- signed server-to-server requests to `maint.reactwoo.com`.

The existing `reactwoo-support-portal` plugin belongs here.

Do **not** move subscription authority to `maint.reactwoo.com`.

## maint.reactwoo.com

`maint.reactwoo.com` is the operational maintenance platform.

It owns:

- managed WordPress site connection;
- maintenance operations;
- WordPress/plugin/theme/core update execution;
- backup operations;
- monitoring;
- operational site checks;
- operational reporting data;
- internal site IDs;
- operational audit logs;
- suspend/resume/disconnect;
- maintenance automation.

The existing `reactwoo-maint-bridge` plugin belongs here.

It must only trust commercial/lifecycle instructions that are signed by `reactwoo.com`.

Customer-facing copy must never mention the underlying management platform.

## license.reactwoo.com

`license.reactwoo.com` remains the **only licensing authority for ReactWoo-owned products**.

Do not create any new ReactWoo product licence registry, entitlement issuer, activation server, revocation server or provisioning server inside:

- `reactwoo-mainwp`;
- `reactwoo-support-portal`;
- `reactwoo-maint-bridge`;
- `maint.reactwoo.com`.

If a maintenance plan includes entitlement to ReactWoo products, the maintenance system must call/reuse the existing licence service on `license.reactwoo.com`.

Cursor must audit the existing licence API/contract before integrating. Do not invent replacement endpoints when an existing licensing contract already exists.

### Important distinction: ReactWoo licences vs third-party premium tools

ReactWoo-owned product licences are provisioned and governed by `license.reactwoo.com`.

Third-party premium WordPress tools included as part of a maintenance service are **maintenance entitlements**, not a reason to create a second licence server. Their activation must use the existing approved vendor/agency licensing mechanism. The maintenance platform may track whether an included tool is eligible/active, but it must not become an independent licensing authority.

---

# 3. Existing repository architecture to preserve

The repository currently contains:

```text
reactwoo-support-portal
reactwoo-support-connector
reactwoo-maint-bridge
```

Useful existing behaviour includes:

- WooCommerce subscription validation;
- user ownership checks;
- allowed-site handling;
- managed-site records;
- enrolment tokens;
- customer identity sync;
- subscription lifecycle hooks;
- payment-failure grace period;
- signed portal → maintenance requests;
- maintenance audit logging;
- connector site secret;
- signed heartbeats;
- MainWP REST client;
- maintenance check/sync/reconnect actions;
- tagging/grouping;
- disconnect/purge lifecycle.

Do not rewrite these parts without need.

---

# 4. Known blocker in the current implementation

The current operational site creation path requires:

```text
admin
adminpassword
```

before creating a managed site.

The current connector enrolment does not supply the customer's WordPress Administrator password.

This means ReactWoo can reach an apparently enrolled state while the operational maintenance connection was never actually created.

The current behaviour that effectively produces:

```text
mainwp_create_skipped
reason: missing_credentials
```

must be corrected.

The preferred automated operational connection must support:

```text
administrator username
+
Unique Security ID
```

without requiring the customer's normal WordPress password.

Password-based operational connection may remain as a manual recovery option.

---

# 5. Customer-facing connection state model

Do not use one generic status for both subscription lifecycle and onboarding.

Add a dedicated connection/provisioning state.

Recommended internal values:

```text
not_started
awaiting_site
awaiting_authorisation
authorising
installing
bootstrapping
connecting_maintenance
verifying
connected
action_required
failed
```

Separate service lifecycle:

```text
active
suspended
disconnected
```

Customer labels should be simple:

```text
Setup required
Connecting
Connected
Needs attention
Paused
Disconnected
```

A site may only be shown as **Connected** after the operational maintenance connection has been verified.

---

# 6. Definition of Connected

Do not mark a site Connected after enrolment-token verification alone.

Required minimum:

1. WordPress site authorisation succeeds.
2. Authorised WordPress user has required capabilities.
3. ReactWoo connector is active and securely enrolled.
4. Operational maintenance child component is installed/active.
5. Passwordless management identity is configured.
6. `maint.reactwoo.com` accepts the signed enrolment.
7. Operational site record is created/reused.
8. Operational platform returns a valid site ID.
9. Initial connection check succeeds.
10. Initial sync succeeds.
11. ReactWoo bootstrap Application Password is revoked.

Optional service setup may complete immediately afterwards without blocking Connected if the long-lived maintenance connection itself is healthy.

---

# 7. Onboarding UX — source of truth

Implement the approved Figma direction.

Primary user goal:

> Understand what was purchased and securely connect the website.

The onboarding view must not look like an administrator table.

## Hero

Customer-facing intent:

```text
ReactWoo Maintenance
Your website, looked after.

We keep WordPress updated, backed up and monitored so you can
focus on running your business — not maintaining your website.

[ Connect WordPress ]

No WordPress password required.
```

If the website is already known, show it and avoid asking again.

## Benefits

The design currently communicates these six core service benefits:

### Managed WordPress updates

WordPress core, themes and supported plugins are kept current, with maintenance activity tracked.

### Supported premium licences

Selected premium WordPress tools are included while the eligible maintenance plan is active, reducing separate annual licence overhead.

Do not promise every paid plugin.

Exact supported tools must be entitlement/configuration driven.

### Cloud backups

Off-site recovery points should be available so ReactWoo can recover the site after update failure or unexpected changes.

Backup frequency must come from plan entitlement. Do not hard-code "daily" unless that is the contracted plan.

### Monitoring

Where enabled by plan/connectors, include:

- uptime;
- SSL certificate health;
- domain expiry;
- WordPress/site health;
- connection failure awareness.

Do not mention the underlying monitoring vendor/platform.

### Security awareness

Monitor known vulnerabilities/security signals to prioritise maintenance.

Do not promise that maintenance makes a site impossible to compromise.

### Reporting

Provide clear ReactWoo-branded maintenance reporting covering relevant plan services such as:

- WordPress updates;
- plugin/theme updates;
- backups;
- availability;
- SSL;
- security;
- maintenance activity;
- performance where enabled;
- support activity where appropriate.

Customer reports must be outcome-oriented rather than raw technical logs.

---

# 8. Standard plan technical support

The design assumes the Standard plan includes:

```text
60 technical-support minutes per billing period
```

This must be stored as product entitlement, not hard-coded into presentation logic.

Suggested entitlement:

```json
{
  "technical_support_minutes": 60,
  "support_reset_period": "billing_cycle",
  "support_rollover": false
}
```

The maintenance dashboard should later be able to expose:

```text
Technical support
42 of 60 minutes remaining
```

If work exceeds included allowance, customer messaging should confirm additional work before billing.

---

# 9. Product entitlement resolver

Do not rely on missing subscription metadata meaning "Unlimited".

Create a single maintenance entitlement resolver on `reactwoo.com`.

Suggested service:

`RW_Maintenance_Entitlements`

Input:

- subscription;
- product/variation;
- customer.

Output concept:

```json
{
  "is_maintenance": true,
  "plan_key": "standard",
  "site_limit": 1,
  "backup_frequency": "configured-plan-value",
  "reports": true,
  "uptime_monitoring": true,
  "ssl_monitoring": true,
  "domain_monitoring": true,
  "vulnerability_monitoring": true,
  "performance_monitoring": false,
  "technical_support_minutes": 60,
  "reactwoo_product_entitlements": [],
  "third_party_premium_entitlements": []
}
```

Explicitly represent unlimited plans. Never infer unlimited from a missing value.

Snapshot contractual entitlements onto the subscription when appropriate so later product edits do not silently alter existing customer contracts.

---

# 10. ReactWoo product licensing integration

If the maintenance plan includes one or more ReactWoo products:

1. `reactwoo.com` resolves the maintenance entitlement.
2. It calls the **existing** licensing service at `license.reactwoo.com`.
3. `license.reactwoo.com` issues/updates/revokes the ReactWoo product licence using its established data model and API.
4. Maintenance stores only non-authoritative references/status required for display/operations.
5. Subscription downgrade/cancellation triggers the existing licence-service lifecycle rather than deleting licences locally.

Do not reproduce:

- licence keys;
- activation tables;
- product entitlement logic already owned by the licence service;
- validation endpoints;
- revocation logic.

The Cursor audit phase must identify the existing licence integration code and document the exact reusable contract before implementation.

---

# 11. Third-party premium plugin inclusion

Third-party premium plugins are separate from ReactWoo-owned product licensing.

Maintain a service catalogue that answers:

- Is this tool included in this maintenance plan?
- Is ReactWoo authorised to apply its agency/vendor licence?
- Is the customer's own licence already active?
- Should ReactWoo leave the customer's licence untouched?

Rules:

- preserve an existing customer-owned licence unless explicitly migrated;
- never remove plugin content/data when maintenance ends;
- do not promise ownership transfer;
- ReactWoo-managed third-party licensing is included only while the plan/contract permits it;
- do not create a standalone third-party licence server in this repository.

Customer-visible catalogue should come from plan/service configuration, not hard-coded page markup.

---

# 12. WordPress authorisation flow

Preferred bootstrap uses native WordPress Application Password authorisation.

## Start

Customer clicks **Connect WordPress** on `reactwoo.com`.

Create a connection attempt containing:

```text
public_id
managed_site_id
user_id
subscription_id
expected_site_url
state_hash
status
attempt_count
expires_at
created_at
updated_at
completed_at
```

Default state validity: 10 minutes.

Store only a hash of the state value.

## Site discovery

Safely discover the customer WordPress REST root and Application Password authorisation endpoint.

Do not guess an auth endpoint if REST discovery provides it.

## Redirect

Redirect the customer to their WordPress authorisation flow.

Use an app name such as:

`ReactWoo Maintenance`

Callback returns to `reactwoo.com`, not `maint.reactwoo.com`.

## Callback validation

Validate:

- state exists;
- state unused;
- state not expired;
- customer still owns subscription;
- subscription is active/eligible;
- site allowance still permits the site;
- callback site matches expected canonical WordPress site;
- HTTPS remains in use.

Consume state atomically.

---

# 13. Application Password handling

Application Password is a short-lived bootstrap credential only.

Never write it to:

- normal WordPress options;
- audit logs;
- PHP error logs;
- analytics;
- long-lived ReactWoo records;
- `maint.reactwoo.com`;
- MainWP credentials.

Use it immediately to:

1. verify `/wp/v2/users/me`;
2. validate capabilities;
3. install/activate required bootstrap plugins where permitted;
4. call the authenticated ReactWoo connector bootstrap;
5. introspect the exact Application Password UUID;
6. revoke the credential once long-lived maintenance connection is verified.

If asynchronous persistence becomes necessary, use a short-lived encrypted credential vault. Never plaintext transients.

---

# 14. SSRF and redirect safety

Customer-supplied site URLs cause server-side outbound requests.

P0 protections:

- HTTPS only for automated bootstrap;
- reject localhost/loopback;
- reject RFC1918/private IP ranges;
- reject link-local;
- reject cloud metadata endpoints;
- resolve DNS safely;
- revalidate each redirect;
- prevent HTTPS downgrade;
- cap redirect depth;
- require the final target to remain an allowed public host.

Canonical URL handling must support:

- trailing slash differences;
- `www`;
- redirects;
- WordPress subdirectory installations.

Do not automatically treat unrelated subdomains as the same site.

---

# 15. Automatic plugin bootstrap

Where WordPress permissions allow, install/activate:

- ReactWoo connector;
- required maintenance-management child component.

Do not require the customer to manually upload ZIP files in the normal journey.

If ReactWoo connector cannot yet be remotely installed through an approved distribution path, treat that as a release gate rather than claiming zero-touch onboarding is complete.

Do not build an unauthenticated general-purpose plugin installer.

---

# 16. ReactWoo connector V2

Refactor the current connector into the customer-site bootstrap/heartbeat agent.

Keep backwards compatibility with V1 connected sites.

Recommended routes:

```text
reactwoo-connector/v2/bootstrap
reactwoo-connector/v2/status
reactwoo-connector/v2/heartbeat
```

`/bootstrap` must require:

- authenticated WordPress administrator/capability;
- valid one-time ReactWoo enrolment challenge.

Do not use `permission_callback => __return_true` for the new bootstrap route.

Responsibilities:

1. verify ReactWoo enrolment;
2. verify required child component;
3. detect an existing conflicting maintenance connection;
4. configure passwordless management connection;
5. return admin username + Unique Security ID;
6. establish/store connector site secret;
7. report protocol/version/capabilities.

Never return the customer's normal WordPress password.

---

# 17. Existing management connection safety

Do not silently overwrite a site already connected to another maintenance provider/dashboard.

If a conflicting connection exists and safe coexistence cannot be proven:

```text
connection_state = action_required
```

Customer message:

> This website already has an existing maintenance connection. We need to check it before ReactWoo can finish setup.

Offer the manual support fallback.

---

# 18. reactwoo.com → maint.reactwoo.com enrolment

After customer-site bootstrap, `reactwoo.com` sends a signed request containing only what the operational maintenance platform needs.

Conceptual payload:

```json
{
  "portal_site_id": 123,
  "subscription_id": 456,
  "user_id": 789,
  "site_url": "https://example.com",
  "site_name": "example.com",
  "client": {
    "client_name": "...",
    "client_email": "...",
    "client_company": "...",
    "client_phone": "...",
    "client_locale": "...",
    "report_email": "...",
    "report_enabled": 1
  },
  "connection": {
    "admin": "admin-user",
    "uniqueid": "...",
    "auth_mode": "unique_id"
  },
  "maintenance_entitlements": {
    "backup_frequency": "...",
    "reports": true,
    "uptime_monitoring": true,
    "ssl_monitoring": true,
    "domain_monitoring": true,
    "vulnerability_monitoring": true
  }
}
```

Do not send:

- WordPress normal password;
- Application Password;
- ReactWoo product licence keys.

---

# 19. MainWP client fix

Update `RW_Maint_MainWP_Client::create_site()`.

Current conceptual block:

```php
if (
    empty( $credentials['admin'] ) ||
    empty( $credentials['adminpassword'] )
) {
    return;
}
```

New logic:

```php
if ( empty( $credentials['admin'] ) ) {
    return;
}

if (
    empty( $credentials['adminpassword'] ) &&
    empty( $credentials['uniqueid'] )
) {
    return;
}
```

Preferred automatic method:

```text
admin username + Unique Security ID
```

Manual fallback:

```text
admin username + password
```

Cursor must verify the exact current MainWP REST request and current child-plugin settings/API before implementing. Do not assume old option names are still correct.

Create an adapter around child-plugin-specific configuration rather than scattering internal MainWP settings through ReactWoo code.

---

# 20. Server-to-server security

Preserve and harden the existing HMAC design.

Every privileged `reactwoo.com → maint.reactwoo.com` request should carry:

```text
X-RW-Timestamp
X-RW-Nonce
X-RW-Signature
X-RW-Path
X-RW-Idempotency-Key
```

Continue:

- request body SHA-256;
- method/path binding;
- `hash_equals`;
- replay protection;
- short clock skew;
- HTTPS.

Prefer secrets supplied through environment/wp-config configuration instead of only editable WordPress settings.

Do not commit production secrets.

---

# 21. Idempotent operational enrolment

`maint.reactwoo.com` enrolment must:

1. verify HMAC;
2. verify nonce/timestamp;
3. verify idempotency key;
4. resolve existing record by `portal_site_id`;
5. reuse an existing operational site if already created;
6. create only if absent;
7. preserve `mainwp_site_id` across retries;
8. run initial check;
9. run initial sync;
10. return explicit status.

Example response:

```json
{
  "maint_site_id": 123,
  "mainwp_site_id": 456,
  "management_connected": true,
  "initial_check": true,
  "initial_sync": true,
  "status": "active"
}
```

Never create duplicate operational sites because a browser was refreshed or a network call retried.

---

# 22. Onboarding progress UI

After returning from WordPress, show truthful progress based on real server state.

Figma direction:

```text
Connecting example.com

✓ WordPress authorised
✓ ReactWoo connected
● Setting up maintenance
○ Preparing backups
○ Running initial checks
```

Do not fake percentages.

Refreshing/revisiting must resume the same connection attempt.

Recommended customer states:

- setup required;
- connecting;
- connected;
- action required.

---

# 23. Successful customer state

Customer-facing success:

```text
Your website is protected

example.com is now connected to ReactWoo Maintenance.

Site status       Connected
Backups           Ready
Monitoring        Active
Maintenance       Active
```

Avoid internal IDs or platform terminology.

---

# 24. Ongoing maintenance dashboard

After onboarding, `/my-account/maintenance/` should show an understandable product dashboard rather than the current operational table.

Recommended hierarchy:

```text
Website
example.com
Connected ✓

Overall status
Good

Maintenance
Up to date / updates pending

Backups
Last successful backup
Next scheduled backup

Monitoring
Online
SSL healthy
Domain status

Security
No known issues / Needs attention

Technical support
Minutes available

Latest report
[View report]

Recent activity
...
```

Customer actions:

- View report;
- Request support;
- Manage report preferences;
- Reconnect;
- Disconnect.

Internal operational controls such as forced sync/check and technical URL overrides should not be shown to normal customers.

---

# 25. Temporary-access fallback

If automatic setup cannot complete:

> Let ReactWoo connect it for you

A temporary admin-login mechanism may be offered.

Treat the temporary link as a credential:

- encrypt if stored;
- never log it;
- never send to analytics;
- hide after receipt;
- delete after use/expiry.

Staff complete the setup manually, verify the long-lived maintenance connection, then revoke/remove temporary access.

Do not create a permanent ReactWoo Administrator by default.

---

# 26. Emails

## Welcome

Subject concept:

> Your ReactWoo Maintenance plan is ready

Primary CTA:

> Connect your website

Email should reinforce:

- managed updates;
- cloud backups;
- monitoring;
- reporting;
- supported premium licences where included;
- technical support allowance.

Do not send a technical setup guide.

## Reminder

If setup remains incomplete, send limited reminders such as 24 hours and 3 days.

Cancel reminders once connected.

## Completion

> Your website is now connected

Confirm website, plan and active service areas.

## Action required

If automated setup failed:

> Finish connecting your website

Return customer to the relevant connection state.

---

# 27. Accessibility & responsive implementation

Implement the approved Figma direction to WCAG 2.2 AA expectations.

Requirements:

- keyboard accessible;
- visible focus states;
- real button/link semantics;
- 44px-ish touch targets where practical;
- status not communicated by colour only;
- errors associated with the affected input/action;
- responsive typography;
- no horizontally scrolling customer account tables;
- mobile actions stacked;
- reduced-motion support.

Desktop reference: Figma node `398:67`.

Mobile reference: Figma node `398:252`.

---

# 28. Analytics

Permitted funnel events:

```text
maintenance_onboarding_viewed
maintenance_connect_started
maintenance_wp_discovery_success
maintenance_wp_discovery_failed
maintenance_authorisation_started
maintenance_authorisation_approved
maintenance_authorisation_rejected
maintenance_bootstrap_started
maintenance_bootstrap_failed
maintenance_management_connected
maintenance_setup_completed
maintenance_manual_setup_selected
maintenance_setup_abandoned
```

Never send:

- Application Password;
- HMAC secret;
- Unique Security ID;
- temporary login URL;
- product licence key;
- raw sensitive callback parameters.

---

# 29. Audit/diagnostics

Create an internal provisioning timeline with a correlation ID.

Example:

```text
purchase entitlement active
connection started
WordPress discovered
authorisation approved
admin capabilities verified
connector active
maintenance child active
passwordless identity created
operational site created/reused
initial check passed
initial sync passed
bootstrap credential revoked
connection completed
```

Every failure should expose:

- stage;
- safe error code;
- retryability;
- recovery action.

Do not log secrets.

---

# 30. Suggested new/refactored services

## reactwoo-support-portal

```text
class-rw-maintenance-entitlements.php
class-rw-connection-attempts.php
class-rw-connection-orchestrator.php
class-rw-wordpress-client.php
class-rw-application-password-auth.php
class-rw-maintenance-emails.php
class-rw-temporary-access.php
class-rw-site-url.php
class-rw-license-client.php   // thin adapter to existing license.reactwoo.com only
```

The licence client must not contain licence issuance logic. It is a client to the existing licence service.

## customer connector

```text
class-rw-connector-bootstrap.php
class-rw-mainwp-child-adapter.php
class-rw-connector-signing.php
class-rw-connector-heartbeat.php
```

## maintenance bridge

```text
class-rw-maint-enrolment-service.php
class-rw-maint-mainwp-client.php
class-rw-maint-security.php
class-rw-maint-sites.php
```

Keep REST controllers thin.

---

# 31. Database/migration requirements

Use versioned migrations rather than activation-hook-only schema changes.

Add connection attempt storage and connection-state fields.

Suggested managed-site additions:

```text
connection_state
connection_method
connected_at
last_connection_attempt_at
connection_error_code
connection_error_message
connector_protocol
mainwp_child_version
last_bootstrap_at
```

Do not store raw bootstrap credentials.

Existing connected sites must continue functioning.

---

# 32. Backwards compatibility

Do not break V1 connector installations.

V1 may continue:

- heartbeat;
- existing site secret;
- existing operational relationship.

New onboarding defaults to V2.

Migration to V2 must be gradual and non-destructive.

---

# 33. Testing requirements

At minimum add tests for:

## Entitlement

- active subscription;
- inactive subscription;
- wrong owner;
- site limit;
- missing entitlement fails safely;
- explicit unlimited plan;
- support allowance;
- ReactWoo product licence entitlement routes to existing licence service rather than local provisioning.

## Connection state

- valid state;
- expired state;
- replayed state;
- wrong user;
- wrong domain;
- duplicate callback.

## URL / SSRF

- localhost;
- private IPv4/IPv6;
- metadata endpoint;
- redirect to private address;
- HTTPS downgrade;
- valid canonical redirect;
- subdirectory WordPress.

## WordPress bootstrap

- app passwords enabled/disabled;
- insufficient capability;
- plugin installation allowed/blocked;
- existing connector;
- existing child component;
- conflicting maintenance connection.

## Operational enrolment

- admin + Unique ID;
- password fallback;
- missing both;
- duplicate enrol request;
- existing operational ID;
- initial check failure;
- sync failure.

## Credential safety

- Application Password revoked after success;
- removed after terminal failure;
- never logged;
- abandoned encrypted credentials cleaned.

## Subscription lifecycle

- active;
- on-hold;
- failed payment grace;
- payment recovery;
- cancellation;
- resume;
- disconnect;
- purge.

---

# 34. Implementation phases

## Phase 0 — audit

Create:

`docs/MAINTENANCE-CONNECTION-CURRENT-STATE.md`

Document exact current:

- DB tables;
- routes;
- lifecycle hooks;
- connector flow;
- MainWP request shapes;
- `license.reactwoo.com` integration/code;
- status transitions;
- known security constraints.

Then create:

`docs/MAINTENANCE-CONNECTION-IMPLEMENTATION-PLAN.md`

Do not start feature implementation until the audit identifies what should be reused.

## Phase 1 — entitlements/state/security foundations

- explicit plan entitlement resolver;
- connection state model;
- connection attempts;
- migrations;
- URL canonicalisation;
- SSRF-safe WordPress discovery;
- idempotency.

## Phase 2 — WordPress authorisation

- Application Password discovery;
- connection start;
- callback;
- state validation;
- admin validation;
- password introspection.

## Phase 3 — remote bootstrap

- connector installation/activation;
- child component installation/activation;
- authenticated V2 bootstrap;
- existing-connection safety;
- Unique Security ID.

## Phase 4 — operational maintenance connection

- signed enrolment;
- passwordless MainWP add;
- idempotency;
- initial check/sync;
- status propagation.

## Phase 5 — bootstrap credential cleanup

- exact credential revocation;
- limited retry;
- security warning on revoke failure.

## Phase 6 — customer UX

Implement approved Figma:

- product-value onboarding;
- responsive desktop/mobile;
- setup states;
- progress;
- success;
- action required;
- ongoing maintenance dashboard.

## Phase 7 — licensing integration

- audit existing `license.reactwoo.com` contract;
- add thin client only where needed;
- maintenance bundle requests eligible ReactWoo product entitlements from the existing service;
- no new licence provisioning database/server.

## Phase 8 — manual fallback/emails

- temporary access;
- welcome/reminder/completion/action-required email.

## Phase 9 — tests/rollout

- automated tests;
- staging;
- controlled live test;
- cohort rollout;
- V1 compatibility monitoring.

---

# 35. Cursor implementation rules

Cursor must:

- pull this spec before implementation;
- inspect the Figma source-of-truth nodes;
- wait for design approval before treating copy/layout as final;
- preserve existing working behaviour;
- use `reactwoo.com` as commercial authority;
- use `maint.reactwoo.com` as operational authority;
- use `license.reactwoo.com` as ReactWoo product licensing authority;
- never create a replacement licence server;
- never ask for/store the customer's normal WordPress password in the standard flow;
- never mark Connected before operational verification;
- never silently overwrite an existing maintenance connection;
- never expose internal platform terminology to the customer;
- make provisioning idempotent;
- protect all site-discovery calls against SSRF;
- preserve backwards compatibility during V2 rollout.

---

# 36. Definition of Done

The implementation is complete only when this works end to end:

1. Customer purchases maintenance on `reactwoo.com`.
2. Correct maintenance plan entitlement is created.
3. Any included ReactWoo product entitlement is delegated to the existing `license.reactwoo.com` service.
4. Customer opens the maintenance onboarding page and understands the value of the plan.
5. Customer confirms the site.
6. Customer clicks **Connect WordPress**.
7. Customer authorises ReactWoo through their own WordPress site.
8. ReactWoo validates admin capability.
9. Required customer-site components are installed/activated.
10. ReactWoo establishes the secure connector.
11. Passwordless management identity is configured.
12. `reactwoo.com` sends signed operational enrolment to `maint.reactwoo.com`.
13. The operational site is created/reused.
14. Initial connection check succeeds.
15. Initial sync succeeds.
16. Bootstrap Application Password is revoked.
17. Customer sees **Your website is protected**.
18. Customer can see maintenance/backups/monitoring/reporting/support in plain language.
19. Subscription lifecycle correctly suspends/resumes operational maintenance.
20. No duplicated licensing authority or provisioning server has been introduced.

