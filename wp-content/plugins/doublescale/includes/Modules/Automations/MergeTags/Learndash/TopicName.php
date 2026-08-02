<?php

/**
 * Class TopicName Merge Tag
 *
 * This class is responsible for handling the topic name merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Topic Name Merge Tag
 */
class TopicName extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Topic Name';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'topic_name';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Topic Name';

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array( 'learndash_topic_completed' );

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'learndash';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$topic_id = $contact->get_data( 'topic_id' );

		$topic = get_post( $topic_id );

		if ( ! empty( $topic ) ) {
			return $topic->post_title;
		}

		return '';
	}
}

MergeTagsManager::instance()->register( new TopicName() );
