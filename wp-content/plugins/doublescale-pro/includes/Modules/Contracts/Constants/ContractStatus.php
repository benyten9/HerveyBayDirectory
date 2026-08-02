<?php
/**
 * Contract status constants.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Contract status values and helpers.
 */
class ContractStatus {

	const DRAFT   = 'draft';
	const SENT    = 'sent';
	const SIGNED  = 'signed';
	const ACTIVE  = 'active';
	const EXPIRED = 'expired';

	/**
	 * @return string[]
	 */
	public static function all() {
		return array( self::DRAFT, self::SENT, self::SIGNED, self::ACTIVE, self::EXPIRED );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( $status ) {
		$labels = array(
			self::DRAFT   => __( 'Draft', 'doublescale' ),
			self::SENT    => __( 'Sent', 'doublescale' ),
			self::SIGNED  => __( 'Signed', 'doublescale' ),
			self::ACTIVE  => __( 'Active', 'doublescale' ),
			self::EXPIRED => __( 'Expired', 'doublescale' ),
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
