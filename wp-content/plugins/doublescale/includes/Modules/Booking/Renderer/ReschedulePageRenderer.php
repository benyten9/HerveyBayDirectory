<?php

/**
 * Reschedule Page Renderer (React)
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;

class ReschedulePageRenderer extends BaseTemplateRenderer {

	private string $eventModelClass;
	private string $bookingValidatorClass;
	private string $globalSettingsClass;
	private string $calendarModelClass;

	public function __construct(
		string $eventModelClass,
		string $bookingValidatorClass,
		string $globalSettingsClass,
		string $calendarModelClass
	) {
		parent::__construct();
		$this->eventModelClass       = $eventModelClass;
		$this->bookingValidatorClass = $bookingValidatorClass;
		$this->globalSettingsClass   = $globalSettingsClass;
		$this->calendarModelClass    = $calendarModelClass;
	}

	public function render( $booking ) {
		// See note in BookingPageRenderer::render — booking renderers need the
		// schema-defaulted shape so `payments.currency` etc. are guaranteed.
		$global_settings = $this->globalSettingsClass::all();

		$bookable = $booking ? $booking->getBookableEntity() : null;
		if ( ! $booking || ! $bookable ) {
			\doublescale_safe_redirect( home_url() );
		}

		$calendar = $this->calendarModelClass::where( 'id', $booking->calendar_id )->first();
		if ( ! $calendar ) {
			\doublescale_safe_redirect( home_url() );
		}

		if ( 'active' !== $calendar->status ) {
			return $this->render_unavailable();
		}

		$event      = $booking->event;
		$event_data = array();

		if ( $event ) {
			$event->hosts             = $this->get_event_hosts( $event );
			$event->fields            = $event->getFieldsAttribute();
			$event->availability_data = maybe_unserialize( $event->availability );
			$event->reserve           = $event->getReserveTimesAttribute();
			$event->advanced_settings = $event->getAdvancedSettingsAttribute();
			$event_data               = $event->toArray();
		}

		$booking_array          = $this->dataFormatter->format_booking_data( $booking );
		$advanced_settings      = $booking->getAdvancedSettings();
		$timezone               = $booking_array['timezone'] ?? 'UTC';
		$reschedule_permissions = $this->check_reschedule_permissions( $advanced_settings, $booking_array, $timezone );

		add_filter(
			'doublescale_booking_config',
			function ( $config ) use ( $booking, $calendar, $event_data, $global_settings, $reschedule_permissions ) {
				$config['calendar']                  = $calendar->toArray();
				$config['event']                     = $event_data;
				$config['booking']                   = $booking->toArray();
				$config['global_settings']           = $global_settings;
				$config['can_reschedule']            = $reschedule_permissions['can_reschedule'];
				$config['reschedule_denied_message'] = $reschedule_permissions['message'];
				return $config;
			}
		);

		return $this->render_react_page( 'doublescale-booking-reschedule-page' );
	}
}
