<?php
/**
 * Shared runtime of the LatePoint triggers: listens to {@see BookingEvents}
 * and filters by service and agent.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\LatepointBookings;
use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

/**
 * AbstractLatepointTrigger class
 */
abstract class AbstractLatepointTrigger extends Trigger {

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
	public $group = 'latepoint';

	/**
	 * The BookingEvents hook this trigger listens to.
	 *
	 * @var string
	 */
	protected $watch_hook = 'doublescale_latepoint_booking_created';

	/**
	 * Load Hooks
	 *
	 * @return void
	 */
	public function load_hooks() {
		// LatepointBookings ships with the free plugin; an older free has none.
		if ( ! class_exists( LatepointBookings::class ) ) {
			return;
		}

		BookingEvents::boot();
		add_action( $this->watch_hook, array( $this, 'on_change' ), 10, 3 );
	}

	/**
	 * A change BookingEvents reported.
	 *
	 * @param mixed $booking Normalised booking.
	 * @param mixed $first   Old status / old start.
	 * @param mixed $second  New status.
	 * @return void
	 */
	public function on_change( $booking, $first = null, $second = null ) {
		if ( ! is_array( $booking ) ) {
			return;
		}
		if ( ! is_email( (string) ( $booking['email'] ?? '' ) ) && '' === (string) ( $booking['phone'] ?? '' ) ) {
			return;
		}

		$extra = $this->extra_data( $booking, $first, $second );
		if ( null === $extra ) {
			return;
		}

		$this->process( LatepointBookings::trigger_args( $booking, $extra ) );
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
	 * The chosen services and agents.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$data = (array) ( $args['data'] ?? array() );

		return LatepointBookings::filter_matches( $automation->get_setting( 'service_ids', array() ), (int) ( $data['service_id'] ?? 0 ) )
			&& LatepointBookings::filter_matches( $automation->get_setting( 'agent_ids', array() ), (int) ( $data['agent_id'] ?? 0 ) );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		if ( ! class_exists( LatepointBookings::class ) ) {
			return array();
		}

		return array(
			'service_ids' => array(
				'label'   => __( 'Target Services', 'doublescale' ),
				'type'    => 'multiselect',
				'options' => LatepointBookings::service_options(),
				'help'    => __( 'Select services to filter this trigger. Leave empty to trigger for all services.', 'doublescale' ),
			),
			'agent_ids'   => array(
				'label'   => __( 'Target Agents', 'doublescale' ),
				'type'    => 'multiselect',
				'options' => LatepointBookings::agent_options(),
				'help'    => __( 'Select agents to filter this trigger. Leave empty to trigger for all agents.', 'doublescale' ),
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

		return array(
			'type'       => 'object',
			'properties' => array(
				'service_ids' => $ids,
				'agent_ids'   => $ids,
			),
		);
	}
}
