# Release checklist

Use docs/verification-report.md for actual executed results. This is also the repeatable staging acceptance checklist; unchecked items are not claimed as passed.

* [x] Source and runtime autoloader exist; plugin activates against real WordPress/WooCommerce.
* [x] Dependency failure avoids loading WooCommerce-dependent services (source guard; runtime dependency test recorded separately).
* [x] HPOS compatibility declaration, CRUD, native COD filter and legacy/HPOS order screens implemented.
* [x] Pure eligibility handles payable totals, method/type/status, exclusions, inclusive decimal/category policies.
* [x] Unique enrollment; generation/attempt/state replay guards.
* [x] Processing holds are owned; native gateway holds are preserved.
* [x] Token derivation, generation rotation, expiration, public POST challenge and replay controls implemented/tested.
* [x] No direct payment completion or inventory adjustment in plugin runtime.
* [x] Transport acceptance/failure, bounded retries, reminders and unknown-send review implemented/tested.
* [x] Conservative expiry and opt-in owned-hold cancellation implemented/tested.
* [x] Manual start/reissue/confirm/cancel/stop/eligibility actions have working handlers and checks.
* [x] Advisory duplicate fingerprints and authorized matching-order links implemented.
* [x] Six administration views, settings, order panel, paginated queue/logs and date-filtered reports implemented.
* [x] Capability/nonce protected bounded CSV; output escaping and formula protection.
* [x] Audit context redaction, actionable diagnostics, privacy hooks, retention and merchant-controlled uninstall implemented.
* [x] Domain, real WooCommerce integration, HTTP and competing-process tests executed.
* [x] PHP syntax, portable coding baseline and full-runtime PHPStan checks executed.
* [ ] Full keyboard and screen-reader review across all screens and localized strings.
* [ ] Visual RTL and small-screen browser acceptance across supported themes/admin versions.
* [ ] Real transactional mail delivery through a merchant's chosen mail service and inbox.
* [ ] Merchant-specific shipping/warehouse/payment extension interactions.
* [ ] Extended load, mail-provider timeout, distributed DB/proxy and long-duration cron outage testing.
* [ ] Hosted CI matrix result reviewed (configuration supplied; local verification is separate).
* [x] Final ZIP built, integrity/layout verified, clean archive installation smoke test recorded.

Before commercial release, resolve the remaining acceptance items for the deployment environment. Never interpret confirmation as payment received, mail acceptance as delivery, or an advisory fingerprint as proof of fraud.
