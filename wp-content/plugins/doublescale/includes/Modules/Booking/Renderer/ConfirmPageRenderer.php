<?php
/**
 * Confirm Page Renderer
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

class ConfirmPageRenderer extends BaseTemplateRenderer {

	private string $eventModelClass;

	public function __construct( string $eventModelClass ) {
		parent::__construct();
		$this->eventModelClass = $eventModelClass;
	}

	public function render( $booking ) {
		$calendar = $booking ? $booking->calendar : null;
		if ( ! $calendar || 'active' !== $calendar->status ) {
			return $this->render_unavailable();
		}

		$time_format            = $this->get_time_format();
		$booking_array          = $this->dataFormatter->format_booking_data( $booking, $time_format );
		$booking_array['hosts'] = $this->format_hosts_data( $booking );

		$advanced_settings = $booking->getAdvancedSettings();
		$timezone          = $booking_array['timezone'] ?? 'UTC';

		// Check permissions using the base class methods
		$cancel_permissions     = $this->check_cancellation_permissions( $advanced_settings, $booking_array, $timezone );
		$reschedule_permissions = $this->check_reschedule_permissions( $advanced_settings, $booking_array, $timezone );

		// Include waiting list position if booking is in waiting status.
		$is_waiting            = 'waiting' === $booking->status;
		$waiting_list_position = $is_waiting ? $booking->waiting_list_position : null;

		$template_path = __DIR__ . '/templates/confirm.php';

		return $this->render_template_page(
			$template_path,
			array(
				'booking_array'             => $booking_array,
				'title'                     => $booking->getBookableName() ?: __( 'Booking Confirmation', 'doublescale' ),
				'can_cancel'                => $cancel_permissions['can_cancel'],
				'cancel_denied_message'     => $cancel_permissions['message'],
				'can_reschedule'            => $reschedule_permissions['can_reschedule'],
				'reschedule_denied_message' => $reschedule_permissions['message'],
				'is_waiting'                => $is_waiting,
				'waiting_list_position'     => $waiting_list_position,
			)
		);
	}
}
