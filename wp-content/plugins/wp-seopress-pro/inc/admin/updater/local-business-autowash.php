<?php
/**
 * SEOPress PRO — repair the Local Business "Auto Wash" type.
 *
 * The Business type dropdown stored `Auto Wash`, with a space, for the AutoWash
 * entry. schema.org has no such type, so any site that picked it published an
 * invalid `@type` and got nothing out of the markup.
 *
 * The list now stores `AutoWash`, which is both the real type and what the older
 * PHP list (seopress_lb_types_list()) has always used. That correction is enough
 * for sites carrying the legacy value: the option exists again, so the dropdown
 * can display and change it. Sites carrying the invalid one need their stored
 * value rewritten, which is what this file does.
 *
 * Two places can hold it: the global setting, and the per-post manual schema,
 * whose form reads the same list.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! defined( 'SEOPRESS_PRO_LB_AUTOWASH_VERSION' ) ) {
	define( 'SEOPRESS_PRO_LB_AUTOWASH_VERSION', 1 );
}

/**
 * The value that was written by mistake.
 */
const SEOPRESS_PRO_LB_AUTOWASH_WRONG = 'Auto Wash';

/**
 * The schema.org type it was meant to be.
 */
const SEOPRESS_PRO_LB_AUTOWASH_RIGHT = 'AutoWash';

/**
 * Rewrite every stored occurrence of the invalid type.
 *
 * Deliberately narrow: it only ever replaces one exact string with one exact
 * string, so a site that never picked that entry is left completely alone, and
 * running it twice changes nothing the second time.
 *
 * @since 10.3.0
 *
 * @return array{option:bool, posts:int} Whether the global setting was rewritten,
 *                                       and how many manual schemas were.
 */
function seopress_pro_repair_local_business_autowash() {
	$result = array(
		'option' => false,
		'posts'  => 0,
	);

	$options = get_option( 'seopress_pro_option_name' );

	if ( is_array( $options )
		&& isset( $options['seopress_local_business_type'] )
		&& SEOPRESS_PRO_LB_AUTOWASH_WRONG === $options['seopress_local_business_type'] ) {

		$options['seopress_local_business_type'] = SEOPRESS_PRO_LB_AUTOWASH_RIGHT;

		update_option( 'seopress_pro_option_name', $options );

		$result['option'] = true;
	}

	// Queried by value rather than walked over every post: the meta_value index
	// makes this cheap, and the vast majority of sites match nothing at all.
	$post_ids = get_posts(
		array(
			'post_type'        => 'any',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- the cleanup must reach every post, not the ones a multilingual plugin's filters leave in.
			'no_found_rows'    => true,
			'meta_query'       => array(
				array(
					'key'     => '_seopress_pro_rich_snippets_lb_type',
					'value'   => SEOPRESS_PRO_LB_AUTOWASH_WRONG,
					'compare' => '=',
				),
			),
		)
	);

	foreach ( $post_ids as $post_id ) {
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_lb_type', SEOPRESS_PRO_LB_AUTOWASH_RIGHT );
		++$result['posts'];
	}

	return $result;
}

add_action( 'admin_init', 'seopress_pro_migrate_local_business_autowash', 20 );
/**
 * One-shot repair for installs that update in place.
 *
 * WordPress does not fire the activation hook on plugin updates, so this runs
 * from admin_init behind its own guard option.
 *
 * No notice goes with it. Nothing the user chose changes: the entry they picked
 * is still the entry they picked, it simply stops being spelled in a way
 * schema.org rejects.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_pro_migrate_local_business_autowash() {
	$option_key = 'seopress_pro_lb_autowash_version';
	$current    = (int) get_option( $option_key, 0 );
	$target     = (int) SEOPRESS_PRO_LB_AUTOWASH_VERSION;

	if ( $current >= $target ) {
		return;
	}

	seopress_pro_repair_local_business_autowash();

	update_option( $option_key, $target, false );
}
