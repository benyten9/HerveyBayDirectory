<?php

/**
 * LifterLMS Trigger for Membership Enrolled
 * This trigger will be fired when a user is added to a membership level.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Lifterlms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * Membership Enrolled Trigger
 */
class MembershipEnrolled extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Membership Enrolled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'lifterlms_membership_enrolled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user is added to a LifterLMS membership level.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'lms';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'lifterlms';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'llms_user_added_to_membership_level', array( $this, 'membership_enrolled' ), 10, 2 );
	}

	/**
	 * Membership Enrolled
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @param int $membership_id Membership ID.
	 * @return void
	 */
	public function membership_enrolled( $user_id, $membership_id ) {
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'membership_id' => $membership_id,
				'user_id'       => $user_id,
			),
		);

		$this->process( $data );
	}
}
