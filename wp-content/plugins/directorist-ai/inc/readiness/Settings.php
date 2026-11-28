<?php
/** Opt-in publication policy, separate from AI tasks and accounting.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Small closed settings value object.

/** An empty directory selection publishes nothing. */
final class Settings {
	public const OPTION         = 'directorist_ai_pro_readiness';
	public const REVISION       = 'public-markdown-v3';
	public const ENABLED_PREFIX = 'directorist_ai_pro_ready_enabled_';

	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'enabled'           => true === ( $value['enabled'] ?? false ),
			'directories'       => array_values( array_unique( array_map( 'intval', (array) ( $value['directories'] ?? array() ) ) ) ),
			'excluded_fields'   => (array) ( $value['excluded_fields'] ?? array() ),
			'root_mode'         => in_array( $value['root_mode'] ?? '', array( 'auto', 'scoped_only' ), true ) ? $value['root_mode'] : 'auto',
			'discovery_title'   => is_string( $value['discovery_title'] ?? null ) ? $value['discovery_title'] : '',
			'discovery_summary' => is_string( $value['discovery_summary'] ?? null ) ? $value['discovery_summary'] : '',
		);
	}

	public static function active( int $directory = 0 ): bool {
		$settings = self::get();
		return $settings['enabled'] && '1' === (string) get_option( 'blog_public', '1' ) && ( $directory ? DirectoryReview::settings( $directory )['enabled'] : (bool) self::directory_ids( 0, 1 ) );
	}

	public static function excluded( int $directory ): array {
		return DirectoryReview::settings( $directory )['excluded_fields'];
	}

	public static function publication(): array {
		return array_intersect_key( self::get(), array_flip( array( 'enabled', 'root_mode', 'discovery_title', 'discovery_summary' ) ) );
	}

	public static function legacy_revision(): string {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) && is_string( $value['_legacy_revision'] ?? null ) ? $value['_legacy_revision'] : 'legacy-v0';
	}

	public static function policy_hash(): string {
		$value = get_option( self::OPTION, array() );
		return hash( 'sha256', serialize( array( self::REVISION, self::publication(), $value['_policy_revision'] ?? '', Publication::entry_url(), Publication::root_conflict(), get_option( 'blog_public', '1' ) ) ) );
	}

	/** Bounded merge of legacy selections and separately reviewed directory records. */
	public static function directory_ids( int $after = 0, int $limit = 50 ): array {
		global $wpdb;
		$limit  = max( 1, min( 100, $limit ) );
		$prefix = self::ENABLED_PREFIX;
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d ORDER BY CAST(SUBSTRING(option_name, %d) AS UNSIGNED) ASC LIMIT %d", $wpdb->esc_like( $prefix ) . '%', $wpdb->esc_like( '1:' . self::legacy_revision() . ':' ) . '%', strlen( $prefix ) + 1, $after, strlen( $prefix ) + 1, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only feature-owned non-autoloaded policy markers, bounded output.
		$ids    = array_map( static fn( string $name ): int => (int) substr( $name, strlen( $prefix ) ), (array) $names );
		$legacy = array_filter( self::get()['directories'], static fn( int $id ): bool => $id > $after && DirectoryReview::settings( $id )['enabled'] );
		sort( $legacy, SORT_NUMERIC );
		$ids = array_values( array_unique( array_merge( $ids, array_slice( $legacy, 0, $limit ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return array_slice( $ids, 0, $limit );
	}

	/** The new UI saves site controls without replacing the directory review set.
	 *
	 * @throws \DomainException When another administrator changes saved settings.
	 */
	public static function save_publication( array $input ): array {
		global $wpdb;
		$stored = get_option( self::OPTION, false );
		if ( array_diff( array_keys( $input ), array( 'enabled', 'root_mode', 'discovery_title', 'discovery_summary', 'expected_policy_hash' ) ) || ! is_bool( $input['enabled'] ?? null ) || ! is_string( $input['expected_policy_hash'] ?? null ) || ! hash_equals( self::policy_hash(), $input['expected_policy_hash'] ) ) {
			throw new \DomainException( 'Saved settings changed. Refresh before saving.' );
		}
		$current   = is_array( $stored ) ? $stored : array();
		$validated = self::validate_publication( $input, self::get() );
		$next      = array_merge( $current, $validated );
		if ( self::publication() !== $validated ) {
			$next['_policy_revision'] = bin2hex( random_bytes( 16 ) );
		}
		if ( $next === $current ) {
			return self::publication(); }
		if ( false === $stored ) {
			$changed = add_option( self::OPTION, $next, '', false );
		} else {
			$changed = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), self::OPTION, maybe_serialize( $stored ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic comparison of the original publication snapshot.
		}
		if ( ! $changed ) {
			throw new \DomainException( 'Saved settings changed. Refresh before saving.' ); }
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return self::publication();
	}

	public static function save( array $input ): array {
		if ( strlen( serialize( $input ) ) > 1048576 || array_diff( array_keys( $input ), array( 'enabled', 'directories', 'excluded_fields', 'root_mode', 'discovery_title', 'discovery_summary' ) ) || ! is_bool( $input['enabled'] ?? null ) || ! is_array( $input['directories'] ?? null ) || ! is_array( $input['excluded_fields'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Use valid listing access settings. Save large field-policy changes one directory at a time.' );
		}
		$directories = array();
		foreach ( $input['directories'] as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! directorist_is_directory( $id ) ) {
				throw new \InvalidArgumentException( 'An export directory is unavailable.' );
			}
			$directories[] = $id;
		}
		$exclusions = array();
		foreach ( $input['excluded_fields'] as $id => $fields ) {
			if ( ! ctype_digit( (string) $id ) || ! directorist_is_directory( (int) $id ) || ! is_array( $fields ) || count( $fields ) > 200 ) {
				throw new \InvalidArgumentException( 'Field exclusions require an existing directory.' );
			}
			foreach ( $fields as $field ) {
				if ( ! is_string( $field ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $field ) ) {
					throw new \InvalidArgumentException( 'A field exclusion is invalid.' );
				}
			}
			$exclusions[ $id ] = array_values( array_unique( $fields ) );
		}
		$value = array(
			'enabled'         => $input['enabled'],
			'directories'     => array_values( array_unique( $directories ) ),
			'excluded_fields' => $exclusions,
		);
		$value = array_merge( $value, self::validate_publication( $input, self::get() ) );
		// A legacy bulk selection is a new review directive, never an implicit field approval.
		$value['_legacy_revision'] = bin2hex( random_bytes( 16 ) );
		$value['_policy_revision'] = bin2hex( random_bytes( 16 ) );
		update_option( self::OPTION, $value, false );
		return self::get();
	}

	private static function validate_publication( array $input, array $current ): array {
		$value = array(
			'enabled'   => $input['enabled'],
			'root_mode' => array_key_exists( 'root_mode', $input ) ? $input['root_mode'] : $current['root_mode'],
		);
		if ( ! in_array( $value['root_mode'], array( 'auto', 'scoped_only' ), true ) ) {
			throw new \InvalidArgumentException( 'Choose automatic or scoped-only discovery.' );
		}
		foreach ( array(
			'discovery_title'   => 120,
			'discovery_summary' => 300,
		) as $key => $limit ) {
			$text = array_key_exists( $key, $input ) ? $input[ $key ] : $current[ $key ];
			if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) || mb_strlen( $text, 'UTF-8' ) > $limit || preg_match( '/[\x00-\x1f\x7f]/u', $text ) || trim( $text ) !== wp_strip_all_tags( trim( $text ) ) ) {
				throw new \InvalidArgumentException( 'Discovery text must be bounded plain text without markup or control characters.' );
			}
			$value[ $key ] = trim( $text );
		}
		return $value;
	}
}
