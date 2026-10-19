<?php
// Integration tests require an explicitly supplied disposable WordPress installation.
$wpRoot = getenv( 'CODVERITY_WP_ROOT' );
if ( $wpRoot ) {
    define( 'WP_USE_THEMES', false );
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8097';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require rtrim( $wpRoot, '/' ) . '/wp-load.php';
    if ( ! CODVerity\Plugin::$instance ) { throw new RuntimeException( 'Activate CODVerity in the disposable WordPress installation first.' ); }
    WC_Install::install();
    // Older WordPress/WooCommerce dependencies emit PHP 8.5 deprecations; plugin errors remain enabled.
    error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );
} else {
    define( 'ABSPATH', __DIR__ . '/' );
    spl_autoload_register( static function ( string $class ): void {
        if ( str_starts_with( $class, 'CODVerity\\' ) ) { require_once dirname( __DIR__ ) . '/src/' . substr( $class, 10 ) . '.php'; }
    } );
}
