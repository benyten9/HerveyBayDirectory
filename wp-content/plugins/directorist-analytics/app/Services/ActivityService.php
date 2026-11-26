<?php
/**
 * Activity Service
 *
 * Handles activity recording for the Overview tab.
 *
 * @package DirectoristAnalytics
 * @subpackage Services
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\App\Models\Activity;

/**
 * Activity Service Class
 *
 * @since 1.0.0
 */
class ActivityService {

	/**
	 * Record listing created activity
	 *
	 * @since 1.0.0
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function record_listing_created( $listing_id ) {
		$listing_id = absint( $listing_id );
		$listing    = get_post( $listing_id );

		if ( ! $listing || 'at_biz_dir' !== $listing->post_type ) {
			return false;
		}

		Activity::query()->insert(
			array(
				'activity_type' => 'listing_created',
				'object_id'     => $listing_id,
				'user_id'       => absint( $listing->post_author ),
				'title'         => sanitize_text_field( $listing->post_title ),
				'created_at'    => current_time( 'mysql' ),
			)
		);

		$this->cleanup_old_activities();

		return true;
	}

	/**
	 * Record review submitted activity
	 *
	 * @since 1.0.0
	 * @param int $comment_id Comment ID.
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	public function record_review_submitted( $comment_id, $listing_id = 0 ) {
		$comment_id = absint( $comment_id );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return false;
		}

		// Get listing ID from comment if not provided
		if ( ! $listing_id ) {
			$listing_id = absint( $comment->comment_post_ID );
		}

		$listing = get_post( $listing_id );
		if ( ! $listing ) {
			return false;
		}

		$title = sprintf(
			/* translators: 1: Listing title, 2: Review excerpt */
			__( 'Review on "%1$s": %2$s', 'directorist-advanced-review' ),
			$listing->post_title,
			wp_trim_words( $comment->comment_content, 10, '...' )
		);

		Activity::query()->insert(
			array(
				'activity_type' => 'review_submitted',
				'object_id'     => $comment_id,
				'user_id'       => absint( $comment->user_id ),
				'title'         => sanitize_text_field( $title ),
				'created_at'    => current_time( 'mysql' ),
			)
		);

		$this->cleanup_old_activities();

		return true;
	}

	/**
	 * Record user registered activity
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function record_user_registered( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$title = sprintf(
			/* translators: %s: Username */
			__( 'New user registered: %s', 'directorist-advanced-review' ),
			$user->display_name
		);

		Activity::query()->insert(
			array(
				'activity_type' => 'user_registered',
				'object_id'     => $user_id,
				'user_id'       => $user_id,
				'title'         => sanitize_text_field( $title ),
				'created_at'    => current_time( 'mysql' ),
			)
		);

		$this->cleanup_old_activities();

		return true;
	}

    /**
     * Delete a user's activity rows from one site's table.
     *
     * @param int      $user_id User whose rows should be deleted.
     * @param int|null $site_id Site ID. Defaults to the current site.
     * @return void
     */
    public function delete_user_activities( $user_id, $site_id = null ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $table = $wpdb->get_blog_prefix( $site_id ?: null ) . Activity::get_table_name();

        // Analytics tables may be missing on some sites in a multisite installation.
        $existing_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $table !== $existing_table ) {
            return;
        }

        $wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );
    }

		/**
	 * Cleanup old activities (keep only latest 500)
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function cleanup_old_activities() {
		global $wpdb;

		$table = $wpdb->prefix . Activity::get_table_name();

		// Count total activities
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// Only cleanup if we have more than 500
		if ( $total <= 500 ) {
			return;
		}

		// Get the ID of the 500th record (from newest)
		$cutoff_id = $wpdb->get_var(
			"SELECT id FROM {$table} 
			ORDER BY created_at DESC 
			LIMIT 1 OFFSET 500"
		);

		if ( $cutoff_id ) {
			// Delete all records older than the cutoff
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id < %d",
					$cutoff_id
				)
			);
		}
	}
}