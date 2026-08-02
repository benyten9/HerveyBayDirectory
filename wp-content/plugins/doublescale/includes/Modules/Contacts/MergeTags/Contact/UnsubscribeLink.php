<?php
/**
 * Class Unsubscribe Link Merge Tag
 *
 * Merge tag for unsubscribe link
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
 * Unsubscribe Link Merge Tag
 */
class UnsubscribeLink extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Unsubscribe Link';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'unsubscribe_link';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Channel-specific unsubscribe link';

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

		// Get channel from filter context (set by campaign processing)
		$channel = apply_filters( 'doublescale_active_channel_context', 'email' );

		$hash_id = $contact->hash_id;
		$args    = array(
			'doublescale-unsubscribe' => '1',
			'id'                      => $hash_id,
			'channel'                 => $channel,
		);
		$link    = add_query_arg( $args, home_url() );

		return $link;
	}
}

MergeTagsManager::instance()->register( new UnsubscribeLink() );
