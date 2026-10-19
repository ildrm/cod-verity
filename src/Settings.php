<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** CODV-006, 018, 025, 043. Settings have no effects on already issued deadlines. */
final class Settings {
    public const OPTION = 'codverity_settings';

    public static function defaults(): array {
        return [
            'enabled' => false, 'hold_enabled' => true, 'min_total' => '', 'max_total' => '',
            'include_categories' => [], 'exclude_categories' => [], 'expiry_hours' => 72,
            'reminders' => true, 'reminder_hours' => '24,48', 'retry_limit' => 3,
            'auto_cancel' => false, 'duplicates' => true, 'duplicate_hours' => 24,
            'resend_cooldown' => 300, 'retention_days' => 90, 'request_retention_days' => 365,
            'delete_on_uninstall' => false, 'sender_name' => '', 'sender_email' => '',
            'email_subject' => __( 'Please confirm order #{order_number} at {store_name}', 'codverity-order-verification-for-woocommerce' ),
            'email_body' => __( "Hello {customer_first_name},\n\nThank you for ordering from {store_name}. Please confirm or cancel cash-on-delivery order #{order_number} ({order_total}) using this secure page:\n\n{verification_url}\n\nPlease respond before {expires_at}. Payment has not been collected; payment is due on delivery. Confirmation records your intent to receive the order.\n\nThank you,\n{store_name}", 'codverity-order-verification-for-woocommerce' ),
        ];
    }

    public function all(): array {
        return array_replace( self::defaults(), (array) get_option( self::OPTION, [] ) );
    }

    public function get( string $key ): mixed {
        return $this->all()[ $key ] ?? null;
    }

    /** Invalid configuration is rejected as a whole rather than silently enabling a policy. */
    public function validate( array $input ): array {
        $out = self::defaults();
        foreach ( [ 'min_total', 'max_total', 'reminder_hours', 'email_subject', 'email_body', 'sender_name', 'sender_email' ] as $key ) {
            if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { throw new \InvalidArgumentException( __( 'Settings fields must contain text or numbers.', 'codverity-order-verification-for-woocommerce' ) ); }
        }
        foreach ( [ 'enabled', 'hold_enabled', 'reminders', 'auto_cancel', 'duplicates', 'delete_on_uninstall' ] as $key ) {
            $out[ $key ] = ! empty( $input[ $key ] );
        }
        foreach ( [ 'expiry_hours' => [ 1, 168 ], 'retry_limit' => [ 0, 3 ], 'duplicate_hours' => [ 1, 168 ], 'resend_cooldown' => [ 60, 86400 ], 'retention_days' => [ 7, 730 ], 'request_retention_days' => [ 30, 3650 ] ] as $key => $range ) {
            $value = $input[ $key ] ?? $out[ $key ];
            if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/D', (string) $value ) || (int) $value < $range[0] || (int) $value > $range[1] ) {
                throw new \InvalidArgumentException( sprintf( __( '%1$s must be between %2$d and %3$d.', 'codverity-order-verification-for-woocommerce' ), $key, $range[0], $range[1] ) );
            }
            $out[ $key ] = (int) $value;
        }
        foreach ( [ 'min_total', 'max_total' ] as $key ) {
            $value = trim( (string) ( $input[ $key ] ?? '' ) );
            if ( $value !== '' && ( ! preg_match( '/^\d{1,12}(?:\.\d{1,8})?$/D', $value ) || strlen( substr( strrchr( $value, '.' ) ?: '', 1 ) ) > wc_get_price_decimals() ) ) {
                throw new \InvalidArgumentException( __( 'Totals must be nonnegative decimals using the store currency precision.', 'codverity-order-verification-for-woocommerce' ) );
            }
            $out[ $key ] = $value;
        }
        if ( $out['min_total'] !== '' && $out['max_total'] !== '' && Amount::compare( $out['min_total'], $out['max_total'] ) > 0 ) {
            throw new \InvalidArgumentException( __( 'Maximum total must not be less than minimum total.', 'codverity-order-verification-for-woocommerce' ) );
        }
        foreach ( [ 'include_categories', 'exclude_categories' ] as $key ) {
            $values = $input[ $key ] ?? [];
            if ( ! is_array( $values ) || count( $values ) > 200 ) {
                throw new \InvalidArgumentException( __( 'Invalid category selection.', 'codverity-order-verification-for-woocommerce' ) );
            }
            foreach ( $values as $value ) {
                if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,10}$/D', (string) $value ) ) { throw new \InvalidArgumentException( __( 'Category IDs must be positive whole numbers.', 'codverity-order-verification-for-woocommerce' ) ); }
            }
            $out[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
            foreach ( $out[ $key ] as $id ) {
                if ( ! term_exists( $id, 'product_cat' ) ) {
                    throw new \InvalidArgumentException( __( 'A selected product category no longer exists.', 'codverity-order-verification-for-woocommerce' ) );
                }
            }
        }
        $hours = trim( (string) ( $input['reminder_hours'] ?? '24,48' ) );
        if ( ! preg_match( '/^\d{1,3}(?:,\s*\d{1,3})?$/D', $hours ) ) {
            throw new \InvalidArgumentException( __( 'Enter one or two reminder hours, separated by a comma.', 'codverity-order-verification-for-woocommerce' ) );
        }
        $hours = array_values( array_unique( array_map( 'intval', explode( ',', $hours ) ) ) );
        sort( $hours );
        if ( $hours[0] < 1 || ( $out['reminders'] && max( $hours ) >= $out['expiry_hours'] ) ) {
            throw new \InvalidArgumentException( __( 'Reminder hours must be positive and earlier than expiration.', 'codverity-order-verification-for-woocommerce' ) );
        }
        $out['reminder_hours'] = implode( ',', $hours );
        foreach ( [ 'email_subject', 'sender_name', 'sender_email' ] as $key ) {
            $value = (string) ( $input[ $key ] ?? $out[ $key ] );
            if ( strlen( $value ) > 250 || preg_match( '/[\r\n\x00-\x1f\x7f]/', $value ) ) {
                throw new \InvalidArgumentException( __( 'Email headers must be single lines of at most 250 characters.', 'codverity-order-verification-for-woocommerce' ) );
            }
            $out[ $key ] = sanitize_text_field( $value );
        }
        if ( $out['sender_email'] !== '' && ! is_email( $out['sender_email'] ) ) {
            throw new \InvalidArgumentException( __( 'Enter a valid sender email.', 'codverity-order-verification-for-woocommerce' ) );
        }
        $out['email_body'] = sanitize_textarea_field( (string) ( $input['email_body'] ?? $out['email_body'] ) );
        if ( $out['email_subject'] === '' || strlen( $out['email_body'] ) > 20000 || ! str_contains( $out['email_body'], '{verification_url}' ) ) {
            throw new \InvalidArgumentException( __( 'Provide a subject and a message containing {verification_url} (maximum 20,000 bytes).', 'codverity-order-verification-for-woocommerce' ) );
        }
        preg_match_all( '/\{[a-z_]+\}/', $out['email_subject'] . $out['email_body'], $placeholders );
        if ( array_diff( $placeholders[0], [ '{store_name}', '{customer_first_name}', '{order_number}', '{order_total}', '{verification_url}', '{expires_at}' ] ) ) {
            throw new \InvalidArgumentException( __( 'Email templates contain an unsupported placeholder.', 'codverity-order-verification-for-woocommerce' ) );
        }
        return $out;
    }
}
