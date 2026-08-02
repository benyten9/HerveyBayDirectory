<?php

/**
 * Class QuillForms
 * This class is responsible for handling the integration of quillforms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Quillforms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Forms\Quillforms\FormUtils;
use DoubleScale\Modules\Forms\Services\FormsManager;


/**
 * QuillForms class
 */
class Form extends Abstracts_Form {
	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'quillforms';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'QuillForms';

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
		add_action( 'quillforms_after_entry_processed', array( $this, 'process' ), 10, 2 );
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
		return doublescale_is_plugin_active( 'quillforms/quillforms.php' );
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
		$form_utils = new FormUtils( $form_id );
		$fields     = $form_utils->get_fields();

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
			wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'doublescale') ) );
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
		$forms   = get_posts(
			array(
				'post_type'      => 'quill_forms',
				'posts_per_page' => -1,
			)
		);

		if ( ! empty( $forms ) ) {
			foreach ( $forms as $form ) {
				$options[ $form->ID ] = $form->post_title;
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
	 * @param array  $form_data
	 *
	 * @return void
	 */
	public function process( $entry, $form_data ) {
		$form_utils         = new FormUtils( $entry->form_id, $form_data );
		$form_data          = $form_utils->prepare_entry( $entry );
		$data               = $this->get_default_data();
		$data['fields']     = $form_utils->get_fields();
		$data['entry']      = $form_data;
		$data['entry_id']   = $entry->ID;
		$data['form_id']    = $entry->form_id;
		$data['form_title'] = isset( $entry->form_id ) ? get_the_title( $entry->form_id ) : '';

		if ( $this->is_form_active( $entry->form_id ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}
}
