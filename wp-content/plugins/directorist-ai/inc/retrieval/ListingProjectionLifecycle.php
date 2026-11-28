<?php
/**
 * Native listing projection invalidation, refresh and resumable rebuilds.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Hook and rebuild boundaries are documented at the service level.

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;
use DirectoristAIPro\Validation\ContractException;

/** Source fences invalidate stale entries synchronously; jobs can only restore the same fence. */
final class ListingProjectionLifecycle {
	private const GROUP = 'directorist-ai-pro';
	private ListingProjectionStore $store;
	private DirectoristInspector $inspector;
	private ListingQueryCatalog $catalog;
	/**
	 * Listings already fenced by before_delete_post in this request.
	 *
	 * @var array<int,true>
	 */
	private array $deleting = array();
	/** Native listing IDs captured before an object-wide metadata deletion. */
	private array $bulk_deletions = array();

	/** Bind projection storage and the native Directorist reader. */
	public function __construct( ListingProjectionStore $store, DirectoristInspector $inspector, ListingQueryCatalog $catalog ) {
		$this->store     = $store;
		$this->inspector = $inspector;
		$this->catalog   = $catalog;
	}

	/** Register native invalidation and background refresh callbacks. */
	public function hooks(): void {
		add_action( 'init', array( $this, 'ensure_public_rebuilds' ), 40 );
		add_action( 'save_post_' . ATBDP_POST_TYPE, array( $this, 'saved' ), 100, 3 );
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), 100, 6 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 100, 4 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 100, 4 );
		add_action( 'delete_post_meta', array( $this, 'before_meta_deleted' ), 100, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 100, 4 );
		add_action( 'added_term_meta', array( $this, 'directory_meta_changed' ), 100, 4 );
		add_action( 'updated_term_meta', array( $this, 'directory_meta_changed' ), 100, 4 );
		add_action( 'deleted_term_meta', array( $this, 'directory_meta_changed' ), 100, 4 );
		add_action( 'before_delete_post', array( $this, 'deleting' ), 100, 2 );
		add_action( 'directorist_ai_pro_listing_applied', array( $this, 'applied' ), 100, 1 );
		add_action( 'directorist_ai_pro_projection_refresh', array( $this, 'handle_refresh' ), 10, 3 );
		add_action( 'directorist_ai_pro_projection_rebuild', array( $this, 'handle_rebuild' ), 10, 2 );
	}

	/** Schedule missing or catalog-stale public directory generations. */
	public function ensure_public_rebuilds(): void {
		if ( get_transient( 'directorist_ai_projection_discovery' ) || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		set_transient( 'directorist_ai_projection_discovery', 1, 300 );
		$directories = get_terms( array( 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1000 ) );
		if ( is_wp_error( $directories ) ) {
			return;
		}
		$context = $this->context( 'public' );
		foreach ( array_map( 'intval', $directories ) as $directory_id ) {
			try {
				$revision = $this->catalog->describe( $context, $directory_id, 'public' )->revision;
				$head     = $this->store->head( 'public', $directory_id );
				if ( ! $head || null === $head['active_catalog_revision'] || ! hash_equals( $head['active_catalog_revision'], $revision ) ) {
					if ( $head && null !== $head['active_catalog_revision'] ) {
						$this->store->expire_catalog( 'public', $directory_id );
					}
					$this->schedule_rebuild( 'public', $directory_id );
				}
			} catch ( ContractException $error ) {
				// An unavailable directory remains absent from projection state.
			}
		}
	}

	/** Invalidate after a native listing save. */
	public function saved( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ATBDP_POST_TYPE !== $post->post_type ) {
			return;
		}
		$this->invalidate( $post_id );
	}

	/** Invalidate after a relevant listing relationship change. */
	public function terms_changed( int $object_id, array $terms, array $term_taxonomy_ids, string $taxonomy, bool $append, array $old_term_taxonomy_ids ): void {
		unset( $terms, $term_taxonomy_ids, $append, $old_term_taxonomy_ids );
		$taxonomies = array_filter( array( defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : '', defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : '', defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : '', defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : '' ) );
		if ( $object_id > 0 && in_array( $taxonomy, $taxonomies, true ) && ATBDP_POST_TYPE === get_post_type( $object_id ) ) {
			$this->invalidate( $object_id );
		}
	}

	/** Invalidate after any native listing metadata change. */
	public function meta_changed( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		unset( $meta_key, $meta_value );
		if ( $object_id < 1 ) {
			$key = $this->bulk_meta_key( $meta_id );
			$ids = null === $key ? array() : array_keys( $this->bulk_deletions[ $key ] ?? array() );
			if ( null !== $key ) {
				unset( $this->bulk_deletions[ $key ] );
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $this->deleting[ $id ] ) && ATBDP_POST_TYPE === get_post_type( $id ) ) {
					$this->invalidate( $id );
				}
			}
			return;
		}
		if ( ! isset( $this->deleting[ $object_id ] ) && ATBDP_POST_TYPE === get_post_type( $object_id ) ) {
			$this->invalidate( $object_id );
		}
	}

	/** Remember real owners while rows still exist for delete_post_meta_by_key(). */
	public function before_meta_deleted( $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		unset( $meta_key, $meta_value );
		$key = $this->bulk_meta_key( $meta_ids );
		if ( $object_id > 0 || null === $key ) {
			return;
		}
		global $wpdb;
		$this->bulk_deletions[ $key ] = array();
		foreach ( array_chunk( array_map( 'intval', $meta_ids ), 500 ) as $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact native IDs captured by WordPress before deletion; placeholders only.
			$owners = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_id IN ($placeholders) AND p.post_type=%s", array_merge( $ids, array( ATBDP_POST_TYPE ) ) ) );
			foreach ( $owners as $id ) {
				$this->bulk_deletions[ $key ][ (int) $id ] = true;
			}
		}
	}

	/** Canonical native metadata identity, retaining no values or caller content. */
	private function bulk_meta_key( $meta_ids ): ?string {
		if ( ! is_array( $meta_ids ) || ! $meta_ids ) {
			return null;
		}
		$ids = array_map( 'intval', $meta_ids );
		if ( min( $ids ) < 1 ) {
			return null;
		}
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', implode( ',', $ids ) );
	}

	/** Schedule every existing scope when a directory definition changes. */
	public function directory_meta_changed( $meta_id, int $directory_id, string $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_key, $meta_value );
		if ( $directory_id < 1 || ! directorist_is_directory( $directory_id ) ) {
			return;
		}
		delete_transient( 'directorist_ai_projection_discovery' );
		$scopes = $this->store->scopes( $directory_id );
		if ( ! in_array( 'public', $scopes, true ) ) {
			$scopes[] = 'public';
		}
		foreach ( $scopes as $scope_key ) {
			$this->store->expire_catalog( $scope_key, $directory_id );
			$this->schedule_rebuild( $scope_key, $directory_id );
		}
	}

	/** Reobserve once a DirectoristAI Pro native effect has finished its full form update. */
	public function applied( int $listing_id ): void {
		$this->invalidate( $listing_id );
	}

	/** Fence a source before WordPress removes it. */
	public function deleting( int $post_id, \WP_Post $post ): void {
		if ( ATBDP_POST_TYPE === $post->post_type ) {
			$this->deleting[ $post_id ] = true;
			$this->store->touch( $post_id, false, hash( 'sha256', 'deleted:' . $post_id . ':' . microtime( true ) ) );
		}
	}

	/** Fence current native state immediately and debounce a refresh wake. */
	public function invalidate( int $listing_id ): array {
		if ( $listing_id < 1 ) {
			throw new ContractException( 'invalid_input', 'A positive native listing identity is required.' );
		}
		$snapshot = $this->snapshot( $listing_id );
		$source   = $this->store->source( $listing_id );
		if ( $source && hash_equals( $source['source_hash'], $snapshot['source_hash'] ) && $source['public_eligible'] === $snapshot['public_eligible'] ) {
			return $source;
		}
		$previous = $source;
		$source   = $this->store->touch( $listing_id, $snapshot['public_eligible'], $snapshot['source_hash'] );
		$this->schedule( $listing_id, $source['epoch'], $source['source_hash'], $previous );
		return $source;
	}

	/** Refresh only if the queued source fence still equals native state. */
	public function handle_refresh( int $listing_id, int $source_epoch, string $source_hash ): bool {
		if ( $listing_id < 1 ) {
			return false;
		}
		$source   = $this->store->source( $listing_id );
		$snapshot = $this->snapshot( $listing_id );
		if ( ! $source || $source['epoch'] !== $source_epoch || ! hash_equals( $source['source_hash'], $source_hash ) || ! hash_equals( $snapshot['source_hash'], $source_hash ) ) {
			return false;
		}
		$directory_id = $snapshot['directory_id'];
		if ( $directory_id < 1 ) {
			return false;
		}
		$changed = false;
		foreach ( $this->store->scopes( $directory_id ) as $scope_key ) {
			$context          = $this->context( $scope_key );
			$dataset          = $this->dataset( $scope_key );
			$head             = $this->store->head( $scope_key, $directory_id );
			$catalog_revision = null;
			if ( ! $context || ! $head || ( null === $head['active_generation'] && null === $head['building_generation'] ) ) {
				continue;
			}
			try {
				$catalog_revision = $this->catalog->describe( $context, $directory_id, $dataset )->revision;
				$facts            = $this->inspector->query_facts( $context, $directory_id, $dataset, array( $listing_id ) )->listings[0];
				$changed          = $this->store->refresh_current( $scope_key, $directory_id, $listing_id, $source_epoch, $source_hash, $catalog_revision, $facts ) || $changed;
			} catch ( ContractException $error ) {
				$changed = $this->store->refresh_current( $scope_key, $directory_id, $listing_id, $source_epoch, $source_hash, $catalog_revision, (object) array( 'revoked' => true ), false ) || $changed;
			}
		}
		return $changed;
	}

	/** Run one queued rebuild batch and reschedule until the generation publishes. */
	public function handle_rebuild( string $scope_key, int $directory_id ): bool {
		$context = $this->context( $scope_key );
		if ( ! $context ) {
			return false;
		}
		try {
			$result = $this->rebuild( $scope_key, $context, $directory_id, 100 );
			if ( ! $result['complete'] && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 1, 'directorist_ai_pro_projection_rebuild', array( 'scope_key' => $scope_key, 'directory_id' => $directory_id ), self::GROUP, false, 5 );
			}
			return true;
		} catch ( ContractException $error ) {
			return false;
		}
	}

	/**
	 * Process one resumable rebuild batch; incomplete generations stay hidden.
	 *
	 * @return array{generation:int,cursor_id:int,complete:bool,staged:int}
	 */
	public function rebuild( string $scope_key, Context $context, int $directory_id, int $batch_size = 100 ): array {
		if ( $batch_size < 1 || $batch_size > 500 ) {
			throw new ContractException( 'invalid_query', 'Projection rebuild batch is invalid.' );
		}
		$dataset    = $this->dataset( $scope_key );
		if ( 'public' !== $dataset && ! hash_equals( self::scope_key( $context, $dataset ), $scope_key ) ) {
			throw new ContractException( 'unauthorized', 'Projection scope does not match the current actor.' );
		}
		$catalog    = $this->catalog->describe( $context, $directory_id, $dataset );
		$generation = $this->store->begin( $scope_key, $directory_id, $catalog->revision );
		$head       = $this->store->head( $scope_key, $directory_id );
		$ids        = $this->candidate_ids( $context, $directory_id, $dataset, $head['cursor_id'], $batch_size );
		$staged     = 0;
		foreach ( $ids as $listing_id ) {
			$snapshot = $this->snapshot( $listing_id );
			$source   = $this->store->source( $listing_id );
			if ( ! $source || ! hash_equals( $source['source_hash'], $snapshot['source_hash'] ) || $source['public_eligible'] !== $snapshot['public_eligible'] ) {
				$source = $this->store->touch( $listing_id, $snapshot['public_eligible'], $snapshot['source_hash'] );
			}
			try {
				$facts = $this->inspector->query_facts( $context, $directory_id, $dataset, array( $listing_id ) )->listings[0];
				if ( $this->store->stage( $scope_key, $directory_id, $generation, $listing_id, $source['epoch'], $source['source_hash'], $facts ) ) {
					++$staged;
				}
			} catch ( ContractException $error ) {
				// Authority/status changed after candidate selection; no entry is staged.
			}
			$this->store->checkpoint( $scope_key, $directory_id, $generation, $listing_id );
		}
		$complete = count( $ids ) < $batch_size;
		if ( $complete ) {
			$this->store->publish( $scope_key, $directory_id, $generation );
		}
		return array( 'generation' => $generation, 'cursor_id' => $ids ? $ids[ count( $ids ) - 1 ] : $head['cursor_id'], 'complete' => $complete, 'staged' => $staged );
	}

	/** Return a stable internal scope for an actor and dataset. */
	public static function scope_key( Context $context, string $dataset ): string {
		if ( 'public' === $dataset ) {
			return 'public';
		}
		if ( ! in_array( $dataset, array( 'own', 'management' ), true ) || $context->actor()->is_guest() ) {
			throw new ContractException( 'unauthorized', 'Projection scope is unavailable.' );
		}
		return 'user:' . $context->actor()->user_id() . ':' . $dataset;
	}

	/** Create or reuse the current source observation without storing native content. */
	private function snapshot( int $listing_id ): array {
		$post = $listing_id > 0 ? get_post( $listing_id ) : null;
		if ( ! $post instanceof \WP_Post || ATBDP_POST_TYPE !== $post->post_type ) {
			return array( 'directory_id' => 0, 'public_eligible' => false, 'source_hash' => hash( 'sha256', 'missing:' . $listing_id ) );
		}
		$directory_id = (int) directorist_get_listing_directory( $listing_id );
		$hash          = hash_init( 'sha256' );
		hash_update( $hash, wp_json_encode( array( $post->post_type, $post->post_status, $post->post_password, $post->post_title, $post->post_content, $post->post_excerpt, (int) $post->post_author, $post->post_modified_gmt, $directory_id ), JSON_UNESCAPED_SLASHES ) );
		$meta = get_post_meta( $listing_id );
		ksort( $meta, SORT_STRING );
		foreach ( $meta as $key => $values ) {
			hash_update( $hash, $key . ':' . hash( 'sha256', serialize( $values ) ) );
		}
		foreach ( array_filter( array( ATBDP_DIRECTORY_TYPE, defined( 'ATBDP_CATEGORY' ) ? ATBDP_CATEGORY : '', defined( 'ATBDP_LOCATION' ) ? ATBDP_LOCATION : '', defined( 'ATBDP_TAGS' ) ? ATBDP_TAGS : '' ) ) as $taxonomy ) {
			$ids = wp_get_object_terms( $listing_id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids, SORT_NUMERIC );
			hash_update( $hash, $taxonomy . ':' . implode( ',', $ids ) );
		}
		return array( 'directory_id' => $directory_id, 'public_eligible' => 'publish' === $post->post_status && '' === $post->post_password, 'source_hash' => hash_final( $hash ) );
	}

	/** Schedule one unique refresh; invalidation is already durable if the queue fails. */
	private function schedule( int $listing_id, int $source_epoch, string $source_hash, ?array $previous = null ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( $previous && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions(
				'directorist_ai_pro_projection_refresh',
				array( 'listing_id' => $listing_id, 'source_epoch' => $previous['epoch'], 'source_hash' => $previous['source_hash'] ),
				self::GROUP
			);
		}
		$args = array( 'listing_id' => $listing_id, 'source_epoch' => $source_epoch, 'source_hash' => $source_hash );
		if ( false === as_has_scheduled_action( 'directorist_ai_pro_projection_refresh', $args, self::GROUP ) ) {
			as_schedule_single_action( time() + 5, 'directorist_ai_pro_projection_refresh', $args, self::GROUP, true, 5 );
		}
	}

	/** Schedule one unique rebuild continuation. */
	private function schedule_rebuild( string $scope_key, int $directory_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$args = array( 'scope_key' => $scope_key, 'directory_id' => $directory_id );
		if ( false === as_has_scheduled_action( 'directorist_ai_pro_projection_rebuild', $args, self::GROUP ) ) {
			as_schedule_single_action( time() + 1, 'directorist_ai_pro_projection_rebuild', $args, self::GROUP, true, 5 );
		}
	}

	/** Resolve a persisted internal scope back to a live actor. */
	private function context( string $scope_key ): ?Context {
		if ( 'public' === $scope_key ) {
			return new Context( Actor::verified_guest( hash( 'sha256', 'directorist-ai-projection-public' ), time() + 300 ), 'visitor', 'worker' );
		}
		if ( ! preg_match( '/^user:([1-9][0-9]{0,19}):(own|management)$/D', $scope_key, $matches ) || ! get_userdata( (int) $matches[1] ) ) {
			return null;
		}
		return new Context( Actor::user( (int) $matches[1] ), 'management' === $matches[2] ? 'admin' : 'owner', 'worker' );
	}

	/** Extract the dataset from a validated internal scope. */
	private function dataset( string $scope_key ): string {
		if ( 'public' === $scope_key ) {
			return 'public';
		}
		if ( preg_match( '/^user:[1-9][0-9]{0,19}:(own|management)$/D', $scope_key, $matches ) ) {
			return $matches[1];
		}
		throw new ContractException( 'unauthorized', 'Projection scope is unavailable.' );
	}

	/** Bounded native candidate page for one rebuild. */
	private function candidate_ids( Context $context, int $directory_id, string $dataset, int $after_id, int $limit ): array {
		$statuses = 'public' === $dataset ? array( 'publish' ) : array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) ) );
		$args     = array( 'post_type' => ATBDP_POST_TYPE, 'post_status' => $statuses, 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false, 'tax_query' => array( array( 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'field' => 'term_id', 'terms' => array( $directory_id ) ) ) );
		$where    = null;
		if ( $after_id > 0 ) {
			$where = static function ( string $sql ) use ( $after_id ): string {
				global $wpdb;
				return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id );
			};
			add_filter( 'posts_where', $where, 10, 1 );
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
		try {
			return array_map( 'intval', ( new \WP_Query( $args ) )->posts );
		} finally {
			$_GET     = $get;
			$_POST    = $post;
			$_REQUEST = $request;
			if ( null !== $where ) {
				remove_filter( 'posts_where', $where, 10 );
			}
		}
	}
}
