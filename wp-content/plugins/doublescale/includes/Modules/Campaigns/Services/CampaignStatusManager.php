<?php
/**
 * Campaign Status Manager
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

final class CampaignStatusManager {


	/**
	 *  Status constants
	 */
	const DRAFT      = 'draft';
	const INACTIVE   = 'inactive';
	const ACTIVE     = 'active';
	const SCHEDULED  = 'schedule';
	const PROCESSING = 'processing';
	const COMPLETED  = 'completed';
	const RESENDING  = 'resending';
	const PAUSED     = 'paused';
	const CANCELLED  = 'cancelled';
	const FAILED     = 'failed';


	/**
	 * Class instance
	 *
	 * @var CampaignStatusManager
	 */
	private static $instance;

	/**
	 * Get Instance
	 *
	 * @return CampaignStatusManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
	}


	/**
	 * Get all statuses
	 *
	 * @return array
	 */
	public function get_all_statuses() {
		return array(
			self::DRAFT,
			self::INACTIVE,
			self::ACTIVE,
			self::SCHEDULED,
			self::PROCESSING,
			self::COMPLETED,
			self::RESENDING,
			self::PAUSED,
			self::CANCELLED,
			self::FAILED,
		);
	}

	/**
	 * Get status labels
	 *
	 * @return array
	 */
	public function get_status_labels() {
		return array(
			self::DRAFT      => __( 'Draft', 'doublescale' ),
			self::INACTIVE   => __( 'Inactive', 'doublescale' ),
			self::ACTIVE     => __( 'Active', 'doublescale' ),
			self::SCHEDULED  => __( 'Scheduled', 'doublescale' ),
			self::PROCESSING => __( 'Processing', 'doublescale' ),
			self::COMPLETED  => __( 'Completed', 'doublescale' ),
			self::RESENDING  => __( 'Resending', 'doublescale' ),
			self::PAUSED     => __( 'Paused', 'doublescale' ),
			self::CANCELLED  => __( 'Cancelled', 'doublescale' ),
			self::FAILED     => __( 'Failed', 'doublescale' ),
		);
	}


	/**
	 * Validate status
	 *
	 * @param string $status
	 * @return bool
	 */
	public function is_valid_status( $status ) {
		return in_array( $status, $this->get_all_statuses(), true );
	}


	/**
	 * Get valid transitions
	 *
	 * @return array
	 */
	public function get_valid_transitions() {
		return array(
			self::DRAFT      => array( self::INACTIVE, self::ACTIVE, self::SCHEDULED, self::PROCESSING ),
			self::INACTIVE   => array( self::DRAFT, self::ACTIVE, self::SCHEDULED, self::PROCESSING ),
			self::ACTIVE     => array( self::DRAFT, self::INACTIVE, self::PROCESSING, self::PAUSED, self::CANCELLED ),
			self::SCHEDULED  => array( self::DRAFT, self::INACTIVE, self::PROCESSING, self::CANCELLED, self::FAILED ),
			self::PROCESSING => array( self::COMPLETED, self::PAUSED, self::CANCELLED, self::FAILED ),
			self::PAUSED     => array( self::PROCESSING, self::ACTIVE, self::CANCELLED ),
			self::COMPLETED  => array( self::ACTIVE, self::RESENDING ),
			self::RESENDING  => array( self::ACTIVE, self::COMPLETED, self::CANCELLED ),
			self::CANCELLED  => array(),
			self::FAILED     => array( self::ACTIVE, self::DRAFT ),
		);
	}

	/**
	 * Validate transition
	 *
	 * @param string $from_status
	 * @param string $to_status
	 * @return bool
	 */
	public function is_valid_transition( $from_status, $to_status ) {
		$transitions = $this->get_valid_transitions();
		return isset( $transitions[ $from_status ] ) &&
		in_array( $to_status, $transitions[ $from_status ], true );
	}
}
