<?php

/**
 * Class Enrolled Course With Links Merge Tag
 *
 * This class is responsible for handling the enrolled group with links merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Learndash;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Enrolled Group With Links Merge Tag
 */
class EnrolledGroupWithLinks extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Enrolled Group With Links (list)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'enrolled_group_with_links';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Enrolled Group With Links';

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
		$contact_id = $contact->contact_id;
		$contact    = ContactModel::find( $contact_id );

		if ( ! $contact ) {
			return '';
		}

		$user = get_user_by( 'email', $contact->email );

		if ( ! $user ) {
			return '';
		}

		$enrolled_groups = learndash_get_users_group_ids( $user->ID );
		$group_links     = array();

		foreach ( $enrolled_groups as $group_id ) {
			$group_title = get_the_title( $group_id );
			$group_url   = get_permalink( $group_id );

			if ( $group_title && $group_url ) {
				$group_links[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $group_url ), esc_html( $group_title ) );
			}
		}

		if ( empty( $group_links ) ) {
			return '';
		}

		return '<ul>' . implode( '', $group_links ) . '</ul>';
	}
}

MergeTagsManager::instance()->register( new EnrolledGroupWithLinks() );
