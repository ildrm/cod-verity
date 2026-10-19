<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Duplicates {
    public function fingerprint( \WC_Order $order ): string {
        $phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );
        if ( ! $phone ) { return ''; }
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) { continue; }
            $key = $item->get_product_id() . ':' . $item->get_variation_id();
            $items[ $key ] = ( $items[ $key ] ?? 0 ) + $item->get_quantity();
        }
        ksort( $items );
        return hash_hmac( 'sha256', wp_json_encode( [ $phone, strtoupper( $order->get_billing_country() ), $order->get_currency(), wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $items ] ), wp_salt( 'auth' ) );
    }
}
