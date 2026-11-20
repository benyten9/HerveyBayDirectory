<?php
/**
 * The signed-in team member's own profile photo.
 *
 *   POST   /me/photo   Upload one picture (multipart, field "photo").
 *   DELETE /me/photo   Remove it: back to Gravatar or the first letter.
 *
 * Only for people who answer tickets ({@see ProfilePhoto::can_set()}), and
 * only their own photo: there is no user id to pass. WordPress's
 * upload_files is not needed, because the file never reaches the media
 * library: ProfilePhoto checks it and saves a re-drawn copy.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Support\Services\ProfilePhoto;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestProfilePhotoController class.
 */
class RestProfilePhotoController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'me';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/photo',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'own_photo_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'own_photo_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function own_photo_permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'doublescale' ), array( 'status' => 401 ) );
		}
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		return ProfilePhoto::can_set( get_current_user_id() )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Only your support team can set a profile photo.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['photo'] ) && is_array( $files['photo'] ) ? $files['photo'] : null;
		if ( null === $file ) {
			return new WP_Error( 'photo_missing', __( 'Choose a JPG, PNG or WebP image.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		// PHP stopped it for its size before we saw it.
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return ProfilePhoto::too_large();
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return ProfilePhoto::not_an_image();
		}

		$user_id = get_current_user_id();
		$url     = ProfilePhoto::store( $user_id, $tmp );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return new WP_REST_Response( $this->shape( $user_id, $url ), 200 );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function remove() {
		$user_id = get_current_user_id();
		ProfilePhoto::remove( $user_id );

		return new WP_REST_Response( $this->shape( $user_id, '' ), 200 );
	}

	/**
	 * The photo, and the avatars the rest of the app shows now (Gravatar
	 * again once removed).
	 *
	 * @param int    $user_id User.
	 * @param string $photo   The DoubleScale photo, '' for none.
	 * @return array<string, mixed>
	 */
	private function shape( int $user_id, string $photo ): array {
		return array(
			'photo'       => $photo,
			'avatar_urls' => rest_get_avatar_urls( $user_id ),
		);
	}
}
