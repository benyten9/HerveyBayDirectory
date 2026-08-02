<?php
/**
 * Per-user list table UI preferences (columns, filters, pagination).
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\ListPreferences;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and retrieves list table preferences in user meta.
 *
 * @since 1.0.0
 */
final class ListPreferencesManager {

	/**
	 * User meta key for all list preferences.
	 *
	 * @var string
	 */
	const META_KEY = 'doublescale_list_preferences';

	/**
	 * Legacy contacts column visibility meta key.
	 *
	 * @var string
	 */
	const LEGACY_CONTACTS_COLUMN_META_KEY = 'doublescale_contacts_list_column_visibility';

	/**
	 * Supported list keys.
	 *
	 * @var string[]
	 */
	const ALLOWED_LIST_KEYS = array(
		'contacts',
		'lists',
		'tags',
		'automations',
		'email_sequences',
		'email_campaigns',
		'sms_campaigns',
		'forms',
		'sales_pipeline',
		'tasks',
	);

	/**
	 * Get all list preferences for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );
		$all    = is_array( $stored ) ? $stored : array();

		$contacts = isset( $all['contacts'] ) && is_array( $all['contacts'] ) ? $all['contacts'] : array();
		if ( empty( $contacts['column_visibility'] ) ) {
			$legacy = get_user_meta( $user_id, self::LEGACY_CONTACTS_COLUMN_META_KEY, true );
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$contacts['column_visibility'] = self::sanitize_column_visibility( $legacy );
				$all['contacts']               = $contacts;
			}
		}

		if ( isset( $all['contacts']['filters'] ) ) {
			unset( $all['contacts']['filters'] );
		}

		foreach ( array( 'email_campaigns', 'sms_campaigns' ) as $campaign_list_key ) {
			if ( isset( $all[ $campaign_list_key ]['campaign_filters'] ) ) {
				unset( $all[ $campaign_list_key ]['campaign_filters'] );
			}
		}

		return $all;
	}

	/**
	 * Get preferences for a single list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $list_key List identifier.
	 * @param int    $user_id  User ID. Defaults to current user.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( $list_key, $user_id = 0 ) {
		$list_key = sanitize_key( (string) $list_key );
		if ( ! self::is_allowed_list_key( $list_key ) ) {
			return array();
		}

		$all = self::get_all( $user_id );

		if ( ! isset( $all[ $list_key ] ) || ! is_array( $all[ $list_key ] ) ) {
			return array();
		}

		$prefs = $all[ $list_key ];

		if ( 'contacts' === $list_key ) {
			unset( $prefs['filters'] );
		}

		if ( in_array( $list_key, array( 'email_campaigns', 'sms_campaigns' ), true ) ) {
			unset( $prefs['campaign_filters'] );
		}

		return $prefs;
	}

	/**
	 * Merge and persist preferences for a list.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $list_key    List identifier.
	 * @param array<string, mixed> $preferences Partial preferences to merge.
	 * @param int                  $user_id     User ID. Defaults to current user.
	 *
	 * @return array<string, mixed>|false
	 */
	public static function update( $list_key, array $preferences, $user_id = 0 ) {
		$list_key = sanitize_key( (string) $list_key );
		if ( ! self::is_allowed_list_key( $list_key ) ) {
			return false;
		}

		$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$all     = self::get_all( $user_id );
		$current = isset( $all[ $list_key ] ) && is_array( $all[ $list_key ] ) ? $all[ $list_key ] : array();
		$merged  = array_merge( $current, self::sanitize_preferences( $preferences ) );

		if ( 'contacts' === $list_key ) {
			unset( $merged['filters'] );
		}

		if ( in_array( $list_key, array( 'email_campaigns', 'sms_campaigns' ), true ) ) {
			unset( $merged['campaign_filters'] );
		}

		$all[ $list_key ] = $merged;
		update_user_meta( $user_id, self::META_KEY, $all );

		if ( 'contacts' === $list_key && isset( $merged['column_visibility'] ) ) {
			update_user_meta( $user_id, self::LEGACY_CONTACTS_COLUMN_META_KEY, $merged['column_visibility'] );
		}

		return $merged;
	}

	/**
	 * Whether a list key is supported.
	 *
	 * @since 1.0.0
	 *
	 * @param string $list_key List identifier.
	 *
	 * @return bool
	 */
	public static function is_allowed_list_key( $list_key ) {
		return in_array( $list_key, self::ALLOWED_LIST_KEYS, true );
	}

	/**
	 * Sanitize a preferences payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $preferences Raw preferences.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize_preferences( array $preferences ) {
		$sanitized = array();

		if ( array_key_exists( 'per_page', $preferences ) ) {
			$sanitized['per_page'] = max( 1, min( 100, (int) $preferences['per_page'] ) );
		}

		if ( array_key_exists( 'column_visibility', $preferences ) ) {
			$sanitized['column_visibility'] = self::sanitize_column_visibility( $preferences['column_visibility'] );
		}

		if ( array_key_exists( 'show_filters', $preferences ) ) {
			$sanitized['show_filters'] = (bool) $preferences['show_filters'];
		}

		if ( array_key_exists( 'keyword', $preferences ) ) {
			$sanitized['keyword'] = sanitize_text_field( (string) $preferences['keyword'] );
		}

		if ( array_key_exists( 'date_range', $preferences ) ) {
			$sanitized['date_range'] = self::sanitize_date_range( $preferences['date_range'] );
		}

		if ( array_key_exists( 'view_mode', $preferences ) ) {
			$view_mode = sanitize_key( (string) $preferences['view_mode'] );
			if ( in_array( $view_mode, array( 'kanban', 'table', 'list' ), true ) ) {
				$sanitized['view_mode'] = $view_mode;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize column visibility map.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $visibility Raw visibility map.
	 *
	 * @return array<string, bool>
	 */
	public static function sanitize_column_visibility( $visibility ) {
		if ( ! is_array( $visibility ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $visibility as $column => $visible ) {
			$key = sanitize_key( (string) $column );
			if ( '' === $key ) {
				continue;
			}
			$sanitized[ $key ] = (bool) $visible;
		}

		return $sanitized;
	}

	/**
	 * Sanitize a date range payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $date_range Raw date range.
	 *
	 * @return array{from: string|null, to: string|null}
	 */
	private static function sanitize_date_range( $date_range ) {
		if ( ! is_array( $date_range ) ) {
			return array(
				'from' => null,
				'to'   => null,
			);
		}

		return array(
			'from' => self::sanitize_iso_date( $date_range['from'] ?? null ),
			'to'   => self::sanitize_iso_date( $date_range['to'] ?? null ),
		);
	}

	/**
	 * Sanitize an ISO date string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw date value.
	 *
	 * @return string|null
	 */
	private static function sanitize_iso_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'c', $timestamp );
	}
}
