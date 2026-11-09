=== ReactWoo Support Portal ===
Stable tag: 0.5.10
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
License: GPLv2 or later

Customer maintenance account, support minutes, and the website each plan or pack covers.

== Changelog ==

= 0.5.10 =
* An admin who can manage WooCommerce can assign or change the website for purchased support minutes that have no site, from the Managed Sites queue.
* The same screen, and the subscription admin screen, can set the maintenance plan website stored as _rw_service_domain.
* Assignment uses the checkout website check, a nonce, and a private note on the order or subscription. Minute totals are not changed.

= 0.5.9 =
* Additional Support can be purchased and used only on the website stored for that customer's maintenance plan.
* Technical Support stays on the website stored on its own order line.
* Purchased minutes with no website are kept and flagged for assignment instead of being spent or removed.

= 0.5.8 =
* The Maintenance account is shown only for an entitled plan, and Additional Support in the cart requires an active or pending-cancel plan.
* A saved card starts checkout and is not charged unless that charge is explicitly enabled.
