<?php

/**
 * WPForms Forms Form Utils
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Wpforms;

defined( 'ABSPATH' ) || exit;

/**
 * FormUtils class.
 */
class FormUtils {



	/**
	 * Prepare name field.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $field Name field.
	 * @param string $field_id Field ID.
	 *
	 * @return array
	 */
	public static function prepare_name_field( $field, $field_id ) {
		$label  = $field['label'] ?? $field['name'];
		$fields = array();

		$fields[ $field_id . '_first' ] = array(
			'label' => $label . ': First',
			'type'  => 'text',
		);
		$fields[ $field_id . '_last' ]  = array(
			'label' => $label . ': Last',
			'type'  => 'text',
		);

		$format = $field['format'] ?? 'first-last';
		if ( 'first-middle-last' === $format || ! empty( $field['middle'] ?? '' ) ) {
			$fields[ $field_id . '_middle' ] = array(
				'label' => $label . ': Middle',
				'type'  => 'text',
			);
		}

		return $fields;
	}

	/**
	 * Prepare address field.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $field Address field.
	 * @param string $field_id Field ID.
	 *
	 * @return array
	 */
	public static function prepare_address_field( $field, $field_id ) {
		$label  = $field['label'] ?? $field['name'];
		$fields = array();

		$fields[ $field_id . '_address1' ] = array(
			'label' => $label . ': Address 1',
			'type'  => 'text',
		);
		$fields[ $field_id . '_address2' ] = array(
			'label' => $label . ': Address 2',
			'type'  => 'text',
		);
		$fields[ $field_id . '_city' ]     = array(
			'label' => $label . ': City',
			'type'  => 'text',
		);
		$fields[ $field_id . '_state' ]    = array(
			'label' => $label . ': State',
			'type'  => 'text',
		);
		$fields[ $field_id . '_zip' ]      = array(
			'label' => $label . ': Zip',
			'type'  => 'text',
		);
		$fields[ $field_id . '_country' ]  = array(
			'label' => $label . ': Country',
			'type'  => 'text',
		);

		return $fields;
	}

	/**
	 * Get field id.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field_index Field index.
	 *
	 * @return string
	 */
	public static function get_field_id( $field_index ) {
		return 'wpforms_' . $field_index;
	}
}
