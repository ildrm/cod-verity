<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** Coordinates claims and WooCommerce CRUD, with persisted intent for crash recovery. */
final class Workflow {
    private ?Scheduler $scheduler = null;
    private string $runningOperation = '';
    private array $saveLocks = [];

    public function __construct(
        public readonly Repository $repo,
        public readonly Settings $settings,
        public readonly Token $tokens,
        public readonly Eligibility $eligibility,
    ) {}

    public function setScheduler( Scheduler $scheduler ): void { $this->scheduler = $scheduler; }

    /** Serialize ordinary WooCommerce CRUD saves with decisions, including staff requests. */
    public function beforeOrderSave( \WC_Order $order ): void {
        if ( ! $order->get_id() ) { return; }
        $this->repo->acquire( $order->get_id() );
        $key = spl_object_id( $order );
        $this->saveLocks[ $key ] = ( $this->saveLocks[ $key ] ?? 0 ) + 1;
        $row = $this->repo->byOrder( $order->get_id() );
        if ( ! $row || ( ! State::active( $row['state'] ) && ! in_array( $row['state'], State::CLAIMED, true ) ) ) { return; }
        if ( $row['operation'] !== '' && $this->runningOperation === $row['operation'] ) { return; }
        $changes = $order->get_changes();
        $statusConflict = isset( $changes['status'] ) && $changes['status'] !== $row['expected_status'];
        if ( $statusConflict || ! hash_equals( $row['order_signature'], $this->signature( $order ) ) || $order->get_date_paid() || $order->get_meta( '_codverity_excluded', true ) === 'yes' ) {
            $this->interruptRow( $row, 'external_order_edit' );
        }
    }
    public function afterOrderSave( \WC_Order $order ): void {
        $key = spl_object_id( $order );
        if ( ! empty( $this->saveLocks[ $key ] ) ) { --$this->saveLocks[ $key ]; $this->repo->release( $order->get_id() ); }
    }

    public function freshOrder( int $id ): ?\WC_Order {
        $order = wc_get_order( $id );
        if ( ! $order instanceof \WC_Order || $order->get_type() !== 'shop_order' ) { return null; }
        $order->get_data_store()->read( $order );
        return $order;
    }

    public function signature( \WC_Order $order ): string {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) { continue; }
            $items[] = [ $item->get_product_id(), $item->get_variation_id(), (string) $item->get_quantity(), (string) $item->get_total() ];
        }
        sort( $items );
        return hash_hmac( 'sha256', wp_json_encode( [ $order->get_payment_method(), $order->get_currency(), (string) $order->get_total(), $order->get_billing_email(), $items ] ), wp_salt( 'secure_auth' ) );
    }

    /** Gateway filter persists BEFORE native COD update_status; checkout itself never sends mail. */
    public function gatewayStatus( string $status, \WC_Order $order ): string {
        if ( ! in_array( $status, [ 'processing', 'on-hold' ], true ) ) { return $status; }
        try {
            return $this->repo->locked( $order->get_id(), function () use ( $status, $order ): string {
                $row = $this->repo->byOrder( $order->get_id() );
                if ( $row ) {
                    $current = $this->freshOrder( $order->get_id() );
                    // A retried gateway call must not reopen a cancelled/expired/staff-edited order.
                    return $current && State::active( $row['state'] ) && $current->get_status() === 'pending'
                        ? $row['expected_status'] : ( $current ? $current->get_status() : $order->get_status() );
                }
                $created = $order->get_date_created();
                if ( ! $created || $created->getTimestamp() < (int) get_option( 'codverity_installed_at', time() ) ) { return $status; }
                if ( $this->eligibility->evaluate( $order ) !== 'eligible' ) { return $status; }
                $row = $this->create( $order, $status );
                return $row['expected_status'];
            } );
        } catch ( \Throwable $e ) {
            update_option( 'codverity_last_error', 'enrollment_failed', false );
            // If persistence fails, do not claim an unmanaged hold or alter the gateway's result.
            return $status;
        }
    }

    private function create( \WC_Order $order, string $intended ): array {
        $seed = $this->tokens->seed(); $now = time();
        $owned = $intended === 'processing' && $this->settings->get( 'hold_enabled' );
        $fingerprint = $this->settings->get( 'duplicates' ) ? ( new Duplicates() )->fingerprint( $order ) : '';
        $matches = $this->repo->matches( $fingerprint, $order->get_id(), $now - (int) $this->settings->get( 'duplicate_hours' ) * HOUR_IN_SECONDS );
        $row = $this->repo->insert( [
            'order_id' => $order->get_id(), 'seed' => $seed,
            'token_hash' => $this->tokens->digest( $this->tokens->derive( $order->get_id(), 1, $seed ) ),
            'expires_at' => $now + (int) $this->settings->get( 'expiry_hours' ) * HOUR_IN_SECONDS,
            'intended_status' => $intended, 'expected_status' => $owned ? 'on-hold' : $intended,
            'hold_owned' => (int) $owned, 'created_at' => $now, 'updated_at' => $now, 'generation_at' => $now,
            'next_send_at' => $now, 'fingerprint' => $fingerprint, 'order_signature' => $this->signature( $order ),
            'duplicate_warning' => (int) (bool) $matches,
        ] );
        $this->repo->event( $row, 'request_created', '', '', [ 'eligibility' => 'eligible' ] );
        if ( $matches ) { $this->repo->event( $row, 'duplicate_warning', '', '', [ 'matched_order_ids' => $matches ] ); }
        $this->scheduler?->schedule( $row );
        return $row;
    }

    public function start( int $orderId, string $reason = '' ): array {
        return $this->repo->locked( $orderId, function () use ( $orderId, $reason ): array {
            $order = $this->freshOrder( $orderId );
            if ( ! $order ) { throw new \RuntimeException( 'order_unavailable' ); }
            $eligibility = $this->eligibility->evaluate( $order, (bool) $this->repo->byOrder( $orderId ), true );
            if ( $eligibility !== 'eligible' ) { throw new \RuntimeException( $eligibility ); }
            if ( ! in_array( $order->get_status(), [ 'on-hold', 'processing' ], true ) ) { throw new \RuntimeException( 'unsafe_status' ); }
            $row = $this->create( $order, $order->get_status() );
            $this->repo->update( (int) $row['id'], [ 'reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ) ] );
            if ( $order->get_status() !== $row['expected_status'] ) {
                $operation = bin2hex( random_bytes( 16 ) );
                $this->repo->update( (int) $row['id'], [ 'operation' => $operation, 'target_status' => 'on-hold' ] );
                if ( ! $this->changeOrder( $this->repo->get( (int) $row['id'] ), $order, 'on-hold' ) ) { throw new \RuntimeException( 'payment_semantics_conflict' ); }
                $this->repo->update( (int) $row['id'], [ 'operation' => '', 'target_status' => '' ] );
                $this->repo->event( $row, 'order_held' );
            }
            $this->repo->event( $row, 'staff_start', '', '', [], 'staff', get_current_user_id() );
            return $row;
        } );
    }

    /** Called only after capability/nonce checks or public bearer authentication. */
    public function decide( int $id, string $decision, string $actor = 'customer', int $actorId = 0, string $reason = '', ?int $generation = null ): string {
        if ( ! in_array( $decision, [ 'confirm', 'cancel' ], true ) || ! in_array( $actor, [ 'customer', 'staff' ], true ) ) { throw new \RuntimeException( 'invalid_decision' ); }
        $initial = $this->repo->get( $id );
        if ( ! $initial ) { throw new \RuntimeException( 'invalid_request' ); }
        return $this->repo->locked( (int) $initial['order_id'], function () use ( $id, $decision, $actor, $actorId, $reason, $generation ): string {
            $row = $this->repo->get( $id );
            if ( ! $row || ( $generation !== null && (int) $row['generation'] !== $generation ) ) { throw new \RuntimeException( 'invalid_request' ); }
            $allowed = State::active( $row['state'] ) || ( $actor === 'staff' && in_array( $row['state'], [ 'expired', 'interrupted' ], true ) );
            if ( ! $allowed ) { return 'already_resolved'; }
            if ( $actor === 'customer' && (int) $row['expires_at'] <= time() ) { return 'expired'; }
            $order = $this->freshOrder( (int) $row['order_id'] );
            if ( ! $this->safe( $row, $order ) ) { $this->interruptRow( $row, 'order_changed' ); return 'interrupted'; }
            // Cancellation is only permitted while held. Confirmation without hold only records intent.
            if ( $decision === 'cancel' && $order->get_status() !== 'on-hold' ) { throw new \RuntimeException( 'cancellation_requires_hold' ); }
            $target = $decision === 'cancel' ? 'cancelled' : ( (int) $row['hold_owned'] === 1 ? 'processing' : $row['expected_status'] );
            $claim = $decision === 'confirm' ? 'confirming' : 'cancelling';
            if ( $actor === 'customer' && $decision === 'cancel' ) { $reason = 'customer_requested_cancellation'; }
            $data = [ 'operation' => bin2hex( random_bytes( 16 ) ), 'target_status' => $target, 'actor' => $actor, 'actor_id' => $actorId, 'reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ), 'response_at' => $actor === 'customer' ? time() : 0 ];
            if ( ! $this->repo->transition( $row, $claim, $data ) ) { return 'already_resolved'; }
            return $this->complete( $this->repo->get( $id ) );
        } );
    }

    /** Shared order guard also protects delayed notifications and expiration. */
    public function safe( array $row, ?\WC_Order $order ): bool {
        return $order && $order->get_payment_method() === 'cod' && ! $order->get_date_paid()
            && $order->get_status() === $row['expected_status']
            && in_array( $order->get_status(), [ 'on-hold', 'processing' ], true )
            && $order->get_meta( '_codverity_excluded', true ) !== 'yes'
            && hash_equals( $row['order_signature'], $this->signature( $order ) );
    }

    private function changeOrder( array $row, \WC_Order $order, string $target ): bool {
        // Gateways are lazy-loaded on public/worker requests. The native COD gateway's
        // payment-complete filter must exist before WC_Order::maybe_set_date_paid runs.
        WC()->payment_gateways()->payment_gateways();
        $paidStatus = apply_filters( 'woocommerce_payment_complete_order_status', $order->needs_processing() ? 'processing' : 'completed', $order->get_id(), $order );
        if ( $paidStatus === $target ) {
            $this->interruptRow( $row, 'payment_semantics_conflict' ); return false;
        }
        $current = $this->repo->get( (int) $row['id'] );
        if ( ! $current || $current['state'] !== $row['state'] || $current['operation'] !== $row['operation'] ) { return false; }
        $marker = [ 'operation' => $row['operation'], 'generation' => (int) $row['generation'], 'target' => $target ];
        $this->runningOperation = $row['operation'];
        try {
            $order->update_meta_data( '_codverity_operation', $marker );
            $order->save_meta_data();
            $order->update_status( $target, __( 'CODVerity: customer verification workflow (payment is not collected).', 'codverity-order-verification-for-woocommerce' ) );
            $order->get_data_store()->read( $order );
            if ( $order->get_status() !== $target ) { throw new \RuntimeException( 'order_update_failed' ); }
        } finally { $this->runningOperation = ''; }
        return true;
    }

    /** Persisted operation marker distinguishes crash-after-CRUD from an unrelated staff change. */
    public function complete( array $row ): string {
        if ( ! in_array( $row['state'], State::CLAIMED, true ) ) { return $row['state']; }
        $order = $this->freshOrder( (int) $row['order_id'] );
        $marker = $order?->get_meta( '_codverity_operation', true );
        $alreadyApplied = $order && is_array( $marker ) && hash_equals( $row['operation'], (string) ( $marker['operation'] ?? '' ) )
            && (int) ( $marker['generation'] ?? 0 ) === (int) $row['generation'] && $order->get_status() === $row['target_status']
            && $order->get_payment_method() === 'cod' && ! $order->get_date_paid() && hash_equals( $row['order_signature'], $this->signature( $order ) );
        if ( ! $alreadyApplied && ! $this->safe( $row, $order ) ) { $this->interruptRow( $row, 'transition_conflict' ); return 'interrupted'; }
        if ( ! $alreadyApplied && $row['target_status'] !== $order->get_status() ) {
            if ( ! $this->changeOrder( $row, $order, $row['target_status'] ) ) { return 'interrupted'; }
        }
        $latest = $this->repo->get( (int) $row['id'] );
        if ( ! $latest || $latest['state'] !== $row['state'] ) { return 'interrupted'; }
        $after = $this->freshOrder( (int) $row['order_id'] );
        if ( ! $after || $after->get_status() !== $row['target_status'] || $after->get_payment_method() !== 'cod' || $after->get_date_paid() || ! hash_equals( $row['order_signature'], $this->signature( $after ) ) ) {
            $this->interruptRow( $latest, 'post_transition_conflict' ); return 'interrupted';
        }
        $final = match ( $row['state'] ) {
            'confirming' => $row['actor'] === 'staff' ? 'manually_confirmed' : 'confirmed',
            'cancelling' => $row['actor'] === 'staff' ? 'manually_cancelled' : 'cancelled',
            default => 'expired',
        };
        if ( $this->repo->transition( $row, $final, [ 'resolved_at' => time(), 'error_code' => '', 'next_send_at' => 0 ] ) ) {
            $finished = $this->repo->get( (int) $row['id'] );
            $this->repo->event( $finished, $row['actor'] === 'staff' ? 'staff_override' : $final, $row['state'], '', [], $row['actor'] ?: 'system', (int) $row['actor_id'] );
            $this->scheduler?->cancel( $row );
        }
        return $final;
    }

    public function expire( int $id, int $generation ): void {
        $row = $this->repo->get( $id );
        if ( ! $row ) { return; }
        $this->repo->locked( (int) $row['order_id'], function () use ( $id, $generation ): void {
            $row = $this->repo->get( $id );
            if ( ! $row || (int) $row['generation'] !== $generation || ! State::active( $row['state'] ) || time() < (int) $row['expires_at'] ) { return; }
            $order = $this->freshOrder( (int) $row['order_id'] );
            if ( ! $this->safe( $row, $order ) ) { $this->interruptRow( $row, 'expiry_conflict' ); return; }
            $cancel = $this->settings->get( 'auto_cancel' ) && $order->get_status() === 'on-hold' && (int) $row['hold_owned'] === 1;
            if ( $this->repo->transition( $row, 'expiring', [ 'target_status' => $cancel ? 'cancelled' : $row['expected_status'], 'operation' => bin2hex( random_bytes( 16 ) ), 'actor' => 'expiry' ] ) ) {
                $this->complete( $this->repo->get( $id ) );
            }
        } );
    }

    public function interruptRow( array $row, string $error = 'staff_stopped' ): void {
        if ( in_array( $row['state'], [ 'confirmed', 'cancelled', 'manually_confirmed', 'manually_cancelled', 'interrupted' ], true ) ) { return; }
        if ( $this->repo->transition( $row, 'interrupted', [ 'error_code' => $error, 'resolved_at' => time(), 'next_send_at' => 0, 'actor' => get_current_user_id() ? 'staff' : 'system', 'actor_id' => get_current_user_id() ] ) ) {
            $this->repo->event( $this->repo->get( (int) $row['id'] ), 'workflow_interrupted', $row['state'], $error, [], get_current_user_id() ? 'staff' : 'system', get_current_user_id() );
            $this->scheduler?->cancel( $row );
        }
    }
    public function stop( int $orderId, string $reason = '' ): void {
        $this->repo->locked( $orderId, function () use ( $orderId, $reason ): void {
            $row = $this->repo->byOrder( $orderId );
            if ( ! $row || ! in_array( $row['state'], array_merge( State::ACTIVE, State::CLAIMED, [ 'expired' ] ), true ) ) { throw new \RuntimeException( 'invalid_request' ); }
            $this->repo->update( (int) $row['id'], [ 'reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ) ] );
            $this->interruptRow( $row );
        } );
    }

    public function reissue( int $orderId, string $reason ): void {
        $this->repo->locked( $orderId, function () use ( $orderId, $reason ): void {
            $row = $this->repo->byOrder( $orderId );
            if ( ! $row || ! in_array( $row['state'], [ 'pending', 'awaiting_response', 'expired', 'interrupted' ], true ) ) { throw new \RuntimeException( 'invalid_request' ); }
            if ( time() - (int) $row['generation_at'] < (int) $this->settings->get( 'resend_cooldown' ) ) { throw new \RuntimeException( 'resend_cooldown' ); }
            $order = $this->freshOrder( $orderId );
            if ( ! $this->safe( $row, $order ) || $this->eligibility->evaluate( $order, false, true ) !== 'eligible' ) { throw new \RuntimeException( 'order_changed' ); }
            $seed = $this->tokens->seed(); $generation = (int) $row['generation'] + 1;
            $data = [ 'generation' => $generation, 'generation_at' => time(), 'seed' => $seed,
                'token_hash' => $this->tokens->digest( $this->tokens->derive( $orderId, $generation, $seed ) ),
                'expires_at' => time() + (int) $this->settings->get( 'expiry_hours' ) * HOUR_IN_SECONDS,
                'delivery' => 'queued', 'attempts' => 0, 'slot_attempts' => 0, 'mail_slot' => 0, 'delivered_slot' => -1,
                'reminders' => 0, 'next_send_at' => time(), 'send_claim' => '', 'error_code' => '', 'response_at' => 0,
                'resolved_at' => 0, 'operation' => '', 'target_status' => '', 'actor' => '', 'actor_id' => 0,
                'reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ),
            ];
            if ( ! $this->repo->transition( $row, 'pending', $data ) ) { throw new \RuntimeException( 'request_busy' ); }
            $this->scheduler?->cancel( $row );
            $updated = $this->repo->get( (int) $row['id'] );
            $this->repo->event( $updated, 'token_rotated', $row['state'], '', [ 'generation' => $generation ], 'staff', get_current_user_id() );
            $this->scheduler?->schedule( $updated );
        } );
    }

    /** Observes native gateway hold and staff status changes on both order datastores. */
    public function statusChanged( int $orderId, string $from, string $to, \WC_Order $order ): void {
        try {
            $this->repo->locked( $orderId, function () use ( $orderId, $from, $to, $order ): void {
                $row = $this->repo->byOrder( $orderId );
                if ( ! $row ) {
                    $created = $order->get_date_created();
                    if ( $from === 'pending' && $to === 'on-hold' && $order->get_created_via() === 'admin' && $created && $created->getTimestamp() >= (int) get_option( 'codverity_installed_at', time() ) && $this->eligibility->evaluate( $order ) === 'eligible' ) { $this->create( $order, 'on-hold' ); }
                    return;
                }
                if ( ! State::active( $row['state'] ) && ! in_array( $row['state'], State::CLAIMED, true ) ) { return; }
                $marker = $order->get_meta( '_codverity_operation', true );
                if ( $row['operation'] !== '' && $this->runningOperation === $row['operation'] && is_array( $marker ) && ( $marker['operation'] ?? '' ) === $row['operation'] && $to === $row['target_status'] ) { return; }
                if ( $from === 'pending' && $to === $row['expected_status'] && $row['state'] === 'pending' ) { $this->repo->event( $row, $to === 'on-hold' ? 'order_held' : 'order_enrolled' ); return; }
                $this->interruptRow( $row, 'external_status_change' );
            } );
        } catch ( \Throwable $e ) { update_option( 'codverity_last_error', 'status_observer_failed', false ); }
    }
}
