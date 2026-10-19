<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** Only plugin-owned tables use SQL; WooCommerce orders always use CRUD. */
final class Repository {
    public readonly string $requests;
    public readonly string $events;

    public function __construct( private readonly \wpdb $db ) {
        $this->requests = $db->prefix . 'codverity_requests';
        $this->events = $db->prefix . 'codverity_events';
    }
    public function byOrder( int $id ): ?array {
        return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->requests} WHERE order_id=%d", $id ), ARRAY_A ) ?: null;
    }
    public function get( int $id ): ?array {
        return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->requests} WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }
    public function byHash( string $hash ): ?array {
        return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->requests} WHERE token_hash=%s", $hash ), ARRAY_A ) ?: null;
    }
    public function insert( array $data ): array {
        if ( ! $this->db->insert( $this->requests, $data ) ) {
            throw new \RuntimeException( 'request_insert_failed' );
        }
        return $this->get( (int) $this->db->insert_id );
    }
    public function update( int $id, array $data ): void {
        if ( array_key_exists( 'state', $data ) ) {
            throw new \LogicException( 'Use compare-and-set for state transitions.' );
        }
        $data['updated_at'] = time();
        if ( $this->db->update( $this->requests, $data, [ 'id' => $id ] ) === false ) {
            throw new \RuntimeException( 'request_update_failed' );
        }
    }

    /** Generation and state are both part of the atomic compare-and-set. */
    public function transition( array $row, string $to, array $data = [] ): bool {
        if ( ! State::allows( $row['state'], $to ) ) { return false; }
        $data['state'] = $to;
        $data['updated_at'] = time();
        $result = $this->db->update( $this->requests, $data, [ 'id' => $row['id'], 'generation' => $row['generation'], 'state' => $row['state'] ] );
        if ( $result === false ) { throw new \RuntimeException( 'transition_failed' ); }
        return $result === 1;
    }

    /** Connection-owned named locks serialize work, release on disconnect, never lease-expire mid-mail. */
    public function locked( int $orderId, callable $callback ): mixed {
        $this->acquire( $orderId );
        try { return $callback(); }
        finally { $this->release( $orderId ); }
    }
    public function acquire( int $orderId ): void {
        $name = 'codv_' . substr( hash( 'sha256', $this->requests . ':' . $orderId ), 0, 48 );
        if ( (int) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 2)', $name ) ) !== 1 ) {
            throw new \RuntimeException( 'request_busy' );
        }
    }
    public function release( int $orderId ): void {
        $name = 'codv_' . substr( hash( 'sha256', $this->requests . ':' . $orderId ), 0, 48 );
        $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    public function event( ?array $row, string $type, string $previous = '', string $error = '', array $context = [], string $actor = 'system', int $actorId = 0 ): void {
        // Context is a narrow allowlist: no email address, message, token, URL, phone or IP.
        $context = array_intersect_key( $context, array_flip( [ 'generation', 'attempt', 'slot', 'policy', 'matched_order_ids', 'eligibility' ] ) );
        if ( ! $this->db->insert( $this->events, [
            'request_id' => (int) ( $row['id'] ?? 0 ), 'order_id' => (int) ( $row['order_id'] ?? 0 ),
            'event_type' => sanitize_key( $type ), 'created_at' => time(), 'actor' => sanitize_key( $actor ),
            'actor_id' => $actorId, 'previous_state' => $previous, 'resulting_state' => $row['state'] ?? '',
            'error_code' => sanitize_key( $error ), 'context' => wp_json_encode( $context ),
        ] ) ) { throw new \RuntimeException( 'audit_insert_failed' ); }
    }

    public function matches( string $fingerprint, int $exclude, int $since ): array {
        if ( $fingerprint === '' ) { return []; }
        return array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT order_id FROM {$this->requests} WHERE fingerprint=%s AND order_id<>%d AND created_at>=%d ORDER BY id DESC LIMIT 20", $fingerprint, $exclude, $since ) ) );
    }

    /** Shared bounded filters for queue, reports and CSV. Dynamic SQL fragments are allowlisted. */
    public function where( array $filters ): array {
        $sql = '1=1'; $args = [];
        if ( in_array( $filters['state'] ?? '', array_merge( State::ACTIVE, State::CLAIMED, State::FINAL ), true ) ) {
            $sql .= ' AND state=%s'; $args[] = $filters['state'];
        }
        foreach ( [ 'from' => '>=', 'to' => '<=' ] as $key => $operator ) {
            if ( ! empty( $filters[ $key ] ) ) { $sql .= " AND created_at$operator%d"; $args[] = (int) $filters[ $key ]; }
        }
        if ( ! empty( $filters['order_id'] ) ) { $sql .= ' AND order_id=%d'; $args[] = (int) $filters['order_id']; }
        if ( ! empty( $filters['duplicate'] ) ) { $sql .= ' AND duplicate_warning=1'; }
        if ( ! empty( $filters['failed'] ) ) { $sql .= " AND delivery IN ('failed','exhausted','uncertain')"; }
        return [ $sql, $args ];
    }
    public function rows( array $filters = [], int $page = 1, int $limit = 25, int $after = 0 ): array {
        [ $where, $args ] = $this->where( $filters );
        $limit = min( 250, max( 1, $limit ) );
        if ( $after > 0 ) { $where .= ' AND id>%d'; $args[] = $after; }
        $args[] = $limit; $args[] = ( max( 1, $page ) - 1 ) * $limit;
        return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->requests} WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );
    }
    public function count( array $filters = [] ): int {
        [ $where, $args ] = $this->where( $filters );
        return (int) $this->db->get_var( $args ? $this->db->prepare( "SELECT COUNT(*) FROM {$this->requests} WHERE $where", $args ) : "SELECT COUNT(*) FROM {$this->requests} WHERE $where" );
    }
    public function statistics( array $filters = [] ): array {
        [ $where, $args ] = $this->where( $filters );
        $sql = "SELECT state,COUNT(*) AS total,SUM(duplicate_warning) AS duplicates,SUM(transport_failures) AS transport_failures,SUM(delivery IN ('failed','exhausted','uncertain')) AS failures,AVG(CASE WHEN actor='customer' AND response_at>0 THEN response_at-generation_at ELSE NULL END) AS response_seconds FROM {$this->requests} WHERE $where GROUP BY state";
        return $this->db->get_results( $args ? $this->db->prepare( $sql, $args ) : $sql, ARRAY_A );
    }
    public function history( int $requestId = 0, int $page = 1 ): array {
        $where = $requestId > 0 ? $this->db->prepare( 'request_id=%d', $requestId ) : '1=1';
        return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->events} WHERE $where ORDER BY id DESC LIMIT 25 OFFSET %d", ( max( 1, $page ) - 1 ) * 25 ), ARRAY_A );
    }
    public function historyCount(): int {
        return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->events}" );
    }
    public function recoveryRows( int $after = 0 ): array {
        return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->requests} WHERE id>%d AND state IN ('pending','awaiting_response','confirming','cancelling','expiring') ORDER BY id ASC LIMIT 100", $after ), ARRAY_A );
    }
    public function prune( Settings $settings ): void {
        $this->db->query( $this->db->prepare( "DELETE FROM {$this->events} WHERE created_at<%d ORDER BY id LIMIT 1000", time() - (int) $settings->get( 'retention_days' ) * DAY_IN_SECONDS ) );
        // Preserve the unique enrollment tombstone, scrub secrets/fingerprints/reasons after retention.
        $this->db->query( $this->db->prepare( "UPDATE {$this->requests} SET seed='',fingerprint='',order_signature='',reason='',actor_id=0 WHERE resolved_at>0 AND resolved_at<%d AND state IN ('confirmed','cancelled','expired','manually_confirmed','manually_cancelled','interrupted') AND seed<>'' LIMIT 1000", time() - (int) $settings->get( 'request_retention_days' ) * DAY_IN_SECONDS ) );
    }
    public function erase( array $row ): void {
        $this->db->delete( $this->events, [ 'request_id' => (int) $row['id'] ] );
        $this->db->delete( $this->requests, [ 'id' => (int) $row['id'] ] );
    }
}
