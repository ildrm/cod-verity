=== CODVerity — Cash on Delivery Order Verification for WooCommerce ===
Contributors: codverity
Tags: cash on delivery, order confirmation, woocommerce, cod verification
Requires at least: 6.6
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure COD verification and order confirmation for WooCommerce, with email requests, safe holds, reminders, and a merchant review queue.

== Description ==

CODVerity provides post-checkout Cash on Delivery order verification. Customers confirm or cancel through a secure page. GET requests never resolve orders. Confirmation records intent, not payment or guaranteed delivery.

* Transactional WordPress email with editable templates.
* Native COD gateway integration, HPOS and legacy CRUD.
* Configurable eligibility, safe holds, reminders and expiration.
* Bounded email retries and cooldown-protected link reissue.
* Manual actions, advisory duplicate warnings, reports and CSV export.
* Audit history, diagnostics, retention and WordPress privacy tools.

WooCommerce 9.5+ and PHP 8.2+ are required. Automation and expiry cancellation are disabled initially. No hosted backend or paid email provider is required. Use HTTPS and reliable cron. Activate separately on each multisite store.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the CODVerity ZIP through Plugins → Add New → Upload Plugin.
3. Activate CODVerity and visit WooCommerce → CODVerity.
4. Review Settings and Diagnostics; configure mail and send a test.
5. Enable new automation and verify a staging COD order end to end.

== Frequently Asked Questions ==

= Does confirmation mean payment was received? =
No. COD payment remains due on delivery.

= Will opening an email link confirm an order? =
No. The customer must submit a confirmation or cancellation form.

= Are unresponsive orders automatically cancelled? =
No by default. Expiry cancellation is an explicit opt-in, limited to eligible holds owned by CODVerity.

= What happens when I disable or deactivate it? =
Disabling new automation lets existing workflows finish. Deactivation stops plugin jobs and preserves orders, settings, and history. Held orders require merchant attention. Reactivation reconciles active workflows.

= Does an accepted email mean delivered? =
No. Acceptance reports only the configured WordPress mail transport's return value.

== Changelog ==

= 1.0.0 =
* Initial COD order verification workflow and merchant administration.
