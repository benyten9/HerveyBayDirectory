<?php

/**
 * Class Forminator Form
 * This class is responsible for handling the integration of forminator
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Forminator;

use Forminator_API;
use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * Forminator class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'forminator';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Forminator';

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
		// Use the hook that fires BEFORE setting fields to database
		// This hook provides direct access to entry object with entry_id and field_data_array
		add_action( 'forminator_custom_form_submit_before_set_fields', array( $this, 'process' ), 10, 3 );
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
		return doublescale_is_plugin_active( 'forminator/forminator.php' );
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
		$form_fields = Forminator_API::get_form_fields( $form_id );
		$fields      = array();

		if ( empty( $form_fields ) ) {
			return $fields;
		}

		$address_fields = array(
			'street_address',
			'city',
			'country',
			'line',
			'state',
			'zip',
		);

		foreach ( $form_fields as $field ) {
			if ( 'group' === $field->type ) {
				continue;
			}
			if ( 'address' === $field->type ) {
				foreach ( $address_fields as $address_field ) {
					$address_field_id   = 'line' === $address_field ? $field->element_id . '_address_line' : $field->element_id . '_' . $address_field;
					$address_field_slug = 'street_address' === $address_field ? $address_field : "address_{$address_field}";
					if ( 'true' === $field->raw[ $address_field_slug ] ) {
						$fields[ $address_field_id ] = $field->raw[ "{$address_field_slug}_label" ];
					}
				}
				continue;
			}

			$fields[ $field->element_id ] = array(
				'label' => $field->field_label,
				'type'  => $field->type,
			);
		}

		return $fields;
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
			wp_send_json_error( array( 'message' => __( 'Invalid form ID', 'doublescale') ) );
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

		$forms   = Forminator_API::get_forms();
		$options = array();

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'No forms found', 'doublescale') ) );
		}

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
	 * @param \Forminator_Form_Entry_Model $entry Entry object
	 * @param int                          $form_id Form ID
	 * @param array                        $field_data_array Field data array
	 *
	 * @return void
	 */
	public function process( $entry, $form_id, $field_data_array ) {
		try {
			$data               = $this->get_default_data();
			$data['form_id']    = $form_id;
			$data['entry_id']   = $entry->entry_id; // Get entry ID from entry object
			$data['fields']     = $this->get_fields( $form_id );
			$data['form_title'] = Forminator_API::get_form( $form_id )->name;

			$data['entry'] = array(
				'fields' => $this->prepare_form_fields( $field_data_array ),
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $data );
			}

			$this->process_automations( $data );
		} catch ( \Exception $e ) {
			// Log error but don't break the form submission
			doublescale_get_logger()->error(
				__( 'Error processing Forminator form', 'doublescale'),
				array(
					'code'     => 'forminator_process_error',
					'form_id'  => $form_id,
					'entry_id' => isset( $entry->entry_id ) ? $entry->entry_id : null,
					'error'    => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}

	/**
	 * Prepare Form Fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function prepare_form_fields( $fields ) {
		$prepared_fields = array();
		$address_fields  = array(
			'street_address',
			'city',
			'country',
			'address_line',
			'state',
			'zip',
		);

		foreach ( $fields as $field ) {
			if ( ! isset( $field['field_type'] ) ) {
				continue;
			}
			if ( 'address' === $field['field_type'] ) {
				foreach ( $address_fields as $address_field ) {
					if ( isset( $field['value'][ $address_field ] ) ) {
						$address_field_id                     = $field['name'] . '_' . $address_field;
						$prepared_fields[ $address_field_id ] = $field['value'][ $address_field ];
					}
				}
				continue;
			}

			$prepared_fields[ $field['name'] ] = $field['value'];
		}

		return $prepared_fields;
	}
}
