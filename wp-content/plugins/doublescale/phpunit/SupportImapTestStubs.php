<?php
/**
 * WordPress function/constant stubs for the Support custom-IMAP unit tests.
 *
 * The shared `bootstrap.php` shims only options/hooks/transients/`__`/REST base
 * classes. The custom-IMAP code under test reaches a few more WP primitives —
 * the sanitizers in {@see \DoubleScale\Modules\Support\Rest\Controllers\RestMailboxController}
 * (`sanitize_email`, `wp_kses_post`, `sanitize_key`) and the at-rest encryption
 * in {@see \DoubleScale\Core\Settings\Settings} (`SECURE_AUTH_KEY`, plus
 * `wp_json_encode` via the model boundary). This file fills exactly those gaps so
 * the tests exercise real logic without a live WordPress.
 *
 * `require_once`d at the top of each Support custom-IMAP test (same per-concern
 * pattern as {@see RestApiEndpointTestStubs.php}).
 *
 * @package DoubleScale\Tests
 */

defined( 'ABSPATH' ) || exit;

// Deterministic key so encrypt_value()/decrypt_value() round-trip in tests.
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'doublescale-phpunit-secure-auth-key-0123456789' );
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return (int) abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( $str ) : (string) $str;
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';
		// Cheap RFC-ish clean: strip chars WP's sanitize_email would drop.
		return (string) preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@-]/', '', $email );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		$email = is_string( $email ) ? $email : '';
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = is_string( $key ) ? strtolower( $key ) : '';
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		// Tests don't assert HTML filtering — pass through unchanged.
		return is_string( $data ) ? $data : (string) $data;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = is_string( $title ) ? strtolower( trim( $title ) ) : '';
		$title = preg_replace( '/[^a-z0-9\s\-]/', '', $title );
		return (string) preg_replace( '/[\s\-]+/', '-', $title );
	}
}
