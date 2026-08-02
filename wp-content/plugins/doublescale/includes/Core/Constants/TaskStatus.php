<?php
/**
 * Task Status Constants
 * Defines string constants for CRM task statuses
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * TaskStatus class
 *
 * Provides constants and utility methods for task statuses.
 */
class TaskStatus {

	/**
	 * Pending - Task not yet started or in progress
	 */
	const PENDING = 'pending';

	/**
	 * Completed - Task has been finished
	 */
	const COMPLETED = 'completed';

	/**
	 * Overdue - Task past due date and not completed
	 */
	const OVERDUE = 'overdue';

	/**
	 * Upcoming - Task scheduled for the future
	 */
	const UPCOMING = 'upcoming';

	/**
	 * Due Today - Task due today (calculated)
	 */
	const DUE_TODAY = 'due_today';

	/**
	 * Get database statuses (only these should be stored)
	 *
	 * @return array Associative array of DB status constants to labels
	 */
	public static function get_db_statuses() {
		return array(
			self::PENDING   => __( 'Pending', 'doublescale' ),
			self::COMPLETED => __( 'Completed', 'doublescale' ),
		);
	}

	/**
	 * DB-storable status keys only (never display pseudo-statuses).
	 *
	 * Used by entity-report descriptors so the status filter/breakdown offers
	 * only values that can actually be stored on a task row.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_keys( self::get_db_statuses() );
	}

	/**
	 * Get all display statuses (for UI filtering/display)
	 *
	 * @return array Associative array of all status constants to labels
	 */
	public static function get_all() {
		return array(
			self::PENDING   => __( 'Pending', 'doublescale' ),
			self::COMPLETED => __( 'Completed', 'doublescale' ),
			self::OVERDUE   => __( 'Overdue', 'doublescale' ),
			self::UPCOMING  => __( 'Upcoming', 'doublescale' ),
			self::DUE_TODAY => __( 'Due Today', 'doublescale' ),
		);
	}

	/**
	 * Get status label
	 *
	 * @param string $status Status constant value.
	 * @return string Status label
	 */
	public static function get_label( $status ) {
		$statuses = self::get_all();
		return $statuses[ $status ] ?? __( 'Unknown', 'doublescale' );
	}

	/**
	 * Check if status is valid for database storage
	 *
	 * @param string $status Status to validate.
	 * @return bool True if valid DB status
	 */
	public static function is_valid( $status ) {
		$statuses = self::get_db_statuses();
		return isset( $statuses[ $status ] );
	}

	/**
	 * Check if status is a valid display status
	 *
	 * @param string $status Status to validate.
	 * @return bool True if valid display status
	 */
	public static function is_valid_display( $status ) {
		$statuses = self::get_all();
		return isset( $statuses[ $status ] );
	}

	/**
	 * Get default status
	 *
	 * @return string Default status
	 */
	public static function get_default() {
		return self::PENDING;
	}

	/**
	 * Get badge color for UI
	 *
	 * @param string $status Status constant.
	 * @return string Badge color (success, error, warning, default)
	 */
	public static function get_badge_color( $status ) {
		$colors = array(
			self::PENDING   => 'default',
			self::COMPLETED => 'success',
			self::OVERDUE   => 'error',
			self::UPCOMING  => 'info',
			self::DUE_TODAY => 'warning',
		);
		return $colors[ $status ] ?? 'default';
	}

	/**
	 * Get CSS class for status
	 *
	 * @param string $status Status constant.
	 * @return string CSS class name
	 */
	public static function get_css_class( $status ) {
		$classes = array(
			self::PENDING   => 'status-pending',
			self::COMPLETED => 'status-completed',
			self::OVERDUE   => 'status-overdue',
			self::UPCOMING  => 'status-upcoming',
			self::DUE_TODAY => 'status-due-today',
		);
		return $classes[ $status ] ?? 'status-unknown';
	}

	/**
	 * Get active statuses (tasks that need attention)
	 * Note: These are display statuses, not DB statuses
	 *
	 * @return array Active status constants
	 */
	public static function get_active_statuses() {
		return array(
			self::PENDING,
			self::OVERDUE,
			self::UPCOMING,
			self::DUE_TODAY,
		);
	}

	/**
	 * Check if task is active (not completed)
	 *
	 * @param string $status Status to check.
	 * @return bool True if task is active
	 */
	public static function is_active( $status ) {
		return $status !== self::COMPLETED;
	}

	/**
	 * Calculate display status from due_date and db_status
	 *
	 * @param string $db_status The stored database status (pending/completed).
	 * @param string $due_date The task due date (Y-m-d format).
	 * @return string The calculated display status
	 */
	public static function calculate_display_status( $db_status, $due_date ) {
		// Completed tasks always show as completed
		if ( self::COMPLETED === $db_status ) {
			return self::COMPLETED;
		}

		// For pending tasks, calculate based on due_date
		$today = current_time( 'Y-m-d' );

		if ( $due_date < $today ) {
			return self::OVERDUE;
		} elseif ( $due_date === $today ) {
			return self::DUE_TODAY;
		} else {
			return self::UPCOMING;
		}
	}
}
