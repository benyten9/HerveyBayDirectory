<?php

/**
 * Base Template Renderer
 *
 * Contains common functionality for all renderers
 */

namespace DoubleScale\Modules\Booking\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Data\BookingDataFormatter;
use DoubleScale\Modules\Booking\Models\UserModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;

abstract class BaseTemplateRenderer {

	protected BookingDataFormatter $dataFormatter;

	public function __construct() {
		$this->dataFormatter = new BookingDataFormatter();
	}

	/**
	 * Get global time format setting
	 */
	protected function get_time_format(): string {
		// Get global settings - using the correct option name
		$global_settings = get_option( 'doublescale_booking_settings', array() );
		return $global_settings['general']['time_format'] ?? '12';
	}

	/**
	 * Format hosts data for templates.
	 *
	 * `$booking->hosts` is a `hasManyThrough` to `UserModel`, so each `$host`
	 * is a wp_users row exposing `ID`, `display_name`, and `user_email`.
	 * Hosts whose WP user has been deleted are absent from the collection
	 * because hasManyThrough uses an INNER JOIN.
	 *
	 * @param object $booking Booking object with hosts.
	 * @return array Formatted hosts array.
	 */
	protected function format_hosts_data( $booking ): array {
		$hosts = array();

		foreach ( $booking->hosts as $host ) {
			$hosts[] = array(
				'name'  => $host->display_name ?? '',
				'image' => $host->ID ? get_avatar_url( $host->ID ) : '',
				'email' => $host->user_email ?? '',
			);
		}

		return $hosts;
	}

	/**
	 * Get common head HTML.
	 *
	 * Public booking pages bypass the WP template hierarchy (`exit()` after
	 * render), so wp_head() never runs. We mimic the essentials here:
	 *
	 *   1. Fire `wp_enqueue_scripts` so listeners (e.g. BookingFrontendHandler)
	 *      get a chance to register/enqueue.
	 *   2. Run our own enqueues AFTER that hook so they aren't wiped by the
	 *      handler's queue reset.
	 *   3. Print enqueued styles + head scripts so the `<link>` tags actually
	 *      land in <head>.
	 */
	protected function get_head( $title = '' ) {
		ob_start();
		?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
		<head>
			<meta http-equiv="Content-type" content="text/html; charset=utf-8" />
			<meta http-equiv="Imagetoolbar" content="No" />
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ?: __( 'Booking', 'doublescale' ) ); ?></title>
			<meta name="robots" content="noindex">
			<?php
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core action; booking renderer mimics `wp_head()` flow because public pages bypass the WP template hierarchy.
			do_action( 'wp_enqueue_scripts' );
			$this->enqueue_deferred_assets();
			wp_print_styles();
			wp_print_head_scripts();
			?>
		</head>
		<body class="doublescale-booking-body">
		<?php
		return ob_get_clean();
	}

	/**
	 * Slots for assets that must be enqueued AFTER `wp_enqueue_scripts` fires
	 * (because that hook resets the queue). Populated by render_template_page /
	 * render_react_page / render_unavailable before they call get_head().
	 *
	 * @var array{handles: array<int, string>, template_path: string, inline_data: array}
	 */
	private $deferred = array(
		'handles'       => array(),
		'template_path' => '',
		'inline_data'   => array(),
		'react'         => false,
	);

	/**
	 * Schedule which assets get enqueued from inside get_head() (post-hook).
	 *
	 * @param array{
	 *   handles?: array<int, string>,
	 *   template_path?: string,
	 *   inline_data?: array,
	 *   react?: bool
	 * } $assets
	 */
	private function defer_assets( array $assets ): void {
		$this->deferred = array_merge( $this->deferred, $assets );
	}

	/**
	 * Run the deferred enqueues inside get_head(), after wp_enqueue_scripts fired.
	 */
	private function enqueue_deferred_assets(): void {
		if ( ! empty( $this->deferred['handles'] ) ) {
			foreach ( $this->deferred['handles'] as $handle ) {
				if ( wp_style_is( $handle, 'registered' ) ) {
					wp_enqueue_style( $handle );
				}
				if ( wp_script_is( $handle, 'registered' ) ) {
					wp_enqueue_script( $handle );
				}
			}
		}
		if ( ! empty( $this->deferred['template_path'] ) ) {
			$this->enqueue_template_assets(
				$this->deferred['template_path'],
				$this->deferred['inline_data']
			);
		}
		if ( ! empty( $this->deferred['react'] ) ) {
			wp_enqueue_script( 'doublescale-booking-renderer' );
			wp_enqueue_style( 'doublescale-booking-renderer' );
		}
	}

	/**
	 * Get common footer HTML
	 */
	protected function get_footer() {
		ob_start();
		wp_footer();
		?>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Queue the shared booking-page CSS/JS for the next get_head() run.
	 */
	protected function enqueue_page_assets() {
		$current = $this->deferred['handles'];
		if ( ! in_array( 'doublescale-booking-page', $current, true ) ) {
			$current[] = 'doublescale-booking-page';
		}
		$this->defer_assets( array( 'handles' => $current ) );
	}

	/**
	 * Queue the React renderer bundle for the next get_head() run.
	 */
	protected function enqueue_react_assets() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Plugin-prefixed action; the prefix-detection rule misfires on dynamic hooks.
		do_action( 'doublescale_booking_renderer_enqueue_scripts' );
		$this->defer_assets( array( 'react' => true ) );
	}

	/**
	 * Get event hosts data
	 */
	protected function get_event_hosts( $event ) {
		$ids   = $event->getTeamMembersAttribute() ?: array( $event->user_id );
		$ids   = is_array( $ids ) ? $ids : array( $ids );
		$hosts = array();

		foreach ( $ids as $userId ) {
			$user = UserModel::find( $userId );
			if ( ! $user ) {
				continue;
			}
			$hosts[] = array(
				'id'    => $user->ID,
				'name'  => $user->display_name,
				'image' => get_avatar_url( $user->ID ),
			);
		}

		return $hosts;
	}

	/**
	 * Render React page helper
	 */
	protected function render_react_page( string $div_id ) {
		$this->enqueue_react_assets();
		echo $this->get_head(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML head markup.
		printf( '<div id="%s"></div>', esc_attr( $div_id ) );
		echo $this->get_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML footer markup.
		exit( 200 );
	}

	/**
	 * Render the "calendar no longer available" page used when a host calendar has
	 * been soft-retired (status !== 'active'). Halts further WP rendering.
	 *
	 * @return mixed
	 */
	protected function render_unavailable() {
		$this->enqueue_page_assets();

		$title = __( 'Booking link unavailable', 'doublescale' );

		echo $this->get_head( $title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML head markup.
		?>
		<div class="doublescale-booking-unavailable" style="max-width:560px;margin:80px auto;padding:32px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<h1 style="font-size:24px;margin-bottom:16px;"><?php echo esc_html__( 'This booking link is no longer available.', 'doublescale' ); ?></h1>
			<p style="color:#555;line-height:1.6;">
				<?php
				printf(
					/* translators: %s: link to the site home page. */
					esc_html__( 'Please visit %s for more information.', 'doublescale' ),
					'<a href="' . esc_url( home_url() ) . '">' . esc_html( home_url() ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
		echo $this->get_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML footer markup.

		exit( 200 );
	}

	/**
	 * Register and enqueue the template-specific CSS/JS, if a sibling
	 * `assets/css/booking-renderer/{name}.css` or `assets/js/booking-renderer/{name}.js`
	 * exists. Inline data (e.g. nonces, hash ids) is passed via `wp_add_inline_script`
	 * as a `window.doublescaleBooking<Name>` object.
	 *
	 * @param string $template_path Path to the template being rendered.
	 * @param array  $inline_data   Per-template values to surface to the JS layer.
	 */
	protected function enqueue_template_assets( string $template_path, array $inline_data = array() ): void {
		$basename = pathinfo( $template_path, PATHINFO_FILENAME );
		$css_rel  = 'assets/css/booking-renderer/' . $basename . '.css';
		$js_rel   = 'assets/js/booking-renderer/' . $basename . '.js';
		$css_abs  = DOUBLESCALE_PLUGIN_DIR . $css_rel;
		$js_abs   = DOUBLESCALE_PLUGIN_DIR . $js_rel;

		if ( file_exists( $css_abs ) ) {
			$handle = 'doublescale-booking-template-' . $basename;
			wp_register_style( $handle, DOUBLESCALE_PLUGIN_URL . $css_rel, array(), DOUBLESCALE_VERSION );
			wp_enqueue_style( $handle );
		}

		if ( file_exists( $js_abs ) ) {
			$handle = 'doublescale-booking-template-' . $basename;
			wp_register_script( $handle, DOUBLESCALE_PLUGIN_URL . $js_rel, array(), DOUBLESCALE_VERSION, true );
			if ( ! empty( $inline_data ) ) {
				$object_name = 'doublescaleBooking' . str_replace( ' ', '', ucwords( str_replace( array( '-', '_' ), ' ', $basename ) ) );
				wp_add_inline_script(
					$handle,
					'window.' . $object_name . ' = ' . wp_json_encode( $inline_data ) . ';',
					'before'
				);
			}
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Render template page helper
	 */
	protected function render_template_page( string $template_path, array $variables = array() ) {
		if ( ! file_exists( $template_path ) ) {
			return false;
		}

		$this->enqueue_page_assets();
		$inline_js_data = isset( $variables['__js_data'] ) && is_array( $variables['__js_data'] )
			? $variables['__js_data']
			: array();
		unset( $variables['__js_data'] );
		$this->defer_assets(
			array(
				'template_path' => $template_path,
				'inline_data'   => $inline_js_data,
			)
		);
		extract( $variables );

		echo $this->get_head( $variables['title'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML head markup.
		include $template_path;
		echo $this->get_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal trusted HTML footer markup.

		exit( 200 );
	}

	/**
	 * Check if booking can be cancelled based on advanced settings
	 *
	 * @param array  $advanced_settings Event advanced settings
	 * @param array  $booking_array Booking data array
	 * @param string $timezone Timezone for calculations
	 * @return array Returns array with 'can_cancel' boolean and 'message' string
	 */
	protected function check_cancellation_permissions( $advanced_settings, $booking_array, $timezone ) {
		$can_cancel            = true;
		$cancel_denied_message = '';

		// Get booking model for merge tag processing
		$find_booking = BookingModel::find( $booking_array['id'] );

		// Process merge tags for permission denied message
		$cancel_denied_message = MergeTagsManager::instance()->process_merge_tags(
			$advanced_settings['permission_denied_message'] ?? '',
			$find_booking
		);

		// Check if attendee cannot cancel at event start
		if ( ! empty( $advanced_settings['attendee_cannot_cancel'] ) && $advanced_settings['attendee_cannot_cancel'] ) {
			// Default restriction is 'event_start' so legacy events (which may
			// have `attendee_cannot_cancel=true` without an explicit time key)
			// behave like the UI's default radio selection instead of silently
			// allowing cancellation.
			$cancel_time_restriction = $advanced_settings['cannot_cancel_time'] ?? 'event_start';

			if ( 'event_start' === $cancel_time_restriction ) {
				$can_cancel            = false;
				$cancel_denied_message = $cancel_denied_message ?: __( 'You do not have permission to cancel this booking.', 'doublescale' );
			} else {
				$cancel_time_value = (int) ( $advanced_settings['cannot_cancel_time_value'] ?? 24 );
				$cancel_time_unit  = $advanced_settings['cannot_cancel_time_unit'] ?? 'hours';
				if ( $cancel_time_value <= 0 ) {
					$cancel_time_value = 24;
				}
				if ( ! in_array( $cancel_time_unit, array( 'minutes', 'hours', 'days' ), true ) ) {
					$cancel_time_unit = 'hours';
				}

				if ( $cancel_time_restriction === 'less_than' ) {
					try {
						$start_time = $booking_array['start_time'] ?? '';
						$start_dt   = new \DateTime( $start_time, new \DateTimeZone( 'UTC' ) );
						$start_dt->setTimezone( new \DateTimeZone( $timezone ) );

						$current_time     = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
						$restriction_time = clone $start_dt;

						// Calculate the restriction time based on settings
						$time_modifier = "-{$cancel_time_value} {$cancel_time_unit}";
						$restriction_time->modify( $time_modifier );

						// If current time is past the restriction time, prevent cancellation
						if ( $current_time >= $restriction_time ) {
							$can_cancel            = false;
							$cancel_denied_message = $cancel_denied_message ?: __( 'Cancellation is no longer allowed for this booking.', 'doublescale' );
						}
					} catch ( \Exception $e ) {
						// If there's an error with time calculation, allow cancellation for safety
						$can_cancel = true;
					}
				}
			}
		}

		return array(
			'can_cancel' => $can_cancel,
			'message'    => $cancel_denied_message,
		);
	}

	/**
	 * Check if booking can be rescheduled based on advanced settings
	 *
	 * @param array  $advanced_settings Event advanced settings
	 * @param array  $booking_array Booking data array
	 * @param string $timezone Timezone for calculations
	 * @return array Returns array with 'can_reschedule' boolean and 'message' string
	 */
	protected function check_reschedule_permissions( $advanced_settings, $booking_array, $timezone ) {
		$can_reschedule            = true;
		$reschedule_denied_message = '';

		// Get booking model for merge tag processing
		$find_booking = BookingModel::find( $booking_array['id'] );

		// Process merge tags for permission denied message
		$reschedule_denied_message = MergeTagsManager::instance()->process_merge_tags(
			$advanced_settings['reschedule_denied_message'] ?? '',
			$find_booking
		);

		if ( ! empty( $advanced_settings['attendee_cannot_reschedule'] ) && $advanced_settings['attendee_cannot_reschedule'] ) {
			// Same defaulting logic as cancellation: legacy events without
			// `cannot_reschedule_time` are treated as 'event_start'.
			$reschedule_time_restriction = $advanced_settings['cannot_reschedule_time'] ?? 'event_start';

			if ( 'event_start' === $reschedule_time_restriction ) {
				$can_reschedule            = false;
				$reschedule_denied_message = $reschedule_denied_message ?: __( 'You do not have permission to reschedule this booking.', 'doublescale' );
			} else {
				$reschedule_time_value = (int) ( $advanced_settings['cannot_reschedule_time_value'] ?? 24 );
				$reschedule_time_unit  = $advanced_settings['cannot_reschedule_time_unit'] ?? 'hours';
				if ( $reschedule_time_value <= 0 ) {
					$reschedule_time_value = 24;
				}
				if ( ! in_array( $reschedule_time_unit, array( 'minutes', 'hours', 'days' ), true ) ) {
					$reschedule_time_unit = 'hours';
				}

				if ( $reschedule_time_restriction === 'less_than' ) {
					try {
						$start_time = $booking_array['start_time'] ?? '';
						$start_dt   = new \DateTime( $start_time, new \DateTimeZone( 'UTC' ) );
						$start_dt->setTimezone( new \DateTimeZone( $timezone ) );

						$current_time     = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
						$restriction_time = clone $start_dt;

						// Calculate the restriction time based on settings
						$time_modifier = "-{$reschedule_time_value} {$reschedule_time_unit}";
						$restriction_time->modify( $time_modifier );

						// If current time is past the restriction time, prevent rescheduling
						if ( $current_time >= $restriction_time ) {
							$can_reschedule            = false;
							$reschedule_denied_message = $reschedule_denied_message ?: __( 'Rescheduling is no longer allowed for this booking.', 'doublescale' );
						}
					} catch ( \Exception $e ) {
						// If there's an error with time calculation, allow rescheduling for safety
						$can_reschedule = true;
					}
				}
			}
		}

		return array(
			'can_reschedule' => $can_reschedule,
			'message'        => $reschedule_denied_message,
		);
	}

	// /**
	// * Render method - must be implemented by child classes
	// *
	// * @param mixed ...$args Variable arguments depending on renderer type
	// * @return mixed|false Returns rendered content or false on failure
	// */
	// abstract public function render( ...$args );
}
