<?php
/**
 * User Register Trigger
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
 * User Register Trigger
 */
class UserRegister extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'User Register';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'user_register';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user registers.';

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
		add_action( 'user_register', array( $this, 'user_register' ), 10, 2 );
	}

	/**
	 * User Register
	 *
	 * @since 1.0.0
	 *
	 * @param int   $user_id
	 * @param array $user_data
	 *
	 * @return void
	 */
	public function user_register( $user_id, $user_data ) {
		$user_email = $user_data['user_email'];
		$data       = array(
			'email' => $user_email,
			'data'  => array(
				'user_id' => $user_id,
			),
		);
		$this->process( $data );
	}
}
