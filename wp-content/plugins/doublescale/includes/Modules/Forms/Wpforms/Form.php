<?php

/**
 * Class WPForms Form
 * This class is responsible for handling the integration of wpforms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Wpforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Wpforms\FormUtils;
use DoubleScale\Modules\Forms\Services\FormsManager;

/**
 * WPForms class
 */
class Form extends Abstracts_Form {



	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'wpforms';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'WPForms';

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
		add_action( 'wpforms_process_complete', array( $this, 'process' ), 10, 4 );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
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
		return doublescale_is_plugin_active( 'wpforms/wpforms.php' );
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
		$form = wpforms()->form->get( absint( $form_id ) );
		if ( ! $form ) {
			return;
		}

		$form_fields = wpforms_decode( $form->post_content );
		$fields      = array();
		if ( ! empty( $form_fields['fields'] ) ) {
			$fields = $this->prepare_fields( $form_fields['fields'] );
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
			if ( isset( $field['isHidden'] ) && $field['isHidden'] ) {
				continue;
			}

			$field_id = FormUtils::get_field_id( $field['id'] );
			$label    = $field['label'] ?? $field['name'];

			switch ( $field['type'] ) {
				case 'name':
					$fields_arr = array_merge( $fields_arr, FormUtils::prepare_name_field( $field, $field_id ) );
					break;
				case 'address':
					$fields_arr = array_merge( $fields_arr, FormUtils::prepare_address_field( $field, $field_id ) );
					break;
				default:
					$fields_arr[ $field_id ] = array(
						'label' => $label,
						'type'  => $field['type'],
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

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'doublescale') ) );
		}

		$fields = $this->get_fields( $form_id );

		wp_send_json_success( $fields );
	}

	/**
	 * Get Form Select Options
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		// Check nonce.
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$forms = wpforms()->form->get();

		$options = array();

		foreach ( $forms as $form ) {
			$options[ $form->ID ] = $form->post_title;
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields
	 * @param array $entry
	 * @param array $form_data
	 * @param array $entry_id
	 *
	 * @return void
	 */
	public function process( $fields, $entry, $form_data, $entry_id ) {
		$data               = $this->get_default_data();
		$entry['fields']    = $fields;
		$data['entry']      = $this->prepare_entry( $entry, $this->prepare_fields( $fields ) );
		$data['entry_id']   = $entry_id;
		$data['form_id']    = $form_data['id'];
		$data['form_title'] = isset( $form_data['id'] ) ? get_the_title( $form_data['id'] ) : '';
		$data['fields']     = $this->prepare_fields( $fields );

		if ( $this->is_form_active( $form_data['id'] ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}

	/**
	 * Prepare entry fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Entry.
	 * @param array $fields Fields.
	 *
	 * @return array
	 */
	public function prepare_entry( $entry, $fields ) {
		$entry_fields   = array();
		$name_fields    = array( 'first', 'last', 'middle' );
		$address_fields = array( 'address1', 'address2', 'city', 'state', 'zip', 'country' );

		foreach ( $entry['fields'] as  $field ) {
			$field_id = FormUtils::get_field_id( $field['id'] );
			switch ( $field['type'] ) {
				case 'name':
					foreach ( $name_fields as $name_field ) {
						$sub_field_id = $field_id . '_' . $name_field;
						if ( isset( $fields[ $sub_field_id ] ) ) {
							$entry_fields[ $sub_field_id ] = $field[ $name_field ];
						}
					}
					break;
				case 'address':
					foreach ( $address_fields as $address_field ) {
						$sub_field_id = $field_id . '_' . $address_field;
						if ( isset( $fields[ $sub_field_id ] ) ) {
							$address_field                 = 'zip' === $address_field ? 'postal' : $address_field;
							$entry_fields[ $sub_field_id ] = $field[ $address_field ];
						}
					}
					break;
				default:
					$entry_fields[ $field_id ] = $field['value'];
					break;
			}
		}

		$entry['fields'] = $entry_fields;

		return $entry;
	}
}
