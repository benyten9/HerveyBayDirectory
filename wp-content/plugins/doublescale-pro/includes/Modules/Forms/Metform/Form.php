<?php

/**
 * MetForm Form Class
 * This class is responsible for handling the integration of MetForm forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Metform;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * MetForm class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'metform';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'MetForm';

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
		add_action( 'metform_after_store_form_data', array( $this, 'process' ), 10, 4 );
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
		return doublescale_is_plugin_active( 'metform/metform.php' );
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
		$form_fields = \Metform\Core\Entries\Action::instance()->get_fields( $form_id );
		$fields      = array();

		if ( empty( $form_fields ) ) {
			return $fields;
		}

		foreach ( $form_fields as $field_id => $field ) {
			if ( 'mf-recaptcha' === $field->widgetType ) {
				continue;
			}
			$fields[ $field_id ] = array(
				'label' => $field->mf_input_label,
				'type'  => str_replace( 'mf-', '', $field->widgetType ),
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
			wp_send_json_error( __( 'Invalid form ID', 'doublescale') );
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

		$form_list = array();
		$args      = array(
			'posts_per_page' => -1,
			'post_type'      => 'metform-form',
			'post_status'    => 'publish',
		);

		$forms = get_posts( $args );

		if ( ! empty( $forms ) ) {
			foreach ( $forms as $form ) {
				$form_list[ $form->ID ] = $form->post_title;
			}
		}

		wp_send_json_success( $form_list );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param int   $form_id       Form ID
	 * @param array $form_data     Form Data
	 * @param array $form_settings Form Settings
	 * @param array $attributes    Additional attributes
	 *
	 * @return void
	 */
	public function process( $form_id, $form_data, $form_settings, $attributes ) {
		$data               = $this->get_default_data();
		$data['form_id']    = $form_id;
		$data['fields']     = $this->get_fields( $form_id );
		$data['form_title'] = get_the_title( $form_id );

		// Get entry_id from MetForm Action instance
		// The entry is created before this hook fires, so we can access it
		$action_instance = \Metform\Core\Entries\Action::instance();
		$entry_id        = null;

		// Try to get entry_id using reflection since it's a private property
		try {
			$reflection = new \ReflectionClass( $action_instance );
			$property   = $reflection->getProperty( 'entry_id' );
			$property->setAccessible( true );
			$entry_id = $property->getValue( $action_instance );
		} catch ( \Exception $e ) {
			// Fallback to database query if reflection fails
			global $wpdb;
			$entry_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(id) FROM {$wpdb->prefix}metform_entries WHERE form_id = %d",
					$form_id
				)
			);
		}

		$data['entry_id'] = $entry_id;

		$data['entry'] = array(
			'fields' => $form_data,
		);

		if ( $this->is_form_active( $form_id ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}
}
