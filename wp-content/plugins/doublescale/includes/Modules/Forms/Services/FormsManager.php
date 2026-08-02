<?php

/**
 * Class Forms Manager
 * This class is responsible for handling the integration of forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use DoubleScale\Modules\Forms\Abstracts\Form;
use DoubleScale\Core\PluginKernel;

/**
 * Forms class
 */
final class FormsManager {


	/**
	 * Registed forms
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $forms = array();

	/**
	 * Options
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * Whether {@see load_forms()} has already run (idempotent guard).
	 *
	 * @var bool
	 */
	private $forms_integrations_resolved = false;

	/**
	 * @deprecated Retained for backward compatibility; prefer container resolution.
	 * @var FormsManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return FormsManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * constructor
	 */
	private function __construct() {
		if ( function_exists( 'did_action' ) && did_action( 'doublescale_ready' ) ) {
			$this->load_forms();
		} else {
			add_action( 'doublescale_ready', array( $this, 'load_forms' ), 5 );
		}
	}

	/**
	 * Register form
	 *
	 * @since 1.0.0
	 *
	 * @param Form $form
	 * @throws Exception If form is not an instance of Form
	 * @return void
	 */
	public function register( $form ) {
		if ( ! $form instanceof Form ) {
			throw new Exception(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
				__( 'Form must be an instance of Form', 'doublescale')
			);
		}

		if ( isset( $this->forms[ $form->slug ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new Exception(
				/* translators: %s: form slug */
				sprintf( __( 'Form with slug %s already exists', 'doublescale'), $form->slug )
			);
		}

		$this->forms[ $form->slug ]   = $form;
		$this->options[ $form->slug ] = array(
			'label'           => $form->name,
			'description'     => $form->description,
			'options'         => $form->get_form_options(),
			'fields_settings' => $form->get_form_fields_settings(),
			'is_enabled'      => $form->is_enabled(),
			'is_pro'          => $form->is_pro,
			'platform'        => $form->platform ?? 'wordpress',
		);
	}

	/**
	 * Get form
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 *
	 * @return Form
	 */
	public function get_form( $slug ) {
		return isset( $this->forms[ $slug ] ) ? $this->forms[ $slug ] : null;
	}

	/**
	 * Get all forms
	 *
	 * @since 1.0.0
	 *
	 * @return Form[]
	 */
	public function get_all_forms() {
		if ( ! $this->forms_integrations_resolved && function_exists( 'did_action' ) && did_action( 'doublescale_ready' ) ) {
			$this->load_forms();
		}
		return $this->forms;
	}

	/**
	 * Load forms
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_forms() {
		if ( $this->forms_integrations_resolved ) {
			return;
		}
		$this->forms_integrations_resolved = true;

		/** @var Form[] $forms */
		$forms = apply_filters( 'doublescale_forms', $this->forms );

		foreach ( $forms as $slug => $form ) {
			$this->forms[ $slug ] = $form;

			$this->options[ $slug ] = array(
				'label'           => $form->name,
				'description'     => $form->description,
				'options'         => $form->get_form_options(),
				'fields_settings' => $form->get_form_fields_settings(),
				'is_enabled'      => $form->is_enabled(),
				'is_pro'          => $form->is_pro,
				'platform'        => $form->platform ?? 'wordpress',
			);

			$form->load_hooks();
		}

		PluginKernel::instance()->forms_tasks->register_callback(
			'process_form',
			array( $this, 'handle_async_form_processing' )
		);
	}

	/**
	 * Get form options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		 return $this->options;
	}

	/**
	 * Handle async form processing
	 *
	 * Callback for the Action Scheduler task that processes form submissions
	 * in the background.
	 *
	 * When called async via Action Scheduler, the ['meta_id' => X] array gets unpacked
	 * so we receive just X (the meta_id) as the first argument.
	 *
	 * @since 1.0.0
	 *
	 * @param array|int $args Task arguments - either meta_id (int) when async, or array with 'meta_id' key (legacy)
	 *
	 * @return void
	 */
	public function handle_async_form_processing( $args ) {
		if ( is_numeric( $args ) ) {
			$meta_id = (int) $args;
		} elseif ( is_array( $args ) && isset( $args['meta_id'] ) ) {
			$meta_id = $args['meta_id'];
		} else {
			doublescale_get_logger()->error(
				__( 'Async form processing failed: missing meta_id', 'doublescale'),
				array(
					'code' => 'async_form_missing_meta_id',
					'args' => $args,
				)
			);
			return;
		}

		$meta_args = doublescale_get_meta_args( $meta_id );

		if ( ! $meta_args ) {
			doublescale_get_logger()->error(
				__( 'Async form processing failed: meta not found', 'doublescale'),
				array(
					'code'    => 'async_form_meta_not_found',
					'meta_id' => $meta_id,
				)
			);
			return;
		}

		$form_data = $meta_args[0] ?? array();

		if ( empty( $form_data ) ) {
			doublescale_get_logger()->error(
				__( 'Async form processing failed: empty form data', 'doublescale'),
				array(
					'code'    => 'async_form_empty_data',
					'meta_id' => $meta_id,
				)
			);
			return;
		}

		$form_slug = $form_data['_form_slug'] ?? '';

		if ( empty( $form_slug ) ) {
			doublescale_get_logger()->error(
				__( 'Async form processing failed: missing form slug', 'doublescale'),
				array(
					'code'    => 'async_form_missing_slug',
					'meta_id' => $meta_id,
				)
			);
			return;
		}

		$form = $this->get_form( $form_slug );

		if ( ! $form ) {
			doublescale_get_logger()->error(
				__( 'Async form processing failed: form not found', 'doublescale'),
				array(
					'code'      => 'async_form_not_found',
					'form_slug' => $form_slug,
					'meta_id'   => $meta_id,
				)
			);
			return;
		}

		$form->restore_form_data( $form_data['_form_settings'] ?? array() );
		$form->process_form_sync( $form_data );
	}
}
