<?php
/**
 * Campaign Events Queue Table Migration
 *
 * Stores pending event triggers for automated campaigns so that
 * no events are lost when a campaign is already processing.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * CampaignEventsTable class
 */
class CampaignEventsTable extends Migration {

	/**
	 * @var string
	 */
	public $table_name = 'campaign_events';

	/**
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) NOT NULL,
			post_id BIGINT(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT "pending" COMMENT "pending, processing, completed, failed",
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			INDEX idx_drain (campaign_id, status, created_at),
			INDEX idx_cleanup (status, processed_at),
			INDEX idx_campaign_post (campaign_id, post_id)';
	}
}
