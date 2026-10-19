# Troubleshooting

**WooCommerce unavailable/incompatible:** activate WooCommerce 9.5+ first. CODVerity shows an administrative warning and skips dependent services. No orders are changed by the failed initialization.

**COD gateway disabled:** enable Cash on delivery in WooCommerce payment settings. Check its shipping-method/virtual-order restrictions. Other payment methods are intentionally unaffected.

**Email transport rejects messages:** use Settings → Test email and Diagnostics. Check WordPress SMTP/mail configuration and sender-domain authorization. Attempts are bounded; retry timing is 5/30/120 minutes after each prior failure. Missing/invalid customer billing email also produces a safe transport failure. `accepted` means only accepted by `wp_mail`, not delivered.

**Accepted but not arriving:** check spam filtering, transport logs and DNS sender configuration. CODVerity cannot claim inbox delivery or read status. Never paste live customer links into public support messages.

**Unknown send outcome:** a worker stopped between its persistent claim and result write. Check your transport before staff reissues the link. Unknown outcomes are deliberately not automatically resent.

**Delayed reminders/expiration or missing cron:** inspect WooCommerce → Status → Scheduled Actions, group `codverity`, and Diagnostics. Set a real server task such as `wp cron event run --due-now` every minute. If `DISABLE_WP_CRON` is true, another runner must execute due events. Action Scheduler starts after its initialization event; reconciliation every five minutes repairs missing jobs using generation-aware identifiers. Traffic-dependent WP-Cron can be late.

**Expired/rotated links:** old links are unavailable after expiry, a decision, interruption, explicit reissue or WordPress secret rotation. Reissue only through the authorized queue after checking current order state and cooldown. Reissue always invalidates previous links.

**Orders remaining on hold:** expiration leaves held orders for review by default. Native gateway holds also remain held after customer confirmation. Verify request state, original ownership, notification problems and staff edits. Use an explicit staff decision or handle fulfillment directly in WooCommerce. Confirmation never records payment.

**Interrupted or unsafe-state action:** a staff edit, payment/method/content change or fulfillment status conflicted with verification. CODVerity preserves that order decision. Review history and current state; do not force an old verification link to override fulfillment. A processing order in no-hold mode cannot be cancelled by the customer endpoint.

**Database migration/lock failure:** back up first, check permission to create/alter plugin tables and call `GET_LOCK`/`RELEASE_LOCK`, and verify stable MySQL connections. Reactivate to retry migration. Schema failure prevents service startup. Do not edit WooCommerce order tables directly to repair plugin state. Partial request operations appear in reconciliation/diagnostics.

**HPOS issues:** ensure the selected authoritative store is fully synchronized before switching. Use WooCommerce's supported HPOS migration/sync controls. CODVerity uses order CRUD and provides both legacy and HPOS metabox screens. Test custom order/fulfillment extensions separately.

**HTTPS warning:** configure both WordPress site and administration URLs with valid HTTPS before sending live tokens. Update reverse-proxy HTTPS detection correctly and redact token queries from infrastructure logs.

**Disable/deactivate/uninstall:** disabling new automation keeps existing requests functional. Deactivation preserves all order statuses and data and cancels only CODVerity jobs. Reactivation repairs pending work; review held orders in the meantime. Uninstall deletes data only when explicitly configured. Activate WooCommerce during destructive uninstall to remove plugin order metadata via CRUD.
