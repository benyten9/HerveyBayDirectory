<?php
/**
 * Action Scheduler 4.2 wake-up adapter.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Storage\ConversationStore;

/** Action Scheduler dispatches IDs only; it owns no chat/effect authority. */
final class ActionSchedulerQueue implements WakeQueue {
	/** Action group isolated from unrelated plugins. */
	private const GROUP = 'directorist-ai-pro';

	/**
	 * {@inheritdoc}
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function enqueue( string $run_id ): bool {
		$this->assert_available();
		ConversationStore::identifier( $run_id );
		if ( $this->has( $run_id ) ) {
			return true;
		}
		$arguments = array( 'run_id' => $run_id );
		// AS uniqueness includes the executing action and would discard its continuation.
		// Pending-only deduplication preserves that handoff; database leases fence races.
		$action_id = as_enqueue_async_action( 'directorist_ai_pro_run', $arguments, self::GROUP, false, 10 );
		return $action_id > 0 || $this->has( $run_id );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $run_id Opaque run ID.
	 * @param int    $timestamp Earliest Unix timestamp.
	 * @throws DispatchException Invalid time or unavailable queue.
	 */
	public function schedule( string $run_id, int $timestamp ): bool {
		$this->assert_available();
		ConversationStore::identifier( $run_id );
		if ( $timestamp < time() || $timestamp > time() + 31536000 ) {
			throw new DispatchException( 'Invalid background wake timestamp.' );
		}
		if ( $this->has( $run_id ) ) {
			return true;
		}
		$arguments = array( 'run_id' => $run_id );
		$action_id = as_schedule_single_action( $timestamp, 'directorist_ai_pro_run', $arguments, self::GROUP, false, 10 );
		return $action_id > 0 || $this->has( $run_id );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function has( string $run_id ): bool {
		$this->assert_available();
		return null !== \ActionScheduler::store()->query_action(
			array(
				'hook'   => 'directorist_ai_pro_run',
				'args'   => array( 'run_id' => $run_id ),
				'group'  => self::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $run_id Opaque run ID.
	 */
	public function unschedule( string $run_id ): void {
		if ( $this->available() ) {
			as_unschedule_all_actions( 'directorist_ai_pro_run', array( 'run_id' => $run_id ), self::GROUP );
		}
	}

	/** {@inheritdoc} */
	public function ensure_recurring(): void {
		$this->assert_available();
		if ( false === as_has_scheduled_action( 'directorist_ai_pro_recover', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, 60, 'directorist_ai_pro_recover', array(), self::GROUP, true, 20 );
		}
		if ( false === as_has_scheduled_action( 'directorist_ai_pro_lifecycle', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 3600, 3600, 'directorist_ai_pro_lifecycle', array(), self::GROUP, true, 30 );
		}
	}

	/** {@inheritdoc} */
	public function health(): array {
		return array(
			'available' => $this->available(),
			'version'   => class_exists( 'ActionScheduler_Versions', false ) ? \ActionScheduler_Versions::instance()->latest_version() : null,
		);
	}

	/** Determine whether a compatible initialized queue is callable. */
	private function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler', false ) && \ActionScheduler::is_initialized() && class_exists( 'ActionScheduler_Versions', false ) && version_compare( \ActionScheduler_Versions::instance()->latest_version(), '4.2.0', '>=' );
	}

	/**
	 * Fail clearly while leaving committed database work recoverable.
	 *
	 * @throws DispatchException Unavailable queue.
	 */
	private function assert_available(): void {
		if ( ! $this->available() ) {
			throw new DispatchException( 'Action Scheduler 4.2 or later is unavailable.' );
		}
	}
}
