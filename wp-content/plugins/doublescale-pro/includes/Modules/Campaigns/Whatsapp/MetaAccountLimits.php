<?php
/**
 * The sending limits Meta assigns to the connected WhatsApp number.
 *
 * They differ per account and change over time:
 * - messaging tier: unique recipients of business-initiated messages per
 *   rolling 24 hours. Meta's ladder: 250 before business verification, then
 *   2,000 (TIER_2K) once verified, 10,000, 100,000 and unlimited. Older
 *   accounts may still report TIER_1K, and Meta reports no tier at all for
 *   accounts it does not tier;
 * - throughput level: messages per second (STANDARD ≈ 80, HIGH up to 1,000);
 * - quality rating: GREEN / YELLOW / RED, driven by blocks and reports.
 *
 * Read from Meta, cached for an hour, and remembered as "last known" for when
 * Meta cannot be reached. With nothing known at all, the smallest account is
 * assumed — never a bigger one, because over-sending is what gets a number
 * restricted. When Meta answers but reports no tier, nothing is assumed
 * either way: tier_known is false, no daily cap of ours applies, and Meta's
 * own limit stands.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Managers\IntegrationsManager;

/**
 * MetaAccountLimits class
 */
final class MetaAccountLimits {

	/** Transient holding the limits for the cache window. */
	const CACHE = 'doublescale_meta_account_limits';

	/** How long Meta's answer is trusted before asking again. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/** Option holding the last limits Meta reported, for when it is unreachable. */
	const LAST_KNOWN = 'doublescale_meta_account_limits_last_known';

	/** The smallest tier Meta assigns — the assumption when nothing is known. */
	const DEFAULT_TIER_LIMIT = 250;

	/** STANDARD throughput — the assumption when nothing is known. */
	const DEFAULT_MAX_PER_SECOND = 80;

	/**
	 * In-request copy, so every send in a batch does not re-read the transient.
	 *
	 * @var array|null|false False when not loaded yet.
	 */
	private static $memo = false;

	/**
	 * Whether Meta's limits apply: they do only when Meta sends WhatsApp.
	 *
	 * @return bool
	 */
	public static function applies() {
		return 'meta-whatsapp' === get_option( 'doublescale_default_whatsapp_provider', 'meta-whatsapp' );
	}

	/**
	 * The connected number's limits.
	 *
	 * @param bool $force_refresh Ask Meta even inside the cache window.
	 * @return array{tier:string,tier_known:bool,tier_limit:int|null,phone_number:string,throughput_level:string,max_per_second:int,quality:string,source:string,fetched_at:string}|null
	 *         Null when WhatsApp is not sent through Meta.
	 */
	public static function get( $force_refresh = false ) {
		if ( ! self::applies() ) {
			return null;
		}

		if ( ! $force_refresh ) {
			if ( false !== self::$memo ) {
				return self::$memo;
			}

			$cached = get_transient( self::CACHE );
			if ( self::is_current( $cached ) ) {
				self::$memo = $cached;
				return $cached;
			}
		}

		// Reading the limits must never stop sending. Pro's IntegrationsManager
		// throws when the Meta integration is not registered; uncaught, that
		// reached the campaign loop and silently stopped every WhatsApp send.
		try {
			$raw = self::fetch_raw();
		} catch ( \Throwable $e ) {
			$raw = new \WP_Error( 'meta_limits_exception', $e->getMessage() );
		}

		if ( is_array( $raw ) ) {
			$limits = self::normalize( $raw, 'meta' );
			set_transient( self::CACHE, $limits, self::CACHE_TTL );
			update_option( self::LAST_KNOWN, $limits, false );
		} else {
			$last   = get_option( self::LAST_KNOWN, null );
			$limits = self::is_current( $last )
				? array_merge( $last, array( 'source' => 'last_known' ) )
				: self::normalize( array(), 'default' );

			// Don't hammer Meta while it is failing: retry after a short while.
			set_transient( self::CACHE, $limits, 5 * MINUTE_IN_SECONDS );

			doublescale_get_logger()->info(
				'Meta account limits unavailable; using ' . $limits['source'] . ' values',
				array(
					'code'  => 'meta_account_limits_fallback',
					'error' => is_wp_error( $raw ) ? $raw->get_error_message() : 'unknown',
				)
			);
		}

		self::$memo = $limits;

		return $limits;
	}

	/**
	 * Whether a stored answer was written by this version.
	 *
	 * Answers cached before tier_known existed cannot say whether Meta
	 * reported the tier or the plugin assumed it, and the assumption was the
	 * smallest account. They are re-read rather than trusted, so a site that
	 * updates mid-cache-window is not capped for another hour on a tier Meta
	 * never gave.
	 *
	 * @param mixed $limits Stored limits.
	 * @return bool
	 */
	private static function is_current( $limits ) {
		// tier_field marks answers read since the portfolio field: older ones
		// may hold the deprecated number tier (250 for a 100,000 portfolio).
		return is_array( $limits ) && array_key_exists( 'tier_known', $limits ) && array_key_exists( 'tier_field', $limits );
	}

	/**
	 * Unique recipients per rolling 24h for a Meta tier; null means unlimited.
	 *
	 * Parsed from the value rather than matched against a list, so the whole
	 * ladder is read from whatever spelling Meta uses (TIER_2K and TIER_2000
	 * both mean 2,000) and a tier it adds later needs no code change.
	 * Anything unreadable is the smallest tier.
	 *
	 * Callers must not reach here for an absent tier: that is unknown, not
	 * 250, and {@see self::normalize()} keeps the two apart.
	 *
	 * @param string $tier Meta's messaging_limit_tier.
	 * @return int|null
	 */
	public static function parse_tier( $tier ) {
		$tier = strtoupper( trim( (string) $tier ) );

		if ( 'TIER_UNLIMITED' === $tier ) {
			return null;
		}

		if ( preg_match( '/^TIER_(\d+)(K)?$/', $tier, $m ) ) {
			$value = (int) $m[1] * ( empty( $m[2] ) ? 1 : 1000 );
			return $value > 0 ? $value : self::DEFAULT_TIER_LIMIT;
		}

		return self::DEFAULT_TIER_LIMIT;
	}

	/**
	 * Messages per second for a Meta throughput level.
	 *
	 * @param string $level Meta's throughput.level.
	 * @return int
	 */
	public static function parse_throughput( $level ) {
		return 'HIGH' === strtoupper( trim( (string) $level ) ) ? 1000 : self::DEFAULT_MAX_PER_SECOND;
	}

	/**
	 * Drop the cached limits. The last known values stay unless $everything.
	 *
	 * @param bool $everything Also forget the last known values.
	 * @return void
	 */
	public static function flush( $everything = false ) {
		self::$memo = false;
		delete_transient( self::CACHE );
		if ( $everything ) {
			delete_option( self::LAST_KNOWN );
		}
	}

	/**
	 * Forget the in-request copy only, as a new request would.
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$memo = false;
	}

	/**
	 * Meta's phone-number fields for the connected number.
	 *
	 * @return array|\WP_Error
	 */
	private static function fetch_raw() {
		/**
		 * Short-circuit the Graph call (tests, or a site that knows its limits).
		 *
		 * @param array|\WP_Error|null $raw Phone-number fields, an error, or null to ask Meta.
		 */
		$pre = apply_filters( 'pre_doublescale_meta_account_limits', null );
		if ( null !== $pre ) {
			return $pre;
		}

		$integration = IntegrationsManager::instance()->get_integration( 'meta-whatsapp' );
		if ( ! $integration || ! $integration->is_connected() ) {
			return new \WP_Error( 'meta_not_connected', 'Meta WhatsApp is not connected.' );
		}

		$api = $integration->connect();

		// Added in the Pro release that reads limits; an older Pro has no such
		// call, and the caller then assumes the smallest account.
		if ( ! is_object( $api ) || ! method_exists( $api, 'get_messaging_limits' ) ) {
			return new \WP_Error( 'meta_limits_unsupported', 'The installed Pro version cannot read Meta messaging limits.' );
		}

		$result = $api->get_messaging_limits();
		if ( empty( $result['success'] ) || ! is_array( $result['data'] ?? null ) ) {
			return new \WP_Error( 'meta_limits_failed', (string) ( $result['error'] ?? 'Meta did not return messaging limits.' ) );
		}

		return $result['data'];
	}

	/**
	 * Shape Meta's fields into the limits the plugin works with.
	 *
	 * @param array  $raw    Phone-number fields.
	 * @param string $source 'meta' or 'default'.
	 * @return array
	 */
	private static function normalize( array $raw, $source ) {
		// Meta sets limits per business portfolio and deprecated the phone
		// number's messaging_limit_tier, which below Graph v24.0 still gives
		// the number's old tier: a 100,000 portfolio read as 250. The
		// portfolio field wins; the number's tier is only a fallback.
		$portfolio = trim( (string) ( $raw['whatsapp_business_manager_messaging_limit'] ?? '' ) );
		$tier      = '' !== $portfolio ? $portfolio : trim( (string) ( $raw['messaging_limit_tier'] ?? '' ) );
		$level   = (string) ( $raw['throughput']['level'] ?? '' );
		$quality = strtoupper( (string) ( $raw['quality_rating'] ?? '' ) );
		$number  = trim( (string) ( $raw['display_phone_number'] ?? '' ) );

		// Meta answering without a tier is not the same as Meta saying 250.
		// It stopped reporting messaging_limit_tier for accounts it no longer
		// tiers, and an account with a six-figure limit would otherwise be
		// capped at the smallest tier — with the card claiming Meta said so.
		// An unreported tier means no cap of ours; Meta still enforces its own.
		$reported = 'meta' === $source && '' !== $tier;

		if ( 'meta' === $source && ! $reported ) {
			doublescale_get_logger()->info(
				'Meta answered without a messaging limit tier; no daily cap will be applied',
				array(
					'code'   => 'meta_limits_no_tier',
					'fields' => array_keys( $raw ),
					'number' => $number,
				)
			);
		}

		return array(
			'tier'             => $reported ? $tier : ( 'meta' === $source ? '' : 'TIER_250' ),
			'tier_known'       => $reported,
			// Which Meta field the tier came from ('' when none was read).
			'tier_field'       => $reported ? ( '' !== $portfolio ? 'portfolio' : 'number' ) : '',
			'tier_limit'       => $reported ? self::parse_tier( $tier ) : ( 'meta' === $source ? null : self::DEFAULT_TIER_LIMIT ),
			'phone_number'     => $number,
			'throughput_level' => '' !== $level ? $level : 'STANDARD',
			'max_per_second'   => self::parse_throughput( $level ),
			'quality'          => in_array( $quality, array( 'GREEN', 'YELLOW', 'RED' ), true ) ? $quality : 'UNKNOWN',
			'source'           => $source,
			'fetched_at'       => gmdate( 'Y-m-d H:i:s' ),
		);
	}
}
