<?php
require __DIR__ . '/bootstrap.php';
$plugin = CODVerity\Plugin::$instance; $workflow = $plugin->workflow;
update_option( CODVerity\Settings::OPTION, array_replace( CODVerity\Settings::defaults(), [ 'enabled' => true ] ) );
update_option( 'codverity_installed_at', time() - 10 );
$product = new WC_Product_Simple(); $product->set_name( 'Race fixture' ); $product->set_regular_price( '20' ); $product->save();
$ids = [];
try {
    foreach ( [ [ 'confirm', 'cancel' ], [ 'confirm', 'confirm' ], [ 'cancel', 'confirm' ] ] as $round => $decisions ) {
        $order = wc_create_order(); $ids[] = $order->get_id(); $order->set_payment_method( 'cod' ); $order->set_billing_email( 'race@example.test' ); $order->add_product( $product ); $order->calculate_totals(); $order->save();
        ( new WC_Gateway_COD() )->process_payment( $order->get_id() ); $row = $workflow->repo->byOrder( $order->get_id() );
        $barrier = dirname( __DIR__ ) . '/build/race-' . bin2hex( random_bytes( 8 ) ); $processes = [];
        foreach ( $decisions as $decision ) {
            $process = proc_open( [ PHP_BINARY, __DIR__ . '/decision-worker.php', (string) $row['id'], $decision, $barrier ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
            $processes[] = [ $process, $pipes ];
        }
        usleep( 500000 ); touch( $barrier ); $results = [];
        foreach ( $processes as [ $process, $pipes ] ) {
            fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
            $exit = proc_close( $process ); if ( $exit !== 0 ) { throw new RuntimeException( "Worker failed: $error" ); }
            $results[] = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
        }
        unlink( $barrier );
        wp_cache_flush_runtime();
        $final = $workflow->repo->get( (int) $row['id'] ); $status = $workflow->freshOrder( $order->get_id() )->get_status();
        $wins = array_filter( $results, static fn( array $result ): bool => in_array( $result['result'], [ 'confirmed', 'cancelled' ], true ) );
        if ( count( $wins ) !== 1 || ! in_array( $final['state'], [ 'confirmed', 'cancelled' ], true ) || $status !== ( $final['state'] === 'confirmed' ? 'processing' : 'cancelled' ) ) { throw new RuntimeException( 'Contradictory decisions or order state: ' . json_encode( $results ) ); }
        echo 'PASS: concurrent round ' . ( $round + 1 ) . ' — ' . json_encode( $results ) . "\n";
    }
} finally {
    foreach ( $ids as $id ) { $row = $workflow->repo->byOrder( $id ); if ( $row ) { $plugin->scheduler->cancel( $row ); $workflow->repo->erase( $row ); } $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    $product->delete( true ); update_option( CODVerity\Settings::OPTION, CODVerity\Settings::defaults() );
}
