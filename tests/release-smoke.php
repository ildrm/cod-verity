<?php
/** Run against a separate, disposable store installed from the generated ZIP. */
require __DIR__ . '/bootstrap.php';
$plugin = CODVerity\Plugin::$instance; $workflow = $plugin->workflow;
function smokeCheck( bool $condition, string $name ): void { if ( ! $condition ) { throw new RuntimeException( "FAIL: $name" ); } echo "PASS: $name\n"; }
smokeCheck( str_contains( CODVERITY_FILE, '/build/clean/' ), 'Runtime is loaded from the clean archive installation' );
update_option( CODVerity\Settings::OPTION, array_replace( CODVerity\Settings::defaults(), [ 'enabled' => true ] ) ); update_option( 'codverity_installed_at', time() - 10 );
$product = new WC_Product_Simple(); $product->set_name( 'Release smoke product' ); $product->set_regular_price( '20' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
$order = wc_create_order(); $order->set_payment_method( 'cod' ); $order->set_billing_email( 'release-fixture@example.test' ); $order->add_product( $product, 2 ); $order->calculate_totals(); $order->save();
( new WC_Gateway_COD() )->process_payment( $order->get_id() ); $row = $workflow->repo->byOrder( $order->get_id() );
smokeCheck( $row && $workflow->freshOrder( $order->get_id() )->get_status() === 'on-hold', 'Archive enrollment and schema work on a fresh store' );
smokeCheck( $workflow->decide( (int) $row['id'], 'confirm' ) === 'confirmed' && ! $workflow->freshOrder( $order->get_id() )->get_date_paid(), 'Archive confirmation preserves COD payment semantics' );
CODVerity\Installer::deactivate();
smokeCheck( $workflow->repo->byOrder( $order->get_id() )['state'] === 'confirmed' && $workflow->freshOrder( $order->get_id() )->get_status() === 'processing', 'Deactivation preserves order and request' );
define( 'WP_UNINSTALL_PLUGIN', 'codverity-release-test' );
include CODVERITY_PATH . 'uninstall.php';
smokeCheck( $workflow->repo->byOrder( $order->get_id() ) !== null, 'Default uninstall policy preserves historical data' );
$settings = $workflow->settings->all(); $settings['delete_on_uninstall'] = true; update_option( CODVerity\Settings::OPTION, $settings );
include CODVERITY_PATH . 'uninstall.php';
global $wpdb;
smokeCheck( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'codverity_requests' ) ) ) === null, 'Explicit uninstall policy removes only plugin tables/settings' );
$preserved = wc_get_order( $order->get_id() ); $preserved->read_meta_data( true );
smokeCheck( $preserved->get_status() === 'processing' && $preserved->get_meta( '_codverity_operation', true ) === '' && wc_get_product( $product->get_id() )->get_stock_quantity() === 8, 'Destructive plugin cleanup preserves WooCommerce order/stock and removes operation metadata' );
