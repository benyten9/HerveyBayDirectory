<?php
/**
 * Legacy Pro Forms module — excluded from discovery via
 * {@see doublescale_pro_module_dir_exclude_basenames()}. Pro integrations
 * register through {@see register-pro-integrations.php} so the free module
 * remains canonical.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

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
			\DoubleScale\Modules\Forms\Services\FormsManager::class,
			static fn() => \DoubleScale\Modules\Forms\Services\FormsManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			\DoubleScale\Modules\Forms\Rest\Controllers\RestFormController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$this->loadModuleMergeTagFiles();

		$this->load_pro_form_integration_files();

		add_filter( 'doublescale_forms', array( $this, 'register_forms' ) );

		$container->get( \DoubleScale\Modules\Forms\Services\FormsManager::class );
	}

	/**
	 * Register Pro-only form integrations.
	 *
	 * The 4 free integrations (CF7, WPForms, Fluent Forms, Quill Forms)
	 * are registered by the free Forms module. Pro adds the rest here.
	 */
	public function register_forms( $forms ) {
		// Free integrations — use the free plugin's classes so no code is duplicated.
		$forms['contactform7'] = new \DoubleScale\Modules\Forms\Contactform7\Form();
		$forms['fluentforms']  = new \DoubleScale\Modules\Forms\Fluentforms\Form();
		$forms['quillforms']   = new \DoubleScale\Modules\Forms\Quillforms\Form();
		$forms['wpforms']      = new \DoubleScale\Modules\Forms\Wpforms\Form();

		// Pro-only integrations.
		$pro_forms = array(
			'elementor'      => new Elementor\Form(),
			'formidable'     => new Formidable\Form(),
			'forminator'     => new Forminator\Form(),
			'gravityforms'   => new Gravityforms\Form(),
			'metform'        => new Metform\Form(),
			'ninjaforms'     => new Ninjaforms\Form(),
			'wsform'         => new Wsform\Form(),
			'bitform'        => new Bitform\Form(),
			'sureforms'      => new Sureforms\Form(),
			'eform'          => new Eform\Form(),
			'jetformbuilder' => new Jetformbuilder\Form(),
		);

		foreach ( $pro_forms as $slug => $form ) {
			$form->is_pro = ! (
				function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active()
			);
			$forms[ $slug ] = $form;
		}

		return $forms;
	}

	/**
	 * Require each vendor Form.php so side-effect registration runs before {@see load_forms()}.
	 *
	 * {@see AbstractModule::loadManifestOrGlobs()} resolves paths against the free plugin
	 * (`DOUBLESCALE_PLUGIN_DIR`); Pro form adapters live under {@see DOUBLESCALE_PRO_PLUGIN_DIR}.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_pro_form_integration_files(): void {
		// Load free form integration files first.
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$free_pattern = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Forms/*/Form.php';
			$free_files   = glob( $free_pattern ) ?: array();
			sort( $free_files, SORT_STRING );
			foreach ( $free_files as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					require_once $file;
				}
			}
		}

		// Load Pro form integration files.
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}

		$pattern = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Forms/*/Form.php';
		$files   = glob( $pattern ) ?: array();
		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;
			}
		}
	}
}
