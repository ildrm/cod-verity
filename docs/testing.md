# Testing and acceptance record

Use disposable stores only. The automated suites create, resolve and permanently remove their fixtures. All email transport is intercepted in the test environment; no real customer mail is sent. This is a functional verification suite, not a third-party security certification.

Install PHP 8.2+, Composer, MariaDB/MySQL and curl/zip/mysqli/dom/mbstring extensions. `composer install` installs test-only tools. `php tools/fetch-stubs.php` downloads pinned WordPress 6.6.2, WooCommerce 9.5.2 and Action Scheduler 3.8.2 API definitions. `composer lint`, `composer cs`, and `composer analyse` run syntax, the explicitly bounded portable PHPCS baseline and full-runtime PHPStan level 3. On sandboxes that cannot create PHPStan's worker socket, use `vendor/bin/phpstan analyse --debug --memory-limit=1G` for single-process analysis. No ignored-error baseline is used.

For a local database, `docker compose -f tools/compose.yml up -d` starts a loopback-only MariaDB 11.4 instance with temporary storage and disposable credentials. `bash tools/setup-test-site.sh` prepares WordPress 6.6.2 / WooCommerce 9.5.2 in ignored `build/wordpress`. It disables automatic cron/async workers and intercepts real mail so tests explicitly control side effects. Override `CODVERITY_DB_HOST`, `CODVERITY_DB_NAME`, `CODVERITY_DB_USER`, `CODVERITY_DB_PASS` as needed. Do not point this at an existing merchant store.

```sh
vendor/bin/phpunit --testsuite unit
CODVERITY_WP_ROOT="$PWD/build/wordpress" CODVERITY_STORAGE=legacy vendor/bin/phpunit --testsuite integration
php build/wp-cli.phar --path=build/wordpress wc hpos sync
php build/wp-cli.phar --path=build/wordpress option update woocommerce_custom_orders_table_enabled yes
CODVERITY_WP_ROOT="$PWD/build/wordpress" CODVERITY_STORAGE=hpos vendor/bin/phpunit --testsuite integration
php -S 127.0.0.1:8097 -t build/wordpress
# In another shell:
CODVERITY_WP_ROOT="$PWD/build/wordpress" php tests/http.php
CODVERITY_WP_ROOT="$PWD/build/wordpress" php tests/checkout-http.php
CODVERITY_WP_ROOT="$PWD/build/wordpress" php tests/concurrency.php
```

The concurrency script creates separate PHP workers with a file barrier. It tests confirm/cancel, confirm/confirm and cancel/confirm races against a real database. Exactly one worker must commit a winning decision, and the order must agree with it. The scheduler test dispatches actual stored actions through Action Scheduler's runner and verifies a repeated action is inert; unrelated WooCommerce maintenance batches are excluded from that isolated assertion.

The following are executed acceptance groups; expected and actual results match unless otherwise stated. Exact platform versions and final counts are recorded in verification-report.md. Each named method in `tests/Integration/WorkflowTest.php` is executable evidence rather than a simulated WooCommerce implementation.

| Requirements | Test / type | Preconditions | Expected / actual outcome | Status |
|---|---|---|---|---|
| CODV-001–004, 059 | activation/schema/datastore; integration | disposable activated store | schema and unique indexes exist; initialized scheduler; selected CRUD datastore matches | Passed |
| CODV-002 | missing dependency; subprocess smoke | load WordPress with WooCommerce skipped | no fatal; CODVerity dependent instance stays null | Passed |
| CODV-005, 011 | native checkout; real HTTP | published virtual product, COD enabled, guest cart | Classic and Checkout Block Store API both enroll and hold before processing | Passed |
| CODV-006, 008 | totals/category/variation/historical policy; unit+integration | threshold and parent-category fixtures | exact inclusive boundaries; exclusion wins; old and non-COD/zero-total orders unaffected | Passed |
| CODV-007, 014 | duplicate gateway/mail and CAS/process races; integration | repeated hooks/jobs and simultaneous processes | one request, one initial send/decision; consistent final order and stock | Passed |
| CODV-009–016 | hold/decision/stock/recovery/staff-edit; integration | held COD and native downloadable-hold fixtures | owned hold releases; native hold stays; one restoration; conflicts interrupt; claims recover | Passed |
| CODV-017–022, 058 | token/challenge/decimal/CSV domain; unit | generated secrets and malformed values | rotation/secret isolation/format limits and challenge expiry; terminal reversal rejected | Passed |
| CODV-019–022, 057 | public endpoint; HTTP | valid held request and malformed/expired variants | GET read-only; POST challenge mandatory; confirm/cancel work; replay/invalid/XSS/array input rejected; privacy headers set | Passed |
| CODV-021 | rate limiting; integration | new keyed test IP identity | first 60 allowed, next rejected in fixed minute window | Passed |
| CODV-023–029 | accepted/failed mail, retries/reminders/real action dispatch; integration | controlled pre_wp_mail result | accepted state; four-attempt ceiling; durable failure count; same reminder link; replay inert | Passed |
| CODV-030–031 | reissue/cooldown/test email; integration+HTTP | active row and staff session | old token/jobs invalid; cooldown enforced; invalid test destination gives recoverable feedback | Passed |
| CODV-032–035 | expiry; integration | deadline in past, auto-cancel off/on | expired customer cannot claim; default stays held; only opt-in owned hold cancelled | Passed |
| CODV-036–037 | duplicate advice; integration | same products/quantities/normalized phone/country/total | keyed match, no plaintext phone copy; matching IDs available; order remains held rather than rejected | Passed |
| CODV-038–044 | six admin pages/manual/settings; HTTP+integration | authorized merchant session and nonce | all views render; settings persist/reject correctly; manual confirmation works; disable preserves active links | Passed |
| CODV-046–050 | reports/export/date/pagination; integration+HTTP | operational fixtures, New York DST date | real aggregates; bounded rows; CSV only allowed operational fields; 25-hour local day handled correctly | Passed |
| CODV-051–055 | audit/redaction/unknown mail/retention/reactivation; integration | pending, interrupted-send and aged resolved fixtures | no secrets/PII in context; unknown send not repeated; active secrets retained; resolved secrets scrubbed; jobs repaired | Passed |
| CODV-056–059 | permissions/nonces; HTTP | subscriber and merchant sessions, missing/invalid nonce | subscriber forbidden; invalid/missing nonce forbidden; valid export/action works | Passed |
| CODV-060 | privacy export/eraser; integration | matching billing-email orders | export excludes secrets; erase deletes plugin records and leaves held order unchanged | Passed |
| CODV-061–063 | uninstall/deactivation/archive; clean-store smoke | separate store installed from ZIP | default retains; explicit cleanup removes plugin data/metadata but preserves order/status/stock; package contains runtime files only | Passed |

Manual acceptance remaining: keyboard-only operation, screen readers, mobile/RTL visual rendering, multiple themes/browsers, merchant SMTP inbox delivery, real cron outage duration, custom shipping/warehouse plugins, large-store load and database proxy topology. These are **not run**, and are not inferred from source correctness. A browser provider was unavailable in the execution environment; HTTP rendering checks do not replace browser accessibility/visual tests.

For manual UI acceptance, install the archive in staging, enable COD, place Classic and Block orders, open emailed links on narrow and RTL pages, traverse every control with Tab/Enter, inspect the order metabox in both datastores, and complete each queue action. Repeat with a low-privilege user, invalid nonce, expired link and native on-hold order. Confirm state, stock and notes in WooCommerce after every decision. Any mismatch is a failed release gate.

Local raw logs and JUnit outputs are under ignored `test-results/`; they are excluded from the installable ZIP. CI repeats the suite on PHP 8.2–8.5; a configured workflow is not a claim that hosted CI already ran. The clean archive smoke script requires a separate `build/clean` WordPress install and intentionally exercises destructive plugin cleanup only there.
