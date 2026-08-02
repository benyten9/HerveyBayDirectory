<?php
/**
 * GET /doublescale/v1/portal/timeline
 *
 * Whitelisted activity feed for the current contact, paginated. Two sources are
 * merged (see the audit note on PortalActivityWhitelist):
 *
 *   1. `doublescale_activities` rows filtered to the customer-safe whitelist
 *      (currently `support_reply` only) and shaped to drop agent identity.
 *   2. Module-contributed items via the `doublescale_portal_timeline_items`
 *      filter — Booking projects its lifecycle straight from BookingModel here,
 *      because the `booking_*` activity types are never written to the table.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Portal\Services\PortalActivityWhitelist;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestPortalTimelineController.
 */
class RestPortalTimelineController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'portal';

	/**
	 * Hard cap on rows fetched from the activities table before merge/paging.
	 */
	private const MAX_ACTIVITY_ROWS = 200;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/timeline',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_timeline' ),
					'permission_callback' => array( PortalIdentity::class, 'permission_check' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
			)
		);
	}

	/**
	 * Build the merged, paginated timeline.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_timeline( WP_REST_Request $request ) {
		$contact = PortalIdentity::current_contact();
		if ( ! $contact ) {
			return new WP_REST_Response(
				array(
					'data'     => array(),
					'page'     => 1,
					'per_page' => (int) $request->get_param( 'per_page' ),
					'total'    => 0,
				),
				200
			);
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$items = $this->collect_activity_items( (int) $contact->id, self::resolve_contact_user_id( $contact ) );

		/**
		 * Filter the merged portal timeline items before sort/paging.
		 *
		 * Booking (and other modules) push their own shaped items here:
		 * array{ id, kind, type, date(Y-m-d H:i:s|null), title, ... }.
		 *
		 * @param array<int, array<string, mixed>>                       $items   Timeline items.
		 * @param \DoubleScale\Modules\Contacts\Models\ContactModel       $contact Resolved contact.
		 */
		$items = (array) apply_filters( 'doublescale_portal_timeline_items', $items, $contact );
		$items = array_values( array_filter( $items, 'is_array' ) );

		usort(
			$items,
			static function ( $a, $b ) {
				$da = isset( $a['date'] ) ? (string) $a['date'] : '';
				$db = isset( $b['date'] ) ? (string) $b['date'] : '';
				return strcmp( $db, $da );
			}
		);

		$total  = count( $items );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $items, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'data'     => $paged,
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
			),
			200
		);
	}

	/**
	 * Fetch + shape the whitelisted activity rows for a contact.
	 *
	 * @param int      $contact_id      Contact id.
	 * @param int|null $contact_user_id The contact's own WP account id (for self-author detection).
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_activity_items( int $contact_id, ?int $contact_user_id ): array {
		$rows = ActivityModel::query()
			->whereHas(
				'associations',
				function ( $q ) use ( $contact_id ) {
					$q->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
						->where( 'entity_id', $contact_id );
				}
			)
			->whereIn( 'activity_type', PortalActivityWhitelist::allowed_types() )
			->orderBy( 'id', 'desc' )
			->limit( self::MAX_ACTIVITY_ROWS )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$shaped = PortalActivityWhitelist::shape( $row, $contact_user_id );
			if ( null !== $shaped ) {
				$items[] = $shaped;
			}
		}

		return $items;
	}

	/**
	 * Resolve the contact's own WordPress account id (matched by email).
	 *
	 * The portal is login-only, so the viewing user IS this contact — their
	 * own messages (replies written NULL, and the opening message credited to
	 * their WP id) must read as "self" in the timeline.
	 *
	 * @param \DoubleScale\Modules\Contacts\Models\ContactModel $contact Resolved contact.
	 * @return int|null
	 */
	private static function resolve_contact_user_id( $contact ): ?int {
		$email = strtolower( trim( (string) $contact->email ) );
		if ( '' === $email ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : null;
	}
}
