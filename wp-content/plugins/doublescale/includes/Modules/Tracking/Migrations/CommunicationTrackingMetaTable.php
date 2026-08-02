<?php
/**
 * Class CommunicationTrackingMetaTable
 * Meta table for communication tracking using WordPress meta_key/meta_value convention
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Tracking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * CommunicationTrackingMetaTable class
 */
class CommunicationTrackingMetaTable extends Migration {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'communication_tracking_meta';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Communication Tracking Meta Table Fields (WordPress Convention):
		 *
		 * meta_id: BIGINT(20) NOT NULL AUTO_INCREMENT
		 * communication_tracking_id: BIGINT(20) NOT NULL
		 * meta_key: VARCHAR(255) - Key for the meta data (e.g., 'merge_tags', 'sections_ids')
		 * meta_value: LONGTEXT - Value for the meta data (JSON format)
		 * created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            communication_tracking_id BIGINT(20) NOT NULL,
            meta_key VARCHAR(255),
            meta_value LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY communication_tracking_id (communication_tracking_id),
            KEY meta_key (meta_key(191))';
		return $query;
	}
}
