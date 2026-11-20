<?php
/**
 * Amelia "Appointment Status Changed" trigger.
 *
 * Fires from {@see BookingWatcher} (doublescale_amelia_booking_status_changed),
 * once per customer booking whose status changed, with From / To filters.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AmeliaBookings;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * AppointmentStatusChanged trigger
 */
class AppointmentStatusChanged extends AbstractAmeliaTrigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Appointment Status Changed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_appointment_status_changed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when the status of an Amelia appointment booking changes.';

	/**
	 * The watcher hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_amelia_booking_status_changed';

	/**
	 * Old and new status.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param mixed                $first   Old status.
	 * @param mixed                $second  New status.
	 * @return array<string, mixed>|null
	 */
	protected function extra_data( array $booking, $first, $second ): ?array {
		return array(
			'old_status' => (string) $first,
			'new_status' => (string) $second,
		);
	}

	/**
	 * From / to statuses, then services and employees.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$from = (string) $automation->get_setting( 'from_status', 'any' );
		$to   = (string) $automation->get_setting( 'to_status', 'any' );

		if ( '' !== $from && 'any' !== $from && ( $args['data']['old_status'] ?? '' ) !== $from ) {
			return false;
		}
		if ( '' !== $to && 'any' !== $to && ( $args['data']['new_status'] ?? '' ) !== $to ) {
			return false;
		}

		return parent::is_processable( $automation, $args );
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

		$statuses = array_merge( array( 'any' => __( 'Any status', 'doublescale' ) ), AmeliaBookings::statuses() );

		return array_merge(
			array(
				'from_status' => array(
					'label'   => __( 'From Status', 'doublescale' ),
					'type'    => 'select',
					'options' => $statuses,
					'default' => 'any',
				),
				'to_status'   => array(
					'label'   => __( 'To Status', 'doublescale' ),
					'type'    => 'select',
					'options' => $statuses,
					'default' => 'any',
				),
			),
			parent::get_fields()
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		$schema                              = parent::get_attributes_schema();
		$schema['properties']['from_status'] = array( 'type' => 'string' );
		$schema['properties']['to_status']   = array( 'type' => 'string' );

		return $schema;
	}
}
