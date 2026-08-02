<?php
/**
 * Booking Frontend Handler
 *
 * Lightweight router that delegates to specific renderers.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Exceptions\BookingNotFoundException;
use DoubleScale\Modules\Booking\Exceptions\InvalidBookingHashException;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Models\EventModel;
use DoubleScale\Modules\Booking\Services\BookingValidator;
use DoubleScale\Modules\Booking\Helpers\BookingSettings;

class BookingFrontendHandler {

	private string $calendarModelClass;
	private string $eventModelClass;
	private string $bookingValidatorClass;
	private string $globalSettingsClass;
	private TemplateRendererFactory $rendererFactory;

	public function __construct(
		string $calendarModelClass = CalendarModel::class,
		string $eventModelClass = EventModel::class,
		string $bookingValidatorClass = BookingValidator::class,
		string $globalSettingsClass = BookingSettings::class
	) {
		$this->calendarModelClass    = $calendarModelClass;
		$this->eventModelClass       = $eventModelClass;
		$this->bookingValidatorClass = $bookingValidatorClass;
		$this->globalSettingsClass   = $globalSettingsClass;
		$this->rendererFactory       = new TemplateRendererFactory();

		add_action( 'wp_loaded', array( $this, 'init' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ) );
	}

	public function enqueue_scripts() {
		global $wp_scripts, $wp_styles;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public booking link: identity comes from the hash in the URL, no nonce applies.
		$calendar_slug = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_calendar', '' ) ) );
		$booking_qs    = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking', '' ) ) );
		$event_slug_qs = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_event', '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $calendar_slug && ! $booking_qs && ! $event_slug_qs ) {
			return;
		}

		// Reset the queue to drop theme/other-plugin styles that would otherwise
		// inject into the standalone booking page. Anything our renderer needs
		// is (re-)enqueued inside `BaseTemplateRenderer::get_head()` AFTER this
		// action fires.
		$wp_scripts->queue = array();
		$wp_styles->queue  = array();

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';

		$asset_file = $plugin_dir . 'build/renderer/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$deps       = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;

		wp_register_script(
			'doublescale-booking-renderer',
			$plugin_url . 'build/renderer/index.js',
			$deps,
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'doublescale-booking-renderer', 'doublescale', $plugin_dir . 'languages' );
		}

		wp_register_style(
			'doublescale-booking-renderer',
			$plugin_url . 'build/renderer/style.css',
			array(),
			$ver
		);

		wp_register_style(
			'doublescale-booking-page',
			$plugin_url . 'assets/css/booking-style.css',
			array(),
			$ver
		);

		wp_register_script(
			'doublescale-booking-page',
			$plugin_url . 'assets/js/booking-script.js',
			$deps,
			$ver,
			true
		);

		$timezones = \DateTimeZone::listIdentifiers();
		$tz_map    = array();
		foreach ( $timezones as $tz ) {
			$tz_map[ $tz ] = $tz;
		}

		wp_localize_script(
			'doublescale-booking-renderer',
			'doublescale_booking_config',
			apply_filters(
				'doublescale_booking_config',
				array(
					'ajax_url'  => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'doublescale_booking' ),
					'url'       => home_url(),
					'lang'      => get_locale(),
					'timezones' => $tz_map,
				)
			)
		);

		// Mirror the admin's pro-feature gating into the renderer. When Pro
		// isn't installed, hide the payment / waiting-list UI on public
		// booking pages. The constant is defined by the Pro plugin itself,
		// so the `defined()` guard prevents a notice on free-only installs.
		$is_pro_active = function_exists( 'doublescale_is_pro_addon_active' )
			&& doublescale_is_pro_addon_active();
		wp_add_inline_script(
			'doublescale-booking-renderer',
			sprintf(
				'window.doublescale = window.doublescale || {}; window.doublescale.booking_pro_active = %s;',
				wp_json_encode( $is_pro_active )
			),
			'before'
		);

		wp_style_add_data( 'doublescale-booking-renderer', 'rtl', 'replace' );

		do_action( 'doublescale_booking_renderer_enqueue_scripts' );
	}

	public function init() {
		add_action( 'template_redirect', array( $this, 'route_frontend' ) );
	}

	public function route_frontend() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public booking link: identity comes from the URL hash; route_frontend handles unauthenticated visitors.
		$hash              = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'id', '' ) ) );
		$type              = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'type', '' ) ) );
		$calendar_slug     = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_calendar', '' ) ) );
		$booking_qs        = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking', '' ) ) );
		$event_slug        = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'event', '' ) ) );
		$direct_event_slug = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_event', '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Direct event share link (`?doublescale_booking_event=<slug>`): resolve the
		// event by slug, then render the standard booking page using its parent
		// calendar's slug.
		if ( $direct_event_slug ) {
			$event = $this->eventModelClass::where( 'slug', $direct_event_slug )->first();
			if ( ! $event ) {
				wp_die( esc_html__( 'Event not found.', 'doublescale' ), 404 );
			}
			$calendar = $this->calendarModelClass::find( $event->calendar_id );
			if ( ! $calendar ) {
				wp_die( esc_html__( 'Event calendar not found.', 'doublescale' ), 404 );
			}
			return $this->render_booking_page( $calendar->slug, $event->slug );
		}

		if ( ! $calendar_slug && ! $booking_qs ) {
			return;
		}

		if ( $calendar_slug && ! $hash && ! $type && ! $event_slug ) {
			return $this->render_calendar_page( $calendar_slug );
		}

		if ( $this->is_valid_page_type( $type ) ) {
			return $this->render_action_page( $hash, $type );
		}

		return $this->render_booking_page( $calendar_slug, $event_slug );
	}

	public function hide_admin_bar( $show_bar ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public booking link detection for admin-bar suppression.
		$calendar_slug = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_calendar', '' ) ) );
		$booking_qs    = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking', '' ) ) );
		$event_slug_qs = sanitize_text_field( wp_unslash( Arr::get( $_GET, 'doublescale_booking_event', '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $calendar_slug || $booking_qs || $event_slug_qs ) {
			return false;
		}

		return $show_bar;
	}

	private function render_calendar_page( string $calendar_slug ) {
		$renderer = $this->rendererFactory->create_calendar_renderer(
			$this->calendarModelClass
		);
		return $renderer->render( $calendar_slug );
	}

	private function render_booking_page( string $calendar_slug, string $event_slug ) {
		$renderer = $this->rendererFactory->create_booking_renderer(
			$this->calendarModelClass,
			$this->eventModelClass,
			$this->globalSettingsClass
		);
		return $renderer->render( $calendar_slug, $event_slug );
	}

	private function render_action_page( string $hash, string $type ) {
		try {
			$booking = $this->bookingValidatorClass::validate_booking( $hash );

			$renderer = $this->rendererFactory->create_action_renderer(
				$type,
				$this->eventModelClass,
				$this->bookingValidatorClass,
				$this->globalSettingsClass,
				$this->calendarModelClass
			);

			return $renderer->render( $booking );
		} catch ( InvalidBookingHashException $e ) {
			return $this->render_error_page(
				__( 'Missing booking identifier', 'doublescale' ),
				__( 'This link is missing the booking identifier, so we can\'t look it up.', 'doublescale' ),
				__( 'Check that you opened the full link from your email — some clients trim long URLs.', 'doublescale' )
			);
		} catch ( BookingNotFoundException $e ) {
			return $this->render_error_page(
				__( 'Booking not found', 'doublescale' ),
				__( 'We couldn\'t find this booking. It may have been deleted, or the link has expired.', 'doublescale' ),
				__( 'If you believe this is a mistake, please contact the person who scheduled the booking.', 'doublescale' )
			);
		} catch ( \Exception $e ) {
			return $this->render_error_page(
				__( 'Something went wrong', 'doublescale' ),
				__( 'We hit an unexpected error loading this booking. Please try again in a moment.', 'doublescale' ),
				''
			);
		}
	}

	private function render_error_page( string $heading, string $message, string $detail = '' ) {
		$renderer = new ErrorPageRenderer();
		return $renderer->render( $heading, $message, $detail );
	}

	private function is_valid_page_type( string $type ): bool {
		return in_array( $type, array( 'cancel', 'reschedule', 'confirm', 'claim_waitlist' ), true );
	}
}
