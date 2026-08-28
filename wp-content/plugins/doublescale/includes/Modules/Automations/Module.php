<?php
/**
 * Automations module bootstrap.
 *
 * Owns: automation models, triggers, actions, goals, rules, conditions, REST,
 * and automation processing. CRM vendor integrations and the WooCommerce
 * abandoned-cart capture/recovery feature are provided by the Pro add-on.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Modules\Automations\Abilities\AutomationAbilities;
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
		return AutomationAbilities::definitions();
	}

	public function slug(): string {
		return 'automations';
	}

	public function label(): string {
		return __( 'Automations', 'doublescale' );
	}

	public function description(): string {
		return __( 'Visual workflow builder with triggers, actions, goals, and conditional rules.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts', 'activities' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\TriggersManager::class,
			static fn() => Services\TriggersManager::instance()
		);

		$container->singleton(
			Services\ActionsManager::class,
			static fn() => Services\ActionsManager::instance()
		);

		$container->singleton(
			Services\GoalsManager::class,
			static fn() => Services\GoalsManager::instance()
		);

		$container->singleton(
			Services\RulesManager::class,
			static fn() => Services\RulesManager::instance()
		);

		$container->singleton(
			Services\VersionManager::class,
			static fn() => Services\VersionManager::instance()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestAutomationController::class,
			Rest\Controllers\RestAutomationStepController::class,
			Rest\Controllers\RestAutomationContactController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		add_filter( 'doublescale_mail_merge_tag_groups', array( $this, 'register_merge_tag_groups' ) );

		$container->get( Services\TriggersManager::class );
		$container->get( Services\ActionsManager::class );
		Loader::instance();
		\DoubleScale\Core\MergeTags\MergeTagsManager::instance();

		add_filter( 'doublescale_automation_triggers', array( $this, 'register_triggers' ) );
		add_filter( 'doublescale_automation_actions', array( $this, 'register_actions' ) );
		add_filter( 'doublescale_automation_goals', array( $this, 'register_goals' ) );

		$this->loadManifestOrGlobs(
			array(
				'includes/Modules/Automations/Triggers/*.php',
				'includes/Modules/Automations/Triggers/Woocommerce/*/*.php',
				'includes/Modules/Automations/Triggers/Learndash/*.php',
				'includes/Modules/Automations/Triggers/Edd/*.php',
				'includes/Modules/Automations/Triggers/Tutorlms/*.php',
				'includes/Modules/Automations/Triggers/Lifterlms/*.php',
				'includes/Modules/Automations/Triggers/Learnpress/*.php',
				'includes/Modules/Automations/Triggers/Memberpress/*.php',
				'includes/Modules/Automations/Triggers/Pmpro/*.php',
				'includes/Modules/Automations/Triggers/Deal/*.php',
				'includes/Modules/Automations/Triggers/Task/*.php',
				'includes/Modules/Automations/Triggers/Project/*.php',
				'includes/Modules/Automations/Triggers/Link/*.php',
				'includes/Modules/Automations/Triggers/Forms/*.php',
				'includes/Modules/Automations/Triggers/Booking/*.php',
				'includes/Modules/Automations/Triggers/Support/*.php',
				'includes/Modules/Automations/Triggers/Sales/*.php',
				'includes/Modules/Automations/Triggers/Surecart/*/*.php',
				'includes/Modules/Automations/Triggers/Prestoplayer/*.php',
				'includes/Modules/Automations/Actions/*.php',
				'includes/Modules/Automations/Actions/Delays/*.php',
				'includes/Modules/Automations/Actions/Woocommerce/*.php',
				'includes/Modules/Automations/Actions/Wordpress/*.php',
				'includes/Modules/Automations/Actions/Crm/Slack/*.php',
				'includes/Modules/Automations/Actions/Learndash/*.php',
				'includes/Modules/Automations/Actions/Webhooks/*.php',
				'includes/Modules/Automations/Actions/Tutorlms/*.php',
				'includes/Modules/Automations/Actions/Lifterlms/*.php',
				'includes/Modules/Automations/Actions/Learnpress/*.php',
				'includes/Modules/Automations/Actions/Memberpress/*.php',
				'includes/Modules/Automations/Actions/Pmpro/*.php',
				'includes/Modules/Automations/Actions/Deal/*.php',
				'includes/Modules/Automations/Actions/Task/*.php',
				'includes/Modules/Automations/Actions/Project/*.php',
				'includes/Modules/Automations/Actions/Support/*.php',
				'includes/Modules/Automations/Actions/Messaging/*.php',
				'includes/Modules/Automations/Actions/Email/*.php',
				'includes/Modules/Automations/Goals/*.php',
				'includes/Modules/Automations/Goals/Surecart/*.php',
				'includes/Modules/Automations/Goals/Woocommerce/*.php',
				'includes/Modules/Automations/Rules/*/*.php',
			),
			'automations_v4'
		);

		$this->load_pro_automation_rule_files_if_available();

		$this->loadMergeTags();
	}

	/**
	 * Pro-only rule classes (Activity, Submission, …) live under the Pro plugin; load them only when this module boots.
	 */
	private function load_pro_automation_rule_files_if_available(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}
		foreach ( array( 'Activity', 'Submission' ) as $subdir ) {
			$dir = \DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Automations/Rules/' . $subdir;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( $dir . '/*.php' ) ?: array() as $file ) {
				require_once $file;
			}
		}
	}

	/**
	 * Eagerly load automation-scoped merge-tag classes (WooCommerce, EDD,
	 * LMS integrations, post context, etc.).
	 */
	private function loadMergeTags(): void {
		$this->loadModuleMergeTagFiles();
	}

	public function register_triggers( $triggers ) {
		$triggers['user_login']       = new Triggers\UserLogin();
		$triggers['user_register']    = new Triggers\UserRegister();
		$triggers['user_role_update'] = new Triggers\UserRoleUpdate();
		return $triggers;
	}

	public function register_actions( $actions ) {
		return $actions;
	}

	public function register_goals( $goals ) {
		return $goals;
	}

	public function register_merge_tag_groups( $groups ) {
		$groups['messaging'] = array(
			'name'      => __( 'Messaging', 'doublescale' ),
			'mergeTags' => isset( $groups['messaging']['mergeTags'] ) ? $groups['messaging']['mergeTags'] : array(),
			'triggers'  => array( 'whatsapp_received', 'sms_received', 'email_received' ),
		);

		// Restrict WordPress User tags to WP user-related triggers.
		// Without `triggers`, the selector shows the group for every automation.
		$wp_user_triggers = array(
			'user_login',
			'user_register',
			'user_role_update',
			'contact_information_updated',
		);

		$groups['wordpress_user'] = array(
			'name'      => __( 'WordPress User', 'doublescale' ),
			'mergeTags' => isset( $groups['wordpress_user']['mergeTags'] ) ? $groups['wordpress_user']['mergeTags'] : array(),
			'triggers'  => $wp_user_triggers,
		);

		$acf_disabled = ! doublescale_is_plugin_active( 'advanced-custom-fields/acf.php' )
			&& ! doublescale_is_plugin_active( 'advanced-custom-fields-pro/acf.php' );

		$groups['acf_user'] = array(
			'name'        => __( 'ACF User Fields', 'doublescale' ),
			'mergeTags'   => isset( $groups['acf_user']['mergeTags'] ) ? $groups['acf_user']['mergeTags'] : array(),
			'triggers'    => $wp_user_triggers,
			'is_disabled' => $acf_disabled,
		);

		return $groups;
	}
}
