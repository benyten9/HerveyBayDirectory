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
use DoubleScale\Core\Fields\Types\Boolean_Field;


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
		return doublescale_is_plugin_active( 'quillforms' );
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
	 * A Legal (consent) block answers with its own label ("I accept"), which a yes/no contact field
	 * would read as "no". Map the yes label to true and everything else to false.
	 *
	 * @param mixed  $value           The answer (the block's yes or no label).
	 * @param string $source_field_id The Quill Forms block id.
	 * @param array  $source_field    The block's definition (label, type).
	 * @param array  $target_field    The contact field's definition.
	 * @return mixed
	 */
	protected function normalize_mapped_value( $value, $source_field_id, $source_field, $target_field ) {
		if ( 'legal' !== ( $source_field['type'] ?? '' ) || ! is_a( $target_field['type'] ?? '', Boolean_Field::class, true ) ) {
			return $value;
		}

		$form_id = $this->submission['form_id'] ?? null;
		if ( ! $form_id ) {
			return $value;
		}

		$yes_label = ( new FormUtils( $form_id ) )->get_field_attributes( $source_field_id )['yesLabel'] ?? null;

		return null !== $yes_label && '' !== (string) $value && (string) $value === (string) $yes_label ? 'true' : 'false';
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
		// This runs inside Quill Forms' "entry processed" hook, after the entry is saved: whatever
		// goes wrong here must not turn the visitor's submission into an error page.
		try {
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
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Quill Forms: could not process a submission',
				array(
					'code'     => 'quillforms_process_failed',
					'form_id'  => $entry->form_id ?? null,
					'entry_id' => $entry->ID ?? null,
					'error'    => $e->getMessage(),
				)
			);
		}
	}
}
