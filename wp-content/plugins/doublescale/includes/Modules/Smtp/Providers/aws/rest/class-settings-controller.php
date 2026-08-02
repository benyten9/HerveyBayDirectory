<?php
/**
 * Settings_Controller class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Providers\Aws\REST;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\REST\Settings_Controller as Abstract_Settings_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use Exception;

/**
 * Settings_Controller class.
 *
 * @since 1.0.0
 */
class Settings_Controller extends Abstract_Settings_Controller {

	/**
	 * Retrieves schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {}

	/**
	 * Register controller routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}" . '/(?P<id>[^\/\?]+)/identities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_identities' ),
					'permission_callback' => array( $this, 'get_identities_permissions_check' ),
					'args'                => array(),
				),
				// Verfiy identity.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'verify_identity' ),
					'permission_callback' => array( $this, 'verify_identity_permissions_check' ),
					'args'                => array(),
				),
				// Delete identity.
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_identity' ),
					'permission_callback' => array( $this, 'delete_identity_permissions_check' ),
					'args'                => array(),
				),
			)
		);

		// Resend verification email.
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}" . '/(?P<id>[^\/\?]+)/identities/resend-verification',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resend_verification_email' ),
					'permission_callback' => array( $this, 'resend_verification_email_permissions_check' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_identities( $request ) { // phpcs:ignore

		try {
			$account_id  = $request->get_param( 'id' );
			$account_api = $this->mailer->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new Exception( $account_api->get_error_message() );
			}
			$result     = array();
			$client     = $account_api->get_client();
			$emails     = $client->listIdentities(
				array(
					'IdentityType' => 'EmailAddress',
				)
			);
			$emails     = $emails->get( 'Identities' );
			$domains    = $client->listIdentities(
				array(
					'IdentityType' => 'Domain',
				)
			);
			$domains    = $domains->get( 'Identities' );
			$attributes = $client->getIdentityVerificationAttributes(
				array(
					'Identities' => array_merge( $emails, $domains ),
				)
			);
			$attributes = $attributes->get( 'VerificationAttributes' );
			$dkim       = $client->getIdentityDkimAttributes(
				array(
					'Identities' => array_merge( $emails, $domains ),
				)
			);
			$dkim       = $dkim->get( 'DkimAttributes' );
			$identities = array_merge( $emails, $domains );

			foreach ( $identities as $identity ) {
				$result[] = array(
					'type'       => in_array( $identity, $emails, true ) ? 'email' : 'domain',
					'identity'   => $identity,
					'status'     => $attributes[ $identity ]['VerificationStatus'],
					'attributes' => $attributes[ $identity ],
					'dkim'       => $dkim[ $identity ],
				);
			}
			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Aws Get Identities Error', 'doublescale' ),
				array(
					'code'  => 'aws_get_identities_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);

			return new WP_Error( 'gmail_get_user_profile_error', $e->getMessage() );
		}
	}

	/**
	 * Checks if a given request has access to get items.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_identities_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Verify identity
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function verify_identity( $request ) { // phpcs:ignore
		try {
			$account_id  = $request->get_param( 'id' );
			$account_api = $this->mailer->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new Exception( $account_api->get_error_message() );
			}
			$type   = $request->get_param( 'type' );
			$client = $account_api->get_client();

			if ( 'domain' === $type ) {
				$result = $client->verifyDomainIdentity(
					array(
						'Domain' => $request->get_param( 'identity' ),
					)
				);
			} else {
				$result = $client->verifyEmailIdentity(
					array(
						'EmailAddress' => $request->get_param( 'identity' ),
					)
				);
			}

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Aws Verify Identity Error', 'doublescale' ),
				array(
					'code'  => 'aws_verify_identity_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);

			return new WP_Error( 'aws_verify_identity_error', $e->getMessage() );
		}
	}

	/**
	 * Checks if a given request has access to verify identity.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function verify_identity_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Delete identity
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_identity( $request ) { // phpcs:ignore
		try {
			$account_id  = $request->get_param( 'id' );
			$account_api = $this->mailer->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new Exception( $account_api->get_error_message() );
			}
			$type   = $request->get_param( 'type' );
			$client = $account_api->get_client();

			if ( 'domain' === $type ) {
				$result = $client->deleteIdentity(
					array(
						'Identity' => $request->get_param( 'identity' ),
					)
				);
			} else {
				$result = $client->deleteIdentity(
					array(
						'Identity' => $request->get_param( 'identity' ),
					)
				);
			}

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Aws Delete Identity Error', 'doublescale' ),
				array(
					'code'  => 'aws_delete_identity_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);

			return new WP_Error( 'aws_delete_identity_error', $e->getMessage() );
		}
	}

	/**
	 * Checks if a given request has access to delete identity.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function delete_identity_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}

	/**
	 * Resend verification email
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function resend_verification_email( $request ) { // phpcs:ignore
		try {
			$account_id  = $request->get_param( 'id' );
			$account_api = $this->mailer->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new Exception( $account_api->get_error_message() );
			}
			$client = $account_api->get_client();

			$result = $client->verifyEmailIdentity(
				array(
					'EmailAddress' => $request->get_param( 'identity' ),
				)
			);

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Aws Resend Verification Email Error', 'doublescale' ),
				array(
					'code'  => 'aws_resend_verification_email_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);

			return new WP_Error( 'aws_resend_verification_email_error', $e->getMessage() );
		}
	}

	/**
	 * Checks if a given request has access to resend verification email.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function resend_verification_email_permissions_check( $request ) {
		$capability = 'manage_options';
		return current_user_can( $capability, $request );
	}
}
