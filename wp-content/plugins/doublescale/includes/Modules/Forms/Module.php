<?php
/**
 * Forms module bootstrap.
 *
 * Owns: form integrations (per-vendor adapters), FormsManager, form models,
 * submissions migration, REST form controller.
 *
 * Free baseline ships four integrations (Contact Form 7, WPForms, Fluent Forms,
 * Quill Forms). Pro extends via the `doublescale_forms` filter at priority 10.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Modules\Forms\Abilities\FormAbilities;
use DoubleScale\Core\Container;

final class Module extends AbstractModule implements ProvidesAbilities {

	/**
	 * Read-only abilities for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return FormAbilities::definitions();
	}

	public function slug(): string {
		return 'forms';
	}

	public function label(): string {
		return __( 'Forms', 'doublescale' );
	}

	public function description(): string {
		return __( 'Build forms that send leads straight to your CRM, or connect your form plugin.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\FormsManager::class,
			static fn() => Services\FormsManager::instance()
		);
	}

	/**
	 * Recurring tasks, unscheduled when the module is switched off.
	 *
	 * @return array
	 */
	public function scheduledHooks(): array {
		return array(
			array( Native\Housekeeping::GROUP, Native\Housekeeping::HOOK ),
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestFormController::class,
			Rest\Controllers\RestFormSubmissionsController::class,
			Native\Rest\RestPublicFormController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$this->load_free_form_integration_files();

		// Priority 5 so Pro can override at the default priority 10.
		add_filter( 'doublescale_forms', array( $this, 'register_forms' ), 5 );

		$container->get( Services\FormsManager::class );

		// DoubleScale's own forms: shortcode, block, form pages and the submit pipeline's
		// background step.
		Native\Renderer::init();
		// Unfinished entries are off for now: no "Form abandoned" trigger in the editor.
		add_filter(
			'doublescale_automation_trigger_sources_offered',
			static function ( $sources ) {
				return Native\Partial::available() ? $sources : self::without_trigger( (array) $sources, 'doublescale_form_abandoned' );
			}
		);
		// Website tracking (Pro) hands over entries sent before the visitor was known.
		add_action( 'doublescale_tracking_claim_form_entries', array( Native\Submission::class, 'claim_entries' ), 10, 2 );
		if ( did_action( 'doublescale_ready' ) ) {
			Native\Form::register_tasks();
		} else {
			add_action( 'doublescale_ready', array( Native\Form::class, 'register_tasks' ), 6 );
		}
		Native\Housekeeping::hooks();

		// {{forms:link 12}} for emails, SMS and WhatsApp.
		$this->loadModuleMergeTagFiles();
	}

	/**
	 * Register the four free-tier form integrations.
	 *
	 * @param array $forms Registered form integrations keyed by slug.
	 * @return array
	 */
	public function register_forms( $forms ) {
		$forms[ Native\Form::SLUG ] = new Native\Form();
		$forms['contactform7'] = new Contactform7\Form();
		$forms['fluentforms']  = new Fluentforms\Form();
		$forms['quillforms']   = new Quillforms\Form();
		$forms['wpforms']      = new Wpforms\Form();
		return $forms;
	}

	/**
	 * Require each free vendor Form.php so classes are available before
	 * {@see FormsManager::load_forms()} runs.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_free_form_integration_files(): void {
		$pattern = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Forms/*/Form.php';
		$files   = glob( $pattern ) ?: array();
		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Trigger sources without one trigger (wherever it sits in the groups).
	 *
	 * @param array  $sources Trigger sources.
	 * @param string $slug    Trigger slug.
	 * @return array
	 */
	private static function without_trigger( array $sources, string $slug ): array {
		unset( $sources[ $slug ] );
		foreach ( $sources as $key => $value ) {
			if ( is_array( $value ) ) {
				$sources[ $key ] = self::without_trigger( $value, $slug );
			}
		}
		return $sources;
	}
}
