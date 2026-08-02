<?php

/**
 * Template Renderer Factory
 *
 * Creates appropriate renderer instances
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Renderer\CalendarPageRenderer;
use DoubleScale\Modules\Booking\Renderer\BookingPageRenderer;
use DoubleScale\Modules\Booking\Renderer\CancelPageRenderer;
use DoubleScale\Modules\Booking\Renderer\ClaimWaitlistPageRenderer;
use DoubleScale\Modules\Booking\Renderer\ConfirmPageRenderer;
use DoubleScale\Modules\Booking\Renderer\ReschedulePageRenderer;

class TemplateRendererFactory {

	public function create_calendar_renderer( string $calendarModelClass ): CalendarPageRenderer {
		return new CalendarPageRenderer( $calendarModelClass );
	}

	public function create_booking_renderer(
		string $calendarModelClass,
		string $eventModelClass,
		string $globalSettingsClass
	): BookingPageRenderer {
		return new BookingPageRenderer(
			$calendarModelClass,
			$eventModelClass,
			$globalSettingsClass
		);
	}

	public function create_action_renderer(
		string $type,
		string $eventModelClass,
		string $bookingValidatorClass,
		string $globalSettingsClass,
		string $calendarModelClass
	) {
		switch ( $type ) {
			case 'cancel':
				return new CancelPageRenderer( $eventModelClass );
			case 'confirm':
				return new ConfirmPageRenderer( $eventModelClass );
			case 'reschedule':
				return new ReschedulePageRenderer(
					$eventModelClass,
					$bookingValidatorClass,
					$globalSettingsClass,
					$calendarModelClass
				);
			case 'claim_waitlist':
				return new ClaimWaitlistPageRenderer( $eventModelClass );
			default:
				throw new \InvalidArgumentException( esc_html( "Unknown page type: {$type}" ) );
		}
	}
}
