# Verification report — CODVerity 1.0.0

Verification date: 2026-10-19. Source, user interfaces, background workers, privacy controls, tests, tooling, requirement matrix and merchant/engineering documentation are implemented. The 63 requirement IDs map to concrete components and acceptance evidence in requirements-matrix.md.

Local runtime testing uses WordPress 6.6.2, WooCommerce 9.5.2, Action Scheduler 3.8.2 and MariaDB 11.4. PHP 8.2.34 is exercised through a disposable container; PHP 8.5.8 is the native development runtime. Both legacy order storage and HPOS are exercised. No compatibility assertion is made for unexecuted WordPress/WooCommerce versions, database proxies or third-party fulfillment extensions.

Executed results:

| Check | Actual result |
|---|---|
| Composer strict validation | Passed |
| PHP syntax validation | Passed for runtime, tests and tooling |
| Portable PHPCS baseline | Passed; this is not a full WPCS audit |
| PHPStan level 3, every runtime PHP file | Passed, no errors; pinned API definitions, no ignored-error baseline |
| Domain unit suite, PHP 8.5.8 | 15 tests, 58 assertions passed |
| Real integration suite, PHP 8.2.34 / legacy | 30 tests, 183 assertions passed, no warnings |
| Real integration suite, PHP 8.2.34 / HPOS | 30 tests, 183 assertions passed, no warnings |
| Real integration suite, PHP 8.5.8 / HPOS | 30 tests, 183 assertions passed; 8 dependency deprecation notices |
| Real HTTP acceptance | 27 checks passed: read-only GET, authenticated POST, replay, invalid inputs, headers, six admin views, settings save/rejection, permissions, nonces, manual action and CSV |
| Guest checkout HTTP acceptance | 9 checks passed across Classic Checkout and Checkout Block Store API, including native COD hold and unpaid status |
| Separate-process races | 3 rounds passed: confirm/cancel, confirm/confirm, cancel/confirm; exactly one decision wins and order agrees |
| Missing WooCommerce dependency subprocess | Passed; dependent services remain inactive without a fatal error |
| Clean ZIP installation | Standard WordPress plugin install/activation succeeded on a second fresh store |
| Clean archive lifecycle/uninstall smoke | 7 checks passed: runtime path, schema/enrollment, confirmation/unpaid semantics, deactivation, default retention, explicit cleanup, preserved order/stock and removed marker |
| Package integrity/layout | ZIP opens; a single correctly named plugin directory contains the expected runtime entry point/assets/translations/docs; excluded build/test/vendor/secret paths are absent; SHA-256 sidecar generated |

The clean archive was installed using WordPress's normal plugin installation/upgrader path via WP-CLI. No live inbox delivery, browser accessibility, RTL visual review or production load result is claimed. Test mail is intercepted through WordPress's pre_wp_mail boundary. PHP 8.5 runs of the older WooCommerce fixture report dependency deprecations, including its bundled Sabberworm CSS parser; PHP 8.2 runs are clean. A browser provider was unavailable, so native browser rendering/accessibility acceptance remains unrun.

Important defects found and fixed during verification: public/worker requests now initialize the native COD gateway filters before hold release, preventing WooCommerce from implicitly populating a paid timestamp; an incompatible third-party payment-status filter interrupts before the update. Repeated gateway calls preserve current order status instead of reopening cancelled or staff-edited orders. Regression tests cover both conditions. Schema completion checks verify required columns and unique indexes, and report-date boundaries handle daylight-saving changes.

Raw local logs and JUnit reports are in ignored `test-results/`. Reproducible test entry points and CI are in the source repository. Hosted CI on PHP 8.3/8.4 and the supplied 8.2–8.5 matrix has not been executed in this session; no hosted CI result is implied.

Release assessment: suitable for controlled staging evaluation after the recorded automated checks; not yet certified for unrestricted commercial production release. The remaining merchant/browser/mail/load acceptance gates in release-checklist.md must be completed for the intended deployment. Packaging and version 1.0.0 do not by themselves establish production readiness.
