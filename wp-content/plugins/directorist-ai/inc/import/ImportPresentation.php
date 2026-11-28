<?php
/** Closed administrator import labels without native or provider error prose.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment -- Bounded presentation helpers for owned import results.

/** Safe labels are derived from closed receipt codes, never arbitrary diagnostics. */
final class ImportPresentation {
	/** Same reviewed membership and settled-result meaning for native views and exports. */
	public static function result_state( array $job, array $row ): string {
		$approved = $job['manifest']['approved_manifest'] ?? null;
		$included = is_array( $approved ) ? isset( $approved['record_hashes'][ (string) $row['ordinal'] ] ) : null;
		return false === $included ? 'excluded' : ( in_array( $job['state'], array( 'cancelled', 'succeeded', 'partial', 'failed' ), true ) && in_array( $row['state'], array( 'valid', 'staged' ), true ) ? 'not_imported' : $row['state'] );
	}

	/** Human-facing result alongside the unchanged raw record state in private CSV. */
	public static function result_label( string $state ): string {
		return array(
			'succeeded'    => 'Imported',
			'excluded'     => 'Excluded',
			'not_imported' => 'Not imported',
			'skipped'      => 'Already imported',
			'valid'        => 'Waiting to import',
			'staged'       => 'Not validated',
			'invalid'      => 'Needs attention',
			'queued'       => 'Queued',
			'running'      => 'Importing',
			'unknown'      => 'Needs reconciliation',
			'failed'       => 'Failed',
		)[ $state ] ?? 'Needs attention';
	}

	public static function source_name( array $source ): string {
		$origin = $source['origin'] ?? array();
		if ( 'upload' === ( $origin['kind'] ?? '' ) && is_string( $origin['name'] ?? null ) ) {
			return mb_substr( sanitize_text_field( $origin['name'] ), 0, 200 );
		}
		if ( 'url' === ( $origin['kind'] ?? '' ) && is_string( $origin['url'] ?? null ) ) {
			$parts = wp_parse_url( $origin['url'] );
			return mb_substr( sanitize_text_field( ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '/' ) ), 0, 200 );
		}
		return strtoupper( (string) ( $source['format'] ?? 'source' ) ) . ' source';
	}

	public static function diagnostic( ?array $receipt, string $state ): array {
		$messages = array(
			'native_validation_failed'         => 'Directorist rejected this record during native submission. Review its validation errors before creating a new import.',
			'author_schema_changed'            => 'The selected author resolves different native fields. Review that author’s directory settings before creating a new import.',
			'duplicate_ambiguous'              => 'Existing listings have an ambiguous source identity. Review them before another attempt.',
			'native_transaction_unavailable'   => 'The site could not provide the required native storage transaction. Ask the administrator to check the listing tables.',
			'native_readback_mismatch'         => 'The saved listing could not be confirmed against the approved record. Check the existing result before another attempt.',
			'native_commit_uncertain'          => 'The listing save is unconfirmed. Check the existing result before another attempt.',
			'native_creation_uncertain'        => 'The listing creation outcome is unconfirmed. Check the existing result before another attempt.',
			'creation_marker_missing_no_retry' => 'The prior creation attempt could not be confirmed. It will not be silently repeated.',
			'created_listing_changed'          => 'The created listing changed after import. Review the existing listing before another attempt.',
			'unknown_outcome'                  => 'The prior attempt has an unconfirmed outcome. Check its existing result before another attempt.',
			'schema_changed'                   => 'The directory changed after review. Prepare a fresh source and review before importing remaining records.',
			'unauthorized'                     => 'Current account permissions no longer allow this record. Ask the administrator to review access.',
			'import_expired'                   => 'This import expired. Already created listings remain in WordPress. Use a new reviewed import for remaining records.',
			'emergency_stop'                   => 'Importing is paused by the site administrator.',
			'native_validation_changed'        => 'Native validation changed after approval. Prepare a fresh review for remaining records.',
			'import_paused'                    => 'This import is paused. Review its saved status before taking another action.',
			'approval_mismatch'                => 'This record no longer matches its exact approved review. Prepare a fresh review.',
		);
		$codes    = array( $receipt['code'] ?? null );
		foreach ( $receipt['targets'] ?? array() as $target ) {
			$codes[] = $target['diagnostic'] ?? null;
		}
		foreach ( $codes as $code ) {
			if ( is_string( $code ) && isset( $messages[ $code ] ) ) {
				return array(
					'diagnostic_code'    => $code,
					'diagnostic_message' => $messages[ $code ],
				);
			}
		}
		$code = in_array( $state, array( 'failed', 'unknown' ), true ) ? $state : null;
		return array(
			'diagnostic_code'    => $code,
			'diagnostic_message' => 'unknown' === $code ? 'This record has an unconfirmed outcome. Check its existing result before another attempt.' : ( 'failed' === $code ? 'This record could not be imported. Review its saved validation errors and outcome.' : null ),
		);
	}

	/** Resolve readable current taxonomy labels without replacing approved native IDs. */
	public static function display_values( ?array $values, array $schema ): array {
		$display    = array();
		$taxonomies = array(
			'category' => ATBDP_CATEGORY,
			'location' => ATBDP_LOCATION,
			'tag'      => ATBDP_TAGS,
		);
		foreach ( $values ?? array() as $field_id => $value ) {
			$taxonomy = $taxonomies[ $schema['fields'][ $field_id ]['widget'] ?? '' ] ?? null;
			if ( ! $taxonomy ) {
				continue;
			}
			$names = array();
			foreach ( is_array( $value ) ? $value : array( $value ) as $term_id ) {
				if ( ! ( is_int( $term_id ) || ( is_string( $term_id ) && ctype_digit( $term_id ) ) ) || (int) $term_id < 1 ) {
					continue 2;
				}
				$term = get_term( (int) $term_id, $taxonomy );
				if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy || ! in_array( (int) $schema['directory_id'], directorist_get_term_directory( $term->term_id ), true ) ) {
					continue 2;
				}
				$names[] = mb_substr( sanitize_text_field( html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 0, 200 );
			}
			$display[ $field_id ] = is_array( $value ) ? $names : $names[0];
		}
		return $display;
	}

	public static function public_url( int $id, int $directory ): ?string {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_publicly_viewable( $post ) || get_directorist_option( 'disable_single_listing', false ) || (int) directorist_get_listing_directory( $id ) !== $directory || in_array( get_post_meta( $id, '_listing_status', true ), array( 'expired', 'deleted' ), true ) ) {
			return null;
		}
		$expiry = get_post_meta( $id, '_expiry_date', true );
		if ( ! metadata_exists( 'post', $id, '_never_expire' ) && '' !== $expiry && ( ! is_string( $expiry ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expiry ) || $expiry <= current_time( 'mysql' ) ) ) {
			return null;
		}
		$url = get_permalink( $id );
		return is_string( $url ) && preg_match( '~^https?://~', $url ) ? $url : null;
	}
}
