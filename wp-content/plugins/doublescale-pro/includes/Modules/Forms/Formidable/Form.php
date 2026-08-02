<?php

/**
 * Class Formidable Form
 * This class is responsible for handling the integration of formidable forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Formidable;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * Formidable class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'formidable';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Formidable';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'This will trigger when a form is submitted';

	/**
	 * Load Hooks
	 */
	public function load_hooks() {
		add_action( 'frm_after_create_entry', array( $this, 'process' ), 10, 2 );
		// Ajax Get Fields
		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		// Ajax Get Form Select Options
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
	}



	/**
	 * Is Enabled
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return doublescale_is_plugin_active( 'formidable/formidable.php' );
	}

	/**
	 * Get Fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	public function get_fields( $form_id ) {
		$form_fields = \FrmField::get_all_for_form( $form_id );
		if ( ! $form_fields ) {
			return array();
		}

		$fields = array();
		if ( ! empty( $form_fields ) ) {
			$fields = $this->prepare_fields( $form_fields );
		}

		return $fields;
	}


	/**
	 * Prepare fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields Fields
	 *
	 * @return array
	 */
	public function prepare_fields( $fields ) {
		$fields_arr = array();

		foreach ( $fields as $field ) {
			if ( isset( $field->field_options['hidden'] ) && $field->field_options['hidden'] || 'submit' === $field->type ) {
				continue;
			}

			$field_id = FormUtils::get_field_id( $field->id );
			$label    = $field->name ?? $field->field_key;

			switch ( $field->type ) {
				case 'name':
					$fields_arr = array_merge( $fields_arr, FormUtils::prepare_name_field( $field, $field_id ) );
					break;
				case 'address':
					$fields_arr = array_merge( $fields_arr, FormUtils::prepare_address_field( $field, $field_id ) );
					break;
				default:
					$fields_arr[ $field_id ] = array(
						'label' => $label,
						'type'  => $field->type,
					);
					break;
			}
		}

		return $fields_arr;
	}

	/**
	 * Ajax Get Fields
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_fields() {
		 // Check nonce.
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( $_POST['form_id'] ) : '';

		if ( empty( $form_id ) ) {
			wp_send_json_error( __( 'Invalid form id', 'doublescale') );
		}

		$fields = $this->get_fields( $form_id );

		wp_send_json_success( $fields );
	}

	/**
	 * Ajax Get Form Select Options
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		// Check nonce.
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$forms   = \FrmForm::getAll();
		$options = array();

		foreach ( $forms as $form ) {
			$options[ $form->id ] = $form->name;
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param int $entry_id
	 * @param int $form_id
	 *
	 * @return void
	 */
	public function process( $entry_id, $form_id ) {
		$entry = \FrmEntry::getOne( $entry_id, true );
		if ( ! isset( $entry->metas ) ) {
			return;
		}

		$data                    = $this->get_default_data();
		$data['form_id']         = $form_id;
		$data['entry_id']        = $entry_id;
		$data['form_title']      = \FrmForm::getOne( $form_id )->name;
		$form_fields             = \FrmField::get_all_for_form( $form_id );
		$data['fields']          = $this->prepare_fields( $form_fields );
		$data['entry']['fields'] = $this->prepare_entry( $entry->metas, $form_fields, $data['fields'] );

		if ( $this->is_form_active( $form_id ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}


	/**
	 * Prepare entry fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry       Entry metas.
	 * @param array $form_fields Form field objects.
	 * @param array $fields      Prepared fields array.
	 *
	 * @return array
	 */
	public function prepare_entry( $entry, $form_fields, $fields ) {
		$entry_fields   = array();
		$name_fields    = array( 'first', 'last', 'middle' );
		$address_fields = array( 'address1', 'address2', 'city', 'state', 'zip', 'country' );

		// Create a map of field_id to field object for quick lookup
		$field_map = array();
		foreach ( $form_fields as $field ) {
			$field_map[ $field->id ] = $field;
		}

		foreach ( $entry as $field_id => $field_value ) {
			// Skip if field doesn't exist in the form
			if ( ! isset( $field_map[ $field_id ] ) ) {
				continue;
			}

			$field        = $field_map[ $field_id ];
			$formatted_id = FormUtils::get_field_id( $field_id );

			switch ( $field->type ) {
				case 'name':
					foreach ( $name_fields as $name_field ) {
						$sub_field_id = $formatted_id . '_' . $name_field;
						if ( isset( $fields[ $sub_field_id ] ) && is_array( $field_value ) && isset( $field_value[ $name_field ] ) ) {
							$entry_fields[ $sub_field_id ] = $field_value[ $name_field ];
						}
					}
					break;
				case 'address':
					foreach ( $address_fields as $address_field ) {
						$sub_field_id = $formatted_id . '_' . $address_field;
						if ( isset( $fields[ $sub_field_id ] ) && is_array( $field_value ) ) {
							$address_key = 'zip' === $address_field ? 'postal' : $address_field;
							if ( isset( $field_value[ $address_key ] ) ) {
								$entry_fields[ $sub_field_id ] = $field_value[ $address_key ];
							}
						}
					}
					break;
				default:
					if ( isset( $fields[ $formatted_id ] ) ) {
						$entry_fields[ $formatted_id ] = $field_value;
					}
					break;
			}
		}

		return $entry_fields;
	}
}
