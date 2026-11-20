<?php
/**
 * Tickets from the help center by visitors with no account ({@see PublicTickets}):
 *
 *   POST /support/public/tickets      The help center's form (anyone; the
 *                                     admin's setting, a trap field and limits
 *                                     guard it).
 *   GET  /support/public-tickets      Helpdesk → Settings → Help center tickets.
 *   POST /support/public-tickets      Save it (support settings managers).
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Services\PublicTickets;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPublicTicketController class.
 */
class RestPublicTicketController extends RestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/support/public/tickets',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					// Visitors with no account: the setting, the trap field and
					// the limits in PublicTickets::submit() guard it.
					'permission_callback' => '__return_true',
					'args'                => array(
						'name'                       => array( 'type' => 'string' ),
						'email'                      => array( 'type' => 'string' ),
						'title'                      => array( 'type' => 'string' ),
						'content'                    => array( 'type' => 'string' ),
						PublicTickets::TRAP_FIELD    => array( 'type' => 'string' ),
						PublicTickets::ELAPSED_FIELD => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/public-tickets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
					'args'                => array(
						'who'     => array(
							'type' => 'string',
							'enum' => array( PublicTickets::ANYONE, PublicTickets::SIGNED_IN ),
						),
						'confirm' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * Whoever may change the helpdesk's settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function settings_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		return (bool) Permissions::can_access_support_settings();
	}

	/**
	 * POST /support/public/tickets
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$result = PublicTickets::submit(
			array(
				'name'                       => $request->get_param( 'name' ),
				'email'                      => $request->get_param( 'email' ),
				'title'                      => $request->get_param( 'title' ),
				'content'                    => $request->get_param( 'content' ),
				PublicTickets::TRAP_FIELD    => $request->get_param( PublicTickets::TRAP_FIELD ),
				PublicTickets::ELAPSED_FIELD => $request->get_param( PublicTickets::ELAPSED_FIELD ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 'created' === $result['status'] ? 201 : 202 );
	}

	/**
	 * GET /support/public-tickets
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- WP REST callback signature.
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		return new WP_REST_Response( self::shape(), 200 );
	}

	/**
	 * POST /support/public-tickets
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$input = array();
		foreach ( array( 'who', 'confirm' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		PublicTickets::update( $input );

		return new WP_REST_Response( self::shape(), 200 );
	}

	/**
	 * The settings, and whether the help center is there to show the form.
	 *
	 * @return array<string, mixed>
	 */
	private static function shape(): array {
		$class = '\\DoubleScale\\Modules\\KnowledgeBase\\Services\\KbPublicArticles';
		$page  = class_exists( $class ) && function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'knowledge_base' ) ? (string) $class::page_url() : '';

		return PublicTickets::settings() + array( 'help_center_url' => $page );
	}
}
