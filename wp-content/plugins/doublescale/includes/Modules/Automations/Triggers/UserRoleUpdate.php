<?php
/**
 * User Role Update Trigger
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Automations\Abstracts\Trigger;

/**
 * User Role Update Trigger
 */
class UserRoleUpdate extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Role Update';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'user_role_update';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user role is updated.';

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
	public $source = 'wp';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'user';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'set_user_role', array( $this, 'user_role_update' ), 10, 3 );
	}

	/**
	 * User Role Update
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id
	 * @param string $role
	 * @param array  $old_roles
	 *
	 * @return void
	 */
	public function user_role_update( $user_id, $role, $old_roles ) {
		$user = get_user_by( 'id', $user_id );
		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'old_roles' => $old_roles,
				'user_id'   => $user_id,
			),
		);

		$this->process( $data );
	}
}
