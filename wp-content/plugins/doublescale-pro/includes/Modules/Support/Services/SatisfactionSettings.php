<?php
/**
 * Customer satisfaction for tickets answered by email: on or off (off until
 * the owner turns it on), and how many days a rating link stays valid
 * (default 30, between 7 and 90).
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

/**
 * SatisfactionSettings class.
 */
final class SatisfactionSettings {

	public const OPTION = 'doublescale_support_satisfaction';

	/**
	 * Free has the rating columns on tickets (schema 1.8.0.6, with the
	 * "solved" email filter). An older free: no ratings at all, never a
	 * rating saved nowhere. The public rating endpoint also rate-limits by
	 * visitor address with free's RateLimiter and ClientIp (free 1.9.11): an
	 * older free has no such classes, so it stays off rather than fatal.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return defined( 'DOUBLESCALE_SCHEMA_REV' )
			&& version_compare( (string) DOUBLESCALE_SCHEMA_REV, '1.8.0.6', '>=' )
			&& class_exists( '\\DoubleScale\\Core\\Security\\RateLimiter' )
			&& class_exists( '\\DoubleScale\\Core\\Security\\ClientIp' );
	}

	/**
	 * @return array{enabled: bool, days: int}
	 */
	public static function get(): array {
		return self::normalize( (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * @param array<string, mixed> $changes Partial settings.
	 * @return array{enabled: bool, days: int}
	 */
	public static function update( array $changes ): array {
		$settings = self::normalize( array_merge( self::get(), $changes ) );
		update_option( self::OPTION, $settings, false );

		return $settings;
	}

	/**
	 * @param array<string, mixed> $raw Stored or submitted settings.
	 * @return array{enabled: bool, days: int}
	 */
	public static function normalize( array $raw ): array {
		$days = isset( $raw['days'] ) && is_numeric( $raw['days'] ) ? (int) $raw['days'] : 30;

		return array(
			'enabled' => ! empty( $raw['enabled'] ),
			// A cleared or invalid box means the default, never "zero days".
			'days'    => $days < 1 ? 30 : max( 7, min( 90, $days ) ),
		);
	}
}
