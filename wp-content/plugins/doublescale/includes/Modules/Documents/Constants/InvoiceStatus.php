<?php
/**
 * Invoice status constants.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice status values and helpers.
 */
class InvoiceStatus {

	const DRAFT           = 'draft';
	const UNPAID          = 'unpaid';
	const PARTIALLY_PAID  = 'partially_paid';
	const PAID            = 'paid';
	const OVERDUE         = 'overdue';

	/**
	 * @return string[]
	 */
	public static function all() {
		return array( self::DRAFT, self::UNPAID, self::PARTIALLY_PAID, self::PAID, self::OVERDUE );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( $status ) {
		$labels = array(
			self::DRAFT          => __( 'Draft', 'doublescale' ),
			self::UNPAID         => __( 'Unpaid', 'doublescale' ),
			self::PARTIALLY_PAID => __( 'Partially Paid', 'doublescale' ),
			self::PAID           => __( 'Paid', 'doublescale' ),
			self::OVERDUE        => __( 'Overdue', 'doublescale' ),
		);

		return $labels[ $status ] ?? ucfirst( (string) $status );
	}

	/**
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( $status, self::all(), true );
	}
}
