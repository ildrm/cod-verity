<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class Installer {
    public const SCHEMA = '1';

    public static function activate( bool $networkWide = false ): void {
        if ( $networkWide ) {
            wp_die( esc_html__( 'Activate CODVerity separately for each store. Network-wide activation is not supported.', 'codverity-order-verification-for-woocommerce' ) );
        }
        self::migrate();
        add_option( 'codverity_installed_at', time(), '', false );
        add_option( Settings::OPTION, Settings::defaults(), '', false );
    }

    public static function migrate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $requests = $wpdb->prefix . 'codverity_requests';
        $events = $wpdb->prefix . 'codverity_events';
        dbDelta( "CREATE TABLE $requests (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint unsigned NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'pending',
            delivery varchar(32) NOT NULL DEFAULT 'queued',
            generation bigint unsigned NOT NULL DEFAULT 1,
            seed char(64) NOT NULL,
            token_hash char(64) NOT NULL,
            expires_at bigint unsigned NOT NULL,
            intended_status varchar(32) NOT NULL,
            hold_owned tinyint unsigned NOT NULL DEFAULT 0,
            expected_status varchar(32) NOT NULL,
            created_at bigint unsigned NOT NULL,
            updated_at bigint unsigned NOT NULL,
            generation_at bigint unsigned NOT NULL,
            last_sent_at bigint unsigned NOT NULL DEFAULT 0,
            last_attempt_at bigint unsigned NOT NULL DEFAULT 0,
            attempts int unsigned NOT NULL DEFAULT 0,
            transport_failures int unsigned NOT NULL DEFAULT 0,
            slot_attempts int unsigned NOT NULL DEFAULT 0,
            mail_slot int NOT NULL DEFAULT 0,
            delivered_slot int NOT NULL DEFAULT -1,
            reminders int unsigned NOT NULL DEFAULT 0,
            next_send_at bigint unsigned NOT NULL DEFAULT 0,
            send_claim char(32) NOT NULL DEFAULT '',
            response_at bigint unsigned NOT NULL DEFAULT 0,
            resolved_at bigint unsigned NOT NULL DEFAULT 0,
            actor varchar(32) NOT NULL DEFAULT '',
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            reason varchar(255) NOT NULL DEFAULT '',
            fingerprint char(64) NOT NULL DEFAULT '',
            order_signature char(64) NOT NULL DEFAULT '',
            duplicate_warning tinyint unsigned NOT NULL DEFAULT 0,
            error_code varchar(64) NOT NULL DEFAULT '',
            operation char(32) NOT NULL DEFAULT '',
            target_status varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            UNIQUE KEY token_hash (token_hash),
            KEY state_expiry (state,expires_at),
            KEY state_updated (state,updated_at),
            KEY created_at (created_at),
            KEY resolved_at (resolved_at),
            KEY fingerprint_created (fingerprint,created_at),
            KEY delivery_next (delivery,next_send_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE $events (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint unsigned NOT NULL DEFAULT 0,
            order_id bigint unsigned NOT NULL DEFAULT 0,
            event_type varchar(64) NOT NULL,
            created_at bigint unsigned NOT NULL,
            actor varchar(32) NOT NULL,
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            previous_state varchar(32) NOT NULL DEFAULT '',
            resulting_state varchar(32) NOT NULL DEFAULT '',
            error_code varchar(64) NOT NULL DEFAULT '',
            context text NOT NULL,
            PRIMARY KEY  (id),
            KEY request_id (request_id,id),
            KEY created_at (created_at),
            KEY order_id (order_id)
        ) $charset;" );
        // Do not mark a failed or partial migration as installed.
        $ready = true;
        foreach ( [ $requests, $events ] as $table ) {
            $ready = $ready && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
        }
        if ( ! $ready || $wpdb->last_error ) {
            throw new \RuntimeException( 'codverity_schema_failed' );
        }
        $expected = [
            $requests => [ 'id', 'order_id', 'state', 'delivery', 'generation', 'seed', 'token_hash', 'expires_at', 'intended_status', 'hold_owned', 'expected_status', 'created_at', 'updated_at', 'generation_at', 'last_sent_at', 'last_attempt_at', 'attempts', 'transport_failures', 'slot_attempts', 'mail_slot', 'delivered_slot', 'reminders', 'next_send_at', 'send_claim', 'response_at', 'resolved_at', 'actor', 'actor_id', 'reason', 'fingerprint', 'order_signature', 'duplicate_warning', 'error_code', 'operation', 'target_status' ],
            $events => [ 'id', 'request_id', 'order_id', 'event_type', 'created_at', 'actor', 'actor_id', 'previous_state', 'resulting_state', 'error_code', 'context' ],
        ];
        foreach ( $expected as $table => $columns ) {
            $actual = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
            if ( array_diff( $columns, $actual ) ) { throw new \RuntimeException( 'codverity_schema_incomplete' ); }
        }
        $indexes = $wpdb->get_results( "SHOW INDEX FROM $requests", ARRAY_A );
        foreach ( [ 'order_id', 'token_hash' ] as $key ) {
            $unique = array_filter( $indexes, static fn( array $index ): bool => $index['Key_name'] === $key && (int) $index['Non_unique'] === 0 );
            if ( ! $unique ) { throw new \RuntimeException( 'codverity_unique_index_missing' ); }
        }
        update_option( 'codverity_schema', self::SCHEMA, false );
    }

    public static function deactivate(): void {
        if ( Scheduler::ready() ) {
            foreach ( Scheduler::HOOKS as $hook ) {
                as_unschedule_all_actions( $hook, null, Scheduler::GROUP );
            }
        }
    }
}
