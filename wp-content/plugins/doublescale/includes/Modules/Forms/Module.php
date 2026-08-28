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
		return __( 'Connect form builder plugins to capture leads and trigger automations.', 'doublescale' );
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

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestFormController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$this->load_free_form_integration_files();

		// Priority 5 so Pro can override at the default priority 10.
		add_filter( 'doublescale_forms', array( $this, 'register_forms' ), 5 );

		$container->get( Services\FormsManager::class );
	}

	/**
	 * Register the four free-tier form integrations.
	 *
	 * @param array $forms Registered form integrations keyed by slug.
	 * @return array
	 */
	public function register_forms( $forms ) {
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
}
