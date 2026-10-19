<?php
namespace CODVerity;

defined( 'ABSPATH' ) || exit;

/** CODV-010. All persisted state changes pass this transition table. */
final class State {
    public const ACTIVE = [ 'pending', 'awaiting_response' ];
    public const CLAIMED = [ 'confirming', 'cancelling', 'expiring' ];
    public const FINAL = [ 'confirmed', 'cancelled', 'expired', 'manually_confirmed', 'manually_cancelled', 'interrupted' ];
    public const TRANSITIONS = [
        'pending' => [ 'awaiting_response', 'confirming', 'cancelling', 'expiring', 'interrupted' ],
        'awaiting_response' => [ 'confirming', 'cancelling', 'expiring', 'interrupted', 'pending' ],
        'confirming' => [ 'confirmed', 'manually_confirmed', 'interrupted' ],
        'cancelling' => [ 'cancelled', 'manually_cancelled', 'interrupted' ],
        'expiring' => [ 'expired', 'interrupted' ],
        'expired' => [ 'pending', 'confirming', 'cancelling', 'interrupted' ],
        'interrupted' => [ 'pending', 'confirming', 'cancelling' ],
    ];

    public static function allows( string $from, string $to ): bool {
        return $from === $to || in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
    }
    public static function active( string $state ): bool {
        return in_array( $state, self::ACTIVE, true );
    }
}
