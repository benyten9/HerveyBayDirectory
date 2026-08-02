<?php
/**
 * Unified payable subject — booking or invoice behind one contract.
 *
 * @package DoubleScale\Core\Payment
 */

namespace DoubleScale\Core\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * PayableSubject interface.
 */
interface PayableSubject {

	/**
	 * Payment routing context (`booking` or `invoice`).
	 *
	 * @return string
	 */
	public function context(): string;

	/**
	 * @return int
	 */
	public function entity_id(): int;

	/**
	 * @return float
	 */
	public function amount_due(): float;

	/**
	 * @return string
	 */
	public function currency(): string;

	/**
	 * @return string|null
	 */
	public function customer_name(): ?string;

	/**
	 * @return string|null
	 */
	public function customer_email(): ?string;

	/**
	 * Stored payment-intent id (invoice column or booking meta).
	 *
	 * @return string|null
	 */
	public function external_payment_ref(): ?string;

	/**
	 * @param string $id Payment intent id.
	 * @return void
	 */
	public function set_external_payment_ref( string $id ): void;

	/**
	 * Metadata bag for Stripe (must include `source`).
	 *
	 * @return array<string, string>
	 */
	public function metadata(): array;

	/**
	 * Persist a successful charge — module-specific storage.
	 *
	 * @param object $charge Stripe PaymentIntent or related object.
	 * @return void
	 */
	public function record_payment( object $charge ): void;
}
