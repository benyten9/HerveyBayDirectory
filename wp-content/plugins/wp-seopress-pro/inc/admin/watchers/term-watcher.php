<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Term Watcher.
 *
 * @package Redirects
 */

defined( 'ABSPATH' ) || die( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Term Watcher
 *
 * This file handles automatic detection of term deletions and slug changes
 * to suggest redirects for SEO purposes.
 *
 * Filter Hook: seopress_watch_taxonomy_for_redirects
 * Allows developers to customize which taxonomies should be watched for auto redirects.
 *
 * Usage:
 * add_filter('seopress_watch_taxonomy_for_redirects', function($should_watch, $taxonomy_name, $taxonomy_object) {
 *     // Custom logic to determine if taxonomy should be watched
 *     return $should_watch;
 * }, 10, 3);
 */


add_action( 'delete_term_taxonomy', 'seopress_watcher_term_trash' );

/**
 * Detect a term trash
 *
 * @param int $term_id The term ID.
 * @return void
 */
function seopress_watcher_term_trash( $term_id ) {

	$term = get_term( $term_id );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}
	$taxonomy = get_taxonomy( $term->taxonomy );

	if ( ! $taxonomy ) {
		return;
	}

	// Filter to allow developers to customize which taxonomies to watch.
	$should_watch = apply_filters(
		'seopress_watch_taxonomy_for_redirects',
		( $taxonomy->publicly_queryable || $taxonomy->public ),
		$term->taxonomy,
		$taxonomy
	);

	if ( ! $should_watch ) {
		return;
	}

	$url = get_term_link( $term, $taxonomy );
	$url = wp_parse_url( $url );
	if ( is_array( $url ) && isset( $url['path'] ) ) {
		$url = $url['path'];
	}

	$notices = seopress_get_option_post_need_redirects();

	if ( $notices ) {
		foreach ( $notices as $key => $value ) {
			if ( isset( $value['new_url'] ) && $value['new_url'] === $url ) {
				seopress_remove_notification_for_redirect( $value['id'] );
			}
		}
	}
	$message  = '<p>';
	$message .= sprintf(
		/* translators: %s: term permalink */
		__( 'We have detected that you have deleted a term (<code>%s</code>).', 'wp-seopress-pro' ),
		$url
	);
	$message .= '</p>';

	$message .= '<p>' . esc_html__( 'We suggest you to redirect this URL to avoid any SEO issues, and keep an optimal user experience.', 'wp-seopress-pro' ) . '</p>';

	seopress_create_notification_for_redirect(
		array(
			'id'         => uniqid( '', true ),
			'message'    => $message,
			'type'       => 'delete',
			'before_url' => $url,
		)
	);
}

add_action( 'edit_term', 'seopress_get_old_slug_before_change', 10, 3 );

/**
 * Get the old slug before change
 *
 * @param int    $term_id The term ID.
 * @param int    $tt_id The term taxonomy ID.
 * @param string $taxonomy The taxonomy name.
 * @return void
 */
function seopress_get_old_slug_before_change( $term_id, $tt_id, $taxonomy ) {
	$term = get_term( $term_id, $taxonomy );

	set_transient( 'old_slug_term_' . $term_id, get_term_link( $term, $taxonomy ), 60 );
}



add_action( 'edited_term', 'seopress_watcher_term_slug_change', 10, 3 );

/**
 * Detect slug change
 *
 * @param int    $term_id The term ID.
 * @param int    $tt_id The term taxonomy ID.
 * @param string $taxonomy The taxonomy name.
 * @return void
 */
function seopress_watcher_term_slug_change( $term_id, $tt_id, $taxonomy ) {

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	$taxonomy = get_taxonomy( $taxonomy );

	if ( ! $taxonomy ) {
		return;
	}

	// Filter to allow developers to customize which taxonomies to watch.
	$should_watch = apply_filters(
		'seopress_watch_taxonomy_for_redirects',
		( $taxonomy->publicly_queryable || $taxonomy->public ),
		$taxonomy->name,
		$taxonomy
	);

	if ( ! $should_watch ) {
		return;
	}

	$url_term_before = get_transient( 'old_slug_term_' . $term_id );

	if ( ! $url_term_before ) {
		return;
	}

	delete_transient( 'old_slug_term_' . $term_id );
	$url_term_before = wp_parse_url( $url_term_before );

	if ( is_array( $url_term_before ) && isset( $url_term_before['path'] ) ) {
		$url_term_before = $url_term_before['path'];
	}

	$url = get_term_link( $term, $taxonomy );
	$url = wp_parse_url( $url );
	if ( is_array( $url ) && isset( $url['path'] ) ) {
		$url = $url['path'];
	}

	$notices = seopress_get_option_post_need_redirects();

	if ( $notices ) {
		foreach ( $notices as $key => $value ) {
			if ( isset( $value['new_url'] ) && $value['new_url'] === $url_term_before ) {
				seopress_remove_notification_for_redirect( $value['id'] );
			}
		}
	}

	// Prevent same slug.
	if ( $url === $url_term_before ) {
		return;
	}

	$message  = '<p>';
	$message .= sprintf(
		/* translators: %s: post name (slug) %s: url redirect */
		__( 'We have detected that you have changed a slug (<code>%1$s</code>) to (<code>%2$s</code>).', 'wp-seopress-pro' ),
		$url_term_before,
		$url
	);
	$message .= '</p>';
	$message .= '<p>' . esc_html__( 'We suggest you to redirect this URL.', 'wp-seopress-pro' ) . '</p>';

	seopress_create_notification_for_redirect(
		array(
			'id'         => uniqid( '', true ),
			'message'    => $message,
			'type'       => 'update',
			'before_url' => $url_term_before,
			'new_url'    => $url,
		)
	);
}
