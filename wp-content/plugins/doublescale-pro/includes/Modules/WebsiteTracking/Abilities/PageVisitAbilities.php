<?php
/**
 * Read-only website tracking abilities.
 *
 * @package DoubleScale\Pro\Modules\WebsiteTracking
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel;

/**
 * "Which pages is this lead reading" — the buying-signal question.
 *
 * `ip_address` and `user_agent` are deliberately NOT returned by any ability
 * here. They are stored for de-duplication and abuse handling, not for
 * reporting, and an agent that can read them can also repeat them into a chat
 * log or an email. Nothing an agent legitimately answers needs a visitor's IP,
 * so the safe default is to never hand it over.
 *
 * Page visits are only recorded against an identified contact (contact_id is NOT
 * NULL on the table), so there is no anonymous traffic here and no session
 * stitching to reason about.
 */
final class PageVisitAbilities {

	/**
	 * Most visit rows one summary call will scan.
	 *
	 * Page visits are the highest-volume rows in the product, so the aggregate
	 * reads a bounded newest-first slice instead of the whole table.
	 */
	private const SCAN_CAP = 5000;

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'can_read_contacts' );

		return array(
			'doublescale/list-page-visits'   => array(
				'module_slug'      => 'websitetracking',
				'label'            => __( 'List page visits', 'doublescale' ),
				'description'      => __( 'Pages known contacts have viewed on the site, newest first, with the path and when it was viewed. Only identified contacts are tracked, so this is never anonymous traffic. Visitor IP addresses and browser strings are never returned.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only visits by this contact.',
						),
						'path'       => array(
							'type'        => 'string',
							'description' => 'Match on the page path, e.g. /pricing.',
						),
						'date_from'  => array(
							'type'        => 'string',
							'description' => 'Only visits on or after this date (YYYY-MM-DD).',
						),
						'date_to'    => array(
							'type'        => 'string',
							'description' => 'Only visits on or before this date (YYYY-MM-DD).',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_page_visits' ),
			),

			'doublescale/get-visit-summary' => array(
				'module_slug'      => 'websitetracking',
				'label'            => __( 'Get page visit summary', 'doublescale' ),
				'description'      => __( 'The most-viewed pages over a date range with view counts and how many distinct contacts viewed each. Pass contact_id to get one contact\'s own most-read pages instead — useful for "what is this lead interested in" before a call. Counts cover the newest 5000 visits in the range; when the truncated flag is true, treat them as a floor and narrow the date range for exact figures.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Limit to one contact. Omit for site-wide totals.',
						),
						'date_from'  => array(
							'type'        => 'string',
							'description' => 'Count visits on or after this date (YYYY-MM-DD).',
						),
						'date_to'    => array(
							'type'        => 'string',
							'description' => 'Count visits on or before this date (YYYY-MM-DD).',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'How many top pages to return.',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
						),
					),
				),
				'execute_callback' => array( self::class, 'get_visit_summary' ),
			),
		);
	}

	/**
	 * List page visits.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_page_visits( array $input ) {
		$invalid = self::validate_window( $input );
		if ( $invalid ) {
			return $invalid;
		}

		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = PageVisitModel::query();
		self::apply_filters( $query, $input );

		$total = (int) $query->count();

		$rows  = $query->orderBy( 'created_at', 'desc' )->limit( $limit )->offset( $offset )->get();
		$items = array();

		foreach ( $rows as $visit ) {
			$items[] = array(
				'id'         => (int) $visit->id,
				'contact_id' => (int) $visit->contact_id,
				'path'       => (string) $visit->path,
				// The query string can carry campaign parameters worth seeing,
				// but it is untrusted visitor input, so it is length-capped.
				'query'      => mb_substr( (string) ( $visit->query ?? '' ), 0, 500 ),
				'viewed_at'  => (string) $visit->created_at,
			);
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Most-viewed pages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_visit_summary( array $input ) {
		$invalid = self::validate_window( $input );
		if ( $invalid ) {
			return $invalid;
		}

		$limit = AbilityResult::limit( $input );

		$query = PageVisitModel::query();
		self::apply_filters( $query, $input );

		// Aggregated in PHP rather than SQL: the model's query builder has no
		// groupBy passthrough here, and the alternative is hand-written SQL for
		// a table that is already indexed on path.
		//
		// Hard-capped because page_visits is the fastest-growing table in the
		// product — one row per pageview per known contact. An uncapped
		// aggregate on a site with a year of traffic exhausts memory and takes
		// the whole request down, so the newest slice is scanned and the
		// response says plainly that it was truncated.
		$pages   = array();
		$viewers = array();
		$total   = 0;

		$scanned = $query->orderBy( 'created_at', 'desc' )->limit( self::SCAN_CAP )->get();

		foreach ( $scanned as $visit ) {
			$path = (string) $visit->path;
			if ( '' === $path ) {
				continue;
			}

			$pages[ $path ] = ( $pages[ $path ] ?? 0 ) + 1;
			++$total;

			// Distinct viewers per page — a single obsessive visitor and ten
			// separate leads are very different signals.
			$viewers[ $path ][ (int) $visit->contact_id ] = true;
		}

		arsort( $pages );

		$items = array();
		foreach ( array_slice( $pages, 0, $limit, true ) as $path => $views ) {
			$items[] = array(
				'path'     => $path,
				'views'    => (int) $views,
				'contacts' => count( $viewers[ $path ] ?? array() ),
			);
		}

		return array(
			'pages'       => $items,
			'total_views' => $total,
			'total_pages' => count( $pages ),
			// True means these counts cover only the newest SCAN_CAP visits, so
			// they are a floor rather than a total. Without this an agent
			// reports a capped aggregate as the definitive figure.
			'truncated'   => $total >= self::SCAN_CAP,
			'scanned'     => $total,
		);
	}

	/**
	 * Reject a malformed date window before it silently returns everything.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \WP_Error|null
	 */
	private static function validate_window( array $input ): ?\WP_Error {
		return AbilityInput::first_error(
			array(
				AbilityInput::date( $input['date_from'] ?? null, 'date_from' ),
				AbilityInput::date( $input['date_to'] ?? null, 'date_to' ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
			)
		);
	}

	/**
	 * Apply the shared contact/path/date filters.
	 *
	 * @since 1.0.0
	 *
	 * @param object               $query Query builder.
	 * @param array<string, mixed> $input Ability input.
	 * @return void
	 */
	private static function apply_filters( $query, array $input ): void {
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$path = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';
		if ( '' !== $path ) {
			$query->where( 'path', 'LIKE', '%' . $path . '%' );
		}

		if ( ! empty( $input['date_from'] ) ) {
			$query->where( 'created_at', '>=', (string) $input['date_from'] . ' 00:00:00' );
		}

		if ( ! empty( $input['date_to'] ) ) {
			$query->where( 'created_at', '<=', (string) $input['date_to'] . ' 23:59:59' );
		}
	}
}
