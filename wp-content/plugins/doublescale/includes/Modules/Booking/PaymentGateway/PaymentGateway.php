<?php
/**
 * Legacy abstract payment gateway (deprecated).
 *
 * Kept for backward compatibility with doublescale-pro releases that still
 * ship `StripeBookingGateway` extending this class. New Pro uses
 * `DoubleScale\Core\Payment\Gateway` via `BookingStripeHandler`.
 *
 * @package DoubleScale\Modules\Booking\PaymentGateway
 */

namespace DoubleScale\Modules\Booking\PaymentGateway;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Managers\PaymentGatewaysManager;
use Illuminate\Support\Arr;

/**
 * Payment Gateway class.
 *
 * @deprecated 1.2.0 Use DoubleScale\Core\Payment\Gateway instead.
 */
abstract class PaymentGateway {

	/**
	 * Payment Gateway Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Payment Gateway Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Payment Gateway Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Option name
	 *
	 * @var string
	 */
	public $option_name;

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array();

	/**
	 * Subclasses instances.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Instantiates or reuses an instance of PaymentGateway.
	 *
	 * @return static
	 */
	public static function instance() {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			$instance = new static();
			$instance->register();
			self::$instances[ static::class ] = $instance;
		}
		return self::$instances[ static::class ];
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Init
	 *
	 * @return void
	 */
	public function init() {
		if ( ! empty( static::$classes['rest_api'] ) ) {
			new static::$classes['rest_api']( $this );
		}

		$this->option_name = 'doublescale_booking_' . $this->slug . '_settings';
	}

	/**
	 * Register
	 *
	 * @return bool
	 */
	private function register() {
		try {
			PaymentGatewaysManager::instance()->register_payment_gateway( $this );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the settings
	 *
	 * @return array
	 */
	public function get_settings() {
		return get_option( $this->option_name, array() );
	}

	/**
	 * Get the setting
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return Arr::get( $settings, $key, $default );
	}

	/**
	 * Update the settings
	 *
	 * @param array $settings Settings array.
	 * @return void
	 */
	public function update_settings( $settings ) {
		update_option( $this->option_name, $settings );
	}

	/**
	 * Update the setting
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;
		$this->update_settings( $settings );
	}

	/**
	 * Check if the gateway is enabled
	 *
	 * @return boolean
	 */
	public function is_enabled() {
		return (bool) get_option( "doublescale_booking_{$this->slug}_enabled", false );
	}

	/**
	 * Set gateway enabled status
	 *
	 * @param boolean $enabled Enabled flag.
	 * @return void
	 */
	public function set_enabled( $enabled ) {
		update_option( "doublescale_booking_{$this->slug}_enabled", (bool) $enabled );
	}

	/**
	 * Validate the integration
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public function validate( $settings ) {
		return true;
	}

	/**
	 * Is gateway and method configured
	 *
	 * @return boolean
	 */
	abstract public function is_configured();

	/**
	 * Get fields
	 *
	 * @return array
	 */
	abstract public function get_fields();
}
