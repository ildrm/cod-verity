# CODVerity

Cash on Delivery Order Verification for WooCommerce, version 1.0.0.

CODVerity emails customers a secure confirmation page after eligible COD checkout. It can hold the order until the customer responds, tracks unresolved requests, and gives store staff a verification queue. Confirmation records customer intent; it never records payment or guarantees delivery.

Features include native COD gateway integration, Classic/Block gateway support, HPOS and legacy order CRUD, decimal/category eligibility, generation-based secure links, POST-only decisions, bounded transport retries, two configurable reminders, conservative expiration, optional expiry cancellation, manual interventions, advisory duplicate warnings, reports, CSV export, audit history, diagnostics, privacy tools, and retention controls.

Requires WordPress 6.6+, WooCommerce 9.5+, PHP 8.2+, and MySQL/MariaDB with connection-owned named locks. Use HTTPS and a working WordPress mail transport. No commercial notification service is required. Multisite stores must activate separately per site.

Install the ZIP from **Plugins → Add New → Upload Plugin**, activate WooCommerce and CODVerity, then open **WooCommerce → CODVerity → Settings**. New automation is disabled initially. Configure your email transport, send a test message, inspect Diagnostics, then enable automation. Existing orders are never silently enrolled.

Eligible COD orders normally headed for processing are placed on hold when holding is enabled. A queued email links to a page requiring a deliberate confirm/cancel POST. Confirmation releases only holds owned by CODVerity; native gateway holds remain held. Cancellation uses normal WooCommerce status and stock handling. Expired orders remain available for merchant review by default.

Use the queue or the order's CODVerity panel to test eligibility, start verification, reissue a link, manually confirm/cancel, or stop verification. Changes require an explicit acknowledgment and a reason. Reissuing invalidates prior links and respects a cooldown. Disabling new automation allows existing requests to finish. Deactivation leaves order statuses and stock intact; inspect held orders before fulfillment.

Transport acceptance does not prove inbox delivery. Review notification failures, interrupted workflows, expired requests, and advisory duplicate warnings regularly. Run reliable server cron in production. See [troubleshooting](docs/troubleshooting.md) for delayed jobs, mail problems, expired links, migrations, and held orders.

Development setup:

```sh
composer install
php tools/fetch-stubs.php
composer lint
composer cs
composer analyse
composer test -- --testsuite unit
```

Integration tests must use a disposable database and an activated WordPress/WooCommerce store. Set `CODVERITY_WP_ROOT` to that WordPress directory. Run `vendor/bin/phpunit --testsuite integration`; `tests/http.php` additionally needs the local site reachable over HTTP. `tests/concurrency.php` races independent PHP workers. [Testing instructions and actual results](docs/testing.md) distinguish executed tests from manual acceptance checks.

`php tools/package.php` builds a ZIP with one correctly named plugin directory. Runtime uses a small included autoloader and native WordPress/WooCommerce facilities; Composer dependencies are development-only and excluded from the archive. CI checks PHP 8.2–8.5, both datastores, real HTTP flows, race tests, and packaging. CI configuration is supplied; hosted CI results are separate from local results.

Read [architecture](docs/architecture.md), [state machine](docs/state-machine.md), [configuration](docs/configuration.md), [security](docs/security.md), [privacy](docs/privacy.md), [requirements](docs/requirements-matrix.md), and the [verification report](docs/verification-report.md). The verification report is authoritative for release limitations. Test in staging with your fulfillment integrations before deployment.

License: GPL-2.0-or-later. See [LICENSE](LICENSE). Development API definitions retain their upstream licensing and are not included in the plugin ZIP.
