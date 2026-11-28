<?php
/** Explicit directory review and freshness, isolated from native configuration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed feature-owned review records.

/** No field values or native builder settings are persisted here. */
final class DirectoryReview {
	public const PREFIX            = 'directorist_ai_pro_ready_review_';
	public const GENERATION_PREFIX = 'directorist_ai_pro_ready_generation_';

	public static function record( int $directory ): array {
		$value = get_option( self::PREFIX . $directory, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function settings( int $directory ): array {
		$record = self::record( $directory );
		$legacy = Settings::get();
		if ( ( $record['legacy_revision'] ?? '' ) === Settings::legacy_revision() ) {
			return array(
				'enabled'         => true === ( $record['enabled'] ?? false ),
				'excluded_fields' => (array) ( $record['excluded_fields'] ?? array() ),
			);
		}
		return array(
			'enabled'         => in_array( $directory, $legacy['directories'], true ),
			'excluded_fields' => (array) ( $legacy['excluded_fields'][ $directory ] ?? array() ),
		);
	}

	/** Anonymous native definitions are hashed; their private properties never leave PHP.
	 *
	 * @throws \InvalidArgumentException When the directory is unavailable.
	 */
	public static function schema_hash( int $directory ): string {
		if ( ! directorist_is_directory( $directory ) ) {
			throw new \InvalidArgumentException( 'The directory is unavailable.' );
		}
		return NativeFacts::anonymous(
			static function () use ( $directory ): string {
				wp_cache_delete( $directory, 'term_meta' );
				$fields     = directorist_get_listing_form_fields( $directory, array( 'source' => 'directorist_ai' ) );
				$layout     = directorist_single_listings_contents( $directory );
				$header     = directorist_single_listing_header( $directory );
				$extensions = \DirectoristAIPro\Chat\Workflows\PublicListingFacts::definitions( $directory, $fields );
				return hash( 'sha256', serialize( array( Settings::REVISION, get_current_blog_id(), $directory, ATBDP_VERSION, $fields, $layout, $header, $extensions, NativeFacts::custom_page( $directory ) ) ) );
			}
		);
	}

	public static function fresh( int $directory ): bool {
		$record = self::record( $directory );
		return ( $record['legacy_revision'] ?? '' ) === Settings::legacy_revision() && is_string( $record['schema_hash'] ?? null ) && hash_equals( $record['schema_hash'], self::schema_hash( $directory ) );
	}

	public static function policy_hash( int $directory ): string {
		return hash( 'sha256', serialize( array( Settings::policy_hash(), self::settings( $directory ), self::record( $directory )['schema_hash'] ?? '', self::record( $directory )['review_version'] ?? '' ) ) );
	}

	public static function generation( int $directory ): string {
		return (string) get_option( self::GENERATION_PREFIX . $directory, '' );
	}

	public static function touch( int $directory ): void {
		if ( $directory > 0 && self::record( $directory ) ) {
			update_option( self::GENERATION_PREFIX . $directory, bin2hex( random_bytes( 16 ) ), false );
		}
	}

	public static function state( int $directory ): array {
		$record   = self::record( $directory );
		$settings = self::settings( $directory );
		$hash     = self::schema_hash( $directory );
		$reason   = ! $record ? 'directory_review_required' : ( ! $settings['enabled'] ? 'directory_disabled' : ( in_array( 'title', $settings['excluded_fields'], true ) ? 'required_title_excluded' : ( ( $record['legacy_revision'] ?? '' ) !== Settings::legacy_revision() || ! hash_equals( (string) ( $record['schema_hash'] ?? '' ), $hash ) ? 'directory_review_required' : ( ! Settings::get()['enabled'] ? 'export_disabled' : ( '1' !== (string) get_option( 'blog_public', '1' ) ? 'site_indexing_disabled' : ( get_directorist_option( 'disable_single_listing', false ) ? 'single_listing_disabled' : ( NativeFacts::custom_page( $directory ) ? 'custom_single_page_visibility_unverified' : '' ) ) ) ) ) ) );
		return array(
			'enabled'         => $settings['enabled'],
			'excluded_fields' => $settings['excluded_fields'],
			'schema_hash'     => $hash,
			'policy_hash'     => self::policy_hash( $directory ),
			'review_token'    => hash( 'sha256', serialize( array( Settings::legacy_revision(), $settings, $record['review_version'] ?? '', $record['schema_hash'] ?? '' ) ) ),
			'reviewed_at'     => $record['reviewed_at'] ?? null,
			'reason'          => $reason,
			'review_current'  => ( $record['legacy_revision'] ?? '' ) === Settings::legacy_revision() && hash_equals( (string) ( $record['schema_hash'] ?? '' ), $hash ),
			'state'           => $reason ? ( 'directory_review_required' === $reason || 'required_title_excluded' === $reason ? 'review_needed' : ( in_array( $reason, array( 'site_indexing_disabled', 'single_listing_disabled', 'custom_single_page_visibility_unverified' ), true ) ? 'blocked' : 'not_enabled' ) ) : 'check_needed',
		);
	}

	public static function save( int $directory, array $input ): array {
		$previous      = self::record( $directory );
		$policy_before = self::policy_hash( $directory );
		$legacy_before = Settings::legacy_revision();
		if ( strlen( serialize( $input ) ) > 65536 || array_diff( array_keys( $input ), array( 'enabled', 'excluded_fields', 'schema_hash', 'expected_policy_hash' ) ) || ! is_bool( $input['enabled'] ?? null ) || ! is_array( $input['excluded_fields'] ?? null ) || ! is_string( $input['schema_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['schema_hash'] ) ) {
			throw new \InvalidArgumentException( 'Review this directory’s fields before saving.' );
		}
		if ( ! hash_equals( self::schema_hash( $directory ), $input['schema_hash'] ) || isset( $input['expected_policy_hash'] ) && ( ! is_string( $input['expected_policy_hash'] ) || ! hash_equals( $policy_before, $input['expected_policy_hash'] ) ) ) {
			throw new \DomainException( 'The directory changed. Refresh its fields before saving.' );
		}
		foreach ( $input['excluded_fields'] as $field ) {
			if ( ! is_string( $field ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $field ) ) {
				throw new \InvalidArgumentException( 'A field choice is invalid.' );
			}
		}
		if ( $input['enabled'] && in_array( 'title', $input['excluded_fields'], true ) ) {
			throw new \InvalidArgumentException( 'Keep the listing title included. It identifies each public listing.' );
		}
		$record = array_merge(
			$previous,
			array(
				'version'         => 1,
				'legacy_revision' => $legacy_before,
				'review_version'  => bin2hex( random_bytes( 16 ) ),
				'enabled'         => $input['enabled'],
				'excluded_fields' => array_values( array_unique( $input['excluded_fields'] ) ),
				'schema_hash'     => $input['schema_hash'],
				'reviewed_at'     => gmdate( DATE_RFC3339 ),
				'reviewed_by'     => get_current_user_id(),
			)
		);
		if ( ! hash_equals( self::schema_hash( $directory ), $record['schema_hash'] ) || ! hash_equals( $policy_before, self::policy_hash( $directory ) ) || ! hash_equals( $legacy_before, Settings::legacy_revision() ) ) {
			throw new \DomainException( 'The directory changed during review. Refresh its fields.' );
		}
		self::replace( $directory, $previous, $record );
		self::publish_marker( $directory, $record );
		self::touch( $directory );
		return self::state( $directory );
	}

	/** The joined current-record predicate prevents an older writer from publishing its marker.
	 *
	 * @throws \DomainException When a newer review owns the marker.
	 */
	private static function publish_marker( int $directory, array $record ): void {
		global $wpdb;
		$key = Settings::ENABLED_PREFIX . $directory;
		add_option( $key, '', '', false );
		$value   = ( $record['enabled'] ? '1:' : '0:' ) . $record['legacy_revision'] . ':' . $record['review_version'];
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} AS marker INNER JOIN {$wpdb->options} AS review ON review.option_name = %s AND review.option_value = %s SET marker.option_value = %s WHERE marker.option_name = %s", self::PREFIX . $directory, maybe_serialize( $record ), $value, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One marker is fenced by its corresponding authoritative record.
		if ( 1 !== $changed ) {
			throw new \DomainException( 'Saved review changed. Refresh and try again.' ); }
		wp_cache_delete( $key, 'options' );
	}

	/** Compare-and-swap prevents a receipt from overwriting another administrator’s review.
	 *
	 * @throws \DomainException When the current record differs.
	 */
	public static function replace( int $directory, array $previous, array $next ): void {
		global $wpdb;
		$key = self::PREFIX . $directory;
		if ( ! $previous ) {
			if ( ! add_option( $key, $next, '', false ) ) {
				throw new \DomainException( 'Saved review changed. Refresh and try again.' );
			}
			return;
		}
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), $key, maybe_serialize( $previous ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic comparison of one feature-owned non-autoloaded record.
		if ( 1 !== $changed ) {
			throw new \DomainException( 'Saved review changed. Refresh and try again.' );
		}
		wp_cache_delete( $key, 'options' );
	}

	public static function cleanup( int $directory ): void {
		delete_option( self::PREFIX . $directory );
		delete_option( self::GENERATION_PREFIX . $directory );
		delete_option( Settings::ENABLED_PREFIX . $directory );
	}
}
