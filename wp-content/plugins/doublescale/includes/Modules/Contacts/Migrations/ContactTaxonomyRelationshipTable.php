<?php
/**
 * Unified contact ↔ list/tag pivot (polymorphic discriminator pattern).
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ContactTaxonomyRelationshipTable class
 */
class ContactTaxonomyRelationshipTable extends Migration {

	/**
	 * Table name (without prefix; Migration adds doublescale_ prefix).
	 *
	 * @var string
	 */
	public $table_name = 'contact_taxonomy_relationship';

	/**
	 * Schema for morph-style pivot: taxonomy_type + taxonomy_id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			taxonomy_type VARCHAR(20) NOT NULL COMMENT \'list or tag\',
			taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY contact_taxonomy_unique (contact_id, taxonomy_type, taxonomy_id),
			KEY contact_id (contact_id),
			INDEX idx_taxonomy_polymorphic (taxonomy_type, taxonomy_id),
			INDEX idx_contact_taxonomy_type (contact_id, taxonomy_type)';

		return $query;
	}
}
