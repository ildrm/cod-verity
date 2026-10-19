<?php
/**
 * Plugin Name: CODVerity — Cash on Delivery Order Verification for WooCommerce
 * Description: Secure post-checkout customer confirmation for cash-on-delivery orders.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 9.5
 * Author: CODVerity
 * License: GPL-2.0-or-later
 * Text Domain: codverity-order-verification-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;
define( 'CODVERITY_VERSION', '1.0.0' );
define( 'CODVERITY_FILE', __FILE__ );
define( 'CODVERITY_PATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
    if ( str_starts_with( $class, 'CODVerity\\' ) ) {
        $name = substr( $class, strlen( 'CODVerity\\' ) );
        if ( preg_match( '/^[A-Za-z]+$/D', $name ) && is_file( CODVERITY_PATH . 'src/' . $name . '.php' ) ) {
            require_once CODVERITY_PATH . 'src/' . $name . '.php';
        }
    }
} );
register_activation_hook( __FILE__, [ CODVerity\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ CODVerity\Installer::class, 'deactivate' ] );
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CODVERITY_FILE, true );
    }
} );
add_action( 'plugins_loaded', [ CODVerity\Plugin::class, 'boot' ], 20 );
