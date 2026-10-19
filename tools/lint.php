<?php
declare(strict_types=1);
$root = dirname( __DIR__ );
$files = [ $root . '/codverity-order-verification-for-woocommerce.php', $root . '/uninstall.php' ];
foreach ( [ 'src', 'tests', 'tools' ] as $directory ) {
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$directory", FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file ) { if ( $file->getExtension() === 'php' ) { $files[] = $file->getPathname(); } }
}
$failed = false;
foreach ( array_unique( $files ) as $file ) {
    exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
    if ( $code ) { echo implode( "\n", $output ) . "\n"; $failed = true; }
    $output = [];
}
echo count( $files ) . " PHP files checked\n";
exit( $failed ? 1 : 0 );
