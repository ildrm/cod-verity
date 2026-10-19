<?php
declare(strict_types=1);
// Development-only generated API definitions, pinned to the tested platform versions.
$directory = dirname( __DIR__ ) . '/build/stubs';
if ( ! is_dir( $directory ) ) { mkdir( $directory, 0755, true ); }
$sources = [
    'wordpress' => 'https://raw.githubusercontent.com/php-stubs/wordpress-stubs/v6.6.2/wordpress-stubs.php',
    'woocommerce' => 'https://raw.githubusercontent.com/php-stubs/woocommerce-stubs/v9.5.2/woocommerce-stubs.php',
    'action-scheduler-functions' => 'https://raw.githubusercontent.com/woocommerce/action-scheduler/3.8.2/functions.php',
];
foreach ( $sources as $project => $url ) {
    $handle = curl_init( $url ); curl_setopt_array( $handle, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60 ] );
    $source = curl_exec( $handle ); $status = curl_getinfo( $handle, CURLINFO_HTTP_CODE );
    if ( $source === false || $status !== 200 || ! str_starts_with( $source, '<?php' ) ) { throw new RuntimeException( "Cannot fetch $project API stubs (HTTP $status)." ); }
    $filename = $project === 'action-scheduler-functions' ? "$project.php" : "$project-stubs.php";
    file_put_contents( "$directory/$filename", $source ); echo "$project API definitions ready\n";
}
