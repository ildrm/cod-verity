<?php
use CODVerity\Amount;
use CODVerity\State;
use CODVerity\Token;
use CODVerity\Admin;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DomainTest extends TestCase {
    public static function amounts(): array {
        return [ [ '0', '0.00', 0 ], [ '100.10', '100.1', 0 ], [ '9.99', '10', -1 ], [ '999999999999.99', '999999999999.98', 1 ], [ '0002.00', '2', 0 ], [ '0.00000001', '0.00000002', -1 ], [ '-1', '0', -1 ], [ '-10', '-9.99', -1 ], [ '-0.00', '0', 0 ], [ '0.0000000000001', '0.0000000000002', -1 ] ];
    }
    #[DataProvider( 'amounts' )]
    public function testDecimalBoundaries( string $a, string $b, int $expected ): void { self::assertSame( $expected, Amount::compare( $a, $b ) ); }
    public function testMalformedAmountRejected(): void { $this->expectException( InvalidArgumentException::class ); Amount::compare( '1e10', '10' ); }
    public function testTokenEntropyRotationAndSecretIsolation(): void {
        $tokens = new Token( 'test-secret' ); $seed = $tokens->seed(); $token = $tokens->derive( 42, 1, $seed );
        self::assertSame( 64, strlen( $seed ) ); self::assertTrue( $tokens->validFormat( $token ) );
        self::assertNotSame( $seed, $tokens->seed() ); self::assertNotSame( $token, $tokens->digest( $token ) );
        self::assertNotSame( $token, $tokens->derive( 42, 2, $seed ) );
        self::assertNotSame( $token, ( new Token( 'another-secret' ) )->derive( 42, 1, $seed ) );
        foreach ( [ '', str_repeat( 'a', 65 ), '<script>', str_repeat( 'G', 64 ), "\n" . $token, [ 'bad' ] ] as $input ) { if ( is_string( $input ) ) { self::assertFalse( $tokens->validFormat( $input ) ); } }
    }
    public function testChallengeExpirationAndBinding(): void {
        $tokens = new Token( 'secret' ); $token = str_repeat( 'a', 64 ); $at = 1800 * 20 + 10; $challenge = $tokens->challenge( $token, 20 );
        self::assertTrue( $tokens->verifyChallenge( $token, $challenge, $at ) );
        self::assertTrue( $tokens->verifyChallenge( $token, $challenge, $at + 1800 ) );
        self::assertFalse( $tokens->verifyChallenge( $token, $challenge, $at + 3600 ) );
        self::assertFalse( $tokens->verifyChallenge( str_repeat( 'b', 64 ), $challenge, $at ) );
    }
    public function testStateMachineRejectsTerminalReversal(): void {
        foreach ( [ 'confirmed', 'cancelled', 'manually_confirmed', 'manually_cancelled' ] as $state ) {
            foreach ( array_merge( State::ACTIVE, State::CLAIMED ) as $to ) { self::assertFalse( State::allows( $state, $to ) ); }
        }
        self::assertTrue( State::allows( 'pending', 'confirming' ) ); self::assertTrue( State::allows( 'confirming', 'confirmed' ) );
        self::assertFalse( State::allows( 'pending', 'cancelled' ) ); self::assertFalse( State::allows( 'unknown', 'pending' ) );
    }
    public function testCsvFormulaProtection(): void {
        foreach ( [ '=1+1', '+cmd', '-2+3', '@SUM(1)', "\t=2", '  +5' ] as $value ) { self::assertStringStartsWith( "'", Admin::csvCell( $value ) ); }
        self::assertSame( 'confirmed', Admin::csvCell( 'confirmed' ) ); self::assertSame( '123', Admin::csvCell( 123 ) );
    }
}
