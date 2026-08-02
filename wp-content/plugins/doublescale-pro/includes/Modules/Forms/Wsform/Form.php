<?php

/**
 * Class WS Form
 * This class is responsible for handling the integration of WS Form
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Wsform;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * WSForm class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'wsform';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'WS Form';

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
		// Hook into form submission after all actions complete
		add_action( 'wsf_submit_post_complete', array( $this, 'process' ), 10, 1 );
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
		return doublescale_is_plugin_active( 'ws-form/ws-form.php' ) || doublescale_is_plugin_active( 'ws-form-pro/ws-form.php' );
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
		$fields = array();

		if ( ! function_exists( 'wsf_form_get_object' ) ) {
			return $fields;
		}

		$form_object = wsf_form_get_object( $form_id, true, true );

		if ( empty( $form_object ) || ! isset( $form_object->groups ) ) {
			return $fields;
		}

		// Excluded field types
		$excluded_types = array(
			'submit',
			'reset',
			'clear',
			'tab_previous',
			'tab_next',
			'save',
			'section',
			'recaptcha',
			'hcaptcha',
			'turnstile',
			'signature',
			'html',
			'divider',
			'spacer',
			'message',
			'progress',
			'legal',
			'hidden',
		);

		// Iterate through groups (tabs), sections, and fields
		foreach ( $form_object->groups as $group ) {
			if ( empty( $group->sections ) ) {
				continue;
			}

			foreach ( $group->sections as $section ) {
				if ( empty( $section->fields ) ) {
					continue;
				}

				foreach ( $section->fields as $field ) {
					// Skip excluded field types
					if ( in_array( $field->type, $excluded_types, true ) ) {
						continue;
					}

					$field_id    = $field->id;
					$field_label = isset( $field->label ) && ! empty( $field->label ) ? $field->label : "Field {$field_id}";
					$field_type  = $field->type;

					$fields[ $field_id ] = array(
						'label' => $field_label,
						'type'  => $field_type,
					);
				}
			}
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

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

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

		$options = array();

		if ( ! function_exists( 'wsf_form_get_all' ) ) {
			wp_send_json_error( array( 'message' => __( 'WS Form is not active', 'doublescale') ) );
			return;
		}

		$forms = wsf_form_get_all( true ); // Get only published forms

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'No forms found', 'doublescale') ) );
			return;
		}

		foreach ( $forms as $form ) {
			if ( ! isset( $form['id'], $form['label'] ) ) {
				continue;
			}
			$options[ (int) $form['id'] ] = sanitize_text_field( $form['label'] );
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param object $submit WS Form submit object
	 *
	 * @return void
	 */
	public function process( $submit ) {
		try {
			$form_id  = $submit->form_id;
			$entry_id = $submit->id;

			$data               = $this->get_default_data();
			$data['form_id']    = $form_id;
			$data['entry_id']   = $entry_id;
			$data['fields']     = $this->get_fields( $form_id );
			$data['form_title'] = $this->get_form_title( $form_id );

			$data['entry'] = array(
				'fields' => $this->prepare_form_fields( $submit ),
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $data );
			}

			$this->process_automations( $data );
		} catch ( \Exception $e ) {
			// Log error but don't break the form submission
			doublescale_get_logger()->error(
				__( 'Error processing WS Form', 'doublescale'),
				array(
					'code'     => 'wsform_process_error',
					'form_id'  => $form_id ?? null,
					'entry_id' => $entry_id ?? null,
					'error'    => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}

	/**
	 * Get Form Title
	 *
	 * @since 1.0.0
	 *
	 * @param int $form_id
	 *
	 * @return string
	 */
	protected function get_form_title( $form_id ) {
		if ( ! function_exists( 'wsf_form_get_object' ) ) {
			return '';
		}

		$form_object = wsf_form_get_object( $form_id, false, false );

		return isset( $form_object->label ) ? $form_object->label : '';
	}

	/**
	 * Prepare Form Fields
	 *
	 * @since 1.0.0
	 *
	 * @param object $submit WS Form submit object
	 *
	 * @return array
	 */
	public function prepare_form_fields( $submit ) {
		$prepared_fields = array();

		// Get meta data from submit object
		if ( ! isset( $submit->meta ) || ! is_array( $submit->meta ) ) {
			return $prepared_fields;
		}

		foreach ( $submit->meta as $field_id => $meta ) {
			// Extract field ID from meta key (format: field_XX)
			if ( strpos( $field_id, 'field_' ) === 0 ) {
				$actual_field_id = str_replace( 'field_', '', $field_id );

				// Get value from meta
				$value = isset( $meta['value'] ) ? $meta['value'] : '';

				// Handle array values
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				$prepared_fields[ $actual_field_id ] = $value;
			}
		}

		return $prepared_fields;
	}
}
