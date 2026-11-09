=== ReactWoo Support Connector ===
Stable tag: 0.5.12
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
License: GPLv2 or later

Connects a WordPress website to ReactWoo Maintenance. Updates are delivered from the ReactWoo catalog.

== Changelog ==

= 0.5.12 =
* Heartbeats are signed with the REST route and sent through index.php, so they match the portal.
* The old connect route now needs an administrator, a ReactWoo portal address, and a site that is not already enrolled.
* Backup now saves an on-site snapshot of plugins, themes and the database, and returns a backup reference.
* Security scan starts Wordfence when it is active, and says so when it is not.

= 0.5.11 =
* Version realigned with update catalog; no functional changes.

= 0.3.1 =
* Recognise the ReactWoo dashboard by host, so sites whose MainWP Child stored the /wp-admin/ server URL re-enrol instead of being blocked.
* Re-enrol only when the stored MainWP site still matches, and ignore leftover Child options unless the plugin is active.

= 0.3.0 =
* Install the pinned maintenance components from a signed request after enrolment.
* Hide those components on managed sites, with a bypass for ReactWoo support users.

= 0.2.1 =
* Create the passwordless connection id during setup and turn off password authentication for that administrator.

= 0.2.0 =
* Passwordless maintenance connection and catalog updates.
