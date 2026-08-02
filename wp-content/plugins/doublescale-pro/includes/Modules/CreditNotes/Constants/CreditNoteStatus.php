<?php
/**
 * Credit note status constants.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * CreditNoteStatus values and helpers.
 */
class CreditNoteStatus {

	const DRAFT              = 'draft';
	const OPEN               = 'open';
	const PARTIALLY_APPLIED  = 'partially_applied';
	const APPLIED            = 'applied';
	const VOID               = 'void';

	/**
	 * @return string[]
	 */
	public static function all() {
		return array( self::DRAFT, self::OPEN, self::PARTIALLY_APPLIED, self::APPLIED, self::VOID );
	}

	/**
	 * @param string $status Status value.
	 * @return string
	 */
	public static function get_label( $status ) {
		$labels = array(
			self::DRAFT             => __( 'Draft', 'doublescale' ),
			self::OPEN              => __( 'Open', 'doublescale' ),
			self::PARTIALLY_APPLIED => __( 'Partially Applied', 'doublescale' ),
			self::APPLIED           => __( 'Applied', 'doublescale' ),
			self::VOID              => __( 'Void', 'doublescale' ),
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
