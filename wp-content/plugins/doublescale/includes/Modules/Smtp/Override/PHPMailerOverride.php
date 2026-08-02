<?php
/**
 * Class Mailer
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage override
 */

namespace DoubleScale\Modules\Smtp\Override;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Settings;
use DoubleScale\Modules\Smtp\Providers\Mailers;

/**
 * Mailer class.
 * Override the default PHPMailer class to catch emails.
 *
 * @since 1.0.0
 */
class PHPMailerOverride extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Modify the default send method to catch emails.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function send() {
		do_action( 'doublescale_smtp_before_get_settings' );

		// Snapshot message From before routing/force-from mutates it (used in failure diagnostics).
		$original_from = $this->From;

		// Get smart route based on from email
		$route                  = Settings::get_smart_route( $original_from );
		$default_connection_id  = $route['default_connection_id'];
		$default_connection     = $route['default_connection'];
		$fallback_connection_id = $route['fallback_connection_id'];
		$fallback_connection    = $route['fallback_connection'];

		do_action( 'doublescale_smtp_after_get_settings' );

		if ( ! $default_connection ) {
			return parent::send();
		}

		// Store original values before any modifications (for fallback)
		$original_from      = $this->From;
		$original_from_name = $this->FromName;

		// Apply force from email and name BEFORE provider processing
		$force_from_email      = $default_connection['force_from_email'] ?? false;
		$connection_from_email = $default_connection['from_email'] ?? '';

		if ( $force_from_email && ! empty( $connection_from_email ) && is_email( $connection_from_email ) ) {
			$this->From = $connection_from_email;

			/**
			 * Fires when force from email is applied.
			 *
			 * @since 1.0.0
			 *
			 * @param string $forced_email The forced from email address.
			 * @param string $original_email The original from email address.
			 * @param string $connection_id The connection ID.
			 */
			do_action( 'doublescale_smtp_force_from_email_applied', $connection_from_email, $original_from, $default_connection_id );
		}

		$force_from_name      = $default_connection['force_from_name'] ?? false;
		$connection_from_name = $default_connection['from_name'] ?? '';

		if ( $force_from_name && ! empty( $connection_from_name ) ) {
			$this->FromName = $connection_from_name;

			/**
			 * Fires when force from name is applied.
			 *
			 * @since 1.0.0
			 *
			 * @param string $forced_name The forced from name.
			 * @param string $original_name The original from name.
			 * @param string $connection_id The connection ID.
			 */
			do_action( 'doublescale_smtp_force_from_name_applied', $connection_from_name, $original_from_name, $default_connection_id );
		}

		$mailer = Mailers::get_mailer( $default_connection['mailer'] );
		if ( ! $mailer ) {
			$this->ErrorInfo = sprintf(
				/* translators: %s: mailer slug from connection */
				__( 'Unknown or unavailable mailer: %s', 'doublescale' ),
				esc_html( (string) ( $default_connection['mailer'] ?? '' ) )
			);
			return false;
		}

		$saved_default_id = isset( $route['settings_default_connection_id'] ) ? (string) $route['settings_default_connection_id'] : '';
		$route_reason     = isset( $route['primary_route_reason'] ) ? (string) $route['primary_route_reason'] : 'default';
		Settings::note_smtp_send_attempt(
			array(
				'connection_id'    => (string) $default_connection_id,
				'mailer'           => (string) ( $default_connection['mailer'] ?? '' ),
				'reason'           => $route_reason,
				'message_from'     => (string) $original_from,
				'saved_default_id' => $saved_default_id,
				'is_fallback'      => false,
				'routing_adjusted' => ! empty( $route['routing_adjusted'] ),
			)
		);
		$result = $mailer->process( $this, $default_connection_id, $default_connection )->send();

		if ( ! $result && $fallback_connection ) {
			// Apply force from email for fallback connection too
			$force_from_email      = $fallback_connection['force_from_email'] ?? false;
			$connection_from_email = $fallback_connection['from_email'] ?? '';

			if ( $force_from_email && ! empty( $connection_from_email ) && is_email( $connection_from_email ) ) {
				$this->From = $connection_from_email;

				do_action( 'doublescale_smtp_force_from_email_applied', $connection_from_email, $original_from, $fallback_connection_id );
			}

			$force_from_name      = $fallback_connection['force_from_name'] ?? false;
			$connection_from_name = $fallback_connection['from_name'] ?? '';

			if ( $force_from_name && ! empty( $connection_from_name ) ) {
				$this->FromName = $connection_from_name;

				do_action( 'doublescale_smtp_force_from_name_applied', $connection_from_name, $original_from_name, $fallback_connection_id );
			}

			$mailer = Mailers::get_mailer( $fallback_connection['mailer'] );
			if ( ! $mailer ) {
				if ( '' === (string) $this->ErrorInfo ) {
					$this->ErrorInfo = sprintf(
						/* translators: %s: mailer slug */
						__( 'Unknown or unavailable fallback mailer: %s', 'doublescale' ),
						esc_html( (string) ( $fallback_connection['mailer'] ?? '' ) )
					);
				}
				return false;
			}

			Settings::note_smtp_send_attempt(
				array(
					'connection_id'    => (string) $fallback_connection_id,
					'mailer'           => (string) ( $fallback_connection['mailer'] ?? '' ),
					'reason'           => $route_reason,
					'message_from'     => (string) $original_from,
					'saved_default_id' => $saved_default_id,
					'is_fallback'      => true,
					'routing_adjusted' => false,
				)
			);
			$result = $mailer->process( $this, $fallback_connection_id, $fallback_connection )->send();
		}

		if ( ! $result && '' === (string) $this->ErrorInfo ) {
			$this->ErrorInfo = __( 'Mailer returned false without a specific error message.', 'doublescale' );
		}

		return $result;
	}
}
