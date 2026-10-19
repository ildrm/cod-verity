<?php
/** Real HTTP acceptance checks. Run only against a disposable local store. */
require __DIR__ . '/bootstrap.php';
if ( ! getenv( 'CODVERITY_WP_ROOT' ) ) { throw new RuntimeException( 'CODVERITY_WP_ROOT is required.' ); }
$plugin = CODVerity\Plugin::$instance;
$workflow = $plugin->workflow;
$checks = 0; $orders = []; $products = [];
function check( bool $condition, string $name ): void { global $checks; if ( ! $condition ) { throw new RuntimeException( "FAIL: $name" ); } ++$checks; echo "PASS: $name\n"; }
function request( string $url, ?array $post = null, string $cookie = '' ): array {
    $handle = curl_init( $url );
    curl_setopt_array( $handle, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => $cookie ] );
    if ( $post !== null ) { curl_setopt( $handle, CURLOPT_POST, true ); curl_setopt( $handle, CURLOPT_POSTFIELDS, http_build_query( $post ) ); }
    $result = curl_exec( $handle ); if ( $result === false ) { throw new RuntimeException( curl_error( $handle ) ); }
    $response = [ 'status' => curl_getinfo( $handle, CURLINFO_HTTP_CODE ), 'headers' => substr( $result, 0, curl_getinfo( $handle, CURLINFO_HEADER_SIZE ) ), 'body' => substr( $result, curl_getinfo( $handle, CURLINFO_HEADER_SIZE ) ) ];
    // This test is a separate WP process from the server; discard its stale runtime CRUD caches.
    wp_cache_flush_runtime();
    return $response;
}
function fixture(): array {
    global $workflow, $orders, $products;
    $product = new WC_Product_Simple(); $product->set_name( 'HTTP fixture' ); $product->set_regular_price( '30' ); $product->save(); $products[] = $product->get_id();
    $order = wc_create_order(); $orders[] = $order->get_id(); $order->set_payment_method( 'cod' ); $order->set_billing_email( 'http-fixture@example.test' ); $order->add_product( $product, 1 ); $order->calculate_totals(); $order->save();
    ( new WC_Gateway_COD() )->process_payment( $order->get_id() );
    return $workflow->repo->byOrder( $order->get_id() );
}
update_option( CODVerity\Settings::OPTION, array_replace( CODVerity\Settings::defaults(), [ 'enabled' => true ] ) );
update_option( 'codverity_installed_at', time() - 10 );
try {
    $row = fixture(); $url = $plugin->notifications->url( $row ); $before = $workflow->repo->get( (int) $row['id'] );
    $get = request( $url . '&decision=cancel' );
    check( $get['status'] === 200, 'Public GET renders verification form' );
    check( str_contains( $get['body'], 'Confirm Order' ) && str_contains( $get['body'], 'Cancel Order' ), 'Both deliberate decisions are available' );
    check( $workflow->repo->get( (int) $row['id'] ) === $before && $workflow->freshOrder( (int) $row['order_id'] )->get_status() === 'on-hold', 'Scanner GET with a decision parameter causes no business mutation' );
    check( str_contains( strtolower( $get['headers'] ), 'cache-control: no-store' ) && str_contains( strtolower( $get['headers'] ), 'referrer-policy: no-referrer' ) && str_contains( strtolower( $get['headers'] ), 'x-robots-tag: noindex' ), 'Sensitive-page privacy headers' );
    check( ! str_contains( $get['body'], 'http-fixture@example.test' ) && ! str_contains( $get['body'], '<script' ), 'No customer PII or third-party scripts in page' );
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
    $postUrl = admin_url( 'admin-post.php' );
    $post = [ 'action' => 'codverity_verify', 'token' => $query['token'], 'decision' => 'confirm' ];
    check( request( $postUrl, $post )['status'] === 403, 'POST without form challenge rejected' );
    preg_match( '/name="challenge" value="([a-f0-9]+)"/', $get['body'], $match ); $post['challenge'] = $match[1];
    $confirmation = request( $postUrl, $post ); $after = $workflow->repo->get( (int) $row['id'] );
    check( $confirmation['status'] === 200 && $after['state'] === 'confirmed', 'HTTP confirmation changes the order exactly once (HTTP ' . $confirmation['status'] . ', ' . $after['state'] . ', ' . $after['error_code'] . ')' );
    check( request( $postUrl, [ ...$post, 'decision' => 'cancel' ] )['status'] === 410 && $workflow->repo->get( (int) $row['id'] )['state'] === 'confirmed', 'HTTP replay cannot reverse confirmation' );
    check( request( $postUrl . '?action=codverity_verify&token%5B%5D=bad' )['status'] === 400, 'Array token input rejected' );
    check( request( $postUrl . '?action=codverity_verify&token=' . str_repeat( 'f', 64 ) )['status'] === 404, 'Unknown token rejected without order information' );
    check( request( $postUrl . '?action=codverity_verify&token=%3Cscript%3E' )['status'] === 400, 'XSS token input rejected' );
    $expired = fixture(); $workflow->repo->update( (int) $expired['id'], [ 'expires_at' => time() - 1 ] );
    check( request( $plugin->notifications->url( $expired ) )['status'] === 410 && $workflow->repo->get( (int) $expired['id'] )['state'] === 'pending', 'Expired GET is read-only and unavailable' );
    $cancel = fixture(); $cancelToken = $workflow->tokens->derive( (int) $cancel['order_id'], 1, $cancel['seed'] );
    $cancelPost = [ 'action' => 'codverity_verify', 'token' => $cancelToken, 'decision' => 'cancel', 'challenge' => $workflow->tokens->challenge( $cancelToken, (int) floor( time() / 1800 ) ) ];
    check( request( $postUrl, $cancelPost )['status'] === 200 && $workflow->freshOrder( (int) $cancel['order_id'] )->get_status() === 'cancelled', 'HTTP customer cancellation' );

    $session = WP_Session_Tokens::get_instance( 1 )->create( time() + HOUR_IN_SECONDS );
    $auth = wp_generate_auth_cookie( 1, time() + HOUR_IN_SECONDS, 'auth', $session );
    $logged = wp_generate_auth_cookie( 1, time() + HOUR_IN_SECONDS, 'logged_in', $session );
    $cookie = AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged;
    $_COOKIE[ LOGGED_IN_COOKIE ] = $logged; wp_set_current_user( 1 );
    $nonce = wp_create_nonce( 'codverity_admin' );
    foreach ( [ 'overview', 'queue', 'settings', 'reports', 'logs', 'diagnostics' ] as $tab ) {
        $page = request( admin_url( 'admin.php?page=codverity&tab=' . $tab ), null, $cookie );
        check( $page['status'] === 200 && str_contains( $page['body'], 'CODVerity' ) && ! str_contains( $page['body'], 'Fatal error' ), "Authorized admin $tab page renders" );
    }
    $export = [ 'action' => 'codverity_admin', 'operation' => 'export', '_wpnonce' => $nonce ];
    check( request( $postUrl, [ ...$export, '_wpnonce' => 'invalid' ], $cookie )['status'] === 403, 'Invalid admin nonce rejected' );
    $missingNonce = $export; unset( $missingNonce['_wpnonce'] );
    check( request( $postUrl, $missingNonce, $cookie )['status'] === 403, 'Missing admin nonce rejected' );
    $csv = request( $postUrl, $export, $cookie );
    check( $csv['status'] === 200 && str_contains( $csv['headers'], 'text/csv' ) && str_starts_with( $csv['body'], 'order_id,state,delivery' ) && ! str_contains( $csv['body'], 'token_hash' ), 'Authorized CSV exports operational fields only' );
    $settings = array_replace( CODVerity\Settings::defaults(), [ 'enabled' => true, 'expiry_hours' => 71 ] );
    $settingsPost = [ 'option_page' => 'codverity', 'action' => 'update', '_wpnonce' => wp_create_nonce( 'codverity-options' ), 'codverity_settings' => $settings ];
    $settingsSave = request( admin_url( 'options.php' ), $settingsPost, $cookie );
    // Discard option cache held by this test process after the independent HTTP write.
    wp_cache_delete( CODVerity\Settings::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
    check( $settingsSave['status'] === 302 && (int) get_option( CODVerity\Settings::OPTION )['expiry_hours'] === 71, 'Settings API persists a validated merchant configuration' );
    $settingsPost['codverity_settings']['expiry_hours'] = 0;
    request( admin_url( 'options.php' ), $settingsPost, $cookie ); wp_cache_delete( CODVerity\Settings::OPTION, 'options' ); wp_cache_delete( 'alloptions', 'options' );
    check( (int) get_option( CODVerity\Settings::OPTION )['expiry_hours'] === 71, 'Invalid settings are rejected while preserving the previous configuration' );
    $subscriber = wp_create_user( 'codv-http-subscriber-' . uniqid(), wp_generate_password(), 'subscriber-' . uniqid() . '@example.test' );
    try {
        $sToken = WP_Session_Tokens::get_instance( $subscriber )->create( time() + HOUR_IN_SECONDS );
        $sCookie = AUTH_COOKIE . '=' . wp_generate_auth_cookie( $subscriber, time() + HOUR_IN_SECONDS, 'auth', $sToken ) . '; ' . LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( $subscriber, time() + HOUR_IN_SECONDS, 'logged_in', $sToken );
        check( request( $postUrl, $export, $sCookie )['status'] === 403, 'Subscriber cannot export or mutate verification records' );
    } finally { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber ); }
    $manual = fixture();
    $manualPost = [ 'action' => 'codverity_admin', 'operation' => 'order', 'order_id' => $manual['order_id'], 'order_action' => 'confirm', 'reason' => 'Telephone confirmation', 'acknowledge' => '1', '_wpnonce' => $nonce ];
    check( request( $postUrl, $manualPost, $cookie )['status'] === 302 && $workflow->repo->get( (int) $manual['id'] )['state'] === 'manually_confirmed', 'Authorized manual action performs a real safe transition' );
    $testMail = request( $postUrl, [ 'action' => 'codverity_admin', 'operation' => 'test', 'destination' => 'invalid', '_wpnonce' => $nonce ], $cookie );
    check( $testMail['status'] === 302, 'Invalid test-email destination produces recoverable admin feedback' );
    echo "$checks HTTP checks passed\n";
} finally {
    foreach ( $orders as $id ) { $row = $workflow->repo->byOrder( $id ); if ( $row ) { $plugin->scheduler->cancel( $row ); $workflow->repo->erase( $row ); } $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    foreach ( $products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
    update_option( CODVerity\Settings::OPTION, CODVerity\Settings::defaults() );
}
