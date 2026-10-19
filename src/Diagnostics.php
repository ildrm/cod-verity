<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Diagnostics {
    public function __construct( private readonly Repository $repo ) {}
    public function checks(): array {
        global $wp_version, $wpdb;
        $schemaReady = get_option( 'codverity_schema' ) === Installer::SCHEMA;
        foreach ( [ $this->repo->requests, $this->repo->events ] as $table ) {
            $schemaReady = $schemaReady && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        $pending = Scheduler::ready() ? count( as_get_scheduled_actions( [ 'group' => Scheduler::GROUP, 'status' => 'pending', 'per_page' => 100 ], 'ids' ) ) : 0;
        $mail = get_option( 'codverity_last_mail', [] );
        return [
            [ 'WordPress', $wp_version, '' ], [ 'PHP', PHP_VERSION, '' ], [ 'WooCommerce', WC_VERSION, '' ], [ 'CODVerity', CODVERITY_VERSION, '' ],
            [ __( 'HPOS enabled', 'codverity-order-verification-for-woocommerce' ), \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no', '' ],
            [ __( 'Action Scheduler', 'codverity-order-verification-for-woocommerce' ), Scheduler::ready() ? 'ready' : 'unavailable', __( 'If unavailable, check WooCommerce activation and its scheduled actions screen.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Pending CODVerity jobs (capped at 100)', 'codverity-order-verification-for-woocommerce' ), (string) $pending, __( 'Inspect WooCommerce → Status → Scheduled Actions; filter by codverity.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Traffic-based WP-Cron', 'codverity-order-verification-for-woocommerce' ), defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled', __( 'Use a reliable server cron to run due WordPress events every minute.', 'codverity-order-verification-for-woocommerce' ) ],
            [ 'HTTPS', str_starts_with( home_url(), 'https://' ) && str_starts_with( admin_url(), 'https://' ) ? 'yes' : 'no', __( 'Use HTTPS for the site and administration URLs before sending live links.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'COD gateway enabled', 'codverity-order-verification-for-woocommerce' ), isset( $gateways['cod'] ) && $gateways['cod']->enabled === 'yes' ? 'yes' : 'no', __( 'Enable Cash on delivery in WooCommerce payment settings.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Database schema', 'codverity-order-verification-for-woocommerce' ), $schemaReady ? Installer::SCHEMA . ' (tables present)' : 'missing or mismatched', __( 'Expected schema: 1. Reactivate after resolving database permissions; back up first.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Last mail transport result', 'codverity-order-verification-for-woocommerce' ), ( $mail['result'] ?? 'not_attempted' ) . ( isset( $mail['at'] ) ? ' — ' . wp_date( 'Y-m-d H:i T', (int) $mail['at'] ) : '' ), __( 'Acceptance does not prove delivery. Check your WordPress mail transport and spam filters.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Last reconciliation', 'codverity-order-verification-for-woocommerce' ), get_option( 'codverity_last_reconcile' ) ? wp_date( 'Y-m-d H:i T', (int) get_option( 'codverity_last_reconcile' ) ) : 'not_run', __( 'Reconciliation runs every five minutes while cron and Action Scheduler are operational.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Latest operational error', 'codverity-order-verification-for-woocommerce' ), (string) get_option( 'codverity_last_error', 'none_recorded' ), __( 'Review Activity Logs and interrupted requests. Reissue uncertain mail only after checking the transport.', 'codverity-order-verification-for-woocommerce' ) ],
            [ __( 'Interrupted requests requiring review', 'codverity-order-verification-for-woocommerce' ), $schemaReady ? (string) $this->repo->count( [ 'state' => 'interrupted' ] ) : 'unknown', __( 'Review the interrupted filter in Verification Queue before fulfillment.', 'codverity-order-verification-for-woocommerce' ) ],
        ];
    }
}
