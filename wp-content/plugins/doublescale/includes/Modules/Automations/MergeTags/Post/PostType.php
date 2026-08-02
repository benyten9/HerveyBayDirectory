<?php
/**
 * Post Type Merge Tag
 *
 * Returns the post type label (e.g. "Post", "Page", "Product") of the
 * last published post.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Post Type Merge Tag
 */
class PostType extends AbstractPostMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Last Post Type';

	/**
	 * @var string
	 */
	public $slug = 'type';

	/**
	 * @var string
	 */
	public $description = 'Post type of the last published post (e.g. Post, Page, Product)';

	/**
	 * Get Merge Tag Value
	 *
	 * @param mixed  $contact   Contact Model.
	 * @param string $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$post = $this->get_post();

		if ( ! $post ) {
			return '';
		}

		$post_type_object = get_post_type_object( $post->post_type );

		return $post_type_object ? $post_type_object->labels->singular_name : $post->post_type;
	}
}

MergeTagsManager::instance()->register( new PostType() );
