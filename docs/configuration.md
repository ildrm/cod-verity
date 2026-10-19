# Configuration

All settings are under WooCommerce → CODVerity → Settings. Changes are validated server-side as a whole; invalid submissions preserve the previous configuration. Most policies apply when evaluated; existing deadlines and original hold ownership never change silently.

| Setting | Default | Meaning / limits |
|---|---|---|
| Enable new automation | off | New native COD and new admin-created on-hold orders only. Issued links remain usable when disabled. |
| Hold orders | on | Gate intended processing orders to on-hold. Off allows fulfillment before verification and disallows customer cancellation of processing orders. Existing ownership is preserved. |
| Minimum / maximum total | blank | Inclusive decimal boundaries using store currency precision; blank unrestricted. Positive payable total is always required. |
| Included categories | empty | At least one line item must match when selected. |
| Excluded categories | empty | Any matching line item disqualifies the order. Exclusion wins. Exact categories; variations use parent categories; descendants are not implicitly included. First 500 categories shown. |
| Link validity | 72 hours | 1–168 hours from generation issue. |
| Reminders | on | Policy controls future scheduling and execution; issued links do not rotate. |
| Reminder hours | 24,48 | One/two distinct positive whole hours from issue, before expiry. A reminder follows accepted earlier mail; transport failures are retries rather than reminders. |
| Transport retry limit | 3 | 0–3 additional attempts per email slot after its initial attempt. Delays from previous failure: 5, 30, 120 minutes. No retry after expiry. |
| Automatic expiry cancellation | off | Explicit opt-in; only expired, unchanged orders still held by CODVerity. Native holds remain held. May restore stock through WooCommerce. |
| Duplicate warnings | on | Advisory keyed match across enrolled COD requests, never rejection/cancellation. |
| Duplicate lookback | 24 hours | 1–168 hours. Phone normalization strips nondigits; no international-equivalence claim. |
| Reissue cooldown | 300 seconds | 60–86400 seconds since issue/reissue. Reissues invalidate all previous links and scheduled generations. |
| Email subject | localized confirmation request | Single line, up to 250 bytes. Header controls rejected. |
| Email message | localized plain text | Up to 20,000 bytes and must contain `{verification_url}`. Runtime also appends link if a filter bypasses validation. |
| Sender name / email | blank | Use transport defaults; explicit email validated and header controls rejected. Delivery depends on the configured transport. |
| Audit retention | 90 days | 7–730 days, daily bounded pruning. |
| Resolved secret retention | 365 days | 30–3650 days. Scrubs seed, duplicate/content fingerprints, staff ID and reason after resolution. Minimal enrollment tombstone and operational counts remain. Active rows are not pruned. |
| Delete on uninstall | off | Preserve data by default. Opt-in removes only CODVerity data; never deletes orders. |

Supported email placeholders: `{store_name}`, `{customer_first_name}`, `{order_number}`, `{order_total}`, `{verification_url}`, `{expires_at}`. Totals use the order currency. Times use the WordPress site timezone. Do not place customer personal data in staff reasons.

The test-email control accepts a valid destination and has a 60-second per-staff cooldown. It sends no live customer link and does not create or edit orders. Reissue/confirm/cancel/start/stop use order-editing privileges, a WordPress nonce, a reason and explicit acknowledgment. Stopping preserves the order status; staff must decide what to do with any held order. Terminal confirmed/cancelled requests cannot be reissued.

No time-saving estimate is presented. Reports show recorded operational outcomes and actual customer response intervals only.
