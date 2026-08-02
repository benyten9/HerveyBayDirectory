<?php
/**
 * Post Featured Image URL Merge Tag
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Post Featured Image URL Merge Tag
 */
class PostFeaturedImageUrl extends AbstractPostMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Last Post Featured Image URL';

	/**
	 * @var string
	 */
	public $slug = 'featured_image_url';

	/**
	 * @var string
	 */
	public $description = 'Featured image URL of the last published post';

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

		return get_the_post_thumbnail_url( $post->ID, 'full' ) ?: '';
	}
}

MergeTagsManager::instance()->register( new PostFeaturedImageUrl() );
