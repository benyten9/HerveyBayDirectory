<?php
/**
 * Tracking Status Constants
 * Defines integer constants for message tracking statuses
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * TrackingStatus class
 */
class TrackingStatus {

	/**
	 * Pending - Message queued but not sent yet
	 */
	const PENDING = 1;

	/**
	 * Sent - Message successfully sent
	 */
	const SENT = 2;

	/**
	 * Failed - Message failed to send
	 */
	const FAILED = 3;

	/**
	 * Delivered - Message delivered (Sms/Whatsapp)
	 */
	const DELIVERED = 4;

	/**
	 * Scheduled - Message scheduled for future sending
	 */
	const SCHEDULED = 5;

	/**
	 * Read - Message read by recipient (Whatsapp)
	 */
	const READ = 6;

	/**
	 * Get all statuses
	 *
	 * @return array Associative array of status constants to labels
	 */
	public static function get_all() {
		return array(
			self::PENDING   => __( 'Pending', 'doublescale' ),
			self::SENT      => __( 'Sent', 'doublescale' ),
			self::FAILED    => __( 'Failed', 'doublescale' ),
			self::DELIVERED => __( 'Delivered', 'doublescale' ),
			self::SCHEDULED => __( 'Scheduled', 'doublescale' ),
			self::READ      => __( 'Read', 'doublescale' ),
		);
	}

	/**
	 * Get status name/label
	 *
	 * @param int $status Status constant value.
	 * @return string Status label
	 */
	public static function get_name( $status ) {
		$statuses = self::get_all();
		return $statuses[ $status ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Get status slug (for backwards compatibility and Api)
	 *
	 * @param int $status Status constant value.
	 * @return string Status slug
	 */
	public static function get_slug( $status ) {
		$map = array(
			self::PENDING   => 'pending',
			self::SENT      => 'sent',
			self::FAILED    => 'failed',
			self::DELIVERED => 'delivered',
			self::SCHEDULED => 'scheduled',
			self::READ      => 'read',
		);
		return $map[ $status ] ?? 'unknown';
	}

	/**
	 * Get status constant from slug (for Api input)
	 *
	 * @param string $slug Status slug.
	 * @return int|null Status constant or null if not found
	 */
	public static function from_slug( $slug ) {
		$map = array(
			'pending'   => self::PENDING,
			'sent'      => self::SENT,
			'failed'    => self::FAILED,
			'delivered' => self::DELIVERED,
			'scheduled' => self::SCHEDULED,
			'read'      => self::READ,
		);
		return $map[ strtolower( $slug ) ] ?? null;
	}

	/**
	 * Check if status is valid
	 *
	 * @param int $status Status to validate.
	 * @return bool True if valid status
	 */
	public static function is_valid( $status ) {
		$statuses = self::get_all();
		return isset( $statuses[ $status ] );
	}

	/**
	 * Get statuses for email mode
	 *
	 * @return array Status constants applicable to emails
	 */
	public static function get_email_statuses() {
		return array(
			self::PENDING   => self::get_name( self::PENDING ),
			self::SENT      => self::get_name( self::SENT ),
			self::FAILED    => self::get_name( self::FAILED ),
			self::SCHEDULED => self::get_name( self::SCHEDULED ),
		);
	}

	/**
	 * Get statuses for Sms/Whatsapp mode
	 *
	 * @return array Status constants applicable to Sms/Whatsapp
	 */
	public static function get_messaging_statuses() {
		return array(
			self::PENDING   => self::get_name( self::PENDING ),
			self::SENT      => self::get_name( self::SENT ),
			self::DELIVERED => self::get_name( self::DELIVERED ),
			self::READ      => self::get_name( self::READ ),
			self::FAILED    => self::get_name( self::FAILED ),
			self::SCHEDULED => self::get_name( self::SCHEDULED ),
		);
	}

	/**
	 * Get status color class for UI
	 *
	 * @param int $status Status constant.
	 * @return string CSS class name
	 */
	public static function get_status_class( $status ) {
		$classes = array(
			self::PENDING   => 'status-pending',
			self::SENT      => 'status-sent',
			self::FAILED    => 'status-failed',
			self::DELIVERED => 'status-delivered',
			self::SCHEDULED => 'status-scheduled',
			self::READ      => 'status-read',
		);
		return $classes[ $status ] ?? 'status-unknown';
	}

	/**
	 * Get badge color
	 *
	 * @param int $status Status constant.
	 * @return string Badge color (success, processing, error, default, warning)
	 */
	public static function get_badge_color( $status ) {
		$colors = array(
			self::PENDING   => 'processing',
			self::SENT      => 'success',
			self::FAILED    => 'error',
			self::DELIVERED => 'success',
			self::SCHEDULED => 'default',
			self::READ      => 'success',
		);
		return $colors[ $status ] ?? 'default';
	}
}
