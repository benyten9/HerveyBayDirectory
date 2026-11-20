<?php
/**
 * Contact access settings: what agents see, and who owns a new contact
 * from each source (lead routing).
 *
 * @package DoubleScale\Modules\Contacts\Rest\Controllers
 */

namespace DoubleScale\Modules\Contacts\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Services\LeadRouter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Managers decide the routing; agents never see or change it.
 */
class RestLeadRoutingController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'lead-routing';

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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return Permissions::can_assign_contact_owner();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		return new WP_REST_Response( self::payload(), 200 );
	}

	/**
	 * Routing rules plus the contact visibility mode they work with.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(): array {
		return LeadRouter::settings() + array(
			'contact_visibility' => Permissions::contact_visibility(),
		);
	}

	/**
	 * Replace the routing rules. Unknown keys are dropped; every user id must
	 * be someone who can own contacts, or the rule is dropped too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		$current = LeadRouter::settings();
		$params  = (array) $request->get_json_params();

		$rules = array(
			'default_owner_id'  => self::owner_or_zero( $params['default_owner_id'] ?? $current['default_owner_id'] ),
			'round_robin'       => array(
				'enabled'   => ! empty( $params['round_robin']['enabled'] ?? $current['round_robin']['enabled'] ),
				'agent_ids' => array_values(
					array_filter(
						array_map( 'intval', (array) ( $params['round_robin']['agent_ids'] ?? $current['round_robin']['agent_ids'] ) ),
						array( LeadRouter::class, 'is_valid_owner' )
					)
				),
				'cursor'    => (int) $current['round_robin']['cursor'],
			),
			'booking_calendars' => self::pick_map( $params['booking_calendars'] ?? $current['booking_calendars'], array( LeadRouter::PICK_DEFAULT, LeadRouter::PICK_ROUND_ROBIN, LeadRouter::PICK_HOST ) ),
			'inbound'           => in_array( $params['inbound'] ?? $current['inbound'], array( 'mailbox_owner', LeadRouter::PICK_DEFAULT ), true )
				? (string) ( $params['inbound'] ?? $current['inbound'] )
				: 'mailbox_owner',
			'integrations'      => in_array( $params['integrations'] ?? $current['integrations'], array( LeadRouter::PICK_DEFAULT, LeadRouter::PICK_ROUND_ROBIN ), true )
				? (string) ( $params['integrations'] ?? $current['integrations'] )
				: LeadRouter::PICK_DEFAULT,
			'automations'       => in_array( $params['automations'] ?? $current['automations'], array( LeadRouter::PICK_DEFAULT, LeadRouter::PICK_ROUND_ROBIN, LeadRouter::PICK_WORKFLOW_OWNER ), true )
				? (string) ( $params['automations'] ?? $current['automations'] )
				: LeadRouter::PICK_DEFAULT,
		);

		unset( $rules['round_robin']['cursor'] ); // Kept in its own option.
		update_option( LeadRouter::OPTION, $rules, true );

		// What agents see: 'all' contacts, or 'own' (own + shared + unassigned).
		if ( isset( $params['contact_visibility'] ) ) {
			Permissions::set_contact_visibility( (string) $params['contact_visibility'] );
		}

		return new WP_REST_Response( self::payload(), 200 );
	}

	/**
	 * @param mixed $value User id.
	 * @return int A valid owner, or 0.
	 */
	private static function owner_or_zero( $value ): int {
		$user_id = (int) $value;

		return ( $user_id > 0 && LeadRouter::is_valid_owner( $user_id ) ) ? $user_id : 0;
	}

	/**
	 * Sanitize an id → pick map (pick = user id or a keyword).
	 *
	 * @param mixed    $map      Raw map.
	 * @param string[] $keywords Allowed keyword picks.
	 * @return array<string, int|string>
	 */
	private static function pick_map( $map, array $keywords ): array {
		$clean = array();

		foreach ( (array) $map as $id => $pick ) {
			$id = (string) absint( $id );
			if ( '0' === $id ) {
				continue;
			}

			if ( is_string( $pick ) && in_array( $pick, $keywords, true ) ) {
				$clean[ $id ] = $pick;
				continue;
			}

			$user_id = (int) $pick;
			if ( $user_id > 0 && LeadRouter::is_valid_owner( $user_id ) ) {
				$clean[ $id ] = $user_id;
			}
		}

		return $clean;
	}
}
