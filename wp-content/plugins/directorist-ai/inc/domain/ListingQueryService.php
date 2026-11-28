<?php
/**
 * Bounded logical listing query compiler and executor.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Query validation is kept beside execution.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Compiles only catalog fields into a bounded native candidate query and typed evaluator. */
final class ListingQueryService {
	private DirectoristInspector $inspector;
	private ListingQueryCatalog $catalog;
	private ListingQueryCursor $cursor;
	private int $candidate_limit;
	private ?\Closure $candidate_observer;
	private int $query_depth = 0;
	private bool $prefilter_disabled = false;
	private ?\Closure $active_observer = null;
	private array $source_checks = array();
	private int $observation_work = 0;
	private static array $active_queries = array();
	private ?\DirectoristAIPro\Storage\Database $cancellation_db = null;
	/** Native partition of the retained request-local cancellation reader. */
	private ?array $cancellation_scope = null;
	private ?\DirectoristAIPro\Storage\Database $runtime_db = null;

	/** Only an active, source-pinned query may authorize its own Inspector's term batch. */
	public static function authorizes_native_term_batch( DirectoristInspector $inspector ): bool {
		foreach ( self::$active_queries as $service ) {
			if ( $service->inspector === $inspector && 1 === $service->query_depth && ! $service->prefilter_disabled && null !== $service->active_observer && $service->native_profile_supported( true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Bind trusted domain services and the maximum native hydration batch. */
	public function __construct( DirectoristInspector $inspector, ListingQueryCatalog $catalog, ListingQueryCursor $cursor, int $candidate_limit = 1000, ?\Closure $candidate_observer = null, ?\DirectoristAIPro\Storage\Database $runtime_db = null ) {
		if ( $candidate_limit < 1 || $candidate_limit > 1000 ) {
			throw new ContractException( 'unsupported_environment', 'Listing query candidate limit is invalid.' );
		}
		$this->inspector       = $inspector;
		$this->catalog         = $catalog;
		$this->cursor          = $cursor;
		$this->candidate_limit = $candidate_limit;
		$this->candidate_observer = $candidate_observer;
		$this->runtime_db = $runtime_db;
	}

	/** Execute one strict logical plan without accepting SQL, meta keys or taxonomy names. */
	public function search( Context $context, object $input ): object {
		++$this->query_depth;
		if ( self::$active_queries ) {
			foreach ( self::$active_queries as $active ) {
				$active->prefilter_disabled = true; }
			$this->prefilter_disabled = true;
		}
		self::$active_queries[ spl_object_id( $this ) ] = $this;
		$unsupported = false;
		$observer = function ( $hook ) use ( &$unsupported ): void {
			if ( ++$this->observation_work > 200000 ) {
				$unsupported = true;
				return; }
			if ( ! is_string( $hook ) || strlen( $hook ) > 2048 ) {
				$unsupported = true;
				return; }
			if ( ! is_array( $GLOBALS['wp_filter'] ?? null ) ) {
				$unsupported = true;
				return; }
			$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;
			if ( null === $registered ) {
				return; }
			if ( ! $this->canonical_hook( $registered ) ) {
				$unsupported = true;
				return; }
			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ++$this->observation_work > 200000 ) {
						$unsupported = true;
						return; }
					if ( ! $this->supported_callback( $hook, $priority, $callback ) ) {
						$unsupported = true;
					}
				}
			}
		};
		$observe = false;
		try {
			$observe = 1 === $this->query_depth && ! $this->prefilter_disabled && $this->native_profile_supported();
			if ( $observe ) {
				$this->active_observer = $observer;
				add_action( 'all', $observer, PHP_INT_MIN, 1 );
			}
			return $this->search_current( $context, $input, $observe, $unsupported );
		} finally {
			if ( $observe ) {
				remove_action( 'all', $observer, PHP_INT_MIN );
				$this->active_observer = null;
			}
			--$this->query_depth;
			if ( 0 === $this->query_depth ) {
				unset( self::$active_queries[ spl_object_id( $this ) ] );
				$this->prefilter_disabled = false;
				$this->source_checks = array();
				$this->observation_work = 0;
			}
		}
	}

	/** Keep the original typed evaluator and page/cursor construction behind a private source fence. */
	private function search_current( Context $context, object $input, bool $observe, bool &$unsupported ): object {
		$snapshot = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		$current = $snapshot['catalog'];
		if ( ! hash_equals( $current->revision, $input->catalog_revision ) ) {
			throw new ContractException( 'stale_catalog', 'Listing query catalog has changed.' );
		}
		$fields = array();
		foreach ( $current->fields as $field ) {
			$fields[ $field->field_id ] = $field;
		}
		$metrics = array( 'predicates' => 0, 'depth' => 0 );
		$this->validate_predicate( $input->where, $fields, 0, true, $metrics );
		// Trusted test seam for the metadata anchor/cache observation boundary only.
		if ( null !== $this->candidate_observer && $snapshot['sources_supported'] && null !== $this->postmeta_plan( $input->where, $snapshot ) ) {
			( $this->candidate_observer )( 'catalogued' );
		}
		$near = $this->near_origin( $input->where );
		$order = $this->validate_order( $input->order, $fields );
		foreach ( $order as $item ) {
			if ( 'distance' === $item->field && null === $near ) {
				throw new ContractException( 'invalid_query', 'Distance ordering requires one explicit near predicate.' ); }
		}
		$plan  = (object) array(
			'directory_id'    => $input->directory_id,
			'dataset'         => $input->dataset,
			'catalog_revision' => $input->catalog_revision,
			'where'           => $input->where,
			'order'           => $order,
			'limit'           => $input->limit,
			'include_count'   => $input->include_count,
		);
		$query_hash = hash( 'sha256', wp_json_encode( $this->canonicalize( $plan ), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		$scope_hash = hash( 'sha256', $context->actor()->owner()->reference() . '|' . $context->profile() );
		$after      = null;
		if ( null !== $input->cursor ) {
			$payload = $this->cursor->decode( $input->cursor );
			if ( ! hash_equals( $scope_hash, $payload->scope ) || get_current_blog_id() !== $payload->site || $input->directory_id !== $payload->directory || ! hash_equals( $current->revision, $payload->catalog ) || ! hash_equals( $query_hash, $payload->query ) ) {
				throw new ContractException( 'invalid_cursor', 'Listing query cursor does not match this request.' );
			}
			$after = $this->validate_position( $payload->position, $order );
		}

		$profile_ready = $observe && ! $unsupported && ! $this->prefilter_disabled && $snapshot['sources_supported'] && $this->native_profile_supported( true );
		$condition = $profile_ready ? $this->taxonomy_condition( $input->where, $snapshot['taxonomy_sources'] ) : null;
		$lexical = null;
		if ( $profile_ready && null === $condition ) {
			$lexical = $this->lexical_plan( $input->where, $snapshot );
		}
		$title = $profile_ready && null === $condition && null === $lexical ? $this->title_plan( $input->where, $snapshot ) : null;
		$meta = $profile_ready && null === $condition && null === $lexical && null === $title ? $this->postmeta_plan( $input->where, $snapshot ) : null;
		$anchors = null;
		if ( null !== $condition || null !== $lexical || null !== $title || null !== $meta ) {
			$anchor_condition = null !== $condition ? array( 'relation' => 'AND', $condition, $this->directory_anchor_condition( $input->directory_id ) ) : $this->directory_anchor_condition( $input->directory_id );
			if ( null !== $lexical && null !== $lexical['terms'] ) {
				$anchor_condition = array( 'relation' => 'AND', $lexical['terms'], $this->directory_anchor_condition( $input->directory_id ) );
			}
			$anchors = $this->taxonomy_anchors( $anchor_condition );
			if ( null === $anchors || ! $this->directory_transform_matches( $input->directory_id, $anchors['terms'] ) || $unsupported || $this->prefilter_disabled ) {
				$condition = null;
				$lexical = null;
				$title = null;
				$meta = null;
			} else {
				if ( null !== $condition ) {
					$condition = $this->resolve_taxonomy_condition( $condition, $anchors['terms'] );
				}
				if ( null !== $lexical && null !== $lexical['terms'] ) {
					$lexical['terms'] = $this->resolve_taxonomy_condition( $lexical['terms'], $anchors['terms'] );
				}
			}
		}
		$lexical_profile = null !== $lexical ? $this->lexical_source_profile( (bool) $lexical['title_needles'] ) : null;
		$lexical_meta_source = null !== $lexical && $lexical['meta_plans'] ? $this->postmeta_source_profile() : null;
		$title_profile = null !== $title ? $this->title_source_profile() : null;
		$meta_source = null !== $meta ? $this->postmeta_source_profile() : null;
		if ( null !== $lexical && ( null === $lexical_profile || ( $lexical['meta_plans'] && null === $lexical_meta_source ) ) ) {
			$lexical = null;
		}
		if ( null !== $title && null === $title_profile ) {
			$title = null;
		}
		if ( null !== $meta && null === $meta_source ) {
			$meta = null;
		}
		$profile = null !== $condition || null !== $lexical || null !== $title || null !== $meta ? $this->profile_identity() : null;
		if ( null === $profile ) {
			$condition = null;
			$lexical = null;
			$title = null;
			$meta = null;
		}
		$lexical_ids = null;
		$lexical_identity = null;
		$lexical_meta_identity = null;
		if ( null !== $lexical ) {
			$lexical_ids = $this->lexical_candidate_ids( $context, $input, $lexical, $anchors, $lexical_meta_identity );
			if ( null === $lexical_ids || ! $lexical_ids || $unsupported || $this->prefilter_disabled ) {
				$lexical = null;
				$lexical_ids = null;
			} else {
				$lexical_identity = $this->lexical_content_identity( $lexical_ids, (bool) $lexical['title_needles'] );
			}
		}
		$title_ids = null;
		$title_identity = null;
		if ( null !== $title ) {
			$title_ids = $this->title_candidate_ids( $title );
			if ( null === $title_ids || ! $title_ids || $unsupported || $this->prefilter_disabled ) {
				$title = null;
				$title_ids = null;
			} else {
				$title_identity = $this->native_text_identity( $title_ids, 'post_title' );
			}
		}
		$meta_observation = null;
		if ( null !== $meta ) {
			$directory_terms = $this->postmeta_directory_terms( $anchors, $input->directory_id );
			$meta_observation = null === $directory_terms ? null : $this->postmeta_observation( $meta, $directory_terms );
			if ( null === $meta_observation || ! $meta_observation['ids'] || count( $meta_observation['ids'] ) > $this->candidate_limit || $unsupported || $this->prefilter_disabled ) {
				$meta = null;
				$meta_observation = null;
			}
		}
		$text_ids = null !== $lexical ? $lexical_ids : ( null !== $title ? $title_ids : ( null !== $meta ? $meta_observation['ids'] : null ) );
		$candidate_ids = null !== $text_ids ? $this->candidate_ids( $context, $input->directory_id, $input->dataset, null, $text_ids ) : $this->candidate_ids( $context, $input->directory_id, $input->dataset, $condition );
		if ( count( $candidate_ids ) > $this->candidate_limit ) {
			// A directory may contain more candidates than one hydration batch.
			// Drop optional prefilters and evaluate the complete native dataset in
			// bounded batches, retaining only the requested page and exact count.
			return $this->search_batches( $context, $input, $snapshot, $fields, $metrics, $order, $near, $after, $query_hash, $scope_hash );
		}
		// The native batch reads term facts only. The original typed evaluator still
		// decides every result, and unsupported callback/source profiles stay legacy.
		$batch_terms = $observe && ! $unsupported && ! $this->prefilter_disabled && $snapshot['sources_supported'] && $this->native_profile_supported( true );
		$batch_profile = $batch_terms ? $this->profile_identity() : null;
		$batch_terms = $batch_terms && null !== $batch_profile;
		if ( ( null !== $condition || null !== $lexical || null !== $title || null !== $meta ) && null !== $this->candidate_observer ) {
			( $this->candidate_observer )( 'selected' );
		}
		$this->assert_batch_not_cancelled( $context );
		$facts   = $this->inspector->query_facts( $context, $input->directory_id, $input->dataset, $candidate_ids, $batch_terms );
		if ( null !== $condition || null !== $lexical || null !== $title || null !== $meta ) {
			if ( null !== $this->candidate_observer ) {
				( $this->candidate_observer )( 'hydrated' ); }
			if ( null !== $lexical ) {
				$this->revalidate_lexical_prefilter( $context, $input, $snapshot, $facts, $lexical, $anchors, $profile, $lexical_profile, $lexical_meta_source, $lexical_ids, $lexical_identity, $lexical_meta_identity, $candidate_ids, $unsupported );
			} elseif ( null !== $title ) {
				$this->revalidate_title_prefilter( $context, $input, $snapshot, $facts, $title, $anchors, $profile, $title_profile, $title_ids, $title_identity, $candidate_ids, $unsupported );
			} elseif ( null !== $meta ) {
				$this->revalidate_postmeta_prefilter( $context, $input, $snapshot, $facts, $meta, $anchors, $profile, $meta_source, $meta_observation, $candidate_ids, $unsupported );
			} else {
				$this->revalidate_prefilter( $context, $input, $snapshot, $facts, $condition, $anchors, $profile, $candidate_ids, $unsupported );
			}
			if ( $batch_terms && ( $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $batch_profile !== $this->profile_identity() ) ) {
				throw new ContractException( 'stale_version', 'Listing query native term observation has changed.' );
			}
		} elseif ( $batch_terms ) {
			$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
			if ( ! $fresh['sources_supported'] || ! hash_equals( $snapshot['source_fingerprint'], $fresh['source_fingerprint'] ) || ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $snapshot['catalog']->schema_hash, $facts->schema_hash ) ) {
				throw new ContractException( 'stale_catalog', 'Listing query source mapping has changed.' );
			}
			$final_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset );
			if ( $candidate_ids !== $final_ids || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $batch_profile !== $this->profile_identity() ) {
				throw new ContractException( 'stale_version', 'Listing query native term observation has changed.' );
			}
		}
		$this->distance_facts( $facts->listings, $near );
		$matches = array_values(
			array_filter(
				$facts->listings,
				fn( object $listing ): bool => $this->matches( $input->where, $listing, $fields )
			)
		);
		usort( $matches, fn( object $left, object $right ): int => $this->compare( $left, $right, $order ) );
		$count = count( $matches );
		if ( null !== $after ) {
			$matches = array_values( array_filter( $matches, fn( object $listing ): bool => $this->compare_position( $this->position( $listing, $order ), $after, $order ) > 0 ) );
		}
		$page = array_slice( $matches, 0, $input->limit + 1 );
		$more = count( $page ) > $input->limit;
		if ( $more ) {
			array_pop( $page );
		}
		$next = null;
		if ( $more && $page ) {
			$next = $this->cursor->encode(
				array(
					'v'         => 1,
					'scope'     => $scope_hash,
					'site'      => get_current_blog_id(),
					'directory' => $input->directory_id,
					'catalog'   => $current->revision,
					'query'     => $query_hash,
					'position'  => $this->position( $page[ count( $page ) - 1 ], $order ),
					'expires'   => time() + 900,
				)
			);
		}
		$this->assert_batch_not_cancelled( $context );
		return (object) array(
			'contract'         => 'directorist-ai/search-v1',
			'directory_id'     => $input->directory_id,
			'dataset'          => $input->dataset,
			'catalog_revision' => $current->revision,
			'query_hash'       => $query_hash,
			'items'            => $page,
			'next_cursor'      => $next,
			'count'            => $input->include_count ? $count : null,
			'diagnostics'      => (object) array(
				'candidate_count' => count( $candidate_ids ),
				'predicate_count' => $metrics['predicates'],
				'boolean_depth'   => $metrics['depth'],
				'candidate_limit' => $this->candidate_limit,
			),
			'limitations'      => $facts->limitations,
		);
	}

	/**
	 * Evaluate an arbitrarily sized directory with bounded live facts and top-page
	 * memory. A second complete pass verifies native membership and every released
	 * version before returning counts or results. The existing native hydrator
	 * still owns visibility, actor permission, schema and per-batch source fences.
	 */
	private function search_batches( Context $context, object $input, array $snapshot, array $fields, array $metrics, array $order, ?object $near, ?array $after, string $query_hash, string $scope_hash ): object {
		$this->prefilter_disabled = true;
		$first = $this->scan_batches( $context, $input, $snapshot['catalog']->schema_hash, $fields, $order, $near, $after, true );
		if ( null !== $this->candidate_observer ) { ( $this->candidate_observer )( 'scan_selected' ); }
		$verified = $this->scan_batches( $context, $input, $snapshot['catalog']->schema_hash, $fields, $order, $near, $after, false );
		$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		if ( ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $first['digest'], $verified['digest'] ) || $first['scanned'] !== $verified['scanned'] ) {
			throw new ContractException( 'stale_version', 'Listing query changed during complete native traversal.' );
		}
		$page = $first['page'];
		$more = count( $page ) > $input->limit;
		if ( $more ) { array_pop( $page ); }
		$released = array();
		foreach ( array_chunk( $page, min( 200, $this->candidate_limit ) ) as $selected ) {
			$this->assert_batch_not_cancelled( $context );
			$full = $this->inspector->query_facts( $context, $input->directory_id, $input->dataset, array_column( $selected, 'id' ) );
			$this->distance_facts( $full->listings, $near );
			if ( ! hash_equals( $snapshot['catalog']->schema_hash, $full->schema_hash ) || array_column( $selected, 'id' ) !== array_column( $full->listings, 'id' ) ) { throw new ContractException( 'stale_version', 'Listing query page changed before release.' ); }
			foreach ( $full->listings as $index => $listing ) {
				foreach ( $selected[ $index ]->fields as $fact ) {
					$current = $this->fact( $listing, $fact->field_id );
					if ( null === $current || $fact->known !== $current->known || wp_json_encode( $fact->value ) !== wp_json_encode( $current->value ) ) { throw new ContractException( 'stale_version', 'Listing query page facts changed before release.' ); }
				}
				$released[] = $listing;
			}
			$first['limitations'] = array_values( array_unique( array_merge( $first['limitations'], $full->limitations ) ) );
		}
		$page = $released;
		$this->assert_batch_not_cancelled( $context );
		$next = null;
		if ( $more && $page ) {
			$next = $this->cursor->encode( array(
				'v' => 1, 'scope' => $scope_hash, 'site' => get_current_blog_id(),
				'directory' => $input->directory_id, 'catalog' => $snapshot['catalog']->revision,
				'query' => $query_hash, 'position' => $this->position( $page[ count( $page ) - 1 ], $order ), 'expires' => time() + 900,
			) );
		}
		return (object) array(
			'contract' => 'directorist-ai/search-v1', 'directory_id' => $input->directory_id,
			'dataset' => $input->dataset, 'catalog_revision' => $snapshot['catalog']->revision,
			'query_hash' => $query_hash, 'items' => $page, 'next_cursor' => $next,
			'count' => $input->include_count ? $first['count'] : null,
			'diagnostics' => (object) array(
				'candidate_count' => $first['scanned'], 'predicate_count' => $metrics['predicates'],
				'boolean_depth' => $metrics['depth'], 'candidate_limit' => $this->candidate_limit,
				'strategy' => 'native_batches', 'batch_count' => $first['batches'],
			),
			'limitations' => array_values( array_unique( array_merge( $snapshot['catalog']->limitations, $first['limitations'] ) ) ),
		);
	}

	/** One bounded native traversal; the verification pass stores no result page. */
	private function scan_batches( Context $context, object $input, string $schema_hash, array $fields, array $order, ?object $near, ?array $after, bool $select ): array {
		// Page only native IDs at the existing candidate bound. Metadata-heavy
		// hydration and its cancellation/source checks retain their smaller chunks.
		$hydration_size = min( 200, $this->candidate_limit );
		$size = $hydration_size * intdiv( $this->candidate_limit, $hydration_size );
		$projected = array_values( array_unique( array_merge( $this->predicate_fields( $input->where ), array_column( $order, 'field' ), null === $near ? array() : array( 'map', 'distance' ) ) ) );
		$offset = 0; $last_id = 0; $count = 0; $batches = 0; $page = array(); $limitations = array();
		$digest = hash_init( 'sha256' );
		do {
			$ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, null, null, $offset, $size );
			if ( count( $ids ) > $size ) { throw new ContractException( 'unsupported_environment', 'Native listing pagination did not honor its batch size.' ); }
			foreach ( $ids as $id ) {
				if ( $id <= $last_id ) { throw new ContractException( 'stale_version', 'Native listing pagination is not stable.' ); }
				$last_id = $id;
			}
			if ( ! $ids ) { break; }
			foreach ( array_chunk( $ids, $hydration_size ) as $selected_ids ) {
				$this->assert_batch_not_cancelled( $context );
				++$batches;
				$facts = $this->inspector->query_facts( $context, $input->directory_id, $input->dataset, $selected_ids, false, $projected, $schema_hash );
				if ( ! hash_equals( $schema_hash, $facts->schema_hash ) || array_column( $facts->listings, 'id' ) !== $selected_ids ) {
					throw new ContractException( 'stale_catalog', 'Listing query native batch changed its catalog or membership.' );
				}
				$this->distance_facts( $facts->listings, $near );
				foreach ( $facts->listings as $listing ) {
					// observed_at is delivery metadata, not a source version. The native
					// version hashes every public fact, including filtered extension facts.
					hash_update( $digest, wp_json_encode( array( $listing->id, $listing->status, $listing->author_id, $listing->version ) ) . "\n" );
					if ( ! $select || ! $this->matches( $input->where, $listing, $fields ) ) { continue; }
					++$count;
					if ( null !== $after && $this->compare_position( $this->position( $listing, $order ), $after, $order ) <= 0 ) { continue; }
					$page[] = $listing;
					usort( $page, fn( object $left, object $right ): int => $this->compare( $left, $right, $order ) );
					if ( count( $page ) > $input->limit + 1 ) { array_pop( $page ); }
				}
				$limitations = array_values( array_unique( array_merge( $limitations, $facts->limitations ) ) );
				// Each selected batch was refreshed by the native hydrator. Release its
				// object snapshots instead of accumulating a directory-sized WP cache.
				foreach ( array_merge( array( 'posts', 'post_meta' ), array_map( static fn( string $taxonomy ): string => $taxonomy . '_relationships', array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ) ) ) as $cache_group ) {
					if ( function_exists( 'wp_cache_delete_multiple' ) ) { wp_cache_delete_multiple( $selected_ids, $cache_group ); }
					else { foreach ( $selected_ids as $id ) { wp_cache_delete( $id, $cache_group ); } }
				}
				unset( $facts );
			}
			$offset += count( $ids );
		} while ( count( $ids ) === $size );
		return array( 'digest' => hash_final( $digest ), 'scanned' => $offset, 'count' => $count, 'batches' => $batches, 'page' => $page, 'limitations' => $limitations );
	}

	/** A bound visitor worker must stop before the next native hydration batch. */
	private function assert_batch_not_cancelled( Context $context ): void {
		if ( 'visitor' !== $context->profile() || 'worker' !== $context->transport() || null === $context->run_id() || null === $context->conversation_id() ) { return; }
		$context->assert_execution_identity();
		$scope = array( get_current_blog_id(), $GLOBALS['wpdb'], $GLOBALS['wpdb']->prefix );
		if ( $this->cancellation_scope !== $scope ) {
			if ( null !== $this->cancellation_scope && null !== $this->runtime_db ) {
				throw new ContractException( 'scope_conflict', 'The supplied cancellation storage belongs to another native query scope.' );
			}
			// wpdb's placeholder callback retains each opened connection. Reuse
			// this reader within its native partition; never cache cancellation rows.
			$this->cancellation_db = $this->runtime_db ?? new \DirectoristAIPro\Storage\Database( $GLOBALS['wpdb'] );
			$this->cancellation_scope = $scope;
		}
		$db = $this->cancellation_db;
		$rows = $db->rows( 'SELECT r.cancel_requested,c.erasure_requested FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.profile=%s', array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference(), 'visitor' ) );
		// This is a cancellation observation, never a new source of permission.
		// Direct native/fixture contexts without a durable owned row keep their
		// existing public query checks; another owner's flags cannot stop them.
		if ( 1 !== count( $rows ) ) { return; }
		if ( (int) $rows[0]['cancel_requested'] || (int) $rows[0]['erasure_requested'] ) { throw new ContractException( 'cancelled', 'The visitor query was stopped before its next native batch.', array( 'visitor_query_cancelled' ) ); }
	}

	/** Exact catalog fields needed by the typed predicate, never caller-selected storage keys. */
	private function predicate_fields( object $predicate ): array {
		if ( isset( $predicate->field ) ) { return array( $predicate->field ); }
		$result = array();
		foreach ( $predicate->all ?? $predicate->any as $child ) { $result = array_merge( $result, $this->predicate_fields( $child ) ); }
		return array_values( array_unique( $result ) );
	}

	/** Compile a necessary positive term condition; null means unconstrained TRUE. Input is already validated. */
	private function taxonomy_condition( object $predicate, array $sources ): ?array {
		if ( isset( $predicate->field ) ) {
			if ( ! isset( $sources[ $predicate->field ] ) || ! in_array( $predicate->op, array( 'eq', 'in' ), true ) ) {
				return null;
			}
			$terms = 'eq' === $predicate->op ? array( $predicate->value ) : $predicate->value;
			return array( 'taxonomy' => $sources[ $predicate->field ], 'field' => 'term_id', 'terms' => array_values( array_unique( $terms ) ), 'operator' => 'IN', 'include_children' => false );
		}
		$all = isset( $predicate->all );
		$parts = array();
		foreach ( $all ? $predicate->all : $predicate->any as $child ) {
			$condition = $this->taxonomy_condition( $child, $sources );
			if ( null === $condition ) {
				if ( ! $all ) {
					return null;
				}
				continue;
			}
			$parts[] = $condition;
		}
		if ( ! $parts ) {
			return null;
		}
		return count( $parts ) === 1 ? $parts[0] : array_merge( array( 'relation' => $all ? 'AND' : 'OR' ), $parts );
	}

	/** Compile a bounded necessary native-text cover; residual predicates still run on hydrated facts. */
	private function lexical_plan( object $predicate, array $snapshot ): ?array {
		$available = array();
		foreach ( $snapshot['catalog']->fields as $field ) {
			if ( in_array( $field->field_id, array( 'description', 'title' ), true ) && 'text' === $field->value_type && in_array( 'text', $field->operators, true ) ) {
				$source = $snapshot['sources'][ $field->field_id ] ?? null;
				$widget = is_array( $source ) ? ( $source['widget_name'] ?? null ) : null;
				if ( is_array( $widget ) && $widget['present'] && 'string' === $widget['type'] && $field->field_id === $widget['value'] ) {
					$available[ $field->field_id ] = true;
				}
			}
		}
		$cover = $this->lexical_cover( $predicate, $available, $snapshot );
		if ( null === $cover || ( ! $cover['description'] && ! $cover['title'] ) ) {
			return null;
		}
		$terms = $cover['terms'];
		$term_condition = ! $terms ? null : ( 1 === count( $terms ) ? $terms[0] : array_merge( array( 'relation' => 'OR' ), $terms ) );
		$meta_plans = array_values( array_unique( $cover['meta'], SORT_REGULAR ) );
		if ( count( $meta_plans ) > 4 ) {
			return null;
		}
		return array( 'needles' => array_values( array_unique( $cover['description'] ) ), 'title_needles' => array_values( array_unique( $cover['title'] ) ), 'terms' => $term_condition, 'meta_plans' => $meta_plans );
	}

	/** ANY needs every branch; ALL may choose one necessary source-backed conjunct. */
	private function lexical_cover( object $predicate, array $available, array $snapshot ): ?array {
		if ( isset( $predicate->field ) ) {
			if ( isset( $available[ $predicate->field ] ) && 'text' === $predicate->op && is_string( $predicate->value ) && strlen( $predicate->value ) >= 2 && strlen( $predicate->value ) <= 64 && preg_match( '/^[a-z0-9_-]+(?: [a-z0-9_-]+)*$/D', $predicate->value ) ) {
				$cover = array( 'description' => array(), 'title' => array(), 'terms' => array(), 'meta' => array() );
				$cover[ $predicate->field ][] = $predicate->value;
				return $cover;
			}
			$term = $this->taxonomy_condition( $predicate, $snapshot['taxonomy_sources'] );
			if ( null !== $term ) {
				return array( 'description' => array(), 'title' => array(), 'terms' => array( $term ), 'meta' => array() );
			}
			$meta = $this->postmeta_plan( $predicate, $snapshot );
			return null === $meta ? null : array( 'description' => array(), 'title' => array(), 'terms' => array(), 'meta' => array( $meta ) );
		}
		if ( isset( $predicate->all ) ) {
			$term_only = null;
			foreach ( $predicate->all as $child ) {
				$cover = $this->lexical_cover( $child, $available, $snapshot );
				if ( null !== $cover && ( $cover['description'] || $cover['title'] ) ) {
					return $cover;
				}
				if ( null !== $cover && null === $term_only ) {
					$term_only = $cover;
				}
			}
			return $term_only;
		}
		if ( ! isset( $predicate->any ) || ! $predicate->any ) {
			return null;
		}
		$cover = array( 'description' => array(), 'title' => array(), 'terms' => array(), 'meta' => array() );
		foreach ( $predicate->any as $child ) {
			$branch = $this->lexical_cover( $child, $available, $snapshot );
			if ( null === $branch ) {
				return null;
			}
			foreach ( array( 'description', 'title', 'terms', 'meta' ) as $kind ) {
				$cover[ $kind ] = array_merge( $cover[ $kind ], $branch[ $kind ] );
			}
		}
		return $cover;
	}

	/** Only one advertised raw title-equality leaf may use this small access-probe slice. */
	private function title_plan( object $predicate, array $snapshot ): ?array {
		if ( ! isset( $predicate->field, $predicate->op, $predicate->value ) || 'title' !== $predicate->field || 'eq' !== $predicate->op || ! is_string( $predicate->value ) || ! preg_match( '/^[a-zA-Z0-9_-]{2,200}$/D', $predicate->value ) ) {
			return null;
		}
		$source = $snapshot['sources']['title'] ?? null;
		$widget = is_array( $source ) ? ( $source['widget_name'] ?? null ) : null;
		if ( ! is_array( $widget ) || ! $widget['present'] || 'string' !== $widget['type'] || 'title' !== $widget['value'] ) {
			return null;
		}
		foreach ( $snapshot['catalog']->fields as $field ) {
			if ( 'title' === $field->field_id && 'text' === $field->value_type && in_array( 'eq', $field->operators, true ) ) {
				return array( 'value' => strtolower( $predicate->value ) );
			}
		}
		return null;
	}

	/** One trusted custom scalar leaf is a necessary condition of a root leaf or ALL. */
	private function postmeta_plan( object $predicate, array $snapshot ): ?array {
		$children = isset( $predicate->all ) && is_array( $predicate->all ) ? $predicate->all : array( $predicate );
		foreach ( $children as $child ) {
			if ( ! is_object( $child ) || ! isset( $child->field, $child->op ) || ! is_string( $child->field ) ) {
				continue;
			}
			$source = $snapshot['sources'][ $child->field ] ?? null;
			if ( ! is_array( $source ) || ! isset( $source['widget_name'], $source['field_key'], $source['type'] ) ) {
				continue;
			}
			$widget = $source['widget_name'];
			$type = $source['type'];
			$key_source = $source['field_key']['present'] ? $source['field_key'] : array( 'type' => gettype( $source['source_key'] ), 'value' => $source['source_key'] );
			if ( ! $widget['present'] || 'string' !== $widget['type'] || ! in_array( $widget['value'], array( 'number', 'switch' ), true ) || ! $type['present'] || 'string' !== $type['type'] || $type['value'] !== $widget['value'] || 'string' !== $key_source['type'] || ! is_string( $key_source['value'] ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key_source['value'] ) || $child->field !== $key_source['value'] ) {
				continue;
			}
			$advertised = null;
			foreach ( $snapshot['catalog']->fields as $field ) {
				if ( $field->field_id === $child->field ) {
					$advertised = $field;
					break;
				}
			}
			if ( null === $advertised || ( 'number' === $widget['value'] ? 'number' : 'boolean' ) !== $advertised->value_type || ! in_array( $child->op, $advertised->operators, true ) ) {
				continue;
			}
			$mode = null;
			if ( 'exists' === $child->op && is_bool( $child->value ) ) {
				$mode = $child->value ? 'known' : 'missing';
			} elseif ( 'number' === $widget['value'] && in_array( $child->op, array( 'eq', 'lte' ), true ) && ( is_int( $child->value ) || is_float( $child->value ) ) ) {
				$mode = $child->op;
			} elseif ( 'switch' === $widget['value'] && 'eq' === $child->op && is_bool( $child->value ) ) {
				$mode = 'eq';
			}
			if ( null !== $mode ) {
				return array( 'field' => $child->field, 'key' => '_' . $key_source['value'], 'widget' => $widget['value'], 'mode' => $mode, 'value' => $child->value );
			}
		}
		return null;
	}

	/** Schema and cache implementation remain source-pinned; raw comparisons use binary bytes. */
	private function postmeta_source_profile(): ?array {
		global $wpdb;
		foreach ( array( 'get_post', 'get_post_meta', 'metadata_exists', 'sanitize_key', 'wp_cache_delete', '_prime_post_caches' ) as $function ) {
			if ( function_exists( __NAMESPACE__ . '\\' . $function ) ) {
				return null;
			}
		}
		foreach ( array( 'get_post' => 'wp-includes/post.php', 'get_post_meta' => 'wp-includes/post.php', '_prime_post_caches' => 'wp-includes/post.php', 'metadata_exists' => 'wp-includes/meta.php', 'get_metadata_raw' => 'wp-includes/meta.php', 'sanitize_key' => 'wp-includes/formatting.php', 'wp_cache_delete' => 'wp-includes/cache.php', 'maybe_unserialize' => 'wp-includes/functions.php' ) as $function => $relative ) {
			if ( ! function_exists( $function ) || realpath( ( new \ReflectionFunction( $function ) )->getFileName() ) !== realpath( $this->native_source_path( $relative ) ) || ! $this->native_source_supported( $relative ) ) {
				return null;
			}
		}
		if ( ! is_string( $wpdb->posts ) || ! is_string( $wpdb->postmeta ) || ! is_string( $wpdb->term_relationships ) || $wpdb->posts !== $wpdb->prefix . 'posts' || $wpdb->postmeta !== $wpdb->prefix . 'postmeta' || $wpdb->term_relationships !== $wpdb->prefix . 'term_relationships' || ! preg_match( '/^[a-zA-Z0-9_]{1,256}$/D', $wpdb->posts ) || ! preg_match( '/^[a-zA-Z0-9_]{1,256}$/D', $wpdb->postmeta ) || ! preg_match( '/^[a-zA-Z0-9_]{1,256}$/D', $wpdb->term_relationships ) ) {
			return null;
		}
		$tables = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s) ORDER BY TABLE_NAME', $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $tables ) ) {
			throw new ContractException( 'storage_error', 'Native postmeta table observation failed.' );
		}
		if ( 3 !== count( $tables ) ) {
			return null;
		}
		foreach ( $tables as $table ) {
			if ( ! is_array( $table ) || array_keys( $table ) !== array( 'TABLE_NAME', 'TABLE_TYPE', 'ENGINE' ) || ! in_array( $table['TABLE_NAME'], array( $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ), true ) || 'BASE TABLE' !== $table['TABLE_TYPE'] || 'InnoDB' !== $table['ENGINE'] ) {
				return null;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s) AND COLUMN_NAME IN (\'ID\',\'post_type\',\'meta_id\',\'post_id\',\'meta_key\',\'meta_value\',\'object_id\',\'term_taxonomy_id\') ORDER BY TABLE_NAME,COLUMN_NAME', $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new ContractException( 'storage_error', 'Native postmeta schema observation failed.' );
		}
		$found = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'TABLE_NAME', 'COLUMN_NAME', 'DATA_TYPE' ) ) {
				return null;
			}
			$found[ $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] ] = $row['DATA_TYPE'];
		}
		$expected = array( $wpdb->posts . '.ID' => 'bigint', $wpdb->posts . '.post_type' => 'varchar', $wpdb->postmeta . '.meta_id' => 'bigint', $wpdb->postmeta . '.post_id' => 'bigint', $wpdb->postmeta . '.meta_key' => 'varchar', $wpdb->postmeta . '.meta_value' => 'longtext', $wpdb->term_relationships . '.object_id' => 'bigint', $wpdb->term_relationships . '.term_taxonomy_id' => 'bigint' );
		ksort( $found );
		ksort( $expected );
		if ( $found !== $expected ) {
			return null;
		}
		$indexes = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s) AND INDEX_NAME='PRIMARY' ORDER BY TABLE_NAME,SEQ_IN_INDEX", $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $indexes ) ) {
			throw new ContractException( 'storage_error', 'Native postmeta primary-key observation failed.' );
		}
		$expected_indexes = array( $wpdb->posts . '.1' => 'ID', $wpdb->postmeta . '.1' => 'meta_id', $wpdb->term_relationships . '.1' => 'object_id', $wpdb->term_relationships . '.2' => 'term_taxonomy_id' );
		foreach ( $indexes as $index ) {
			if ( ! is_array( $index ) || array_keys( $index ) !== array( 'TABLE_NAME', 'COLUMN_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'SUB_PART', 'INDEX_TYPE' ) || ! isset( $expected_indexes[ $index['TABLE_NAME'] . '.' . $index['SEQ_IN_INDEX'] ] ) || $index['COLUMN_NAME'] !== $expected_indexes[ $index['TABLE_NAME'] . '.' . $index['SEQ_IN_INDEX'] ] || '0' !== (string) $index['NON_UNIQUE'] || null !== $index['SUB_PART'] || 'BTREE' !== $index['INDEX_TYPE'] ) {
				return null;
			}
			unset( $expected_indexes[ $index['TABLE_NAME'] . '.' . $index['SEQ_IN_INDEX'] ] );
		}
		if ( $expected_indexes ) {
			return null;
		}
		$wire = $wpdb->get_row( "SELECT VERSION() AS native_version,@@character_set_connection AS connection_charset,OCTET_LENGTH(CAST(CONVERT(UNHEX('00E69DB1E4BAAC') USING utf8mb4) AS BINARY)) AS raw_bytes,SHA2(CAST(CONVERT(UNHEX('00E69DB1E4BAAC') USING utf8mb4) AS BINARY),256) AS raw_sha,CAST(CONVERT(UNHEX('00E69DB1E4BAAC') USING utf8mb4) AS BINARY) AS small_raw,CASE WHEN OCTET_LENGTH(REPEAT('x',1025))<=1024 THEN SHA2(CAST(REPEAT('x',1025) AS BINARY),256) ELSE NULL END AS long_sha", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed binary/CASE semantic probe with no supplied values.
		if ( '' !== $wpdb->last_error ) {
			throw new ContractException( 'storage_error', 'Native postmeta binary projection probe failed.' );
		}
		$raw = hex2bin( '00E69DB1E4BAAC' );
		if ( ! is_array( $wire ) || array_keys( $wire ) !== array( 'native_version', 'connection_charset', 'raw_bytes', 'raw_sha', 'small_raw', 'long_sha' ) || ! is_string( $wire['native_version'] ) || ! preg_match( '/^(8\.0\.[0-9]+|10\.6\.[0-9]+.*MariaDB)/D', $wire['native_version'] ) || 'utf8mb4' !== $wire['connection_charset'] || '7' !== (string) $wire['raw_bytes'] || ! is_string( $raw ) || ! is_string( $wire['raw_sha'] ) || ! hash_equals( hash( 'sha256', $raw ), $wire['raw_sha'] ) || $raw !== $wire['small_raw'] || null !== $wire['long_sha'] ) {
			return null;
		}
		return array( $wpdb->posts, $wpdb->postmeta, $tables, $rows, $indexes, $wire );
	}

	/** The stock directory tax query accepts exactly these observed native relationship IDs. */
	private function postmeta_directory_terms( array $anchors, int $directory_id ): ?array {
		$terms = $anchors['terms'][ ATBDP_DIRECTORY_TYPE ][ $directory_id ] ?? array();
		if ( ! is_array( $terms ) || ! $terms || count( $terms ) > 1000 ) {
			return null;
		}
		$terms = array_values( array_unique( $terms ) );
		foreach ( $terms as $term ) {
			if ( ! $this->canonical_native_id( $term ) ) {
				return null;
			}
		}
		sort( $terms, SORT_NUMERIC );
		return array_map( 'intval', $terms );
	}

	/** Observe every listing in the proven directory and one exact metadata key. */
	private function postmeta_observation( array $plan, array $directory_terms ): ?array {
		global $wpdb;
		if ( ! $directory_terms || count( $directory_terms ) > 1000 ) {
			return null;
		}
		foreach ( $directory_terms as $term ) {
			if ( ! $this->canonical_native_id( $term ) ) {
				return null;
			}
		}
		$terms = implode( ',', array_fill( 0, count( $directory_terms ), '%d' ) );
		$sql = " FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta m ON m.post_id=p.ID AND BINARY m.meta_key=BINARY %s WHERE p.post_type=%s AND EXISTS (SELECT 1 FROM $wpdb->term_relationships tr WHERE tr.object_id=p.ID AND tr.term_taxonomy_id IN ($terms))";
		$arguments = array_merge( array( $plan['key'], ATBDP_POST_TYPE ), $directory_terms );
		$projection = 'SELECT p.ID AS post_id,m.meta_id,OCTET_LENGTH(m.meta_value) AS raw_bytes,'
			. 'CASE WHEN OCTET_LENGTH(m.meta_value)<=1024 THEN SHA2(CAST(m.meta_value AS BINARY),256) ELSE NULL END AS raw_sha,'
			. 'CASE WHEN OCTET_LENGTH(m.meta_value) BETWEEN 1 AND 15 THEN CAST(m.meta_value AS BINARY) ELSE NULL END AS small_raw';
		// No SQL ordering: 20,001 returned rows always decline, while <=20,000
		// rows constitute the complete native result under the pinned SQL profile.
		$rows = $wpdb->get_results( $wpdb->prepare( $projection . $sql . ' LIMIT 20001', $arguments ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- Fixed native columns, prepared server-owned key and observed term-taxonomy IDs.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new ContractException( 'storage_error', 'Native postmeta projection could not be read.' );
		}
		if ( count( $rows ) > 20000 ) {
			return null;
		}
		$projected = array();
		$bytes = 0;
		$legacy = false;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'post_id', 'meta_id', 'raw_bytes', 'raw_sha', 'small_raw' ) || ! $this->canonical_native_id( $row['post_id'] ) || ( null !== $row['meta_id'] && ! $this->canonical_native_id( $row['meta_id'] ) ) ) {
				throw new ContractException( 'storage_error', 'Native postmeta projection has invalid wire shape.' );
			}
			if ( null === $row['raw_bytes'] ) {
				if ( null !== $row['raw_sha'] || null !== $row['small_raw'] ) {
					throw new ContractException( 'storage_error', 'Native postmeta null projection has invalid wire shape.' );
				}
			} elseif ( null === $row['meta_id'] || ! $this->canonical_count( $row['raw_bytes'] ) ) {
				throw new ContractException( 'storage_error', 'Native postmeta byte count has invalid wire shape.' );
			} else {
				$length = (int) $row['raw_bytes'];
				if ( $length > 1024 ) {
					$legacy = true;
					break;
				}
				if ( $bytes > 8388608 - $length ) {
					return null;
				}
				$bytes += $length;
				if ( ! is_string( $row['raw_sha'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['raw_sha'] ) || ( $length >= 1 && $length <= 15 ? ! is_string( $row['small_raw'] ) || strlen( $row['small_raw'] ) !== $length || ! hash_equals( $row['raw_sha'], hash( 'sha256', $row['small_raw'] ) ) : null !== $row['small_raw'] ) ) {
					throw new ContractException( 'storage_error', 'Native postmeta raw projection has invalid wire shape.' );
				}
				if ( 0 === $length && ! hash_equals( $row['raw_sha'], hash( 'sha256', '' ) ) ) {
					throw new ContractException( 'storage_error', 'Native empty postmeta digest has changed.' );
				}
			}
			$projected[] = array( 'post_id' => (int) $row['post_id'], 'meta_id' => null === $row['meta_id'] ? null : (int) $row['meta_id'], 'raw_bytes' => null === $row['raw_bytes'] ? null : (int) $row['raw_bytes'], 'raw_sha' => $row['raw_sha'], 'meta_value' => $row['small_raw'] );
		}
		if ( $legacy ) {
			$projected = $this->postmeta_bounded_raw_rows( $sql, $arguments );
			if ( null === $projected ) {
				return null;
			}
		}
		usort(
			$projected,
			static function ( array $left, array $right ): int {
				$by_post = $left['post_id'] <=> $right['post_id'];
				return 0 !== $by_post ? $by_post : ( $left['meta_id'] ?? 0 ) <=> ( $right['meta_id'] ?? 0 );
			}
		);
		$by_id = array();
		$last_id = 0;
		$last_meta = 0;
		foreach ( $projected as $row ) {
			$id = $row['post_id'];
			$meta_id = $row['meta_id'] ?? 0;
			if ( $id < $last_id || ( $id === $last_id && ( 0 === $last_meta || $meta_id <= $last_meta ) ) || ( $id !== $last_id && 0 !== $last_id && ! isset( $by_id[ $last_id ] ) ) ) {
				throw new ContractException( 'storage_error', 'Native postmeta ordering changed.' );
			}
			$last_id = $id;
			$last_meta = $meta_id;
			$by_id[ $id ][] = $row;
		}
		if ( ! $this->postmeta_cache_matches( $plan['key'], $by_id ) ) {
			return null;
		}
		$ids = array();
		foreach ( $by_id as $id => $source_rows ) {
			if ( $this->postmeta_rows_might_match( $source_rows, $plan ) ) {
				$ids[] = $id;
			}
		}
		return array( 'ids' => $ids, 'identity' => hash( 'sha256', serialize( $projected ) ) );
	}

	/** Read rare long values only when one SQL snapshot proves the whole raw transfer bounded. */
	private function postmeta_bounded_raw_rows( string $sql, array $arguments ): ?array {
		global $wpdb;
		$lengths = 'SELECT p.ID AS post_id,m.meta_id,OCTET_LENGTH(m.meta_value) AS raw_bytes' . $sql . ' LIMIT 20001';
		$bounded = 'SELECT b.post_id,b.meta_id,b.raw_bytes,COUNT(*) OVER () AS row_count,COALESCE(SUM(b.raw_bytes) OVER (),0) AS value_bytes FROM (' . $lengths . ') b';
		$select  = "SELECT s.post_id,s.meta_id,s.raw_bytes,s.row_count,s.value_bytes,CASE WHEN s.row_count<=20000 AND s.value_bytes<=8388608 THEN m.meta_value ELSE NULL END AS meta_value FROM ($bounded) s LEFT JOIN $wpdb->postmeta m ON m.meta_id=s.meta_id ORDER BY s.post_id,s.meta_id";
		$rows    = $wpdb->get_results( $wpdb->prepare( $select, $arguments ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- Fixed bounded native length-only derived table and prepared server-owned values.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new ContractException( 'storage_error', 'Native postmeta raw rows could not be read completely.' );
		}
		if ( ! $rows ) {
			throw new ContractException( 'storage_error', 'Native postmeta long-row source disappeared.' );
		}
		$count = $rows[0]['row_count'] ?? null;
		$total = $rows[0]['value_bytes'] ?? null;
		if ( ! $this->canonical_count( $count ) || ! $this->canonical_count( $total ) || count( $rows ) !== (int) $count ) {
			throw new ContractException( 'storage_error', 'Native postmeta raw resource observation changed.' );
		}
		if ( (int) $count > 20000 || (int) $total > 8388608 ) {
			return null;
		}
		$projected = array();
		$bytes = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'post_id', 'meta_id', 'raw_bytes', 'row_count', 'value_bytes', 'meta_value' ) || ! $this->canonical_native_id( $row['post_id'] ) || ( null !== $row['meta_id'] && ! $this->canonical_native_id( $row['meta_id'] ) ) || ! $this->canonical_count( $row['row_count'] ) || ! $this->canonical_count( $row['value_bytes'] ) || (int) $row['row_count'] !== (int) $count || (int) $row['value_bytes'] !== (int) $total || ( null !== $row['raw_bytes'] && ! $this->canonical_count( $row['raw_bytes'] ) ) || ( null !== $row['meta_value'] && ! is_string( $row['meta_value'] ) ) ) {
				throw new ContractException( 'storage_error', 'Native postmeta raw row has invalid wire shape.' );
			}
			$raw = $row['meta_value'];
			$length = null === $raw ? null : strlen( $raw );
			if ( ( null === $row['meta_id'] && null !== $row['raw_bytes'] ) || ( null === $row['raw_bytes'] ) !== ( null === $length ) || ( null !== $length && $length !== (int) $row['raw_bytes'] ) ) {
				throw new ContractException( 'storage_error', 'Native postmeta raw length changed within its snapshot.' );
			}
			if ( null !== $length ) {
				if ( $bytes > 8388608 - $length ) {
					return null;
				}
				$bytes += $length;
			}
			$projected[] = array( 'post_id' => (int) $row['post_id'], 'meta_id' => null === $row['meta_id'] ? null : (int) $row['meta_id'], 'raw_bytes' => $length, 'raw_sha' => null === $raw ? null : hash( 'sha256', $raw ), 'meta_value' => null !== $length && $length >= 1 && $length <= 15 ? $raw : null );
		}
		if ( $bytes !== (int) $total ) {
			throw new ContractException( 'storage_error', 'Native postmeta raw byte count changed.' );
		}
		return $projected;
	}

	/** Native post_meta cache values are ordered raw arrays, not serialized/typed facts. */
	private function postmeta_cache_matches( string $key, array $by_id ): bool {
		$groups = $GLOBALS['wp_object_cache']->cache;
		if ( ! is_array( $groups ) || ! is_array( $groups['post_meta'] ?? array() ) || count( $groups['post_meta'] ?? array() ) > 20000 ) {
			return false;
		}
		$cache = $groups['post_meta'] ?? array();
		$cache_bytes = 0;
		foreach ( $by_id as $id => $rows ) {
			if ( ! array_key_exists( $id, $cache ) ) {
				continue;
			}
			$value = $cache[ $id ];
			$expected = array();
			foreach ( $rows as $row ) {
				if ( null !== $row['meta_id'] ) {
					$expected[] = array( $row['raw_bytes'], $row['raw_sha'] );
				}
			}
			if ( ! is_array( $value ) || array_key_exists( $key, $value ) !== (bool) $expected ) {
				return false;
			}
			if ( ! $expected ) {
				continue;
			}
			$actual = $value[ $key ];
			if ( ! is_array( $actual ) || count( $actual ) !== count( $expected ) || array_keys( $actual ) !== range( 0, count( $expected ) - 1 ) ) {
				return false;
			}
			foreach ( $actual as $index => $raw ) {
				if ( null === $raw ) {
					if ( null !== $expected[ $index ][0] || null !== $expected[ $index ][1] ) {
						return false;
					}
					continue;
				}
				if ( ! is_string( $raw ) || $cache_bytes > 8388608 - strlen( $raw ) || strlen( $raw ) !== $expected[ $index ][0] || ! hash_equals( $expected[ $index ][1], hash( 'sha256', $raw ) ) ) {
					return false;
				}
				$cache_bytes += strlen( $raw );
			}
		}
		return true;
	}

	/** Ambiguous bytes and duplicate rows always remain possible without unserializing them. */
	private function postmeta_rows_might_match( array $rows, array $plan ): bool {
		$present = null !== $rows[0]['meta_id'];
		if ( 'known' === $plan['mode'] || 'missing' === $plan['mode'] ) {
			return 'known' === $plan['mode'] ? $present : ! $present;
		}
		if ( ! $present ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$value = $row['meta_value'];
			if ( 'number' === $plan['widget'] ) {
				if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,15}$/D', $value ) ) {
					return true;
				}
				$number = (int) $value;
				if ( 'eq' === $plan['mode'] ? $number === $plan['value'] : $number <= $plan['value'] ) {
					return true;
				}
			} elseif ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) || ( '1' === $value ) === $plan['value'] ) {
				return true;
			}
		}
		return false;
	}

	/** Exact installed UTF-8 column and SQL folding profile; unsupported deployments keep legacy selection. */
	private function lexical_source_profile( bool $include_title = false ): ?array {
		$content = $this->native_text_source_profile( 'post_content' );
		if ( null === $content || ! $include_title ) {
			return $content;
		}
		$title = $this->native_text_source_profile( 'post_title' );
		return null === $title ? null : array( $content, $title );
	}

	private function title_source_profile(): ?array {
		return $this->native_text_source_profile( 'post_title' );
	}

	/** Validate only two fixed native post columns; no model-selected identifier reaches SQL. */
	private function native_text_source_profile( string $field ): ?array {
		global $wpdb;
		$types = array( 'post_content' => 'longtext', 'post_title' => 'text' );
		if ( ! isset( $types[ $field ] ) ) {
			return null;
		}
		if ( ! is_string( $wpdb->posts ) || ! preg_match( '/^[a-zA-Z0-9_]{1,256}$/D', $wpdb->posts ) || 'utf8mb4' !== $wpdb->charset ) {
			return null;
		}
		$column = $wpdb->get_row( $wpdb->prepare( 'SELECT DATA_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', $wpdb->posts, $field ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new ContractException( 'storage_error', 'Native lexical column observation failed.' );
		}
		if ( ! is_array( $column ) || array_keys( $column ) !== array( 'DATA_TYPE', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'IS_NULLABLE' ) || $types[ $field ] !== $column['DATA_TYPE'] || 'utf8mb4' !== $column['CHARACTER_SET_NAME'] || 'utf8mb4_unicode_520_ci' !== $column['COLLATION_NAME'] || 'NO' !== $column['IS_NULLABLE'] ) {
			return null;
		}
		$wire = $wpdb->get_row( "SELECT VERSION() AS native_version, @@character_set_connection AS connection_charset, LOWER(CONVERT('ABCDEFGHIJKLMNOPQRSTUVWXYZ' USING utf8mb4)) AS folded, OCTET_LENGTH(CONVERT(UNHEX('C3A9') USING utf8mb4)) AS native_bytes, CHAR_LENGTH(CONVERT(UNHEX('C3A9') USING utf8mb4)) AS native_chars, CAST(LOWER(CONVERT('ABC' USING utf8mb4)) AS BINARY) LIKE BINARY '%b%' AS native_match, CAST(LOWER(CONVERT('ABC' USING utf8mb4)) AS BINARY)=BINARY 'abc' AS native_equal", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed server-owned semantic probe with no supplied values.
		if ( '' !== $wpdb->last_error ) {
			throw new ContractException( 'storage_error', 'Native lexical comparison observation failed.' );
		}
		if ( ! is_array( $wire ) || array_keys( $wire ) !== array( 'native_version', 'connection_charset', 'folded', 'native_bytes', 'native_chars', 'native_match', 'native_equal' ) || ! is_string( $wire['native_version'] ) || ! preg_match( '/^(8\.0\.[0-9]+|10\.6\.[0-9]+.*MariaDB)/D', $wire['native_version'] ) || 'utf8mb4' !== $wire['connection_charset'] || 'abcdefghijklmnopqrstuvwxyz' !== $wire['folded'] || '2' !== (string) $wire['native_bytes'] || '1' !== (string) $wire['native_chars'] || '1' !== (string) $wire['native_match'] || '1' !== (string) $wire['native_equal'] ) {
			return null;
		}
		return array( $field, $column, $wire, $wpdb->posts );
	}

	/** Exact raw title equality may overselect all Unicode titles, never omit their PHP casefold matches. */
	private function title_candidate_ids( array $plan ): ?array {
		global $wpdb;
		$budget = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS native_rows, COALESCE(SUM(OCTET_LENGTH(post_title)),0) AS native_bytes FROM $wpdb->posts WHERE post_type=%s", ATBDP_POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native posts table/column; only native post type is prepared.
		if ( '' !== $wpdb->last_error || ! is_array( $budget ) || array_keys( $budget ) !== array( 'native_rows', 'native_bytes' ) || ! $this->canonical_count( $budget['native_rows'] ) || ! $this->canonical_count( $budget['native_bytes'] ) ) {
			throw new ContractException( 'storage_error', 'Native title resource observation failed.' );
		}
		if ( (int) $budget['native_rows'] > 50000 || (int) $budget['native_bytes'] > 16777216 ) {
			return null;
		}
		$sql = "SELECT p.ID FROM $wpdb->posts p WHERE p.post_type=%s AND (OCTET_LENGTH(p.post_title)<>CHAR_LENGTH(p.post_title) OR CAST(LOWER(p.post_title) AS BINARY)=BINARY %s) ORDER BY p.ID LIMIT 1001";
		$raw_ids = $wpdb->get_col( $wpdb->prepare( $sql, ATBDP_POST_TYPE, $plan['value'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native table/column and prepared post type/title value.
		if ( '' !== $wpdb->last_error || ! is_array( $raw_ids ) ) {
			throw new ContractException( 'storage_error', 'Native title source observation failed.' );
		}
		if ( count( $raw_ids ) > $this->candidate_limit ) {
			return null;
		}
		$ids = array();
		foreach ( $raw_ids as $id ) {
			if ( ! $this->canonical_native_id( $id ) || isset( $ids[ (int) $id ] ) ) {
				throw new ContractException( 'storage_error', 'Native title IDs are malformed.' );
			}
			$ids[ (int) $id ] = true;
		}
		return array_keys( $ids );
	}

	/** Bounded raw post-content superset. Unicode rows remain included for PHP's UTF-8 folding. */
	private function lexical_candidate_ids( Context $context, object $input, array $plan, array $anchors, ?string &$meta_identity ): ?array {
		global $wpdb;
		$meta_identity = null;
		if ( $plan['title_needles'] && $plan['needles'] ) {
			$budget = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS native_rows, COALESCE(SUM(OCTET_LENGTH(post_content)+OCTET_LENGTH(post_title)),0) AS native_bytes FROM $wpdb->posts WHERE post_type=%s", ATBDP_POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native columns and prepared post type.
		} elseif ( $plan['title_needles'] ) {
			$budget = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS native_rows, COALESCE(SUM(OCTET_LENGTH(post_title)),0) AS native_bytes FROM $wpdb->posts WHERE post_type=%s", ATBDP_POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native column and prepared post type.
		} else {
			$budget = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS native_rows, COALESCE(SUM(OCTET_LENGTH(post_content)),0) AS native_bytes FROM $wpdb->posts WHERE post_type=%s", ATBDP_POST_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native column and prepared post type.
		}
		if ( '' !== $wpdb->last_error || ! is_array( $budget ) || array_keys( $budget ) !== array( 'native_rows', 'native_bytes' ) || ! $this->canonical_count( $budget['native_rows'] ) || ! $this->canonical_count( $budget['native_bytes'] ) ) {
			throw new ContractException( 'storage_error', 'Native lexical resource observation failed.' );
		}
		// Internal scan resource budget only; this does not raise or replace the 1,000 candidate cap.
		if ( (int) $budget['native_rows'] > 50000 || (int) $budget['native_bytes'] > 16777216 ) {
			return null;
		}
		$clauses = $plan['needles'] ? array( 'OCTET_LENGTH(p.post_content)<>CHAR_LENGTH(p.post_content)' ) : array();
		if ( $plan['title_needles'] ) {
			$clauses[] = 'OCTET_LENGTH(p.post_title)<>CHAR_LENGTH(p.post_title)';
		}
		$values = array( ATBDP_POST_TYPE );
		foreach ( $plan['needles'] as $needle ) {
			$clauses[] = 'CAST(LOWER(p.post_content) AS BINARY) LIKE BINARY %s';
			$values[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		foreach ( $plan['title_needles'] as $needle ) {
			$clauses[] = 'CAST(LOWER(p.post_title) AS BINARY) LIKE BINARY %s';
			$values[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$sql = "SELECT p.ID FROM $wpdb->posts p WHERE p.post_type=%s AND (" . implode( ' OR ', $clauses ) . ') ORDER BY p.ID LIMIT 1001';
		$raw_ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native table/columns; bounded validated ASCII needles are prepared values.
		if ( '' !== $wpdb->last_error || ! is_array( $raw_ids ) ) {
			throw new ContractException( 'storage_error', 'Native lexical source observation failed.' );
		}
		if ( count( $raw_ids ) > $this->candidate_limit ) {
			return null;
		}
		$ids = array();
		foreach ( $raw_ids as $id ) {
			if ( ! $this->canonical_native_id( $id ) || isset( $ids[ (int) $id ] ) ) {
				throw new ContractException( 'storage_error', 'Native lexical IDs are malformed.' );
			}
			$ids[ (int) $id ] = true;
		}
		if ( null !== $plan['terms'] ) {
			$term_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, $plan['terms'] );
			if ( count( $term_ids ) > $this->candidate_limit ) {
				return null;
			}
			foreach ( $term_ids as $id ) {
				$ids[ $id ] = true;
			}
		}
		if ( $plan['meta_plans'] ) {
			$directory_terms = $this->postmeta_directory_terms( $anchors, $input->directory_id );
			if ( null === $directory_terms ) {
				return null;
			}
			$identities = array();
			foreach ( $plan['meta_plans'] as $meta_plan ) {
				$observation = $this->postmeta_observation( $meta_plan, $directory_terms );
				if ( null === $observation ) {
					return null;
				}
				$identities[] = $observation['identity'];
				foreach ( $observation['ids'] as $id ) {
					$ids[ $id ] = true;
				}
				if ( count( $ids ) > $this->candidate_limit ) {
					return null;
				}
			}
			$meta_identity = hash( 'sha256', serialize( $identities ) );
		}
		if ( count( $ids ) > $this->candidate_limit ) {
			return null;
		}
		$ids = array_keys( $ids );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/** Zero or canonical positive native count without accepting overflow or a partial wire value. */
	private function canonical_count( $value ): bool {
		return 0 === $value || '0' === $value || $this->canonical_native_id( $value );
	}

	/** Private exact-ID content fence also covers a selected edit that leaves membership unchanged. */
	private function lexical_content_identity( array $ids, bool $include_title = false ): string {
		$content = $this->native_text_identity( $ids, 'post_content' );
		return $include_title ? hash( 'sha256', $content . '|' . $this->native_text_identity( $ids, 'post_title' ) ) : $content;
	}

	/** Hash only bounded server-selected raw native post text; never expose the source bytes. */
	private function native_text_identity( array $ids, string $field ): string {
		global $wpdb;
		if ( ! in_array( $field, array( 'post_content', 'post_title' ), true ) || ! $ids || count( $ids ) > $this->candidate_limit ) {
			throw new ContractException( 'storage_error', 'Native text identity scope is invalid.' );
		}
		$sql = "SELECT ID,SHA2($field,256) AS content_hash FROM $wpdb->posts WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY ID';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact bounded native IDs and fixed table/columns.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new ContractException( 'storage_error', 'Native lexical content observation failed.' );
		}
		if ( count( $rows ) !== count( $ids ) ) {
			throw new ContractException( 'stale_version', 'Native lexical source rows have changed.' );
		}
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== array( 'ID', 'content_hash' ) || ! $this->canonical_native_id( $row['ID'] ) || (int) $row['ID'] !== $ids[ $index ] || ! is_string( $row['content_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['content_hash'] ) ) {
				throw new ContractException( 'storage_error', 'Native lexical content observation is malformed.' );
			}
		}
		return hash( 'sha256', serialize( $rows ) );
	}

	/** Exact source hashes for the reviewed native implementation; unknown builds use legacy selection. */
	private function native_sources(): array {
		return array(
			'wp-includes/class-wp-hook.php' => array( 'a66fe7372af72876fc702e50943f9a2a8dff4ed7394163e07491b80a06e27f1d', '7d5f86b7d25073a821dc971ce85febedff554175f861c7e0e8ee3bb2dfb48b94' ),
			'wp-includes/class-wp-query.php' => array( '21953b48dc8fe948896ce6cac8cff3b575cf18553dd3ff2f8daec85c1cfe8e7e', '6db1f0aa772680920c0203a87b2624f4f14e1e8969624db5c12010bc816e040c' ),
			'wp-includes/class-wp-tax-query.php' => array( '22eb4845078ec33afd9e5123c88817002542ce38f14ade73cf0a498ad5236c39' ),
			'wp-includes/class-wp-term-query.php' => array( 'af18376c0aa11cac728190fac6dfe59770a1827fcfeda13ec95b53e4a8c70fbf' ),
			'wp-includes/class-wp-term.php' => array( '4bceedaf4397db32016cc8e8032d4c02c2d1dda2ad966cd56a6b99d75c7f32ef' ),
			'wp-includes/class-wp-taxonomy.php' => array( '055b2da70596c7d78efb51892b6e078940a35e6c9f0963a16eb43915f87a0805' ),
			'wp-includes/class-wp-object-cache.php' => array( '1857b774bd9cfbafbd50a1b1424f7df637d202cf3f2253cda9798d4ad56f3cf0' ),
			'wp-includes/cache.php' => array( 'a219c2b57cc9990b560c6b497c877db443b7e29239645a6a79b2d76839c46e19' ),
			'wp-includes/cache-compat.php' => array( 'eebd7161af3ae4feabbcff8cf0206ada14a539635448050f066b1e6d7a2c345d' ),
			'wp-includes/functions.php' => array( '1da1a76b610bca873081ed4cbc68b5dce303df0fad16570e6abae1d50b236faf', 'cac8a17e293df99f0683a88debd3ba5e5cce274183a4e9fd3c9f18375747f41c' ),
			'wp-includes/formatting.php' => array( '922d8ce082d25d3375dae336feec1b13288df46e15c9c2dcac013c8cc2c44648', '69b5e5acd15fc70582e7edd09c6e201ffdd7f5ab4c5a0b481a9963a0acd604f8' ),
			'wp-includes/post.php' => array( '2e6ac612f82a1aeb04e1e1de0c7e2a186e5f997f67e942026bfe9b211108811c', '522ae4ad3dd9e367840cd235cde9fe6ff01ae1e4dd96906c7b5dc0edbe81a56b' ),
			'wp-includes/taxonomy.php' => array( '48af3bc6434c99beb2f274ba309590c4c063ab0c4984c6cc34d3697c5edbb1c3', '281f7aa456cb28bd09b1476b61ee7d9614db78de013f31667141a42c07e31c7b' ),
			'wp-includes/meta.php' => array( 'b1b49d1d3ddbf10d0f17286f53c30ce9582c636a4679f9a0ef92ee5ebb020a3e' ),
			'wp-includes/class-wpdb.php' => array( '99a30801ae7ed3ddb6faf5feef3b564210c8dc681fc42b8cd1ea7da0904218b5', 'e15403e90032dd0508811301505e491d4212de5152581a98f51b744a1a3903bd' ),
			'wp-includes/post-formats.php' => array( '160a927cafe77d4288375beb614b0309615d2037a1a019e506ccb09aabec983e' ),
			'wp-includes/class-wp-metadata-lazyloader.php' => array( 'c1df481157f06db91ab7f81fe68981db206beaa38721e6cab49a064110219467' ),
			'wp-includes/capabilities.php' => array( '12e26e9aede307a7ad1eed11137f0ac0ca7db1c9a841ef77d3f83f960c7a7fee', 'f5aaf3dd6a3c669f244fd6f01ad3a906556afd55941b443f1e9b34f65bb08573' ),
			'directorist/includes/classes/class-listing.php' => array( 'b0d934c7a1d3a2164c313df69b02d38c73d162e29bfebe3e8c91ab4327e15b19' ),
			'directorist/includes/classes/class-user.php' => array( 'bc85e3ccb5cbbdbe68aacf87686634263088db8d423da0346c1ec2c47e1e4252' ),
			'directorist/includes/classes/class-roles.php' => array( '203e458860238a9eac5eca1df2f1cf24f88af1a634502f4d8e16e8462171febe' ),
			'directorist/includes/review/init.php' => array( '2bca2ac1fa06d82f59826108aa9c90c00ea45050b6a4333ec1ce0b40393d05ce' ),
			'directorist/includes/helper-functions.php' => array( 'f142b1e8a79cab1a3f318a4e7ad5e19d6357c26023b39ca459c546e241b614d3' ),
			'directorist/includes/directorist-directory-functions.php' => array( 'd25753da7795fd8e879a83b33d2906737b425e466a0f27a0b5805b1b8b32df92' ),
		);
	}

	/** Require pinned callable bodies and native query/cache classes, not spelling alone. */
	private function native_source_path( string $relative ): string {
		return str_starts_with( $relative, 'directorist/' ) ? ATBDP_DIR . substr( $relative, strlen( 'directorist/' ) ) : ABSPATH . $relative;
	}

	private function native_source_supported( string $relative ): bool {
		if ( array_key_exists( $relative, $this->source_checks ) ) {
			return $this->source_checks[ $relative ]; }
		$allowed = $this->native_sources()[ $relative ] ?? array();
		$file = $this->native_source_path( $relative );
		$this->source_checks[ $relative ] = is_file( $file ) && in_array( hash_file( 'sha256', $file ), $allowed, true );
		return $this->source_checks[ $relative ];
	}

	/** Audited callback contexts only; this is not a general assertion of PHP callback purity. */
	private function supported_callback( string $hook, int $priority, array $callback ): bool {
		$function = $callback['function'];
		if ( 'all' === $hook && null !== $this->active_observer && $function === $this->active_observer && PHP_INT_MIN === $priority && 1 === $callback['accepted_args'] ) {
			return true;
		}
		$allowed = array(
			'pre_option_gmt_offset' => array( 'wp_timezone_override_offset' => array( 10, 1, 'wp-includes/functions.php' ) ),
			'parse_query' => array( 'ATBDP_Listing::listing_type_search_query' => array( 10, 1, 'directorist/includes/classes/class-listing.php' ) ),
			'pre_get_posts' => array( 'Directorist\\Review\\Bootstrap::override_comments_pagination' => array( 10, 1, 'directorist/includes/review/init.php' ), 'ATBDP_User::restrict_listing_to_the_author' => array( 10, 1, 'directorist/includes/classes/class-user.php' ) ),
			'query' => array( 'wpdb::remove_placeholder_escape' => array( 0, 1, 'wp-includes/class-wpdb.php' ) ),
			'get_terms' => array( '_post_format_get_terms' => array( 10, 3, 'wp-includes/post-formats.php' ) ),
			'wp_get_object_terms' => array( '_post_format_wp_get_object_terms' => array( 10, 1, 'wp-includes/post-formats.php' ) ),
			'get_term_metadata' => array( 'wp_check_term_meta_support_prefilter' => array( 10, 1, 'wp-includes/taxonomy.php' ), 'WP_Metadata_Lazyloader::lazyload_meta_callback' => array( 10, 5, 'wp-includes/class-wp-metadata-lazyloader.php' ) ),
			'update_term_metadata_cache' => array( 'wp_check_term_meta_support_prefilter' => array( 10, 1, 'wp-includes/taxonomy.php' ) ),
			'map_meta_cap' => array( 'Directorist\\Review\\Bootstrap::map_meta_cap_for_review_author' => array( 10, 4, 'directorist/includes/review/init.php' ), 'ATBDP_Roles::meta_caps' => array( 10, 4, 'directorist/includes/classes/class-roles.php' ) ),
			'user_has_cap' => array( 'wp_maybe_grant_install_languages_cap' => array( 1, 1, 'wp-includes/capabilities.php' ), 'wp_maybe_grant_resume_extensions_caps' => array( 1, 1, 'wp-includes/capabilities.php' ), 'wp_maybe_grant_site_health_caps' => array( 1, 4, 'wp-includes/capabilities.php' ) ),
		);
		if ( is_string( $function ) ) {
			$key = $function;
			$reflection = null;
		} elseif ( is_array( $function ) && count( $function ) === 2 && is_string( $function[1] ) ) {
			$class = is_object( $function[0] ) ? get_class( $function[0] ) : $function[0];
			if ( ! is_string( $class ) ) {
				return false; }
			$key = $class . '::' . $function[1];
			$reflection = null;
		} else {
			return false;
		}
		$definition = $allowed[ $hook ][ $key ] ?? null;
		if ( null === $definition ) {
			return false; }
		$reflection = is_string( $function ) ? ( function_exists( $function ) ? new \ReflectionFunction( $function ) : null ) : ( class_exists( $class, false ) && method_exists( $class, $function[1] ) ? new \ReflectionMethod( $class, $function[1] ) : null );
		return null !== $definition && null !== $reflection && $priority === $definition[0] && $callback['accepted_args'] === $definition[1] && realpath( $reflection->getFileName() ) === realpath( $this->native_source_path( $definition[2] ) ) && $this->native_source_supported( $definition[2] );
	}

	/** Unknown profiles select the original bounded path before any candidate narrowing. */
	private function native_profile_supported( bool $observing = false ): bool {
		global $wpdb, $wp_object_cache, $wp_version, $pagenow, $post_type;
		// Every boundary rehashes; callback observation reuses only that active-call check.
		$this->source_checks = array();
		if ( ! defined( 'ATBDP_DIR' ) || ! defined( 'ATBDP_VERSION' ) || ! defined( 'ATBDP_POST_TYPE' ) || ! defined( 'ATBDP_DIRECTORY_TYPE' ) || ! defined( 'ATBDP_CATEGORY' ) || ! defined( 'ATBDP_LOCATION' ) || ! defined( 'ATBDP_TAGS' ) || '8.9.4' !== ATBDP_VERSION || ! is_string( $wp_version ) || ! preg_match( '/^7\.(0|1)(\.|$)/D', $wp_version ) || ( null !== $pagenow && ! is_string( $pagenow ) ) || ( null !== $post_type && ! is_string( $post_type ) ) || ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) || ! is_object( $wp_object_cache ) || 'WP_Object_Cache' !== get_class( $wp_object_cache ) || wp_using_ext_object_cache() || is_multisite() || ( is_admin() && 'edit.php' === $pagenow ) || ( 'edit.php' === $pagenow && ATBDP_POST_TYPE === $post_type ) ) {
			return false;
		}
		if ( ! is_string( ATBDP_DIR ) || strlen( ATBDP_DIR ) > 4096 || ( isset( $GLOBALS['user_ID'] ) && ! is_int( $GLOBALS['user_ID'] ) && ! is_string( $GLOBALS['user_ID'] ) ) || ! is_string( $wpdb->prefix ) || strlen( $wpdb->prefix ) > 256 ) {
			return false; }
		foreach ( array( ATBDP_POST_TYPE, ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ) as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $name ) ) {
				return false; }
		}
		foreach ( $this->native_sources() as $relative => $hashes ) {
			if ( ! $this->native_source_supported( $relative ) ) {
				return false; }
		}
		foreach ( array( 'WP_Hook' => 'wp-includes/class-wp-hook.php', 'WP_Query' => 'wp-includes/class-wp-query.php', 'WP_Tax_Query' => 'wp-includes/class-wp-tax-query.php', 'WP_Term_Query' => 'wp-includes/class-wp-term-query.php', 'WP_Term' => 'wp-includes/class-wp-term.php', 'WP_Taxonomy' => 'wp-includes/class-wp-taxonomy.php', 'WP_Object_Cache' => 'wp-includes/class-wp-object-cache.php', 'wpdb' => 'wp-includes/class-wpdb.php' ) as $class => $relative ) {
			if ( realpath( ( new \ReflectionClass( $class ) )->getFileName() ) !== realpath( $this->native_source_path( $relative ) ) ) {
				return false; }
		}
		foreach ( array( 'wp_cache_get', 'wp_cache_set', 'wp_cache_delete', 'wp_cache_get_multiple' ) as $function ) {
			if ( realpath( ( new \ReflectionFunction( $function ) )->getFileName() ) !== realpath( $this->native_source_path( 'wp-includes/cache.php' ) ) ) {
				return false; }
		}
		foreach ( array( 'wp_cache_get_salted', 'wp_cache_set_salted' ) as $function ) {
			if ( ! function_exists( $function ) || realpath( ( new \ReflectionFunction( $function ) )->getFileName() ) !== realpath( $this->native_source_path( 'wp-includes/cache-compat.php' ) ) ) {
				return false; }
		}
		if ( realpath( ( new \ReflectionFunction( 'wp_cache_get_last_changed' ) )->getFileName() ) !== realpath( $this->native_source_path( 'wp-includes/functions.php' ) ) ) {
			return false; }
		$hooks = array( 'all', 'pre_option_gmt_offset', 'parse_query', 'parse_tax_query', 'pre_get_posts', 'posts_pre_query', 'posts_selection', 'wp_allow_query_attachment_by_filename', 'query', 'get_terms_defaults', 'parse_term_query', 'pre_get_terms', 'get_terms_args', 'list_terms_exclusions', 'get_terms_fields', 'terms_clauses', 'terms_pre_query', 'get_terms_orderby', 'get_terms', 'wp_get_object_terms_args', 'get_object_terms', 'wp_get_object_terms', 'get_term', 'get_post_metadata', 'default_post_metadata', 'update_post_metadata_cache', 'get_term_metadata', 'default_term_metadata', 'update_term_metadata_cache', 'directorist_submission_form_fields', 'map_meta_cap', 'user_has_cap' );
		foreach ( array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			if ( ! $taxonomy instanceof \WP_Taxonomy || 'WP_Taxonomy' !== get_class( $taxonomy ) ) {
				return false; }
			$registration = get_object_vars( $taxonomy );
			if ( ! isset( $registration['object_type'], $registration['hierarchical'] ) || ! is_array( $registration['object_type'] ) || count( $registration['object_type'] ) > 1000 || ! in_array( ATBDP_POST_TYPE, $registration['object_type'], true ) || ( null !== ( $registration['args'] ?? null ) && array() !== $registration['args'] ) || ! is_bool( $registration['hierarchical'] ) ) {
				return false; }
			if ( ATBDP_DIRECTORY_TYPE === $name && $taxonomy->hierarchical ) {
				return false; }
			foreach ( $taxonomy->object_type as $object_type ) {
				if ( ! is_string( $object_type ) || strlen( $object_type ) > 64 ) {
					return false; }
			}
			$hooks[] = 'get_' . $name;
		}
		if ( null === $this->profile_identity() ) {
			return false; }
		foreach ( $hooks as $hook ) {
			$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;
			if ( null !== $registered && ! $this->canonical_hook( $registered ) ) {
				return false; }
			foreach ( $registered->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! $this->supported_callback( $hook, $priority, $callback ) ) {
						return false; }
				}
			}
		}
		return ( $observing || null === $this->active_observer ) && null !== $this->profile_identity() && $this->native_cache_supported();
	}

	/** Bound native current-site term cache inspection; explicitly exempt only the native salt key. */
	private function native_cache_supported(): bool {
		if ( false !== $GLOBALS['wp_object_cache']->multisite || '' !== $GLOBALS['wp_object_cache']->blog_prefix ) {
			return false; }
		$groups = $GLOBALS['wp_object_cache']->cache;
		if ( ! is_array( $groups ) || count( $groups ) > 10000 ) {
			return false; }
		$cache = $groups['terms'] ?? array();
		if ( ! is_array( $cache ) || count( $cache ) > 10000 ) {
			return false; }
		$bytes = 0;
		foreach ( $cache as $key => $value ) {
			if ( 'last_changed' === $key ) {
				if ( ! is_string( $value ) || strlen( $value ) > 256 ) {
					return false; }
				$bytes += strlen( $value );
				continue;
			}
			if ( ! $this->canonical_native_id( $key ) || ! $this->canonical_cached_term( (string) $key, $value, $bytes ) || $bytes > 1048576 ) {
				return false; }
		}
		return true;
	}

	/** Raw cache K must not advertise J before uncached relationships are turned into typed facts. */
	private function canonical_cached_term( string $key, $value, int &$bytes ): bool {
		if ( ! is_object( $value ) || ! in_array( get_class( $value ), array( 'stdClass', 'WP_Term' ), true ) ) {
			return false; }
		$row = get_object_vars( $value );
		$integers = array( 'term_id', 'term_group', 'term_taxonomy_id', 'parent', 'count' );
		$strings = array( 'name', 'slug', 'taxonomy', 'description' );
		$fields = array_merge( $integers, $strings );
		if ( array_diff( array_keys( $row ), array_merge( $fields, array( 'filter' ) ) ) || array_diff( $fields, array_keys( $row ) ) || ( array_key_exists( 'filter', $row ) && 'raw' !== $row['filter'] ) ) {
			return false; }
		foreach ( $integers as $field ) {
			$native = $row[ $field ];
			if ( 0 !== $native && '0' !== $native && ! $this->canonical_native_id( $native ) ) {
				return false; }
			$bytes += strlen( (string) $native );
		}
		foreach ( $strings as $field ) {
			if ( ! is_string( $row[ $field ] ) || strlen( $row[ $field ] ) > 1048576 ) {
				return false; }
			$bytes += strlen( $row[ $field ] );
		}
		return $key === (string) $row['term_id'] && $this->canonical_native_id( $row['term_id'] ) && $this->canonical_native_id( $row['term_taxonomy_id'] );
	}

	/** Validate before property iteration; malformed/custom hook observations never invoke reflection or magic. */
	private function canonical_hook( $registered ): bool {
		if ( ! $registered instanceof \WP_Hook || 'WP_Hook' !== get_class( $registered ) || ! is_array( $registered->callbacks ) || count( $registered->callbacks ) > 10000 ) {
			return false; }
		$count = 0;
		foreach ( $registered->callbacks as $priority => $values ) {
			if ( ! is_int( $priority ) || ! is_array( $values ) ) {
				return false; }
			foreach ( $values as $value ) {
				if ( ++$count > 10000 || ! is_array( $value ) || count( $value ) !== 2 || ! isset( $value['function'], $value['accepted_args'] ) || ! is_int( $value['accepted_args'] ) || $value['accepted_args'] < 0 || $value['accepted_args'] > 1000 ) {
					return false; }
				$function = $value['function'];
				if ( is_array( $function ) ) {
					if ( count( $function ) !== 2 || ! array_key_exists( 0, $function ) || ! array_key_exists( 1, $function ) || ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) || ! is_string( $function[1] ) || strlen( $function[1] ) > 2048 || ( is_string( $function[0] ) && strlen( $function[0] ) > 2048 ) ) {
						return false; }
				} elseif ( ! is_object( $function ) && ( ! is_string( $function ) || strlen( $function ) > 2048 ) ) {
					return false; }
			}
		}
		return true;
	}

	/** Capture callback registration identity and context; only the known lazyloader queue lifecycle is excluded. */
	private function profile_identity(): ?array {
		$statuses = $this->status_registry_identity();
		if ( null === $statuses ) {
			return null; }
		$hooks = $GLOBALS['wp_filter'] ?? null;
		if ( ! is_array( $hooks ) || count( $hooks ) > 10000 ) {
			return null; }
		$callbacks = array();
		$count = 0;
		$bytes = 0;
		foreach ( $hooks as $hook => $registered ) {
			if ( ! is_string( $hook ) || strlen( $hook ) > 2048 || ! $this->canonical_hook( $registered ) ) {
				return null; }
			$bytes += strlen( $hook );
			foreach ( $registered->callbacks as $priority => $values ) {
				foreach ( $values as $value ) {
					if ( ++$count > 10000 || ! is_int( $priority ) || ! isset( $value['function'], $value['accepted_args'] ) || ! is_int( $value['accepted_args'] ) || $value['accepted_args'] < 0 || $value['accepted_args'] > 1000 ) {
						return null; }
					$function = $value['function'];
					if ( is_array( $function ) && ( count( $function ) !== 2 || ! array_key_exists( 0, $function ) || ! array_key_exists( 1, $function ) || ( ! is_object( $function[0] ) && ! is_string( $function[0] ) ) || ! is_string( $function[1] ) || strlen( $function[1] ) > 2048 ) ) {
						return null; }
					if ( 'get_term_metadata' === $hook && is_array( $function ) && is_object( $function[0] ) && 'WP_Metadata_Lazyloader' === get_class( $function[0] ) && 'lazyload_meta_callback' === $function[1] && $this->supported_callback( $hook, $priority, $value ) ) {
						continue; }
					if ( is_array( $function ) ) {
						$identity = ( is_object( $function[0] ) ? get_class( $function[0] ) . ':' . spl_object_id( $function[0] ) : $function[0] ) . '::' . $function[1];
					} else {
						if ( ! is_object( $function ) && ! is_string( $function ) ) {
							return null; }
						$identity = is_object( $function ) ? get_class( $function ) . ':' . spl_object_id( $function ) : $function;
					}
					$bytes += strlen( $identity ) + 32;
					if ( $bytes > 1048576 ) {
						return null; }
					$callbacks[ $hook ][] = array( $priority, $identity, $value['accepted_args'] );
				}
			}
		}
		ksort( $callbacks );
		$taxonomies = array();
		foreach ( array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			$taxonomies[ $name ] = array( $taxonomy->hierarchical, $taxonomy->object_type, $taxonomy->args ?? null );
		}
		return array( hash( 'sha256', serialize( $callbacks ) ), $taxonomies, get_current_blog_id(), get_current_user_id(), $GLOBALS['pagenow'] ?? null, $GLOBALS['post_type'] ?? null, $GLOBALS['user_ID'] ?? null, is_admin(), $statuses, spl_object_id( $GLOBALS['wp_object_cache'] ), spl_object_id( $GLOBALS['wpdb'] ), $GLOBALS['wpdb']->prefix );
	}

	/** Observe native status rows without invoking property magic or object serializers. */
	private function status_registry_identity(): ?string {
		$registry = $GLOBALS['wp_post_statuses'] ?? null;
		if ( ! is_array( $registry ) || count( $registry ) > 1000 ) {
			return null; }
		$identity = array();
		$bytes = 0;
		$entries = 0;
		foreach ( $registry as $name => $status ) {
			if ( ! is_string( $name ) || strlen( $name ) > 64 || ! is_object( $status ) || 'stdClass' !== get_class( $status ) ) {
				return null; }
			$row = get_object_vars( $status );
			if ( ! isset( $row['name'] ) || ! is_string( $row['name'] ) || $name !== $row['name'] || ! preg_match( '/^[a-z0-9_-]{1,64}$/D', $name ) || ! $this->status_value_supported( $row, 0, $bytes, $entries ) ) {
				return null; }
			$identity[ $name ] = $row;
		}
		return hash( 'sha256', serialize( $identity ) );
	}

	/** Bound only this private native status snapshot, including core label-count arrays. */
	private function status_value_supported( $value, int $depth, int &$bytes, int &$entries ): bool {
		if ( ++$entries > 10000 || $depth > 3 ) {
			return false; }
		if ( is_array( $value ) ) {
			if ( count( $value ) > 100 ) {
				return false; }
			foreach ( $value as $key => $child ) {
				$bytes += strlen( (string) $key );
				if ( $bytes > 65536 || ! $this->status_value_supported( $child, $depth + 1, $bytes, $entries ) ) {
					return false; }
			}
			return true;
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return false; }
		$bytes += strlen( (string) $value ) + 8;
		return $bytes <= 65536;
	}

	/** Keep the actual candidate directory clause unchanged; this private read covers its stock cached transform. */
	private function directory_anchor_condition( int $directory_id ): array {
		return array( 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'field' => 'term_id', 'terms' => array( $directory_id ), 'operator' => 'IN', 'include_children' => false );
	}

	private function directory_transform_matches( int $directory_id, array $resolved ): bool {
		// Taxonomy-based accelerators are complete only when every native
		// directory assignment also has that index relationship. Legacy imports
		// may have valid metadata alone; those use the authoritative native query.
		global $wpdb;
		$missing = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_directory_type' WHERE p.post_type=%s AND CAST(m.meta_value AS SIGNED)=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy=%s AND tt.term_id=%d) LIMIT 1",
			ATBDP_POST_TYPE, $directory_id, ATBDP_DIRECTORY_TYPE, $directory_id
		) );
		if ( '' !== $wpdb->last_error || null !== $missing ) { return false; }
		$query = new \WP_Tax_Query( array( array( 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'field' => 'term_id', 'terms' => array( $directory_id ) ) ) );
		$clause = $query->queries[0];
		$query->transform_query( $clause, 'term_taxonomy_id' );
		if ( ! is_array( $clause ) || 'term_taxonomy_id' !== ( $clause['field'] ?? null ) || ! is_array( $clause['terms'] ?? null ) ) {
			return false; }
		$actual = array();
		foreach ( $clause['terms'] as $id ) {
			if ( ! $this->canonical_native_id( $id ) ) {
				return false; }
			$actual[] = (int) $id;
		}
		$actual = array_values( array_unique( $actual ) );
		sort( $actual, SORT_NUMERIC );
		$expected = array_values( array_unique( $resolved[ ATBDP_DIRECTORY_TYPE ][ $directory_id ] ?? array() ) );
		sort( $expected, SORT_NUMERIC );
		return $actual === $expected;
	}

	/** Resolve only validated predicate IDs from fixed native columns; null selects legacy resource fallback. */
	private function taxonomy_anchors( array $condition ): ?array {
		global $wpdb;
		$requested = array();
		$this->collect_taxonomy_terms( $condition, $requested );
		ksort( $requested );
		foreach ( $requested as &$terms ) {
			$terms = array_values( array_unique( $terms ) );
			sort( $terms, SORT_NUMERIC );
		}
		unset( $terms );
		$columns = array( 't' => array( 'term_id', 'name', 'slug', 'term_group' ), 'tt' => array( 'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count' ) );
		$selects = array( 'tt.term_taxonomy_id AS native_tt_id', 'tt.term_id AS native_term_id', 'tt.taxonomy AS native_taxonomy', 't.term_id AS joined_term_id' );
		$lengths = array();
		foreach ( $columns as $alias => $names ) {
			foreach ( $names as $name ) {
				$selects[] = "SHA2(CAST($alias.$name AS BINARY), 256) AS {$alias}_{$name}";
				$lengths[] = "COALESCE(OCTET_LENGTH($alias.$name), 0)";
			}
		}
		$where = array();
		$arguments = array();
		foreach ( $requested as $taxonomy => $terms ) {
			$where[] = '(BINARY tt.taxonomy=%s AND tt.term_id IN (' . implode( ',', array_fill( 0, count( $terms ), '%d' ) ) . '))';
			$arguments = array_merge( $arguments, array( $taxonomy ), $terms );
		}
		// Inspect lengths before hashing LOBs; at most 1001 joined rows enter this private preflight.
		// This engineering fallback does not alter the public candidate ceiling or guarantee SQL CPU cost.
		$from = " FROM $wpdb->term_taxonomy tt LEFT JOIN $wpdb->terms t ON t.term_id=tt.term_id WHERE " . implode( ' OR ', $where );
		$order = ' ORDER BY tt.term_id, BINARY tt.taxonomy, tt.term_taxonomy_id LIMIT 1001';
		$budget_sql = 'SELECT COUNT(*) AS native_rows, COALESCE(SUM(native_bytes), 0) AS native_bytes FROM (SELECT (' . implode( ' + ', $lengths ) . ') AS native_bytes' . $from . $order . ') native_anchor_lengths';
		$budget = $wpdb->get_row( $wpdb->prepare( $budget_sql, $arguments ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns/table aliases; only supplied values use prepared placeholders.
		if ( '' !== $wpdb->last_error || ! is_array( $budget ) || ! $this->valid_anchor_budget( $budget ) ) {
			throw new ContractException( 'storage_error', 'Native taxonomy source resource observation failed.' );
		}
		if ( (int) $budget['native_rows'] > 1000 || (int) $budget['native_bytes'] > 1048576 ) {
			return null; }
		// Fixed aliases/columns and trusted native table names; no supplied identifier or SQL fragment.
		$sql = 'SELECT ' . implode( ', ', $selects ) . $from . $order;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $arguments ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed columns/table aliases; only supplied values use prepared placeholders.
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new ContractException( 'storage_error', 'Native taxonomy source observation failed.' );
		}
		if ( count( $rows ) > 1000 ) {
			return null;
		}
		$resolved = array_fill_keys( array_keys( $requested ), array() );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! $this->valid_anchor_row( $row ) || ! isset( $requested[ $row['native_taxonomy'] ] ) || ! in_array( (int) $row['native_term_id'], $requested[ $row['native_taxonomy'] ], true ) ) {
				throw new ContractException( 'storage_error', 'Native taxonomy source observation is malformed.' );
			}
			if ( null !== $row['joined_term_id'] ) {
				$resolved[ $row['native_taxonomy'] ][ (int) $row['native_term_id'] ][] = (int) $row['native_tt_id'];
			}
		}
		return array( 'terms' => $resolved, 'identity' => hash( 'sha256', serialize( array( $requested, $rows ) ) ) );
	}

	/** Require canonical bounded preflight observations, including legitimate empty anchors. */
	private function valid_anchor_budget( array $row ): bool {
		if ( 2 !== count( $row ) || ! array_key_exists( 'native_rows', $row ) || ! array_key_exists( 'native_bytes', $row ) ) {
			return false; }
		foreach ( $row as $value ) {
			if ( 0 !== $value && '0' !== $value && ! $this->canonical_native_id( $value ) ) {
				return false; }
		}
		return (int) $row['native_rows'] <= 1001 && ( 0 !== (int) $row['native_rows'] || 0 === (int) $row['native_bytes'] );
	}

	/** Native wire IDs must retain canonical positive integer identity before any conversion. */
	private function canonical_native_id( $value ): bool {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return false;
		}
		$value = (string) $value;
		$maximum = (string) PHP_INT_MAX;
		return (bool) preg_match( '/^[1-9][0-9]*$/D', $value ) && strlen( $value ) <= strlen( $maximum ) && ( strlen( $value ) < strlen( $maximum ) || strcmp( $value, $maximum ) <= 0 );
	}

	/** Require every fixed digest alias; legitimate missing joined terms remain represented privately. */
	private function valid_anchor_row( array $row ): bool {
		$required = array( 'native_tt_id', 'native_term_id', 'native_taxonomy', 'joined_term_id', 't_term_id', 't_name', 't_slug', 't_term_group', 'tt_term_taxonomy_id', 'tt_term_id', 'tt_taxonomy', 'tt_description', 'tt_parent', 'tt_count' );
		if ( count( $row ) !== count( $required ) || array_diff( $required, array_keys( $row ) ) || ! $this->canonical_native_id( $row['native_tt_id'] ) || ! $this->canonical_native_id( $row['native_term_id'] ) || ! is_string( $row['native_taxonomy'] ) || strlen( $row['native_taxonomy'] ) > 32 ) {
			return false;
		}
		$missing = null === $row['joined_term_id'];
		if ( ! $missing && ( ! $this->canonical_native_id( $row['joined_term_id'] ) || (string) $row['joined_term_id'] !== (string) $row['native_term_id'] ) ) {
			return false;
		}
		foreach ( array_slice( $required, 4 ) as $key ) {
			if ( $missing && str_starts_with( $key, 't_' ) ) {
				if ( null !== $row[ $key ] ) {
					return false; }
			} elseif ( ! is_string( $row[ $key ] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row[ $key ] ) ) {
				return false;
			}
		}
		return hash_equals( hash( 'sha256', (string) $row['native_term_id'] ), $row['tt_term_id'] ) && hash_equals( hash( 'sha256', (string) $row['native_tt_id'] ), $row['tt_term_taxonomy_id'] ) && hash_equals( hash( 'sha256', $row['native_taxonomy'] ), $row['tt_taxonomy'] ) && ( $missing || hash_equals( $row['t_term_id'], $row['tt_term_id'] ) );
	}

	/** Collect only bounded validated leaves. */
	private function collect_taxonomy_terms( array $condition, array &$requested ): void {
		if ( isset( $condition['taxonomy'] ) ) {
			$requested[ $condition['taxonomy'] ] = array_merge( $requested[ $condition['taxonomy'] ] ?? array(), $condition['terms'] );
			return;
		}
		foreach ( $condition as $key => $child ) {
			if ( 'relation' !== $key ) {
				$this->collect_taxonomy_terms( $child, $requested ); }
		}
	}

	/** Compile server-observed TT IDs; core skips its cached ID transformation for this exact field. */
	private function resolve_taxonomy_condition( array $condition, array $resolved ): array {
		if ( isset( $condition['taxonomy'] ) ) {
			$terms = array();
			foreach ( $condition['terms'] as $id ) {
				$terms = array_merge( $terms, $resolved[ $condition['taxonomy'] ][ $id ] ?? array() ); }
			$condition['terms'] = array_values( array_unique( $terms ) );
			sort( $condition['terms'], SORT_NUMERIC );
			$condition['field'] = 'term_taxonomy_id';
			return $condition;
		}
		foreach ( $condition as $key => $child ) {
			if ( 'relation' !== $key ) {
				$condition[ $key ] = $this->resolve_taxonomy_condition( $child, $resolved ); }
		}
		return $condition;
	}

	/** Repeat source and complete bounded membership observations before returning any narrowed facts. */
	private function revalidate_prefilter( Context $context, object $input, array $snapshot, object $facts, array $condition, array $anchors, array $profile, array $candidate_ids, bool &$unsupported ): void {
		$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		if ( ! $fresh['sources_supported'] || ! hash_equals( $snapshot['source_fingerprint'], $fresh['source_fingerprint'] ) || ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $snapshot['catalog']->schema_hash, $facts->schema_hash ) ) {
			throw new ContractException( 'stale_catalog', 'Listing query source mapping has changed.' );
		}
		$logical = $this->taxonomy_condition( $input->where, $fresh['taxonomy_sources'] );
		$final_anchors = null === $logical ? null : $this->taxonomy_anchors( array( 'relation' => 'AND', $logical, $this->directory_anchor_condition( $input->directory_id ) ) );
		if ( null === $final_anchors || ! hash_equals( $anchors['identity'], $final_anchors['identity'] ) || ! $this->directory_transform_matches( $input->directory_id, $final_anchors['terms'] ) || $this->resolve_taxonomy_condition( $logical, $final_anchors['terms'] ) !== $condition ) {
			throw new ContractException( 'stale_version', 'Listing query taxonomy sources have changed.' );
		}
		$final_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, $condition );
		if ( $candidate_ids !== $final_ids || count( $final_ids ) > $this->candidate_limit || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $profile !== $this->profile_identity() ) {
			throw new ContractException( 'stale_version', 'Listing query candidate observation has changed.' );
		}
	}

	/** Repeat complete source, anchor, SQL superset and native eligibility observations after hydration. */
	private function revalidate_lexical_prefilter( Context $context, object $input, array $snapshot, object $facts, array $plan, array $anchors, array $profile, array $lexical_profile, ?array $lexical_meta_source, array $lexical_ids, string $lexical_identity, ?string $lexical_meta_identity, array $candidate_ids, bool &$unsupported ): void {
		$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		if ( ! $fresh['sources_supported'] || ! hash_equals( $snapshot['source_fingerprint'], $fresh['source_fingerprint'] ) || ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $snapshot['catalog']->schema_hash, $facts->schema_hash ) ) {
			throw new ContractException( 'stale_catalog', 'Listing query source mapping has changed.' );
		}
		$logical = $this->lexical_plan( $input->where, $fresh );
		if ( null === $logical ) {
			throw new ContractException( 'stale_catalog', 'Listing query lexical source is unavailable.' );
		}
		$anchor_condition = null === $logical['terms'] ? $this->directory_anchor_condition( $input->directory_id ) : array( 'relation' => 'AND', $logical['terms'], $this->directory_anchor_condition( $input->directory_id ) );
		$final_anchors = $this->taxonomy_anchors( $anchor_condition );
		if ( null === $final_anchors || ! hash_equals( $anchors['identity'], $final_anchors['identity'] ) || ! $this->directory_transform_matches( $input->directory_id, $final_anchors['terms'] ) ) {
			throw new ContractException( 'stale_version', 'Listing query native anchors have changed.' );
		}
		if ( null !== $logical['terms'] ) {
			$logical['terms'] = $this->resolve_taxonomy_condition( $logical['terms'], $final_anchors['terms'] );
		}
		if ( $logical !== $plan || $lexical_profile !== $this->lexical_source_profile( (bool) $logical['title_needles'] ) || ( $logical['meta_plans'] && $lexical_meta_source !== $this->postmeta_source_profile() ) ) {
			throw new ContractException( 'stale_version', 'Listing query lexical source profile has changed.' );
		}
		$final_meta_identity = null;
		$final_lexical_ids = $this->lexical_candidate_ids( $context, $input, $logical, $final_anchors, $final_meta_identity );
		if ( null === $final_lexical_ids || $lexical_ids !== $final_lexical_ids || $lexical_meta_identity !== $final_meta_identity || ! hash_equals( $lexical_identity, $this->lexical_content_identity( $final_lexical_ids, (bool) $logical['title_needles'] ) ) ) {
			throw new ContractException( 'stale_version', 'Listing query lexical membership has changed.' );
		}
		$final_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, null, $final_lexical_ids );
		if ( $candidate_ids !== $final_ids || count( $final_ids ) > $this->candidate_limit || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $profile !== $this->profile_identity() ) {
			throw new ContractException( 'stale_version', 'Listing query candidate observation has changed.' );
		}
	}

	/** A revoked title result cannot retain facts from an earlier native eligibility observation. */
	private function revalidate_title_prefilter( Context $context, object $input, array $snapshot, object $facts, array $plan, array $anchors, array $profile, array $title_profile, array $title_ids, string $title_identity, array $candidate_ids, bool &$unsupported ): void {
		$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		if ( ! $fresh['sources_supported'] || ! hash_equals( $snapshot['source_fingerprint'], $fresh['source_fingerprint'] ) || ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $snapshot['catalog']->schema_hash, $facts->schema_hash ) ) {
			throw new ContractException( 'stale_catalog', 'Listing query title source mapping has changed.' );
		}
		$logical = $this->title_plan( $input->where, $fresh );
		$final_anchors = $this->taxonomy_anchors( $this->directory_anchor_condition( $input->directory_id ) );
		if ( null === $logical || null === $final_anchors || ! hash_equals( $anchors['identity'], $final_anchors['identity'] ) || ! $this->directory_transform_matches( $input->directory_id, $final_anchors['terms'] ) || $logical !== $plan || $title_profile !== $this->title_source_profile() ) {
			throw new ContractException( 'stale_version', 'Listing query title source has changed.' );
		}
		$final_title_ids = $this->title_candidate_ids( $logical );
		if ( null === $final_title_ids || $title_ids !== $final_title_ids || ! hash_equals( $title_identity, $this->native_text_identity( $final_title_ids, 'post_title' ) ) ) {
			throw new ContractException( 'stale_version', 'Listing query title membership has changed.' );
		}
		$final_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, null, $final_title_ids );
		if ( $candidate_ids !== $final_ids || count( $final_ids ) > $this->candidate_limit || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $profile !== $this->profile_identity() ) {
			throw new ContractException( 'stale_version', 'Listing query title candidate observation has changed.' );
		}
	}

	/** Reread all excluded and selected raw rows; membership equality alone is insufficient. */
	private function revalidate_postmeta_prefilter( Context $context, object $input, array $snapshot, object $facts, array $plan, array $anchors, array $profile, array $source_profile, array $initial, array $candidate_ids, bool &$unsupported ): void {
		$fresh = $this->catalog->query_snapshot( $context, $input->directory_id, $input->dataset );
		if ( ! $fresh['sources_supported'] || ! hash_equals( $snapshot['source_fingerprint'], $fresh['source_fingerprint'] ) || ! hash_equals( $snapshot['catalog']->revision, $fresh['catalog']->revision ) || ! hash_equals( $snapshot['catalog']->schema_hash, $facts->schema_hash ) ) {
			throw new ContractException( 'stale_catalog', 'Listing query postmeta mapping has changed.' );
		}
		$final_anchors = $this->taxonomy_anchors( $this->directory_anchor_condition( $input->directory_id ) );
		if ( null === $final_anchors || ! hash_equals( $anchors['identity'], $final_anchors['identity'] ) || ! $this->directory_transform_matches( $input->directory_id, $final_anchors['terms'] ) || $plan !== $this->postmeta_plan( $input->where, $fresh ) || $source_profile !== $this->postmeta_source_profile() || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $profile !== $this->profile_identity() ) {
			throw new ContractException( 'stale_version', 'Listing query postmeta source profile has changed.' );
		}
		$directory_terms = $this->postmeta_directory_terms( $final_anchors, $input->directory_id );
		$final = null === $directory_terms ? null : $this->postmeta_observation( $plan, $directory_terms );
		if ( null === $final || ! hash_equals( $initial['identity'], $final['identity'] ) || $initial['ids'] !== $final['ids'] || count( $final['ids'] ) > $this->candidate_limit ) {
			throw new ContractException( 'stale_version', 'Listing query postmeta source has changed.' );
		}
		$final_ids = $this->candidate_ids( $context, $input->directory_id, $input->dataset, null, $final['ids'] );
		if ( $candidate_ids !== $final_ids || count( $final_ids ) > $this->candidate_limit || $unsupported || $this->prefilter_disabled || ! $this->native_profile_supported( true ) || $profile !== $this->profile_identity() ) {
			throw new ContractException( 'stale_version', 'Listing query postmeta candidates have changed.' );
		}
	}

	/** Query only native eligibility columns/relationships, then recheck every returned post. */
	private function candidate_ids( Context $context, int $directory_id, string $dataset, ?array $condition = null, ?array $post_ids = null, int $offset = 0, ?int $batch_size = null ): array {
		$statuses = 'public' === $dataset ? array( 'publish' ) : array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) ) );
		$args     = array(
			'post_type'              => ATBDP_POST_TYPE,
			'post_status'            => $statuses,
			'posts_per_page'         => $batch_size ?? $this->candidate_limit + 1,
			'offset'                 => $offset,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => ! defined( 'ICL_SITEPRESS_VERSION' ),
			// Native directory membership is the saved _directory_type.
			// Old imports/translations can omit or leave stale taxonomy terms.
			'meta_query'             => array( array( 'key' => '_directory_type', 'value' => $directory_id, 'compare' => '=', 'type' => 'NUMERIC' ) ),
		);
		if ( null !== $condition ) {
			$args['tax_query'] = $condition;
		}
		if ( null !== $post_ids ) {
			if ( ! $post_ids ) {
				throw new ContractException( 'stale_version', 'Empty native lexical membership cannot be narrowed.' );
			}
			$args['post__in'] = $post_ids;
		}
		if ( 'own' === $dataset ) {
			$args['author'] = $context->actor()->user_id();
		}
		if ( 'public' === $dataset ) {
			$args['has_password'] = false;
		}
		$get      = $_GET;
		$post     = $_POST;
		$request  = $_REQUEST;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
			$query = new \WP_Query();
		$native_order = null;
		$cpto = $GLOBALS['CPTO'] ?? null;
		$sort_priority = is_object( $cpto ) && 'CPTO' === get_class( $cpto ) ? has_action( 'pre_get_posts', array( $cpto, 'pre_get_posts' ) ) : false;
		if ( false !== $sort_priority && $sort_priority < PHP_INT_MAX && false !== has_filter( 'posts_orderby', array( $cpto, 'posts_orderby' ) ) ) {
			// Candidate traversal requires ID order. Preserve native filter policy
			// before skipping display sorting; requested order runs after hydration.
			$native_order = static function ( $current ) use ( $query ): void {
				if ( $current === $query ) {
					$current->set( 'ignore_custom_sort', true );
				}
			};
			add_action( 'pre_get_posts', $native_order, PHP_INT_MAX );
		}
			$capture = null;
			$rewrite = null;
			// Keep the first eligibility probe and existing optional prefilters native.
			// Only complete public scans may replace a recognized physical join plan.
		if ( null !== $batch_size && 'public' === $dataset && null === $condition && null === $post_ids && ! defined( 'ICL_SITEPRESS_VERSION' ) && isset( $GLOBALS['wpdb'] ) && 'wpdb' === get_class( $GLOBALS['wpdb'] ) ) {
			$captured = null;
			$capture = static function ( $posts, $current ) use ( $query, &$captured ) {
				if ( $current === $query ) {
					$captured = null === $posts && is_string( $query->request ) ? $query->request : null;
				}
				return $posts;
			};
				$rewrite_id = '';
				$rewrite = static function ( $sql ) use ( $query, $directory_id, &$captured, &$rewrite_id ) {
					if ( null === $captured || $sql !== $captured ) {
						return $sql; }
					$hook = $GLOBALS['wp_filter']['query'] ?? null;
					if ( ! $hook instanceof \WP_Hook || array_key_last( $hook->callbacks[ PHP_INT_MAX ] ?? array() ) !== $rewrite_id ) {
						return $sql; }
					// A nested identical SELECT must not consume the outer query's capture.
					// No arguments or stack data leave this bounded identity observation.
					$owned = false;
					$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 12 );
					foreach ( $frames as $index => $frame ) {
						if ( ( $frame['object'] ?? null ) instanceof \wpdb ) {
							$reader = $frames[ $index + 1 ] ?? array();
							$owner = $frames[ $index + 2 ] ?? array();
							$owned = 'query' === ( $frame['function'] ?? null ) && 'get_col' === ( $reader['function'] ?? null ) && ( $reader['object'] ?? null ) === $frame['object'] && 'get_posts' === ( $owner['function'] ?? null ) && ( $owner['object'] ?? null ) === $query;
							break;
						}
					}
					if ( ! $owned ) {
						return $sql; }
					$captured = null;
					global $wpdb;
					return CandidateQuerySql::rewrite( $sql, $wpdb->posts, $wpdb->postmeta, $directory_id, ATBDP_POST_TYPE ) ?? $sql;
				};
				$rewrite_id = _wp_filter_build_unique_id( 'query', $rewrite, PHP_INT_MAX );
				add_filter( 'posts_pre_query', $capture, PHP_INT_MAX, 2 );
				add_filter( 'query', $rewrite, PHP_INT_MAX );
		}
		try {
				$query->query( $args );
			return array_values( array_map( 'intval', $query->posts ) );
		} finally {
			if ( null !== $native_order ) {
				remove_action( 'pre_get_posts', $native_order, PHP_INT_MAX );
			}
			if ( null !== $capture ) {
				remove_filter( 'posts_pre_query', $capture, PHP_INT_MAX ); }
			if ( null !== $rewrite ) {
				remove_filter( 'query', $rewrite, PHP_INT_MAX ); }
			$_GET     = $get;
			$_POST    = $post;
			$_REQUEST = $request;
		}
	}

	/** Validate a bounded predicate tree against the exact current catalog. */
	private function validate_predicate( object $predicate, array $fields, int $depth, bool $root, array &$metrics ): void {
		$metrics['depth'] = max( $metrics['depth'], $depth );
		if ( $depth > 4 ) {
			throw new ContractException( 'invalid_query', 'Listing query Boolean depth is too large.' );
		}
		$values = get_object_vars( $predicate );
		if ( array_keys( $values ) === array( 'field', 'op', 'value' ) ) {
			++$metrics['predicates'];
			if ( $metrics['predicates'] > 20 || ! isset( $fields[ $predicate->field ] ) || ! in_array( $predicate->op, $fields[ $predicate->field ]->operators, true ) ) {
				throw new ContractException( 'invalid_query', 'Listing query field or operator is unavailable.' );
			}
			$this->validate_value( $fields[ $predicate->field ], $predicate->op, $predicate->value );
			return;
		}
		if ( count( $values ) !== 1 || ( ! isset( $predicate->all ) && ! isset( $predicate->any ) ) ) {
			throw new ContractException( 'invalid_query', 'Listing query predicate shape is invalid.' );
		}
		$children = isset( $predicate->all ) ? $predicate->all : $predicate->any;
		if ( ! is_array( $children ) || count( $children ) > 20 || ( ! $children && ( ! $root || ! isset( $predicate->all ) ) ) ) {
			throw new ContractException( 'invalid_query', 'Listing query Boolean group is invalid.' );
		}
		foreach ( $children as $child ) {
			if ( ! $child instanceof \stdClass ) {
				throw new ContractException( 'invalid_query', 'Listing query child predicate is invalid.' );
			}
			$this->validate_predicate( $child, $fields, $depth + 1, false, $metrics );
		}
	}

	/** Validate operator-specific JSON types and catalog values. */
	private function validate_value( object $field, string $operator, $value ): void {
		if ( 'near' === $operator ) {
			$keys = $value instanceof \stdClass ? array_keys( get_object_vars( $value ) ) : array();
			sort( $keys );
			if ( 'distance' !== $field->field_id || array( 'latitude', 'longitude', 'radius', 'unit' ) !== $keys || ! $this->number( $value->latitude ) || abs( $value->latitude ) > 90 || ! $this->number( $value->longitude ) || abs( $value->longitude ) > 180 || ! $this->number( $value->radius ) || $value->radius <= 0 || $value->radius > 200 || ! in_array( $value->unit, array( 'km', 'mi' ), true ) ) {
				throw new ContractException( 'invalid_query', 'Near requires explicit valid coordinates, a radius up to 200, and km or mi.' ); }
			return;
		}
		if ( 'exists' === $operator ) {
			if ( ! is_bool( $value ) ) {
				throw new ContractException( 'invalid_query', 'Exists predicate requires a Boolean value.' );
			}
			return;
		}
		if ( 'between' === $operator ) {
			if ( 'number' !== $field->value_type || ! is_array( $value ) || 2 !== count( $value ) || ! $this->number( $value[0] ) || ! $this->number( $value[1] ) || $value[0] > $value[1] ) {
				throw new ContractException( 'invalid_query', 'Between predicate requires an ordered numeric pair.' );
			}
			return;
		}
		if ( 'in' === $operator ) {
			if ( ! is_array( $value ) || ! $value || count( $value ) > 20 ) {
				throw new ContractException( 'invalid_query', 'In predicate requires one to twenty values.' );
			}
			foreach ( $value as $child ) {
				$this->validate_scalar( $field, $child );
			}
			return;
		}
		$this->validate_scalar( $field, $value );
		if ( 'text' === $operator && ( ! is_string( $value ) || '' === $value || strlen( $value ) > 200 ) ) {
			throw new ContractException( 'invalid_query', 'Text predicate is empty or too long.' );
		}
	}

	/** Validate one scalar for a catalog value family. */
	private function validate_scalar( object $field, $value ): void {
		if ( 'number' === $field->value_type && $this->number( $value ) ) {
			return;
		}
		if ( 'text' === $field->value_type && is_string( $value ) && strlen( $value ) <= 200 ) {
			return;
		}
		if ( 'boolean' === $field->value_type && is_bool( $value ) ) {
			return;
		}
		if ( 'term_ids' === $field->value_type && is_int( $value ) && $value > 0 ) {
			return;
		}
		if ( in_array( $field->value_type, array( 'enum', 'enum_set' ), true ) ) {
			foreach ( $field->allowed_values as $allowed ) {
				if ( $allowed->value === $value ) {
					return;
				}
			}
		}
		throw new ContractException( 'invalid_query', 'Listing query value does not match the catalog type.' );
	}

	/** Validate deterministic ordering and retain only logical identities. */
	private function validate_order( array $order, array $fields ): array {
		if ( count( $order ) > 3 ) {
			throw new ContractException( 'invalid_query', 'Listing query has too many order fields.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $order as $item ) {
			if ( ! $item instanceof \stdClass || ! isset( $fields[ $item->field ] ) || ! $fields[ $item->field ]->orderable || isset( $seen[ $item->field ] ) || ! in_array( $item->direction, array( 'asc', 'desc' ), true ) ) {
				throw new ContractException( 'invalid_query', 'Listing query order is unavailable.' );
			}
			$seen[ $item->field ] = true;
			$result[]              = (object) array( 'field' => $item->field, 'direction' => $item->direction, 'type' => $fields[ $item->field ]->value_type );
		}
		return $result;
	}


	/** One mandatory origin governs computed facts, ordering and cursor identity. */
	private function near_origin( object $predicate ): ?object {
		if ( isset( $predicate->field ) ) {
			return 'distance' === $predicate->field && 'near' === $predicate->op ? $predicate->value : null; }
		$origin = null;
		foreach ( $predicate->all ?? $predicate->any as $child ) {
			$next = $this->near_origin( $child );
			if ( null === $next ) {
				continue; }
			if ( isset( $predicate->any ) || ( null !== $origin && ( (float) $origin->latitude !== (float) $next->latitude || (float) $origin->longitude !== (float) $next->longitude || $origin->unit !== $next->unit ) ) ) {
				throw new ContractException( 'invalid_query', 'Use one explicit near origin in mandatory filters.' ); }
			$origin = $next;
		}
		return $origin;
	}

	/** Calculate only from the current hydrated native map facts; missing stays unknown. */
	private function distance_facts( array $listings, ?object $near ): void {
		foreach ( $listings as $listing ) {
			$distance = $this->fact( $listing, 'distance' );
			if ( null === $distance ) {
				continue; }
			$distance->known = false;
			$distance->value = null;
			$map = $this->fact( $listing, 'map' );
			if ( null === $near || null === $map || ! $map->known || ! is_object( $map->value ) || ! empty( $map->value->hide_map ) ) {
				continue; }
			$lat = $map->value->latitude ?? null;
			$lng = $map->value->longitude ?? null;
			if ( ! is_scalar( $lat ) || ! is_scalar( $lng ) || '' === (string) $lat || '' === (string) $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! is_finite( (float) $lat ) || ! is_finite( (float) $lng ) || abs( (float) $lat ) > 90 || abs( (float) $lng ) > 180 ) {
				continue; }
			$latitude = deg2rad( (float) $lat - $near->latitude );
			$longitude = deg2rad( (float) $lng - $near->longitude );
			$a = sin( $latitude / 2 ) ** 2 + cos( deg2rad( $near->latitude ) ) * cos( deg2rad( (float) $lat ) ) * sin( $longitude / 2 ) ** 2;
			$km = 6371.0088 * 2 * atan2( sqrt( min( 1, $a ) ), sqrt( max( 0, 1 - $a ) ) );
			$distance->value = 'mi' === $near->unit ? $km / 1.609344 : $km;
			$distance->known = true;
			$distance->source->key = 'distance_' . $near->unit;
		}
	}

	/** Evaluate one already validated predicate against typed facts. */
	private function matches( object $predicate, object $listing, array $fields ): bool {
		if ( isset( $predicate->all ) ) {
			foreach ( $predicate->all as $child ) {
				if ( ! $this->matches( $child, $listing, $fields ) ) {
					return false;
				}
			}
			return true;
		}
		if ( isset( $predicate->any ) ) {
			foreach ( $predicate->any as $child ) {
				if ( $this->matches( $child, $listing, $fields ) ) {
					return true;
				}
			}
			return false;
		}
		$fact = $this->fact( $listing, $predicate->field );
		$type = $fields[ $predicate->field ]->value_type;
		if ( 'exists' === $predicate->op ) {
			$exists = null !== $fact && $fact->known;
			if ( in_array( $type, array( 'term_ids', 'enum_set' ), true ) ) {
				$exists = $exists && ( 'term_ids' === $type ? (bool) $this->term_ids( $fact->value ) : ( is_array( $fact->value ) && (bool) $fact->value ) );
			}
			return $exists === $predicate->value;
		}
		if ( null === $fact || ! $fact->known ) {
			return false;
		}
		$actual = 'term_ids' === $type ? $this->term_ids( $fact->value ) : $fact->value;
		if ( 'enum_set' === $type ) {
			if ( ! is_array( $actual ) ) {
				return false;
			}
			$wanted = 'eq' === $predicate->op ? array( $predicate->value ) : $predicate->value;
			foreach ( $wanted as $value ) {
				if ( in_array( $value, $actual, true ) ) {
					return true;
				}
			}
			return false;
		}
		switch ( $predicate->op ) {
			case 'near':
				return $actual <= $predicate->value->radius;
			case 'eq':
				return 'term_ids' === $type ? in_array( $predicate->value, $actual, true ) : $this->equal( $actual, $predicate->value, $type );
			case 'in':
				if ( 'term_ids' === $type ) {
					return (bool) array_intersect( $actual, $predicate->value );
				}
				foreach ( $predicate->value as $value ) {
					if ( $this->equal( $actual, $value, $type ) ) {
						return true;
					}
				}
				return false;
			case 'text':
				return false !== strpos( $this->lower( (string) $actual ), $this->lower( $predicate->value ) );
			case 'lt':
				return $actual < $predicate->value;
			case 'lte':
				return $actual <= $predicate->value;
			case 'gt':
				return $actual > $predicate->value;
			case 'gte':
				return $actual >= $predicate->value;
			case 'between':
				return $actual >= $predicate->value[0] && $actual <= $predicate->value[1];
		}
		return false;
	}

	/** Stable sort with missing values last and listing ID as the final tie breaker. */
	private function compare( object $left, object $right, array $order ): int {
		return $this->compare_position( $this->position( $left, $order ), $this->position( $right, $order ), $order );
	}

	/** Produce cursor-safe typed sort atoms plus the stable ID. */
	private function position( object $listing, array $order ): array {
		$result = array();
		foreach ( $order as $item ) {
			$fact     = $this->fact( $listing, $item->field );
			$result[] = (object) array( 'missing' => null === $fact || ! $fact->known, 'type' => $item->type, 'value' => null === $fact || ! $fact->known ? null : $fact->value );
		}
		$result[] = (object) array( 'missing' => false, 'type' => 'id', 'value' => $listing->id );
		return $result;
	}

	/** Validate cursor atoms before comparing them with live results. */
	private function validate_position( array $position, array $order ): array {
		if ( count( $position ) !== count( $order ) + 1 ) {
			throw new ContractException( 'invalid_cursor', 'Listing query cursor position is invalid.' );
		}
		foreach ( $position as $index => $atom ) {
			$type = $index < count( $order ) ? $order[ $index ]->type : 'id';
			if ( ! $atom instanceof \stdClass || array_keys( get_object_vars( $atom ) ) !== array( 'missing', 'type', 'value' ) || ! is_bool( $atom->missing ) || $atom->type !== $type || ( 'id' === $type && ( ! is_int( $atom->value ) || $atom->value < 1 ) ) ) {
				throw new ContractException( 'invalid_cursor', 'Listing query cursor position is invalid.' );
			}
		}
		return $position;
	}

	/** Compare two typed cursor positions. */
	private function compare_position( array $left, array $right, array $order ): int {
		foreach ( $left as $index => $left_atom ) {
			$right_atom = $right[ $index ];
			if ( $left_atom->missing !== $right_atom->missing ) {
				return $left_atom->missing ? 1 : -1;
			}
			if ( $left_atom->missing ) {
				continue;
			}
			$comparison = $this->compare_value( $left_atom->value, $right_atom->value, $left_atom->type );
			if ( 0 !== $comparison ) {
				if ( $index < count( $order ) && 'desc' === $order[ $index ]->direction ) {
					$comparison *= -1;
				}
				return $comparison;
			}
		}
		return 0;
	}

	/** Compare one typed scalar. */
	private function compare_value( $left, $right, string $type ): int {
		if ( in_array( $type, array( 'number', 'id' ), true ) ) {
			return $left <=> $right;
		}
		if ( 'boolean' === $type ) {
			return (int) $left <=> (int) $right;
		}
		return strcmp( $this->lower( (string) $left ), $this->lower( (string) $right ) );
	}

	/** Find one logical fact without accepting a native key. */
	private function fact( object $listing, string $field_id ): ?object {
		foreach ( $listing->fields as $fact ) {
			if ( $fact->field_id === $field_id ) {
				return $fact;
			}
		}
		return null;
	}

	/** Extract exact term IDs from strict term facts. */
	private function term_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( static fn( object $term ): int => (int) $term->id, $value ) );
	}

	/** Type-aware scalar equality. */
	private function equal( $actual, $expected, string $type ): bool {
		return 'text' === $type ? $this->lower( (string) $actual ) === $this->lower( (string) $expected ) : $actual === $expected;
	}

	/** Locale-independent case folding when available. */
	private function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/** Finite JSON number check; booleans and numeric strings never pass. */
	private function number( $value ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
	}

	/** Sort object keys recursively so equivalent plans share one cursor hash. */
	private function canonicalize( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );
			$result = new \stdClass();
			foreach ( $properties as $key => $child ) {
				$result->{$key} = $this->canonicalize( $child );
			}
			return $result;
		}
		if ( is_array( $value ) ) {
			return array_map( fn( $child ) => $this->canonicalize( $child ), $value );
		}
		return $value;
	}
}
