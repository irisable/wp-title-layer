<?php
/**
 * Parse every production PHP file with the selected Playground PHP version.
 */

$root     = '/wordpress/wp-content/plugins/wp-title-layer';
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
$checked  = 0;
$failed   = array();

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) || false !== strpos( $file->getPathname(), '/tests/' ) ) {
		continue;
	}
	if ( preg_match( '#/(?:build|node_modules|vendor)/#', $file->getPathname() ) ) {
		continue;
	}

	try {
		token_get_all( file_get_contents( $file->getPathname() ), TOKEN_PARSE );
		++$checked;
	} catch ( ParseError $error ) {
		$failed[] = str_replace( $root . '/', '', $file->getPathname() ) . ': ' . $error->getMessage();
	}
}

if ( $failed ) {
	fwrite( STDERR, "PHP syntax failures:\n- " . implode( "\n- ", $failed ) . "\n" );
	exit( 1 );
}

echo 'PHP files parsed: ' . $checked . "\n";
