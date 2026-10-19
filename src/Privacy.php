<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Privacy {
    public function __construct( private readonly Workflow $workflow ) {}
    public function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', function ( array $exporters ): array {
            $exporters['codverity'] = [ 'exporter_friendly_name' => 'CODVerity', 'callback' => [ $this, 'export' ] ]; return $exporters;
        } );
        add_filter( 'wp_privacy_personal_data_erasers', function ( array $erasers ): array {
            $erasers['codverity'] = [ 'eraser_friendly_name' => 'CODVerity', 'callback' => [ $this, 'erase' ] ]; return $erasers;
        } );
        add_action( 'admin_init', static function (): void {
            if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
                wp_add_privacy_policy_content( 'CODVerity', wpautop( __( 'We send transactional cash-on-delivery verification emails using our WordPress email transport. CODVerity stores order references, verification decisions, timestamps, audit events and keyed duplicate fingerprints. Verification links expire and are invalidated after resolution. Active verification data is retained until resolved; audit events are normally pruned after 90 days, and resolved request secrets after 365 days. Minimal enrollment records remain to prevent duplicate verification. The store can configure retention and assist with personal-data requests.', 'codverity-order-verification-for-woocommerce' ) ) );
            }
        } );
    }
    private function orders( string $email, int $page ): object {
        return wc_get_orders( [ 'type' => 'shop_order', 'billing_email' => sanitize_email( $email ), 'limit' => 20, 'page' => max( 1, $page ), 'paginate' => true, 'orderby' => 'ID', 'order' => 'ASC' ] );
    }
    public function export( string $email, int $page = 1 ): array {
        $orders = $this->orders( $email, $page ); $data = [];
        foreach ( $orders->orders as $order ) {
            $row = $this->workflow->repo->byOrder( $order->get_id() );
            if ( ! $row ) { continue; }
            $fields = [];
            foreach ( [ 'order_id', 'state', 'delivery', 'created_at', 'expires_at', 'response_at', 'resolved_at', 'actor', 'reason', 'duplicate_warning' ] as $key ) {
                $fields[] = [ 'name' => $key, 'value' => (string) $row[ $key ] ];
            }
            $data[] = [ 'group_id' => 'codverity', 'group_label' => __( 'COD order verification', 'codverity-order-verification-for-woocommerce' ), 'item_id' => 'codverity-' . $row['id'], 'data' => $fields ];
        }
        return [ 'data' => $data, 'done' => $page >= (int) $orders->max_num_pages ];
    }
    public function erase( string $email, int $page = 1 ): array {
        $orders = $this->orders( $email, $page ); $removed = false; $retained = false; $messages = [];
        foreach ( $orders->orders as $order ) {
            try {
                $this->workflow->repo->locked( $order->get_id(), function () use ( $order, &$removed ): void {
                    $row = $this->workflow->repo->byOrder( $order->get_id() );
                    if ( ! $row ) { return; }
                    $this->workflow->interruptRow( $row, 'privacy_erasure' );
                    // Exclusion prevents erasure from allowing accidental re-enrollment. No status/stock change.
                    $order->update_meta_data( '_codverity_excluded', 'yes' );
                    $order->delete_meta_data( '_codverity_operation' );
                    $order->save_meta_data();
                    $this->workflow->repo->erase( $row ); $removed = true;
                } );
            } catch ( \Throwable $e ) { $retained = true; $messages[] = __( 'A busy verification request was retained. Please retry the erasure and review any held order.', 'codverity-order-verification-for-woocommerce' ); }
        }
        return [ 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => $page >= (int) $orders->max_num_pages ];
    }
}
