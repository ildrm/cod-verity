<?php
/** CODV-061. Only this plugin's data is removed, only when explicitly configured. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$codveritySettings = get_option( 'codverity_settings', [] );
if ( empty( $codveritySettings['delete_on_uninstall'] ) ) { return; }
global $wpdb;
// Prefix comes exclusively from WordPress, never from request data.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}codverity_events" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}codverity_requests" );
foreach ( [ 'codverity_settings', 'codverity_schema', 'codverity_installed_at', 'codverity_last_error', 'codverity_last_mail', 'codverity_last_reconcile' ] as $codverityOption ) { delete_option( $codverityOption ); }
// Bounded CRUD cleanup supports either WooCommerce datastore without order SQL.
if ( function_exists( 'wc_get_orders' ) ) {
    $codverityPage = 1;
    do {
        $codverityOrders = wc_get_orders( [ 'type' => 'shop_order', 'limit' => 100, 'page' => $codverityPage++, 'orderby' => 'ID', 'order' => 'ASC' ] );
        foreach ( $codverityOrders as $codverityOrder ) {
            $codverityOrder->delete_meta_data( '_codverity_operation' );
            $codverityOrder->delete_meta_data( '_codverity_excluded' );
            $codverityOrder->save_meta_data();
        }
    } while ( count( $codverityOrders ) === 100 );
}
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key='codverity_last_test'" );
// Only plugin-prefixed temporary options. Underscores are escaped for LIKE.
$codverityPattern = $wpdb->esc_like( '_transient_codverity_' ) . '%';
$codverityTimeoutPattern = $wpdb->esc_like( '_transient_timeout_codverity_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $codverityPattern, $codverityTimeoutPattern ) );
