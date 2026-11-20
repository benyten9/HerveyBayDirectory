<?php
/**
 * Reading values out of an incoming webhook payload.
 *
 * Payloads from other apps are often nested ({"data":{"lead":{"email":…}}}),
 * so mappings, merge tags and conditions address a value by its dot path:
 * `data.lead.email`, `answers.0.value`. The workflow run keeps the whole
 * payload in its data under `webhook`.
 *
 * @package DoubleScale\Modules\Automations\Services
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Dot-path access to webhook payloads.
 */
final class WebhookPayload {

	/** Key under which a workflow run stores the payload. */
	const DATA_KEY = 'webhook';

	/** Deepest level read when listing a payload's fields. */
	const MAX_DEPTH = 6;

	/** Most fields listed for one payload. */
	const MAX_FIELDS = 300;

	/**
	 * The value at a dot path, or null when the path does not exist.
	 *
	 * A key that itself contains a dot (`{"user.email": …}`) is found too:
	 * the exact key is tried before the path is split.
	 *
	 * @param mixed  $payload Payload (array, or anything else for "no value").
	 * @param string $path    Dot path, e.g. `data.lead.email`.
	 * @return mixed
	 */
	public static function get( $payload, string $path ) {
		$path = trim( $path );
		if ( '' === $path || ! is_array( $payload ) ) {
			return null;
		}
		if ( array_key_exists( $path, $payload ) ) {
			return $payload[ $path ];
		}
		$current = $payload;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * A payload value as text for merge tags, notes and conditions.
	 *
	 * Lists of plain values are joined with commas; anything else that is
	 * not plain text is written as JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function to_text( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$plain = ! empty( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 );
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) && null !== $item ) {
					$plain = false;
					break;
				}
			}
			if ( $plain ) {
				return implode( ', ', array_map( 'strval', $value ) );
			}
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	/**
	 * Every leaf of a payload as path => value (for the editor's field list).
	 *
	 * Lists of plain values stay one field (`tags` → "a, b"); objects and
	 * lists of objects are walked into (`items.0.name`).
	 *
	 * @param mixed  $payload Payload.
	 * @param string $prefix  Path so far.
	 * @param int    $depth   Current depth.
	 * @param array  $out     Collected fields.
	 * @return array<string, mixed>
	 */
	public static function flatten( $payload, string $prefix = '', int $depth = 0, array &$out = array() ): array {
		if ( ! is_array( $payload ) ) {
			return $out;
		}
		foreach ( $payload as $key => $value ) {
			if ( count( $out ) >= self::MAX_FIELDS ) {
				break;
			}
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$walk = is_array( $value ) && ! empty( $value ) && $depth < self::MAX_DEPTH && ! self::is_plain_list( $value );
			if ( $walk ) {
				self::flatten( $value, $path, $depth + 1, $out );
			} else {
				$out[ $path ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Whether an array is a list of plain values (strings, numbers, booleans).
	 *
	 * @param array $value Value.
	 */
	private static function is_plain_list( array $value ): bool {
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) && null !== $item ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The payload a workflow run started with.
	 *
	 * @param mixed $run Automation contact (workflow run) or anything with `data`.
	 * @return array
	 */
	public static function for_run( $run ): array {
		if ( ! is_object( $run ) ) {
			return array();
		}
		$data = null;
		if ( method_exists( $run, 'get_data' ) ) {
			$data = $run->get_data( self::DATA_KEY, null );
		} elseif ( isset( $run->data ) && is_array( $run->data ) ) {
			$data = $run->data[ self::DATA_KEY ] ?? null;
		}
		return is_array( $data ) ? $data : array();
	}
}
