<?php
/** Real guest Classic Checkout and Store API/Checkout Block payment-boundary checks. */
require __DIR__ . '/bootstrap.php';
$plugin = CODVerity\Plugin::$instance; $workflow = $plugin->workflow;
$oldGateway = get_option( 'woocommerce_cod_settings', [] );
$oldCheckout = get_option( 'woocommerce_checkout_page_id' ); $oldGuest = get_option( 'woocommerce_enable_guest_checkout' );
$orders = []; $jars = []; $page = 0;
$product = new WC_Product_Simple(); $product->set_name( 'Checkout integration fixture' ); $product->set_regular_price( '25' ); $product->set_virtual( true ); $product->set_status( 'publish' ); $product->save();
function checkoutRequest( string $url, string $jar, ?array $data = null, array $headers = [], bool $json = false ): array {
    $handle = curl_init( $url ); curl_setopt_array( $handle, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 45, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar ] );
    if ( $data !== null ) {
        curl_setopt( $handle, CURLOPT_POST, true ); curl_setopt( $handle, CURLOPT_POSTFIELDS, $json ? wp_json_encode( $data ) : http_build_query( $data ) );
        if ( $json ) { $headers[] = 'Content-Type: application/json'; }
    }
    curl_setopt( $handle, CURLOPT_HTTPHEADER, $headers );
    $raw = curl_exec( $handle ); if ( $raw === false ) { throw new RuntimeException( curl_error( $handle ) ); }
    $split = curl_getinfo( $handle, CURLINFO_HEADER_SIZE );
    $response = [ curl_getinfo( $handle, CURLINFO_HTTP_CODE ), substr( $raw, $split ), substr( $raw, 0, $split ) ];
    wp_cache_flush_runtime(); return $response;
}
function checkoutAssert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; }
try {
    update_option( CODVerity\Settings::OPTION, array_replace( CODVerity\Settings::defaults(), [ 'enabled' => true ] ) ); update_option( 'codverity_installed_at', time() - 10 );
    update_option( 'woocommerce_cod_settings', array_replace( $oldGateway, [ 'enabled' => 'yes', 'enable_for_virtual' => 'yes', 'enable_for_methods' => [] ] ) );
    update_option( 'woocommerce_enable_guest_checkout', 'yes' );
    $page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic checkout fixture', 'post_content' => '[woocommerce_checkout]' ] ); update_option( 'woocommerce_checkout_page_id', $page );
    $address = [ 'first_name' => 'Test', 'last_name' => 'Customer', 'company' => '', 'address_1' => '123 Main Street', 'address_2' => '', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90001', 'country' => 'US', 'email' => 'checkout-fixture@example.test', 'phone' => '2125550199' ];

    $jar = tempnam( dirname( __DIR__ ) . '/build', 'classic-cookie-' ); $jars[] = $jar;
    [ $code, $body ] = checkoutRequest( add_query_arg( 'wc-ajax', 'add_to_cart', home_url( '/' ) ), $jar, [ 'product_id' => $product->get_id(), 'quantity' => 1 ] );
    checkoutAssert( $code === 200 && isset( json_decode( $body, true )['fragments'] ), 'Classic guest adds product to cart' );
    [ $code, $body ] = checkoutRequest( wc_get_checkout_url(), $jar );
    preg_match( '/name="woocommerce-process-checkout-nonce" value="([^"]+)"/', $body, $match );
    checkoutAssert( ! empty( $match[1] ), 'Classic Checkout renders its supported nonce/form' );
    $data = [ 'payment_method' => 'cod', 'terms' => 1, 'terms-field' => 1, 'woocommerce-process-checkout-nonce' => $match[1] ];
    foreach ( $address as $key => $value ) { $data[ 'billing_' . $key ] = $value; }
    [ $code, $body ] = checkoutRequest( add_query_arg( 'wc-ajax', 'checkout', home_url( '/' ) ), $jar, $data );
    $result = json_decode( $body, true ); checkoutAssert( ( $result['result'] ?? '' ) === 'success', 'Classic Checkout accepts native COD payment: ' . ( $result['result'] ?? (string) $code ) );
    parse_str( (string) parse_url( $result['redirect'], PHP_URL_QUERY ), $params );
    $id = isset( $params['key'] ) ? wc_get_order_id_by_order_key( $params['key'] ) : 0; $orders[] = $id;
    $row = $workflow->repo->byOrder( $id ); checkoutAssert( $row && $workflow->freshOrder( $id )->get_status() === 'on-hold' && (int) $row['hold_owned'] === 1, 'Classic COD checkout enrolled and held before processing' );

    $jar = tempnam( dirname( __DIR__ ) . '/build', 'block-cookie-' ); $jars[] = $jar;
    [ $code, $body, $headers ] = checkoutRequest( rest_url( 'wc/store/v1/cart' ), $jar );
    preg_match( '/^Cart-Token:\s*(\S+)/mi', $headers, $token ); checkoutAssert( $code === 200 && ! empty( $token[1] ), 'Store API issues an authenticated guest cart token' );
    $cartHeader = [ 'Cart-Token: ' . $token[1] ];
    [ $code, $body ] = checkoutRequest( rest_url( 'wc/store/v1/cart/add-item' ), $jar, [ 'id' => $product->get_id(), 'quantity' => 1 ], $cartHeader, true );
    checkoutAssert( $code === 201 || $code === 200, 'Store API guest adds product' );
    [ $code, $body ] = checkoutRequest( rest_url( 'wc/store/v1/checkout' ), $jar, [ 'billing_address' => $address, 'shipping_address' => $address, 'payment_method' => 'cod', 'payment_data' => [], 'customer_note' => '' ], $cartHeader, true );
    $result = json_decode( $body, true ); checkoutAssert( $code === 200 && ! empty( $result['order_id'] ), 'Checkout Block Store API completes native COD: HTTP ' . $code . ' ' . ( $result['code'] ?? '' ) );
    $id = (int) $result['order_id']; $orders[] = $id; $row = $workflow->repo->byOrder( $id );
    checkoutAssert( $row && $workflow->freshOrder( $id )->get_status() === 'on-hold' && (int) $row['hold_owned'] === 1, 'Checkout Block COD is enrolled and held through the native gateway filter' );
    checkoutAssert( ! $workflow->freshOrder( $id )->get_date_paid(), 'Checkout Block verification does not collect payment' );
} finally {
    foreach ( $orders as $id ) { $row = $workflow->repo->byOrder( $id ); if ( $row ) { $plugin->scheduler->cancel( $row ); $workflow->repo->erase( $row ); } $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    $product->delete( true ); if ( $page ) { wp_delete_post( $page, true ); } foreach ( $jars as $jar ) { unlink( $jar ); }
    update_option( 'woocommerce_cod_settings', $oldGateway ); update_option( 'woocommerce_checkout_page_id', $oldCheckout ); update_option( 'woocommerce_enable_guest_checkout', $oldGuest ); update_option( CODVerity\Settings::OPTION, CODVerity\Settings::defaults() );
}
