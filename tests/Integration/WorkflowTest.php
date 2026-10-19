<?php
use CODVerity\Plugin;
use CODVerity\Settings;
use CODVerity\Installer;
use CODVerity\State;
use CODVerity\Privacy;
use CODVerity\PublicEndpoint;
use CODVerity\Scheduler;
use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase {
    private array $orders = [];
    private array $products = [];
    private array $messages = [];
    private bool $mailResult = true;
    private mixed $mailFilter;
    private CODVerity\Workflow $workflow;

    protected function setUp(): void {
        if ( ! getenv( 'CODVERITY_WP_ROOT' ) ) { self::markTestSkipped( 'Set CODVERITY_WP_ROOT to a disposable activated WordPress installation.' ); }
        $this->workflow = Plugin::$instance->workflow;
        update_option( Settings::OPTION, array_replace( Settings::defaults(), [ 'enabled' => true ] ) );
        update_option( 'codverity_installed_at', time() - 10 );
        wp_set_current_user( 1 );
        $this->mailFilter = function ( mixed $result, array $atts ): bool {
            if ( str_contains( $atts['message'], 'action=codverity_verify' ) ) { $this->messages[] = $atts; }
            return $this->mailResult;
        };
        add_filter( 'pre_wp_mail', $this->mailFilter, 10, 2 );
    }
    protected function tearDown(): void {
        if ( ! isset( $this->workflow ) ) { return; }
        remove_filter( 'pre_wp_mail', $this->mailFilter );
        foreach ( $this->orders as $id ) {
            $row = $this->workflow->repo->byOrder( $id );
            if ( $row ) { Plugin::$instance->scheduler->cancel( $row ); $this->workflow->repo->erase( $row ); }
            $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); }
        }
        foreach ( $this->products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
        update_option( Settings::OPTION, Settings::defaults() );
    }
    private function setting( string $key, mixed $value ): void { $s = $this->workflow->settings->all(); $s[ $key ] = $value; update_option( Settings::OPTION, $s ); }
    private function order( string $method = 'cod', bool $downloadable = false, bool $gateway = true ): WC_Order {
        $product = new WC_Product_Simple(); $product->set_name( 'Integration product' ); $product->set_regular_price( '25.00' );
        $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->set_downloadable( $downloadable );
        if ( $downloadable ) { $download = new WC_Product_Download(); $download->set_name( 'fixture' ); $download->set_file( 'https://example.test/file.txt' ); $product->set_downloads( [ $download ] ); }
        $product->save(); $this->products[] = $product->get_id();
        $order = wc_create_order(); $this->orders[] = $order->get_id();
        $order->set_payment_method( $method ); $order->set_billing_email( 'customer@example.test' ); $order->set_billing_first_name( 'Customer' );
        $order->set_billing_phone( '+98 (912) 000-1234' ); $order->set_billing_country( 'IR' ); $order->set_currency( 'USD' );
        $order->add_product( $product, 2 ); $order->calculate_totals(); $order->save();
        if ( $gateway && $method === 'cod' ) { ( new WC_Gateway_COD() )->process_payment( $order->get_id() ); }
        return $this->workflow->freshOrder( $order->get_id() );
    }
    private function row( WC_Order $order ): array { return $this->workflow->repo->byOrder( $order->get_id() ); }
    private function send( array $row, int $slot = 0, int $attempt = 0 ): void { Plugin::$instance->scheduler->send( (int) $row['id'], (int) $row['generation'], $slot, $attempt ); }

    /** CODV-001, CODV-003, CODV-009, CODV-026, CODV-059. */
    public function testRealActivationSchemaSchedulerAndDatastore(): void {
        Installer::migrate(); self::assertSame( '1', get_option( 'codverity_schema' ) ); self::assertTrue( Scheduler::ready() );
        Plugin::$instance->scheduler->ensure(); self::assertTrue( as_has_scheduled_action( 'codverity_reconcile', [], 'codverity' ) );
        if ( getenv( 'CODVERITY_STORAGE' ) ) { self::assertSame( getenv( 'CODVERITY_STORAGE' ) === 'hpos', Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ); }
    }
    /** CODV-005, CODV-011, CODV-012, CODV-022, CODV-028. */
    public function testGatewayHoldConfirmationNoPaymentAndSingleStockReduction(): void {
        $order = $this->order(); $row = $this->row( $order ); self::assertSame( 'on-hold', $order->get_status() ); self::assertSame( '1', (string) $row['hold_owned'] );
        $this->send( $row ); self::assertCount( 1, $this->messages ); self::assertSame( 'awaiting_response', $this->row( $order )['state'] );
        self::assertSame( 'confirmed', $this->workflow->decide( (int) $row['id'], 'confirm' ) );
        $order = $this->workflow->freshOrder( $order->get_id() ); self::assertSame( 'processing', $order->get_status() ); self::assertNull( $order->get_date_paid() ); self::assertSame( '', $order->get_transaction_id() );
        $product = wc_get_product( $this->products[0] ); self::assertSame( 8, $product->get_stock_quantity() );
        self::assertSame( 'already_resolved', $this->workflow->decide( (int) $row['id'], 'cancel' ) );
        $this->send( $row ); self::assertCount( 1, $this->messages ); self::assertSame( 8, wc_get_product( $product->get_id() )->get_stock_quantity() );
    }
    /** CODV-011, CODV-012. */
    public function testNativeDownloadableHoldIsNotReleased(): void {
        $order = $this->order( 'cod', true ); $row = $this->row( $order ); self::assertSame( 'on-hold', $row['intended_status'] );
        $this->workflow->decide( (int) $row['id'], 'confirm' ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $order->get_id() )->get_status() );
    }
    /** CODV-007, CODV-013, CODV-022. */
    public function testCancellationUsesWooCommerceStockRestorationOnce(): void {
        $order = $this->order(); $row = $this->row( $order ); self::assertSame( 'cancelled', $this->workflow->decide( (int) $row['id'], 'cancel' ) );
        self::assertSame( 'cancelled', $this->workflow->freshOrder( $order->get_id() )->get_status() ); self::assertSame( 10, wc_get_product( $this->products[0] )->get_stock_quantity() );
        self::assertSame( 'already_resolved', $this->workflow->decide( (int) $row['id'], 'confirm' ) ); self::assertSame( 10, wc_get_product( $this->products[0] )->get_stock_quantity() );
        ( new WC_Gateway_COD() )->process_payment( $order->get_id() );
        self::assertSame( 'cancelled', $this->workflow->freshOrder( $order->get_id() )->get_status() ); self::assertSame( 10, wc_get_product( $this->products[0] )->get_stock_quantity() );
    }
    /** CODV-005, CODV-006, CODV-008. */
    public function testNonCodZeroTotalsAndHistoricalOrdersNotEnrolled(): void {
        $order = $this->order( 'bacs' ); self::assertNull( $this->workflow->repo->byOrder( $order->get_id() ) );
        $order = $this->order( 'cod', false, false ); $order->set_date_created( time() - DAY_IN_SECONDS ); $order->save();
        ( new WC_Gateway_COD() )->process_payment( $order->get_id() ); self::assertNull( $this->workflow->repo->byOrder( $order->get_id() ) );
        $order = $this->order( 'cod', false, false ); $order->set_total( 0 ); $order->save();
        self::assertSame( 'no_payable_total', $this->workflow->eligibility->evaluate( $order ) );
    }
    /** CODV-007, CODV-027. */
    public function testDuplicateGatewayAndMailJobsAreIdempotent(): void {
        $order = $this->order(); $row = $this->row( $order ); $before = $this->workflow->repo->count();
        ( new WC_Gateway_COD() )->process_payment( $order->get_id() ); self::assertSame( $before, $this->workflow->repo->count() );
        $this->send( $row ); $this->send( $row ); self::assertCount( 1, $this->messages );
        self::assertSame( '1', (string) $this->row( $order )['attempts'] );
    }
    /** CODV-027, CODV-028. */
    public function testRetriesAreBoundedAndReplayCannotAdvanceAttempts(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->mailResult = false;
        for ( $attempt = 0; $attempt <= 3; $attempt++ ) {
            $this->workflow->repo->update( (int) $row['id'], [ 'next_send_at' => time() - 1 ] );
            $this->send( $row, 0, $attempt );
        }
        $row = $this->row( $order ); self::assertSame( 'exhausted', $row['delivery'] ); self::assertSame( '4', (string) $row['attempts'] );
        self::assertSame( '4', (string) $row['transport_failures'] );
        $this->send( $row, 0, 3 ); self::assertCount( 4, $this->messages );
    }
    /** CODV-029. */
    public function testReminderUsesSameLinkAndCannotRepeat(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->send( $row );
        $this->workflow->repo->update( (int) $row['id'], [ 'generation_at' => time() - 25 * HOUR_IN_SECONDS ] );
        $this->send( $row, 1 ); $this->send( $row, 1 ); self::assertCount( 2, $this->messages );
        preg_match( '/https?:\/\/[^\s]+action=codverity_verify[^\s]+/', $this->messages[0]['message'], $a );
        preg_match( '/https?:\/\/[^\s]+action=codverity_verify[^\s]+/', $this->messages[1]['message'], $b ); self::assertSame( $a, $b );
    }
    /** CODV-018, CODV-032, CODV-033. */
    public function testExpiredCustomerCannotClaimAndDefaultDoesNotCancel(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->workflow->repo->update( (int) $row['id'], [ 'expires_at' => time() - 1 ] );
        self::assertSame( 'expired', $this->workflow->decide( (int) $row['id'], 'confirm' ) );
        $this->workflow->expire( (int) $row['id'], 1 ); self::assertSame( 'expired', $this->row( $order )['state'] ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $order->get_id() )->get_status() );
        $this->send( $row ); self::assertCount( 0, $this->messages );
    }
    /** CODV-034, CODV-035. */
    public function testOptInExpirationCancellationOnlyPluginOwnedHolds(): void {
        $this->setting( 'auto_cancel', true ); $order = $this->order(); $row = $this->row( $order );
        $this->workflow->repo->update( (int) $row['id'], [ 'expires_at' => time() - 1 ] ); $this->workflow->expire( (int) $row['id'], 1 );
        self::assertSame( 'expired', $this->row( $order )['state'] ); self::assertSame( 'cancelled', $this->workflow->freshOrder( $order->get_id() )->get_status() );
        $native = $this->order( 'cod', true ); $nativeRow = $this->row( $native ); $this->workflow->repo->update( (int) $nativeRow['id'], [ 'expires_at' => time() - 1 ] );
        $this->workflow->expire( (int) $nativeRow['id'], 1 ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $native->get_id() )->get_status() );
    }
    /** CODV-015, CODV-042. */
    public function testStaffChangeInterruptsAndCompletedOrderCannotBeCancelled(): void {
        $order = $this->order(); $row = $this->row( $order ); $order->update_status( 'completed' );
        self::assertSame( 'interrupted', $this->row( $order )['state'] );
        self::assertSame( 'interrupted', $this->workflow->decide( (int) $row['id'], 'cancel', 'staff', 1, 'Review' ) );
        self::assertSame( 'completed', $this->workflow->freshOrder( $order->get_id() )->get_status() );
    }
    /** CODV-015, CODV-058. */
    public function testOrderEditsInvalidateOriginalIntent(): void {
        $order = $this->order(); $order->set_total( '60.00' ); $order->save(); self::assertSame( 'interrupted', $this->row( $order )['state'] );
    }
    /** CODV-017, CODV-022, CODV-030. */
    public function testReissueRotatesTokenAndStaleGenerationIsInert(): void {
        $order = $this->order(); $row = $this->row( $order ); $old = $row['token_hash'];
        try { $this->workflow->reissue( $order->get_id(), 'Resend' ); self::fail( 'Expected cooldown' ); } catch ( RuntimeException $e ) { self::assertSame( 'resend_cooldown', $e->getMessage() ); }
        $this->workflow->repo->update( (int) $row['id'], [ 'generation_at' => time() - 301 ] );
        $this->workflow->reissue( $order->get_id(), 'Customer requested new link' );
        $new = $this->row( $order ); self::assertSame( '2', (string) $new['generation'] ); self::assertNotSame( $old, $new['token_hash'] ); self::assertNull( $this->workflow->repo->byHash( $old ) );
        $this->send( $row ); $this->workflow->expire( (int) $row['id'], 1 ); self::assertCount( 0, $this->messages ); self::assertSame( 'pending', $this->row( $order )['state'] );
        $this->send( $new ); self::assertCount( 1, $this->messages );
    }
    /** CODV-044. */
    public function testDisabledAutomationStillAllowsExistingResponse(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->setting( 'enabled', false );
        $other = $this->order(); self::assertNull( $this->workflow->repo->byOrder( $other->get_id() ) );
        self::assertSame( 'confirmed', $this->workflow->decide( (int) $row['id'], 'confirm' ) );
    }
    /** CODV-010, CODV-014, CODV-016. */
    public function testReconcileCompletesCrashAfterClaimAndPreservesFirstDecision(): void {
        $order = $this->order(); $row = $this->row( $order );
        self::assertTrue( $this->workflow->repo->transition( $row, 'confirming', [ 'target_status' => 'processing', 'operation' => str_repeat( 'a', 32 ), 'actor' => 'customer', 'response_at' => time() ] ) );
        self::assertFalse( $this->workflow->repo->transition( $row, 'cancelling' ) );
        Plugin::$instance->scheduler->reconcile(); self::assertSame( 'confirmed', $this->row( $order )['state'] ); self::assertSame( 'processing', $this->workflow->freshOrder( $order->get_id() )->get_status() );
    }
    /** CODV-014, CODV-016. */
    public function testCrashAfterOrderWriteRecognizedByPersistentMarker(): void {
        $order = $this->order(); $row = $this->row( $order ); $operation = str_repeat( 'c', 32 );
        $this->workflow->repo->transition( $row, 'confirming', [ 'target_status' => 'processing', 'operation' => $operation, 'actor' => 'customer', 'response_at' => time() ] );
        remove_action( 'woocommerce_before_order_object_save', [ $this->workflow, 'beforeOrderSave' ], 1 );
        remove_action( 'woocommerce_order_status_changed', [ $this->workflow, 'statusChanged' ], 10 );
        $order->update_meta_data( '_codverity_operation', [ 'operation' => $operation, 'generation' => 1, 'target' => 'processing' ] ); $order->update_status( 'processing' );
        add_action( 'woocommerce_before_order_object_save', [ $this->workflow, 'beforeOrderSave' ], 1 ); add_action( 'woocommerce_order_status_changed', [ $this->workflow, 'statusChanged' ], 10, 4 );
        Plugin::$instance->scheduler->reconcile(); self::assertSame( 'confirmed', $this->row( $order )['state'] ); self::assertSame( 8, wc_get_product( $this->products[0] )->get_stock_quantity() );
    }
    /** CODV-027, CODV-052, CODV-054. */
    public function testUncertainSendIsNeverAutomaticallyRepeated(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->workflow->repo->update( (int) $row['id'], [ 'delivery' => 'sending', 'send_claim' => str_repeat( 'd', 32 ) ] );
        Plugin::$instance->scheduler->reconcile(); self::assertSame( 'uncertain', $this->row( $order )['delivery'] ); $this->send( $row ); self::assertCount( 0, $this->messages );
    }
    /** CODV-006. */
    public function testDecimalEligibilityAndCategoryPolicies(): void {
        $order = $this->order( 'cod', false, false ); $this->setting( 'min_total', '50.00' ); $this->setting( 'max_total', '50.00' );
        self::assertSame( 'eligible', $this->workflow->eligibility->evaluate( $order ) ); $this->setting( 'max_total', '49.99' ); self::assertSame( 'max_total', $this->workflow->eligibility->evaluate( $order ) );
        $this->setting( 'max_total', '' ); $category = wp_insert_term( 'CODV Test ' . uniqid(), 'product_cat' ); $id = (int) $category['term_id'];
        try {
            $this->setting( 'include_categories', [ $id ] ); self::assertSame( 'included_category_required', $this->workflow->eligibility->evaluate( $order ) );
            wp_set_object_terms( $this->products[0], [ $id ], 'product_cat' ); clean_object_term_cache( $this->products[0], 'product' );
            self::assertSame( 'eligible', $this->workflow->eligibility->evaluate( $order ) ); $this->setting( 'exclude_categories', [ $id ] ); self::assertSame( 'excluded_category', $this->workflow->eligibility->evaluate( $order ) );
        } finally { wp_delete_term( $id, 'product_cat' ); }
    }
    /** CODV-008, CODV-042. */
    public function testManualStartResolutionAndStop(): void {
        $order = $this->order( 'cod', false, false ); $order->update_status( 'processing' ); $row = $this->workflow->start( $order->get_id() );
        self::assertSame( 'on-hold', $this->workflow->freshOrder( $order->get_id() )->get_status() );
        self::assertSame( 'manually_confirmed', $this->workflow->decide( (int) $row['id'], 'confirm', 'staff', 1, 'Phone confirmation' ) );
        $other = $this->order(); $this->workflow->stop( $other->get_id() ); self::assertSame( 'interrupted', $this->row( $other )['state'] ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $other->get_id() )->get_status() );
    }
    /** CODV-011, CODV-013. */
    public function testNoHoldModeRecordsConfirmationWithoutCancellingProcessing(): void {
        $this->setting( 'hold_enabled', false ); $order = $this->order(); $row = $this->row( $order ); self::assertSame( 'processing', $order->get_status() );
        try { $this->workflow->decide( (int) $row['id'], 'cancel' ); self::fail( 'Unsafe cancellation' ); } catch ( RuntimeException $e ) { self::assertSame( 'cancellation_requires_hold', $e->getMessage() ); }
        self::assertSame( 'confirmed', $this->workflow->decide( (int) $row['id'], 'confirm' ) ); self::assertNull( $this->workflow->freshOrder( $order->get_id() )->get_date_paid() );
    }
    /** CODV-060. */
    public function testPrivacyExportErasureAndRetention(): void {
        $order = $this->order(); $privacy = new Privacy( $this->workflow ); $export = $privacy->export( 'customer@example.test' ); self::assertNotEmpty( $export['data'] );
        $encoded = wp_json_encode( $export ); self::assertStringNotContainsString( 'token_hash', $encoded ); self::assertStringNotContainsString( 'seed', $encoded );
        $result = $privacy->erase( 'customer@example.test' ); self::assertTrue( $result['items_removed'] ); self::assertNull( $this->workflow->repo->byOrder( $order->get_id() ) ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $order->get_id() )->get_status() );
    }
    /** CODV-025, CODV-043, CODV-058. */
    public function testSettingsRejectHeaderInjectionAndUnsafeBounds(): void {
        foreach ( [ [ 'email_subject' => "Subject\r\nBcc: attacker@example.test" ], [ 'expiry_hours' => 0 ], [ 'min_total' => '90', 'max_total' => '1' ], [ 'email_body' => 'Missing link' ], [ 'retry_limit' => 99 ] ] as $changes ) {
            try { $this->workflow->settings->validate( array_replace( Settings::defaults(), $changes ) ); self::fail( 'Invalid settings accepted' ); } catch ( InvalidArgumentException $e ) { self::assertNotEmpty( $e->getMessage() ); }
        }
        self::assertSame( 72, $this->workflow->settings->validate( Settings::defaults() )['expiry_hours'] );
    }
    /** CODV-021, CODV-046, CODV-047, CODV-050, CODV-051, CODV-052. */
    public function testReportingPaginationAuditRedactionAndRateLimit(): void {
        $order = $this->order(); $row = $this->row( $order ); $this->send( $row );
        self::assertCount( 1, $this->workflow->repo->rows( [ 'order_id' => $order->get_id() ], 1, 1 ) );
        self::assertNotEmpty( $this->workflow->repo->statistics( [ 'order_id' => $order->get_id() ] ) );
        $history = wp_json_encode( $this->workflow->repo->history( (int) $row['id'] ) ); self::assertStringNotContainsString( $row['seed'], $history ); self::assertStringNotContainsString( 'customer@example.test', $history );
        $endpoint = new PublicEndpoint( $this->workflow ); $ip = 'test-' . uniqid(); for ( $i = 0; $i < 60; $i++ ) { self::assertTrue( $endpoint->rateAllowed( $ip ) ); } self::assertFalse( $endpoint->rateAllowed( $ip ) );
    }
    /** CODV-036, CODV-037. */
    public function testDuplicateFingerprintIsAdvisoryAndContainsNoPhone(): void {
        $first = $this->order(); $second = wc_create_order(); $this->orders[] = $second->get_id();
        $second->set_payment_method( 'cod' ); $second->set_billing_email( 'customer@example.test' ); $second->set_billing_phone( '+98 912 000 1234' ); $second->set_billing_country( 'IR' ); $second->set_currency( 'USD' );
        $second->add_product( wc_get_product( $this->products[0] ), 2 ); $second->calculate_totals(); $second->save(); ( new WC_Gateway_COD() )->process_payment( $second->get_id() );
        $row = $this->row( $second ); self::assertSame( '1', (string) $row['duplicate_warning'] ); self::assertSame( $this->row( $first )['fingerprint'], $row['fingerprint'] );
        self::assertStringNotContainsString( '9120001234', wp_json_encode( $row ) ); self::assertSame( 'on-hold', $this->workflow->freshOrder( $second->get_id() )->get_status() );
        self::assertContains( $first->get_id(), $this->workflow->repo->matches( $row['fingerprint'], $second->get_id(), time() - DAY_IN_SECONDS ) );
    }
    /** CODV-006. */
    public function testVariationUsesParentCategories(): void {
        $parent = new WC_Product_Variable(); $parent->set_name( 'Variable fixture' ); $parent->save(); $this->products[] = $parent->get_id();
        $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( '12' ); $variation->save(); $this->products[] = $variation->get_id();
        $category = wp_insert_term( 'CODV Parent ' . uniqid(), 'product_cat' ); $id = (int) $category['term_id'];
        try {
            wp_set_object_terms( $parent->get_id(), [ $id ], 'product_cat' ); $this->setting( 'include_categories', [ $id ] );
            $order = wc_create_order(); $this->orders[] = $order->get_id(); $order->set_payment_method( 'cod' ); $order->add_product( $variation, 1 ); $order->calculate_totals(); $order->save();
            self::assertSame( 'eligible', $this->workflow->eligibility->evaluate( $order ) );
            $this->setting( 'exclude_categories', [ $id ] ); self::assertSame( 'excluded_category', $this->workflow->eligibility->evaluate( $order ) );
        } finally { wp_delete_term( $id, 'product_cat' ); }
    }
    /** CODV-053, CODV-060. */
    public function testRetentionPreservesActiveAndScrubsOldResolvedRequests(): void {
        $active = $this->order(); $resolved = $this->order(); $row = $this->row( $resolved );
        $this->workflow->decide( (int) $row['id'], 'confirm', 'staff', 1, 'Private staff note' );
        $this->workflow->repo->update( (int) $row['id'], [ 'resolved_at' => time() - 366 * DAY_IN_SECONDS ] );
        $this->workflow->repo->prune( $this->workflow->settings );
        $pruned = $this->row( $resolved ); self::assertSame( '', $pruned['seed'] ); self::assertSame( '', $pruned['reason'] ); self::assertSame( '', $pruned['fingerprint'] );
        self::assertNotSame( '', $this->row( $active )['seed'] ); self::assertSame( 'pending', $this->row( $active )['state'] );
    }
    /** CODV-055, CODV-062. */
    public function testDeactivationIsNondestructiveAndReactivationRepairsJobs(): void {
        $order = $this->order(); $row = $this->row( $order ); Installer::deactivate();
        self::assertSame( 'on-hold', $this->workflow->freshOrder( $order->get_id() )->get_status() ); self::assertSame( 'pending', $this->row( $order )['state'] );
        self::assertFalse( as_has_scheduled_action( 'codverity_expire', [ (int) $row['id'], 1 ], 'codverity' ) );
        Installer::activate(); Plugin::$instance->scheduler->ensure(); Plugin::$instance->scheduler->reconcile();
        self::assertTrue( as_has_scheduled_action( 'codverity_expire', [ (int) $row['id'], 1 ], 'codverity' ) );
    }
    /** CODV-026, CODV-027, CODV-055. */
    public function testRealActionSchedulerDispatchesAndDuplicateJobIsInert(): void {
        $order = $this->order(); $row = $this->row( $order );
        $args = [ (int) $row['id'], (int) $row['generation'], 0, 0 ];
        $action = as_schedule_single_action( time() - 5, 'codverity_send', $args, 'codverity', false ); self::assertGreaterThan( 0, $action );
        ActionScheduler::runner()->process_action( $action, 'CODVerity acceptance' ); self::assertSame( 'complete', ActionScheduler::store()->get_status( $action ) ); self::assertSame( 'accepted', $this->row( $order )['delivery'] ); self::assertCount( 1, $this->messages );
        $duplicate = as_schedule_single_action( time() - 5, 'codverity_send', $args, 'codverity', false ); ActionScheduler::runner()->process_action( $duplicate, 'CODVerity replay acceptance' );
        self::assertCount( 1, $this->messages );
    }
    /** CODV-046, CODV-047. */
    public function testReportDateRangeRespectsSiteTimezoneAndDst(): void {
        $original = get_option( 'timezone_string' ); update_option( 'timezone_string', 'America/New_York' );
        try {
            $filters = Plugin::$instance->admin->filters( [ 'from' => '2026-11-01', 'to' => '2026-11-01' ] );
            self::assertSame( 25 * HOUR_IN_SECONDS - 1, $filters['to'] - $filters['from'] );
            self::assertSame( '2026-11-01 23:59:59', wp_date( 'Y-m-d H:i:s', $filters['to'] ) );
            self::assertArrayNotHasKey( 'from', Plugin::$instance->admin->filters( [ 'from' => '2026-02-31' ] ) );
        } finally { update_option( 'timezone_string', $original ); }
    }
    /** CODV-011, CODV-012, CODV-015. */
    public function testConflictingPaymentFilterCannotMarkCodPaidOnRelease(): void {
        $order = $this->order(); $row = $this->row( $order );
        $conflict = static fn(): string => 'processing'; add_filter( 'woocommerce_payment_complete_order_status', $conflict, PHP_INT_MAX, 3 );
        try {
            self::assertSame( 'interrupted', $this->workflow->decide( (int) $row['id'], 'confirm' ) );
            $order = $this->workflow->freshOrder( $order->get_id() ); self::assertSame( 'on-hold', $order->get_status() ); self::assertNull( $order->get_date_paid() );
            self::assertSame( 'payment_semantics_conflict', $this->row( $order )['error_code'] );
        } finally { remove_filter( 'woocommerce_payment_complete_order_status', $conflict, PHP_INT_MAX ); }
    }
}
