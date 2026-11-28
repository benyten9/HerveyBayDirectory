<?php
/** Bounded administrator inventory and public-only disclosure diagnostics.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

use DirectoristAIPro\Domain\DirectoristInspector;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed administrator read contracts.

/** Administrative metadata never broadens the anonymous publication adapter. */
final class AdminInspector {
	public const PAGE_SIZE  = 20;
	public const MAX_FIELDS = 200;

	public static function label( string $value, int $limit = 160 ): string {
		return mb_substr( Markdown::plain( $value ), 0, $limit );
	}

	/** Keep previews within the same visible text disclosure as the Markdown body. */
	private static function projected_value( $value ) {
		if ( is_string( $value ) ) {
			return Markdown::plain( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return array_map( array( self::class, 'projected_value' ), (array) $value );
		}
		return $value;
	}

	public function directories( string $search = '', int $page = 1 ): array {
		if ( $page < 1 || $page > 2147483647 || mb_strlen( $search ) > 100 ) {
			throw new \InvalidArgumentException( 'Choose a valid directory page and search.' ); }
		$args  = array(
			'taxonomy'   => ATBDP_DIRECTORY_TYPE,
			'hide_empty' => false,
			'search'     => $search,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		$count = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
		$terms = get_terms(
			array_merge(
				$args,
				array(
					'number' => self::PAGE_SIZE,
					'offset' => ( $page - 1 ) * self::PAGE_SIZE,
				)
			)
		);
		if ( is_wp_error( $count ) || is_wp_error( $terms ) ) {
			throw new \RuntimeException( 'Directory inventory is unavailable.' ); }
		return array(
			'items'    => array_map( array( $this, 'summary' ), $terms ),
			'page'     => $page,
			'per_page' => self::PAGE_SIZE,
			'total'    => (int) $count,
			'has_more' => $page * self::PAGE_SIZE < (int) $count,
		);
	}

	/** A small status page avoids loading every directory definition eagerly.
	 *
	 * @throws \InvalidArgumentException For an invalid search or page.
	 * @throws \RuntimeException When native directory metadata is unavailable.
	 */
	public function overview( string $search = '', int $page = 1 ): array {
		if ( $page < 1 || $page > 2147483647 || mb_strlen( $search ) > 100 ) {
			throw new \InvalidArgumentException( 'Choose a valid directory search.' ); }
		$args  = array(
			'taxonomy'   => ATBDP_DIRECTORY_TYPE,
			'hide_empty' => false,
			'search'     => $search,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		$count = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
		$terms = get_terms(
			array_merge(
				$args,
				array(
					'number' => 5,
					'offset' => ( $page - 1 ) * 5,
				)
			)
		);
		if ( is_wp_error( $count ) || is_wp_error( $terms ) ) {
			throw new \RuntimeException( 'Directory status is unavailable.' ); }
		$items = array();
		foreach ( $terms as $term ) {
			$review       = DirectoryReview::state( (int) $term->term_id );
			$verification = $review['reason'] ? array(
				'state'   => $review['state'],
				'reason'  => $review['reason'],
				'receipt' => DirectoryReview::record( (int) $term->term_id )['verification'] ?? null,
			) : Verification::status( (int) $term->term_id );
			$items[]      = array_merge(
				$this->summary( $term ),
				array(
					'review'       => $review,
					'verification' => $verification,
				)
			);
		}
		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => 5,
			'has_more' => $page * 5 < (int) $count,
			'total'    => (int) $count,
		);
	}

	public function selected(): array {
		$result = array();
		foreach ( Settings::directory_ids( 0, self::PAGE_SIZE ) as $id ) {
			$term     = get_term( $id, ATBDP_DIRECTORY_TYPE );
			$result[] = $term instanceof \WP_Term ? $this->summary( $term ) : array(
				'id'             => $id,
				'name'           => 'Removed directory #' . $id,
				'eligible'       => false,
				'reason'         => 'directory_unavailable',
				'fields'         => array(),
				'index_url'      => Publication::directory_url( $id ),
				'title_excluded' => in_array( 'title', Settings::excluded( $id ), true ),
			);
		}
		return $result;
	}

	public function summary( \WP_Term $term ): array {
		$id     = (int) $term->term_id;
		$reason = get_directorist_option( 'disable_single_listing', false ) ? 'single_listing_disabled' : ( NativeFacts::custom_page( $id ) ? 'custom_single_page_visibility_unverified' : '' );
		return array(
			'id'             => $id,
			'name'           => self::label( $term->name ),
			'eligible'       => '' === $reason,
			'reason'         => $reason,
			'fields'         => array(),
			'index_url'      => Publication::directory_url( $id ),
			'title_excluded' => in_array( 'title', Settings::excluded( $id ), true ),
			'enabled'        => DirectoryReview::settings( $id )['enabled'],
			'reviewed_at'    => DirectoryReview::record( $id )['reviewed_at'] ?? null,
		);
	}

	/** These IDs mirror the native public schema, including unsupported dispositions. */
	private static function field_id( string $key, array $field ): string {
		$map = array(
			'title'           => 'title',
			'description'     => 'description',
			'excerpt'         => 'excerpt',
			'category'        => 'categories',
			'location'        => 'locations',
			'tag'             => 'tags',
			'image_upload'    => 'images',
			'video'           => 'video_url',
			'social_info'     => 'social',
			'pricing'         => 'pricing',
			'map'             => 'map',
			'ai_price'        => 'price',
			'ai_distance'     => 'distance',
			'ai_open_now'     => 'open_now',
			'ai_hours'        => 'hours',
			'ai_faqs'         => 'faqs',
			'ai_review_count' => 'review_count',
			'ai_rating'       => 'rating',
		);
		$id  = $map[ $field['widget_name'] ?? '' ] ?? sanitize_key( (string) ( $field['field_key'] ?? $key ) );
		return preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) ? $id : '';
	}

	public function field_audit( int $directory, int $listing = 0, int $page = 1 ): array {
		if ( ! directorist_is_directory( $directory ) ) {
			throw new \InvalidArgumentException( 'The selected directory is unavailable.' ); }
		return NativeFacts::anonymous(
			function () use ( $directory, $listing, $page ): array {
				$post = $listing ? get_post( $listing ) : null;
				if ( $listing && ! $post instanceof \WP_Post ) {
					throw new \InvalidArgumentException( 'The native listing is no longer available.' );
				}
				$data       = $listing ? array(
					'listing_id'        => $listing,
					'listing_owner_id'  => (int) $post->post_author,
					'directory_type_id' => $directory,
				) : array();
				$native     = directorist_get_listing_form_fields( $directory, array_merge( array( 'source' => 'directorist_ai' ), $data ) );
				$layout     = directorist_single_listings_contents( $directory, $data );
				$header     = directorist_single_listing_header( $directory, $data );
				$placements = DisclosurePolicy::placements( $native, $layout, $header );
				$fields     = array();
				$seen       = array();
				foreach ( $native as $key => $field ) {
					if ( ! is_array( $field ) || empty( $field['widget_name'] ) || in_array( $field['widget_name'], array( 'business_hours', 'faqs' ), true ) ) {
						continue; }
					$id = self::field_id( (string) $key, $field );
					if ( ! $id || isset( $seen[ $id ] ) ) {
						continue; }
					$seen[ $id ] = true;
					$reason      = ! DisclosurePolicy::public_definition( $field ) ? 'admin_or_restricted' : ( ! in_array( $field['widget_name'], DisclosurePolicy::WIDGETS, true ) ? 'unsupported_public_adapter' : ( ! isset( $placements[ $key ] ) ? 'absent_from_public_layout' : 'current_listing_visibility_required' ) );
					$label       = self::label( (string) ( $field['label'] ?? '' ) );
					$fields[]    = array(
						'id'        => $id,
						'label'     => $label ? $label : ucwords( str_replace( '_', ' ', (string) ( $field['widget_name'] ?? $id ) ) ),
						'reason'    => $reason,
						'supported' => 'current_listing_visibility_required' === $reason,
						'included'  => ! in_array( $id, Settings::excluded( $directory ), true ),
						'critical'  => 'title' === $id,
					);
				}
				$extensions = \DirectoristAIPro\Chat\Workflows\PublicListingFacts::definitions( $directory, $native );
				foreach ( array(
					'hours' => 'Business hours',
					'faqs'  => 'FAQs',
				) as $id => $label ) {
						$supported = isset( $extensions[ 'directorist_ai_' . $id ] );
					$widget        = 'hours' === $id ? 'business_hours' : 'faqs';
					if ( ! $supported && ! array_filter( $native, static fn( $field ): bool => is_array( $field ) && ( $field['widget_name'] ?? '' ) === $widget ) ) {
						continue; }
						$fields[] = array(
							'id'        => $id,
							'label'     => $label,
							'reason'    => $supported ? 'verified_extension_visibility_required' : 'unsupported_public_adapter',
							'supported' => $supported,
							'included'  => ! in_array( $id, Settings::excluded( $directory ), true ),
							'critical'  => false,
						);
				}
				return array(
					'fields'           => array_slice( $fields, ( $page - 1 ) * self::MAX_FIELDS, self::MAX_FIELDS ),
					'field_total'      => count( $fields ),
					'fields_truncated' => count( $fields ) > self::MAX_FIELDS,
					'field_limit'      => self::MAX_FIELDS,
					'field_page'       => $page,
					'field_has_more'   => $page * self::MAX_FIELDS < count( $fields ),
				);
			}
		);
	}

	public function directory( int $id, int $page = 1 ): array {
		if ( $page < 1 || $page > 2147483647 ) {
			throw new \InvalidArgumentException( 'Choose a valid field page.' ); }
		$term = get_term( $id, ATBDP_DIRECTORY_TYPE );
		if ( ! $term instanceof \WP_Term ) {
			throw new \InvalidArgumentException( 'The selected directory is unavailable.' ); }
		$review       = DirectoryReview::state( $id );
		$verification = ! $review['reason'] ? Verification::status( $id ) : array(
			'state'   => $review['state'],
			'reason'  => $review['reason'],
			'receipt' => DirectoryReview::record( $id )['verification'] ?? null,
		);
		$data         = array_merge(
			$this->summary( $term ),
			$this->field_audit( $id, 0, $page ),
			array(
				'review'             => $review,
				'verification'       => $verification,
				'verification_token' => Verification::token( $id ),
			)
		);
		if ( ! hash_equals( $review['schema_hash'], DirectoryReview::schema_hash( $id ) ) || ! hash_equals( $review['review_token'], DirectoryReview::state( $id )['review_token'] ) ) {
			throw new \DomainException( 'Directory choices changed while loading. Refresh and review again.' ); }
		return $data;
	}

	public function listings( int $directory, string $search = '', int $page = 1 ): array {
		if ( ! directorist_is_directory( $directory ) || $page < 1 || $page > 2147483647 || mb_strlen( $search ) > 100 ) {
			throw new \InvalidArgumentException( 'Choose an existing directory and a bounded listing search.' ); }
		$query = new \WP_Query(
			array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => self::PAGE_SIZE,
				'paged'          => $page,
				's'              => $search,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'cache_results'  => false,
				'tax_query'      => array(
					array(
						'taxonomy' => ATBDP_DIRECTORY_TYPE,
						'field'    => 'term_id',
						'terms'    => array( $directory ),
					),
				),
			)
		);
		$items = array();
		foreach ( $query->posts as $id ) {
			if ( ! current_user_can( 'read_post', $id ) ) {
				continue; }
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue; }
			$items[] = array(
				'id'           => (int) $id,
				'title'        => self::label( $post->post_title ),
				'modified_gmt' => $post->post_modified_gmt,
			);
		}
		return array(
			'items'           => $items,
			'page'            => $page,
			'per_page'        => self::PAGE_SIZE,
			'has_more'        => $page < (int) $query->max_num_pages,
			'candidate_total' => (int) $query->found_posts,
			'note'            => 'Published native candidates. Current public eligibility is checked for the selected listing.',
		);
	}

	public function listing( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type || ! current_user_can( 'read_post', $id ) ) {
			throw new \InvalidArgumentException( 'This native listing is unavailable to the current administrator.' ); }
		$directory   = (int) directorist_get_listing_directory( $id );
		$policy_hash = Settings::policy_hash();
		$facts       = new NativeFacts();
		$diagnostics = array();
		$capture     = $facts->capture( $id, $diagnostics );
		$reason      = NativeFacts::anonymous( fn(): string => $facts->eligibility_reason( $id ) );
		if ( in_array( $reason, array( 'eligible', 'directory_review_required' ), true ) && ! $capture ) {
			$reason = in_array( 'title', Settings::excluded( $directory ), true ) ? 'required_title_excluded' : ( 'eligible' === $reason ? 'current_public_visibility_required' : $reason ); }
		$audit  = directorist_is_directory( $directory ) ? $this->field_audit( $directory, $id ) : array(
			'fields'           => array(),
			'field_total'      => 0,
			'fields_truncated' => false,
			'field_limit'      => self::MAX_FIELDS,
		);
		$public = array();
		foreach ( $capture['fields'] ?? array() as $field ) {
			$public[ $field['id'] ] = $field; }
		foreach ( $audit['fields'] as &$field ) {
			$field['reason'] = isset( $public[ $field['id'] ] ) ? 'published' : ( in_array( $field['id'], Settings::excluded( $directory ), true ) ? 'excluded_by_policy' : ( $diagnostics[ $field['id'] ] ?? ( $field['supported'] ? 'current_public_visibility_required' : $field['reason'] ) ) );
			if ( isset( $public[ $field['id'] ] ) ) {
				$field['value'] = self::projected_value( $public[ $field['id'] ]['value'] ); }
		} unset( $field );
		$index_cursor = max( 0, $id - 1 );
		$entry_url    = Publication::entry_url();
		$final        = $capture ? $facts->capture( $id ) : null;
		if ( $capture && ( ! $final || ! hash_equals( $capture['revision'], $final['revision'] ) || ! hash_equals( $policy_hash, Settings::policy_hash() ) ) ) {
			$capture = null;
			$reason  = 'source_changed_during_audit';
			foreach ( $audit['fields'] as &$field ) {
				unset( $field['value'] );
				if ( 'published' === $field['reason'] ) {
					$field['reason'] = $reason;
				}
			} unset( $field ); }
		$canonical = $capture['canonical'] ?? NativeFacts::anonymous( static fn(): string => (string) get_permalink( $id ) );
		$body      = $capture ? Markdown::listing( $capture ) : '';
		if ( strlen( $body ) > Publication::MAX_DOCUMENT_BYTES ) {
			$capture = null;
			$reason  = 'document_too_large';
			foreach ( $audit['fields'] as &$field ) {
				unset( $field['value'] );
				if ( 'published' === $field['reason'] ) {
					$field['reason'] = 'document_too_large';
				}
			} unset( $field ); }
		return array_merge(
			$audit,
			array(
				'id'                      => $id,
				'directory_id'            => $directory,
				'published'               => null !== $capture,
				'reason'                  => $capture ? 'eligible' : $reason,
				'source_modified_gmt'     => $capture['modified_gmt'] ?? null,
				'canonical_url'           => Markdown::url( $canonical ),
				'listing_url'             => Publication::listing_url( $id ),
				'index_page'              => null,
				'index_cursor'            => $index_cursor,
				'index_url'               => Publication::cursor_url( $directory, $index_cursor ),
				'directory_discovery_url' => Publication::discovery_url( max( 0, $directory - 1 ) ),
				'entry_url'               => $entry_url,
				'scoped_entry_url'        => home_url( '/directorist/llms.txt' ),
				'root_url'                => home_url( '/llms.txt' ),
				'inspected_at'            => gmdate( DATE_RFC3339 ),
				'saved_policy_hash'       => $policy_hash,
				'directory_policy_hash'   => DirectoryReview::policy_hash( $directory ),
				'schema_hash'             => DirectoryReview::schema_hash( $directory ),
				'expected_markdown'       => $capture ? $body : null,
				'body_sha256'             => $capture ? hash( 'sha256', $body ) : null,
				'provider_calls'          => 0,
			)
		);
	}
}
