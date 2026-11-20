<?php
/**
 * The client portal's bell and the client's email choices.
 *
 *   GET  /doublescale/v1/portal/notifications                    (newest first + unread count)
 *   POST /doublescale/v1/portal/notifications/read               ({ids} | {route} | {all})
 *   GET  /doublescale/v1/portal/notifications/email-preferences
 *   POST /doublescale/v1/portal/notifications/email-preferences  ({team_updates: every|daily|none})
 *
 * Always the signed-in client's own rows ({@see PortalNotifications}).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalEmailPreferences;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalNotifications;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalNotificationsController.
 */
class RestPortalNotificationsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'portal/notifications';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/read',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_read' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/email-preferences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_preferences' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_preferences' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response(
				array(
					'data'   => array(),
					'unread' => 0,
				),
				200
			);
		}
		return new WP_REST_Response( PortalNotifications::list_for( $contact ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function mark_read( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( $contact ) {
			PortalNotifications::mark_read(
				$contact,
				array(
					'ids'   => (array) $request->get_param( 'ids' ),
					'route' => (string) $request->get_param( 'route' ),
					'all'   => rest_sanitize_boolean( $request->get_param( 'all' ) ),
				)
			);
		}
		return $this->get_items( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preferences( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return PortalIdentity::not_found( __( 'Contact not found.', 'doublescale' ) );
		}
		return new WP_REST_Response( PortalEmailPreferences::for_contact( (int) $contact->id ), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_preferences( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return PortalIdentity::not_found( __( 'Contact not found.', 'doublescale' ) );
		}
		$input = (array) $request->get_json_params();
		$saved = PortalEmailPreferences::save( (int) $contact->id, $input );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}
		return new WP_REST_Response( $saved, 200 );
	}
}
