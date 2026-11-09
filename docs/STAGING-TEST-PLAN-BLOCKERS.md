# Staging test plan: maintenance blockers

Do not run this against production. Use a staging child site enrolled to the staging portal and the staging MainWP hub. Deploy the connector, portal, and bridge from the same pull request together. The connector publish workflow reads the plugin header and publishes only when that version is higher than the catalog.

## Before you start

- Note the child site's connector version, the portal version, and the bridge version.
- Confirm the child is enrolled and a heartbeat has been received after the new connector is installed.
- Wordfence does not have to be active for the failure check. Install it only for the success check.
- The snapshot is stored on the child under `wp-content/reactwoo-private/snapshots/`. Apache is denied by `.htaccess`. If the child uses nginx, confirm a direct request for a snapshot file returns 403 before treating the file as private.

## Heartbeat

1. On a site still running a connector older than 0.5.12, wait for the hourly heartbeat or trigger it from the connector's signed heartbeat route.
2. The portal should show a last-seen time. The old connector signs `/wp-json/...`, including a directory prefix such as `/staging`.
3. Update the connector to 0.5.12 and trigger another heartbeat.
4. Last seen should move again. The new request goes to `index.php?rest_route=/reactwoo/v1/sites/heartbeat`.

## Connect route

1. While logged out, POST to `/wp-json/reactwoo-connector/v1/connect` with any token and portal URL. Expect 401 or 403, and the stored secret must be unchanged.
2. As an administrator, repeat the call against a site that is already enrolled. Expect 409. The secret must be unchanged.
3. Enrol a fresh staging site with the normal connector setup (administrator plus enrolment challenge). That path must still finish and start heartbeats.

## Dashboard buttons

Use the maintenance account for the staging site. Each button still asks for the confirmation tick. After three uses in 15 minutes, the rate-limit message appears on that action card and the action does not run.

1. **Request backup.** Expect a success notice that includes a `rwsnap_...` reference, not a claim that an offsite backup finished. On the child, the zip should contain `manifest.json`, `database.sql`, `plugins/`, and `themes/`. A second request should replace nothing older than the three newest archives.
2. **Security scan, Wordfence inactive.** Expect a failure that says Wordfence is not active and that a scan was not started.
3. **Security scan, Wordfence active.** Expect a notice that a scan has started, or that one is already running. Do not expect scan results in the same response.
4. **Run pending updates.** Expect a notice that updates have been started and that completion has not been confirmed. In MainWP, the call is `POST /mainwp/v2/updates/{site id}/update`. A suspended site should fail with the hub message, and the portal should not say the updates ran.

## What this does not prove

- The snapshot is on the child disk. It is not an offsite copy, and this plan does not restore it.
- MainWP's own daily automatic updates are not gated by this pull request.
- Nginx will still serve the snapshot directory unless the server is told to deny it.
