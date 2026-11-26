<?php

/**
 * Class FormSubmissionsTable
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * FormSubmissionsTable class
 */
class FormSubmissionsTable extends Migration {


	/**
	 * Table Name
	 *
	 * @var string
	 */
	public $table_name = 'form_submissions';

	/**
	 * Get Query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		  * Fields:
		  *
		  * id: Primary key
		  * form_id: ID of the form from forms table
		  * contact_id: ID of the contact who submitted the form
		  * external_entry_id: External entry ID
		  * created_at: Created at timestamp
		  * updated_at: Updated at timestamp
		  */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20),
            contact_id BIGINT(20),
			external_entry_id VARCHAR(255) NULL,
			status VARCHAR(20) NOT NULL DEFAULT "complete",
			answers LONGTEXT NULL,
			meta LONGTEXT NULL,
			variant VARCHAR(40) NULL,
			step INT(11) NULL,
			resume_token VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY contact_id (contact_id),
            KEY status (status)';

		return $query;
	}

	/**
	 * Add the columns and, on tables made before 1.11, the lookup indexes.
	 *
	 * dbDelta only runs when the table is new, so older installs get the indexes here.
	 *
	 * @return void
	 */
	public function ensure_columns(): void {
		parent::ensure_columns();

		global $wpdb;
		$wanted = array(
			'form_id'    => 'form_id',
			'contact_id' => 'contact_id',
			'status'     => 'status',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Schema read on our own table (no input).
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $this->table_name, ARRAY_A );
		$have = array();
		foreach ( (array) $rows as $row ) {
			$have[ $row['Key_name'] ] = true;
		}
		foreach ( $wanted as $name => $column ) {
			if ( isset( $have[ $name ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Fixed identifiers from the list above.
			$wpdb->query( 'ALTER TABLE ' . $this->table_name . ' ADD INDEX ' . $name . ' (' . $column . ')' );
		}
	}
}
