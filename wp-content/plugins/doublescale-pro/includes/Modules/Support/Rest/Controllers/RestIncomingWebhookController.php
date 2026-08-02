<?php
/**
 * REST controller for the incoming support webhook.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Pro\Modules\Support\Services\IncomingWebhookService;
use DoubleScale\Pro\Modules\Support\Services\WebhookTokenService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestIncomingWebhookController class.
 */
class RestIncomingWebhookController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'support';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/support/webhook/(?P<mailbox_id>[\d]+)/(?P<token>[a-f0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ingest' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/incoming-webhook',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
					'args'                => array(
						'mailbox_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/incoming-webhook/regenerate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ingest( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! $this->check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$mailbox_id = (int) $request->get_param( 'mailbox_id' );
		$token      = strtolower( (string) $request->get_param( 'token' ) );
		$mailbox    = $this->token_service()->find_mailbox_by_token( $mailbox_id, $token );
		if ( ! $mailbox ) {
			return new WP_Error( 'invalid_token', __( 'Invalid webhook token.', 'doublescale' ), array( 'status' => 401 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || array() === $params ) {
			$params = $request->get_params();
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		unset( $params['mailbox_id'], $params['token'] );

		$result = $this->webhook_service()->ingest( $mailbox, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = 'replied' === $result['action'] ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_config( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$mailbox = $this->resolve_mailbox( (int) $request->get_param( 'mailbox_id' ) );
		if ( is_wp_error( $mailbox ) ) {
			return $mailbox;
		}

		return new WP_REST_Response(
			array( 'data' => $this->webhook_service()->get_admin_config( $mailbox ) ),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$mailbox_id = isset( $params['mailbox_id'] ) ? (int) $params['mailbox_id'] : 0;
		$mailbox    = $this->resolve_mailbox( $mailbox_id );
		if ( is_wp_error( $mailbox ) ) {
			return $mailbox;
		}

		return new WP_REST_Response(
			array( 'data' => $this->webhook_service()->regenerate_config( $mailbox ) ),
			200
		);
	}

	/**
	 * @param int $mailbox_id Mailbox id.
	 * @return MailboxModel|WP_Error
	 */
	private function resolve_mailbox( int $mailbox_id ) {
		if ( $mailbox_id <= 0 ) {
			return new WP_Error( 'missing_mailbox', __( 'Mailbox is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$mailbox = MailboxModel::find( $mailbox_id );
		if ( ! $mailbox ) {
			return new WP_Error( 'not_found', __( 'Mailbox not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $mailbox;
	}

	/**
	 * @param WP_REST_Request $request Unused.
	 * @return bool|WP_Error
	 */
	public function settings_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( Permissions::can_access_support_settings() ) {
			return true;
		}
		return new WP_Error( 'not_allowed', __( 'You do not have permission to manage support settings.', 'doublescale' ), array( 'status' => 403 ) );
	}

	/**
	 * @return bool
	 */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP used only for rate-limit key.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'ds_support_webhook_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count > 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * @return WebhookTokenService
	 */
	private function token_service(): WebhookTokenService {
		return $this->resolve_service( WebhookTokenService::class );
	}

	/**
	 * @return IncomingWebhookService
	 */
	private function webhook_service(): IncomingWebhookService {
		return $this->resolve_service( IncomingWebhookService::class );
	}

	/**
	 * @param class-string $class Service class.
	 * @return object
	 */
	private function resolve_service( string $class ) {
		if ( function_exists( 'doublescale_resolve' ) ) {
			$service = doublescale_resolve( $class );
			if ( is_object( $service ) ) {
				return $service;
			}
		}

		if ( IncomingWebhookService::class === $class ) {
			return new IncomingWebhookService( new WebhookTokenService() );
		}

		return new WebhookTokenService();
	}
}
