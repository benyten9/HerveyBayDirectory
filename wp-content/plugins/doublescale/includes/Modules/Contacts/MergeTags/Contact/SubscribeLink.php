<?php
/**
 * Class Subscribe Link Merge Tag
 *
 * Merge tag for subscribe link
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Subscribe Link Merge Tag
 */
class SubscribeLink extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Subscribe Link';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'subscribe_link';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Subscribe Link';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Get Merge Tag Value
	 *
	 * @param ContactModel $contact Contact Model.
	 * @param string       $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( is_null( $contact ) ) {
			return '#';
		}

		$hash_id = $contact->hash_id;
		$args    = array(
			'doublescale-subscribe' => '1',
			'id'                    => $hash_id,
		);
		$link    = add_query_arg( $args, home_url() );

		return $link;
	}
}

MergeTagsManager::instance()->register( new SubscribeLink() );
