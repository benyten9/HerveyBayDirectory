<?php
/**
 * Shared booking lifecycle → Action Scheduler bridge for calendar integrations.
 *
 * Heavy integrations (Google, Outlook, …) should enqueue work instead of
 * blocking the HTTP request that emitted {@see BookingEvents::emit()}.
 *
 * @package DoubleScale\Pro\Modules\Booking\Integrations
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations;

use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Pro\Modules\Tasks\Tasks;

defined( 'ABSPATH' ) || exit;

/**
 * Register thin lifecycle listeners that enqueue async Tasks jobs.
 *
 * Expected public methods on the using class (called when the job runs, not
 * from these hooks): `add_event_to_calendars`, `remove_event_from_calendars`,
 * `reschedule_event`.
 */
trait EnqueuesBookingLifecycleTasks {

	/**
	 * @var string
	 */
	protected $lifecycle_tasks_group = '';

	/**
	 * @var array<string,bool>
	 */
	protected $lifecycle_tasks_enabled = array();

	/**
	 * Subscribe to `doublescale_booking_*` hooks and enqueue matching Tasks.
	 *
	 * @param string   $group   Action Scheduler group / Tasks group slug.
	 * @param string[] $events  Lifecycle names to subscribe to (e.g. created, cancelled).
	 */
	protected function register_lifecycle_tasks( string $group, array $events = array( 'created', 'confirmed', 'cancelled', 'rescheduled' ) ): void {
		$this->lifecycle_tasks_group   = $group;
		$this->lifecycle_tasks_enabled = array_fill_keys( $events, true );

		if ( ! empty( $this->lifecycle_tasks_enabled['created'] ) ) {
			add_action( 'doublescale_booking_created', array( $this, 'lifecycle_enqueue_add_event' ), 10, 2 );
		}
		if ( ! empty( $this->lifecycle_tasks_enabled['confirmed'] ) ) {
			add_action( 'doublescale_booking_confirmed', array( $this, 'lifecycle_enqueue_add_event' ), 10, 2 );
		}
		if ( ! empty( $this->lifecycle_tasks_enabled['cancelled'] ) ) {
			add_action( 'doublescale_booking_cancelled', array( $this, 'lifecycle_enqueue_remove_event' ), 10, 2 );
		}
		if ( ! empty( $this->lifecycle_tasks_enabled['rescheduled'] ) ) {
			add_action( 'doublescale_booking_rescheduled', array( $this, 'lifecycle_enqueue_reschedule_event' ), 10, 2 );
		}
	}

	/**
	 * @param mixed $booking Booking model or numeric id.
	 */
	public function lifecycle_enqueue_add_event( $booking, $context = array() ): void {
		$this->enqueue_lifecycle_calendar_task( 'add_event', $booking );
	}

	/**
	 * @param mixed $booking Booking model or numeric id.
	 */
	public function lifecycle_enqueue_remove_event( $booking, $context = array() ): void {
		$this->enqueue_lifecycle_calendar_task( 'remove_event', $booking );
	}

	/**
	 * @param mixed $booking Booking model or numeric id.
	 */
	public function lifecycle_enqueue_reschedule_event( $booking, $context = array() ): void {
		$this->enqueue_lifecycle_calendar_task( 'reschedule_event', $booking );
	}

	/**
	 * @param string $hook_suffix Short hook passed to {@see Tasks::enqueue_async()} (becomes `{group}_{suffix}`).
	 * @param mixed  $booking     Booking model or numeric id.
	 */
	private function enqueue_lifecycle_calendar_task( string $hook_suffix, $booking ): void {
		if ( '' === $this->lifecycle_tasks_group ) {
			return;
		}

		$model = $booking instanceof BookingModel ? $booking : BookingModel::find( (int) $booking );
		if ( ! $model ) {
			return;
		}

		$tasks = new Tasks( $this->lifecycle_tasks_group );
		$tasks->enqueue_async( $hook_suffix, (int) $model->id );
	}
}
