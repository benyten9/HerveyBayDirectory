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
			$fields[ $field_id ] = null;

			if ( ! isset( $entry->records['fields'][ $field_id ] ) ) {
				continue;
			}

			// One field's odd value must never break the visitor's submission:
			// this runs inside Quill Forms' "entry processed" hook.
			try {
				if ( 'date' === $field['name'] ) {
					$raw    = $field['block_type']->get_readable_value( $entry->records['fields'][ $field_id ]['value'], $this->form_data, 'raw' );
					$fields = array_merge( $fields, self::date_values( $field_id, $raw ) );
					continue;
				}

				$fields[ $field_id ] = $field['block_type']->get_readable_value( $entry->records['fields'][ $field_id ]['value'], $this->form_data, 'plain' );
			} catch ( \Throwable $e ) {
				doublescale_get_logger()->error(
					'Quill Forms: could not read a field of the submission',
					array(
						'code'     => 'quillforms_field_read_failed',
						'form_id'  => $this->form_id,
						'field_id' => $field_id,
						'error'    => $e->getMessage(),
					)
				);
			}
		}

		return array(
			'fields' => $fields,
		);
	}

	/**
	 * A date answer as DoubleScale exposes it: the date itself plus its ISO 8601 and Unix forms.
	 *
	 * Quill Forms stores a date as Y-m-d, but an optional date that was cleared (or sent by an
	 * API or import) is an empty or malformed string: that is "no answer", not an error.
	 *
	 * @param string $field_id Field (block) id.
	 * @param mixed  $raw      The stored value.
	 * @return array<string, mixed>
	 */
	public static function date_values( $field_id, $raw ) {
		$empty = array(
			$field_id                 => null,
			"{$field_id}_utc_iso8601" => null,
			"{$field_id}_unix"        => null,
		);

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $empty;
		}

		$date = \DateTime::createFromFormat( '!Y-m-d', trim( $raw ), new \DateTimeZone( 'UTC' ) );
		if ( ! $date ) {
			return $empty;
		}

		return array(
			$field_id                 => $date->format( 'Y-m-d' ),
			"{$field_id}_utc_iso8601" => $date->format( 'c' ),
			"{$field_id}_unix"        => $date->getTimestamp(),
		);
	}

	/**
	 * A field's block attributes (label, choices, yes/no labels…).
	 *
	 * @param string $field_id Block id.
	 * @return array
	 */
	public function get_field_attributes( $field_id ) {
		return $this->form_records_info['fields'][ $field_id ]['attributes'] ?? array();
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
					esc_html__( 'Field ID #%s', 'doublescale' ),
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
				'label'      => $block_data['attributes']['label'] ?? '',
				'attributes' => $block_data['attributes'],
				'block_type' => $block_type,
			);
		}
	}
}
