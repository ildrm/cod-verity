<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** Native server-rendered administration. Every mutation is POST + nonce + capability. */
final class Admin {
    private const SLUG = 'codverity';
    private const TABS = [ 'overview', 'queue', 'settings', 'reports', 'logs', 'diagnostics' ];
    public function __construct( private readonly Workflow $workflow, private readonly Notifications $notifications ) {}

    public function register(): void {
        add_action( 'admin_menu', function (): void { add_submenu_page( 'woocommerce', 'CODVerity', 'CODVerity', 'manage_woocommerce', self::SLUG, [ $this, 'page' ] ); } );
        add_action( 'admin_post_codverity_admin', [ $this, 'handle' ] );
        add_action( 'add_meta_boxes', [ $this, 'metaBoxes' ] );
        add_action( 'admin_init', function (): void {
            register_setting( 'codverity', Settings::OPTION, [ 'type' => 'array', 'sanitize_callback' => function ( mixed $input ): array {
                try { return $this->workflow->settings->validate( is_array( $input ) ? $input : [] ); }
                catch ( \InvalidArgumentException $e ) { add_settings_error( Settings::OPTION, 'invalid_settings', $e->getMessage() ); return $this->workflow->settings->all(); }
            } ] );
        } );
        add_filter( 'option_page_capability_codverity', static fn(): string => 'manage_woocommerce' );
        add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
            if ( str_contains( $hook, 'codverity' ) ) { wp_enqueue_style( 'codverity-admin', plugins_url( 'assets/admin.css', CODVERITY_FILE ), [], CODVERITY_VERSION ); }
        } );
    }
    private function text( string $key, array $source, int $max = 100 ): string {
        return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $source[ $key ] ) ), 0, $max ) : '';
    }
    public function filters( array $input ): array {
        $filters = [ 'state' => $this->text( 'state', $input ), 'order_id' => absint( $this->text( 'order_id', $input ) ), 'failed' => $this->text( 'failed', $input ) === '1', 'duplicate' => $this->text( 'duplicate', $input ) === '1' ];
        foreach ( [ 'from', 'to' ] as $key ) {
            $date = $this->text( $key, $input );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) {
                $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
                if ( $parsed && $parsed->format( 'Y-m-d' ) === $date ) { $filters[ $key ] = ( $key === 'to' ? $parsed->setTime( 23, 59, 59 ) : $parsed )->getTimestamp(); }
            }
        }
        return $filters;
    }
    private function url( string $tab, array $args = [] ): string { return add_query_arg( [ 'page' => self::SLUG, 'tab' => $tab, ...$args ], admin_url( 'admin.php' ) ); }
    private function date( mixed $timestamp ): string { return (int) $timestamp > 0 ? wp_date( 'Y-m-d H:i T', (int) $timestamp ) : '—'; }
    public static function label( string $value ): string {
        $labels = [
            'pending' => __( 'Pending', 'codverity-order-verification-for-woocommerce' ), 'awaiting_response' => __( 'Awaiting response', 'codverity-order-verification-for-woocommerce' ),
            'confirmed' => __( 'Customer confirmed', 'codverity-order-verification-for-woocommerce' ), 'cancelled' => __( 'Customer cancelled', 'codverity-order-verification-for-woocommerce' ),
            'expired' => __( 'Expired', 'codverity-order-verification-for-woocommerce' ), 'interrupted' => __( 'Interrupted — review required', 'codverity-order-verification-for-woocommerce' ),
            'manually_confirmed' => __( 'Manually confirmed', 'codverity-order-verification-for-woocommerce' ), 'manually_cancelled' => __( 'Manually cancelled', 'codverity-order-verification-for-woocommerce' ),
            'confirming' => __( 'Confirmation in progress', 'codverity-order-verification-for-woocommerce' ), 'cancelling' => __( 'Cancellation in progress', 'codverity-order-verification-for-woocommerce' ), 'expiring' => __( 'Expiration in progress', 'codverity-order-verification-for-woocommerce' ),
            'queued' => __( 'Queued', 'codverity-order-verification-for-woocommerce' ), 'sending' => __( 'Sending', 'codverity-order-verification-for-woocommerce' ),
            'accepted' => __( 'Accepted by mail transport', 'codverity-order-verification-for-woocommerce' ), 'failed' => __( 'Transport failed', 'codverity-order-verification-for-woocommerce' ),
            'retry_scheduled' => __( 'Retry scheduled', 'codverity-order-verification-for-woocommerce' ), 'exhausted' => __( 'Attempts exhausted', 'codverity-order-verification-for-woocommerce' ), 'uncertain' => __( 'Transport outcome unknown — review before reissuing', 'codverity-order-verification-for-woocommerce' ),
        ];
        return $labels[ $value ] ?? ucwords( str_replace( '_', ' ', $value ) );
    }

    public function page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You are not authorized.', 'codverity-order-verification-for-woocommerce' ), '', [ 'response' => 403 ] ); }
        $tab = $this->text( 'tab', $_GET );
        if ( ! in_array( $tab, self::TABS, true ) ) { $tab = 'overview'; }
        $titles = [ 'overview' => __( 'Overview', 'codverity-order-verification-for-woocommerce' ), 'queue' => __( 'Verification Queue', 'codverity-order-verification-for-woocommerce' ), 'settings' => __( 'Settings', 'codverity-order-verification-for-woocommerce' ), 'reports' => __( 'Reports', 'codverity-order-verification-for-woocommerce' ), 'logs' => __( 'Activity Logs', 'codverity-order-verification-for-woocommerce' ), 'diagnostics' => __( 'Diagnostics', 'codverity-order-verification-for-woocommerce' ) ];
        echo '<div class="wrap codverity"><h1>CODVerity</h1><p>' . esc_html__( 'Verify customer intent before fulfilling cash-on-delivery orders. Confirmation does not record a payment.', 'codverity-order-verification-for-woocommerce' ) . '</p><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'CODVerity navigation', 'codverity-order-verification-for-woocommerce' ) . '">';
        foreach ( $titles as $key => $title ) { echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $this->url( $key ) ) . '">' . esc_html( $title ) . '</a>'; }
        echo '</nav>';
        settings_errors( Settings::OPTION );
        $notice = get_transient( 'codverity_notice_' . get_current_user_id() );
        if ( is_array( $notice ) ) { delete_transient( 'codverity_notice_' . get_current_user_id() ); echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>'; }
        if ( ! $this->workflow->settings->get( 'enabled' ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'New automation is disabled. Existing verification links and scheduled workflows may still finish. Enable automation in Settings when ready.', 'codverity-order-verification-for-woocommerce' ) . '</p></div>'; }
        echo '<h2>' . esc_html( $titles[ $tab ] ) . '</h2>';
        match ( $tab ) { 'settings' => $this->settings(), 'queue' => $this->queue(), 'reports' => $this->reports(), 'logs' => $this->logs(), 'diagnostics' => $this->diagnostics(), default => $this->overview() };
        echo '</div>';
    }

    private function overview(): void {
        $rows = $this->workflow->repo->statistics(); $metrics = []; $duplicates = 0; $failures = 0; $total = 0;
        foreach ( $rows as $row ) { $metrics[ $row['state'] ] = (int) $row['total']; $duplicates += (int) $row['duplicates']; $failures += (int) $row['failures']; $total += (int) $row['total']; }
        $cards = [
            [ __( 'Total requests', 'codverity-order-verification-for-woocommerce' ), $total, [] ],
            [ __( 'Awaiting response', 'codverity-order-verification-for-woocommerce' ), ( $metrics['pending'] ?? 0 ) + ( $metrics['awaiting_response'] ?? 0 ), [ 'state' => 'awaiting_response' ] ],
            [ __( 'Customer confirmed', 'codverity-order-verification-for-woocommerce' ), $metrics['confirmed'] ?? 0, [ 'state' => 'confirmed' ] ],
            [ __( 'Customer cancelled', 'codverity-order-verification-for-woocommerce' ), $metrics['cancelled'] ?? 0, [ 'state' => 'cancelled' ] ],
            [ __( 'Expired', 'codverity-order-verification-for-woocommerce' ), $metrics['expired'] ?? 0, [ 'state' => 'expired' ] ],
            [ __( 'Notification problems', 'codverity-order-verification-for-woocommerce' ), $failures, [ 'failed' => 1 ] ],
            [ __( 'Manual resolutions', 'codverity-order-verification-for-woocommerce' ), ( $metrics['manually_confirmed'] ?? 0 ) + ( $metrics['manually_cancelled'] ?? 0 ), [] ],
            [ __( 'Suspected duplicates', 'codverity-order-verification-for-woocommerce' ), $duplicates, [ 'duplicate' => 1 ] ],
            [ __( 'Interrupted', 'codverity-order-verification-for-woocommerce' ), $metrics['interrupted'] ?? 0, [ 'state' => 'interrupted' ] ],
        ];
        echo '<div class="codverity-cards">';
        foreach ( $cards as [ $title, $count, $args ] ) { echo '<a class="codverity-card" href="' . esc_url( $this->url( 'queue', $args ) ) . '"><strong>' . esc_html( (string) $count ) . '</strong><span>' . esc_html( $title ) . '</span></a>'; }
        echo '</div><p>' . esc_html__( 'Expired and interrupted orders may remain on hold. Review them before fulfillment. Duplicate warnings are advisory and never reject an order.', 'codverity-order-verification-for-woocommerce' ) . '</p>';
    }
    private function filterForm( string $tab ): void {
        echo '<form method="get" class="codverity-filters"><input type="hidden" name="page" value="codverity"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label>' . esc_html__( 'State', 'codverity-order-verification-for-woocommerce' ) . '<select name="state"><option value="">' . esc_html__( 'All states', 'codverity-order-verification-for-woocommerce' ) . '</option>';
        foreach ( array_merge( State::ACTIVE, State::CLAIMED, State::FINAL ) as $state ) { echo '<option value="' . esc_attr( $state ) . '" ' . selected( $this->text( 'state', $_GET ), $state, false ) . '>' . esc_html( self::label( $state ) ) . '</option>'; }
        echo '</select></label>';
        foreach ( [ 'from' => __( 'From', 'codverity-order-verification-for-woocommerce' ), 'to' => __( 'Through', 'codverity-order-verification-for-woocommerce' ), 'order_id' => __( 'Exact order ID', 'codverity-order-verification-for-woocommerce' ) ] as $key => $label ) { echo '<label>' . esc_html( $label ) . '<input type="' . ( $key === 'order_id' ? 'number' : 'date' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $this->text( $key, $_GET ) ) . '"></label>'; }
        foreach ( [ 'failed' => __( 'Notification problems', 'codverity-order-verification-for-woocommerce' ), 'duplicate' => __( 'Duplicate warnings', 'codverity-order-verification-for-woocommerce' ) ] as $key => $label ) { echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $this->text( $key, $_GET ), '1', false ) . '>' . esc_html( $label ) . '</label>'; }
        submit_button( __( 'Filter', 'codverity-order-verification-for-woocommerce' ), 'secondary', '', false ); echo '</form>';
    }
    private function queue(): void {
        $this->filterForm( 'queue' ); $filters = $this->filters( $_GET );
        $page = max( 1, absint( $this->text( 'paged', $_GET ) ) );
        $rows = $this->workflow->repo->rows( $filters, $page );
        if ( $filters['order_id'] && ! $this->workflow->repo->byOrder( $filters['order_id'] ) ) { $this->actions( $filters['order_id'], null ); }
        echo '<div class="codverity-table"><table class="widefat striped"><thead><tr>';
        foreach ( [ __( 'Order', 'codverity-order-verification-for-woocommerce' ), __( 'Verification', 'codverity-order-verification-for-woocommerce' ), __( 'Notification', 'codverity-order-verification-for-woocommerce' ), __( 'Dates', 'codverity-order-verification-for-woocommerce' ), __( 'Attempts', 'codverity-order-verification-for-woocommerce' ), __( 'Actions', 'codverity-order-verification-for-woocommerce' ) ] as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $order = wc_get_order( (int) $row['order_id'] );
            echo '<tr><td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a>' : esc_html( (string) $row['order_id'] ) ) . ( (int) $row['duplicate_warning'] ? '<p><strong>' . esc_html__( 'Suspected duplicate', 'codverity-order-verification-for-woocommerce' ) . '</strong></p>' : '' ) . '</td><td>' . esc_html( self::label( $row['state'] ) ) . '<p>' . esc_html( $row['error_code'] ) . '</p></td><td>' . esc_html( self::label( $row['delivery'] ) ) . '</td><td>' . esc_html__( 'Created:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['created_at'] ) ) . '<br>' . esc_html__( 'Expires:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['expires_at'] ) ) . '<br>' . esc_html__( 'Last attempt:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['last_attempt_at'] ) ) . '</td><td>' . esc_html( (string) $row['attempts'] ) . '</td><td>';
            $this->actions( (int) $row['order_id'], $row ); echo '</td></tr>';
        }
        if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html__( 'No requests match these filters. Enable CODVerity for new orders, or enter an existing order ID to test eligibility and start verification.', 'codverity-order-verification-for-woocommerce' ) . '</td></tr>'; }
        echo '</tbody></table></div>';
        $this->pagination( $this->workflow->repo->count( $filters ), $page );
    }
    private function pagination( int $total, int $page ): void {
        $pages = max( 1, (int) ceil( $total / 25 ) );
        echo '<p class="tablenav">' . esc_html( sprintf( __( '%1$d records · Page %2$d of %3$d', 'codverity-order-verification-for-woocommerce' ), $total, $page, $pages ) );
        foreach ( [ $page - 1 => __( 'Previous', 'codverity-order-verification-for-woocommerce' ), $page + 1 => __( 'Next', 'codverity-order-verification-for-woocommerce' ) ] as $target => $label ) {
            if ( $target >= 1 && $target <= $pages ) { echo ' <a class="button" href="' . esc_url( add_query_arg( 'paged', $target ) ) . '">' . esc_html( $label ) . '</a>'; }
        }
        echo '</p>';
    }
    private function formStart( string $operation, int $orderId = 0 ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="codverity_admin"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="order_id" value="' . esc_attr( (string) $orderId ) . '">';
        wp_nonce_field( 'codverity_admin' );
    }
    private function actions( int $orderId, ?array $row ): void {
        if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
        $this->formStart( 'order', $orderId );
        echo '<label class="screen-reader-text" for="codverity-op-' . esc_attr( (string) $orderId ) . '">' . esc_html__( 'Order action', 'codverity-order-verification-for-woocommerce' ) . '</label><select id="codverity-op-' . esc_attr( (string) $orderId ) . '" name="order_action">';
        $options = [ 'eligibility' => __( 'Test eligibility', 'codverity-order-verification-for-woocommerce' ) ];
        if ( ! $row ) { $options['start'] = __( 'Start verification', 'codverity-order-verification-for-woocommerce' ); }
        elseif ( in_array( $row['state'], [ 'pending', 'awaiting_response', 'expired', 'interrupted' ], true ) ) {
            $options += [ 'resend' => __( 'Reissue link and email', 'codverity-order-verification-for-woocommerce' ), 'confirm' => __( 'Manually confirm', 'codverity-order-verification-for-woocommerce' ), 'cancel' => __( 'Cancel held order', 'codverity-order-verification-for-woocommerce' ), 'stop' => __( 'Stop verification', 'codverity-order-verification-for-woocommerce' ) ];
        }
        foreach ( $options as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>'; }
        echo '</select><label>' . esc_html__( 'Reason (required for changes)', 'codverity-order-verification-for-woocommerce' ) . '<input type="text" maxlength="255" name="reason"></label><label><input type="checkbox" name="acknowledge" value="1">' . esc_html__( 'I approve this change. Cancellation changes the order; stopping leaves its current status.', 'codverity-order-verification-for-woocommerce' ) . '</label>';
        submit_button( __( 'Apply action', 'codverity-order-verification-for-woocommerce' ), 'secondary', '', false ); echo '</form>';
    }

    private function settings(): void {
        $s = $this->workflow->settings->all();
        echo '<form method="post" action="options.php">'; settings_fields( 'codverity' );
        echo '<table class="form-table" role="presentation">';
        $fields = [
            'enabled' => [ __( 'Enable new automation', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
            'hold_enabled' => [ __( 'Hold orders until verified', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
            'min_total' => [ __( 'Minimum total (blank = unrestricted)', 'codverity-order-verification-for-woocommerce' ), 'text' ],
            'max_total' => [ __( 'Maximum total (blank = unrestricted)', 'codverity-order-verification-for-woocommerce' ), 'text' ],
            'include_categories' => [ __( 'Included categories (any matching item)', 'codverity-order-verification-for-woocommerce' ), 'categories' ],
            'exclude_categories' => [ __( 'Excluded categories (any match excludes order)', 'codverity-order-verification-for-woocommerce' ), 'categories' ],
            'expiry_hours' => [ __( 'Link validity in hours (1–168)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'reminders' => [ __( 'Enable reminders', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
            'reminder_hours' => [ __( 'Reminder hours from issue (one or two, comma separated)', 'codverity-order-verification-for-woocommerce' ), 'text' ],
            'retry_limit' => [ __( 'Transport retries after initial attempt (0–3)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'auto_cancel' => [ __( 'Automatically cancel expired plugin-held orders', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
            'duplicates' => [ __( 'Show advisory duplicate warnings', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
            'duplicate_hours' => [ __( 'Duplicate lookback hours (1–168)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'resend_cooldown' => [ __( 'Reissue cooldown in seconds (60–86400)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'email_subject' => [ __( 'Email subject', 'codverity-order-verification-for-woocommerce' ), 'text' ],
            'email_body' => [ __( 'Plain-text email message', 'codverity-order-verification-for-woocommerce' ), 'textarea' ],
            'sender_name' => [ __( 'Sender name (blank = transport default)', 'codverity-order-verification-for-woocommerce' ), 'text' ],
            'sender_email' => [ __( 'Sender email (blank = transport default)', 'codverity-order-verification-for-woocommerce' ), 'email' ],
            'retention_days' => [ __( 'Audit retention days (7–730)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'request_retention_days' => [ __( 'Resolved request secret retention days (30–3650)', 'codverity-order-verification-for-woocommerce' ), 'number' ],
            'delete_on_uninstall' => [ __( 'Delete CODVerity data on uninstall', 'codverity-order-verification-for-woocommerce' ), 'checkbox' ],
        ];
        foreach ( $fields as $key => [ $label, $type ] ) {
            $name = Settings::OPTION . '[' . $key . ']';
            echo '<tr><th scope="row"><label for="codverity-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
            if ( $type === 'textarea' ) { echo '<textarea class="large-text" rows="10" id="codverity-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $s[ $key ] ) . '</textarea>'; }
            elseif ( $type === 'categories' ) {
                echo '<select multiple size="6" id="codverity-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '[]">';
                $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
                if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( $term->term_id, $s[ $key ], true ), true, false ) . '>' . esc_html( $term->name ) . '</option>'; } }
                echo '</select><p class="description">' . esc_html__( 'Empty inclusion allows all categories. Variation parent categories are used; exact category matches apply. Use Ctrl/Command to select multiple (up to 500 categories shown).', 'codverity-order-verification-for-woocommerce' ) . '</p>';
            } else { echo '<input id="codverity-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" ' . ( $type === 'checkbox' ? 'value="1" ' . checked( $s[ $key ], true, false ) : 'class="regular-text" value="' . esc_attr( (string) $s[ $key ] ) . '"' ) . '>'; }
            if ( $key === 'auto_cancel' ) { echo '<p class="description codverity-danger">' . esc_html__( 'Warning: enabling this cancels unresponsive orders still held by CODVerity and lets WooCommerce restore stock. Leave disabled for merchant review.', 'codverity-order-verification-for-woocommerce' ) . '</p>'; }
            if ( $key === 'delete_on_uninstall' ) { echo '<p class="description codverity-danger">' . esc_html__( 'Uninstall cleanup permanently removes verification history and settings. WooCommerce orders and their current statuses remain.', 'codverity-order-verification-for-woocommerce' ) . '</p>'; }
            if ( $key === 'hold_enabled' ) { echo '<p class="description">' . esc_html__( 'When disabled, normal processing orders can proceed before verification and customer cancellation is unavailable for them. Existing holds retain their original policy.', 'codverity-order-verification-for-woocommerce' ) . '</p>'; }
            echo '</td></tr>';
        }
        echo '</table><p>' . esc_html__( 'Placeholders: {store_name}, {customer_first_name}, {order_number}, {order_total}, {verification_url}, {expires_at}. Reissuing rotates the link. Retries occur after 5, 30 and 120 minutes from each preceding failure.', 'codverity-order-verification-for-woocommerce' ) . '</p>';
        submit_button(); echo '</form><h3>' . esc_html__( 'Test email transport', 'codverity-order-verification-for-woocommerce' ) . '</h3>';
        $this->formStart( 'test' ); echo '<label>' . esc_html__( 'Destination email', 'codverity-order-verification-for-woocommerce' ) . '<input type="email" name="destination" maxlength="254" required></label> '; submit_button( __( 'Send test email', 'codverity-order-verification-for-woocommerce' ), 'secondary', '', false ); echo '</form>';
    }

    private function reports(): void {
        $this->filterForm( 'reports' ); $filters = $this->filters( $_GET );
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Verification outcome', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Requests initiated in selected dates', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Notification problems', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Failed transport attempts (all generations)', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Duplicate warnings', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Average customer response (minutes)', 'codverity-order-verification-for-woocommerce' ) . '</th></tr></thead><tbody>';
        $rows = $this->workflow->repo->statistics( $filters );
        foreach ( $rows as $row ) { echo '<tr><th scope="row">' . esc_html( self::label( $row['state'] ) ) . '</th><td>' . esc_html( (string) $row['total'] ) . '</td><td>' . esc_html( (string) $row['failures'] ) . '</td><td>' . esc_html( (string) $row['transport_failures'] ) . '</td><td>' . esc_html( (string) $row['duplicates'] ) . '</td><td>' . esc_html( $row['response_seconds'] === null ? '—' : number_format_i18n( (float) $row['response_seconds'] / 60, 1 ) ) . '</td></tr>'; }
        if ( ! $rows ) { echo '<tr><td colspan="6">' . esc_html__( 'No requests match these filters.', 'codverity-order-verification-for-woocommerce' ) . '</td></tr>'; }
        echo '</tbody></table><p>' . esc_html__( 'Dates select request creation in the site timezone. Outcomes reflect current request state. Response time measures the latest generation issue to a customer decision; staff decisions and nonresponses are excluded. Verification does not measure payment or delivery success.', 'codverity-order-verification-for-woocommerce' ) . '</p>';
        $this->formStart( 'export' );
        foreach ( [ 'state', 'from', 'to', 'order_id', 'failed', 'duplicate' ] as $key ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $this->text( $key, $_GET ) ) . '">'; }
        submit_button( __( 'Export filtered CSV', 'codverity-order-verification-for-woocommerce' ), 'secondary', '', false ); echo '</form>';
    }
    private function logs(): void {
        $page = max( 1, absint( $this->text( 'paged', $_GET ) ) );
        $this->historyTable( $this->workflow->repo->history( 0, $page ) );
        $this->pagination( $this->workflow->repo->historyCount(), $page );
    }
    private function historyTable( array $events ): void {
        echo '<div class="codverity-table"><table class="widefat striped"><thead><tr>';
        foreach ( [ __( 'Time', 'codverity-order-verification-for-woocommerce' ), __( 'Order ID', 'codverity-order-verification-for-woocommerce' ), __( 'Event', 'codverity-order-verification-for-woocommerce' ), __( 'Actor', 'codverity-order-verification-for-woocommerce' ), __( 'Transition', 'codverity-order-verification-for-woocommerce' ), __( 'Safe diagnostic code', 'codverity-order-verification-for-woocommerce' ) ] as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ( $events as $event ) { echo '<tr><td>' . esc_html( $this->date( $event['created_at'] ) ) . '</td><td>' . esc_html( (string) $event['order_id'] ) . '</td><td>' . esc_html( self::label( $event['event_type'] ) ) . '</td><td>' . esc_html( $event['actor'] . ( $event['actor_id'] ? ' #' . $event['actor_id'] : '' ) ) . '</td><td>' . esc_html( $event['previous_state'] . ' → ' . $event['resulting_state'] ) . '</td><td>' . esc_html( $event['error_code'] ) . '</td></tr>'; }
        if ( ! $events ) { echo '<tr><td colspan="6">' . esc_html__( 'No verification activity has been recorded yet.', 'codverity-order-verification-for-woocommerce' ) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    private function diagnostics(): void {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Current value', 'codverity-order-verification-for-woocommerce' ) . '</th><th>' . esc_html__( 'Guidance', 'codverity-order-verification-for-woocommerce' ) . '</th></tr></thead><tbody>';
        foreach ( ( new Diagnostics( $this->workflow->repo ) )->checks() as [ $name, $value, $guidance ] ) { echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( $value ) . '</td><td>' . esc_html( $guidance ) . '</td></tr>'; }
        echo '</tbody></table>';
    }
    public function metaBoxes(): void {
        foreach ( [ 'shop_order', wc_get_page_screen_id( 'shop-order' ) ] as $screen ) { add_meta_box( 'codverity', 'CODVerity', [ $this, 'metaBox' ], $screen, 'normal', 'default' ); }
    }
    public function metaBox( mixed $object ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_orders' ) ) { return; }
        $order = $object instanceof \WC_Order ? $object : wc_get_order( $object->ID ?? 0 );
        if ( ! $order ) { return; }
        $row = $this->workflow->repo->byOrder( $order->get_id() );
        if ( $row ) {
            echo '<p><strong>' . esc_html( self::label( $row['state'] ) ) . '</strong> · ' . esc_html( self::label( $row['delivery'] ) ) . '</p><p>' . esc_html__( 'Customer response:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['response_at'] ) ) . ' · ' . esc_html__( 'Expires:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['expires_at'] ) ) . ' · ' . esc_html__( 'Attempts:', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( (string) $row['attempts'] ) . '</p>';
            if ( (int) $row['duplicate_warning'] ) {
                echo '<p><strong>' . esc_html__( 'Suspected duplicate (advisory only)', 'codverity-order-verification-for-woocommerce' ) . '</strong> — ' . esc_html__( 'Matched phone digits, country, currency, total, products and quantities when enrolled.', 'codverity-order-verification-for-woocommerce' ) . ' ' . esc_html( $this->date( $row['created_at'] ) ) . '</p>';
                foreach ( $this->workflow->repo->matches( $row['fingerprint'], $order->get_id(), (int) $row['created_at'] - (int) $this->workflow->settings->get( 'duplicate_hours' ) * HOUR_IN_SECONDS ) as $match ) {
                    $other = wc_get_order( $match ); if ( $other ) { echo '<a href="' . esc_url( $other->get_edit_order_url() ) . '">#' . esc_html( (string) $other->get_order_number() ) . '</a> '; }
                }
            }
            $this->historyTable( $this->workflow->repo->history( (int) $row['id'] ) );
        } else { echo '<p>' . esc_html__( 'No verification request. Use the queue to test eligibility or explicitly start verification.', 'codverity-order-verification-for-woocommerce' ) . '</p>'; }
        echo '<p><a class="button" href="' . esc_url( $this->url( 'queue', [ 'order_id' => $order->get_id() ] ) ) . '">' . esc_html__( 'Manage verification actions', 'codverity-order-verification-for-woocommerce' ) . '</a></p>';
    }

    public function handle(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( esc_html__( 'POST is required.', 'codverity-order-verification-for-woocommerce' ), '', [ 'response' => 405 ] ); }
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You are not authorized.', 'codverity-order-verification-for-woocommerce' ), '', [ 'response' => 403 ] ); }
        check_admin_referer( 'codverity_admin' );
        $operation = $this->text( 'operation', $_POST ); $orderId = absint( $this->text( 'order_id', $_POST ) );
        $error = false; $text = __( 'Action completed.', 'codverity-order-verification-for-woocommerce' );
        try {
            if ( $operation === 'export' ) { $this->csv( $this->filters( $_POST ) ); }
            elseif ( $operation === 'test' ) {
                $last = (int) get_user_meta( get_current_user_id(), 'codverity_last_test', true );
                if ( time() - $last < 60 ) { throw new \RuntimeException( 'test_cooldown' ); }
                update_user_meta( get_current_user_id(), 'codverity_last_test', time() );
                $accepted = $this->notifications->test( $this->text( 'destination', $_POST, 254 ) );
                $error = ! $accepted;
                update_option( 'codverity_last_mail', [ 'result' => $accepted ? 'accepted' : 'failed', 'at' => time() ], false );
                $text = $accepted ? __( 'Test email accepted by mail transport. Inbox delivery is not proven.', 'codverity-order-verification-for-woocommerce' ) : __( 'Mail transport rejected the test email. Check mail configuration.', 'codverity-order-verification-for-woocommerce' );
            } elseif ( $operation === 'order' ) {
                if ( ! current_user_can( 'edit_shop_orders' ) ) { throw new \RuntimeException( 'not_authorized' ); }
                $order = $this->workflow->freshOrder( $orderId );
                if ( ! $order ) { throw new \RuntimeException( 'order_unavailable' ); }
                $action = $this->text( 'order_action', $_POST ); $reason = $this->text( 'reason', $_POST, 255 );
                $row = $this->workflow->repo->byOrder( $orderId );
                if ( $action === 'eligibility' ) {
                    $result = $this->workflow->eligibility->evaluate( $order, (bool) $row, true );
                    $this->workflow->repo->event( $row ?? [ 'order_id' => $orderId ], 'eligibility_checked', '', '', [ 'eligibility' => $result ], 'staff', get_current_user_id() );
                    $text = __( 'Eligibility result:', 'codverity-order-verification-for-woocommerce' ) . ' ' . self::label( $result );
                } else {
                    if ( $reason === '' || $this->text( 'acknowledge', $_POST ) !== '1' ) { throw new \RuntimeException( 'reason_and_acknowledgment_required' ); }
                    if ( $action === 'start' ) { $this->workflow->start( $orderId, $reason ); }
                    elseif ( $action === 'resend' ) { $this->workflow->reissue( $orderId, $reason ); }
                    elseif ( $action === 'stop' ) { $this->workflow->stop( $orderId, $reason ); }
                    elseif ( in_array( $action, [ 'confirm', 'cancel' ], true ) && $row ) {
                        $result = $this->workflow->decide( (int) $row['id'], $action, 'staff', get_current_user_id(), $reason );
                        if ( ! in_array( $result, [ 'manually_confirmed', 'manually_cancelled' ], true ) ) { throw new \RuntimeException( $result ); }
                    } else { throw new \RuntimeException( 'invalid_action' ); }
                }
            } else { throw new \RuntimeException( 'invalid_action' ); }
        } catch ( \Throwable $e ) {
            $error = true;
            $text = __( 'The action could not be completed. Review the order state, cooldown, and Diagnostics, then retry. Code:', 'codverity-order-verification-for-woocommerce' ) . ' ' . sanitize_key( $e->getMessage() );
        }
        set_transient( 'codverity_notice_' . get_current_user_id(), [ 'error' => $error, 'text' => $text ], 120 );
        wp_safe_redirect( $this->url( $operation === 'test' ? 'settings' : 'queue', $orderId ? [ 'order_id' => $orderId ] : [] ) ); exit;
    }
    public static function csvCell( mixed $value ): string {
        $text = (string) $value;
        return preg_match( '/^[\s\x00-\x1f]*[=+@-]/u', $text ) ? "'" . $text : $text;
    }
    private function csv( array $filters ): never {
        nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="codverity-report.csv"' ); header( 'X-Content-Type-Options: nosniff' );
        $stream = fopen( 'php://output', 'w' );
        if ( ! $stream ) { throw new \RuntimeException( 'export_unavailable' ); }
        $fields = [ 'order_id', 'state', 'delivery', 'created_at', 'expires_at', 'response_at', 'resolved_at', 'attempts', 'transport_failures', 'reminders', 'duplicate_warning' ];
        fputcsv( $stream, $fields, ',', '"', '' ); $after = 0;
        do {
            $rows = $this->workflow->repo->rows( $filters, 1, 250, $after );
            foreach ( $rows as $row ) { $line = []; foreach ( $fields as $field ) { $line[] = self::csvCell( $row[ $field ] ); } fputcsv( $stream, $line, ',', '"', '' ); $after = (int) $row['id']; }
        } while ( count( $rows ) === 250 );
        fclose( $stream ); exit;
    }
}
