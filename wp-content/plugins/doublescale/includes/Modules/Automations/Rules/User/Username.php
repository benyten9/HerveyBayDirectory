<?php

/**
 * Class Username
 *
 * This class is responsible for handling the user username rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\User;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Core\Models\UserModel;

/**
 * Username class
 */
class Username extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Username';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'user_username';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'user';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'select';

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @param string $keyword Keyword.
	 *
	 * @return array
	 */
	public function get_options( $keyword = '' ) {
		$users   = UserModel::where( 'user_login', 'like', '%' . $keyword . '%' )->get();
		$options = array();
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->user_login;
		}

		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$user_id = $automation_contact->get_data( 'user_id', null );
		if ( ! $user_id ) {
			return null;
		}

		return $user_id;
	}
}

RulesManager::instance()->register( new Username() );
