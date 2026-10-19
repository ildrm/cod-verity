<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** Pure policy evaluator; caller supplies enrollment status. CODV-005–008. */
final class Eligibility {
    public function __construct( private readonly Settings $settings ) {}

    public function evaluate( \WC_Order $order, bool $enrolled = false, bool $manual = false ): string {
        if ( ! $manual && ! $this->settings->get( 'enabled' ) ) { return 'automation_disabled'; }
        if ( $order->get_type() !== 'shop_order' ) { return 'order_type'; }
        if ( $order->get_payment_method() !== 'cod' ) { return 'not_cod'; }
        if ( Amount::compare( (string) $order->get_total(), '0' ) <= 0 ) { return 'no_payable_total'; }
        if ( ! in_array( $order->get_status(), [ 'pending', 'on-hold', 'processing' ], true ) || $order->get_date_paid() ) { return 'unsafe_status'; }
        if ( $enrolled ) { return 'already_enrolled'; }
        if ( $order->get_meta( '_codverity_excluded', true ) === 'yes' ) { return 'excluded'; }
        foreach ( [ 'min_total' => -1, 'max_total' => 1 ] as $key => $direction ) {
            $bound = $this->settings->get( $key );
            if ( $bound !== '' && Amount::compare( (string) $order->get_total(), (string) $bound ) === $direction ) { return $key; }
        }
        $included = $this->settings->get( 'include_categories' );
        $excluded = $this->settings->get( 'exclude_categories' );
        $match = ! $included;
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) { continue; }
            $product = $item->get_product();
            if ( ! $product ) { continue; }
            $id = $product->get_parent_id() ?: $product->get_id();
            $categories = wc_get_product_term_ids( $id, 'product_cat' );
            if ( array_intersect( $excluded, $categories ) ) { return 'excluded_category'; }
            $match = $match || (bool) array_intersect( $included, $categories );
        }
        return $match ? 'eligible' : 'included_category_required';
    }
}
