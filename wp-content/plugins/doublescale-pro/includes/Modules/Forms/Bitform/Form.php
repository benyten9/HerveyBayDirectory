<?php

/**
 * Class Bit Form
 * This class is responsible for handling the integration of Bit Form
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Bitform;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * BitForm class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'bitform';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Bit Form';

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
		// Hook into form submission success
		add_action( 'bitform_submit_success', array( $this, 'process' ), 10, 4 );
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
		return doublescale_is_plugin_active( 'bit-form/bitforms.php' );
	}

	/**
	 * Get Fields
	 *
	 * Bit Form stores fields in the form_content JSON column of bitforms_form table.
	 * Field keys are like 'b1-1-', 'b1-2-', etc.
	 * Field structure: { typ: 'text', lbl: 'Field Label', adminLbl: 'Admin Label', fieldName: 'field_name', ... }
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	public function get_fields( $form_id ) {
		global $wpdb;
		$fields = array();

		// Get form content from Bit Form database
		$table_name = $wpdb->prefix . 'bitforms_form';

		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			return $fields;
		}

		$form_content_json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT form_content FROM {$table_name} WHERE id = %d",
				$form_id
			)
		);

		if ( empty( $form_content_json ) ) {
			return $fields;
		}

		$form_content = json_decode( $form_content_json );

		if ( empty( $form_content ) || ! isset( $form_content->fields ) ) {
			return $fields;
		}

		// Excluded field types
		$excluded_types = array(
			'button',
			'recaptcha',
			'hcaptcha',
			'turnstile',
			'divider',
			'html',
			'section',
			'title',
			'image',
			'spacer',
			'paypal',
			'razorpay',
			'stripe',
		);

		foreach ( $form_content->fields as $field_key => $field ) {
			$field_type = isset( $field->typ ) ? $field->typ : '';

			// Skip excluded field types
			if ( in_array( $field_type, $excluded_types, true ) ) {
				continue;
			}

			// Get label: prefer lbl, then adminLbl, then fieldName, then key
			$field_label = '';
			if ( ! empty( $field->lbl ) ) {
				$field_label = $field->lbl;
			} elseif ( ! empty( $field->adminLbl ) ) {
				$field_label = $field->adminLbl;
			} elseif ( ! empty( $field->fieldName ) ) {
				$field_label = $field->fieldName;
			} else {
				$field_label = $field_key;
			}

			$fields[ $field_key ] = array(
				'label' => $field_label,
				'type'  => $field_type,
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

		global $wpdb;
		$options = array();

		$table_name = $wpdb->prefix . 'bitforms_form';

		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			wp_send_json_error( array( 'message' => __( 'Bit Form is not active', 'doublescale') ) );
			return;
		}

		$forms = $wpdb->get_results(
			"SELECT id, form_name FROM {$table_name} WHERE status = 1 ORDER BY form_name ASC"
		);

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'No forms found', 'doublescale') ) );
			return;
		}

		foreach ( $forms as $form ) {
			$options[ $form->id ] = $form->form_name;
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * @since 1.0.0
	 *
	 * @param int   $form_id       Form ID
	 * @param int   $entry_id      Entry ID
	 * @param array $form_data     Submitted form data
	 * @param array $integrations  Integration data
	 *
	 * @return void
	 */
	public function process( $form_id, $entry_id, $form_data, $integrations = array() ) {
		try {
			$data               = $this->get_default_data();
			$data['form_id']    = $form_id;
			$data['entry_id']   = $entry_id;
			$data['fields']     = $this->get_fields( $form_id );
			$data['form_title'] = $this->get_form_title( $form_id );

			$data['entry'] = array(
				'fields' => $this->prepare_form_fields( $form_data ),
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $data );
			}

			$this->process_automations( $data );
		} catch ( \Exception $e ) {
			// Log error but don't break the form submission
			doublescale_get_logger()->error(
				__( 'Error processing Bit Form', 'doublescale'),
				array(
					'code'     => 'bitform_process_error',
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
		global $wpdb;

		$table_name = $wpdb->prefix . 'bitforms_form';

		$form_name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT form_name FROM {$table_name} WHERE id = %d",
				$form_id
			)
		);

		return $form_name ? $form_name : '';
	}

	/**
	 * Prepare Form Fields
	 *
	 * Bit Form field keys are like 'b1-1-', 'b1-2-', etc.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form_data Submitted form data
	 *
	 * @return array
	 */
	public function prepare_form_fields( $form_data ) {
		$prepared_fields = array();

		if ( empty( $form_data ) || ! is_array( $form_data ) ) {
			return $prepared_fields;
		}

		// Internal/system fields to skip
		$skip_fields = array(
			'g-recaptcha-response',
			'h-captcha-response',
			'cf-turnstile-response',
			'bitforms_nonce',
			'_wp_http_referer',
			'form-current-step',
			'hidden_fields',
		);

		foreach ( $form_data as $field_key => $value ) {
			// Skip internal fields
			if ( in_array( $field_key, $skip_fields, true ) ) {
				continue;
			}

			// Bit Form field keys start with 'b' followed by numbers and dashes (e.g., 'b1-1-')
			// Also allow GCLID and other standard fields
			if ( strpos( $field_key, 'b' ) !== 0 && $field_key !== 'GCLID' ) {
				continue;
			}

			// Handle array values
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value ) );
			}

			$prepared_fields[ $field_key ] = $value;
		}

		return $prepared_fields;
	}
}
