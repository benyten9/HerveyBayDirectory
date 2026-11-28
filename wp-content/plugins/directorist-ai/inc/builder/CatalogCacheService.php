<?php
/**
 * Context-aware effective catalog cache orchestration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Cache transitions are explicit in methods.

use DirectoristAIPro\Validation\ContractException;

/** Every warm read re-resolves current authority/fingerprints before storage. */
final class CatalogCacheService {
	private CatalogCacheStore $store;
	private EffectiveCatalogCompiler $compiler;
	private CatalogCacheContextReader $contexts;
	private ?\Closure $environment_refresh;
	private ?CatalogCachePrewarm $prewarm = null;

	public function __construct( CatalogCacheStore $store, EffectiveCatalogCompiler $compiler, CatalogCacheContextReader $contexts, ?callable $environment_refresh = null ) {
		$this->store               = $store;
		$this->compiler            = $compiler;
		$this->contexts            = $contexts;
		$this->environment_refresh = null === $environment_refresh ? null : \Closure::fromCallable( $environment_refresh );
	}

	/** Resolve from one exact complete generation or rebuild synchronously. */
	public function describe( CatalogRequest $request, CatalogEnvironment $environment ): CatalogCacheResult {
		$result = $this->resolve( $request, $environment, 0 );
		if ( null !== $this->prewarm && 'unavailable' !== $result->state() ) {
			try {
				$this->prewarm->remember( $request, $result->context() );
			} catch ( \Throwable $error ) {
				// Optional background warming never replaces a verified foreground result.
			}
		}
		return $result;
	}

	/** Attach only the trusted bootstrap-owned background coordinator. */
	public function enable_prewarm( CatalogCachePrewarm $prewarm ): void {
		$this->prewarm = $prewarm;
	}

	/** A worker may warm only a complete currently cacheable non-editor context. */
	public function prewarm_context( CatalogRequest $request, CatalogEnvironment $environment ): ?CatalogCacheContext {
		if ( null !== $request->editor_binding() ) {
			return null;
		}
		$context = $this->contexts->read( $request, $environment );
		return $context->cacheable() ? $context : null;
	}

	/** Resolve with one bounded retry when a warm/fresh final fence changes. */
	private function resolve( CatalogRequest $request, CatalogEnvironment $environment, int $attempt ): CatalogCacheResult {
		$context = $this->contexts->read( $request, $environment );
		if ( ! $context->cacheable() ) {
			$catalog             = $this->compiler->compile( $request, $environment );
			$current_environment = $this->current_environment( $environment );
			$current             = $this->contexts->read( $request, $current_environment );
			if ( ! $this->same_context( $context, $current ) ) {
				throw new ContractException( 'stale_version', 'Catalog context changed during compilation.' );
			}
			return new CatalogCacheResult( $catalog, 'dynamic', null, $current );
		}
		$before = $this->store->read( $context );
		if ( 'warm' === $before['state'] ) {
			$confirmed = $this->confirm( $request, $environment, $context, $before, 'warm' );
			if ( null !== $confirmed ) {
				return $confirmed;
			}
			if ( $attempt < 1 ) {
				return $this->resolve( $request, $this->current_environment( $environment ), $attempt + 1 );
			}
			throw new ContractException( 'stale_version', 'Catalog changed before delivery.' );
		}
		$generation = $this->store->begin( $context );
		$raced      = $this->store->read( $context );
		if ( 'warm' === $raced['state'] ) {
			$confirmed = $this->confirm( $request, $environment, $context, $raced, 'raced' );
			if ( null !== $confirmed ) {
				return $confirmed;
			}
			throw new ContractException( 'stale_version', 'Catalog changed before delivery.' );
		}
		try {
			$catalog = $this->compiler->compile( $request, $environment );
		} catch ( ContractException $error ) {
			$this->store->fail( $context, $generation, $error->error_code() );
			throw $error;
		} catch ( \Throwable $error ) {
			$this->store->fail( $context, $generation, 'catalog_unavailable' );
			throw $error;
		}
		$current_environment = $this->current_environment( $environment );
		$current_context     = $this->contexts->read( $request, $current_environment );
		if ( ! $this->same_context( $context, $current_context ) ) {
			$this->store->fail( $context, $generation, 'cache_context_changed' );
			throw new ContractException( 'stale_version', 'Catalog context changed during compilation.' );
		}
		if ( $this->unavailable( $catalog ) ) {
			$this->store->fail( $context, $generation, 'provider_unavailable' );
			return new CatalogCacheResult( $catalog, 'unavailable', null, $current_context );
		}
		try {
			$published = $this->store->publish( $context, $generation, $catalog );
		} catch ( \Throwable $error ) {
			$this->store->fail( $context, $generation, 'cache_context_changed' );
			throw new ContractException( 'stale_version', 'Catalog context changed during compilation.' );
		}
		if ( ! $published ) {
			$winner = $this->store->read( $current_context );
			if ( 'warm' === $winner['state'] ) {
				$confirmed = $this->confirm( $request, $current_environment, $current_context, $winner, 'raced' );
				if ( null !== $confirmed ) {
					return $confirmed;
				}
			}
			throw new ContractException( 'busy', 'Catalog generation changed before publication.' );
		}
		$state         = 'cold' === $before['state'] ? 'fresh' : ( 'stale' === $before['state'] ? 'refreshed' : 'recovered' );
		$published_row = $this->store->read( $current_context );
		$confirmed     = 'warm' === $published_row['state'] ? $this->confirm( $request, $current_environment, $current_context, $published_row, $state ) : null;
		if ( null === $confirmed ) {
			throw new ContractException( 'stale_version', 'Catalog changed before delivery.' );
		}
		return $confirmed;
	}

	private function confirm( CatalogRequest $request, CatalogEnvironment $environment, CatalogCacheContext $expected, array $row, string $state ): ?CatalogCacheResult {
		$current_environment = $this->current_environment( $environment );
		$current             = $this->contexts->read( $request, $current_environment );
		if ( ! $this->same_context( $expected, $current ) || ! is_object( $row['catalog'] ) || ! $this->store->current( $current, (int) $row['generation'], $row['catalog']->revision ) ) {
			return null;
		}
		return new CatalogCacheResult( $row['catalog'], $state, (int) $row['generation'], $current );
	}

	private function same_context( CatalogCacheContext $left, CatalogCacheContext $right ): bool {
		return hash_equals( $left->context_key(), $right->context_key() ) && hash_equals( $left->key_hash(), $right->key_hash() );
	}

	private function current_environment( CatalogEnvironment $fallback ): CatalogEnvironment {
		return null === $this->environment_refresh ? $fallback : ( $this->environment_refresh )();
	}

	/** Failed/incomplete source readers are retryable and never warmed indefinitely. */
	private function unavailable( object $catalog ): bool {
		foreach ( $catalog->providers as $provider ) {
			if ( in_array( $provider->status, array( 'failed', 'incomplete' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Prewarm uses the identical live authority and generation path. */
	public function prewarm( CatalogRequest $request, CatalogEnvironment $environment ): CatalogCacheResult {
		return $this->resolve( $request, $environment, 0 );
	}

	/** Register bounded invalidation signals; fingerprints remain the authority. */
	public function hooks(): void {
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_all' ) );
		}
		add_action( 'upgrader_process_complete', array( $this, 'invalidate_all' ) );
		add_action( 'directorist_ai_pro_builder_catalog_invalidated', array( $this, 'invalidate_requested' ), 10, 1 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'directory_meta_changed' ), 10, 4 );
		}
		add_action( 'updated_option', array( $this, 'option_changed' ), 10, 1 );
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'deleted_user', 'remove_user_from_blog', 'add_user_to_blog' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_all' ), 10, 0 );
		}
		add_action( 'directorist_ai_pro_lifecycle', array( $this, 'prune' ), 20 );
	}

	public function invalidate_all(): void {
		$this->store->invalidate_all();
		$this->queue_prewarm( null );
	}

	/** Shared lifecycle queue removes expired private overlays and generations. */
	public function prune(): void {
		$this->store->prune( 200 );
	}

	/** Integration-owned invalidation may name one directory or all with null. */
	public function invalidate_requested( $directory_id = null ): void {
		if ( null === $directory_id ) {
			$this->invalidate_all();
			return;
		}
		if ( is_int( $directory_id ) && $directory_id > 0 ) {
			$this->store->invalidate_directory( $directory_id );
			$this->queue_prewarm( $directory_id );
		}
	}

	/** Submission-form schema changes invalidate every actor overlay for that directory. */
	public function directory_meta_changed( $meta_id, $term_id, $meta_key, $meta_value = null ): void {
		if ( 'submission_form_fields' === $meta_key ) {
			$this->store->invalidate_directory( (int) $term_id );
			$this->queue_prewarm( (int) $term_id );
		}
	}

	/** Common builder/global-design option families receive an immediate fence. */
	public function option_changed( $option ): void {
		if ( ! is_string( $option ) ) {
			return;
		}
		foreach ( array( 'elementor_', 'et_', 'theme_mods_', 'global_styles', 'directorist_' ) as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				$this->store->invalidate_all( 'design_changed' );
				$this->queue_prewarm( null );
				return;
			}
		}
	}

	/** Invalidation remains synchronous even when optional scheduling is unavailable. */
	private function queue_prewarm( ?int $directory_id ): void {
		if ( null !== $this->prewarm ) {
			try {
				$this->prewarm->invalidate( $directory_id );
			} catch ( \Throwable $error ) {
				// The next authorized foreground read retains the normal fresh fallback.
			}
		}
	}
}
