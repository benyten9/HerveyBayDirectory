<?php
/**
 * Unified abstract payment gateway.
 *
 * @package DoubleScale\Core\Payment
 */

namespace DoubleScale\Core\Payment;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Gateway abstract class.
 */
abstract class Gateway {

	/**
	 * Human-readable gateway name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Gateway slug.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Short description for admin UI.
	 *
	 * @var string
	 */
	public $description = '';

	/**
	 * @var array<class-string, static>
	 */
	private static $instances = array();

	/**
	 * @return static
	 */
	public static function instance() {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			$instance = new static();
			$instance->register();
			self::$instances[ $class ] = $instance;
		}
		return self::$instances[ $class ];
	}

	/**
	 * Register this gateway with {@see GatewayManager} for the appropriate context(s).
	 *
	 * @return void
	 */
	abstract protected function register(): void;

	/**
	 * Whether the gateway implementation is present (e.g. Pro module active).
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * Whether credentials/settings are complete.
	 *
	 * @return bool
	 */
	abstract public function is_configured(): bool;

	/**
	 * Start an online payment session.
	 *
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	abstract public function init( PayableSubject $subject );

	/**
	 * Confirm / poll payment status after checkout.
	 *
	 * @param PayableSubject $subject Payable subject.
	 * @return array|WP_Error
	 */
	abstract public function confirm( PayableSubject $subject );

	/**
	 * Record a successful charge idempotently (by transaction id).
	 *
	 * @param PayableSubject $subject Payable subject.
	 * @param object         $charge  Stripe charge or payment intent.
	 * @return void
	 */
	abstract public function record_paid( PayableSubject $subject, object $charge ): void;

	/**
	 * Payment mode slug stored on recorded payment rows.
	 *
	 * @return string
	 */
	public function payment_mode_slug(): string {
		return (string) $this->slug;
	}
}
