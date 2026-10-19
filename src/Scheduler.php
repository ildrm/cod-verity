<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Scheduler {
    public const GROUP = 'codverity';
    public const HOOKS = [ 'codverity_send', 'codverity_expire', 'codverity_reconcile', 'codverity_reconcile_page', 'codverity_prune' ];
    private const RETRY_DELAYS = [ 300, 1800, 7200 ];

    public function __construct( private readonly Workflow $workflow, private readonly Notifications $notifications ) {}

    public static function ready(): bool {
        return did_action( 'action_scheduler_init' ) > 0 && function_exists( 'as_schedule_single_action' );
    }
    public function register(): void {
        add_action( 'codverity_send', [ $this, 'send' ], 10, 4 );
        add_action( 'codverity_expire', [ $this->workflow, 'expire' ], 10, 2 );
        add_action( 'codverity_reconcile', [ $this, 'reconcile' ] );
        add_action( 'codverity_reconcile_page', [ $this, 'reconcile' ] );
        add_action( 'codverity_prune', fn() => $this->workflow->repo->prune( $this->workflow->settings ) );
        add_action( 'action_scheduler_init', [ $this, 'ensure' ] );
        add_action( 'init', [ $this, 'ensure' ], 20 );
        add_action( 'action_scheduler_ensure_recurring_actions', [ $this, 'ensure' ] );
    }
    public function ensure(): void {
        if ( ! self::ready() ) { return; }
        foreach ( [ 'codverity_reconcile' => 300, 'codverity_prune' => DAY_IN_SECONDS ] as $hook => $interval ) {
            if ( ! as_has_scheduled_action( $hook, [], self::GROUP ) ) {
                as_schedule_recurring_action( time() + 60, $interval, $hook, [], self::GROUP, true );
            }
        }
    }
    private function enqueue( int $when, string $hook, array $args ): void {
        if ( ! self::ready() ) { update_option( 'codverity_last_error', 'scheduler_unavailable', false ); return; }
        if ( ! as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
            if ( ! as_schedule_single_action( max( time() + 1, $when ), $hook, $args, self::GROUP, true ) ) {
                update_option( 'codverity_last_error', 'schedule_failed', false );
            } elseif ( $hook === 'codverity_send' ) {
                $this->workflow->repo->event( $this->workflow->repo->get( (int) $args[0] ), 'email_queued', '', '', [ 'generation' => $args[1], 'slot' => $args[2], 'attempt' => $args[3] ] );
            }
        }
    }
    public function schedule( array $row ): void {
        $id = (int) $row['id']; $generation = (int) $row['generation'];
        $this->enqueue( (int) $row['expires_at'], 'codverity_expire', [ $id, $generation ] );
        if ( (int) $row['next_send_at'] > 0 && in_array( $row['delivery'], [ 'queued', 'retry_scheduled' ], true ) ) {
            $this->enqueue( (int) $row['next_send_at'], 'codverity_send', [ $id, $generation, (int) $row['mail_slot'], (int) $row['slot_attempts'] ] );
        }
        if ( $this->workflow->settings->get( 'reminders' ) ) {
            foreach ( explode( ',', $this->workflow->settings->get( 'reminder_hours' ) ) as $index => $hour ) {
                $at = (int) $row['generation_at'] + (int) $hour * HOUR_IN_SECONDS;
                if ( $at < (int) $row['expires_at'] && (int) $row['delivered_slot'] < $index + 1 && (int) $row['mail_slot'] < $index + 1 ) {
                    $this->enqueue( $at, 'codverity_send', [ $id, $generation, $index + 1, 0 ] );
                }
            }
        }
    }
    public function cancel( array $row ): void {
        if ( ! self::ready() ) { return; }
        $args = [ (int) $row['id'], (int) $row['generation'] ];
        as_unschedule_all_actions( 'codverity_expire', $args, self::GROUP );
        for ( $slot = 0; $slot <= 2; $slot++ ) {
            for ( $attempt = 0; $attempt <= 3; $attempt++ ) {
                as_unschedule_all_actions( 'codverity_send', [ ...$args, $slot, $attempt ], self::GROUP );
            }
        }
    }

    public function send( int $id, int $generation, int $slot = 0, int $expectedAttempts = 0 ): void {
        if ( $slot < 0 || $slot > 2 || $expectedAttempts < 0 || $expectedAttempts > 3 ) { return; }
        $row = $this->workflow->repo->get( $id );
        if ( ! $row ) { return; }
        $this->workflow->repo->locked( (int) $row['order_id'], function () use ( $id, $generation, $slot, $expectedAttempts ): void {
            $repo = $this->workflow->repo;
            $row = $repo->get( $id );
            if ( ! $row || (int) $row['generation'] !== $generation || ! State::active( $row['state'] ) || (int) $row['expires_at'] <= time() || (int) $row['delivered_slot'] >= $slot ) { return; }
            $order = $this->workflow->freshOrder( (int) $row['order_id'] );
            if ( ! $this->workflow->safe( $row, $order ) ) { $this->workflow->interruptRow( $row, 'notification_order_changed' ); return; }
            if ( $slot > (int) $row['mail_slot'] ) {
                $hours = explode( ',', $this->workflow->settings->get( 'reminder_hours' ) );
                if ( ! $this->workflow->settings->get( 'reminders' ) || ! isset( $hours[ $slot - 1 ] ) || time() < (int) $row['generation_at'] + (int) $hours[ $slot - 1 ] * HOUR_IN_SECONDS || (int) $row['delivered_slot'] !== $slot - 1 || $expectedAttempts !== 0 ) { return; }
                $repo->update( $id, [ 'mail_slot' => $slot, 'slot_attempts' => 0, 'delivery' => 'queued', 'next_send_at' => time() ] );
                $row = $repo->get( $id );
            }
            if ( (int) $row['mail_slot'] !== $slot || (int) $row['slot_attempts'] !== $expectedAttempts || ! in_array( $row['delivery'], [ 'queued', 'retry_scheduled' ], true ) || time() < (int) $row['next_send_at'] ) { return; }
            $claim = bin2hex( random_bytes( 16 ) );
            $attempts = (int) $row['slot_attempts'] + 1;
            $repo->update( $id, [ 'delivery' => 'sending', 'send_claim' => $claim, 'last_attempt_at' => time(), 'attempts' => (int) $row['attempts'] + 1, 'slot_attempts' => $attempts, 'next_send_at' => 0 ] );
            $repo->event( $row, $slot ? 'reminder_attempted' : 'email_attempted', '', '', [ 'attempt' => $attempts, 'slot' => $slot ] );
            // An exception/crash here leaves "sending". Reconciliation marks uncertainty and never blindly resends.
            $accepted = $this->notifications->send( $row, $order );
            $latest = $repo->get( $id );
            if ( ! $latest || $latest['send_claim'] !== $claim || (int) $latest['generation'] !== $generation ) { return; }
            update_option( 'codverity_last_mail', [ 'result' => $accepted ? 'accepted' : 'failed', 'at' => time() ], false );
            if ( $accepted ) {
                $repo->update( $id, [ 'delivery' => 'accepted', 'last_sent_at' => time(), 'delivered_slot' => $slot, 'reminders' => $slot, 'send_claim' => '', 'error_code' => '' ] );
                if ( $latest['state'] === 'pending' ) { $repo->transition( $latest, 'awaiting_response' ); }
                $repo->event( $repo->get( $id ), 'email_transport_accepted', $latest['state'], '', [ 'slot' => $slot, 'attempt' => $attempts ] );
            } else {
                $retry = $attempts <= (int) $this->workflow->settings->get( 'retry_limit' ) && isset( self::RETRY_DELAYS[ $attempts - 1 ] );
                $when = $retry ? time() + self::RETRY_DELAYS[ $attempts - 1 ] : 0;
                if ( $when >= (int) $row['expires_at'] ) { $retry = false; $when = 0; }
                $repo->update( $id, [ 'delivery' => $retry ? 'retry_scheduled' : 'exhausted', 'next_send_at' => $when, 'error_code' => 'mail_transport_failed', 'send_claim' => '', 'transport_failures' => (int) $latest['transport_failures'] + 1 ] );
                $repo->event( $repo->get( $id ), 'email_transport_failed', '', 'mail_transport_failed', [ 'attempt' => $attempts, 'slot' => $slot ] );
                if ( $retry ) {
                    $this->enqueue( $when, 'codverity_send', [ $id, $generation, $slot, $attempts ] );
                    $repo->event( $repo->get( $id ), 'retry_scheduled', '', '', [ 'attempt' => $attempts ] );
                }
            }
        } );
    }

    /** Bounded keyset pages recover missing jobs and incomplete claims after reactivation. */
    public function reconcile( int $after = 0 ): void {
        $rows = $this->workflow->repo->recoveryRows( $after );
        foreach ( $rows as $snapshot ) {
            try {
                $this->workflow->repo->locked( (int) $snapshot['order_id'], function () use ( $snapshot ): void {
                    $row = $this->workflow->repo->get( (int) $snapshot['id'] );
                    if ( ! $row ) { return; }
                    if ( in_array( $row['state'], State::CLAIMED, true ) ) {
                        $this->workflow->complete( $row );
                        $this->workflow->repo->event( $this->workflow->repo->get( (int) $row['id'] ), 'reconciliation_completed' );
                        return;
                    }
                    if ( ! State::active( $row['state'] ) ) { return; }
                    if ( (int) $row['expires_at'] <= time() ) { $this->workflow->expire( (int) $row['id'], (int) $row['generation'] ); return; }
                    if ( ! $this->workflow->safe( $row, $this->workflow->freshOrder( (int) $row['order_id'] ) ) ) { $this->workflow->interruptRow( $row, 'reconciliation_order_changed' ); return; }
                    if ( $row['delivery'] === 'sending' ) {
                        // The order lock is now ours, so no other worker is still sending.
                        $this->workflow->repo->update( (int) $row['id'], [ 'delivery' => 'uncertain', 'error_code' => 'send_outcome_unknown', 'send_claim' => '', 'next_send_at' => 0 ] );
                        $this->workflow->repo->event( $row, 'reconciliation_failed', '', 'send_outcome_unknown' );
                        $row = $this->workflow->repo->get( (int) $row['id'] );
                    }
                    $this->schedule( $row );
                } );
            } catch ( \Throwable $e ) { update_option( 'codverity_last_error', 'reconciliation_failed', false ); }
        }
        update_option( 'codverity_last_reconcile', time(), false );
        if ( count( $rows ) === 100 ) { $this->enqueue( time() + 2, 'codverity_reconcile_page', [ (int) end( $rows )['id'] ] ); }
    }
}
