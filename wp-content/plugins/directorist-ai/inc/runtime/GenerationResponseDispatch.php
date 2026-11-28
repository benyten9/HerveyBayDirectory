<?php
/**
 * Finish an authorized generation response before attempting one fenced step.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Storage\ConversationStore;

/** Request-local transport only; database leases and normal workers own execution. */
final class GenerationResponseDispatch {
	/**
	 * One wake per run in this PHP request, across API instances.
	 *
	 * @var array<string,bool>
	 */
	private static array $requested = array();
	/**
	 * Trusted response finisher; null selects the current SAPI.
	 *
	 * @var \Closure|false|null
	 */
	private \Closure|false|null $finish_response;
	/**
	 * Trusted shutdown registrar; never supplied by an HTTP request.
	 *
	 * @var \Closure
	 */
	private \Closure $register_shutdown;
	/**
	 * Whether this helper has already finished the HTTP response.
	 *
	 * @var bool|null
	 */
	private ?bool $finished = null;

	/**
	 * Optional server-only seams allow testing without finishing a real HTTP response.
	 *
	 * @param callable|false|null $finish_response Finisher, false to use the queue, or automatic SAPI detection.
	 * @param callable|null       $register_shutdown Request-local shutdown registrar.
	 */
	public function __construct( callable|false|null $finish_response = null, ?callable $register_shutdown = null ) {
		$this->finish_response   = is_callable( $finish_response ) ? \Closure::fromCallable( $finish_response ) : $finish_response;
		$this->register_shutdown = null !== $register_shutdown ? \Closure::fromCallable( $register_shutdown ) : static fn( callable $callback ): bool => add_action( 'shutdown', $callback, 0 );
	}

	/**
	 * Called only after the generation POST's complete owner/native/due checks.
	 *
	 * Shutdown follows REST serialization. Priority zero finishes its serialized
	 * packet before ordinary shutdown queue dispatch or other slow cleanup.
	 * A missing/failed finisher always uses the existing durable queue instead.
	 *
	 * @param Dispatcher $dispatcher Existing fenced coordinator.
	 * @param string     $run_id Already authorized exact run.
	 */
	public function defer( Dispatcher $dispatcher, string $run_id ): void {
		ConversationStore::identifier( $run_id );
		if ( isset( self::$requested[ $run_id ] ) ) {
			return;
		}
		self::$requested[ $run_id ] = true;
		$finish                     = $this->finisher();
		if ( null === $finish ) {
			self::schedule( $dispatcher, $run_id );
			return;
		}
		$called   = false;
		$callback = function () use ( $dispatcher, $run_id, $finish, &$called ): void {
			if ( $called ) {
				return;
			}
			$called = true;
			if ( null === $this->finished ) {
				try {
					$this->finished = true === $finish();
				} catch ( \Throwable $error ) {
					$this->finished = false;
				}
			}
			if ( ! $this->finished ) {
				self::schedule( $dispatcher, $run_id );
				return;
			}
			// HTTP has deliberately ended. Incidental native output or a closed
			// client connection must not silently terminate this fenced worker.
			$previous_abort = ignore_user_abort( true );
			$buffer_level   = ob_get_level();
			ob_start( static fn( string $output ): string => '', 8192 );
			try {
				// Claim, active ownership, cancellation, actor and source checks are
				// unchanged. This callback supplies no lease or effect authority.
				$dispatcher->handle( $run_id );
			} catch ( \Throwable $error ) {
				// Never invent completion after a background transport/storage failure.
				// Durable state and the normal queue/recovery path retain uncertainty.
				self::schedule( $dispatcher, $run_id );
			} finally {
				while ( ob_get_level() > $buffer_level ) {
					if ( ! ob_end_clean() ) {
						break;
					}
				}
				ignore_user_abort( (bool) $previous_abort );
			}
		};
		try {
			if ( false === ( $this->register_shutdown )( $callback ) ) {
				$called = true;
				self::schedule( $dispatcher, $run_id );
			}
		} catch ( \Throwable $error ) {
			$called = true;
			self::schedule( $dispatcher, $run_id );
		}
	}

	/** Only a real REST HTTP request may finish a real server response. */
	private function finisher(): ?\Closure {
		if ( false === $this->finish_response ) {
			return null;
		}
		if ( $this->finish_response instanceof \Closure ) {
			return $this->finish_response;
		}
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return null;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			return static fn(): bool => fastcgi_finish_request();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			return static fn(): bool => litespeed_finish_request();
		}
		return null;
	}

	/**
	 * Queue failure leaves the committed task available to the normal recovery sweep.
	 *
	 * @param Dispatcher $dispatcher Existing fenced coordinator.
	 * @param string     $run_id Authorized exact run.
	 */
	private static function schedule( Dispatcher $dispatcher, string $run_id ): void {
		try {
			$dispatcher->schedule( $run_id );
		} catch ( \Throwable $error ) {
			// No processed/completed claim is made by this transport.
		}
	}
}
