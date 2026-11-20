<?php
/**
 * Load DoubleScale's own non-autoloaded options in one query per request.
 *
 * Modules read many small options and transients while they boot: an
 * integration's settings, "already migrated?" flags, scheduling locks. Each
 * one WordPress does not autoload costs its own database query, on every
 * request, which on hosts with a remote database added up to seconds per
 * click (about 50 queries before a REST request did anything).
 *
 * This class watches which DoubleScale options a request reads that were not
 * autoloaded, remembers their names in one small autoloaded option, and on
 * the next request primes them all with a single query
 * (wp_prime_option_caches(), WordPress 6.4+). Option values are untouched:
 * get_option() returns exactly what it did before, only from cache. The list
 * resets when the free or Pro version changes, so names that are no longer
 * read drop out.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class BootOptionPrimer {

	/** Autoloaded: array{sig: string, names: string[]}. */
	const OPTION = 'doublescale_boot_options';

	/** Cap, so options with generated names can never grow the list forever. */
	const MAX_NAMES = 400;

	/** Options (and transients) this class looks after. */
	const PATTERN = '/^(?:_(?:site_)?transient_(?:timeout_)?)?doublescale_/';

	/** @var array<string, true> Names already in the stored list. */
	private static $known = array();

	/** @var array<string, true> Names read this request that were not known. */
	private static $seen = array();

	/** @var string */
	private static $sig = '';

	/** @var bool */
	private static $started = false;

	/**
	 * Prime the remembered options and start watching for new ones.
	 */
	public static function start(): void {
		if ( self::$started ) {
			return;
		}
		self::$started = true;
		self::$sig     = self::signature();

		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ( $stored['sig'] ?? '' ) === self::$sig && ! empty( $stored['names'] ) && is_array( $stored['names'] ) ) {
			self::$known = array_fill_keys( $stored['names'], true );
			if ( function_exists( 'wp_prime_option_caches' ) ) {
				wp_prime_option_caches( $stored['names'] );
			}
		}

		// `pre_option` runs for every get_option() (WordPress 6.1+).
		add_filter( 'pre_option', array( __CLASS__, 'observe' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'save' ) );
	}

	/**
	 * Note a DoubleScale option that WordPress did not autoload.
	 *
	 * @param mixed  $pre    Short-circuit value (passed through untouched).
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function observe( $pre, $option ) {
		if ( isset( self::$known[ $option ] ) || isset( self::$seen[ $option ] ) ) {
			return $pre;
		}
		if ( ! preg_match( self::PATTERN, (string) $option ) ) {
			return $pre;
		}
		$alloptions = wp_load_alloptions();
		if ( ! isset( $alloptions[ $option ] ) ) {
			self::$seen[ $option ] = true;
		}
		return $pre;
	}

	/**
	 * Remember newly seen names for the next request (only when there are any).
	 */
	public static function save(): void {
		if ( ! self::$seen || count( self::$known ) >= self::MAX_NAMES ) {
			return;
		}
		$names = array_slice( array_keys( self::$known + self::$seen ), 0, self::MAX_NAMES );
		update_option(
			self::OPTION,
			array(
				'sig'   => self::$sig,
				'names' => $names,
			),
			true
		);
		self::$known = array_fill_keys( $names, true );
		self::$seen  = array();
	}

	/**
	 * Which code is reading options: a new version starts a fresh list.
	 */
	private static function signature(): string {
		return ( defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : '' )
			. '|' . ( defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '' );
	}
}
