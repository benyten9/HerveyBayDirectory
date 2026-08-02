<?php

/**
 * LearnDash Trigger for User Added to Group
 * This trigger will be fired when a user is added to a group.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Learndash;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use WP_User;

/**
 * User Added to Group Trigger
 */
class UserAddedToGroup extends Trigger
{




	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Added to Group';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'learndash_user_added_group';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user is added to a group.';

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
	public $group = 'learndash';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('ld_added_group_access', array($this, 'user_added_to_group'), 10, 2);
	}

	/**
	 * User Added to Group
	 *
	 * @since 1.0.0
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function user_added_to_group($user_id, $group_id)
	{
		$user = get_user_by('ID', $user_id);
		if (! $user instanceof WP_User) {
			return;
		}

		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'group_id' => $group_id,
				'user_id'  => $user_id,
			),
		);

		$this->process($data);
	}
}
