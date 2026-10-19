<?php
require __DIR__ . '/bootstrap.php';
$barrier = $argv[3];
$deadline = microtime( true ) + 20;
while ( ! is_file( $barrier ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
$result = CODVerity\Plugin::$instance->workflow->decide( (int) $argv[1], $argv[2] );
echo json_encode( [ 'decision' => $argv[2], 'result' => $result ] );
