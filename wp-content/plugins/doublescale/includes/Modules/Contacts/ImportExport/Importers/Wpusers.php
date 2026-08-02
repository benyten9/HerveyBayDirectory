<?php
/**
 * Wpusers Importer
 *
 * This class is responsible for handling the Wpusers importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;
use DoubleScale\Core\Models\UserModel;

/**
 * Wpusers Importer class
 */
class Wpusers extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'WordPress Users';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'wpusers';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = false;

	/**
	 * Run importer
	 */
	public function run() {
		$total = UserModel::count();

		$mapping = array(
			'email'      => 'user_email',
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
		);

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) {
				$users = UserModel::offset( $offset )->limit( 20 )->get();

				foreach ( $users as $user ) {
					self::hydrate_user_name( $user );
				}

				return $users;
			},
			$mapping
		);

		return $result;
	}

	/**
	 * Populate first_name / last_name on a WP user model.
	 *
	 * These live in wp_usermeta, not the wp_users row, so the bare model has no
	 * such columns and the generic name mapping would import nothing. Read them
	 * from user meta and, when both are empty (common for users who never set a
	 * profile name), fall back to splitting display_name on the first space.
	 *
	 * @param object $user UserModel instance (assigned in place).
	 *
	 * @return void
	 */
	protected static function hydrate_user_name( $user ) {
		$first = (string) get_user_meta( $user->ID, 'first_name', true );
		$last  = (string) get_user_meta( $user->ID, 'last_name', true );

		if ( '' === $first && '' === $last && ! empty( $user->display_name ) ) {
			$parts = explode( ' ', trim( $user->display_name ), 2 );
			$first = $parts[0];
			$last  = isset( $parts[1] ) ? $parts[1] : '';
		}

		$user->first_name = $first;
		$user->last_name  = $last;
	}
}
