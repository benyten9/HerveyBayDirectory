<?php

namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

use SEOPress\Core\Hooks\ExecuteHooks;

/**
 * Removes a post's Site Audit issues when the post is permanently deleted.
 *
 * Nothing else ever removes rows from the seopress_seo_issues table for a
 * gone post: the scanner only rewrites rows for posts it visits, and a
 * deleted post is never visited again. The leftovers then poison the audit
 * screen in a way that is hard to diagnose from the UI: the Results table
 * counts them ("33 active"), while the detail view drops them at render
 * time because the post no longer resolves, so it shows 3 rows, announces
 * 33, and offers a second page that is empty.
 *
 * Registered on deleted_post rather than wp_trash_post: a trashed post can
 * be restored with its history intact, and the audit queries already filter
 * on published posts, so trashed content disappears from the screens without
 * losing its rows.
 */
class SiteAuditCleanup implements ExecuteHooks {

	/**
	 * @return void
	 */
	public function hooks() {
		add_action( 'deleted_post', array( $this, 'purgeIssuesForPost' ), 10, 2 );
	}

	/**
	 * Drop every audit issue attached to the deleted post.
	 *
	 * @param int           $post_id Deleted post ID.
	 * @param \WP_Post|null $post    Deleted post object (WP >= 5.5).
	 *
	 * @return void
	 */
	public function purgeIssuesForPost( $post_id, $post = null ) {
		// Revisions and menu items go through deleted_post too; their IDs can
		// never be in the issues table, so skip the query for them.
		if ( $post instanceof \WP_Post && in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) ) {
			return;
		}

		if ( ! function_exists( 'seopress_pro_get_service' ) ) {
			return;
		}

		$repository = seopress_pro_get_service( 'SEOIssuesRepository' );

		if ( $repository && method_exists( $repository, 'deleteAllForPost' ) ) {
			$repository->deleteAllForPost( (int) $post_id );
		}
	}
}
