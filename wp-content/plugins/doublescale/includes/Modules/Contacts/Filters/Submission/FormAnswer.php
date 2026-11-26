<?php
/**
 * Contact filter: "Form answer" — contacts whose submission of a DoubleScale form answered a
 * field a certain way (Budget is "Over $10k", Company size is greater than 50…).
 *
 * Used by segments, automation conditions and (Pro) lead scoring rules.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Filters\Submission;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Native\Schema;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * Form answer filter.
 */
class FormAnswer extends Filter {

	public $name = 'Form Answer';

	public $slug = 'form_answer';

	public $group = 'submission';

	public $type = 'form_answer';

	public $is_automation = false;

	/**
	 * DoubleScale forms with their answerable fields.
	 *
	 * @return array
	 */
	public function get_options() {
		$this->name = __( 'Form Answer', 'doublescale' );
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return array();
		}
		$options = array();
		foreach ( FormModel::where( 'form_type', FormModel::NATIVE_TYPE )->orderBy( 'name' )->get() as $form ) {
			$fields = array();
			foreach ( Schema::input_fields( Schema::normalize_schema( $form->form_schema ?: array() ) ) as $field ) {
				if ( in_array( $field['type'], array( 'file', 'name', 'address' ), true ) ) {
					continue;
				}
				$fields[] = array(
					'key'     => $field['key'],
					'label'   => '' !== $field['label'] ? $field['label'] : $field['key'],
					'type'    => $field['type'],
					'options' => $field['options'],
				);
			}
			$options[] = array(
				'value'  => (string) $form->id,
				'label'  => (string) $form->name,
				'fields' => $fields,
			);
		}
		return $options;
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
	 * Contacts with at least one complete submission of the form whose answer matches.
	 *
	 * @param Builder $query  Query.
	 * @param array   $filter { value: { form_id, field, op, value } }.
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		global $wpdb;
		$value   = (array) ( $filter['value'] ?? array() );
		$form_id = (int) ( $value['form_id'] ?? 0 );
		$key     = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $value['field'] ?? '' ) ) );
		$op      = (string) ( $value['op'] ?? 'is' );
		$target  = trim( (string) ( $value['value'] ?? '' ) );

		if ( $form_id <= 0 || '' === $key || ! doublescale_table_exists( 'form_submissions' ) ) {
			return $query->whereRaw( '1 = 0' );
		}

		$subs     = $wpdb->prefix . 'doublescale_form_submissions';
		$contacts = $wpdb->prefix . 'doublescale_contacts';
		$path     = '$.' . $key;
		$scalar   = "JSON_UNQUOTE(JSON_EXTRACT({$subs}.answers, '{$path}'))";
		$raw      = "JSON_EXTRACT({$subs}.answers, '{$path}')";

		switch ( $op ) {
			case 'empty':
				$condition = "({$raw} IS NULL OR {$scalar} = '')";
				$bindings  = array();
				break;
			case 'not_empty':
				$condition = "({$raw} IS NOT NULL AND {$scalar} <> '')";
				$bindings  = array();
				break;
			case 'contains':
				$condition = "LOWER({$scalar}) LIKE ?";
				$bindings  = array( '%' . strtolower( $wpdb->esc_like( $target ) ) . '%' );
				break;
			case 'gt':
				$condition = "CAST({$scalar} AS DECIMAL(20,4)) > ?";
				$bindings  = array( (float) $target );
				break;
			case 'lt':
				$condition = "CAST({$scalar} AS DECIMAL(20,4)) < ?";
				$bindings  = array( (float) $target );
				break;
			case 'is_not':
				$condition = "NOT ( LOWER({$scalar}) = ? OR JSON_CONTAINS({$raw}, JSON_QUOTE(?)) )";
				$bindings  = array( strtolower( $target ), $target );
				break;
			case 'is':
			default:
				// A single answer equals it, or a multiple-choice answer includes it.
				$condition = "( LOWER({$scalar}) = ? OR ( JSON_TYPE({$raw}) = 'ARRAY' AND JSON_CONTAINS({$raw}, JSON_QUOTE(?)) ) )";
				$bindings  = array( strtolower( $target ), $target );
				break;
		}

		// Table names and the operator's SQL come from the code above; every value is a binding.
		$sql = 'EXISTS ( SELECT 1 FROM ' . $subs . ' WHERE ' . $subs . '.contact_id = ' . $contacts . '.id AND ' . $subs . '.form_id = ? AND ' . $subs . ".status = 'complete' AND " . $condition . ' )';

		return $query->whereRaw( $sql, array_merge( array( $form_id ), $bindings ) );
	}
}
