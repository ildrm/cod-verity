<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    public static ?self $instance = null;
    public readonly Workflow $workflow;
    public readonly Scheduler $scheduler;
    public readonly Notifications $notifications;
    public readonly Admin $admin;

    public static function boot(): void {
        if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.5', '<' ) ) {
            self::notice( __( 'CODVerity requires WooCommerce 9.5 or newer. Verification automation is inactive.', 'codverity-order-verification-for-woocommerce' ) ); return;
        }
        try {
            if ( get_option( 'codverity_schema' ) !== Installer::SCHEMA ) { Installer::migrate(); }
            self::$instance = new self();
        } catch ( \Throwable $e ) {
            self::notice( __( 'CODVerity could not initialize its database. Check database permissions and reactivate the plugin. No new verification is running.', 'codverity-order-verification-for-woocommerce' ) );
        }
    }
    private static function notice( string $message ): void {
        add_action( 'admin_notices', static function () use ( $message ): void {
            if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'activate_plugins' ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>'; }
        } );
    }
    private function __construct() {
        global $wpdb;
        add_action( 'init', static function (): void { load_plugin_textdomain( 'codverity-order-verification-for-woocommerce', false, dirname( plugin_basename( CODVERITY_FILE ) ) . '/languages' ); }, 0 );
        $settings = new Settings(); $repo = new Repository( $wpdb );
        $this->workflow = new Workflow( $repo, $settings, new Token( wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), new Eligibility( $settings ) );
        $this->notifications = new Notifications( $this->workflow );
        $this->scheduler = new Scheduler( $this->workflow, $this->notifications );
        $this->workflow->setScheduler( $this->scheduler );
        $this->scheduler->register();
        add_filter( 'woocommerce_cod_process_payment_order_status', [ $this->workflow, 'gatewayStatus' ], 999, 2 );
        add_action( 'woocommerce_order_status_changed', [ $this->workflow, 'statusChanged' ], 10, 4 );
        add_action( 'woocommerce_before_order_object_save', [ $this->workflow, 'beforeOrderSave' ], 1 );
        add_action( 'woocommerce_after_order_object_save', [ $this->workflow, 'afterOrderSave' ], 999 );
        ( new PublicEndpoint( $this->workflow ) )->register();
        ( new Privacy( $this->workflow ) )->register();
        $this->admin = new Admin( $this->workflow, $this->notifications );
        $this->admin->register();
    }
}
