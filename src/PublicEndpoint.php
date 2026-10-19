<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

final class PublicEndpoint {
    public function __construct( private readonly Workflow $workflow ) {}
    public function register(): void {
        add_action( 'admin_post_nopriv_codverity_verify', [ $this, 'handle' ] );
        add_action( 'admin_post_codverity_verify', [ $this, 'handle' ] );
    }

    /** Fixed-window IP limits; only a keyed, short-lived IP digest is stored. */
    public function rateAllowed( string $address ): bool {
        $digest = hash_hmac( 'sha256', substr( $address, 0, 64 ), wp_salt( 'nonce' ) );
        return $this->workflow->repo->locked( (int) hexdec( substr( $digest, 0, 12 ) ), static function () use ( $digest ): bool {
            $key = 'codverity_rate_' . substr( $digest, 0, 32 );
            $bucket = (int) floor( time() / 60 );
            $record = get_transient( $key );
            $count = is_array( $record ) && (int) $record[0] === $bucket ? (int) $record[1] : 0;
            set_transient( $key, [ $bucket, $count + 1 ], 120 );
            return $count < 60;
        } );
    }

    public function handle(): void {
        nocache_headers();
        header( 'Cache-Control: no-store, private, max-age=0' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: DENY' );
        header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ( ! in_array( $method, [ 'GET', 'POST' ], true ) ) { header( 'Allow: GET, POST' ); $this->render( 'unavailable', 405 ); }
        if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 4096 ) { $this->render( 'invalid', 413 ); }
        try {
            if ( ! $this->rateAllowed( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) { header( 'Retry-After: 60' ); $this->render( 'rate_limited', 429 ); }
            $input = $method === 'POST' ? $_POST : $_GET;
            $token = isset( $input['token'] ) && is_string( $input['token'] ) ? wp_unslash( $input['token'] ) : '';
            if ( ! $this->workflow->tokens->validFormat( $token ) ) { $this->render( 'invalid', 400 ); }
            $row = $this->workflow->repo->byHash( $this->workflow->tokens->digest( $token ) );
            if ( ! $row ) { $this->render( 'invalid', 404 ); }
            if ( ! State::active( $row['state'] ) ) { $this->render( $row['state'] === 'expired' ? 'expired' : 'already_resolved', 410 ); }
            if ( (int) $row['expires_at'] <= time() ) { $this->render( 'expired', 410 ); }
            if ( $method === 'GET' ) { $this->render( 'form', 200, $token, $row['expected_status'] === 'on-hold' ); }
            $challenge = isset( $input['challenge'] ) && is_string( $input['challenge'] ) ? wp_unslash( $input['challenge'] ) : '';
            $decision = isset( $input['decision'] ) && is_string( $input['decision'] ) ? $input['decision'] : '';
            if ( ! $this->workflow->tokens->verifyChallenge( $token, $challenge, time() ) || ! in_array( $decision, [ 'confirm', 'cancel' ], true ) ) { $this->render( 'invalid', 403 ); }
            $result = $this->workflow->decide( (int) $row['id'], $decision, 'customer', 0, '', (int) $row['generation'] );
            $this->render( $result, in_array( $result, [ 'confirmed', 'cancelled' ], true ) ? 200 : 409 );
        } catch ( \Throwable $e ) {
            $this->render( $e->getMessage() === 'cancellation_requires_hold' ? 'contact_store' : 'unavailable', $e->getMessage() === 'cancellation_requires_hold' ? 409 : 503 );
        }
    }

    private function render( string $state, int $status, string $token = '', bool $canCancel = true ): never {
        status_header( $status );
        header( 'Content-Type: text/html; charset=UTF-8' );
        $messages = [
            'form' => __( 'Please confirm that you intend to receive your cash-on-delivery order. Payment is due on delivery and has not been collected.', 'codverity-order-verification-for-woocommerce' ),
            'confirmed' => __( 'Thank you. Your order confirmation has been recorded. This does not record a payment.', 'codverity-order-verification-for-woocommerce' ),
            'cancelled' => __( 'Your cancellation has been recorded. Contact the store if you need help.', 'codverity-order-verification-for-woocommerce' ),
            'expired' => __( 'This link has expired. Please contact the store for assistance.', 'codverity-order-verification-for-woocommerce' ),
            'already_resolved' => __( 'This request has already been resolved or is no longer available. No further action was taken.', 'codverity-order-verification-for-woocommerce' ),
            'interrupted' => __( 'The order has changed. Please contact the store to review it.', 'codverity-order-verification-for-woocommerce' ),
            'invalid' => __( 'This verification link is invalid or unavailable. Please contact the store.', 'codverity-order-verification-for-woocommerce' ),
            'unavailable' => __( 'Verification is temporarily unavailable. Please try again later or contact the store.', 'codverity-order-verification-for-woocommerce' ),
            'rate_limited' => __( 'Too many requests. Please wait a minute and try again.', 'codverity-order-verification-for-woocommerce' ),
            'contact_store' => __( 'This order may already be in fulfillment. Please contact the store to request cancellation.', 'codverity-order-verification-for-woocommerce' ),
        ];
        echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html__( 'Order verification', 'codverity-order-verification-for-woocommerce' ) . '</title><style>body{font:18px/1.6 system-ui,sans-serif;background:#f3f5f6;color:#17252d;margin:0;padding:24px}main{max-width:560px;margin:8vh auto;background:#fff;padding:32px;border-radius:12px;box-shadow:0 4px 24px #0001}h1{font-size:26px}button{font:inherit;padding:12px 18px;border:2px solid #18604c;border-radius:6px;background:#18604c;color:#fff;cursor:pointer;margin:8px 8px 8px 0}button[value=cancel]{background:#fff;color:#8b1c1c;border-color:#8b1c1c}button:focus-visible{outline:3px solid #275ddd;outline-offset:3px}@media(max-width:480px){main{padding:20px;margin:2vh auto}button{width:100%}}</style></head><body><main><h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p role="status">' . esc_html( $messages[ $state ] ?? $messages['unavailable'] ) . '</p>';
        if ( $state === 'form' ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="codverity_verify"><input type="hidden" name="token" value="' . esc_attr( $token ) . '"><input type="hidden" name="challenge" value="' . esc_attr( $this->workflow->tokens->challenge( $token, (int) floor( time() / 1800 ) ) ) . '"><button name="decision" value="confirm">' . esc_html__( 'Confirm Order', 'codverity-order-verification-for-woocommerce' ) . '</button>';
            if ( $canCancel ) { echo '<button name="decision" value="cancel">' . esc_html__( 'Cancel Order', 'codverity-order-verification-for-woocommerce' ) . '</button>'; }
            else { echo '<p>' . esc_html( $messages['contact_store'] ) . '</p>'; }
            echo '</form>';
        }
        echo '</main></body></html>';
        exit;
    }
}
