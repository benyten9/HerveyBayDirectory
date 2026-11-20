<?php
/**
 * Service targets (SLA) per ticket priority, on the clock (every hour counts):
 *
 *   first_response  minutes to the team's first reply (at most 7 days)
 *   resolution      hours to solved or closed (at most 30 days)
 *
 * null = no target for that priority (not counted in the report). Kept in
 * the support settings under `service_targets`.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Support\Constants\TicketPriority;

/**
 * ServiceTargets class.
 */
final class ServiceTargets {

	private const KEY = 'service_targets';

	/**
	 * Kind => the largest target (minutes for first_response, hours for resolution).
	 */
	public const LIMITS = array(
		'first_response' => 10080,
		'resolution'     => 720,
	);

	/**
	 * @return array{first_response: array<string, int|null>, resolution: array<string, int|null>}
	 */
	public static function get(): array {
		$support = Settings::get( 'support', array() );
		$support = is_array( $support ) ? $support : array();

		return self::normalize( (array) ( $support[ self::KEY ] ?? array() ) );
	}

	/**
	 * Merge $changes (per kind, per priority) into the stored targets.
	 *
	 * @param array<string, mixed> $changes Partial targets.
	 * @return array{first_response: array<string, int|null>, resolution: array<string, int|null>}
	 */
	public static function update( array $changes ): array {
		$current = self::get();
		foreach ( array_keys( self::LIMITS ) as $kind ) {
			if ( isset( $changes[ $kind ] ) && is_array( $changes[ $kind ] ) ) {
				$current[ $kind ] = array_merge( $current[ $kind ], $changes[ $kind ] );
			}
		}
		$targets = self::normalize( $current );

		$support                = Settings::get( 'support', array() );
		$support                = is_array( $support ) ? $support : array();
		$support[ self::KEY ] = $targets;
		Settings::update( 'support', $support );

		return $targets;
	}

	/**
	 * Every priority of both kinds: a whole number above zero (capped), else null.
	 *
	 * @param array<string, mixed> $raw Stored or submitted targets.
	 * @return array{first_response: array<string, int|null>, resolution: array<string, int|null>}
	 */
	public static function normalize( array $raw ): array {
		$out = array();
		foreach ( self::LIMITS as $kind => $limit ) {
			$out[ $kind ] = array();
			foreach ( array( TicketPriority::LOW, TicketPriority::NORMAL, TicketPriority::HIGH, TicketPriority::URGENT ) as $priority ) {
				$value                       = $raw[ $kind ][ $priority ] ?? null;
				$number                      = is_numeric( $value ) ? (int) $value : 0;
				$out[ $kind ][ $priority ] = $number > 0 ? min( $number, $limit ) : null;
			}
		}

		return $out;
	}
}
