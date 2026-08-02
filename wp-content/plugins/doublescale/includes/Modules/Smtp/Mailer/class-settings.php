<?php
/**
 * Mailer Settings.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailer
 */

namespace DoubleScale\Modules\Smtp\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer Settings Class.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Mailer
	 *
	 * @var Mailer
	 */
	protected $mailer;

	/**
	 * Option key
	 *
	 * @var string
	 */
	protected $option_key;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Mailer $mailer Mailer.
	 */
	public function __construct( $mailer ) {
		$this->mailer     = $mailer;
		$this->option_key = "doublescale_smtp_{$this->mailer->slug}_settings";
	}

	/**
	 * Get settings
	 *
	 * @since 1.0.0
	 *
	 * @param false|string $property Property.
	 * @return mixed
	 */
	public function get( $property = false ) {
		$settings = get_option( $this->option_key, array() );
		if ( $property ) {
			return $settings[ $property ] ?? null;
		}
		return $settings;
	}

	/**
	 * Update settings
	 *
	 * @since 1.0.0
	 *
	 * @param array   $new_settings New settings.
	 * @param boolean $partial Partial update or complete.
	 * @return boolean
	 */
	public function update( $new_settings, $partial = true ) {
		$previous_settings = $this->get();
		if ( $partial ) {
			$new_settings = array_replace( $previous_settings, $new_settings );
			// Shallow merge replaces the whole `app` array — an empty client_secret from the UI
			// (masked field) must not wipe the stored secret or Google token exchange fails.
			if ( isset( $new_settings['app'] ) && is_array( $new_settings['app'] ) ) {
				$prev_app            = isset( $previous_settings['app'] ) && is_array( $previous_settings['app'] )
					? $previous_settings['app']
					: array();
				$new_settings['app'] = $this->merge_oauth_app_credentials( $prev_app, $new_settings['app'] );
			}
		}
		if ( $new_settings === $previous_settings ) {
			return true;
		}
		return update_option( $this->option_key, $new_settings );
	}

	/**
	 * Merge OAuth `app` payload without clearing client_secret when the client re-saves the same client_id.
	 *
	 * @param array $previous_app Stored app row.
	 * @param array $incoming_app  Incoming app row from REST.
	 * @return array
	 */
	private function merge_oauth_app_credentials( array $previous_app, array $incoming_app ) {
		$out         = array_replace( $previous_app, $incoming_app );
		$incoming_cs = isset( $incoming_app['client_secret'] ) ? trim( (string) $incoming_app['client_secret'] ) : '';
		if ( $incoming_cs !== '' ) {
			return $out;
		}
		$previous_cs = isset( $previous_app['client_secret'] ) ? trim( (string) $previous_app['client_secret'] ) : '';
		if ( $previous_cs === '' ) {
			return $out;
		}
		$prev_id = isset( $previous_app['client_id'] ) ? trim( (string) $previous_app['client_id'] ) : '';
		$new_id  = isset( $incoming_app['client_id'] ) ? trim( (string) $incoming_app['client_id'] ) : '';
		if ( $new_id === '' || $new_id === $prev_id ) {
			$out['client_secret'] = $previous_app['client_secret'];
		}
		return $out;
	}

	/**
	 * Delete settings
	 *
	 * @since 1.0.0
	 *
	 * @param false|string $property Property.
	 * @return boolean
	 */
	public function delete( $property = false ) {
		if ( $property ) {
			return $this->update( array( $property => null ) );
		} else {
			return delete_option( $this->option_key );
		}
	}
}
