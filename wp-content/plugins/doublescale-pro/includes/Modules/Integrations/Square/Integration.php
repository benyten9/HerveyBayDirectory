<?php
/**
 * Square global integration.
 *
 * @package DoubleScale\Pro\Modules\Integrations\Square
 */

namespace DoubleScale\Pro\Modules\Integrations\Square;

use DoubleScale\Pro\Modules\Integrations\Abstracts\Integration as Integration_Abstract;

defined( 'ABSPATH' ) || exit;

/**
 * Integration class.
 */
class Integration extends Integration_Abstract {

	public $name = 'Square';

	public $slug = 'square';

	public $description = 'Accept Square payments for invoices. Configure sandbox or production access token and location.';

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Accept Square payments for invoices. Configure sandbox or production access token and location.', 'doublescale' );
	}

	public $is_pro = false;

	public $show_in_catalog = true;

	public $catalog_category = 'payment';

	public $option_name = 'square';

	protected static $classes = array(
		'rest_controller' => RestController::class,
	);

	/**
	 * @return self
	 */
	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Resolve credentials for a mode, falling back to whichever is populated.
	 *
	 * @param string|null $mode `sandbox` or `production`.
	 * @return array|false
	 */
	public function get_mode_settings( $mode = null ) {
		$settings = $this->get_settings();
		$mode     = $mode ?? ( $settings['mode'] ?? null );

		if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) ) {
			if ( ! empty( $settings['sandbox_access_token'] ) ) {
				$mode = 'sandbox';
			} elseif ( ! empty( $settings['production_access_token'] ) ) {
				$mode = 'production';
			} else {
				return false;
			}
		}

		$result = array( 'mode' => $mode );
		foreach ( array( 'access_token', 'location_id', 'signature_key', 'subscription_id' ) as $key ) {
			$value = $settings[ "{$mode}_{$key}" ] ?? '';
			// A payment link cannot be created without both of these.
			if ( '' === $value && in_array( $key, array( 'access_token', 'location_id' ), true ) ) {
				return false;
			}
			$result[ $key ] = $value;
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== $this->get_mode_settings();
	}

	/**
	 * Square uses a dedicated Api client, not IntegrationApi.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->is_configured();
	}

	/**
	 * @return Api|false
	 */
	public function connect() {
		if ( $this->api instanceof Api ) {
			return $this->api;
		}

		$mode_settings = $this->get_mode_settings();
		if ( ! $mode_settings ) {
			return false;
		}

		$this->api = new Api(
			(string) $mode_settings['access_token'],
			(string) $mode_settings['mode'],
			(string) $mode_settings['location_id']
		);

		return $this->api;
	}

	/**
	 * @param array $settings Candidate settings.
	 * @return true|\WP_Error
	 */
	public function validate( $settings ) {
		$mode = $settings['mode'] ?? 'sandbox';
		if ( ! in_array( $mode, array( 'sandbox', 'production' ), true ) ) {
			return new \WP_Error( 'invalid_mode', __( 'Mode must be sandbox or production.', 'doublescale' ) );
		}

		$access_token = $settings[ "{$mode}_access_token" ] ?? '';
		if ( '' === $access_token ) {
			return new \WP_Error( 'missing_credentials', __( 'An access token is required for the selected mode.', 'doublescale' ) );
		}

		$api    = new Api( $access_token, $mode );
		$result = $api->list_locations();

		if ( ! $result['success'] ) {
			doublescale_get_logger()->warning(
				'Square credentials validation failed',
				array(
					'code'    => 'square_validation_failed',
					'mode'    => $mode,
					'message' => $result['message'] ?? '',
				)
			);
			return new \WP_Error( 'square_connection_failed', $result['message'] ?? __( 'Failed to connect to Square.', 'doublescale' ) );
		}

		return true;
	}

	/**
	 * Locations available to the saved credentials, for the settings UI.
	 *
	 * @param string $mode `sandbox` or `production`.
	 * @return array<int, array{id:string,name:string,currency:string}>
	 */
	public function get_locations( string $mode ): array {
		$settings     = $this->get_settings();
		$access_token = $settings[ "{$mode}_access_token" ] ?? '';
		if ( '' === $access_token ) {
			return array();
		}

		$result = ( new Api( $access_token, $mode ) )->list_locations();
		if ( ! $result['success'] ) {
			return array();
		}

		$locations = array();
		foreach ( (array) ( $result['data']['locations'] ?? array() ) as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			// Only locations that can actually take card payments.
			$status = strtoupper( (string) ( $location['status'] ?? '' ) );
			if ( 'ACTIVE' !== $status ) {
				continue;
			}
			$locations[] = array(
				'id'       => (string) ( $location['id'] ?? '' ),
				'name'     => (string) ( $location['name'] ?? '' ),
				'currency' => strtoupper( (string) ( $location['currency'] ?? '' ) ),
			);
		}

		return $locations;
	}

	/**
	 * Catalog icon.
	 *
	 * @return string
	 */
	public function get_icon_url(): string {
		return $this->plugin_asset_url( 'assets/images/square/square.svg' );
	}
}
