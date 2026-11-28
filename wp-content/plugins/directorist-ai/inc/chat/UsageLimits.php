<?php
/**
 * Optional administrator allowances, separate from provider capacity and credit settlement.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

/** Null means no administrator cap. Numeric bounds apply only to explicit overrides. */
final class UsageLimits {
	public const REVISION         = 2;
	public const MAXIMUMS         = array(
		'actor_rounds'           => 5000,
		'actor_input_tokens'     => 4000000,
		'actor_output_tokens'    => 4000000,
		'site_rounds'            => 5000,
		'site_input_tokens'      => 4000000,
		'site_output_tokens'     => 4000000,
		'profile_admin_rounds'   => 5000,
		'profile_owner_rounds'   => 5000,
		'profile_visitor_rounds' => 5000,
		'profile_builder_rounds' => 5000,
		'site_ai_tasks'          => 5000,
		'visitor_ai_tasks'       => 5000,
		'run_model_rounds'       => 5000,
		'run_repair_rounds'      => 5000,
		'run_input_tokens'       => 4000000,
		'run_output_tokens'      => 4000000,
		'request_output_tokens'  => 16384,
	);
	private const LEGACY_DEFAULTS = array(
		'actor_rounds'           => 50,
		'actor_input_tokens'     => 200000,
		'actor_output_tokens'    => 100000,
		'site_rounds'            => 500,
		'site_input_tokens'      => 800000,
		'site_output_tokens'     => 200000,
		'profile_admin_rounds'   => 500,
		'profile_owner_rounds'   => 200,
		'profile_visitor_rounds' => 200,
		'profile_builder_rounds' => 200,
		'site_ai_tasks'          => 500,
		'visitor_ai_tasks'       => 50,
	);

	/**
	 * Project inherited legacy defaults without rewriting saved customer options.
	 *
	 * @param array $saved Saved operational settings.
	 * @return array Effective settings.
	 */
	public static function normalize( array $saved ): array {
		foreach ( self::MAXIMUMS as $key => $maximum ) {
			if ( ! array_key_exists( $key, $saved ) || ( self::REVISION > (int) ( $saved['usage_limits_revision'] ?? 0 ) && isset( self::LEGACY_DEFAULTS[ $key ] ) && self::LEGACY_DEFAULTS[ $key ] === $saved[ $key ] ) ) {
				$saved[ $key ] = null;
			}
		}
		$saved['usage_limits_revision'] = self::REVISION;
		return $saved;
	}

	/**
	 * A missing policy cap never acts like zero.
	 *
	 * @param int|null $limit Optional explicit cap.
	 * @param int      $next Proposed usage.
	 * @return bool Whether an explicit cap would be exceeded.
	 */
	public static function exceeded( ?int $limit, int $next ): bool {
		return null !== $limit && $next > $limit;
	}
}
