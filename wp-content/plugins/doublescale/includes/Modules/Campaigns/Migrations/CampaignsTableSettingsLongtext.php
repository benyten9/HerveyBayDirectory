<?php
/**
 * Widen campaigns.settings from TEXT to LONGTEXT.
 *
 * An email sequence keeps its enrolments (contact_ids, contact_enrollments,
 * contact_exit_reasons) in `settings`, about 46 bytes per contact. TEXT stops
 * at 64 KB (~1,400 contacts), and with strict mode off MySQL truncated the
 * JSON instead of refusing it, which wiped the sequence's settings.
 *
 * @package DoubleScale\Modules\Campaigns\Migrations
 */

namespace DoubleScale\Modules\Campaigns\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * CampaignsTableSettingsLongtext migration.
 */
class CampaignsTableSettingsLongtext {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_campaigns';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'settings'" );
		if ( ! $column || ! isset( $column->Type ) ) {
			return;
		}

		if ( 'longtext' === strtolower( $column->Type ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY settings LONGTEXT NULL" );
	}
}
