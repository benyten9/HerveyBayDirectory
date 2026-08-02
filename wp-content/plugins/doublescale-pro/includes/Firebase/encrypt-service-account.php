#!/usr/bin/env php
<?php
/**
 * One-time developer tool: encrypts a Firebase service account JSON file
 * for bundling with the plugin.
 *
 * Usage:
 *   php encrypt-service-account.php /path/to/service-account.json
 *
 * Outputs:
 *   includes/firebase/service-account.enc  (encrypted blob)
 *   includes/firebase/bundle.key           (encryption key)
 *
 * Both files must be committed to the repo. The .enc file is safe to
 * distribute; it can only be decrypted with the bundle.key.
 *
 * @package DoubleScale\Pro\Pro
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'CLI only.' );
}

if ( ! isset( $argv[1] ) || ! file_exists( $argv[1] ) ) {
	fprintf( STDERR, "Usage: php %s /path/to/service-account.json\n", $argv[0] );
	exit( 1 );
}

$plaintext = file_get_contents( $argv[1] );
$sa        = json_decode( $plaintext, true );

if ( ! $sa || empty( $sa['project_id'] ) || empty( $sa['private_key'] ) ) {
	fprintf( STDERR, "Error: invalid service account JSON.\n" );
	exit( 1 );
}

$bundle_key = bin2hex( random_bytes( 32 ) );
$key        = hash( 'sha256', $bundle_key, true );
$iv         = openssl_random_pseudo_bytes( 16 );
$cipher     = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

if ( false === $cipher ) {
	fprintf( STDERR, "Error: encryption failed.\n" );
	exit( 1 );
}

$dir = __DIR__;
file_put_contents( $dir . '/service-account.enc', base64_encode( $iv . $cipher ) );
file_put_contents( $dir . '/bundle.key', $bundle_key );

printf( "Encrypted service account for project: %s\n", $sa['project_id'] );
printf( "  → %s/service-account.enc\n", $dir );
printf( "  → %s/bundle.key\n", $dir );
printf( "Commit both files to the repo.\n" );
