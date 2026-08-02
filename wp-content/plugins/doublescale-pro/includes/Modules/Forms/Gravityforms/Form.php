<?php

/**
 * Class GravityForms Form
 * This class is responsible for handling the integration of gravityforms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Gravityforms;

use GFAPI;
use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * GravityForms class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'gravityforms';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'GravityForms';

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
		add_action( 'gform_after_submission', array( $this, 'process' ), 10, 2 );
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
		return doublescale_is_plugin_active( 'gravityforms/gravityforms.php' );
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
		$form = GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return;
		}

		$fields = array();

		foreach ( $form['fields'] as $field ) {
			$inputs = $field->get_entry_inputs();
			if ( is_array( $inputs ) && 'checkbox' !== $field['type'] ) {
				foreach ( $inputs as $input ) {
					if ( isset( $input['isHidden'] ) && $input['isHidden'] ) {
						continue;
					}
					$fields[ $input['id'] ] = array(
						'label' => $field->label . ': ' . $input['label'],
						'type'  => $field['type'],
					);
				}
			} else {
				$fields[ $field->id ] = array(
					'label' => $field->label,
					'type'  => $field['type'],
				);
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

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : null;

		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Form ID is required', 'doublescale') ) );
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
		$forms   = GFAPI::get_forms();

		if ( ! empty( $forms ) ) {
			foreach ( $forms as $form ) {
				$options[ $form['id'] ] = $form['title'];
			}
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param object $entry
	 * @param array  $form
	 *
	 * @return void
	 */
	public function process( $entry, $form ) {
		$data               = $this->get_default_data();
		$data['entry_id']   = $entry['id'];
		$data['entry']      = array(
			'fields' => $entry,
		);
		$data['form_id']    = $form['id'];
		$data['form_title'] = $form['title'];
		$data['fields']     = $this->get_fields( $form['id'] );

		if ( $this->is_form_active( $form['id'] ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}
}
