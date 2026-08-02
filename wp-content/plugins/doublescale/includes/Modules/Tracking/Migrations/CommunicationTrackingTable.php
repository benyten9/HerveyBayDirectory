<?php
/**
 * Class CommunicationTrackingTable
 * Unified table for tracking message delivery, opens, clicks across all channels
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Tracking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * CommunicationTrackingTable class
 */
class CommunicationTrackingTable extends Migration {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'communication_tracking';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Unified Communication Tracking Table Fields:
		 *
		 * id: BIGINT(20) NOT NULL AUTO_INCREMENT
		 * contact_id: BIGINT(20) NOT NULL
		 * template_id: BIGINT(20) NULL DEFAULT NULL COMMENT 'NULL for individual messages'
		 * hash_key: VARCHAR(255) NOT NULL
		 * mode: TINYINT(1) NOT NULL COMMENT '1=Email, 2=Sms, 3=Whatsapp'
		 * source_type: TINYINT(2) NOT NULL DEFAULT 1 COMMENT '1=Campaign, 2=Automation, 3=Individual'
		 * source_id: BIGINT(20) NULL DEFAULT NULL COMMENT 'Polymorphic FK: campaign_id, automation_id, or activity_id (for individuals)'
		 * step_id: BIGINT(20) NULL DEFAULT NULL COMMENT 'Automation step ID (NULL for campaigns/individual)'
		 * author_id: BIGINT(20) UNSIGNED DEFAULT 0 COMMENT 'User ID who sent the message (for individual sends)'
		 * recipient: VARCHAR(255) NOT NULL COMMENT 'Email address or phone number'
		 * opened: TINYINT(1) DEFAULT 0 COMMENT 'Only for emails'
		 * clicked: TINYINT(1) DEFAULT 0
		 * status: TINYINT(2) UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=Pending, 2=Sent, 3=Failed, 4=Delivered, 5=Scheduled'
		 * sent_at: TIMESTAMP NULL DEFAULT NULL
		 * opened_at: TIMESTAMP COMMENT 'Only for emails'
		 * clicked_at: TIMESTAMP
		 * created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 *
		 * NOTE: activity_id column has been removed - use source_id + source_type instead
		 * For individual messages (source_type=3), source_id points to activities table
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            contact_id BIGINT(20) NOT NULL,
            template_id BIGINT(20) NULL DEFAULT NULL COMMENT "NULL for individual messages",
            hash_key VARCHAR(255) NOT NULL,
            mode TINYINT(1) NOT NULL COMMENT "1=Email, 2=Sms, 3=Whatsapp",
            direction TINYINT(1) NOT NULL DEFAULT 1 COMMENT "Message direction: 1=Outbound, 2=Inbound",
            source_type TINYINT(2) NOT NULL DEFAULT 1 COMMENT "1=Campaign, 2=Automation, 3=Individual",
            source_id BIGINT(20) NULL DEFAULT NULL COMMENT "Polymorphic FK: campaign_id, automation_id, or activity_id (for individuals)",
            step_id BIGINT(20) NULL DEFAULT NULL COMMENT "Automation step ID (NULL for campaigns/individual)",
            author_id BIGINT(20) UNSIGNED DEFAULT 0 COMMENT "User ID who sent the message (for individual sends)",
            recipient VARCHAR(255) NOT NULL COMMENT "Email address or phone number",
            opened TINYINT(1) DEFAULT 0 COMMENT "Only for emails",
            clicked TINYINT(1) DEFAULT 0,
            status TINYINT(2) UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=Pending, 2=Sent, 3=Failed, 4=Delivered, 5=Scheduled ,6=Read",
            external_id VARCHAR(255) NULL COMMENT "Twilio MessageSid, email ID, etc.",
            sent_at TIMESTAMP NULL DEFAULT NULL,
            opened_at TIMESTAMP NULL COMMENT "Only for emails",
            clicked_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY contact_id (contact_id),
            KEY template_id (template_id),
            KEY hash_key (hash_key),
            KEY mode (mode),
            KEY direction (direction),
            KEY source_type (source_type),
            KEY source_id (source_id),
            KEY idx_source_polymorphic (source_type, source_id),
            KEY step_id (step_id),
            KEY author_id (author_id),
            KEY recipient (recipient),
            KEY status (status),
            KEY external_id (external_id),
            KEY sent_at (sent_at),
            KEY idx_seq_contact_status (source_type, source_id, contact_id, mode, status)';

		return $query;
	}
}
