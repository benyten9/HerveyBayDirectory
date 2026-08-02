<?php
/**
 * Lead scoring module bootstrap.
 *
 * Owns migrations, models, REST, segment filters, and score recalculation hooks.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\LeadScoring;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Automations\Rules\LeadScoring\LeadScoreLevel;
use DoubleScale\Modules\Automations\Rules\LeadScoring\LeadScorePoints;
use DoubleScale\Modules\Automations\Services\RulesManager;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'leadscoring';
	}

	public function label(): string {
		return __( 'Lead scoring', 'doublescale' );
	}

	public function description(): string {
		return __( 'Rules, levels, and automatic score updates when contacts engage.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts', 'automations' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			LeadScoringManager::class,
			static fn() => LeadScoringManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			\DoubleScale\Pro\Modules\LeadScoring\Rest\Controllers\RestLeadScoringRuleController::class,
			\DoubleScale\Pro\Modules\LeadScoring\Rest\Controllers\RestLeadScoringRuleLevelController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		LeadScoringManager::instance();
		new EventHandler();

		$this->load_contact_filter_files();

		$this->register_automation_filter_rules();
	}

	/**
	 * Load segment/contact filter classes so `FiltersManager::register()` side effects run.
	 *
	 * {@see AbstractModule::loadManifestOrGlobs()} resolves globs and manifests against the free
	 * plugin (`DOUBLESCALE_PLUGIN_DIR`); these filters live under {@see DOUBLESCALE_PRO_PLUGIN_DIR}
	 * (same constraint as {@see \DoubleScale\Pro\Modules\Forms\Module::load_pro_form_integration_files()}).
	 *
	 * @since 1.0.0
	 */
	private function load_contact_filter_files(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}

		$pattern = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/LeadScoring/Filters/*.php';
		$files   = glob( $pattern ) ?: array();
		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	private function register_automation_filter_rules(): void {
		if ( ! class_exists( RulesManager::class ) ) {
			return;
		}
		RulesManager::instance()->register( new LeadScorePoints() );
		// Level model lives under Pro namespace; wrong FQCN here hid "Level" from Advanced Filters.
		if ( class_exists( \DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleLevelModel::class ) ) {
			RulesManager::instance()->register( new LeadScoreLevel() );
		}
	}
}
