<?php
/**
 * What a portal client may see: their own records, plus records set to a
 * company they belong to (companies come with DoubleScale Pro). Only in areas their own portal access shows.
 *
 * Every portal list and single-record check goes through here instead of a
 * bare `contact_id = me`, so company sharing works the same in each section.
 *
 * @package DoubleScale\Modules\Portal\Services
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

/**
 * PortalScope class
 */
class PortalScope {

	/**
	 * Companies whose records in an area the contact may see.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $area       projects, tickets, files or invoices.
	 * @return int[]
	 */
	public static function company_ids( int $contact_id, string $area ): array {
		/**
		 * Filters the companies whose records a portal client sees in an area
		 * (DoubleScale Pro's companies; none without them).
		 *
		 * @param int[]  $ids        Company ids.
		 * @param int    $contact_id Contact ID.
		 * @param string $area       Area.
		 */
		return array_values( array_filter( array_map( 'intval', (array) apply_filters( 'doublescale_portal_scope_company_ids', array(), $contact_id, $area ) ) ) );
	}

	/**
	 * The company a client shares a new record with from the portal (a
	 * ticket, an upload): the one they picked when it is one of theirs in
	 * that area, else their first (main) company; null when they have none.
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $area       tickets or files.
	 * @param mixed  $requested  Company id they picked (0/empty: none picked).
	 * @return int|null
	 */
	public static function share_company( int $contact_id, string $area, $requested = 0 ): ?int {
		$companies = self::company_ids( $contact_id, $area );
		if ( empty( $companies ) ) {
			return null;
		}
		$requested = (int) $requested;
		if ( $requested > 0 ) {
			return in_array( $requested, $companies, true ) ? $requested : null;
		}
		return $companies[0];
	}

	/**
	 * Narrow a query to the contact's own rows and their companies' rows.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder|\DoubleScale\Vendor\Illuminate\Database\Query\Builder $query      Query.
	 * @param int                                                                                                            $contact_id Contact ID.
	 * @param string                                                                                                         $area       Area.
	 * @param string                                                                                                         $prefix     Column prefix for joined queries, e.g. "t.".
	 * @return mixed The query.
	 */
	public static function apply( $query, int $contact_id, string $area, string $prefix = '' ) {
		$companies = self::company_ids( $contact_id, $area );
		if ( empty( $companies ) ) {
			return $query->where( $prefix . 'contact_id', $contact_id );
		}

		return $query->where(
			static function ( $q ) use ( $contact_id, $companies, $prefix ) {
				$q->where( $prefix . 'contact_id', $contact_id )->orWhereIn( $prefix . 'company_id', $companies );
			}
		);
	}

	/**
	 * Whether a record (with contact_id and maybe company_id) is visible.
	 *
	 * @param object|array<string, mixed>|null $row        Record.
	 * @param int                              $contact_id Contact ID.
	 * @param string                           $area       Area.
	 */
	public static function owns( $row, int $contact_id, string $area ): bool {
		if ( ! $row || $contact_id <= 0 ) {
			return false;
		}
		$row_contact = (int) ( is_array( $row ) ? ( $row['contact_id'] ?? 0 ) : ( $row->contact_id ?? 0 ) );
		if ( $row_contact === $contact_id ) {
			return true;
		}
		$row_company = (int) ( is_array( $row ) ? ( $row['company_id'] ?? 0 ) : ( $row->company_id ?? 0 ) );
		return $row_company > 0 && in_array( $row_company, self::company_ids( $contact_id, $area ), true );
	}

	/**
	 * SQL fragment for raw $wpdb queries: "(contact_id = 5 OR company_id IN (2,3) OR contact_id IN (8,9))".
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $area       Area.
	 * @param string $prefix     Column prefix, e.g. "t.".
	 */
	public static function sql( int $contact_id, string $area, string $prefix = '' ): string {
		$sql       = $prefix . 'contact_id = ' . (int) $contact_id;
		$companies = self::company_ids( $contact_id, $area );
		if ( $companies ) {
			$sql = '(' . $sql . ' OR ' . $prefix . 'company_id IN (' . implode( ',', $companies ) . '))';
		}
		return $sql;
	}
}
