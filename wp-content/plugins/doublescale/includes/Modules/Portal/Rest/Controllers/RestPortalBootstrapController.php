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
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Modules\Portal\Services\PortalSections;
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
	}

	/**
	 * Build the bootstrap payload.
	 *
	 * @return WP_REST_Response
	 */
	public function get_bootstrap() {
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
					'cards' => array_values( array_filter( $cards, 'is_array' ) ),
				),
			),
			200
		);
	}
}
