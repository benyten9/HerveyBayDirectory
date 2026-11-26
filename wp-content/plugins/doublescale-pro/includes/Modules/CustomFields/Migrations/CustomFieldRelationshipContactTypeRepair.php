<?php
/**
 * Give contact custom-field values saved without an entity type their type.
 *
 * The "Update Contact" automation action saved custom-field values with an
 * empty `entity_type`. Contacts only read rows typed `contact`, so those
 * values were stored but never shown, and each run added another hidden row.
 * This keeps the newest hidden row per contact and field, drops it when the
 * contact already has a visible value for that field (that value was set
 * later, from the contact screen, a form or an import), and types the rest
 * as `contact` so they show up.
 *
 * Only fields of the contact scope are touched; deals, projects and tasks
 * always saved their type.
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Migrations
 */

namespace DoubleScale\Pro\Modules\CustomFields\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * CustomFieldRelationshipContactTypeRepair migration.
 */
class CustomFieldRelationshipContactTypeRepair {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$relationships = $wpdb->prefix . 'doublescale_custom_field_relationship';
		$fields        = $wpdb->prefix . 'doublescale_custom_fields';

		foreach ( array( $relationships, $fields ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return;
			}
		}

		// Table names are ours (prefix + fixed name); WordPress 5.8 has no
		// placeholder for identifiers, so they are joined in.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$r = '`' . $relationships . '`';
		$f = '`' . $fields . '`';

		// 1. Several hidden rows for one contact and field: keep the newest.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE older FROM ' . $r . ' older
				INNER JOIN ' . $r . ' newer
					ON newer.entity_id = older.entity_id
					AND newer.custom_field_id = older.custom_field_id
					AND newer.entity_type = %s
					AND newer.id > older.id
				INNER JOIN ' . $f . ' cf ON cf.id = older.custom_field_id AND cf.scope = %s
				WHERE older.entity_type = %s',
				'',
				'contact',
				''
			)
		);

		// 2. The contact already has a visible value for the field: it is the later one.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE hidden FROM ' . $r . ' hidden
				INNER JOIN ' . $r . ' visible
					ON visible.entity_id = hidden.entity_id
					AND visible.custom_field_id = hidden.custom_field_id
					AND visible.entity_type = %s
				INNER JOIN ' . $f . ' cf ON cf.id = hidden.custom_field_id AND cf.scope = %s
				WHERE hidden.entity_type = %s',
				'contact',
				'contact',
				''
			)
		);

		// 3. Type what is left so it shows on the contact.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $r . ' rel
				INNER JOIN ' . $f . ' cf ON cf.id = rel.custom_field_id AND cf.scope = %s
				SET rel.entity_type = %s
				WHERE rel.entity_type = %s',
				'contact',
				'contact',
				''
			)
		);

		// phpcs:enable
	}
}
