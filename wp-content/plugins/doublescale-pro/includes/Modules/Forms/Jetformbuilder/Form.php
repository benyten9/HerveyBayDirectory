<?php

/**
 * Class JetFormBuilder
 * This class is responsible for handling the integration of JetFormBuilder
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\Jetformbuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Forms\Abstracts\Form as Abstracts_Form;

/**
 * JetFormBuilder class
 */
class Form extends Abstracts_Form {

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'jetformbuilder';

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'JetFormBuilder';

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description = 'This will trigger when a form is submitted';

	/**
	 * Non-data blocks that should be excluded from field extraction.
	 *
	 * @var array
	 */
	private $excluded_blocks = array(
		'jet-forms/submit-field',
		'jet-forms/heading-field',
		'jet-forms/progress-bar',
		'jet-forms/form-break-field',
		'jet-forms/form-break-start',
		'jet-forms/group-break-field',
		'jet-forms/captcha-container',
		'jet-forms/map-field',
		'jet-forms/check-mark',
		'jet-forms/action-button',
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/spacer',
		'core/separator',
	);

	/**
	 * Container blocks whose innerBlocks must be recursed into.
	 *
	 * @var array
	 */
	private $container_blocks = array(
		'jet-forms/conditional-block',
		'jet-forms/repeater-field',
		'jet-forms/choices-field',
		'core/columns',
		'core/column',
		'core/group',
	);

	/**
	 * Load Hooks
	 */
	public function load_hooks() {
		add_action( 'jet-form-builder/form-handler/after-send', array( $this, 'process' ), 10, 2 );
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
		return doublescale_is_plugin_active( 'jetformbuilder/jet-form-builder.php' );
	}

	/**
	 * Get Fields
	 *
	 * Parses the Gutenberg block content of a JetFormBuilder form post
	 * to extract all data-collecting field blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	public function get_fields( $form_id ) {
		$fields = array();

		$post = get_post( $form_id );

		if ( empty( $post ) || 'jet-form-builder' !== $post->post_type ) {
			return $fields;
		}

		$blocks = parse_blocks( $post->post_content );

		return $this->extract_fields_from_blocks( $blocks );
	}

	/**
	 * Recursively extract field definitions from parsed blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param array $blocks Parsed block array from parse_blocks().
	 *
	 * @return array
	 */
	protected function extract_fields_from_blocks( $blocks ) {
		$fields = array();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( empty( $block_name ) ) {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$fields = array_merge( $fields, $this->extract_fields_from_blocks( $block['innerBlocks'] ) );
				}
				continue;
			}

			if ( in_array( $block_name, $this->excluded_blocks, true ) ) {
				continue;
			}

			$attrs = $block['attrs'] ?? array();

			if ( in_array( $block_name, $this->container_blocks, true ) ) {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$fields = array_merge( $fields, $this->extract_fields_from_blocks( $block['innerBlocks'] ) );
				}
				continue;
			}

			if ( strpos( $block_name, 'jet-forms/' ) !== 0 ) {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$fields = array_merge( $fields, $this->extract_fields_from_blocks( $block['innerBlocks'] ) );
				}
				continue;
			}

			$field_name = $attrs['name'] ?? '';

			if ( empty( $field_name ) ) {
				continue;
			}

			$field_label = $attrs['label'] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $field_name ) );
			$field_type  = $this->get_field_type( $block_name, $attrs );

			$fields[ $field_name ] = array(
				'label' => $field_label,
				'type'  => $field_type,
			);
		}

		return $fields;
	}

	/**
	 * Resolve the field type from block name and attributes.
	 *
	 * For jet-forms/text-field, the actual type is determined by
	 * attrs.field_type (email, tel, url, password, or text).
	 *
	 * @since 1.0.0
	 *
	 * @param string $block_name Full block name (e.g. jet-forms/text-field).
	 * @param array  $attrs      Block attributes.
	 *
	 * @return string
	 */
	protected function get_field_type( $block_name, $attrs ) {
		if ( 'jet-forms/text-field' === $block_name ) {
			return $attrs['field_type'] ?? 'text';
		}

		$type = str_replace( 'jet-forms/', '', $block_name );
		$type = preg_replace( '/-field$/', '', $type );

		return $type;
	}

	/**
	 * Ajax Get Fields
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_get_fields() {
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'JetFormBuilder is not active', 'doublescale') ) );
			return;
		}

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
		check_ajax_referer( 'doublescale-admin', 'nonce' );

		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'JetFormBuilder is not active', 'doublescale') ) );
			return;
		}

		$forms = get_posts(
			array(
				'post_type'      => 'jet-form-builder',
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

		$options = array();
		foreach ( $forms as $form ) {
			$options[ $form->ID ] = $form->post_title;
		}

		wp_send_json_success( $options );
	}

	/**
	 * Process
	 *
	 * Triggered on jet-form-builder/form-handler/after-send. The Form_Handler
	 * object must NOT be passed to process_form() -- Action Scheduler would
	 * fail to serialize it. Only scalar values are extracted.
	 *
	 * @since 1.0.0
	 *
	 * @param object $handler    JetFormBuilder Form_Handler instance.
	 * @param bool   $is_success Whether the form submission succeeded.
	 *
	 * @return void
	 */
	public function process( $handler, $is_success ) {
		try {
			if ( ! $is_success ) {
				return;
			}

			$form_id = (int) ( $handler->form_id ?? 0 );
			if ( ! $form_id ) {
				return;
			}

			$raw_request  = jet_fb_context()->resolve_request();
			$entry_fields = array();

			foreach ( (array) $raw_request as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, '__' ) ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
				}

				$entry_fields[ $key ] = (string) $value;
			}

			$std_data               = $this->get_default_data();
			$std_data['form_id']    = $form_id;
			$std_data['entry_id']   = null;
			$std_data['form_title'] = get_the_title( $form_id );
			$std_data['fields']     = $this->get_fields( $form_id );
			$std_data['entry']      = array(
				'fields' => $entry_fields,
			);

			if ( $this->is_form_active( $form_id ) ) {
				$this->process_form( $std_data );
			}

			$this->process_automations( $std_data );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				__( 'Error processing JetFormBuilder submission', 'doublescale'),
				array(
					'code'    => 'jetformbuilder_process_error',
					'form_id' => $form_id ?? null,
					'error'   => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
		}
	}
}
