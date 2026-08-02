<?php
/**
 * Post Permalink Merge Tag
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Post;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Post Permalink Merge Tag
 */
class PostPermalink extends AbstractPostMergeTag {

	/**
	 * @var string
	 */
	public $name = 'Last Post Permalink';

	/**
	 * @var string
	 */
	public $slug = 'permalink';

	/**
	 * @var string
	 */
	public $description = 'Permalink URL of the last published post';

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

		return $post ? get_permalink( $post ) : '';
	}
}

MergeTagsManager::instance()->register( new PostPermalink() );
