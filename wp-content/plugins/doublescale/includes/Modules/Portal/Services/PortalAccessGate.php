<?php
/**
 * Applies a customer's Client Portal access to everything the portal serves.
 *
 * Hiding an area must remove it from every surface, not just the menu: the
 * dashboard cards, recent activity, the calendar, the Documents list, the
 * project page and each area's own routes. All of it funnels through here so
 * the decision cannot drift between surfaces, and so the items Pro and add-ons
 * contribute are filtered by free itself — an older Pro that predates portal
 * access is still held to it.
 *
 * An item no area owns is left alone (see {@see PortalFeatures}).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PortalAccessGate.
 */
final class PortalAccessGate {

	/** Every customer portal route starts with this. */
	private const PORTAL_ROUTE_PREFIX = '/doublescale/v1/portal/';

	/**
	 * Calendar kinds that never reach a client, whatever the settings say.
	 * Deals are staff-only; a Pro from before they left the portal still
	 * pushes their close dates.
	 */
	private const STAFF_ONLY_CALENDAR_KINDS = array( 'deal' );

	/** The project routes Pro serves; their money and documents are trimmed here. */
	private const PROJECT_ROUTE = '#^/doublescale/v1/portal/projects(?:/\d+)?$#';

	/**
	 * Wire the route guard and the project trimming.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard_route' ), 10, 3 );
		// After the callback, not `rest_post_dispatch`: that one only runs for
		// HTTP requests, and an internal rest_do_request() must be held to the
		// same decision.
		add_filter( 'rest_request_after_callbacks', array( self::class, 'trim_project_response' ), 10, 3 );
	}

	/**
	 * Whether a value in one dimension is visible: unowned, or owned by at
	 * least one visible area.
	 *
	 * @param string              $dimension One of {@see PortalFeatures::DIMENSIONS}.
	 * @param string              $value     Section slug, card key, kind, type…
	 * @param array<string, bool> $access    Feature key => visible.
	 * @param array|null          $index     Ownership index (computed when null).
	 * @return bool
	 */
	public static function visible( string $dimension, string $value, array $access, ?array $index = null ): bool {
		$index  = $index ?? PortalFeatures::index();
		$owners = $index[ $dimension ][ $value ] ?? null;
		if ( null === $owners ) {
			return true;
		}

		foreach ( $owners as $key ) {
			if ( ! empty( $access[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $cards   Dashboard cards.
	 * @param ContactModel|null                $contact Contact.
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards( array $cards, ?ContactModel $contact ): array {
		return self::keep( $cards, $contact, static fn( array $card ): array => array( 'cards', (string) ( $card['key'] ?? '' ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items   Recent activity items.
	 * @param ContactModel|null                $contact Contact.
	 * @return array<int, array<string, mixed>>
	 */
	public static function timeline( array $items, ?ContactModel $contact ): array {
		return self::keep(
			$items,
			$contact,
			static function ( array $item ): array {
				$kind = (string) ( $item['kind'] ?? '' );
				if ( 'activity' === $kind ) {
					return array( 'activity_types', (string) ( $item['type'] ?? '' ) );
				}
				if ( 'document' === $kind ) {
					return array( 'document_types', (string) ( $item['document_type'] ?? '' ) );
				}
				return array( 'timeline_kinds', $kind );
			}
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $events  Calendar events.
	 * @param ContactModel|null                $contact Contact.
	 * @return array<int, array<string, mixed>>
	 */
	public static function calendar( array $events, ?ContactModel $contact ): array {
		$events = array_values(
			array_filter(
				$events,
				static fn( array $event ): bool => ! in_array( (string) ( $event['kind'] ?? '' ), self::STAFF_ONLY_CALENDAR_KINDS, true )
			)
		);
		return self::keep( $events, $contact, static fn( array $event ): array => array( 'calendar_kinds', (string) ( $event['kind'] ?? '' ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows    Documents rows.
	 * @param ContactModel|null                $contact Contact.
	 * @return array<int, array<string, mixed>>
	 */
	public static function document_rows( array $rows, ?ContactModel $contact ): array {
		return self::keep( $rows, $contact, static fn( array $row ): array => array( 'document_types', (string) ( $row['type'] ?? '' ) ) );
	}

	/**
	 * Refuse a route that belongs to a hidden area, before its own callback.
	 *
	 * Runs only for a request the portal would serve: logged out and staff are
	 * left to the route's own permission check, so they keep their 401/403.
	 *
	 * @param mixed           $response Response so far (null to continue).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_route( $response, $handler, $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = self::normalise_route( $request );
		if ( 0 !== strpos( $route, self::PORTAL_ROUTE_PREFIX ) ) {
			return $response;
		}

		$owners = self::route_owners( $route );
		if ( null === $owners || true !== PortalIdentity::permission_check() ) {
			return $response;
		}

		$access = PortalAccess::for_contact( PortalIdentity::current_contact() );
		foreach ( $owners as $key ) {
			if ( ! empty( $access[ $key ] ) ) {
				return $response;
			}
		}

		return new WP_Error(
			'portal_feature_unavailable',
			__( 'This part of the portal is not available.', 'doublescale' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Take what a hidden area owns out of Pro's project responses: the budget
	 * and money summary (financial summaries), and the linked documents by type
	 * along with their events in the project's history.
	 *
	 * @param mixed           $result  Callback result (response, array or error).
	 * @param array           $handler Route handler.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function trim_project_response( $result, $handler, $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- filter signature.
		if ( ! $request instanceof WP_REST_Request || ! preg_match( self::PROJECT_ROUTE, self::normalise_route( $request ) ) ) {
			return $result;
		}
		if ( is_wp_error( $result ) || ( $result instanceof WP_REST_Response && $result->is_error() ) ) {
			return $result;
		}

		$is_response = $result instanceof WP_REST_Response;
		$data        = $is_response ? $result->get_data() : $result;
		if ( ! is_array( $data ) ) {
			return $result;
		}

		$access = PortalAccess::for_contact( PortalIdentity::current_contact() );
		$index  = PortalFeatures::index();
		$trim   = static function ( $project ) use ( $access, $index ) {
			if ( ! is_array( $project ) ) {
				return $project;
			}
			if ( empty( $access['financial_summaries'] ) ) {
				foreach ( array( 'budget', 'financials' ) as $field ) {
					if ( array_key_exists( $field, $project ) ) {
						$project[ $field ] = null;
					}
				}
				// Said outright: with no summary the portal would show zeros.
				$project['money_hidden'] = true;
			}
			if ( isset( $project['documents'] ) && is_array( $project['documents'] ) ) {
				$project['documents'] = array_values(
					array_filter(
						$project['documents'],
						static fn( $doc ): bool => is_array( $doc ) && self::visible( 'document_types', (string) ( $doc['type'] ?? '' ), $access, $index )
					)
				);
			}
			// The project's history names those documents too.
			if ( isset( $project['activities'] ) && is_array( $project['activities'] ) ) {
				$project['activities'] = array_values(
					array_filter(
						$project['activities'],
						static fn( $item ): bool => is_array( $item ) && (
							empty( $item['document_type'] )
							|| self::visible( 'document_types', (string) $item['document_type'], $access, $index )
						)
					)
				);
			}
			return $project;
		};

		if ( isset( $data['data'] ) && is_array( $data['data'] ) && ! isset( $data['id'] ) ) {
			$data['data'] = array_map( $trim, $data['data'] );
		} else {
			$data = $trim( $data );
		}

		if ( ! $is_response ) {
			return $data;
		}

		$result->set_data( $data );

		return $result;
	}

	/**
	 * The request path as WordPress matches it: case-insensitively. Without
	 * this, `/PORTAL/Bookings` reaches the bookings handler and walks past a
	 * case-sensitive check. Area route patterns are matched against this.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function normalise_route( WP_REST_Request $request ): string {
		return strtolower( untrailingslashit( (string) $request->get_route() ) );
	}

	/**
	 * Areas that own a route, or null when no area does.
	 *
	 * @param string $route Request path.
	 * @return string[]|null
	 */
	private static function route_owners( string $route ): ?array {
		$owners = null;
		foreach ( PortalFeatures::index()['routes'] as $pattern => $keys ) {
			if ( @preg_match( $pattern, $route ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an add-on's malformed pattern must not break every request.
				$owners = array_merge( $owners ?? array(), $keys );
			}
		}

		if ( null === $owners ) {
			return null;
		}

		// A core area's route is decided by core areas alone: an add-on pattern
		// that also matches (written differently, so the claim check could not
		// see it) must not reopen a route its own area hides.
		$core = array_values( array_filter( $owners, array( PortalFeatures::class, 'is_core' ) ) );

		return array_values( array_unique( array() !== $core ? $core : $owners ) );
	}

	/**
	 * Keep the items whose owner is visible.
	 *
	 * @param array<int, mixed>  $items   Items.
	 * @param ContactModel|null  $contact Contact.
	 * @param callable           $locate  Item => [dimension, value].
	 * @return array<int, mixed>
	 */
	private static function keep( array $items, ?ContactModel $contact, callable $locate ): array {
		if ( array() === $items ) {
			return $items;
		}

		$access = PortalAccess::for_contact( $contact );
		$index  = PortalFeatures::index();

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $access, $index, $locate ): bool {
					if ( ! is_array( $item ) ) {
						return false;
					}
					list( $dimension, $value ) = $locate( $item );
					return self::visible( $dimension, $value, $access, $index );
				}
			)
		);
	}
}
