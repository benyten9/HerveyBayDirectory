<?php
/**
 * Core REST routes of article pages (`/wp/v2/kb-articles`), for the block
 * editor only.
 *
 * Visitors and portal clients read articles through the help center routes
 * (`/doublescale/v1/kb/public`), which apply who may read what. Here a page
 * is readable only by someone who may edit it, so the core routes never hand
 * out a body, published or not.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ArticlePagesRestController class.
 */
class ArticlePagesRestController extends \WP_REST_Posts_Controller {

	/**
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function check_read_permission( $post ) {
		return $post instanceof \WP_Post
			&& current_user_can( 'edit_post', $post->ID )
			&& parent::check_read_permission( $post );
	}
}
