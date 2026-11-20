<?php
/**
 * GET /doublescale/v1/portal/bootstrap
 *
 * One-shot payload the renderer needs to draw the shell: the read-only identity
 * header, the visible section list (with badge counts), and the dashboard
 * summary cards. Identity is resolved by lowercased email; a logged-in user with
 * no contact still gets a valid (empty-ish) payload so the UI renders an empty
 * state rather than erroring.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Portal\Services\PortalAccessGate;
use DoubleScale\Modules\Portal\Services\PortalEmailConfirmation;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalWelcome;
use DoubleScale\Modules\Portal\Services\PortalRequestScope;
use DoubleScale\Modules\Portal\Services\PortalSections;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalBootstrapController.
 */
class RestPortalBootstrapController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bootstrap',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bootstrap' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);

		// "Confirm your email": the link that proves the login owns its address.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/confirm-email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_confirmation' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
				),
			)
		);
	}

	/**
	 * Email the signed-in login its confirmation link.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public function send_confirmation() {
		$user = wp_get_current_user();
		if ( PortalEmailConfirmation::is_confirmed( $user ) ) {
			return new WP_REST_Response( array( 'confirmed' => true ), 200 );
		}
		$sent = PortalEmailConfirmation::send( $user );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return new WP_REST_Response(
			array(
				'sent'  => true,
				'email' => sanitize_email( (string) $user->user_email ),
			),
			200
		);
	}

	/**
	 * Build the bootstrap payload.
	 *
	 * A page locked to one support department (`box_id`) counts that
	 * department's tickets, as its tickets list shows.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response
	 */
	public function get_bootstrap( $request = null ) {
		$mailbox_id = $request instanceof WP_REST_Request ? absint( $request->get_param( 'box_id' ) ) : 0;

		return PortalRequestScope::with_mailbox(
			$mailbox_id,
			function () {
				return $this->build_bootstrap();
			}
		);
	}

	/**
	 * The bootstrap payload for the current user.
	 *
	 * @return WP_REST_Response
	 */
	private function build_bootstrap() {
		$user    = wp_get_current_user();
		$contact = PortalIdentity::current_contact();

		$name = '';
		if ( $contact ) {
			$name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->display_name;
		}

		$identity = array(
			'name'        => $name,
			'email'       => sanitize_email( (string) $user->user_email ),
			'avatar'      => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) ),
			'has_contact' => (bool) $contact,
			// A login that has not proved it owns its email sees no client's
			// data: the portal asks it to confirm first.
			'email_unconfirmed' => ! PortalEmailConfirmation::is_confirmed( $user ),
			'welcome'     => PortalWelcome::for_user( $user, $contact ),
		);

		/**
		 * Filter the portal dashboard summary cards.
		 *
		 * Each card: array{ key:string, label:string, value:string|int, route?:string }.
		 *
		 * @param array<int, array<string, mixed>>                 $cards   Summary cards.
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel|null $contact Resolved contact.
		 */
		$cards = (array) apply_filters( 'doublescale_portal_summary_cards', array(), $contact );

		return new WP_REST_Response(
			array(
				'identity' => $identity,
				'sections' => PortalSections::for_contact( $contact ),
				'summary'  => array(
					'cards' => PortalAccessGate::cards( array_values( array_filter( $cards, 'is_array' ) ), $contact ),
				),
			),
			200
		);
	}
}
