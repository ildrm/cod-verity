<?php
declare(strict_types=1);
$root = dirname( __DIR__ );
$slug = 'codverity-order-verification-for-woocommerce';
$version = '1.0.0';
if ( ! is_dir( "$root/dist" ) ) { mkdir( "$root/dist", 0755, true ); }
$path = "$root/dist/$slug-$version.zip";
$zip = new ZipArchive();
if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) { throw new RuntimeException( 'Cannot create package.' ); }
$files = [ "$slug.php", 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE' ];
foreach ( [ 'src', 'assets', 'languages', 'docs' ] as $directory ) {
    if ( ! is_dir( "$root/$directory" ) ) { continue; }
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$directory", FilesystemIterator::SKIP_DOTS ) ) as $file ) {
        if ( $file->isFile() && ! $file->isLink() ) { $files[] = substr( $file->getPathname(), strlen( $root ) + 1 ); }
    }
}
sort( $files );
foreach ( $files as $file ) {
    if ( ! is_file( "$root/$file" ) ) { throw new RuntimeException( "Missing runtime/documentation file: $file" ); }
    $zip->addFile( "$root/$file", "$slug/$file" );
    $zip->setMtimeName( "$slug/$file", 1792368000 );
}
$zip->close();
$check = new ZipArchive(); $check->open( $path );
for ( $i = 0; $i < $check->numFiles; $i++ ) {
    $name = $check->getNameIndex( $i );
    if ( ! str_starts_with( $name, "$slug/" ) || preg_match( '~(?:^|/)(?:vendor|build|tests|\.env|\.git)(?:/|$)~', $name ) ) { throw new RuntimeException( "Forbidden package entry: $name" ); }
}
if ( $check->locateName( "$slug/$slug.php" ) === false ) { throw new RuntimeException( 'Plugin entry point missing.' ); }
$count = $check->numFiles; $check->close();
file_put_contents( "$path.sha256", hash_file( 'sha256', $path ) . '  ' . basename( $path ) . "\n" );
echo "$path\n$count files; package layout verified\n";
