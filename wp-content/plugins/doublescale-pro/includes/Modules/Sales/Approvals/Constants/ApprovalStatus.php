<?php
/**
 * Approval workflow status constants.
 *
 * @package DoubleScale\Pro\Modules\Sales\Approvals
 */

namespace DoubleScale\Pro\Modules\Sales\Approvals\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * ApprovalStatus class.
 */
final class ApprovalStatus {

	const PENDING  = 'pending';
	const APPROVED = 'approved';
	const REJECTED = 'rejected';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::PENDING, self::APPROVED, self::REJECTED );
	}

	/**
	 * @param string $status Status value.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( string $status ): string {
		$labels = array(
			self::PENDING  => __( 'Pending Approval', 'doublescale' ),
			self::APPROVED => __( 'Approved', 'doublescale' ),
			self::REJECTED => __( 'Rejected', 'doublescale' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}
}
