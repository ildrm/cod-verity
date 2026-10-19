<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** CODV-023–031. wp_mail success means transport acceptance, never delivery. */
final class Notifications {
    public function __construct( private readonly Workflow $workflow ) {}

    public function url( array $row ): string {
        return add_query_arg( [ 'action' => 'codverity_verify', 'token' => $this->workflow->tokens->derive( (int) $row['order_id'], (int) $row['generation'], $row['seed'] ) ], admin_url( 'admin-post.php' ) );
    }
    public function headers(): array {
        $settings = $this->workflow->settings;
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        $email = $settings->get( 'sender_email' );
        if ( $email && is_email( $email ) && ! preg_match( '/[\r\n]/', $email ) ) {
            $name = preg_replace( '/[\r\n<>\x00-\x1f\x7f]/', '', (string) $settings->get( 'sender_name' ) );
            $headers[] = 'From: ' . ( $name ? $name . ' ' : '' ) . '<' . $email . '>';
        }
        return $headers;
    }
    public function send( array $row, \WC_Order $order ): bool {
        $destination = $order->get_billing_email();
        if ( ! is_email( $destination ) || preg_match( '/[\r\n]/', $destination ) ) { return false; }
        $plain = static fn( string $text ): string => html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
        $values = [
            '{store_name}' => $plain( get_bloginfo( 'name' ) ), '{customer_first_name}' => $plain( $order->get_billing_first_name() ),
            '{order_number}' => $plain( (string) $order->get_order_number() ),
            '{order_total}' => $plain( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ),
            '{verification_url}' => $this->url( $row ),
            '{expires_at}' => wp_date( 'Y-m-d H:i T', (int) $row['expires_at'] ),
        ];
        $subject = strtr( (string) $this->workflow->settings->get( 'email_subject' ), $values );
        $subject = preg_replace( '/[\r\n\x00-\x1f\x7f]/', '', $subject );
        $body = strtr( (string) $this->workflow->settings->get( 'email_body' ), $values );
        if ( ! str_contains( $body, $values['{verification_url}'] ) ) { $body .= "\n\n" . $values['{verification_url}']; }
        return wp_mail( $destination, $subject, $body, $this->headers() );
    }
    public function test( string $destination ): bool {
        if ( ! is_email( $destination ) || strlen( $destination ) > 254 || preg_match( '/[\r\n]/', $destination ) ) { throw new \InvalidArgumentException( 'invalid_email' ); }
        return wp_mail( $destination, __( 'CODVerity mail transport test', 'codverity-order-verification-for-woocommerce' ), __( 'This is a transport test. It contains no customer verification link and does not change any order. Acceptance by the transport does not prove inbox delivery.', 'codverity-order-verification-for-woocommerce' ), $this->headers() );
    }
}
