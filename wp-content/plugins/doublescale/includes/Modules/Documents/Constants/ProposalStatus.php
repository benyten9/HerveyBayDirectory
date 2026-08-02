<?php
/**
 * Proposal status constants.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Proposal status values and helpers.
 */
class ProposalStatus {

	const DRAFT    = 'draft';
	const SENT     = 'sent';
	const OPEN     = 'open';
	const DECLINED = 'declined';
	const ACCEPTED = 'accepted';

	/**
	 * @return string[]
	 */
	public static function all() {
		return array( self::DRAFT, self::SENT, self::OPEN, self::DECLINED, self::ACCEPTED );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( $status ) {
		$labels = array(
			self::DRAFT    => __( 'Draft', 'doublescale' ),
			self::SENT     => __( 'Sent', 'doublescale' ),
			self::OPEN     => __( 'Open', 'doublescale' ),
			self::DECLINED => __( 'Declined', 'doublescale' ),
			self::ACCEPTED => __( 'Accepted', 'doublescale' ),
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
