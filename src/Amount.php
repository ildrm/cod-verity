<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** Decimal comparison without floating point rounding or a BCMath dependency. */
final class Amount {
    public static function compare( string $a, string $b ): int {
        $normalize = static function ( string $value ): array {
            if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/D', $value ) ) {
                throw new \InvalidArgumentException( 'invalid_amount' );
            }
            $negative = str_starts_with( $value, '-' );
            [ $whole, $fraction ] = array_pad( explode( '.', ltrim( $value, '-' ), 2 ), 2, '' );
            $whole = ltrim( $whole, '0' ) ?: '0'; $fraction = rtrim( $fraction, '0' );
            return [ $whole, $fraction, $negative && ( $whole !== '0' || $fraction !== '' ) ];
        };
        [ $wholeA, $fractionA, $negativeA ] = $normalize( $a );
        [ $wholeB, $fractionB, $negativeB ] = $normalize( $b );
        if ( $negativeA !== $negativeB ) { return $negativeA ? -1 : 1; }
        $precision = max( strlen( $fractionA ), strlen( $fractionB ) );
        $result = strlen( $wholeA ) <=> strlen( $wholeB ) ?: ( strcmp( $wholeA, $wholeB ) <=> 0 ) ?: ( strcmp( str_pad( $fractionA, $precision, '0' ), str_pad( $fractionB, $precision, '0' ) ) <=> 0 );
        return $negativeA ? -$result : $result;
    }
}
