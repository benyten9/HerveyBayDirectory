<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Post Watcher.
 *
 * @package Redirects
 */
defined( 'ABSPATH' ) || die( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Enqueues watcher script only when block editor is used.
 */
function seopress_pro_post_watcher_scripts() {
	$screen = get_current_screen();
	// Null on admin pages without a WP_Screen context; a method call on it is
	// an uncaught Error, not just a warning.
	if ( $screen && $screen->is_block_editor() && 'post' === $screen->base ) {
		global $post;
		wp_enqueue_script( 'seopress-pro-post-watcher', SEOPRESS_PRO_PUBLIC_URL . '/editor/post-watcher/post-watcher.js', array( 'wp-data' ), SEOPRESS_PRO_VERSION, true );

		$notice_data = array(
			'message'     => __( 'We have detected that you have changed the slug of this post. We suggest you to redirect this URL.', 'wp-seopress-pro' ),
			'actionLabel' => __( 'Create a redirection (new window)', 'wp-seopress-pro' ),
			'actionUrl'   => seopress_get_redirection_create_url(),
		);

		$notices   = seopress_get_option_post_need_redirects();
		$post_path = seopress_get_permalink_for_updated_post( $post );
		$filtered  = array();

		if ( ! empty( $notices ) ) {
			$filtered = array_values(
				array_filter(
					$notices,
					function ( $n ) use ( $post_path ) {
						return isset( $n['new_url'] ) && $post_path === $n['new_url'];
					}
				)
			);
		}

		if ( ! empty( $filtered ) ) {
			$notice                       = $filtered[0];
			$notice_data['actionUrl']     = seopress_get_redirection_create_url(
				(string) ( $notice['before_url'] ?? '' ),
				'update' === $notice['type'] ? (string) ( $notice['new_url'] ?? '' ) : ''
			);
			$notice_data['displayOnLoad'] = true;
		}

		wp_localize_script( 'seopress-pro-post-watcher', 'seopressPostWatcherData', array( 'notice_data' => $notice_data ) );
	}
}
add_action( 'admin_enqueue_scripts', 'seopress_pro_post_watcher_scripts', 10, 1 );

/**
 * Detect a post trash
 *
 * @param int $post_id The post ID.
 * @return void
 */
function seopress_watcher_post_trash( $post_id ) {

	$can_autoredirect = seopress_can_post_autoredirect( $post_id );

	if ( ! $can_autoredirect ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	$status = get_post_status( $post_id );
	if ( 'publish' !== $status ) {
		return;
	}

	$url = seopress_get_permalink_for_updated_post( $post_id );

	$notices = seopress_get_option_post_need_redirects();

	if ( $notices ) {
		foreach ( $notices as $key => $value ) {
			if ( isset( $value['new_url'] ) && $value['new_url'] === $url ) {
				seopress_remove_notification_for_redirect( $value['id'] );
			}
		}
	}
	/* translators: %s: post permalink */
	$message = '<p>';
	/* translators: %s URL of the post deleted */
	$message .= sprintf( __( 'We have detected that you have deleted a post (<code>%s</code>).', 'wp-seopress-pro' ), $url );
	$message .= '</p>';

	$message .= '<p>' . __( 'We suggest you to redirect this URL to avoid any SEO issues, and keep an optimal user experience.', 'wp-seopress-pro' ) . '</p>';

	seopress_create_notification_for_redirect(
		array(
			'id'         => uniqid( '', true ),
			'message'    => $message,
			'type'       => 'delete',
			'before_url' => $url,
		)
	);
}
add_action( 'wp_trash_post', 'seopress_watcher_post_trash' );

/**
 * Detect slug change. Not work in Gutenberg
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post The post object.
 * @param WP_Post $post_before The post object before the update.
 * @return void
 */
function seopress_watcher_slug_change( $post_id, $post, $post_before ) {
	$post_updated_at        = get_post_modified_time( 'U', true, $post );
	$post_before_updated_at = get_post_modified_time( 'U', true, $post_before );

	// Block editor triggers save_post hooks twice.
	// This prevents double trigger of the function.
	if ( abs( $post_updated_at - $post_before_updated_at ) < 10 ) {
		return;
	}

	$authorize = true;
	if ( 'trash' === $post_before->post_status ) {
		$authorize = false;
	}

	$can_autoredirect = seopress_can_post_autoredirect( $post_id );

	if ( ! $can_autoredirect ) {
		$authorize = false;
	}

	if ( wp_is_post_revision( $post_before ) !== false && wp_is_post_revision( $post ) !== false ) {
		$authorize = false;
	}

	$url_post        = seopress_get_permalink_for_updated_post( $post );
	$url_post_before = seopress_get_permalink_for_updated_post( $post_before );
	$notices         = seopress_get_option_post_need_redirects();

	if ( $notices ) {
		foreach ( $notices as $key => $value ) {
			if ( isset( $value['new_url'] ) && $value['new_url'] === $url_post_before ) {
				seopress_remove_notification_for_redirect( $value['id'] );
			}
		}
	}

	// Prevent same slug.
	if ( $url_post === $url_post_before ) {
		$authorize = false;
	}

	// Prevent {status} to publish.
	if ( $url_post !== $url_post_before && $post_before->post_status !== 'publish' && $post->post_status === 'publish' ) {
		$authorize = false;
	}

	$post_status_authorized = array(
		'publish',
		'static',
		'private',
	);

	if ( ! in_array( get_post_status( $post->ID ), $post_status_authorized, true ) || ! in_array( get_post_status( $post->ID ), $post_status_authorized, true ) ) {
		$authorize = false;
	}

	$authorize = apply_filters( 'seopress_watcher_slug_change_can_create_notification', $authorize, $post_id, $post, $post_before );

	if ( ! $authorize ) {
		return;
	}

	$message  = '<p>';
	$message .= sprintf(
		/* translators: %s: post name (slug) %s: url redirect */
		__( 'We have detected that you have changed a slug (<code>%1$s</code>) to (<code>%2$s</code>).', 'wp-seopress-pro' ),
		$url_post_before,
		$url_post
	);
	$message .= '</p>';

	$message .= '<p>' . __( 'We suggest you to redirect this URL.', 'wp-seopress-pro' ) . '</p>';

	seopress_create_notification_for_redirect(
		array(
			'id'         => uniqid( '', true ),
			'message'    => $message,
			'type'       => 'update',
			'before_url' => $url_post_before,
			'new_url'    => $url_post,
		)
	);
}
add_action( 'post_updated', 'seopress_watcher_slug_change', 12, 3 );

/**
 * Remove notice watcher if needed
 *
 * @param WP_Post $post The post object.
 * @return void
 */
function seopress_remove_notice_if_needed( $post ) {

	// Remove notice watcher if needed.
	$notices = seopress_get_option_post_need_redirects();
	if ( ! $notices ) {
		return;
	}

	foreach ( $notices as $key => $notice ) {
		if ( strpos( $notice['before_url'], $post->post_name ) !== false ) {
			seopress_remove_notification_for_redirect( $notice['id'] );
		}
	}
}
add_filter( 'trash_to_publish', 'seopress_remove_notice_if_needed' );
