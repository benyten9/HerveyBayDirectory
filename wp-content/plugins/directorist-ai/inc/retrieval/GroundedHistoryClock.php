<?php
/** Private, forward-only source certificate for dated listing history.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Closed private certificate and native source projection.
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** A saved clock is historical evidence, never current authority or action input. */
final class GroundedHistoryClock {

	/** Capture only new, stable, freshly public native listing observations. */
	public static function capture( Gateway $gateway, Database $db, array $run, int $sequence, object $content, object $checkpoint ): ?object {
		if ( 'running' !== ( $run['state'] ?? null ) || array( 'text', 'grounded' ) !== array_keys( get_object_vars( $content ) ) || 'visitor' !== ( $run['profile'] ?? null ) ) {
			return null;
		}
		try {
			// Eligibility may reject an oversized optional history observation.
			// Keep that rejection inside the certificate's delivery-safe boundary.
			if ( ! self::eligible( $content->grounded ?? null ) ) {
				return null;
			}
			$context = self::context( $run, $checkpoint );
			$refs    = self::references( $content->grounded );
			$before  = self::sources( $gateway, $context, $refs );
			foreach ( $refs as $directory => $listings ) {
				\DirectoristAIPro\Chat\ListingActions::assert_scope( $context, $directory, $db );
				// Respect the native comparison's five-listing input contract while
				// checking every original result in the twenty-listing observation.
				foreach ( array_chunk( $listings, 5, true ) as $batch ) {
					$fresh = $gateway->execute( 'listing.compare', wp_json_encode( array( 'directory_id' => $directory, 'listing_ids' => array_keys( $batch ), 'fields' => array( 'title' ) ) ), $context );
					if ( 'comparison' !== $fresh->status || count( $fresh->rows ) !== 1 || array_keys( $batch ) !== array_column( $fresh->rows[0]->cells, 'listing_id' ) ) {
						return null;
					}
					foreach ( $fresh->rows[0]->cells as $cell ) {
						if ( ! hash_equals( $batch[ $cell->listing_id ], $cell->version ) ) {
							return null;
						}
					}
				}
			}
			$after = self::sources( $gateway, $context, $refs );
			if ( ! hash_equals( $before, $after ) ) {
				return null;
			}
			$certificate            = (object) array(
				'version'     => 1,
				'observed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'sources'     => $after,
				'content'     => self::hash( (object) array( 'text' => $content->text, 'grounded' => $content->grounded ) ),
			);
			$certificate->signature = self::signature( $run, $sequence, $certificate );
			return $certificate;
		} catch ( \Throwable $error ) {
			// Optional history certification never weakens or replaces normal delivery.
			return null;
		}
	}

	/** A forged, copied, missing or retroactively invented certificate cannot qualify. */
	public static function verifies( array $run, int $sequence, object $content ): bool {
		$certificate = $content->provenance->history_clock ?? null;
		return is_string( $content->text ?? null ) && ( $content->grounded ?? null ) instanceof \stdClass && 'visitor' === ( $run['profile'] ?? null ) && $certificate instanceof \stdClass
			&& array( 'version', 'observed_at', 'sources', 'content', 'signature' ) === array_keys( get_object_vars( $certificate ) )
			&& 1 === $certificate->version
			&& is_string( $certificate->observed_at ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $certificate->observed_at )
			&& false !== strtotime( $certificate->observed_at ) && strtotime( $certificate->observed_at ) <= time()
			&& is_string( $certificate->sources ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $certificate->sources )
			&& is_string( $certificate->content ) && hash_equals( self::hash( (object) array( 'text' => $content->text, 'grounded' => $content->grounded ) ), $certificate->content )
			&& is_string( $certificate->signature ) && hash_equals( self::signature( $run, $sequence, $certificate ), $certificate->signature );
	}

	/** Re-observe all relevant native sources and compare only the closed clock delta. */
	public static function current( Gateway $gateway, Context $context, object $content, object $current ): bool {
		if ( ! self::eligible( $content->grounded ?? null ) || ! self::eligible( $current ) || ! self::clock_changed( $content->grounded, $current ) ) {
			return false;
		}
		$refs = self::references( $content->grounded );
		if ( array_keys( $refs ) !== array_keys( self::references( $current ) ) || ! hash_equals( $content->provenance->history_clock->sources, self::sources( $gateway, $context, $refs ) ) ) {
			return false;
		}
		return self::projection( $content->grounded ) === self::projection( $current );
	}

	/** The same private source certificate is checked before and after fresh native replay. */
	public static function sources_current( Gateway $gateway, Context $context, object $content ): bool {
		return hash_equals( $content->provenance->history_clock->sources, self::sources( $gateway, $context, self::references( $content->grounded ) ) );
	}

	/** No actions, documents, empty searches, unknown contracts or oversized results. */
	private static function eligible( $response ): bool {
		return $response instanceof \stdClass && 'directorist-ai/grounded-response-v1' === ( $response->contract ?? null ) && 'results' === ( $response->status ?? null ) && 'listing' === ( $response->task->family ?? null ) && in_array( $response->task->kind ?? null, array( 'search', 'compare' ), true ) && ! isset( $response->native_actions ) && ! empty( self::references( $response ) );
	}

	/**
	 * Exact bounded source identities and original versions, preserving result order.
	 *
	 * @throws ContractException Invalid, inconsistent or oversized public identities.
	 */
	private static function references( object $response ): array {
		$refs = array();
		$add  = static function ( $directory, $id, $version ) use ( &$refs ): void {
			if ( ! is_int( $directory ) || $directory < 1 || ! is_int( $id ) || $id < 1 || ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) || ( isset( $refs[ $directory ][ $id ] ) && $refs[ $directory ][ $id ] !== $version ) ) {
				throw new ContractException( 'invalid_output', 'Historical listing identity is unavailable.' );
			}
			$refs[ $directory ][ $id ] = $version;
		};
		foreach ( $response->cards ?? array() as $card ) {
			$add( $card->directory_id ?? null, $card->listing_id ?? null, $card->version ?? null );
		}
		$sets = $response->comparisons ?? array();
		if ( isset( $response->comparison ) ) {
			$sets[] = $response->comparison;
		}
		foreach ( $sets as $set ) {
			foreach ( $set->listing_ids ?? array() as $id ) {
				$version = null;
				foreach ( $set->rows ?? array() as $row ) {
					foreach ( $row->cells ?? array() as $cell ) {
						if ( $id === $cell->listing_id ) {
							if ( null !== $version && $version !== $cell->version ) {
								throw new ContractException( 'invalid_output', 'Historical listing versions disagree.' );
							}
							$version = $cell->version;
						}
					}
				}
				$add( $set->directory_id ?? null, $id, $version );
			}
		}
		// Global search results may span one directory per observed listing.
		if ( array_sum( array_map( 'count', $refs ) ) > 20 ) {
			throw new ContractException( 'unsupported_dependency', 'Historical source observation is bounded.' );
		}
		return $refs;
	}

	/** Same native actor as the sealed run; no saved capability flags. */
	private static function context( array $run, object $checkpoint ): Context {
		$owner = $run['owner_ref'];
		$actor = str_starts_with( $owner, 'user:' ) ? Actor::user( (int) substr( $owner, 5 ) ) : Actor::verified_guest( substr( $owner, 6 ), (int) ( $checkpoint->guest_expiry ?? 0 ) );
		return new Context( $actor, $run['profile'], 'worker', $run['conversation_public_id'], $run['public_id'] );
	}

	/**
	 * Hash exact raw native source rows, effective schema/dispatch and current code.
	 *
	 * @throws ContractException Unavailable, changed, private or oversized native sources.
	 */
	private static function sources( Gateway $gateway, Context $context, array $refs ): string {
		global $wpdb;
		$budget = 2097152;
		$context->actor()->refresh();
		$context->assert_execution_identity();
		$ids = array_merge( ...array_map( 'array_keys', array_values( $refs ) ) );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		$in = implode( ',', array_map( 'intval', $ids ) );
		// Native raw observations deliberately bypass object caches; no source rows escape.
		$posts = self::rows( "SELECT * FROM $wpdb->posts WHERE ID IN ($in) ORDER BY ID", $budget );
		if ( count( $posts ) !== count( $ids ) ) {
			throw new ContractException( 'not_found', 'A historical native listing is unavailable.' );
		}
		foreach ( $posts as $post ) {
			if ( ATBDP_POST_TYPE !== $post['post_type'] || 'publish' !== $post['post_status'] || '' !== $post['post_password'] ) {
				throw new ContractException( 'unauthorized', 'A historical native listing is no longer public.' );
			}
		}
		$meta        = self::rows( "SELECT * FROM $wpdb->postmeta WHERE post_id IN ($in) ORDER BY meta_id", $budget );
		$relations   = self::rows( "SELECT * FROM $wpdb->term_relationships WHERE object_id IN ($in) ORDER BY object_id,term_taxonomy_id", $budget );
		$terms       = self::rows( "SELECT t.*,tt.term_taxonomy_id,tt.taxonomy,tt.description,tt.parent,tt.count FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id IN ($in)) OR t.term_id IN (" . implode( ',', array_map( 'intval', array_keys( $refs ) ) ) . ') ORDER BY t.term_id,tt.term_taxonomy_id', $budget );
		$term_ids    = implode( ',', array_map( 'intval', array_unique( array_column( $terms, 'term_id' ) ) ) );
		$termmeta    = $term_ids ? self::rows( "SELECT * FROM $wpdb->termmeta WHERE term_id IN ($term_ids) ORDER BY meta_id", $budget ) : array();
		$comments    = self::rows( "SELECT * FROM $wpdb->comments WHERE comment_post_ID IN ($in) ORDER BY comment_ID", $budget );
		$commentmeta = self::rows( "SELECT * FROM $wpdb->commentmeta WHERE comment_id IN (SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID IN ($in)) ORDER BY meta_id", $budget );
		// Native preview/gallery images may be unattached; include their actual source rows.
		$image_ids = array();
		foreach ( $ids as $id ) {
			$images = array_merge( array( (int) directorist_get_listing_preview_image( $id ) ), directorist_get_listing_gallery_images( $id ) );
			if ( count( $images ) > 21 ) {
				throw new ContractException( 'unsupported_dependency', 'Historical media observation is bounded.' );
			}
			foreach ( $images as $image ) {
				if ( (int) $image > 0 ) {
					$image_ids[] = (int) $image; }
			}
		}
		$image_in   = implode( ',', array_unique( $image_ids ) );
		$image_in   = '' !== $image_in ? $image_in : '0';
		$media      = self::rows( "SELECT p.* FROM $wpdb->posts p WHERE p.post_type='attachment' AND (p.post_parent IN ($in) OR p.ID IN ($image_in)) ORDER BY p.ID", $budget );
		$parent_in  = implode( ',', array_unique( array_map( 'intval', array_column( $media, 'post_parent' ) ) ) );
		$parent_in  = '' !== $parent_in ? $parent_in : '0';
		$parents    = self::rows( "SELECT * FROM $wpdb->posts WHERE ID IN ($parent_in) ORDER BY ID", $budget );
		$media_ids  = implode( ',', array_map( 'intval', array_column( $media, 'ID' ) ) );
		$media_meta = $media_ids ? self::rows( "SELECT * FROM $wpdb->postmeta WHERE post_id IN ($media_ids) ORDER BY meta_id", $budget ) : array();
		$options    = self::rows( "SELECT option_name,option_value FROM $wpdb->options WHERE option_name IN ('atbdp_option','timezone_string','gmt_offset','active_plugins','WPLANG','date_format','time_format','home','siteurl','upload_path','upload_url_path') ORDER BY option_name", $budget );
		$schemas    = array();
		$user       = get_current_user_id();
		wp_set_current_user( $context->actor()->user_id() ?? 0 );
		try {
			foreach ( $refs as $directory => $listings ) {
				$snapshot = ( new ListingQueryCatalog( new DirectoristInspector() ) )->query_snapshot( $context, $directory, 'public' );
				if ( ! $snapshot['sources_supported'] ) {
					throw new ContractException( 'unsupported_dependency', 'Historical native source dispatch is unavailable.' );
				}
				$schemas[ $directory ] = array( $snapshot['catalog'], $snapshot['source_fingerprint'], $snapshot['taxonomy_sources'] );
			}
		} finally {
			wp_set_current_user( $user );
		}
		$registry = array_values( array_filter( $gateway->describe( $context ), static fn( array $operation ): bool => in_array( $operation['id'], array( 'listing.catalog', 'listing.search', 'listing.compare', 'conversation.respond' ), true ) ) );
		$code     = array();
		foreach ( array( self::class, GroundedResponseService::class, GroundedMessageProvenance::class, ListingComparisonService::class, DirectoristInspector::class, ListingQueryCatalog::class, \DirectoristAIPro\Domain\ListingQueryService::class, \DirectoristAIPro\Chat\Workflows\PublicListingFacts::class ) as $reader ) {
			$path = ( new \ReflectionClass( $reader ) )->getFileName();
			if ( ! is_string( $path ) || ! is_file( $path ) ) {
				throw new ContractException( 'unsupported_dependency', 'Historical native reader source is unavailable.' );
			}
			$code[] = hash_file( 'sha256', $path );
		}
		$functions = array( 'directorist_get_listing_form_fields', 'directorist_get_listing_form_groups', 'directorist_get_listing_directory', 'directorist_get_listing_preview_image', 'directorist_get_listing_gallery_images', 'directorist_business_hours_status_times', 'directorist_is_business_hour_enabled_for_listing' );
		// Bind a namespace-local native reader too, if an integration supplies one.
		$functions[] = 'DirectoristAIPro\\Chat\\Workflows\\directorist_business_hours_status_times';
		foreach ( $functions as $function ) {
			if ( function_exists( $function ) ) {
				$path = ( new \ReflectionFunction( $function ) )->getFileName();
				if ( ! is_string( $path ) || ! is_file( $path ) ) {
					throw new ContractException( 'unsupported_dependency', 'Historical native function source is unavailable.' ); }
				$code[] = hash_file( 'sha256', $path );
			}
		}
		foreach ( array( 'Directorist\\Helper', 'Directorist\\Review\\Listing_Review_Meta' ) as $class ) {
			if ( class_exists( $class ) ) {
				$path = ( new \ReflectionClass( $class ) )->getFileName();
				if ( ! is_string( $path ) || ! is_file( $path ) ) {
					throw new ContractException( 'unsupported_dependency', 'Historical native class source is unavailable.' ); }
				$code[] = hash_file( 'sha256', $path );
			}
		}
		$source = serialize( array( get_current_blog_id(), $posts, $meta, $relations, $terms, $termmeta, $comments, $commentmeta, $media, $media_meta, $parents, $options, $schemas, $registry, $code ) );
		if ( strlen( $source ) > 2097152 ) {
			throw new ContractException( 'unsupported_dependency', 'Historical native source observation is bounded.' );
		}
		return hash( 'sha256', $source );
	}
	/**
	 * Bound every raw observation without truncating facts or accepting partial sources.
	 *
	 * @throws ContractException Failed or oversized native observation.
	 */
	private static function rows( string $sql, int &$budget ): array {
		global $wpdb;
		// Inspect only metadata first; large native longtext must never enter PHP unchecked.
		$bounded = '(' . $sql . ' LIMIT 10001) dai_history_source';
		$wpdb->get_results( 'SELECT * FROM ' . $bounded . ' LIMIT 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SELECT and integer-only IDs.
		$columns = $wpdb->get_col_info( 'name' );
		if ( $wpdb->last_error || ! is_array( $columns ) || ! $columns || count( $columns ) > 128 ) {
			throw new ContractException( 'unsupported_dependency', 'Historical source shape is unavailable.' );
		}
		$lengths = array();
		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $column ) ) {
				throw new ContractException( 'unsupported_dependency', 'Historical source shape is unavailable.' );
			}
			$lengths[] = 'COALESCE(OCTET_LENGTH(CAST(`' . $column . '` AS BINARY)),0)+64';
		}
		$measure = 'SELECT COUNT(*) AS source_rows,COALESCE(SUM(' . implode( '+', $lengths ) . '),0) AS source_bytes FROM ' . $bounded;
		$size    = $wpdb->get_row( $measure, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Validated column identifiers and fixed SELECT.
		if ( $wpdb->last_error || ! is_array( $size ) || (int) $size['source_rows'] > 10000 || (int) $size['source_bytes'] > $budget ) {
			throw new ContractException( 'unsupported_dependency', 'Historical source observation exceeds its bound.' );
		}
		// Recheck the byte/count predicate in the same SELECT that returns native values.
		$rows = $wpdb->get_results( 'SELECT dai_history_source.* FROM ' . $bounded . ' JOIN (' . $measure . ') dai_history_size ON dai_history_size.source_rows<=10000 AND dai_history_size.source_bytes<=' . (int) $budget, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed bounded SELECT with validated metadata and integer budget.
		if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) !== (int) $size['source_rows'] || strlen( serialize( $rows ) ) > $budget ) {
			throw new ContractException( 'unsupported_dependency', 'Historical native source observation is unavailable.' );
		}
		// Charge the actual fetched bytes too if native rows grew after the earlier size probe.
		$budget -= max( (int) $size['source_bytes'], strlen( serialize( $rows ) ) );
		return $rows;
	}

	/** Only typed clock members may differ; schedules/timezones and all static facts remain exact. */
	private static function projection( object $response ): string {
		$value = json_decode( wp_json_encode( $response ) );
		unset( $value->message, $value->context_token, $value->trace, $value->reply_origin );
		foreach ( $value->cards ?? array() as $card ) {
			$card->version = 'clock-certified';
			foreach ( $card->facts as $fact ) {
				self::fact( $fact->field_id, $fact );
			}
		}
		$sets = $value->comparisons ?? array();
		if ( isset( $value->comparison ) ) {
			$sets[] = $value->comparison;
		}
		foreach ( $sets as $set ) {
			foreach ( $set->rows as $row ) {
				foreach ( $row->cells as $cell ) {
					$cell->version = 'clock-certified';
					self::fact( $row->field_id, $cell );
				}
			}
		}
		foreach ( $value->assertions ?? array() as $assertion ) {
			$assertion->version = 'clock-certified';
			self::fact( $assertion->predicate, $assertion );
		}
		return self::hash( $value );
	}

	private static function fact( string $field, object $fact ): void {
		if ( isset( $fact->source ) && $fact->source instanceof \stdClass ) {
			unset( $fact->source->observed_at );
		}
		if ( 'open_now' === $field && is_bool( $fact->value ?? null ) ) {
			$fact->value = 'historical-clock';
		}
		if ( 'hours' === $field && ( $fact->value ?? null ) instanceof \stdClass ) {
			if ( is_bool( $fact->value->open_now ?? null ) ) {
				$fact->value->open_now = 'historical-clock';
			}
			foreach ( array( 'current_open', 'current_close', 'next_open', 'next_close' ) as $clock ) {
				if ( property_exists( $fact->value, $clock ) && ( null === $fact->value->$clock || is_int( $fact->value->$clock ) ) ) {
					$fact->value->$clock = 'historical-clock';
				}
			}
		}
	}

	private static function clock_changed( object $old, object $current ): bool {
		$collect = static function ( object $response ): array {
			$values = array();
			$add    = static function ( string $field, object $fact ) use ( &$values ): void {
				if ( 'open_now' === $field ) {
					$values[] = $fact->value; }
				if ( 'hours' === $field && ( $fact->value ?? null ) instanceof \stdClass ) {
					$values[] = array_map( static fn( string $key ) => $fact->value->$key ?? null, array( 'open_now', 'current_open', 'current_close', 'next_open', 'next_close' ) );
				}
			};
			foreach ( $response->cards ?? array() as $card ) {
				foreach ( $card->facts as $fact ) {
					$add( $fact->field_id, $fact ); }
			}
			$sets = $response->comparisons ?? array();
			if ( isset( $response->comparison ) ) {
				$sets[] = $response->comparison; }
			foreach ( $sets as $set ) {
				foreach ( $set->rows as $row ) {
					foreach ( $row->cells as $cell ) {
						$add( $row->field_id, $cell ); }
				}
			}
			return $values;
		};
		return self::hash( $collect( $old ) ) !== self::hash( $collect( $current ) );
	}

	private static function signature( array $run, int $sequence, object $certificate ): string {
		$binding = array( 'site' => get_current_blog_id(), 'owner' => $run['owner_ref'], 'conversation' => (int) $run['conversation_id'], 'conversation_public_id' => $run['conversation_public_id'], 'profile' => $run['profile'], 'run' => $run['public_id'], 'turn' => (int) $run['trigger_turn_id'], 'sequence' => $sequence, 'certificate' => array( $certificate->version, $certificate->observed_at, $certificate->sources, $certificate->content ) );
		return hash_hmac( 'sha256', wp_json_encode( $binding, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|grounded-history-clock-v1' );
	}

	private static function hash( $value ): string {
		return hash( 'sha256', wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES ) );
	}
}
