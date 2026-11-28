<?php
/**
 * Internal native creation adapter fenced by an approved parent manifest.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Directory\DirectorySchema;
use DirectoristAIPro\Effects\{EffectOutcome, EffectPlan, EffectTarget, NativeEffect};
use DirectoristAIPro\Validation\ContractException;

/** Never registered as a public capability, route, or model tool. */
final class RowEffect implements NativeEffect {
	public function __construct( private ImportService $service, private ImportStore $store, private Context $context, private string $job_id, private array $row, private array $claim, private string $lease_token ) {}

	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$job = $this->service->authorized( $this->context, $this->job_id, $this->row );
		$this->assert_plan( $job, $plan, $target );
		$this->store->heartbeat( $job, $this->lease_token );
		try {
			$this->assert_native_tables();
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() ); }
		try {
			$existing = $this->marked();
		} catch ( ContractException $error ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() ); }
		if ( $existing ) {
			return $this->reconcile( $plan, $target, $fence ); }
		$this->store->running_claim( $this->claim, $job );
		$this->store->heartbeat( $job, $this->lease_token );
		$staged = json_decode( $this->row['staged_json'], true, 64, JSON_THROW_ON_ERROR );
		$policy = $job['manifest']['policy'];
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->unknown( $target, 'native_transaction_unavailable' ); }
		$stamp = function ( array $meta ) use ( $plan, $target, $fence, $job ): array {
			$meta['_directorist_ai_import_identity'] = $this->row['source_identity'];
			$meta['_directorist_ai_import_intent']   = $target->effect_hash;
			$meta['_directorist_ai_import_parent']   = $job['manifest']['operation_id'];
			$meta['_directorist_ai_import_source']   = $job['source_hash'];
			$meta['_directorist_ai_operation']       = $plan->operation_id;
			$meta['_directorist_ai_effect_hash']     = $target->effect_hash;
			$meta['_directorist_ai_fence']           = $fence;
			return $meta;
		};
		list( $slash_meta, $post_filters, $end_insert ) = $this->persistence_filters( $job['manifest']['schema']['fields'], $staged['values'], $this->row['source_identity'] );
		$status = static fn( $value, $directory_id ) => (int) $directory_id === (int) $job['directory_id'] ? $policy['status'] : $value;
		add_filter( 'atbdp_listing_meta_user_submission', $stamp, PHP_INT_MAX );
		add_filter( 'atbdp_ultimate_listing_meta_user_submission', $stamp, PHP_INT_MAX );
		add_filter( 'atbdp_ultimate_listing_meta_user_submission', $slash_meta, PHP_INT_MAX );
		foreach ( $post_filters as $hook => $callback ) {
			add_filter( $hook, $callback, PHP_INT_MIN ); }
		add_filter( 'wp_insert_post_data', $end_insert, PHP_INT_MIN, 2 );
		add_filter( 'directorist_listing_create_status', $status, PHP_INT_MAX, 2 );
		$previous  = get_current_user_id();
		$id        = 0;
		$committed = false;
		try {
			wp_set_current_user( $policy['author_id'] );
			if ( DirectorySchema::hash( directorist_get_listing_form_fields( (int) $job['directory_id'] ) ) !== DirectorySchema::hash( $job['manifest']['schema']['effective'] ) ) {
				throw new ContractException( 'author_schema_changed', 'The chosen author resolves different effective field properties. A separate native contract is required.' ); }
			$result = \Directorist\AddListingForm\SubmissionController::submit( $staged['posted'], 'api' );
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array( 'diagnostic' => 'Native submission rejected the approved record.' ), array(), 'native_validation_failed' ); }
			$id     = (int) ( $result['id'] ?? 0 );
			$actual = $id ? $this->observe( $job, $id, $target->effect_hash ) : null;
			if ( ! $actual ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->unknown( $target, 'native_readback_mismatch' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return $this->unknown( $target, 'native_commit_uncertain' ); }
			$committed = true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->unknown( $target, $error instanceof ContractException ? $error->error_code() : 'native_creation_uncertain' ); } finally {
			wp_set_current_user( $previous );
			remove_filter( 'atbdp_listing_meta_user_submission', $stamp, PHP_INT_MAX );
			remove_filter( 'atbdp_ultimate_listing_meta_user_submission', $stamp, PHP_INT_MAX );
			remove_filter( 'atbdp_ultimate_listing_meta_user_submission', $slash_meta, PHP_INT_MAX );
			foreach ( $post_filters as $hook => $callback ) {
				remove_filter( $hook, $callback, PHP_INT_MIN ); }
			remove_filter( 'wp_insert_post_data', $end_insert, PHP_INT_MIN );
			remove_filter( 'directorist_listing_create_status', $status, PHP_INT_MAX );
			if ( ! $committed && $id ) {
				clean_post_cache( $id ); }
			}
			return $this->reconcile( $plan, $target, $fence );
	}

	/** Bridge raw native facts into the core persistence contract without rerunning save filters. */
	private function persistence_filters( array $fields, array $values, string $identity ): array {
		$insertion_scope = false;
		$post_fields     = array();
		foreach ( $values as $field_id => $value ) {
			$internal = $fields[ $field_id ]['props']['widget_key'] ?? '';
			$column   = array(
				'title'       => 'post_title',
				'description' => 'post_content',
				'excerpt'     => 'post_excerpt',
			)[ $internal ] ?? null;
			if ( $column ) {
				$post_fields[ $column ] = null === $value ? '' : $value; }
		}
		// There are no native actions between this final metadata boundary and wp_insert_post.
		$slash_meta   = static function ( array $meta ) use ( &$insertion_scope, $identity ): array {
			if ( ! is_string( $meta['_directorist_ai_import_identity'] ?? null ) || ! hash_equals( $identity, $meta['_directorist_ai_import_identity'] ) ) {
				return $meta; }
			$insertion_scope = true;
			return wp_slash( $meta );
		};
		$post_filters = array();
		foreach ( $post_fields as $column => $expected ) {
			$post_filters[ 'pre_' . $column ] = static function ( $value ) use ( &$insertion_scope, $expected ) {
				// Core parses defaults first, so post_type is sanitized after content/title.
				return $insertion_scope && $expected === $value ? wp_slash( $value ) : $value;
			}; }
		$end_insert = static function ( array $data, array $postarr ) use ( &$insertion_scope, $identity ): array {
			if ( is_string( $postarr['meta_input']['_directorist_ai_import_identity'] ?? null ) && hash_equals( $identity, $postarr['meta_input']['_directorist_ai_import_identity'] ) ) {
				$insertion_scope = false;
			} return $data;
		};
		return array( $slash_meta, $post_filters, $end_insert );
	}

	/** Readback only: missing markers never cause another native insertion. */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$job = $this->service->authorized( $this->context, $this->job_id, $this->row, false );
		$this->assert_plan( $job, $plan, $target );
		try {
			$id = $this->marked();
		} catch ( ContractException $error ) {
			return $this->unknown( $target, $error->error_code() ); }
		if ( ! $id ) {
			return $this->unknown( $target, 'creation_marker_missing_no_retry' ); }
		$actual = $this->observe( $job, $id, $target->effect_hash );
		if ( ! $actual ) {
			return $this->unknown( $target, 'created_listing_changed' ); }
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			DirectorySchema::hash( $actual ),
			json_decode(
				ImportStore::json(
					array_merge(
						$actual,
						array(
							'listing_id' => $id,
							'record_id'  => $this->row['public_id'],
							'reconciled' => true,
						)
					)
				)
			),
			array(
				array(
					'kind'  => 'directorist_hooks',
					'state' => 'fired_not_reversible',
				),
			)
		);
	}

	private function assert_native_tables(): void {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships );
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Native atomicity preflight, read-only.
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ), ARRAY_A );
			if ( ! is_array( $row ) || ( $row['Name'] ?? '' ) !== $table || 'innodb' !== strtolower( $row['Engine'] ?? '' ) ) {
				throw new ContractException( 'native_transaction_unavailable', 'Native listing, metadata and taxonomy tables must support InnoDB transactions.' ); }
		}
	}

	private function assert_plan( array $job, EffectPlan $plan, EffectTarget $target ): void {
		$intent = DirectorySchema::hash( array( $this->row['source_identity'], $this->row['row_hash'], $job['manifest']['policy'] ) );
		if ( null !== $plan->approval_id || 'listing.importrow' !== $plan->capability || $plan->actor_ref !== $job['actor_ref'] || 'import-row-' . substr( $this->row['source_identity'], 0, 32 ) !== $plan->operation_id || 1 !== count( $plan->targets ) || 'importrow:' . $this->row['source_identity'] !== $target->key || ! hash_equals( $target->expected_version, $job['schema_hash'] ) || ! hash_equals( $intent, $plan->proposal_hash ) || ! hash_equals( $intent, $target->effect_hash ) || ! hash_equals( $intent, $this->claim['intent_hash'] ) || (int) $this->claim['job_id'] !== (int) $job['id'] ) {
			throw new ContractException( 'approval_mismatch', 'The internal row effect is outside the parent manifest delegation.' ); }
	}

	private function marked(): int {
		$ids = get_posts(
			array(
				'post_type'        => ATBDP_POST_TYPE,
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => 2,
				'fields'           => 'ids',
				'meta_key'         => '_directorist_ai_import_identity',
				'meta_value'       => $this->row['source_identity'],
				'suppress_filters' => true,
			)
		);
		if ( count( $ids ) > 1 ) {
			throw new ContractException( 'duplicate_ambiguous', 'Multiple native listings carry this source identity. No new listing may be inserted.' ); }
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	private function observe( array $job, int $id, string $intent ): ?array {
		clean_post_cache( $id );
		$post   = get_post( $id );
		$schema = $job['manifest']['schema'];
		$policy = $job['manifest']['policy'];
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type || (int) $post->post_author !== $policy['author_id'] || $post->post_status !== $policy['status'] || (int) directorist_get_listing_directory( $id ) !== (int) $job['directory_id'] || get_post_meta( $id, '_directorist_ai_import_identity', true ) !== $this->row['source_identity'] || ! hash_equals( (string) get_post_meta( $id, '_directorist_ai_import_intent', true ), $intent ) ) {
			return null; }
		$memberships = wp_get_object_terms( $id, ATBDP_DIRECTORY_TYPE, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $memberships ) || array( (int) $job['directory_id'] ) !== array_map( 'intval', $memberships ) ) {
			return null; }
		$staged = json_decode( $this->row['staged_json'], true, 64, JSON_THROW_ON_ERROR );
		$values = array();
		foreach ( $staged['values'] as $field_id => $expected ) {
			$descriptor = $schema['fields'][ $field_id ];
			$props      = $descriptor['props'];
			switch ( $props['widget_key'] ?? $descriptor['widget'] ) {
				case 'title':
					$actual = $post->post_title;
					// Core title_save_pre encodes bare ampersands for authors without
					// unfiltered_html (including ordinary multisite administrators).
					// Accept only that exact entity normalization of the reviewed
					// text. Do not rerun save hooks or decode arbitrary changed titles.
					if ( is_string( $expected ) && wp_kses_normalize_entities( $expected ) === $actual ) {
						$expected = $actual;
					}
					break;
				case 'description':
					$actual = $post->post_content;
					break;
				case 'excerpt':
					$actual = $post->post_excerpt;
					break;
				case 'category':
				case 'location':
				case 'tag':
						$taxonomy = array(
							'category' => ATBDP_CATEGORY,
							'location' => ATBDP_LOCATION,
							'tag'      => ATBDP_TAGS,
						)[ $descriptor['widget'] ];
						$actual   = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
						if ( is_wp_error( $actual ) ) {
							return null;
						} $actual = array_map( 'intval', $actual );
					break;
				case 'image_upload':
					$actual = array_values( array_unique( array_filter( array_merge( array( (int) get_post_meta( $id, '_listing_prv_img', true ) ), (array) get_post_meta( $id, '_listing_img', true ) ) ) ) );
					$actual = array_map( 'intval', $actual );
					break;
				case 'pricing':
					$type = get_post_meta( $id, '_atbd_listing_pricing', true );
					$actual = '' === $type ? null : array( 'price_type' => $type, 'price' => 'price' === $type ? (float) get_post_meta( $id, '_price', true ) : '', 'price_range' => 'range' === $type ? (string) get_post_meta( $id, '_price_range', true ) : '' );
					break;
				case 'map':
					$hidden = (bool) get_post_meta( $id, '_hide_map', true ); $lat = (string) get_post_meta( $id, '_manual_lat', true ); $lng = (string) get_post_meta( $id, '_manual_lng', true );
					$actual = ! $hidden && '' === $lat && '' === $lng ? null : array( 'hide_map' => $hidden, 'manual_lat' => $lat, 'manual_lng' => $lng );
					break;
				default:
					$actual = get_post_meta( $id, '_' . $props['field_key'], true );
			}
			if ( in_array( $descriptor['widget'], array( 'category', 'location', 'tag', 'image_upload' ), true ) ) {
				$expected = (array) $expected;
				$actual   = (array) $actual;
				sort( $expected );
				sort( $actual ); } elseif ( 'number' === $descriptor['widget'] && null !== $expected ) {
				$actual = '' === $actual ? null : (float) $actual; } elseif ( null === $expected && '' === $actual ) {
					$actual = null; } elseif ( is_bool( $expected ) ) {
					$actual = filter_var( $actual, FILTER_VALIDATE_BOOLEAN ); }
					if ( DirectorySchema::hash( $actual ) !== DirectorySchema::hash( $expected ) ) {
						return null; }
					$values[ $field_id ] = $actual;
		}
		if ( in_array( $policy['status'], array( 'pending', 'publish' ), true ) ) {
			$duration = directorist_get_default_expiration( (int) $job['directory_id'] );
			if ( ! $duration && ! get_post_meta( $id, '_never_expire', true ) || $duration && ! strtotime( (string) get_post_meta( $id, '_expiry_date', true ) ) ) {
				return null; }
		}
		return array(
			'status'       => $post->post_status,
			'author_id'    => (int) $post->post_author,
			'directory_id' => (int) $job['directory_id'],
			'values'       => $values,
			'expiry_date'  => get_post_meta( $id, '_expiry_date', true ),
			'never_expire' => (bool) get_post_meta( $id, '_never_expire', true ),
		);
	}

	private function unknown( EffectTarget $target, string $code ): EffectOutcome {
		return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), $code ); }
}
