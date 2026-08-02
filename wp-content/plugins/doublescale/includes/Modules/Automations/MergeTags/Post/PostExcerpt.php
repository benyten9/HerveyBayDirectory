<?php
/**
 * Post Excerpt Merge Tag
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Post Excerpt Merge Tag
 */
class PostExcerpt extends AbstractPostMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Last Post Excerpt';

	/**
	 * @var string
	 */
	public $slug = 'excerpt';

	/**
	 * @var string
	 */
	public $description = 'Excerpt of the last published post';

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

		if ( ! empty( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 55, '...' );
	}
}

MergeTagsManager::instance()->register( new PostExcerpt() );
