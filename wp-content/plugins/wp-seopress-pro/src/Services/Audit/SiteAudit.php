<?php
namespace SEOPressPro\Services\Audit;

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Site Audit service — exposes read-only counters on the
 * {$wpdb->prefix}seopress_seo_issues table.
 *
 * The render helpers that this class used to host (renderAnalysis,
 * renderAnalysisResults, renderAnalysisItem…) were powering the legacy
 * jQuery admin page and are gone since the React migration of the Site
 * Audit screen. The scanner worker itself still lives in
 * inc/admin/cron.php (seopress_site_audit_run_task_fn).
 *
 * @since 7.8.0
 */
class SiteAudit {

	/**
	 * Count seopress_seo_issues rows, optionally filtered by type / priority
	 * and whether they are ignored.
	 *
	 * @param string $type     Issue type slug or '' for all types.
	 * @param string $priority 'low' | 'medium' | 'high' or '' for all.
	 * @param int    $ignore   0 = active only, 1 = ignored only.
	 *
	 * @return int
	 */
	public function countTotalIssues( $type = '', $priority = '', $ignore = 0 ) {
		global $wpdb;

		$sql = 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'seopress_seo_issues`';

		$conditions = array();

		if ( ! empty( $type ) ) {
			$conditions[] = $wpdb->prepare( '`issue_type` = %s', $type );
		}

		if ( ! empty( $priority ) ) {
			$conditions[] = $wpdb->prepare( '`issue_priority` = %s', $priority );
		}

		if ( ! empty( $ignore ) ) {
			$conditions[] = $wpdb->prepare( '`issue_ignore` = %d', $ignore );
		}

		if ( ! empty( $conditions ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Count distinct posts represented in the seopress_seo_issues table.
	 *
	 * @return int
	 */
	public function countTotalCrawledURL() {
		global $wpdb;

		$sql = 'SELECT COUNT(DISTINCT `post_id`) FROM `' . $wpdb->prefix . 'seopress_seo_issues`';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}
}

$seopress_pro_site_audit = new SiteAudit();
