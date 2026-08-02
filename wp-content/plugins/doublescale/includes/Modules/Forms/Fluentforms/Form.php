<?php

/**
 * Fluent Forms Form class
 * This class is responsible for fluent forms integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Fluentforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Services\FormsManager;

/**
 * FluentForms class
 */
class Form extends Abstracts_Form {



	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'fluentforms';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Fluent Forms';

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
		add_action( 'fluentform_submission_inserted', array( $this, 'process' ), 10, 3 );
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
		return doublescale_is_plugin_active( 'fluentform/fluentform.php' );
	}

	/**
	 * Get Fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 * @param bool   $register_merge_tags Whether to register merge tags
	 *
	 * @return array
	 */
	public function get_fields( $form_id, $register_merge_tags = true ) {
		$form   = wpFluent()->table( 'fluentform_forms' )->find( $form_id );
		$fields = array();

		if ( empty( $form ) ) {
			return array();
		}

		$form_fields = json_decode( $form->form_fields );
		$fields      = $this->get_fields_recursive( $form_fields->fields );

		return $fields;
	}

	/**
	 * Get Fields Recursive
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function get_fields_recursive( $fields ) {
		$result = array();

		foreach ( $fields as $key => $field ) {
			if ( ( isset( $field->settings->visible ) && false === $field->settings->visible ) ) {
				continue;
			}

			if ( isset( $field->fields ) && ! empty( $field->fields ) ) {
				$result = array_merge( $result, $this->get_fields_recursive( $field->fields ) );
				continue;
			}

			if ( isset( $field->columns ) && ! empty( $field->columns ) ) {
				$result = array_merge( $result, $this->get_fields_recursive( $field->columns ) );
				continue;
			}

			if ( isset( $field->attributes->name ) && isset( $field->settings->label ) ) {
				$result[ $field->attributes->name ] = array(
					'label' => $field->settings->label,
					'type'  => $field->attributes->type,
				);
			}
		}

		return $result;
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

		$forms   = wpFluent()->table( 'fluentform_forms' )->get();
		$options = array();

		foreach ( $forms as $form ) {
			$options[ $form->id ] = $form->title;
		}

		wp_send_json_success( $options );
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

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';

		if ( empty( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form ID', 'doublescale') ) );
		}

		$fields = $this->get_fields( $form_id );

		wp_send_json_success( $fields );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $form_data Data.
	 * @param array $form Form.
	 *
	 * @return void
	 */
	public function process( $submission_id, $form_data, $form ) {
		$data               = $this->get_default_data();
		$data['form_id']    = $form['id'];
		$data['entry_id']   = $submission_id;
		$data['form_title'] = $form['title'];
		$fields             = json_decode( $form['form_fields'] );
		$data['fields']     = $this->get_fields_recursive( $fields->fields );
		$data['entry']      = array(
			'fields' => $this->prepare_form_fields_recursive( $form_data ),
		);

		if ( $this->is_form_active( $form['id'] ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}

	/**
	 * Prepare Form Fields Recursive
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields Fields.
	 *
	 * @return array
	 */
	public function prepare_form_fields_recursive( $fields ) {
		$result = array();

		foreach ( $fields as $key => $field ) {
			if ( is_array( $field ) ) {
				$result = array_merge( $result, $this->prepare_form_fields_recursive( $field ) );
				continue;
			}

			if ( is_object( $field ) ) {
				$result = array_merge( $result, $this->prepare_form_fields_recursive( (array) $field ) );
				continue;
			}

			$result[ $key ] = $field;
		}

		return $result;
	}
}
