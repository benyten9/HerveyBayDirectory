<?php


/**
 * Booking Page Renderer (React)
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;

class BookingPageRenderer extends BaseTemplateRenderer {

	private string $calendarModelClass;
	private string $eventModelClass;
	private string $globalSettingsClass;

	public function __construct(
		string $calendarModelClass,
		string $eventModelClass,
		string $globalSettingsClass
	) {
		parent::__construct();
		$this->calendarModelClass  = $calendarModelClass;
		$this->eventModelClass     = $eventModelClass;
		$this->globalSettingsClass = $globalSettingsClass;
	}

	public function render( $calendar_slug, $event_slug ) {
		// Prefer caller-supplied slugs (used by direct event share links) and
		// fall back to query string for the standard `?doublescale_booking_calendar=…`
		// flow so existing call sites stay byte-compatible.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public booking link: slugs are themselves the public identifier, no nonce applies.
		if ( ! $calendar_slug ) {
			$calendar_slug = Arr::get( $_GET, 'doublescale_booking_calendar', null );
		}
		if ( ! $event_slug ) {
			$event_slug = Arr::get( $_GET, 'event', null );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// `globalSettingsClass` is the booking-scoped settings helper which
		// always returns the schema-defaulted shape (`general`, `payments`,
		// `email`, `theme`). The public renderer reads `payments.currency`
		// and `general.time_format` directly, so it cannot tolerate the raw
		// option store leaking through.
		$global_settings = $this->globalSettingsClass::all();

		if ( ! $calendar_slug ) {
			return false;
		}

		$calendar = $this->calendarModelClass::where( 'slug', $calendar_slug )->first();
		if ( ! $calendar ) {
			return false;
		}

		if ( 'active' !== $calendar->status ) {
			return $this->render_unavailable();
		}

		$event = $this->eventModelClass::where( 'slug', $event_slug )
			->where( 'calendar_id', $calendar->id )
			->first();

		if ( ! $event ) {
			\doublescale_safe_redirect( home_url() );
		}

		// Prepare event data
		$event->hosts             = $this->get_event_hosts( $event );
		$event->fields            = $event->getFieldsAttribute();
		$event->availability_data = maybe_unserialize( $event->availability );
		$event->reserve           = $event->getReserveTimesAttribute();
		$event->limits_data       = $event->getLimitsAttribute();
		$event->advanced_settings = $event->getAdvancedSettingsAttribute();

		// Add config filter
		add_filter(
			'doublescale_booking_config',
			function ( $config ) use ( $calendar, $event, $global_settings ) {
				$config['calendar']        = $calendar->toArray();
				$config['global_settings'] = $global_settings;
				if ( $event ) {
					$config['event'] = $event->toArray();
				}
				return $config;
			}
		);

		return $this->render_react_page( 'doublescale-booking-booking-page' );
	}
}
