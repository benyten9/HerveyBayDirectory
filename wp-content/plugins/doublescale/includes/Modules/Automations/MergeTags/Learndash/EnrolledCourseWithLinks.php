<?php

/**
 * Class Enrolled Course With Links Merge Tag
 *
 * This class is responsible for handling the enrolled course with links merge tag
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
 * Enrolled Course With Links Merge Tag
 */
class EnrolledCourseWithLinks extends MergeTag {




	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Enrolled Course With Links (list)';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'enrolled_course_with_links';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Enrolled Course With Links';

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

		$enrolled_courses = ld_get_mycourses( $user->ID );
		$course_links     = array();

		foreach ( $enrolled_courses as $course_id ) {
			$course_title = get_the_title( $course_id );
			$course_url   = get_permalink( $course_id );

			if ( $course_title && $course_url ) {
				$course_links[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $course_url ), esc_html( $course_title ) );
			}
		}

		if ( empty( $course_links ) ) {
			return '';
		}

		return '<ul>' . implode( '', $course_links ) . '</ul>';
	}
}

MergeTagsManager::instance()->register( new EnrolledCourseWithLinks() );
