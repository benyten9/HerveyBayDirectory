<?php
/**
 * Abstract Post Merge Tag
 *
 * Base class for all post-related merge tags. Centralizes post retrieval
 * from the campaign execution context.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Abstract Post Merge Tag
 */
abstract class AbstractPostMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'last_post';

	/**
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * @var array
	 */
	public $required_triggers = array( 'post_published' );

	/**
	 * Get the post from the current campaign context.
	 *
	 * Returns null when no post_id is set — this is intentional.
	 * Post merge tags only make sense during event-based execution
	 * where the triggering post_id is known.
	 *
	 * @return \WP_Post|null
	 */
	protected function get_post() {
		$post_id = MergeTagsManager::instance()->get_current_post_id();

		if ( ! $post_id ) {
			return null;
		}

		return get_post( $post_id );
	}
}
