<?php
/**
 * Configuration for the "auto-close inactive tickets" feature.
 *
 * Single source of truth for the auto-close rules — read by the daily
 * {@see AutoCloseRunner} and surfaced to the admin settings UI through
 * {@see \DoubleScale\Pro\Modules\Support\Rest\Controllers\RestAutoCloseController}.
 * Persisted under `doublescale_settings['support']['auto_close']`, mirroring
 * how {@see \DoubleScale\Modules\Support\Services\AttachmentSettings} stores
 * `support.attachments`.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;

/**
 * AutoCloseSettings class.
 */
final class AutoCloseSettings {

	/**
	 * Settings sub-key under the `support` settings group.
	 */
	private const SETTINGS_KEY = 'auto_close';

	/**
	 * Default number of inactive days before a ticket is auto-closed.
	 */
	public const DEFAULT_INACTIVE_DAYS = 15;

	/**
	 * Hard ceiling for the configurable inactive-day window, so a typo can't
	 * push the cutoff absurdly far into the future (effectively disabling it).
	 */
	public const MAX_INACTIVE_DAYS = 3650;

	/**
	 * Where the close note is written: an internal note or a customer-visible
	 * reply.
	 */
	public const NOTE_VISIBILITY_NOTE  = 'note';
	public const NOTE_VISIBILITY_REPLY = 'reply';

	/**
	 * Default, fully-populated settings shape. Every key the runner and the UI
	 * read is present here so callers never have to null-check.
	 *
	 * @return array{
	 *     enabled:bool,
	 *     inactive_days:int,
	 *     skip_waiting_on_agent:bool,
	 *     include_tag_ids:int[],
	 *     exclude_tag_ids:int[],
	 *     silent:bool,
	 *     add_close_note:bool,
	 *     close_note:string,
	 *     close_note_visibility:string
	 * }
	 */
	public static function defaults(): array {
		return array(
			'enabled'               => false,
			'inactive_days'         => self::DEFAULT_INACTIVE_DAYS,
			'skip_waiting_on_agent' => false,
			'include_tag_ids'       => array(),
			'exclude_tag_ids'       => array(),
			'silent'                => false,
			'add_close_note'        => false,
			'close_note'            => '',
			'close_note_visibility' => self::NOTE_VISIBILITY_NOTE,
		);
	}

	/**
	 * The current auto-close settings, merged over defaults and normalized so
	 * the returned shape always matches {@see defaults()}.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$support = Settings::get( 'support', array() );
		$raw     = ( is_array( $support ) && isset( $support[ self::SETTINGS_KEY ] ) && is_array( $support[ self::SETTINGS_KEY ] ) )
			? $support[ self::SETTINGS_KEY ]
			: array();

		return self::normalize( $raw );
	}

	/**
	 * Persist the auto-close settings, sanitizing each field. Unknown keys in
	 * the input are dropped. Values round-trip to themselves on the next read.
	 *
	 * @param array<string, mixed> $input Raw settings from the REST request.
	 * @return array<string, mixed> The stored (normalized) settings.
	 */
	public static function save( array $input ): array {
		$clean = self::normalize( $input );

		$support = Settings::get( 'support', array() );
		if ( ! is_array( $support ) ) {
			$support = array();
		}
		$support[ self::SETTINGS_KEY ] = $clean;

		Settings::update( 'support', $support );

		return $clean;
	}

	/**
	 * Coerce an arbitrary input array into the canonical settings shape with
	 * every field sanitized and clamped. Used on both read and write so a value
	 * saved through {@see save()} reads back identically through {@see get()}.
	 *
	 * @param array<string, mixed> $raw Partial/raw settings.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $raw ): array {
		$defaults = self::defaults();

		$days = isset( $raw['inactive_days'] ) ? absint( $raw['inactive_days'] ) : $defaults['inactive_days'];
		if ( $days < 1 ) {
			$days = $defaults['inactive_days'];
		}
		$days = min( $days, self::MAX_INACTIVE_DAYS );

		$visibility = isset( $raw['close_note_visibility'] ) ? sanitize_key( (string) $raw['close_note_visibility'] ) : $defaults['close_note_visibility'];
		if ( ! in_array( $visibility, array( self::NOTE_VISIBILITY_NOTE, self::NOTE_VISIBILITY_REPLY ), true ) ) {
			$visibility = $defaults['close_note_visibility'];
		}

		return array(
			'enabled'               => self::to_bool( $raw['enabled'] ?? $defaults['enabled'] ),
			'inactive_days'         => $days,
			'skip_waiting_on_agent' => self::to_bool( $raw['skip_waiting_on_agent'] ?? $defaults['skip_waiting_on_agent'] ),
			'include_tag_ids'       => self::sanitize_tag_ids( $raw['include_tag_ids'] ?? array() ),
			'exclude_tag_ids'       => self::sanitize_tag_ids( $raw['exclude_tag_ids'] ?? array() ),
			'silent'                => self::to_bool( $raw['silent'] ?? $defaults['silent'] ),
			'add_close_note'        => self::to_bool( $raw['add_close_note'] ?? $defaults['add_close_note'] ),
			'close_note'            => isset( $raw['close_note'] ) ? wp_kses_post( (string) $raw['close_note'] ) : $defaults['close_note'],
			'close_note_visibility' => $visibility,
		);
	}

	/**
	 * Normalize a list of tag IDs to a deduped array of positive integers.
	 *
	 * @param mixed $value Raw tag-id list (array or scalar).
	 * @return int[]
	 */
	private static function sanitize_tag_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array_map( 'absint', $value );
		$ids = array_filter(
			$ids,
			static function ( $id ) {
				return $id > 0;
			}
		);
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Interpret a stored/posted value as a boolean, accepting the 'yes'/'no'
	 * and '1'/'0' shapes the frontend may send in addition to real booleans.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
		}
		return (bool) $value;
	}
}
