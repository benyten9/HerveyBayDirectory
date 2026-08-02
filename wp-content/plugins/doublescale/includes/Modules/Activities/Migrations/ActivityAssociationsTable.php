<?php

/**
 * Class ActivityAssociationsTable
 * Unified table for all activity associations (deal, campaign)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Activities\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * Activity Associations Table class
 */
class ActivityAssociationsTable extends Migration {


	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'activity_associations';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Unified Activity Associations Table Fields.
		 *
		 * Columns:
		 * id: BIGINT(20) NOT NULL AUTO_INCREMENT
		 * activity_id: BIGINT(20) NOT NULL COMMENT "FK to activities table"
		 * entity_type: TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=Deal 2=Campaign 3=Ticket"
		 * entity_id: BIGINT(20) NOT NULL COMMENT "FK to deals campaigns or tickets table"
		 * created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		 * updated_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		 *
		 * Entity types:
		 * 1 = Deal
		 * 2 = Campaign
		 * 3 = Ticket (Support module)
		 *
		 * Index note: composite_entity_lookup (entity_type, entity_id, created_at)
		 * serves the polymorphic "all associations for one entity, oldest first"
		 * read — the hot path for Support ticket threads
		 * (ActivityModel::scopeForTicket: WHERE entity_type=3 AND entity_id=?
		 * ORDER BY created_at). Without it MySQL uses the single-column entity_id
		 * index, filters entity_type as a post-condition, and filesorts the order.
		 * Leading with entity_type+entity_id makes it an index range with no
		 * filesort. Note: this only reaches installs that create the table FRESH —
		 * Migration::run() skips dbDelta when the table already exists, so existing
		 * installs keep the old index set until a dedicated ALTER migration adds it.
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            activity_id BIGINT(20) NOT NULL COMMENT "FK to activities table",
            entity_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "1=Deal 2=Campaign 3=Ticket",
            entity_id BIGINT(20) NOT NULL COMMENT "FK to deals campaigns or tickets table",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY activity_id (activity_id),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY created_at (created_at),
            KEY updated_at (updated_at),
            UNIQUE KEY unique_activity_entity (activity_id, entity_type, entity_id),
            KEY composite_activity_created (activity_id, created_at),
            KEY composite_activity_updated (activity_id, updated_at),
            KEY composite_entity_lookup (entity_type, entity_id, created_at)';

		return $query;
	}
}
