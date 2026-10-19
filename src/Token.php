<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** CODV-017. Database-only disclosure does not reveal bearer tokens. */
final class Token {
    public function __construct( private readonly string $secret ) {}

    public function seed(): string {
        return bin2hex( random_bytes( 32 ) );
    }
    public function derive( int $orderId, int $generation, string $seed ): string {
        return hash_hmac( 'sha256', "codverity:bearer:v1:$orderId:$generation:$seed", $this->secret );
    }
    public function digest( string $token ): string {
        return hash_hmac( 'sha256', 'codverity:lookup:v1:' . $token, $this->secret );
    }
    public function validFormat( string $token ): bool {
        return (bool) preg_match( '/^[a-f0-9]{64}$/D', $token );
    }
    public function challenge( string $token, int $bucket ): string {
        return hash_hmac( 'sha256', "codverity:form:v1:$bucket:$token", $this->secret );
    }
    public function verifyChallenge( string $token, string $challenge, int $now ): bool {
        if ( ! $this->validFormat( $challenge ) ) {
            return false;
        }
        $bucket = (int) floor( $now / 1800 );
        return hash_equals( $this->challenge( $token, $bucket ), $challenge ) || hash_equals( $this->challenge( $token, $bucket - 1 ), $challenge );
    }
}
