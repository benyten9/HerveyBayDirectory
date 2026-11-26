<?php

/**
 * Class Form Submission
 *
 * This class is responsible for handling the form submission filter
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Submission;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Filters\Traits\TimeframeContactFilter;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * FormSubmission class
 */
class FormSubmission extends Filter {

	use TimeframeContactFilter;

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Form Submission';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'form_submission';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'submission';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'form_submission';

	/**
	 * Is automation rule
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $is_automation = false;

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		$options = array();

		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return $options;
		}

		// Free's model: the Pro class this used to name no longer exists, so the list was always empty.
		$forms = \DoubleScale\Modules\Forms\Models\FormModel::orderBy( 'name' )->get( array( 'id', 'name' ) );

		foreach ( $forms as $form ) {
			$options[ $form->id ] = $form->name;
		}

		return $options;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array();
	}


	/**
	 * Apply filter
	 *
	 * @since 1.0.0
	 *
	 * @param Builder $query Query.
	 * @param array   $filter Filter.
	 *
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		global $wpdb;

		$value = $filter['value'] ?? array();

		$timeframe_data = $value['timeframe'] ?? array( 'type' => 'at_any_time' );

		// Extract form IDs to filter by
		$form_ids = $value['form_ids'] ?? array();

		$table_form_submissions = $wpdb->prefix . 'doublescale_form_submissions';
		$table_contacts         = $wpdb->prefix . 'doublescale_contacts';

		$sql_timeframe = $this->build_timeframe_sql( $timeframe_data );
		$time_bindings = $this->get_timeframe_bindings( $timeframe_data );

		// Build form IDs filter SQL
		$form_ids_sql      = '';
		$form_ids_bindings = array();
		if ( ! empty( $form_ids ) ) {
			$placeholders      = implode( ', ', array_fill( 0, count( $form_ids ), '?' ) );
			$form_ids_sql      = " AND {$table_form_submissions}.form_id IN ({$placeholders})";
			$form_ids_bindings = array_map( 'intval', $form_ids );
		}

		$query->whereRaw(
			"(
			SELECT COUNT(*)
			FROM {$table_form_submissions}
			WHERE {$table_form_submissions}.contact_id = {$table_contacts}.id
			AND ( {$table_form_submissions}.status IS NULL OR {$table_form_submissions}.status = 'complete' )
			{$form_ids_sql}
			{$sql_timeframe}
		) > ?",
			array_merge(
				$form_ids_bindings,
				$time_bindings,
				// "Submitted at least N times" (default once): spam and unfinished entries never count.
				array( max( 0, (int) ( $value['min_count'] ?? 1 ) - 1 ) )
			)
		);

		return $query;
	}
}
