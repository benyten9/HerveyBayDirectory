<?php
/**
 * User Login Trigger
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
 * User Login Trigger
 */
class UserLogin extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Login';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'user_login';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user logs in.';

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
		add_action( 'wp_login', array( $this, 'user_login' ), 10, 2 );
	}

	/**
	 * User Login
	 *
	 * @since 1.0.0
	 *
	 * @param string  $user_login
	 * @param WP_User $user
	 *
	 * @return void
	 */
	public function user_login( $user_login, $user ) {
		$data = array(
			'email' => $user->user_email,
			'data'  => array(
				'user_id' => $user->ID,
			),
		);
		$this->process( $data );
	}
}
