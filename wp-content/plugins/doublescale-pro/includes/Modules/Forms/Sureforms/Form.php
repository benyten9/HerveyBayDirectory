<?php

/**
 * Class Sure Forms
 * This class is responsible for handling the integration of SureForms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Sureforms;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * SureForms class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'sureforms';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'SureForms';

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
		// Hook into form submission after processing
		// The hook passes a single $form_data array containing form_id, submission_id and all field values
		add_action( 'srfm_after_submission_process', array( $this, 'process' ), 10, 1 );
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
		return doublescale_is_plugin_active( 'sureforms/sureforms.php' );
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

		// Get the form post
		$form_post = get_post( $form_id );

		if ( ! $form_post || 'sureforms_form' !== $form_post->post_type ) {
			return $fields;
		}

		// Parse blocks from form content
		$blocks = parse_blocks( $form_post->post_content );

		if ( empty( $blocks ) ) {
			return $fields;
		}

		// Get registered SureForms block attributes with defaults
		// This is how SureForms does it in inc/rest-api.php
		$sureforms_blocks = $this->get_registered_sureforms_blocks();

		// Extract fields from blocks
		$fields = $this->extract_fields_from_blocks( $blocks, $sureforms_blocks );

		return $fields;
	}

	/**
	 * Get registered SureForms blocks with their default attributes
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function get_registered_sureforms_blocks() {
		$sureforms_blocks = array();

		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $sureforms_blocks;
		}

		$registry          = \WP_Block_Type_Registry::get_instance();
		$registered_blocks = $registry->get_all_registered();

		foreach ( $registered_blocks as $block_name => $block_type ) {
			if ( strpos( $block_name, 'srfm/' ) === 0 && is_array( $block_type->attributes ) ) {
				$block_key                      = str_replace( 'srfm/', '', $block_name );
				$sureforms_blocks[ $block_key ] = $block_type->attributes;
			}
		}

		return $sureforms_blocks;
	}

	/**
	 * Extract fields from blocks recursively
	 *
	 * SureForms field name format: srfm-{block_type}-{block_id}-lbl-{encrypted_label}-{slug}
	 * Example: srfm-input-9be4df01-lbl-Rmlyc3QgTmFtZQ-text-field
	 *
	 * @since 1.0.0
	 *
	 * @param array $blocks          Parsed blocks
	 * @param array $sureforms_blocks Registered SureForms block attributes with defaults
	 *
	 * @return array
	 */
	protected function extract_fields_from_blocks( $blocks, $sureforms_blocks = array() ) {
		$fields = array();

		// Excluded block types
		$excluded_types = array(
			'srfm/submit',
			'srfm/recaptcha',
			'srfm/hcaptcha',
			'srfm/turnstile',
			'srfm/html',
			'srfm/divider',
			'srfm/spacer',
			'srfm/image',
			'srfm/heading',
			'srfm/paragraph',
			'srfm/separator',
			'srfm/icon',
			'srfm/advanced-heading',
			'srfm/inline-button',
			'core/paragraph',
			'core/heading',
			'core/image',
			'core/spacer',
			'core/separator',
			'core/group',
			'core/columns',
			'core/column',
		);

		foreach ( $blocks as $block ) {
			// Skip empty blocks
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			// Skip excluded block types
			if ( in_array( $block['blockName'], $excluded_types, true ) ) {
				continue;
			}

			// Check if this is a SureForms input block
			if ( strpos( $block['blockName'], 'srfm/' ) === 0 ) {
				$block_type = str_replace( 'srfm/', '', $block['blockName'] );

				// Check if we have registered attributes for this block type
				if ( isset( $sureforms_blocks[ $block_type ] ) && is_array( $sureforms_blocks[ $block_type ] ) ) {
					$block_attributes   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
					$default_attributes = $sureforms_blocks[ $block_type ];

					// Merge block instance attributes with defaults (same as SureForms does)
					$merged_attributes = array();
					foreach ( $default_attributes as $attr_name => $attr_config ) {
						if ( ! is_string( $attr_name ) ) {
							continue;
						}
						$default_value = null;
						if ( is_array( $attr_config ) && isset( $attr_config['default'] ) ) {
							$default_value = $attr_config['default'];
						}
						$merged_attributes[ $attr_name ] = $block_attributes[ $attr_name ] ?? $default_value;
					}

					// Get required attributes for field name generation
					$label    = is_string( $merged_attributes['label'] ?? '' ) ? $merged_attributes['label'] : '';
					$slug     = is_string( $merged_attributes['slug'] ?? '' ) ? $merged_attributes['slug'] : '';
					$block_id = is_string( $merged_attributes['block_id'] ?? '' ) ? $merged_attributes['block_id'] : '';

					// Generate field name matching SureForms pattern
					// Format: srfm-{block_type}-{block_id}-lbl-{encrypted_label}-{slug}
					$field_key = $this->generate_field_name( $block_type, $block_id, $label, $slug );

					if ( empty( $field_key ) ) {
						continue;
					}

					// Get field label for display
					$field_label = ! empty( $label ) ? $label : ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );

					// Determine actual field type from slug or block name
					// For srfm/input block, the slug contains the actual type (e.g., 'text-field', 'number-field')
					// For specific blocks like srfm/email, srfm/textarea, etc., use the block type directly
					$field_type = $this->get_field_type( $block_type, $slug );

					$fields[ $field_key ] = array(
						'label' => $field_label,
						'type'  => $field_type,
					);
				}
			}

			// Process inner blocks recursively
			if ( ! empty( $block['innerBlocks'] ) ) {
				$inner_fields = $this->extract_fields_from_blocks( $block['innerBlocks'], $sureforms_blocks );
				$fields       = array_merge( $fields, $inner_fields );
			}
		}

		return $fields;
	}

	/**
	 * Generate field name matching SureForms pattern
	 *
	 * SureForms uses: srfm-{block_type}-{block_id}-lbl-{base64_label}-{slug}
	 * Only when label, slug, and block_id are all present.
	 * If label is empty, the field name format is different.
	 *
	 * @since 1.0.0
	 *
	 * @param string $block_type Block type (e.g., 'input', 'email', 'textarea')
	 * @param string $block_id   Block ID
	 * @param string $label      Field label
	 * @param string $slug       Field slug
	 *
	 * @return string Generated field name
	 */
	protected function generate_field_name( $block_type, $block_id, $label, $slug ) {
		if ( empty( $slug ) || empty( $block_id ) ) {
			return '';
		}

		// SureForms only adds the label part when all three (label, slug, block_id) are present
		// See: sureforms/inc/rest-api.php line 917
		if ( ! empty( $label ) ) {
			// SureForms uses base64 encoding for the label, then strips trailing '=' characters
			// See: sureforms/inc/helper.php Helper::encrypt()
			$encrypted_label = rtrim( base64_encode( wp_strip_all_tags( $label ) ), '=' );
			$input_label     = '-lbl-' . $encrypted_label;
			$base_field_name = $input_label . '-' . $slug;
		} else {
			// If no label, just use the slug
			$base_field_name = '-' . $slug;
		}

		// Handle special block types
		if ( 'dropdown' === $block_type ) {
			// Dropdown uses a counter, but we can't reliably determine it here
			// Use standard pattern as fallback
			$field_name = 'srfm-' . $block_type . '-' . $block_id . $base_field_name;
		} elseif ( 'multi-choice' === $block_type ) {
			$field_name = 'srfm-input-' . $block_type . '-' . $block_id . $base_field_name;
		} else {
			// Standard field name for other blocks
			$field_name = 'srfm-' . $block_type . '-' . $block_id . $base_field_name;
		}

		return $field_name;
	}

	/**
	 * Get the actual field type from block type and slug
	 *
	 * For generic blocks like 'input', the slug contains the actual type (e.g., 'text-field' -> 'text')
	 * For specific blocks like 'email', 'textarea', 'phone', etc., use the block type directly
	 *
	 * @since 1.0.0
	 *
	 * @param string $block_type Block type (e.g., 'input', 'email', 'textarea')
	 * @param string $slug       Field slug (e.g., 'text-field', 'email', 'number-field-1')
	 *
	 * @return string Actual field type
	 */
	protected function get_field_type( $block_type, $slug ) {
		// Specific block types that are their own field type
		$specific_types = array(
			'email',
			'textarea',
			'phone',
			'url',
			'checkbox',
			'dropdown',
			'multi-choice',
			'number',
			'date',
			'time',
			'address',
			'rating',
			'range',
			'file',
			'signature',
		);

		// If block type is a specific type, use it directly
		if ( in_array( $block_type, $specific_types, true ) ) {
			return $block_type;
		}

		// For 'input' block type, extract the actual type from the slug
		// Slug format: 'text-field', 'text-field-1', 'number-field', etc.
		if ( 'input' === $block_type && ! empty( $slug ) ) {
			// Remove trailing numbers and 'field' suffix to get the type
			// 'text-field' -> 'text', 'text-field-1' -> 'text', 'number-field' -> 'number'
			$type = preg_replace( '/-field(-\d+)?$/', '', $slug );
			if ( ! empty( $type ) ) {
				return $type;
			}
		}

		// Fallback to block type
		return $block_type;
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

		$forms = get_posts(
			array(
				'post_type'      => 'sureforms_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'No forms found', 'doublescale') ) );
			return;
		}

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
	 * @param array $form_data Form data array containing:
	 * - form_id: int Form ID
	 * - submission_id: int Entry/Submission ID
	 * - [field_key]: mixed Field values
	 *
	 * @return void
	 */
	public function process( $form_data ) {
		try {
			// Extract form_id and submission_id from form_data
			$form_id       = isset( $form_data['form_id'] ) ? absint( $form_data['form_id'] ) : 0;
			$submission_id = isset( $form_data['submission_id'] ) ? absint( $form_data['submission_id'] ) : 0;

			// Remove internal keys to get only field data
			$submission_data = $form_data;
			unset( $submission_data['form_id'], $submission_data['submission_id'] );

			$data               = $this->get_default_data();
			$data['form_id']    = $form_id;
			$data['entry_id']   = $submission_id;
			$data['fields']     = $this->get_fields( $form_id );
			$data['form_title'] = get_the_title( $form_id );

			$data['entry'] = array(
				'fields' => $this->prepare_form_fields( $submission_data ),
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $data );
			}

			$this->process_automations( $data );
		} catch ( \Exception $e ) {
			// Log error but don't break the form submission
			doublescale_get_logger()->error(
				__( 'Error processing SureForms', 'doublescale'),
				array(
					'code'     => 'sureforms_process_error',
					'form_id'  => $form_id ?? null,
					'entry_id' => $submission_id ?? null,
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
	 * SureForms field keys start with 'srfm-' (e.g., srfm-input-9be4df01-lbl-Rmlyc3QgTmFtZQ-text-field)
	 *
	 * @since 1.0.0
	 *
	 * @param array $submission_data Submitted form data
	 *
	 * @return array
	 */
	public function prepare_form_fields( $submission_data ) {
		$prepared_fields = array();

		if ( empty( $submission_data ) || ! is_array( $submission_data ) ) {
			return $prepared_fields;
		}

		foreach ( $submission_data as $field_key => $value ) {
			// Only include SureForms field data (keys starting with 'srfm-')
			// This includes: srfm-input-*, srfm-email-*, srfm-textarea-*, etc.
			if ( strpos( $field_key, 'srfm-' ) !== 0 ) {
				continue;
			}

			// Handle array values (checkboxes, multi-select, etc.)
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value ) );
			}

			$prepared_fields[ $field_key ] = $value;
		}

		return $prepared_fields;
	}
}
