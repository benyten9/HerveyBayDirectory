<?php
/**
 * Shared runtime of the Amelia triggers: listens to {@see BookingWatcher}
 * and filters by service and employee (appointments) or event (events).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AmeliaBookings;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * AbstractAmeliaTrigger class
 */
abstract class AbstractAmeliaTrigger extends Trigger {

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'appointments';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'amelia';

	/**
	 * What the trigger is about: `appointment` or `event`.
	 *
	 * @var string
	 */
	protected $booking_type = 'appointment';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_amelia_booking_created';

	/**
	 * Load Hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		// AmeliaBookings ships with the free plugin; an older free has none.
		if ( ! class_exists( AmeliaBookings::class ) ) {
			return;
		}

		BookingWatcher::boot();
		add_action( $this->watch_hook, array( $this, 'on_watch' ), 10, 3 );
	}

	/**
	 * A change the watcher reported.
	 *
	 * @param mixed $booking Normalised booking.
	 * @param mixed $first   Old status / old start.
	 * @param mixed $second  New status.
	 * @return void
	 */
	public function on_watch( $booking, $first = null, $second = null ) {
		if ( ! is_array( $booking ) || ( $booking['type'] ?? '' ) !== $this->booking_type ) {
			return;
		}
		// A customer Amelia knows by phone only still starts the workflow (an SMS or WhatsApp one).
		if ( ! is_email( (string) ( $booking['email'] ?? '' ) ) && '' === trim( (string) ( $booking['phone'] ?? '' ) ) ) {
			return;
		}

		$extra = $this->extra_data( $booking, $first, $second );
		if ( null === $extra ) {
			return;
		}

		$this->process( AmeliaBookings::trigger_args( $booking, $extra ) );
	}

	/**
	 * Extra trigger data; null skips the change.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old status / old start.
	 * @param mixed                $second  New status.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		return array();
	}

	/**
	 * The chosen services / employees / events.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$data = (array) ( $args['data'] ?? array() );

		if ( 'event' === $this->booking_type ) {
			// The picker lists events, not their repeats: a repeat counts as its event.
			$event = (int) ( $data['event_root_id'] ?? 0 );
			return AmeliaBookings::filter_matches( $automation->get_setting( 'event_ids', array() ), $event > 0 ? $event : (int) ( $data['event_id'] ?? 0 ) );
		}

		return AmeliaBookings::filter_matches( $automation->get_setting( 'service_ids', array() ), (int) ( $data['service_id'] ?? 0 ) )
			&& AmeliaBookings::filter_matches( $automation->get_setting( 'employee_ids', array() ), (int) ( $data['employee_id'] ?? 0 ) );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		if ( ! class_exists( AmeliaBookings::class ) ) {
			return array();
		}

		if ( 'event' === $this->booking_type ) {
			return array(
				'event_ids' => array(
					'label'   => __( 'Target Events', 'doublescale' ),
					'type'    => 'multiselect',
					'options' => AmeliaBookings::event_options(),
					'help'    => __( 'Select events to filter this trigger. Leave empty to trigger for all events.', 'doublescale' ),
				),
			);
		}

		return array(
			'service_ids'  => array(
				'label'   => __( 'Target Services', 'doublescale' ),
				'type'    => 'multiselect',
				'options' => AmeliaBookings::service_options(),
				'help'    => __( 'Select services to filter this trigger. Leave empty to trigger for all services.', 'doublescale' ),
			),
			'employee_ids' => array(
				'label'   => __( 'Target Employees', 'doublescale' ),
				'type'    => 'multiselect',
				'options' => AmeliaBookings::employee_options(),
				'help'    => __( 'Select employees to filter this trigger. Leave empty to trigger for all employees.', 'doublescale' ),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		$ids = array(
			'type'  => 'array',
			'items' => array( 'type' => array( 'integer', 'string' ) ),
		);

		if ( 'event' === $this->booking_type ) {
			return array(
				'type'       => 'object',
				'properties' => array( 'event_ids' => $ids ),
			);
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'service_ids'  => $ids,
				'employee_ids' => $ids,
			),
		);
	}
}
