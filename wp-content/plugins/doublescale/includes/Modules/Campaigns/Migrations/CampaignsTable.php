<?php
/**
 * Class CampaignsTable
 * This class is responsible for handling the campaign table migration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * CampaignsTable class
 */
class CampaignsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'campaigns';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Fields:
		 *
		 * id BIGINT(20) NOT NULL AUTO_INCREMENT,
		 * name VARCHAR(255) NOT NULL,
		 * description TEXT,
		 * status VARCHAR(255) NOT NULL DEFAULT "inactive",
		 * type TINYINT UNSIGNED NOT NULL DEFAULT 1,
		 * settings TEXT,
		 * parent_id BIGINT(20) NOT NULL DEFAULT 0,
		 * count INT(11) NOT NULL DEFAULT 0,
		 * execute_at TIMESTAMP,
		 * created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		 * updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		 *
		 * Campaign types:
		 * 1 = Email (CampaignChannel::CHANNEL_EMAIL)
		 * 2 = Sms (CampaignChannel::CHANNEL_SMS)
		 * 3 = WhatsApp (CampaignChannel::CHANNEL_WHATSAPP)
		 * 4 = Sequence Mail (CampaignChannel::CHANNEL_SEQUENCE_MAIL)
		 * 5 = Email Sequence (CampaignChannel::CHANNEL_EMAIL_SEQUENCE)
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(50) NOT NULL DEFAULT "inactive",
            type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=Email, 2=Sms, 3=Whatsapp, 4=Sequence Mail, 5=Email Sequence",
            settings TEXT,
            parent_id BIGINT(20) NOT NULL DEFAULT 0,
            count INT(11) NOT NULL DEFAULT 0,
            execute_at TIMESTAMP,
            processing_started_at DATETIME DEFAULT NULL COMMENT "When processing began, used for stuck job detection",
            created_by BIGINT(20) UNSIGNED DEFAULT NULL COMMENT "WordPress user ID who created this campaign",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type (type),
            INDEX idx_status_type (status, type),
            INDEX idx_created_by (created_by)';

		return $query;
	}
}
