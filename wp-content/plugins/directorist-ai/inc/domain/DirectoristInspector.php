<?php
/**
 * Narrow Directorist 8.9.4 directory/listing inspection adapter.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Chat\Workflows\PublicListingFacts;
use DirectoristAIPro\Validation\ContractException;

/** Native form definitions choose fields; ambient request parameters choose nothing. */
final class DirectoristInspector {
	/** Request-only reuse while history verifies the same public inventory. */
	private static array $inventory_cache = array();
	private static bool $inventory_hooks = false;

	public static function invalidate_inventory( $meta_id, $post_id, $key ): void {
		if ( '_directory_type' === $key ) { self::$inventory_cache = array(); }
	}

	/**
	 * Active query depth; nested queries permanently disable the outer refresh memo.
	 *
	 * @var int
	 */
	private int $query_depth = 0;

	/**
	 * Selected raw term caches refreshed during this call, never filtered term objects.
	 *
	 * @var array|null
	 */
	private ?array $query_term_refresh = null;

	/**
	 * Initial native t/tt digests covering selected, unambiguous term identities.
	 *
	 * @var array|null
	 */
	private ?array $query_term_sources = null;

	/**
	 * A nested callback cannot restore an earlier outer cache assumption.
	 *
	 * @var bool
	 */
	private bool $query_term_disabled = false;

	/**
	 * Native bulk term results keyed by taxonomy and exact selected listing.
	 *
	 * @var array|null
	 */
	private ?array $query_term_batch = null;

	/** Whether the pinned minimum Directorist read API is available. */
	public function available(): bool {
		return defined( 'ATBDP_VERSION' ) && version_compare( ATBDP_VERSION, '8.9.4', '>=' ) && defined( 'ATBDP_POST_TYPE' ) && defined( 'ATBDP_DIRECTORY_TYPE' ) && function_exists( 'directorist_get_listing_form_fields' ) && function_exists( 'directorist_get_listing_directory' );
	}

	/**
	 * Bounded display labels from the exact public descriptor path, without rules.
	 *
	 * @param int $directory_id Current native directory identity.
	 * @return array<string,string> Display metadata, never executable field authority.
	 */
	public function public_field_labels( int $directory_id ): array {
		if ( ! $this->available() || ! directorist_is_directory( $directory_id ) ) {
			return array();
		}
		$result = array();
		$schema = $this->field_schema( null, $directory_id, 'public_search' );
		foreach ( array_slice( $schema['fields'], 0, 100 ) as $field ) {
			$result[ $field->field_id ] = mb_substr( $field->label, 0, 160 );
		}
		return $result;
	}

	/**
	 * Check one directory purpose without granting rights from the purpose string.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param string  $purpose Requested read purpose.
	 */
	public function can_describe( Context $context, int $directory_id, string $purpose ): bool {
		if ( ! $this->available() || ! in_array( $purpose, array( 'public_search', 'submission', 'management' ), true ) || ! directorist_is_directory( $directory_id ) ) {
			return false;
		}
		if ( 'public_search' === $purpose ) {
			return true;
		}
		if ( 'management' === $purpose ) {
			return $context->actor()->can( 'manage_options' );
		}
		$post_type         = get_post_type_object( ATBDP_POST_TYPE );
		$create_capability = $post_type && isset( $post_type->cap->create_posts ) ? $post_type->cap->create_posts : 'edit_posts';
		return ! $context->actor()->is_guest() && ( $this->can_edit_listings( $context ) || $context->actor()->can( $create_capability ) );
	}

	/** Count public inventory without hydrating every listing's custom fields. */
	public function public_listing_count( Context $context, int $directory_id ): ?int {
		$context->assert_execution_identity();
		if ( ! $this->can_describe( $context, $directory_id, 'public_search' ) ) {
			throw new ContractException( 'unauthorized', 'Public directory inventory is unavailable.' );
		}
		if ( ! self::$inventory_hooks ) {
			foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) { add_action( $hook, array( self::class, 'invalidate_inventory' ), 10, 3 ); }
			self::$inventory_hooks = true;
		}
		$key = implode( '|', array( get_current_blog_id(), $context->actor()->owner()->reference(), $directory_id, apply_filters( 'wpml_current_language', '' ), get_locale(), wp_cache_get_last_changed( 'posts' ) ) );
		$cached = self::$inventory_cache[ $key ] ?? null;
		if ( is_array( $cached ) && microtime( true ) - $cached['time'] < 1.0 ) { return $cached['count']; }
		$get = $_GET;
		$post = $_POST;
		$request = $_REQUEST;
		$_GET = $_POST = $_REQUEST = array();
		try {
			$membership = array( array( 'key' => '_directory_type', 'value' => $directory_id, 'compare' => '=', 'type' => 'NUMERIC' ) );
			// Directorist's native listings page counts this metadata query. A
			// total needs no custom-field hydration or search-candidate expansion.
			$query = new \WP_Query( array(
				'post_type' => ATBDP_POST_TYPE, 'post_status' => 'publish',
				'has_password' => false, 'posts_per_page' => 1, 'fields' => 'ids',
				'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => false,
				'ignore_sticky_posts' => true, 'suppress_filters' => ! defined( 'ICL_SITEPRESS_VERSION' ),
				'cache_results' => false, 'update_post_term_cache' => false, 'update_post_meta_cache' => false,
				'meta_query' => $membership,
			) );
			if ( ATBDP_POST_TYPE !== $query->get( 'post_type' ) || 'publish' !== $query->get( 'post_status' ) || false !== $query->get( 'has_password' ) || $membership !== $query->get( 'meta_query' ) ) { return null; }
			if ( $query->posts && ! $this->query_eligible( $context, $directory_id, 'public', get_post( (int) $query->posts[0] ) ) ) { return null; }
			$count = (int) $query->found_posts;
			if ( $count < 0 || ( ! $query->posts && 0 !== $count ) ) { return null; }
			if ( count( self::$inventory_cache ) >= 80 ) { self::$inventory_cache = array(); }
			self::$inventory_cache[ $key ] = array( 'count' => $count, 'time' => microtime( true ) );
			return $count;
		} finally {
			$_GET = $get;
			$_POST = $post;
			$_REQUEST = $request;
		}
	}

	/**
	 * Whether the current actor has Directorist's native collection edit right.
	 *
	 * @param Context $context Current actor/profile.
	 */
	public function can_edit_listings( Context $context ): bool {
		$post_type  = $this->available() ? get_post_type_object( ATBDP_POST_TYPE ) : null;
		$capability = $post_type && isset( $post_type->cap->edit_posts ) ? $post_type->cap->edit_posts : 'edit_posts';
		return $context->actor()->can( $capability );
	}

	/**
	 * Native collection creation capability; directory and plan remain per-request checks.
	 *
	 * @param Context $context Reviewed input.
	 */
	public function can_create_listings( Context $context ): bool {
		$post_type  = $this->available() ? get_post_type_object( ATBDP_POST_TYPE ) : null;
		$capability = $post_type && isset( $post_type->cap->create_posts ) ? $post_type->cap->create_posts : 'edit_posts';
		return ! $context->actor()->is_guest() && $context->actor()->can( $capability );
	}

	/**
	 * Native creation authority and currently usable plan, independent of edit rights.
	 *
	 * @param Context $context Reviewed input.
	 * @param int     $directory_id Reviewed input.
	 * @throws \DirectoristAIPro\Validation\ContractException When native validation fails.
	 */
	public function creation_eligibility( Context $context, int $directory_id ): object {
		if ( ! $this->available() || $context->actor()->is_guest() || ! directorist_is_directory( $directory_id ) ) {
			throw new ContractException( 'unauthorized', 'Listing creation is unavailable.' );
		}
		$post_type  = get_post_type_object( ATBDP_POST_TYPE );
		$capability = $post_type && isset( $post_type->cap->create_posts ) ? $post_type->cap->create_posts : 'edit_posts';
		$user_id    = $context->actor()->user_id();
		$user_type  = get_user_meta( $user_id, '_user_type', true );
		if ( ! $context->actor()->can( $capability ) || in_array( $user_type, array( 'general', 'become_author' ), true ) ) {
			throw new ContractException( 'unauthorized', 'Listing creation is unavailable.' );
		}
		$plan_id    = null;
		$package_id = null;
		if ( function_exists( 'is_fee_manager_active' ) && is_fee_manager_active() ) {
			if ( ! function_exists( 'directorist_user_package_repository' ) || ! function_exists( 'directorist_get_pricing_plan_by_id' ) || ! function_exists( 'directorist_plan_has_listing_quota' ) ) {
				throw new ContractException( 'unsupported_dependency', 'Current pricing-plan eligibility cannot be verified.' );
			}
			$packages = directorist_user_package_repository()->get_active_packages_for_directory( $user_id, $directory_id );
			if ( ! is_array( $packages ) || 1 !== count( $packages ) ) {
				throw new ContractException( 'unauthorized', 'Select one active listing plan in Directorist before creating a listing.' );
			}
			$package    = reset( $packages );
			$plan_id    = (int) ( $package->plan_id ?? 0 );
			$package_id = (int) ( $package->id ?? 0 );
			$plan       = $plan_id > 0 ? directorist_get_pricing_plan_by_id( $plan_id ) : null;
			if ( ! $plan || ! directorist_plan_has_listing_quota( $plan ) || ! apply_filters( 'directorist_has_plan_remaining_quota', true, $plan, false ) ) {
				throw new ContractException( 'unauthorized', 'The selected listing plan has no available quota.' );
			}
			$pay_per_listing = 'pay_per_listing' === ( $package->plan_type ?? '' );
			if ( $pay_per_listing && function_exists( 'directorist_direct_purchase' ) && directorist_direct_purchase() && ( ! function_exists( 'directorist_has_paid_order_without_listing' ) || ! directorist_has_paid_order_without_listing( $plan_id ) ) ) {
				throw new ContractException( 'unauthorized', 'A paid listing order is required before creation.' );
			}
		} elseif ( function_exists( 'directorist_is_monetization_enabled' ) && directorist_is_monetization_enabled() && ! $context->actor()->can( 'manage_options' ) ) {
			throw new ContractException( 'unsupported_dependency', 'Current monetization eligibility cannot be verified.' );
		}
		$environment = $this->environment_hash( $directory_id );
		return (object) array(
			'plan_id'          => $plan_id,
			'package_id'       => $package_id,
			'environment_hash' => $environment,
			'version'          => hash( 'sha256', wp_json_encode( array( $environment, $user_id, $capability, $user_type, $plan_id, $package_id ) ) ),
		);
	}

	/**
	 * Complete submission descriptors plus fresh creation eligibility.
	 *
	 * @param Context $context Reviewed input.
	 * @param int     $directory_id Reviewed input.
	 */
	public function creation_context( Context $context, int $directory_id ): array {
		$eligibility = $this->creation_eligibility( $context, $directory_id );
		$directory   = $this->describe( $context, $directory_id, $context->actor()->can( 'manage_options' ) ? 'management' : 'submission' );
		$schema      = $this->field_schema( $context, $directory_id, $context->actor()->can( 'manage_options' ) ? 'management' : 'submission' );
		return array(
			'eligibility'      => $eligibility,
			'directory'        => $directory,
			'descriptors'      => $schema['fields'],
			'native_fields'    => $schema['native'],
			'environment_hash' => $directory->environment_hash
		);
	}

	/**
	 * Check every exact selected listing through native object rights.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param int[]   $listing_ids Exact selected listing IDs.
	 */
	public function can_inspect( Context $context, int $directory_id, array $listing_ids ): bool {
		if ( ! $this->available() || $context->actor()->is_guest() || ! directorist_is_directory( $directory_id ) || ! $listing_ids || count( $listing_ids ) > 20 || count( array_unique( $listing_ids ) ) !== count( $listing_ids ) ) {
			return false;
		}
		foreach ( $listing_ids as $listing_id ) {
			$post = get_post( $listing_id );
			if ( ! $post || ATBDP_POST_TYPE !== $post->post_type || directorist_get_listing_directory( $listing_id ) !== $directory_id || ! $context->actor()->can( 'edit_post', $listing_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Describe effective fields, groups, taxonomies and moderation defaults.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param string  $purpose Requested read purpose.
	 * @return object Strict directory description.
	 * @throws ContractException Directory or authority is unavailable.
	 */
	public function describe( Context $context, int $directory_id, string $purpose ): object {
		return $this->description_snapshot( $context, $directory_id, $purpose )['directory'];
	}

	/**
	 * In-process source snapshot; only the directory member belongs to the public contract.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param string  $purpose Requested read purpose.
	 * @throws ContractException Directory or authority is unavailable.
	 */
	public function description_snapshot( Context $context, int $directory_id, string $purpose ): array {
		if ( ! $this->can_describe( $context, $directory_id, $purpose ) ) {
			throw new ContractException( 'unauthorized', 'Directory description is unavailable.' );
		}
		$term = get_term( $directory_id, ATBDP_DIRECTORY_TYPE );
		if ( ! $term instanceof \WP_Term ) {
			throw new ContractException( 'not_found', 'Directory is unavailable.' );
		}
		$schema      = $this->field_schema( $context, $directory_id, $purpose );
		$taxonomies  = $this->taxonomies( $directory_id, $schema['fields'] );
		$limitations = array_merge( $schema['limitations'], $taxonomies['limitations'] );
		$complete    = $schema['complete'] && ! $taxonomies['limitations'];
		$groups      = $this->groups( $directory_id, $schema['source_keys'] );
		$hash_data   = (object) array(
			'fields'     => $schema['fields'],
			'groups'     => $groups,
			'taxonomies' => $taxonomies['items'],
			'purpose'    => $purpose,
		);
		$directory   = (object) array(
			'contract'         => 'directorist-ai/directory-v1',
			'directory'        => (object) array(
				'id'   => $directory_id,
				'name' => $term->name,
				'slug' => $term->slug,
			),
			'purpose'          => $purpose,
			'schema_hash'      => $this->hash( $hash_data ),
			'environment_hash' => $this->environment_hash( $directory_id ),
			'complete'         => $complete,
			'groups'           => $groups,
			'fields'           => $schema['fields'],
			'taxonomies'       => $taxonomies['items'],
			'moderation'       => (object) $this->isolated(
				static fn(): array => array(
					'create_status' => (string) directorist_get_listing_create_status( $directory_id ),
					'edit_status'   => (string) directorist_get_listing_edit_status( $directory_id ),
				)
			),
			'limitations'      => array_values( array_unique( $limitations ) ),
		);
		$sources     = $this->query_sources( $schema );
		return array(
			'directory'          => $directory,
			'sources'            => $sources,
			'source_fingerprint' => hash( 'sha256', serialize( $sources ) ),
			'sources_supported'  => $this->query_sources_supported( $sources ),
		);
	}

	/**
	 * Preserve exact dispatch inputs without exposing configuration or invoking object serializers.
	 *
	 * @param array $schema Effective native field schema.
	 */
	private function query_sources( array $schema ): array {
		$sources = array();
		foreach ( $schema['source_keys'] as $source_key => $field_id ) {
			$field  = $schema['native'][ $field_id ];
			$source = array( 'source_key' => $source_key );
			foreach ( array( 'widget_name', 'field_key', 'type', 'only_for_admin' ) as $key ) {
				$value          = $field[ $key ] ?? null;
				$source[ $key ] = array(
					'present' => array_key_exists( $key, $field ),
					'type'    => gettype( $value ),
					'value'   => is_scalar( $value ) || null === $value ? $value : null,
				);
			}
			$sources[ $field_id ] = $source;
		}
		return $sources;
	}

	/**
	 * A projected unsupported value cannot attest its original object's contents or behavior.
	 *
	 * @param array $sources Private projected dispatch inputs.
	 */
	private function query_sources_supported( array $sources ): bool {
		foreach ( $sources as $source ) {
			if ( ! is_string( $source['source_key'] ) && ! is_int( $source['source_key'] ) ) {
				return false;
			}
			foreach ( array( 'widget_name', 'field_key', 'type', 'only_for_admin' ) as $key ) {
				if ( ! in_array( $source[ $key ]['type'], array( 'NULL', 'boolean', 'integer', 'double', 'string' ), true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Inspect selected editable listings without a query or global post context.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param int[]   $listing_ids Exact selected listing IDs.
	 * @return object Strict listing facts.
	 * @throws ContractException Selection or authority is unavailable.
	 */
	public function inspect( Context $context, int $directory_id, array $listing_ids ): object {
		if ( ! $this->can_inspect( $context, $directory_id, $listing_ids ) ) {
			throw new ContractException( 'unauthorized', 'Listing inspection is unavailable.' );
		}
		$purpose     = $context->actor()->can( 'manage_options' ) ? 'management' : 'submission';
		$schema      = $this->field_schema( $context, $directory_id, $purpose );
		$taxonomies  = $this->taxonomies( $directory_id, $schema['fields'] );
		$groups      = $this->groups( $directory_id, $schema['source_keys'] );
		$schema_hash = $this->hash(
			(object) array(
				'fields'     => $schema['fields'],
				'groups'     => $groups,
				'taxonomies' => $taxonomies['items'],
				'purpose'    => $purpose,
			)
		);
		$listings    = array();
		$limitations = array_merge( $schema['limitations'], $taxonomies['limitations'] );
		$complete    = $schema['complete'] && ! $taxonomies['limitations'];
		foreach ( $listing_ids as $listing_id ) {
			$post        = get_post( $listing_id );
			$inspection  = $this->listing( $post, $schema['native'], $schema_hash );
			$listings[]  = $inspection;
			$complete    = $complete && $inspection->complete;
			$limitations = array_merge( $limitations, $inspection->limitations );
		}
		return (object) array(
			'contract'     => 'directorist-ai/listings-v1',
			'directory_id' => $directory_id,
			'schema_hash'  => $schema_hash,
			'complete'     => $complete,
			'listings'     => $listings,
			'limitations'  => array_values( array_unique( $limitations ) ),
		);
	}

	/**
	 * Hydrate an already bounded, eligibility-scoped query result.
	 *
	 * This is an in-process adapter boundary for the query service. It rechecks
	 * every candidate after WP_Query so native filters cannot weaken dataset,
	 * directory or actor scope.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param string  $dataset Authorized public, own or management dataset.
	 * @param int[]   $listing_ids Bounded candidate IDs.
	 * @param bool    $trusted_batch_terms Request batch only inside a pinned active query.
	 * @param array|null $field_ids Internal predicate/order projection; absent from public ability inputs.
	 * @param string|null $projected_schema_hash Internal traversal's before/after-fenced catalog hash.
	 * @return object Strict listing facts.
	 * @throws ContractException Invalid or unauthorized candidates.
	 */
	public function query_facts( Context $context, int $directory_id, string $dataset, array $listing_ids, bool $trusted_batch_terms = false, ?array $field_ids = null, ?string $projected_schema_hash = null ): object {
		++$this->query_depth;
		if ( $this->query_depth > 1 ) {
			$this->query_term_disabled = true;
			$this->query_term_refresh  = null;
			$this->query_term_sources  = null;
			$this->query_term_batch    = null;
		}
		try {
			return $this->hydrate_query_facts( $context, $directory_id, $dataset, $listing_ids, $trusted_batch_terms && ListingQueryService::authorizes_native_term_batch( $this ), $field_ids, $projected_schema_hash );
		} finally {
			--$this->query_depth;
			if ( 0 === $this->query_depth ) {
				$this->query_term_refresh  = null;
				$this->query_term_sources  = null;
				$this->query_term_batch    = null;
				$this->query_term_disabled = false;
			}
		}
	}

	/**
	 * Hydrate one bounded query, optionally sharing only raw cache refreshes.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param string  $dataset Authorized dataset.
	 * @param int[]   $listing_ids Bounded candidate IDs.
	 * @param bool    $trusted_batch_terms Active query batch authorization.
	 * @param array|null $field_ids Current allowed fields needed by an internal query pass.
	 * @param string|null $projected_schema_hash Reused only inside a fully catalog-fenced traversal.
	 * @throws ContractException Invalid, changed or unauthorized sources.
	 */
	private function hydrate_query_facts( Context $context, int $directory_id, string $dataset, array $listing_ids, bool $trusted_batch_terms, ?array $field_ids = null, ?string $projected_schema_hash = null ): object {
		wp_cache_delete( $directory_id, 'terms' );
		wp_cache_delete( $directory_id, 'term_meta' );
		$purpose = 'public' === $dataset ? 'public_search' : ( 'management' === $dataset ? 'management' : 'submission' );
		if ( ! in_array( $dataset, array( 'public', 'own', 'management' ), true ) || ! $this->can_describe( $context, $directory_id, $purpose ) || count( $listing_ids ) > 1000 || count( array_unique( $listing_ids ) ) !== count( $listing_ids ) ) {
			throw new ContractException( 'unauthorized', 'Listing query facts are unavailable.' );
		}
		$schema      = $this->field_schema( $context, $directory_id, $purpose );
		$native_fields = $schema['native'];
		if ( null !== $projected_schema_hash && ( null === $field_ids || ! preg_match( '/^[a-f0-9]{64}$/D', $projected_schema_hash ) ) ) { throw new ContractException( 'invalid_query', 'Projected listing facts require an internal catalog snapshot.' ); }
		if ( null !== $field_ids ) {
			if ( count( $field_ids ) > 100 ) { throw new ContractException( 'invalid_query', 'Listing query field projection is invalid.' ); }
			foreach ( $field_ids as $field_id ) {
				if ( ! is_string( $field_id ) || ! isset( $native_fields[ $field_id ] ) ) { throw new ContractException( 'invalid_query', 'Listing query projected field is unavailable.' ); }
			}
			if ( count( array_unique( $field_ids ) ) !== count( $field_ids ) ) { throw new ContractException( 'invalid_query', 'Listing query field projection is invalid.' ); }
			$native_fields = array_intersect_key( $native_fields, array_fill_keys( $field_ids, true ) );
		}
		// Complete traversal owns full catalog checks before/after its projected
		// batches. Reuse that internal hash instead of reloading the directory's
		// entire vocabulary for each batch. Public allowlists and source fences
		// above/below remain fresh; released page facts use the ordinary full read.
		$taxonomies  = null === $projected_schema_hash ? $this->taxonomies( $directory_id, $schema['fields'] ) : array( 'items' => array(), 'limitations' => array() );
		$groups      = $this->groups( $directory_id, $schema['source_keys'] );
		$schema_hash = $projected_schema_hash ?? $this->hash(
			(object) array(
				'fields'     => $schema['fields'],
				'groups'     => $groups,
				'taxonomies' => $taxonomies['items'],
				'purpose'    => $purpose,
			)
		);
		$listings    = array();
		$limitations = array_merge( $schema['limitations'], $taxonomies['limitations'] );
		$complete    = $schema['complete'] && ! $taxonomies['limitations'];
		// Exact-ID bulk invalidation lets persistent caches pipeline the same
		// refresh, without a directory-sized series of individual network calls.
		foreach ( array_merge( array( 'posts', 'post_meta' ), array_map( static fn( string $taxonomy ): string => $taxonomy . '_relationships', array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ) ) ) as $cache_group ) {
			$this->delete_query_cache_batch( $listing_ids, $cache_group );
		}
		// Fresh bounded native post/meta snapshots; relationships stay uncached.
		// The end fence rejects observed drift; neither batch is an atomic snapshot.
		_prime_post_caches( $listing_ids, false, true );
		$identities = array();
		foreach ( $listing_ids as $listing_id ) {
			$identities[ $listing_id ] = $this->query_source_identity( $listing_id );
		}
		$taxonomy_identity = null;
		if ( 1 === $this->query_depth && ! $this->query_term_disabled ) {
			$taxonomy_identity = $this->query_taxonomy_identity( $listing_ids );
			if ( null !== $taxonomy_identity && 1 === $this->query_depth && ! $this->query_term_disabled ) {
				$this->query_term_refresh = array();
				$this->query_term_sources = $taxonomy_identity['terms'];
				if ( $trusted_batch_terms ) {
					$this->query_term_batch = $this->query_batch_terms( $listing_ids, $schema['native'], $taxonomy_identity );
					if ( 1 !== $this->query_depth || $this->query_term_disabled ) {
						$this->query_term_batch = null;
					}
				}
			}
		}
		foreach ( $listing_ids as $listing_id ) {
			$post = get_post( $listing_id );
			if ( ! $this->query_eligible( $context, $directory_id, $dataset, $post ) ) {
				throw new ContractException( 'unauthorized', 'Listing query candidate is unavailable.' );
			}
			$inspection  = $this->listing( $post, $native_fields, $schema_hash, 'public' !== $dataset );
			$listings[]  = $inspection;
			$complete    = $complete && $inspection->complete;
			$limitations = array_merge( $limitations, $inspection->limitations );
		}
		// Discard the first batch after hydration, then reject the entire result on drift.
		$this->delete_query_cache_batch( $listing_ids, 'posts' );
		$this->delete_query_cache_batch( $listing_ids, 'post_meta' );
		_prime_post_caches( $listing_ids, false, true );
		foreach ( $listing_ids as $listing_id ) {
			if ( ! hash_equals( $identities[ $listing_id ], $this->query_source_identity( $listing_id ) ) ) {
				throw new ContractException( 'stale_version', 'Listing query source changed during hydration.' );
			}
		}
		if ( null !== $taxonomy_identity ) {
			$current = $this->query_taxonomy_identity( $listing_ids );
			if ( null === $current || ! hash_equals( $taxonomy_identity['identity'], $current['identity'] ) ) {
				throw new ContractException( 'stale_version', 'Listing query taxonomy changed during hydration.' );
			}
		}
		return (object) array(
			'directory_id' => $directory_id,
			'schema_hash'  => $schema_hash,
			'complete'     => $complete,
			'listings'     => $listings,
			'limitations'  => array_values( array_unique( $limitations ) ),
		);
	}

	/** Same exact selected-ID invalidation on native and older cache drop-ins. */
	private function delete_query_cache_batch( array $ids, string $group ): void {
		if ( function_exists( 'wp_cache_delete_multiple' ) ) { wp_cache_delete_multiple( $ids, $group ); return; }
		foreach ( $ids as $id ) { wp_cache_delete( $id, $group ); }
	}

	/**
	 * Return trusted native field mappings beside the public inspection result.
	 *
	 * This is an in-process adapter contract and is never registered as a model
	 * or transport response.
	 *
	 * @param Context $context Current actor/profile.
	 * @param int     $directory_id Exact directory term ID.
	 * @param int     $listing_id Exact listing ID.
	 * @return array Inspection, native fields and environment identity.
	 */
	public function proposal_context( Context $context, int $directory_id, int $listing_id ): array {
		$inspection = $this->inspect( $context, $directory_id, array( $listing_id ) );
		$purpose    = $context->actor()->can( 'manage_options' ) ? 'management' : 'submission';
		$schema     = $this->field_schema( $context, $directory_id, $purpose );
		return array(
			'inspection'       => $inspection,
			'native_fields'    => $schema['native'],
			'descriptors'      => $schema['fields'],
			'environment_hash' => $this->environment_hash( $directory_id ),
		);
	}

	/**
	 * Private whole-source fence; unused metadata changes conservatively reject.
	 *
	 * No native source payload is persisted or exposed by this identity. Native
	 * metadata filters remain in effect. Delivery performs its own fresh reads;
	 * edits after the final observation remain outside this non-atomic fence.
	 *
	 * @param int $listing_id Exact bounded native listing ID.
	 * @return string Native post and metadata identity.
	 */
	private function query_source_identity( int $listing_id ): string {
		$post = get_post( $listing_id );
		// Serialization preserves private binary values and native types without JSON decoding.
		return hash(
			'sha256',
			serialize(
				array(
					'post'     => $post ? get_object_vars( $post ) : null,
					'metadata' => get_post_meta( $listing_id ),
				)
			)
		);
	}

	/**
	 * Build actor-specific field descriptors from the native ordered form.
	 *
	 * @param Context|null $context Current actor/profile or public-label-only read.
	 * @param int          $directory_id Exact directory term ID.
	 * @param string       $purpose Requested read purpose.
	 * @throws ContractException When a private descriptor read lacks an actor.
	 */
	private function field_schema( ?Context $context, int $directory_id, string $purpose ): array {
		if ( null === $context && 'public_search' !== $purpose ) {
			throw new ContractException( 'unauthorized', 'Only public labels may be read without an execution actor.' );
		}
		$native_fields = $this->isolated( static fn(): array => directorist_get_listing_form_fields( $directory_id, array( 'source' => 'directorist_ai' ) ) );
		if ( 'public_search' === $purpose && function_exists( 'directorist_is_review_enabled' ) && directorist_is_review_enabled() && class_exists( '\\Directorist\\Review\\Listing_Review_Meta' ) ) {
			$native_fields['directorist_ai_review_count'] = array(
				'widget_name' => 'ai_review_count',
				'field_key'   => \Directorist\Review\Listing_Review_Meta::FIELD_REVIEW_COUNT,
				'type'        => 'number',
				'label'       => 'Review count'
			);
			$native_fields['directorist_ai_rating']       = array(
				'widget_name' => 'ai_rating',
				'field_key'   => \Directorist\Review\Listing_Review_Meta::FIELD_AVG_RATING,
				'type'        => 'number',
				'label'       => 'Average rating'
			);
		}
		if ( 'public_search' === $purpose ) {
			$discovery = PublicListingFacts::definitions( $directory_id, $native_fields );
			foreach ( $native_fields as $key => $field ) {
				if ( is_array( $field ) && in_array( $field['widget_name'] ?? '', array( 'faqs', 'business_hours' ), true ) ) {
					unset( $native_fields[ $key ] ); }
			}
			$native_fields = array_merge( $native_fields, $discovery );
		}
		$fields            = array();
		$native            = array();
		$source_keys       = array();
		$limitations       = array();
		$admin             = 'public_search' !== $purpose && $context->actor()->can( 'manage_options' );
		$post_type         = get_post_type_object( ATBDP_POST_TYPE );
		$create_capability = $post_type && isset( $post_type->cap->create_posts ) ? $post_type->cap->create_posts : 'edit_posts';
		$editable          = 'public_search' !== $purpose && ! $context->actor()->is_guest() && ( $this->can_edit_listings( $context ) || $context->actor()->can( $create_capability ) );
		foreach ( $native_fields as $source_key => $field ) {
			if ( ! is_array( $field ) || empty( $field['widget_name'] ) || ( ! empty( $field['only_for_admin'] ) && ! $admin ) ) {
				continue;
			}
			$field_id = $this->field_id( (string) $source_key, $field );
			if ( '' === $field_id || isset( $native[ $field_id ] ) ) {
				$limitations[] = 'duplicate_or_invalid_field_id';
				continue;
			}
			$descriptor                 = (object) array(
				'field_id'          => $field_id,
				'widget'            => sanitize_key( (string) $field['widget_name'] ),
				'type'              => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
				'label'             => wp_strip_all_tags( (string) ( $field['label'] ?? $field_id ) ),
				'required'          => ! empty( $field['required'] ),
				'editable'          => $editable,
				'admin_only'        => ! empty( $field['only_for_admin'] ),
				'conditional_logic' => $this->conditional_logic( $field ),
				'allowed_values'    => $this->allowed_values( $field ),
				'limits'            => $this->limits( $field ),
			);
			$fields[]                   = $descriptor;
			$native[ $field_id ]        = $field;
			$source_keys[ $source_key ] = $field_id;
		}
		$eligible = array_fill_keys( array_keys( $native ), true );
		foreach ( $fields as $descriptor ) {
			if ( null === $descriptor->conditional_logic ) {
				continue;
			}
			$valid                         = true;
			$descriptor->conditional_logic = $this->condition_scope( $descriptor->conditional_logic, $source_keys, $eligible, $valid );
			if ( ! $valid ) {
				$descriptor->conditional_logic = null;
				$limitations[]                 = 'conditional_dependency_unavailable';
			}
		}
		if ( ! $fields ) {
			$limitations[] = 'directory_has_no_eligible_fields';
		}
		return array(
			'fields'      => $fields,
			'native'      => $native,
			'source_keys' => $source_keys,
			'complete'    => $fields && ! $limitations,
			'limitations' => $limitations,
		);
	}

	/**
	 * Preserve native group ordering while replacing internal keys with field IDs.
	 *
	 * @param int   $directory_id Exact directory term ID.
	 * @param array $source_keys Native-to-logical field map.
	 */
	private function groups( int $directory_id, array $source_keys ): array {
		$groups = $this->isolated( static fn(): array => directorist_get_listing_form_groups( $directory_id, array( 'source' => 'directorist_ai' ) ) );
		$result = array();
		foreach ( $groups as $group ) {
			$fields = array();
			foreach ( $group['fields'] ?? array() as $source_key ) {
				if ( isset( $source_keys[ $source_key ] ) ) {
					$fields[] = $source_keys[ $source_key ];
				}
			}
			if ( $fields ) {
				$result[] = (object) array(
					'label'  => wp_strip_all_tags( (string) ( $group['label'] ?? '' ) ),
					'fields' => $fields,
				);
			}
		}
		return $result;
	}

	/**
	 * Build bounded directory-specific taxonomy term choices.
	 *
	 * @param int      $directory_id Exact directory term ID.
	 * @param object[] $fields Eligible field descriptors.
	 */
	private function taxonomies( int $directory_id, array $fields ): array {
		$mapping     = array(
			'categories' => defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : '',
			'locations'  => defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : '',
			'tags'       => defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : '',
		);
		$present     = array_fill_keys( array_map( static fn( object $field ): string => $field->field_id, $fields ), true );
		$items       = array();
		$limitations = array();
		foreach ( $mapping as $field_id => $taxonomy ) {
			if ( ! isset( $present[ $field_id ] ) || ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms     = get_terms(
				array(
					'taxonomy'      => $taxonomy,
					'hide_empty'    => false,
					'number'        => 101,
					'cache_results' => false,
					'meta_query'    => array(
						'relation' => 'OR',
						array(
							'key'     => '_directory_type_' . $directory_id,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_directory_type',
							'value'   => 'i:' . $directory_id . ';',
							'compare' => 'LIKE',
						),
					),
				)
			);
			$terms     = is_wp_error( $terms ) ? array() : $terms;
			$terms     = $this->fresh_terms( $terms, $taxonomy );
			$truncated = count( $terms ) > 100;
			if ( $truncated ) {
				$limitations[] = 'taxonomy_terms_truncated:' . $field_id;
				$terms         = array_slice( $terms, 0, 100 );
			}
			$items[] = (object) array(
				'field_id'  => $field_id,
				'taxonomy'  => $taxonomy,
				'terms'     => array_map(
					static fn( \WP_Term $term ): object => (object) array(
						'id'        => (int) $term->term_id,
						'name'      => $term->name,
						'parent_id' => (int) $term->parent,
					),
					$terms
				),
				'truncated' => $truncated,
			);
		}
		return array(
			'items'       => $items,
			'limitations' => $limitations,
		);
	}

	/**
	 * Inspect one post through the already filtered field schema.
	 *
	 * @param \WP_Post $post Exact selected listing.
	 * @param array    $native_fields Eligible native field map.
	 * @param string   $schema_hash Actor-specific schema hash.
	 * @param bool     $editable Whether returned facts are editable in this dataset.
	 */
	private function listing( \WP_Post $post, array $native_fields, string $schema_hash, bool $editable = true ): object {
		$observed       = gmdate( DATE_RFC3339 );
		$fields         = array();
		$version_fields = array();
		foreach ( $native_fields as $field_id => $field ) {
			$fact             = $this->field_value( $post, $field_id, $field );
			$version_fields[] = (object) array(
				'field_id' => $field_id,
				'known'    => $fact['known'],
				'value'    => $fact['value'],
			);
			// Version identity continues to cover exact stored native state. Only
			// public release omits the inactive pricing branch; form/admin reads
			// retain both values so switching native pricing mode is lossless.
			if ( ! $editable && 'pricing' === ( $field['widget_name'] ?? null ) ) {
				$fact['value'] = PublicListingFacts::active_pricing( $fact['value'] ); }
			$fields[] = (object) array(
				'field_id' => $field_id,
				'known'    => $fact['known'],
				'value'    => $fact['value'],
				'editable' => $editable,
				'source'   => (object) array(
					'kind'        => $fact['kind'],
					'object_id'   => (string) $post->ID,
					'key'         => $fact['key'],
					'observed_at' => $observed,
				),
			);
		}
		$plan_id     = (int) get_post_meta( $post->ID, '_fm_plans', true );
		$limitations = $plan_id ? array( 'pricing_plan_constraints_require_extension_adapter' ) : array();
		$content     = (object) array(
			'status'  => $post->post_status,
			'fields'  => $version_fields,
			'plan_id' => $plan_id,
		);
		return (object) array(
			'id'          => (int) $post->ID,
			'status'      => $post->post_status,
			'author_id'   => (int) $post->post_author,
			'complete'    => ! $limitations,
			'version'     => (object) array(
				'modified_gmt' => mysql_to_rfc3339( $post->post_modified_gmt ),
				'content_hash' => $this->hash( $content ),
				'schema_hash'  => $schema_hash,
			),
			'plan'        => (object) array(
				'id'       => $plan_id ? $plan_id : null,
				'complete' => ! $plan_id,
			),
			'fields'      => $fields,
			'limitations' => $limitations,
		);
	}

	/**
	 * Recheck one post against the requested native dataset.
	 *
	 * @param Context        $context Current actor/profile.
	 * @param int            $directory_id Exact directory term ID.
	 * @param string         $dataset Authorized dataset.
	 * @param \WP_Post|false $post Candidate post.
	 */
	private function query_eligible( Context $context, int $directory_id, string $dataset, $post ): bool {
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type || directorist_get_listing_directory( $post->ID ) !== $directory_id ) {
			return false;
		}
		if ( 'public' === $dataset ) {
			return 'publish' === $post->post_status && '' === $post->post_password;
		}
		if ( 'own' === $dataset ) {
			return $context->actor()->user_id() === (int) $post->post_author && $context->actor()->can( 'edit_post', $post->ID );
		}
		return 'management' === $dataset && $context->actor()->can( 'manage_options' ) && $context->actor()->can( 'edit_post', $post->ID );
	}

	/**
	 * Read one configured field without accepting a caller-selected meta key.
	 *
	 * @param \WP_Post $post Exact selected listing.
	 * @param string   $field_id Stable logical field ID.
	 * @param array    $field Native field configuration.
	 */
	private function field_value( \WP_Post $post, string $field_id, array $field ): array {
		$widget = (string) $field['widget_name'];
		$key    = (string) ( $field['field_key'] ?? $field_id );
		switch ( $widget ) {
			case 'ai_price':
			case 'ai_distance':
			case 'ai_open_now':
			case 'ai_hours':
			case 'ai_faqs':
				return PublicListingFacts::fact( $post->ID, $widget );
			case 'ai_review_count':
				return array(
					'known' => true,
					'value' => (int) directorist_get_listing_review_count( $post->ID ),
					'kind'  => 'meta',
					'key'   => \Directorist\Review\Listing_Review_Meta::FIELD_REVIEW_COUNT
				);
			case 'ai_rating':
				$count = (int) directorist_get_listing_review_count( $post->ID );
				return array(
					'known' => $count > 0,
					'value' => $count > 0 ? \Directorist\Review\Listing_Review_Meta::get_rating( $post->ID ) : null,
					'kind'  => 'meta',
					'key'   => \Directorist\Review\Listing_Review_Meta::FIELD_AVG_RATING
				);
			case 'title':
				return array(
					'known' => true,
					'value' => $post->post_title,
					'kind'  => 'post',
					'key'   => 'post_title',
				);
			case 'description':
				return array(
					'known' => true,
					'value' => $post->post_content,
					'kind'  => 'post',
					'key'   => 'post_content',
				);
			case 'excerpt':
				$value = '' !== $post->post_excerpt ? $post->post_excerpt : get_post_meta( $post->ID, '_excerpt', true );
				return array(
					'known' => true,
					'value' => $value,
					'kind'  => 'post',
					'key'   => 'post_excerpt',
				);
			case 'category':
				return $this->term_fact( $post->ID, ATBDP_CATEGORY, 'categories' );
			case 'location':
				return $this->term_fact( $post->ID, ATBDP_LOCATION, 'locations' );
			case 'tag':
				return $this->term_fact( $post->ID, ATBDP_TAGS, 'tags' );
			case 'pricing':
				return array(
					'known' => true,
					'value' => (object) array(
						'type'  => is_callable( array( '\\Directorist\\Helper', 'pricing_type' ) ) ? \Directorist\Helper::pricing_type( $post->ID ) : get_post_meta( $post->ID, '_atbd_listing_pricing', true ),
						'price' => get_post_meta( $post->ID, '_price', true ),
						'range' => get_post_meta( $post->ID, '_price_range', true ),
					),
					'kind'  => 'meta',
					'key'   => 'pricing',
				);
			case 'image_upload':
				return array(
					'known' => true,
					'value' => (object) array(
						'preview_id'  => (int) directorist_get_listing_preview_image( $post->ID ),
						'gallery_ids' => array_values( array_map( 'intval', directorist_get_listing_gallery_images( $post->ID ) ) ),
						'preview'     => $this->public_listing_image( (int) directorist_get_listing_preview_image( $post->ID ) ),
					),
					'kind'  => 'meta',
					'key'   => 'listing_media',
				);
			case 'social_info':
				$value = get_post_meta( $post->ID, '_social', true );
				return array(
					'known' => metadata_exists( 'post', $post->ID, '_social' ),
					'value' => $this->normalize( $value ),
					'kind'  => 'meta',
					'key'   => 'social',
				);
			case 'map':
				return array(
					'known' => metadata_exists( 'post', $post->ID, '_manual_lat' ) || metadata_exists( 'post', $post->ID, '_manual_lng' ),
					'value' => (object) array(
						'hide_map'  => (bool) get_post_meta( $post->ID, '_hide_map', true ),
						'latitude'  => get_post_meta( $post->ID, '_manual_lat', true ),
						'longitude' => get_post_meta( $post->ID, '_manual_lng', true ),
					),
					'kind'  => 'meta',
					'key'   => 'map',
				);
		}
		$meta_key = '_' . sanitize_key( $key );
		$known    = metadata_exists( 'post', $post->ID, $meta_key );
		return array(
			'known' => $known,
			'value' => $known ? $this->typed_value( get_post_meta( $post->ID, $meta_key, true ), $field ) : null,
			'kind'  => 'meta',
			'key'   => ltrim( $meta_key, '_' ),
		);
	}

	/**
	 * Restore semantic scalar types using the trusted native field definition.
	 *
	 * @param mixed $value Native metadata value.
	 * @param array $field Native field configuration.
	 * @return mixed JSON-safe typed value.
	 */
	private function typed_value( $value, array $field ) {
		$type   = sanitize_key( (string) ( $field['type'] ?? '' ) );
		$widget = sanitize_key( (string) ( $field['widget_name'] ?? '' ) );
		if ( 'number' === $type && is_numeric( $value ) ) {
			return false === strpos( (string) $value, '.' ) ? (int) $value : (float) $value;
		}
		if ( 'checkbox' === $widget && $this->allowed_values( $field ) ) {
			$selected = is_array( $value ) ? $value : array();
			$result   = array();
			foreach ( $this->allowed_values( $field ) as $choice ) {
				foreach ( $selected as $entry ) {
					if ( is_scalar( $entry ) && (string) $choice->value === (string) $entry ) {
						$result[] = $choice->value;
						break;
					}
				}
			}
			return $result;
		}
		if ( in_array( $type, array( 'checkbox', 'toggle', 'switch' ), true ) || in_array( $widget, array( 'checkbox', 'toggle', 'switch' ), true ) ) {
			return in_array( $value, array( true, 1, '1', 'yes', 'on', 'true' ), true );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			foreach ( $this->allowed_values( $field ) as $choice ) {
				if ( (string) $choice->value === (string) $value ) {
					return $choice->value;
				}
			}
		}
		return $this->normalize( $value );
	}

	/**
	 * Resolve only an actual native public image, never a model-provided URL.
	 *
	 * @param int $attachment_id Native media identity from the selected listing.
	 */
	private function public_listing_image( int $attachment_id ): ?object {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! in_array( $attachment->post_status, array( 'inherit', 'publish' ), true ) || ! empty( $attachment->post_password ) || ! in_array( get_post_mime_type( $attachment ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return null;
		}
		if ( $attachment->post_parent ) {
			$parent = get_post( $attachment->post_parent );
			if ( ! $parent || 'publish' !== $parent->post_status || ! empty( $parent->post_password ) ) {
				return null;
			}
		}
		$image = wp_get_attachment_image_src( $attachment_id, 'medium_large' );
		if ( ! $image || ! is_string( $image[0] ) || ! in_array( wp_parse_url( $image[0], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || strtolower( (string) wp_parse_url( $image[0], PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) || wp_parse_url( $image[0], PHP_URL_USER ) || wp_parse_url( $image[0], PHP_URL_PASS ) ) {
			return null;
		}
		return (object) array(
			'id'     => $attachment_id,
			'url'    => esc_url_raw( $image[0] ),
			'alt'    => mb_substr( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ), 0, 240 ),
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	/**
	 * Return exact term identities without rendering links or descriptions.
	 *
	 * @param int    $listing_id Exact listing ID.
	 * @param string $taxonomy Registered Directorist taxonomy.
	 * @param string $key Stable logical field key.
	 */
	private function term_fact( int $listing_id, string $taxonomy, string $key ): array {
		$terms = null === $this->query_term_batch ? wp_get_object_terms( $listing_id, $taxonomy, array( 'cache_results' => false ) ) : ( $this->query_term_batch[ $taxonomy ][ $listing_id ] ?? array() );
		$terms = is_wp_error( $terms ) || false === $terms ? array() : $terms;
		$terms = $this->fresh_terms( $terms, $taxonomy );
		return array(
			'known' => true,
			'value' => array_map(
				static fn( \WP_Term $term ): object => (object) array(
					'id'        => (int) $term->term_id,
					'name'      => $term->name,
					'parent_id' => (int) $term->parent,
				),
				$terms
			),
			'kind'  => 'term',
			'key'   => $key,
		);
	}

	/**
	 * Discard only selected term objects; query-cache bypass does not refresh them.
	 *
	 * @param \WP_Term[] $terms Bounded native selection.
	 * @param string     $taxonomy Exact native taxonomy.
	 */
	private function fresh_terms( array $terms, string $taxonomy ): array {
		$fresh = array();
		foreach ( $terms as $term ) {
			$id      = (int) $term->term_id;
			$key     = hash( 'sha256', (string) $id ) . ':' . hash( 'sha256', $taxonomy );
			$covered = null !== $this->query_term_sources && isset( $this->query_term_sources[ $key ] );
			$refresh = ! $covered || null === $this->query_term_refresh || ! isset( $this->query_term_refresh[ $key ] );
			if ( ! $refresh ) {
				$refresh = ! $this->query_cached_term_matches( $id, $this->query_term_sources[ $key ] );
			}
			if ( $refresh ) {
				wp_cache_delete( $id, 'terms' );
				if ( $covered && null !== $this->query_term_refresh ) {
					$this->query_term_refresh[ $key ] = true;
				}
			}
			// Native term filters may read metadata; retain its original per-access freshness.
			wp_cache_delete( $id, 'term_meta' );
			// Always execute native get_term and its filters in the original access order.
			$current = get_term( $id, $taxonomy );
			if ( $current instanceof \WP_Term ) {
				$fresh[] = $current;
			}
		}
		return $fresh;
	}

	/**
	 * Verify only canonical native cache columns before reusing one covered raw row.
	 *
	 * Native filter results are deliberately excluded: get_term still applies them
	 * on every access. Unsupported cache shape/type/content retains legacy eviction.
	 *
	 * @param int   $term_id Exact requested term.
	 * @param array $source Initial native per-column digests.
	 */
	private function query_cached_term_matches( int $term_id, array $source ): bool {
		$cached = wp_cache_get( $term_id, 'terms' );
		if ( ! is_object( $cached ) || ! in_array( get_class( $cached ), array( 'stdClass', 'WP_Term' ), true ) ) {
			return false;
		}
		$values = get_object_vars( $cached );
		$fields = array(
			'term_id'          => 't_term_id',
			'name'             => 't_name',
			'slug'             => 't_slug',
			'term_group'       => 't_term_group',
			'term_taxonomy_id' => 'tt_term_taxonomy_id',
			'taxonomy'         => 'tt_taxonomy',
			'description'      => 'tt_description',
			'parent'           => 'tt_parent',
			'count'            => 'tt_count',
		);
		if ( array_diff( array_keys( $values ), array_merge( array_keys( $fields ), array( 'filter' ) ) ) || ( array_key_exists( 'filter', $values ) && 'raw' !== $values['filter'] ) ) {
			return false;
		}
		$integers = array( 'term_id', 'term_group', 'term_taxonomy_id', 'parent', 'count' );
		$maximum  = (string) PHP_INT_MAX;
		$bytes    = 0;
		foreach ( $fields as $field => $column ) {
			if ( ! array_key_exists( $field, $values ) ) {
				return false;
			}
			$value = $values[ $field ];
			if ( in_array( $field, $integers, true ) ) {
				if ( ! is_int( $value ) && ! is_string( $value ) ) {
					return false;
				}
				$value = (string) $value;
				if ( strlen( $value ) > strlen( $maximum ) || ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
					return false;
				}
			} elseif ( ! is_string( $value ) ) {
				return false;
			}
			$bytes += strlen( $value );
			if ( $bytes > 1048576 ) {
				return false;
			}
			$values[ $field ] = $value;
		}
		foreach ( $fields as $field => $column ) {
			if ( hash( 'sha256', $values[ $field ] ) !== $source[ $column ] ) {
				return false;
			}
		}
		return hash( 'sha256', $values['term_id'] ) === $source['tt_term_id'];
	}

	/**
	 * Use WordPress's native object-ID term query only where a single term per field
	 * makes its global ordering identical to each original per-listing query.
	 * Any incomplete/ambiguous row retains the original per-listing native calls.
	 *
	 * @param int[] $listing_ids Exact selected listing IDs.
	 * @param array $native_fields Authorized native field definitions.
	 * @param array $identity Initial private taxonomy identity.
	 */
	private function query_batch_terms( array $listing_ids, array $native_fields, array $identity ): ?array {
		if ( ! $listing_ids || ! $identity['batch_safe'] || $identity['relationship_count'] > 5000 ) {
			return null;
		}
		$mapping    = array(
			'category' => ATBDP_CATEGORY,
			'location' => ATBDP_LOCATION,
			'tag'      => ATBDP_TAGS,
		);
		$taxonomies = array();
		foreach ( $native_fields as $field ) {
			$widget = $field['widget_name'] ?? null;
			if ( is_string( $widget ) && isset( $mapping[ $widget ] ) ) {
				$taxonomies[ $mapping[ $widget ] ] = true;
			}
		}
		if ( ! $taxonomies ) {
			return null;
		}
		$result = array();
		foreach ( array_keys( $taxonomies ) as $taxonomy ) {
			$terms = wp_get_object_terms(
				$listing_ids,
				$taxonomy,
				array(
					'fields'        => 'all_with_object_id',
					'cache_results' => false,
				)
			);
			if ( is_wp_error( $terms ) || ! is_array( $terms ) || count( $terms ) > 5000 ) {
				return null;
			}
			$result[ $taxonomy ] = array();
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term || ! isset( $term->object_id ) || ! is_int( $term->object_id ) || ! is_int( $term->term_id ) || ! is_int( $term->term_taxonomy_id ) || ! isset( $identity['membership'][ $taxonomy ][ $term->object_id ] ) || isset( $result[ $taxonomy ][ $term->object_id ] ) ) {
					return null;
				}
				$expected = $identity['membership'][ $taxonomy ][ $term->object_id ];
				if ( $term->taxonomy !== $taxonomy || $term->term_taxonomy_id !== $expected['term_taxonomy_id'] || ! hash_equals( $expected['term_id_hash'], hash( 'sha256', (string) $term->term_id ) ) ) {
					return null;
				}
				$result[ $taxonomy ][ $term->object_id ] = array( $term );
			}
			if ( count( $result[ $taxonomy ] ) !== count( $identity['membership'][ $taxonomy ] ?? array() ) ) {
				return null;
			}
		}
		return $result;
	}

	/**
	 * Observe exact native taxonomy rows without caching or exposing raw source data.
	 *
	 * The optional memo requires <=10,000 relationships and <=1MiB raw columns.
	 * Over-budget initial observations retain legacy per-access refreshes.
	 * Fixed-size column digests avoid fetching a LOB enlarged after preflight.
	 * Missing taxonomy/term rows remain part of the private identity. No snapshot
	 * is atomic; changes after the final observation remain outside this fence.
	 *
	 * @param int[] $listing_ids Already authorized, bounded selected IDs.
	 * @throws ContractException Failed or changed native observations.
	 */
	private function query_taxonomy_identity( array $listing_ids ): ?array {
		if ( ! $listing_ids ) {
			return array(
				'identity' => hash( 'sha256', serialize( array() ) ),
				'terms'    => array(),
			);
		}
		global $wpdb;
		$columns = array(
			'tr' => array( 'object_id', 'term_taxonomy_id', 'term_order' ),
			'tt' => array( 'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count' ),
			't'  => array( 'term_id', 'name', 'slug', 'term_group' ),
		);
		$lengths = array();
		$digests = array();
		foreach ( $columns as $alias => $names ) {
			foreach ( $names as $name ) {
				$lengths[] = "COALESCE(OCTET_LENGTH($alias.$name),0)";
				$digests[] = "SHA2(CAST($alias.$name AS BINARY),256) AS {$alias}_{$name}";
			}
		}
		$id_marks   = implode( ',', array_fill( 0, count( $listing_ids ), '%d' ) );
		$taxonomies = array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS );
		$marks      = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Server-owned native tables and generated placeholder lists; every value is bound.
		$selection = $wpdb->prepare(
			"FROM $wpdb->term_relationships tr LEFT JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id LEFT JOIN $wpdb->terms t ON t.term_id=tt.term_id WHERE tr.object_id IN ($id_marks) AND (tt.taxonomy IN ($marks) OR tt.term_taxonomy_id IS NULL) ORDER BY tr.object_id,tr.term_taxonomy_id LIMIT 10001",
			array_merge( array_map( 'intval', $listing_ids ), $taxonomies )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$length_sql = implode( '+', $lengths );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed native column expressions and prepared selection.
		$budget = $wpdb->get_row( "SELECT COUNT(*) AS row_count,COALESCE(SUM(native_bytes),0) AS byte_count FROM (SELECT ($length_sql) AS native_bytes $selection) dai_taxonomy_budget", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $budget ) || ! isset( $budget['row_count'], $budget['byte_count'] ) || $wpdb->last_error ) {
			throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
		}
		if ( (int) $budget['row_count'] > 10000 || (int) $budget['byte_count'] > 1048576 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed native column digests and the fully prepared selection above.
		$rows = $wpdb->get_results( 'SELECT tr.object_id AS listing_id,tr.term_taxonomy_id AS relationship_id,' . implode( ',', $digests ) . ' ' . $selection, ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
		}
		if ( count( $rows ) !== (int) $budget['row_count'] ) {
			throw new ContractException( 'stale_version', 'Listing query taxonomy changed during observation.' );
		}
		$expected = array( 'listing_id', 'relationship_id' );
		foreach ( $columns as $alias => $names ) {
			foreach ( $names as $name ) {
				$expected[] = $alias . '_' . $name;
			}
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== $expected ) {
				throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
			}
			foreach ( array_slice( $expected, 2 ) as $column ) {
				$value = $row[ $column ];
				if ( null !== $value && ( ! is_string( $value ) || 64 !== strlen( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) ) {
					throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
				}
			}
			if ( null === $row['tr_object_id'] || null === $row['tr_term_taxonomy_id'] || null === $row['tr_term_order'] ) {
				throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
			}
			foreach ( array( 'tt', 't' ) as $alias ) {
				$joined = null !== $row[ $alias . '_' . $columns[ $alias ][0] ];
				foreach ( $columns[ $alias ] as $column ) {
					if ( ( $joined && null === $row[ $alias . '_' . $column ] ) || ( ! $joined && null !== $row[ $alias . '_' . $column ] ) ) {
						throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
					}
				}
			}
			if ( null === $row['tt_term_taxonomy_id'] && null !== $row['t_term_id'] ) {
				throw new ContractException( 'storage_error', 'Listing query taxonomy observation failed.' );
			}
		}
		$terms           = array();
		$identities      = array();
		$conflicts       = array();
		$membership      = array();
		$batch_safe      = true;
		$term_taxonomies = array( ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS );
		foreach ( $rows as $row ) {
			if ( null === $row['tt_taxonomy'] ) {
				$batch_safe = false;
			} else {
				foreach ( $term_taxonomies as $taxonomy ) {
					if ( hash( 'sha256', $taxonomy ) !== $row['tt_taxonomy'] ) {
						continue;
					}
					$listing_id       = (int) $row['listing_id'];
					$term_taxonomy_id = (int) $row['relationship_id'];
					if ( isset( $membership[ $taxonomy ][ $listing_id ] ) || null === $row['t_term_id'] || $row['t_term_id'] !== $row['tt_term_id'] || hash( 'sha256', (string) $term_taxonomy_id ) !== $row['tt_term_taxonomy_id'] ) {
						$batch_safe = false;
					}
					$membership[ $taxonomy ][ $listing_id ] = array(
						'term_taxonomy_id' => $term_taxonomy_id,
						'term_id_hash'     => $row['tt_term_id'],
					);
					break;
				}
			}
			if ( null === $row['t_term_id'] || $row['t_term_id'] !== $row['tt_term_id'] || null === $row['tt_taxonomy'] ) {
				continue; // Missing/orphaned native rows cannot authorize cache reuse.
			}
			$id       = $row['t_term_id'];
			$key      = $id . ':' . $row['tt_taxonomy'];
			$identity = $row['tt_term_taxonomy_id'] . ':' . $row['tt_taxonomy'];
			if ( isset( $identities[ $id ] ) && $identities[ $id ] !== $identity ) {
				$conflicts[ $id ] = true;
			}
			$identities[ $id ] = $identity;
			$terms[ $key ]     = $row;
		}
		foreach ( $terms as $key => $row ) {
			if ( isset( $conflicts[ $row['t_term_id'] ] ) ) {
				unset( $terms[ $key ] );
			}
		}
		return array(
			'identity'           => hash( 'sha256', serialize( $rows ) ),
			'terms'              => $terms,
			'membership'         => $membership,
			'batch_safe'         => $batch_safe,
			'relationship_count' => count( $rows ),
		);
	}

	/**
	 * Produce a stable logical field ID independent from bracketed form names.
	 *
	 * @param string $source_key Native form map key.
	 * @param array  $field Native field configuration.
	 */
	private function field_id( string $source_key, array $field ): string {
		$map    = array(
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
		$widget = sanitize_key( (string) $field['widget_name'] );
		if ( isset( $map[ $widget ] ) ) {
			return $map[ $widget ];
		}
		$key = sanitize_key( (string) ( $field['field_key'] ?? $source_key ) );
		return preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key ) ? $key : '';
	}

	/**
	 * Extract only supported static select choices.
	 *
	 * @param array $field Native field configuration.
	 */
	private function allowed_values( array $field ): array {
		$choices = $field['options'] ?? $field['choices'] ?? $field['select_options'] ?? array();
		if ( ! is_array( $choices ) || count( $choices ) > 100 ) {
			return array();
		}
		$result = array();
		foreach ( $choices as $choice_key => $choice ) {
			if ( is_array( $choice ) ) {
				$value = $choice['value'] ?? $choice['option_value'] ?? $choice_key;
				$label = $choice['label'] ?? $choice['option_label'] ?? $value;
			} else {
				$value = is_int( $choice_key ) ? $choice : $choice_key;
				$label = $choice;
			}
			if ( is_scalar( $value ) && is_scalar( $label ) ) {
				$result[] = (object) array(
					'value' => $value,
					'label' => wp_strip_all_tags( (string) $label ),
				);
			}
		}
		return $result;
	}

	/**
	 * Extract validation-relevant scalar limits without builder UI metadata.
	 *
	 * @param array $field Native field configuration.
	 */
	private function limits( array $field ): object {
		$result = new \stdClass();
		foreach ( array( 'rows', 'max_image_limit', 'max_per_image_limit', 'max_total_image_limit', 'max_location_creation', 'min', 'max', 'maxlength' ) as $key ) {
			if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ) {
				$result->{$key} = is_numeric( $field[ $key ] ) ? (float) $field[ $key ] : (string) $field[ $key ];
			}
		}
		foreach ( array(
			'min_value' => 'min',
			'max_value' => 'max',
		) as $native => $logical ) {
			if ( isset( $field[ $native ] ) && is_numeric( $field[ $native ] ) ) {
				$result->{$logical} = (float) $field[ $native ];
			}
		}
		return $result;
	}

	/**
	 * Extract normalized conditional relationships from supported native shapes.
	 *
	 * @param array $field Native field configuration.
	 */
	private function conditional_logic( array $field ): ?object {
		$logic = $field['conditional_logic_data'] ?? $field['options']['conditional_logic']['value'] ?? $field['options']['conditional_logic'] ?? $field['conditional_logic'] ?? null;
		if ( is_string( $logic ) ) {
			$logic = json_decode( $logic, true );
		}
		if ( ! is_array( $logic ) || ! filter_var( $logic['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return null;
		}
		$logic['enabled'] = true;
		$normalized       = $this->normalize( $logic );
		return $normalized instanceof \stdClass ? $normalized : null;
	}

	/**
	 * Normalize conditional field references and reject hidden/missing dependencies.
	 *
	 * @param mixed $value Conditional graph value.
	 * @param array $source_keys Native-to-logical key map.
	 * @param array $eligible Eligible logical field set.
	 * @param bool  $valid Updated to false for an inaccessible dependency.
	 * @return mixed Scoped conditional graph.
	 */
	private function condition_scope( $value, array $source_keys, array $eligible, bool &$valid ) {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $child ) {
				$result[] = $this->condition_scope( $child, $source_keys, $eligible, $valid );
			}
			return $result;
		}
		if ( ! $value instanceof \stdClass ) {
			return $value;
		}
		$result = new \stdClass();
		foreach ( get_object_vars( $value ) as $key => $child ) {
			if ( 'field' === $key && is_string( $child ) ) {
				$logical = $source_keys[ $child ] ?? sanitize_key( $child );
				if ( ! isset( $eligible[ $logical ] ) ) {
					$valid = false;
					continue;
				}
				$result->{$key} = $logical;
				continue;
			}
			$result->{$key} = $this->condition_scope( $child, $source_keys, $eligible, $valid );
		}
		return $result;
	}

	/**
	 * Dependency identity independent from user/profile exposure.
	 *
	 * @param int $directory_id Exact directory term ID.
	 */
	private function environment_hash( int $directory_id ): string {
		global $wp_version;
		$raw = $this->isolated( static fn(): array => directorist_submission_form_fields_raw( $directory_id ) );
		return $this->hash(
			(object) array(
				'wordpress'    => $wp_version,
				'directorist'  => ATBDP_VERSION,
				'directory_id' => $directory_id,
				'form'         => $this->normalize( $raw ),
			)
		);
	}

	/**
	 * Remove ambient request selectors while invoking Directorist's read filters.
	 *
	 * @param callable $callback Native read callback.
	 * @return mixed Native callback result.
	 */
	private function isolated( callable $callback ) {
		$get      = $_GET;
		$post     = $_POST;
		$request  = $_REQUEST;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		try {
			return $callback();
		} finally {
			$_GET     = $get;
			$_POST    = $post;
			$_REQUEST = $request;
		}
	}

	/**
	 * Convert native associative arrays to preserved JSON object identity.
	 *
	 * @param mixed $value Native value.
	 * @return mixed JSON-safe value.
	 */
	private function normalize( $value ) {
		if ( is_array( $value ) ) {
			if ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
				return array_map( fn( $child ) => $this->normalize( $child ), $value );
			}
			$object = new \stdClass();
			foreach ( $value as $key => $child ) {
				$object->{ (string) $key} = $this->normalize( $child );
			}
			return $object;
		}
		if ( is_object( $value ) ) {
			return $this->normalize( get_object_vars( $value ) );
		}
		return is_scalar( $value ) || null === $value ? $value : null;
	}

	/**
	 * Stable JSON hash for version and environment binding.
	 *
	 * @param mixed $value JSON-safe value.
	 */
	private function hash( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
