<?php
/**
 * Contact filter: "Lead source" — where the contact first (or last) came from:
 * the UTM source, medium or campaign, or the referring site when there were no UTMs.
 *
 * Written by DoubleScale forms (first_touch_* / latest_touch_* contact meta).
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Filters\Submission;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * Lead source filter.
 */
class LeadSource extends Filter {

	public $name = 'Lead Source';

	public $slug = 'lead_source';

	public $group = 'submission';

	public $type = 'lead_source';

	public $is_automation = false;

	/**
	 * Known values for each part, for suggestions.
	 *
	 * @return array
	 */
	public function get_options() {
		$this->name = __( 'Lead Source', 'doublescale' );
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_contact_meta';
		if ( ! doublescale_table_exists( 'contact_meta' ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'source', 'medium', 'campaign' ) as $part ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Suggestions from our own table.
			$out[ $part ] = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$table} WHERE meta_key IN (%s, %s) ORDER BY meta_value LIMIT 100", 'first_touch_' . $part, 'latest_touch_' . $part ) );
		}
		return $out;
	}

	/**
	 * Operators (the UI shows its own list).
	 *
	 * @return array
	 */
	public function get_operators() {
		return array();
	}

	/**
	 * Contacts whose first or latest touch matches.
	 *
	 * @param Builder $query  Query.
	 * @param array   $filter { value: { touch: first|latest, part: source|medium|campaign, op: is|is_not|contains, value } }.
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		global $wpdb;
		$value  = (array) ( $filter['value'] ?? array() );
		$touch  = 'latest' === ( $value['touch'] ?? 'first' ) ? 'latest_touch' : 'first_touch';
		$part   = in_array( $value['part'] ?? 'source', array( 'source', 'medium', 'campaign' ), true ) ? $value['part'] : 'source';
		$op     = (string) ( $value['op'] ?? 'is' );
		$target = strtolower( trim( (string) ( $value['value'] ?? '' ) ) );
		$meta   = $wpdb->prefix . 'doublescale_contact_meta';
		$table  = $wpdb->prefix . 'doublescale_contacts';
		$key    = $touch . '_' . $part;

		if ( 'contains' === $op ) {
			$condition = 'meta_value LIKE ?';
			$binding   = '%' . $wpdb->esc_like( $target ) . '%';
		} else {
			$condition = 'meta_value = ?';
			$binding   = $target;
		}

		// Table names come from $wpdb; the key and the value are bindings.
		$exists = 'EXISTS ( SELECT 1 FROM ' . $meta . ' WHERE ' . $meta . '.contact_id = ' . $table . '.id AND meta_key = ? AND ' . $condition . ' )';
		return 'is_not' === $op
			? $query->whereRaw( 'NOT ' . $exists, array( $key, $binding ) )
			: $query->whereRaw( $exists, array( $key, $binding ) );
	}
}
