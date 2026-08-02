<?php

/**
 * QuillForms Form Utils
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Quillforms;

defined( 'ABSPATH' ) || exit;

use QuillForms\Core;
use QuillForms\Entry;
use QuillForms\Managers\Blocks_Manager;

/**
 * FormUtils class.
 *
 * @since 1.0.0
 */
class FormUtils {




	/**
	 * Form id
	 *
	 * @since 1.0.0
	 *
	 * @var integer
	 */
	private $form_id;

	/**
	 * Form data
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $form_data;

	/**
	 * Form records info
	 * Includes fields, variables, has_calculator_actions & hidden_fields
	 * fields has only the known editable blocks
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $form_records_info;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param integer $form_id Form id.
	 * @param array   $form_data Form data.
	 */
	public function __construct( $form_id, $form_data = null ) {
		$this->form_id   = $form_id;
		$this->form_data = $form_data ?? Core::get_form_data( $this->form_id );

		$this->define_form_records_info();
	}

	/**
	 * Prepare entry
	 *
	 * @since 1.0.0
	 *
	 * @param Entry $entry Entry.
	 * @return array
	 */
	public function prepare_entry( $entry ) {
		$fields = array();
		foreach ( $this->form_records_info['fields'] as $field_id => $field ) {
			if ( isset( $entry->records['fields'][ $field_id ] ) ) {
				switch ( $field['name'] ) {
					case 'date':
						$field_raw_value     = $field['block_type']->get_readable_value( $entry->records['fields'][ $field_id ]['value'], $this->form_data, 'raw' );
						$fields[ $field_id ] = $field_raw_value;

						$field_utc_datetime                  = \DateTime::createFromFormat( 'Y-m-d', $field_raw_value, new \DateTimeZone( 'UTC' ) )->setTime( 0, 0, 0 );
						$fields[ "{$field_id}_utc_iso8601" ] = $field_utc_datetime->format( 'c' );
						$fields[ "{$field_id}_unix" ]        = $field_utc_datetime->getTimestamp();
						break;
					default:
						$fields[ $field_id ] = $field['block_type']->get_readable_value( $entry->records['fields'][ $field_id ]['value'], $this->form_data, 'plain' );
				}
			} else {
				$fields[ $field_id ] = null;
			}
		}

		return array(
			'fields' => $fields,
		);
	}

	/**
	 * Get fields.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		$fields = array();
		foreach ( $this->form_records_info['fields'] as $field_id => $field ) {
			$field_label = $field['label'];
			if ( empty( $field_label ) && null !== $field_label ) {
				$field_label = sprintf( /* translators: %d - field ID. */
					esc_html__( 'Field ID #%s', 'doublescale'),
					$field_id
				);
			}
			$fields[ $field_id ] = array(
				'label' => $field_label,
				'type'  => $field['name'],
			);
		}

		return $fields;
	}

	/**
	 * Define form records info
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function define_form_records_info() {
		$this->form_records_info = array(
			'fields' => array(),
		);

		$blocks = $this->form_data['blocks'];
		if ( method_exists( Core::class, 'get_blocks_recursively' ) ) {
			$blocks = Core::get_blocks_recursively( $blocks );
		}

		foreach ( $blocks as $block_data ) {
			$block_type = Blocks_Manager::instance()->create( $block_data );
			if ( $block_type === false || ! $block_type->supported_features['editable'] ) {
				continue;
			}
			$this->form_records_info['fields'][ $block_data['id'] ] = array(
				'name'       => $block_data['name'],
				'label'      => $block_data['attributes']['label'],
				'attributes' => $block_data['attributes'],
				'block_type' => $block_type,
			);
		}
	}
}
