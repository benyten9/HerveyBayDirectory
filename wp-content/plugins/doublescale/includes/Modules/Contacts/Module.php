<?php
/**
 * Contacts module bootstrap.
 *
 * Owns: contacts, contact meta, lists, tags, segments (contact filters),
 * import/export, unsubscribe page. Lead scoring lives in the Lead Scoring module.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Abilities\ProvidesAbilities;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Contacts\Abilities\ContactAbilities;

final class Module extends AbstractModule implements ProvidesAbilities {

	public function slug(): string {
		return 'contacts';
	}

	/**
	 * Read-only contact abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return ContactAbilities::definitions();
	}

	public function label(): string {
		return __( 'Contacts', 'doublescale' );
	}

	public function description(): string {
		return __( 'Contact management, lists, tags, segments, and import/export.', 'doublescale' );
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function version(): string {
		return '1.0.0';
	}

	public function dependencies(): array {
		return array( 'core' );
	}

	public function register( Container $container ): void {
		$container->singleton(
			Filters\FiltersManager::class,
			static fn() => Filters\FiltersManager::instance()
		);

		$container->singleton(
			ImportExport\Importers\Manager::class,
			static fn() => ImportExport\Importers\Manager::instance()
		);

		$container->singleton(
			Services\ContactAttachmentActivityLogger::class,
			static fn() => new Services\ContactAttachmentActivityLogger()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestContactController::class,
			Rest\Controllers\RestListController::class,
			Rest\Controllers\RestTagController::class,
			Rest\Controllers\RestImportExportController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		$container->get( Filters\FiltersManager::class );
		$container->get( ImportExport\Importers\Manager::class );
		$container->get( Services\ContactAttachmentActivityLogger::class )->register();
		Services\ContactMergeNotifier::register();

		add_action(
			'doublescale_ready',
			static function () {
				if ( class_exists( \DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::class ) ) {
					\DoubleScale\Modules\Integrations\Gohighlevel\GohighlevelOauth::init();
				}
			},
			15
		);

		$this->loadModuleMergeTagFiles();

		$this->loadManifestOrGlobs(
			array( 'includes/Modules/Contacts/Filters/*/*.php' ),
			'contacts-filters'
		);

		$this->load_contact_scoped_automation_rules();

		/**
		 * Register the form-submission contact filter after the full kernel boot.
		 *
		 * The filter class file loads with the glob above, but registration must depend on the
		 * Pro `forms` module being active. Doing this on {@see 'doublescale_ready'} guarantees
		 * module registry + enabled flags are final before {@see \DoubleScale\Admin\AdminConfig}
		 * reads {@see FiltersManager::get_groups()} on admin pages.
		 */
		add_action(
			'doublescale_ready',
			static function (): void {
				if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'forms' ) ) {
					return;
				}
				if ( ! class_exists( Filters\Submission\FormSubmission::class, true ) ) {
					return;
				}
				$mgr = Filters\FiltersManager::instance();
				if ( array_key_exists( 'form_submission', $mgr->get_filters() ) ) {
					return;
				}
				try {
					$mgr->register( new Filters\Submission\FormSubmission() );
				} catch ( \Throwable $e ) {
					// Duplicate registration or invalid state — do not break bootstrap.
				}
			},
			20
		);

		/**
		 * Register contact-scoped custom field filters after Pro Custom Fields is available.
		 *
		 * The filter class loads with the contacts filters glob, but registration must run once
		 * module storage is ready and scoped fields can be queried reliably.
		 */
		add_action(
			'doublescale_ready',
			static function (): void {
				if ( ! class_exists( Filters\ContactFields\Fields::class, true ) ) {
					return;
				}
				if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class, true ) ) {
					return;
				}
				if ( function_exists( 'doublescale_is_module_storage_ready' )
					&& ! doublescale_is_module_storage_ready( 'custom-fields', \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::class ) ) {
					return;
				}

				$mgr = Filters\FiltersManager::instance();

				try {
					$custom_fields = \DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel::where( 'scope', 'contact' )->get();
					foreach ( $custom_fields as $custom_field ) {
						$slug = 'contact_field_' . $custom_field->id;
						if ( array_key_exists( $slug, $mgr->get_filters() ) ) {
							continue;
						}
						$mgr->register( new Filters\ContactFields\Fields( $custom_field ) );
					}
				} catch ( \Throwable $e ) {
					// Tables may not exist yet before migrations.
				}
			},
			20
		);

		/**
		 * Register Activity filters that query Pro-owned tables only when their owning
		 * modules are active. `page_visits` lives under `websitetracking` (Pro, non-toggleable);
		 * `form_submissions` lives under `forms` (Pro, toggleable). Hiding the filters at
		 * registration time keeps them out of segment builder UIs and prevents SQL errors
		 * against missing tables on Free-standalone installs or when modules are disabled.
		 */
		add_action(
			'doublescale_ready',
			static function (): void {
				if ( ! function_exists( 'doublescale_is_module_active' ) ) {
					return;
				}

				$mgr = Filters\FiltersManager::instance();

				if ( doublescale_is_module_active( 'websitetracking' )
					&& class_exists( Filters\Activity\PageVisited::class, true )
					&& ! array_key_exists( 'activity_page_visited', $mgr->get_filters() ) ) {
					try {
						$mgr->register( new Filters\Activity\PageVisited() );
					} catch ( \Throwable $e ) {
						// Duplicate registration or invalid state — do not break bootstrap.
					}
				}

				// "Was Active" sums activities + form_submissions + page_visits. Only register
				// when at least one of the Pro tables it depends on is available; apply() will
				// skip any missing table at query time as a defense-in-depth fallback.
				$has_websitetracking = doublescale_is_module_active( 'websitetracking' );
				$has_forms           = doublescale_is_module_active( 'forms' );
				if ( ( $has_websitetracking || $has_forms )
					&& class_exists( Filters\Activity\WasActiveInactive::class, true ) ) {
					try {
						if ( ! array_key_exists( 'activity_was_active', $mgr->get_filters() ) ) {
							$mgr->register( new Filters\Activity\WasActiveInactive( 'Was Active', 'was_active', 'activity_was_active' ) );
						}
						if ( ! array_key_exists( 'activity_was_not_active', $mgr->get_filters() ) ) {
							$mgr->register( new Filters\Activity\WasActiveInactive( 'Was Not Active', 'was_not_active', 'activity_was_not_active' ) );
						}
					} catch ( \Throwable $e ) {
						// Duplicate registration or invalid state — do not break bootstrap.
					}
				}
			},
			20
		);
	}

	/**
	 * Load condition rules that describe *contact state*, independently of the
	 * `automations` module's lifecycle.
	 *
	 * These rule files live under `Modules/Automations/Rules/` for historical
	 * reasons, but what they describe — a contact's email, city, status, tags,
	 * lists, custom fields — is contact data, not automation runtime state.
	 * They were only ever loaded inside {@see \DoubleScale\Modules\Automations\Module::boot()},
	 * which made them disappear whenever a user turned the automations module off.
	 *
	 * That mattered because Lead Scoring is non-toggleable: it keeps running and
	 * evaluating its conditions through
	 * {@see \DoubleScale\Modules\Automations\Conditions\Condition::is_condition_fulfilled()},
	 * which resolves each condition via `RulesManager::get_rule()` and `continue`s
	 * past anything it cannot resolve — leaving `$result` at its initial `true`.
	 * So every lead-scoring condition silently became a no-op that reported
	 * "matched", and contacts were awarded points they had not earned, with no
	 * error and no notice.
	 *
	 * Only the contact-scoped directories load here. Automation-runtime rules
	 * (`Rules/Automation/*` — "entered automation", "completed automation") stay
	 * behind the automations module, because they are meaningless without it.
	 * Integration rules (WooCommerce, LMS, …) likewise stay put.
	 *
	 * Each file calls `RulesManager::instance()->register(...)` on include, and
	 * `register()` returns early on a duplicate slug, so loading here and again
	 * from the automations module is harmless.
	 *
	 * @since 1.0.0
	 */
	private function load_contact_scoped_automation_rules(): void {
		$this->loadManifestOrGlobs(
			array(
				'includes/Modules/Automations/Rules/Contact/*.php',
				'includes/Modules/Automations/Rules/ContactFields/*.php',
				'includes/Modules/Automations/Rules/Segments/*.php',
			),
			'contact-scoped-automation-rules'
		);

		// Activity rules ("last email opened", "was active") are contact state
		// too, but ship in Pro. Mirrors the Pro-directory handling in
		// {@see \DoubleScale\Modules\Automations\Module::load_pro_automation_rule_files_if_available()}.
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}

		$activity_dir = \DOUBLESCALE_PRO_PLUGIN_DIR
			. 'includes/Modules/Automations/Rules/Activity';

		if ( ! is_dir( $activity_dir ) ) {
			return;
		}

		foreach ( glob( $activity_dir . '/*.php' ) ?: array() as $file ) {
			require_once $file;
		}
	}
}
