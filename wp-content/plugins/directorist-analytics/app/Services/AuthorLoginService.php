<?php
/**
 * Author Login Service
 *
 * Tracks author login timestamps and counts.
 *
 * @package DirectoristAnalytics
 * @subpackage Services
 * @since 1.0.0
 */

namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Author Login Service Class
 *
 * @since 1.0.0
 */
class AuthorLoginService {

	/**
	 * Track author login
	 *
	 * @since 1.0.0
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 * @return void
	 */
	public function track_login( $user_login, $user ) {
		if ( ! $user || ! isset( $user->ID ) ) {
			return;
		}

		$user_id = absint( $user->ID );

		// Update last login time
		update_user_meta( $user_id, '_directorist_last_login', current_time( 'mysql' ) );

		// Increment login count
		$count = absint( get_user_meta( $user_id, '_directorist_login_count', true ) );
		update_user_meta( $user_id, '_directorist_login_count', $count + 1 );
	}

	/**
	 * Get last login time
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return string|null
	 */
	public function get_last_login( $user_id ) {
		$last_login = get_user_meta( absint( $user_id ), '_directorist_last_login', true );

		return $last_login ? sanitize_text_field( $last_login ) : null;
	}

	/**
	 * Get login count
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_login_count( $user_id ) {
		return absint( get_user_meta( absint( $user_id ), '_directorist_login_count', true ) );
	}
}