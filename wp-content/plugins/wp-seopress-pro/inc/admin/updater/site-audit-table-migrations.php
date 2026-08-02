<?php
/**
 * SEOPress PRO — Site Audit table schema migrations.
 *
 * One-shot ALTER TABLE statements applied on admin_init to bring the
 * `wp_seopress_seo_issues` table in line with the schema the Overview
 * queries rely on (GROUP BY / WHERE on issue_priority / issue_type).
 *
 * The Table/QueryCreateTable abstraction only knows how to emit single-
 * column indexes, so composite indexes have to live outside of it. The
 * column-type narrowing (TEXT → VARCHAR) is also required because TEXT
 * columns can't be indexed without a prefix length.
 *
 * Guarded by a dedicated option (`seopress_pro_site_audit_schema_version`)
 * so we don't re-run ALTERs on every admin page view and so we can stage
 * future schema changes incrementally.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! defined( 'SEOPRESS_PRO_SITE_AUDIT_SCHEMA_VERSION' ) ) {
	define( 'SEOPRESS_PRO_SITE_AUDIT_SCHEMA_VERSION', 2 );
}

add_action( 'admin_init', 'seopress_pro_migrate_site_audit_table_schema', 20 );
/**
 * Bring the seopress_seo_issues table up to the expected schema version.
 *
 * Runs once per install; subsequent calls short-circuit on the version
 * option. Safe if the table doesn't exist yet (returns silently — the
 * scanner's table creation path will pick up the new column types from
 * TableList on the first run).
 *
 * @return void
 */
function seopress_pro_migrate_site_audit_table_schema() {
	global $wpdb;

	$option_key  = 'seopress_pro_site_audit_schema_version';
	$current     = (int) get_option( $option_key, 0 );
	$target      = (int) SEOPRESS_PRO_SITE_AUDIT_SCHEMA_VERSION;

	if ( $current >= $target ) {
		return;
	}

	$table = $wpdb->prefix . 'seopress_seo_issues';

	// Scanner hasn't run yet → table doesn't exist. TableList already
	// carries the new column types, so fresh creation will land with the
	// right schema; we'll add the composite indexes on the next admin tick.
	$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	if ( '' === $exists ) {
		return;
	}

	if ( $current < 1 ) {
		seopress_pro_site_audit_migration_v1( $table );
	}

	if ( $current < 2 ) {
		seopress_pro_site_audit_migration_v2( $table );
	}

	update_option( $option_key, $target );
}

/**
 * v1 — narrow issue_type / issue_priority from TEXT to VARCHAR so they
 * can be indexed. VARCHAR(64) comfortably covers every slug we emit
 * (the longest issue_type today is 'nofollow_links' at 14 chars);
 * VARCHAR(16) covers every priority we emit ('high' / 'medium' / 'low' /
 * 'good'). `ALTER ... MODIFY` keeps existing data intact.
 *
 * @param string $table Fully qualified table name (with prefix).
 *
 * @return void
 */
function seopress_pro_site_audit_migration_v1( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "ALTER TABLE {$table} MODIFY issue_type VARCHAR(64) NOT NULL DEFAULT '', MODIFY issue_priority VARCHAR(16) NOT NULL DEFAULT ''" );
}

/**
 * v2 — composite indexes that cover the Overview / Results hotpaths:
 *   - (issue_ignore, issue_priority) for countTotalIssues() by priority
 *     and for the priority-urls ranking query
 *   - (issue_ignore, issue_type) for queryIssuesForType() with the
 *     active-only filter
 *
 * Checked for presence first so re-running this migration after a
 * partial failure (or on a site where an index was added manually) is
 * safe.
 *
 * @param string $table Fully qualified table name (with prefix).
 *
 * @return void
 */
function seopress_pro_site_audit_migration_v2( $table ) {
	global $wpdb;

	$indexes = array(
		'idx_ignore_priority' => '(issue_ignore, issue_priority)',
		'idx_ignore_type'     => '(issue_ignore, issue_type)',
	);

	foreach ( $indexes as $name => $cols ) {
		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				$name
			)
		);
		if ( 0 !== $exists ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$name} {$cols}" );
	}
}
