<?php
/**
 * Bricks Builder forms integration.
 *
 * Works like the Elementor integration: the site owner picks the page and the
 * form in DoubleScale and maps its fields there. Nothing has to be set inside
 * Bricks.
 *
 * Bricks has no documented hook that fires once a submission has passed every
 * check. `bricks/form/response` fires at the very end of a successful submit,
 * but only when an action left a result behind; a plain form with only the
 * email action never reaches it. So the last `bricks/form/validate` callback
 * leaves an empty success result on the form. That guarantees the response
 * filter runs, and since Bricks bails out with an error response before it on
 * any failed check or failed action, only accepted submissions get here.
 *
 * @since 1.5.4
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Bricks;

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;
use DoubleScale\Modules\Automations\Models\AutomationModel;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks form integration.
 */
class Form extends Abstracts_Form {

	/**
	 * Result type marker left on a Bricks form.
	 */
	const RESULT_ACTION = 'doublescale';

	/**
	 * Field types that carry no answer worth keeping on a contact.
	 */
	const SKIPPED_FIELD_TYPES = array( 'html', 'password', 'rememberme' );

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'bricks';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Bricks';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'This will trigger when a Bricks form is submitted';

	/**
	 * Page, post or template the form is on. Set before {@see get_fields()} by
	 * the admin endpoints, as for Elementor.
	 *
	 * @var int|string
	 */
	public $post_id;

	/**
	 * The Bricks form instance that passed validation in this request.
	 *
	 * @var object|null
	 */
	private $pending_form = null;

	/**
	 * Load hooks.
	 */
	public function load_hooks() {
		add_filter( 'bricks/form/validate', array( $this, 'mark_submission' ), PHP_INT_MAX, 2 );
		add_filter( 'bricks/form/response', array( $this, 'handle_response' ), 10, 2 );

		add_action( "wp_ajax_doublescale_{$this->slug}_get_fields", array( $this, 'ajax_get_fields' ) );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_form_select_options", array( $this, 'ajax_get_form_select_options' ) );
		add_action( "wp_ajax_doublescale_{$this->slug}_get_source_select_options", array( $this, 'ajax_get_source_select_options' ) );
	}

	/**
	 * Bricks is a theme: on `plugins_loaded`, when forms load, its constants do
	 * not exist yet, so the active template is checked too (covers child themes).
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return defined( 'BRICKS_VERSION' ) || 'bricks' === get_template();
	}

	/**
	 * Remember the submission and make sure Bricks reaches its response filter.
	 *
	 * @param array  $errors Validation errors so far.
	 * @param object $form   Bricks form instance (\Bricks\Integrations\Form\Init).
	 * @return array Errors, unchanged.
	 */
	public function mark_submission( $errors, $form = null ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'set_result' ) || ! method_exists( $form, 'get_id' ) ) {
			return $errors;
		}

		$this->pending_form = $form;

		// No message and no flags: Bricks' success response is unchanged.
		$form->set_result(
			array(
				'type'   => 'success',
				'action' => self::RESULT_ACTION,
			)
		);

		return $errors;
	}

	/**
	 * The submission was accepted: hand it to DoubleScale.
	 *
	 * @param array  $response Bricks' JSON response.
	 * @param object $form     Bricks form instance.
	 * @return array Response, unchanged.
	 */
	public function handle_response( $response, $form = null ) {
		if ( null === $this->pending_form || $form !== $this->pending_form ) {
			return $response;
		}
		$this->pending_form = null;

		try {
			$this->process( $form );
		} catch ( \Throwable $e ) {
			// A CRM failure must never turn the visitor's submission into an error.
			doublescale_get_logger()->error(
				__( 'Error processing Bricks form submission', 'doublescale' ),
				array(
					'code'  => 'bricks_form_error',
					'error' => $e->getMessage(),
				)
			);
		}

		return $response;
	}

	/**
	 * Build the submission and run the form connection and the automations.
	 *
	 * @param object $form Bricks form instance.
	 * @return void
	 */
	public function process( $form ) {
		$element_id = (string) $form->get_id();
		$post_id    = method_exists( $form, 'get_post_id' ) ? absint( $form->get_post_id() ) : 0;
		$source_id  = $this->resolve_source_id( $post_id, $element_id );
		$settings   = method_exists( $form, 'get_settings' ) ? (array) $form->get_settings() : array();
		$submitted  = method_exists( $form, 'get_fields' ) ? (array) $form->get_fields() : array();

		$element = Utils::get_form( $element_id, $source_id );
		if ( empty( $element ) ) {
			$element = array(
				'id'       => $element_id,
				'settings' => $settings,
			);
		}

		$definitions = $this->field_definitions( $settings['fields'] ?? ( $element['settings']['fields'] ?? array() ) );

		$fields  = array();
		$answers = array();
		foreach ( $definitions as $field_id => $field ) {
			$fields[ $field_id ]  = $field['label'];
			$answers[ $field_id ] = $this->answer( $form, $field_id, $field['type'], $field['input'], $submitted );
		}

		$data               = $this->get_default_data();
		$data['form_id']    = $element_id;
		$data['post_id']    = $source_id;
		$data['form_title'] = Utils::get_form_label( $element );
		$data['fields']     = $fields;
		$data['entry_id']   = null;
		$data['entry']      = array( 'fields' => $answers );

		if ( $this->is_form_active( $source_id . ':' . $element_id ) ) {
			$this->process_form( $data );
		}

		$this->process_automations( $data );
	}

	/**
	 * The post that stores the form: the page itself, or the header, footer or
	 * template it was placed in. Connections are keyed by it, so a form in a
	 * header matches on every page it shows on.
	 *
	 * @param int    $post_id    Page the visitor submitted from.
	 * @param string $element_id Form element ID.
	 * @return int
	 */
	private function resolve_source_id( $post_id, $element_id ) {
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_element_data' ) ) {
			$data = \Bricks\Helpers::get_element_data( $post_id, $element_id );
			if ( is_array( $data ) ) {
				foreach ( array( 'source_post_id', 'source_id' ) as $key ) {
					if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && absint( $data[ $key ] ) > 0 ) {
						return absint( $data[ $key ] );
					}
				}
			}
		}

		return $post_id;
	}

	/**
	 * Field ID => label and type, from the form's `fields` repeater.
	 *
	 * @param mixed $fields Repeater rows.
	 * @return array<string, array{label: string, type: string}>
	 */
	private function field_definitions( $fields ) {
		$definitions = array();
		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, self::SKIPPED_FIELD_TYPES, true ) ) {
				continue;
			}
			$definitions[ (string) $field['id'] ] = array(
				'label' => Utils::get_field_label( $field ),
				'type'  => $type,
				// Bricks posts the field under its "Name" attribute when one is set.
				'input' => self::input_name( $field ),
			);
		}

		return $definitions;
	}

	/**
	 * One answer as plain text. Choices with several values are joined.
	 *
	 * @param object $form      Bricks form instance.
	 * @param string $field_id  Field ID.
	 * @param string $type      Field type.
	 * @param string $input     Key the field is posted under.
	 * @param array  $submitted Raw submitted values.
	 * @return string
	 */
	private function answer( $form, $field_id, $type, $input, $submitted ) {
		if ( 'file' === $type ) {
			return $this->file_answer( $form, $field_id, $input );
		}

		// Bricks copies a named field's answer to form-field-{id}, so that key
		// comes first; the Name attribute is the fallback for a Bricks that
		// does not copy it.
		$value = '';
		foreach ( $this->input_keys( $field_id, $input, $form ) as $key ) {
			if ( array_key_exists( $key, $submitted ) ) {
				$value = $submitted[ $key ];
				break;
			}
		}

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ), 'strlen' ) );
			return $value;
		}

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( (string) $value );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * A file answer: the file's address when Bricks kept it (media library or
	 * a folder), else its name. Without a storage setting Bricks deletes the
	 * upload before its response filter runs, and a link would lead nowhere.
	 *
	 * @param object $form     Bricks form instance.
	 * @param string $field_id Field ID.
	 * @param string $input    Key the field is posted under.
	 * @return string
	 */
	private function file_answer( $form, $field_id, $input ) {
		$uploaded = method_exists( $form, 'get_uploaded_files' ) ? (array) $form->get_uploaded_files() : array();
		$files    = array();
		foreach ( $this->input_keys( $field_id, $input, $form ) as $key ) {
			if ( ! empty( $uploaded[ $key ] ) ) {
				$files = (array) $uploaded[ $key ];
				break;
			}
		}
		$uploads  = wp_upload_dir();
		$values   = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = (string) ( $file['file'] ?? '' );
			$url  = '';
			if ( ! empty( $file['attachment_id'] ) ) {
				$url = (string) wp_get_attachment_url( (int) $file['attachment_id'] );
			} elseif ( '' !== $path && file_exists( $path ) && 0 === strpos( $path, $uploads['basedir'] ) ) {
				$url = str_replace( $uploads['basedir'], $uploads['baseurl'], $path );
			}

			$values[] = '' !== $url ? esc_url_raw( $url ) : sanitize_file_name( (string) ( $file['name'] ?? basename( $path ) ) );
		}

		return implode( ', ', array_filter( $values, 'strlen' ) );
	}

	/**
	 * Keys a field's answer or upload can sit under, in the order to try:
	 * form-field-{id}, the Name attribute as typed, and the Name attribute with
	 * its dynamic data rendered (as Bricks posts it), both normalised the way
	 * PHP turns dots and spaces in a request key into underscores.
	 *
	 * @param string $field_id Field ID.
	 * @param string $input    Name attribute key, or form-field-{id}.
	 * @param object $form     Bricks form instance.
	 * @return string[]
	 */
	private function input_keys( $field_id, $input, $form ) {
		$keys = array( "form-field-{$field_id}", $input );

		if ( false !== strpos( $input, '{' ) && function_exists( 'bricks_render_dynamic_data' ) ) {
			$post_id = method_exists( $form, 'get_post_id' ) ? (int) $form->get_post_id() : 0;
			$keys[]  = (string) bricks_render_dynamic_data( $input, $post_id );
		}

		$keys = array_map(
			static function ( $key ) {
				return strtr( ltrim( (string) $key, ' ' ), '. ', '__' );
			},
			$keys
		);

		return array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
	}

	/**
	 * The key a field is posted under when it has a "Name" attribute (PHP drops
	 * a trailing [] from the key), else Bricks' form-field-{id}. Uploads are
	 * kept under it; answers are read from form-field-{id} first.
	 *
	 * @param array $field Field from the form's `fields` repeater.
	 * @return string
	 */
	private static function input_name( $field ) {
		$name = trim( (string) ( $field['name'] ?? '' ) );
		if ( '[]' === substr( $name, -2 ) ) {
			$name = substr( $name, 0, -2 );
		}

		return '' !== $name ? $name : 'form-field-' . $field['id'];
	}

	/**
	 * Connections are stored as "{post ID}:{element ID}" and looked up whole.
	 *
	 * @param string $form_id Form ID.
	 * @return string
	 */
	public function get_form_id( $form_id ) {
		return $form_id;
	}

	/**
	 * An automation runs for its form, and for its page when one is set: the
	 * same element ID can exist on two pages when a page was duplicated.
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Submission.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		if ( (string) $automation->get_setting( 'form_id' ) !== (string) ( $args['form_id'] ?? '' ) ) {
			return false;
		}

		$post_id = absint( $automation->get_setting( 'post_id' ) );

		return ! $post_id || absint( $args['post_id'] ?? 0 ) === $post_id;
	}

	/**
	 * Form fields for mapping.
	 *
	 * @param string $form_id Element ID.
	 * @return array<string, array{label: string, type: string}>
	 */
	public function get_fields( $form_id ) {
		$form   = Utils::get_form( $form_id, $this->post_id );
		$fields = array();
		foreach ( $this->field_definitions( $form['settings']['fields'] ?? array() ) as $id => $field ) {
			$fields[ $id ] = array(
				'label' => $field['label'],
				'type'  => $field['type'],
			);
		}

		return $fields;
	}

	/**
	 * Ajax: fields of one form.
	 *
	 * @return void
	 */
	public function ajax_get_fields() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid source ID', 'doublescale' ) );
		}

		if ( '' === $form_id ) {
			wp_send_json_error( __( 'Invalid form ID', 'doublescale' ) );
		}

		$this->post_id = $post_id;

		wp_send_json_success( $this->get_fields( $form_id ) );
	}

	/**
	 * Ajax: pages, posts and templates that hold a Bricks form.
	 *
	 * @return void
	 */
	public function ajax_get_source_select_options() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		wp_send_json_success( Utils::get_pages() );
	}

	/**
	 * Ajax: forms on one page.
	 *
	 * @return void
	 */
	public function ajax_get_form_select_options() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid source ID', 'doublescale' ) );
		}

		wp_send_json_success( Utils::get_forms_by_page_id( $post_id ) );
	}

	/**
	 * Pickers: page first, then the form on it.
	 *
	 * @return array
	 */
	public function get_form_options() {
		return array(
			'post_id' => array(
				'label'       => __( 'Page', 'doublescale' ),
				'type'        => 'ajax_select',
				'help'        => __( 'The page, post or Bricks template the form is on', 'doublescale' ),
				'ajax_action' => "doublescale_{$this->slug}_get_source_select_options",
			),
			'form_id' => array(
				'label'       => __( 'Form', 'doublescale' ),
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
	}

	/**
	 * Fields endpoint settings.
	 *
	 * @return array
	 */
	public function get_form_fields_settings() {
		return array(
			'action' => "doublescale_{$this->slug}_get_fields",
			'fields' => array(
				'post_id',
				'form_id',
			),
		);
	}
}
