<?php
/**
 * Bounded event-driven warming of previously authorized catalog contexts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Private background coordinates never confer authority.

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** The queue carries one opaque ticket, never editor state, content or a model request. */
final class CatalogCachePrewarm {
	private const HOOK  = 'directorist_ai_pro_catalog_prewarm';
	private const GROUP = 'directorist-ai-pro';
	private CatalogCacheStore $store;
	private CatalogCacheService $cache;
	private \Closure $environment;
	private \Closure $permitted;
	private \Closure $queue;
	private \Closure $clock;
	private array $remembered = array();
	private array $queued     = array();
	private bool $hooked      = false;

	public function __construct( CatalogCacheStore $store, CatalogCacheService $cache, callable $environment, callable $permitted, ?callable $queue = null, ?callable $clock = null ) {
		$this->store       = $store;
		$this->cache       = $cache;
		$this->environment = \Closure::fromCallable( $environment );
		$this->permitted   = \Closure::fromCallable( $permitted );
		$this->queue       = null === $queue ? \Closure::fromCallable( array( self::class, 'schedule' ) ) : \Closure::fromCallable( $queue );
		$this->clock       = null === $clock ? static fn(): int => time() : \Closure::fromCallable( $clock );
	}

	public function hooks(): void {
		if ( ! $this->hooked ) {
			add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
			$this->hooked = true;
		}
	}

	/** Called after verified foreground delivery, with no second source/facts read. */
	public function remember( CatalogRequest $request, CatalogCacheContext $context ): void {
		$owner = $request->context();
		if ( null !== $request->editor_binding() || ! $context->cacheable() || $context->editor_mode() || $owner->actor()->is_guest() || ! in_array( $owner->profile(), array( 'admin', 'builder' ), true ) || count( $this->remembered ) >= 32 ) {
			return;
		}
		$owner->assert_execution_identity();
		$key = $context->context_key();
		if ( isset( $this->remembered[ $key ] ) || $context->site_id() !== get_current_blog_id() || ! hash_equals( $context->scope_hash(), $this->scope( $owner ) ) ) {
			return;
		}
		$this->store->remember_prewarm(
			array(
				'site_id'       => get_current_blog_id(),
				'owner_id'      => $owner->actor()->user_id(),
				'profile'       => $owner->profile(),
				'builder'       => $request->builder(),
				'document_kind' => $request->document_kind(),
				'directory_id'  => $request->directory_id(),
				'scope_hash'    => $context->scope_hash(),
				'context_key'   => $key,
			),
			( $this->clock )()
		);
		$this->remembered[ $key ] = true;
	}

	/** Epoch fencing has already happened; warming is best-effort and coalesced. */
	public function invalidate( ?int $directory_id ): void {
		$this->enqueue( $this->store->request_prewarm( $directory_id, ( $this->clock )() ), ( $this->clock )() + 5 );
	}

	/** One bounded context per job; current native authority is resolved again. */
	public function run( $ticket ): void {
		if ( ! is_string( $ticket ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $ticket ) ) {
			return;
		}
		$claim = $this->store->claim_prewarm( $ticket, ( $this->clock )() );
		if ( null === $claim ) {
			return;
		}
		if ( isset( $claim['retry_ticket'] ) ) {
			$this->enqueue( $claim['retry_ticket'], $claim['retry_at'] );
			return;
		}
		$previous = get_current_user_id();
		$forget   = false;
		try {
			$entry = $claim['descriptor'];
			if ( get_current_blog_id() !== $entry['site_id'] || ! get_userdata( $entry['owner_id'] ) ) {
				$forget = true;
				return;
			}
			clean_user_cache( $entry['owner_id'] );
			wp_set_current_user( $entry['owner_id'] );
			$context = new Context( Actor::user( $entry['owner_id'] ), $entry['profile'], 'worker' );
			$context->assert_execution_identity();
			$request = new CatalogRequest( $context, $entry['builder'], $entry['document_kind'], $entry['directory_id'] );
			if ( ! hash_equals( $this->scope( $context ), $entry['scope_hash'] ) || ! ( $this->permitted )( $request ) ) {
				$forget = true;
				return;
			}
			$environment = ( $this->environment )();
			$current     = $this->cache->prewarm_context( $request, $environment );
			if ( null === $current ) {
				return;
			}
			if ( ! hash_equals( $current->context_key(), $entry['context_key'] ) || ! hash_equals( $current->scope_hash(), $entry['scope_hash'] ) ) {
				$forget = true;
				return;
			}
			// The normal compiler/publication/final-delivery fences remain intact.
			// prewarm() deliberately does not remember or schedule itself again.
			$this->cache->prewarm( $request, $environment );
		} catch ( ContractException $error ) {
			$forget = 'unauthorized' === $error->error_code();
		} catch ( \Throwable $error ) {
			// A source/queue failure retains the ordinary fresh foreground fallback.
		} finally {
			wp_set_current_user( $previous );
			$this->enqueue( $this->store->finish_prewarm( $claim['lease_token'], ( $this->clock )(), $forget ), ( $this->clock )() + 5 );
		}
	}

	private function scope( Context $context ): string {
		return hash( 'sha256', get_current_blog_id() . ':' . $context->actor()->owner()->reference() . ':' . $context->profile() );
	}

	private function enqueue( ?string $ticket, int $at ): void {
		if ( null === $ticket || isset( $this->queued[ $ticket ] ) ) {
			return;
		}
		if ( ( $this->queue )( $ticket, $at ) ) {
			$this->queued[ $ticket ] = true;
		}
	}

	/** Use the existing scheduler/group below user generation priority. */
	private static function schedule( string $ticket, int $at ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) || ! class_exists( '\ActionScheduler', false ) || ! \ActionScheduler::is_initialized() ) {
			return false;
		}
		$args = array( 'ticket' => $ticket );
		$id   = as_schedule_single_action( max( time() + 1, $at ), self::HOOK, $args, self::GROUP, true, 30 );
		return $id > 0 || (bool) as_has_scheduled_action( self::HOOK, $args, self::GROUP );
	}
}
