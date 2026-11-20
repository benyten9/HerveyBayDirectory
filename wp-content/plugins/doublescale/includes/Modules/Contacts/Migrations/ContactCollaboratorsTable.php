<?php
/**
 * Contact collaborators: users who may see and work a contact without owning it.
 *
 * A row is added automatically when a deal, task, ticket, booking or sales
 * document on the contact is assigned to a user other than the owner, and by
 * hand from the contact drawer. `reason` records why ("deal:123", "manual").
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Modules\Contacts\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * ContactCollaboratorsTable class
 */
class ContactCollaboratorsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 */
	public $table_name = 'contact_collaborators';

	/**
	 * Get query
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			added_by BIGINT(20) UNSIGNED NULL,
			reason VARCHAR(64) NOT NULL DEFAULT "manual",
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY contact_user (contact_id, user_id),
			KEY user_id (user_id)';
	}
}
