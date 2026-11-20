<?php
namespace DirectoristAnalytics\App\Hooks;

use DirectoristAnalytics\App\Services\PrivacyService;

defined( 'ABSPATH' ) || exit;

/**
 * Cron Jobs
 *
 * @package DirectoristAnalytics
 * @since 1.0.0
 */
class Cron {

	/**
	 * Privacy service
	 *
	 * @var PrivacyService
	 */
	private $privacy_service;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->privacy_service = new PrivacyService();
	}

	/**
	 * Register hooks
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( 'directorist_analytics_delete_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'directorist_analytics_delete_old_logs' );
		}

		// Register cron action
		add_action( 'directorist_analytics_delete_old_logs', array( $this, 'delete_old_logs' ) );
		
	}

	/**
	 * Delete old raw logs based on retention setting
	 *
	 * @since 1.0.0
	 */
	public function delete_old_logs() {
		global $wpdb;

		$retention_days = $this->privacy_service->get_retention_days();
		
		if ( $retention_days <= 0 ) {
			return; // 0 = keep forever
		}

		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// Delete old views
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}directorist_analytics_views WHERE created_at < %s",
				$cutoff_date
			)
		);

		// Delete old clicks
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}directorist_analytics_clicks WHERE created_at < %s",
				$cutoff_date
			)
		);

		// Delete old searches
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}directorist_analytics_searches WHERE created_at < %s",
				$cutoff_date
			)
		);
	}

	/**
	 * Clear scheduled hooks
	 *
	 * @since 1.0.0
	 */
	public function clear_scheduled_hooks() {
		wp_clear_scheduled_hook( 'directorist_analytics_delete_old_logs' );
	}
}