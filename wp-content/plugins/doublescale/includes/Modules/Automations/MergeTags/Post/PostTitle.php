<?php
/**
 * Post Title Merge Tag
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Post Title Merge Tag
 */
class PostTitle extends AbstractPostMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Last Post Title';

	/**
	 * @var string
	 */
	public $slug = 'title';

	/**
	 * @var string
	 */
	public $description = 'Title of the last published post';

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

		return $post ? get_the_title( $post ) : '';
	}
}

MergeTagsManager::instance()->register( new PostTitle() );
