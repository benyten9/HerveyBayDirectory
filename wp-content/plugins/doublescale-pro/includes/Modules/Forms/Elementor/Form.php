<?php

/**
 * Class ElementorForms Form
 * This class is responsible for handling the integration of elementor forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Elementor;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Pro\Modules\Forms\Elementor\Utils;
/**
 * ElementorForms class
 */
class Form extends Abstracts_Form {


	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'elementor';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Elementor';

	/**
	 * Post ID
	 *
	 * @var int
	 */
	public $post_id;

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
		add_action( 'elementor_pro/forms/new_record', array( $this, 'process' ), 10, 2 );
		// Ajax Get Fields
		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		// Ajax Get Form Select Options
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
		// Ajax Get Source Select Options
		add_action( "wp_ajax_doublescale_{$this->slug}_get_source_select_options", array( $this, 'ajax_get_source_select_options' ) );
	}


	/**
	 * Is Enabled
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return doublescale_is_plugin_active( 'elementor-pro/elementor-pro.php' );
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
		$form        = Utils::get_form( $form_id, $this->post_id );
		$form_fields = $form['settings']['form_fields'] ?? array();

		$fields = array();
		foreach ( $form_fields as $field ) {
			$fields[ $field['custom_id'] ] = array(
				'label' => $field['field_label'],
				'type'  => $field['field_type'],
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
		$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( $_POST['post_id'] ) : '';

		if ( empty( $post_id ) ) {
			wp_send_json_error( __( 'Invalid source ID', 'doublescale') );
		}

		if ( empty( $form_id ) ) {
			wp_send_json_error( __( 'Invalid form ID', 'doublescale') );
		}

		$this->post_id = $post_id;
		$fields        = $this->get_fields( $form_id, $post_id );

		wp_send_json_success( $fields );
	}

	/**
	 * Ajax Get Source Select Options
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_source_select_options() {
		// Check nonce.
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$pages = Utils::get_pages();

		wp_send_json_success( $pages );
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

		$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( $_POST['post_id'] ) : '';

		if ( empty( $post_id ) ) {
			wp_send_json_error( __( 'Invalid source ID', 'doublescale') );
		}

		$forms = Utils::get_forms_by_page_id( $post_id );

		wp_send_json_success( $forms );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param $record
	 * @param $handler
	 *
	 * @return void
	 */
	public function process( $record, $handler ) {
		global $wpdb;
		$data               = $this->get_default_data();
		$data['form_id']    = $record->get_form_settings( 'id' );
		$data['form_title'] = $record->get_form_settings( 'form_name' );
		$fields             = $record->get( 'fields' );
		$data['fields']     = $this->prepare_fields( $fields );

		// Elementor doesn't provide submission IDs by default
		// Try to get the submission ID if "Collect Submissions" action is enabled
		$page_id = $record->get_form_settings( 'form_post_id' );
		if ( ! $page_id ) {
			$page_id = $record->get_form_settings( 'source_id' );
		}
		if ( ! $page_id ) {
			$page_id = get_the_ID();
		}
		if ( ! $page_id && ! empty( $_POST['referrer'] ) ) {
			$page_id = url_to_postid( sanitize_text_field( $_POST['referrer'] ) );
		}

		$form_id_combined = $page_id . ':' . $record->get_form_settings( 'id' );
		$element_id       = $record->get_form_settings( 'id' );

		// Try to get submission ID from Elementor's submissions table if it exists
		$table_name = $wpdb->prefix . 'e_submissions';
		$entry_id   = null;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			// Get the most recent submission for this specific form (post_id + element_id combination)
			$entry_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $table_name 
					WHERE post_id = %d 
					AND element_id = %s 
					ORDER BY created_at DESC, id DESC 
					LIMIT 1",
					$page_id,
					$element_id
				)
			);

			// Fallback: try by form name if the above query returns nothing
			if ( ! $entry_id ) {
				$entry_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM $table_name 
						WHERE form_name = %s 
						ORDER BY created_at DESC, id DESC 
						LIMIT 1",
						$data['form_title']
					)
				);
			}
		}

		$data['entry_id'] = $entry_id;

		$entry = array( 'fields' => array() );
		foreach ( $fields as $field_id => $field ) {
			$entry['fields'][ $field_id ] = $field['value'] ?? '';
		}
		$data['entry'] = $entry;

		if ( $this->is_form_active( $form_id_combined ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}


	/**
	 * Get form id
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return string
	 */
	public function get_form_id( $form_id ) {
		return $form_id;
	}


	/**
	 * Prepare fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields form fields
	 *
	 * @return array
	 */
	public function prepare_fields( $fields ) {
		$prepared_fields = array();

		foreach ( $fields as $field_id => $field ) {
			$prepared_fields[ $field_id ] = $field['title'] ?? '';
		}

		return $prepared_fields;
	}

	/**
	 * Get form options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_form_options() {
		$options = array(
			'post_id' => array(
				'label'       => __( 'Source ID', 'doublescale'),
				'type'        => 'ajax_select',
				'help'        => __( 'The Post/Page or Elementor Library ID where the form is located', 'doublescale'),
				'ajax_action' => "doublescale_{$this->slug}_get_source_select_options",
			),
			'form_id' => array(
				'label'       => __( 'Form ID', 'doublescale'),
				'type'        => 'ajax_select',
				'ajax_action' => "doublescale_{$this->slug}_get_form_select_options",
				'parent'      => 'post_id',
				'conditions'  => array(
					'relation' => 'and',
					'rules'    => array(
						array(
							'field'    => 'post_id',
							'operator' => 'not_empty',
						),
					),
				),
			),
		);

		return $options;
	}

	/**
	 * Get form fields settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_form_fields_settings() {
		$settings = array(
			'action' => "doublescale_{$this->slug}_get_fields",
			'fields' => array(
				'post_id',
				'form_id',
			),
		);

		return $settings;
	}
}
