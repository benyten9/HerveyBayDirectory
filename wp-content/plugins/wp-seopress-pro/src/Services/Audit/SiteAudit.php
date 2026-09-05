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

		// Joined on published posts so the counters describe exactly what the
		// detail view will show. Rows can outlive their post (deleted content,
		// pages that left the audit scope): counting them says "33 active"
		// while the list renders 3, with an empty extra page for the rest.
		$sql = 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'seopress_seo_issues` AS issues'
			. " INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish'";

		$conditions = array();

		if ( ! empty( $type ) ) {
			$conditions[] = $wpdb->prepare( 'issues.`issue_type` = %s', $type );
		}

		if ( ! empty( $priority ) ) {
			$conditions[] = $wpdb->prepare( 'issues.`issue_priority` = %s', $priority );
		}

		if ( ! empty( $ignore ) ) {
			$conditions[] = $wpdb->prepare( 'issues.`issue_ignore` = %d', $ignore );
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

		// Same published-posts join as countTotalIssues(), same reason.
		$sql = 'SELECT COUNT(DISTINCT issues.`post_id`) FROM `' . $wpdb->prefix . 'seopress_seo_issues` AS issues'
			. " INNER JOIN {$wpdb->posts} AS posts ON posts.ID = issues.post_id AND posts.post_status = 'publish'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}
}

$seopress_pro_site_audit = new SiteAudit();
