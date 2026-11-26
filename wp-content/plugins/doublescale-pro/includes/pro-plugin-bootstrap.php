<?php
/**
 * Pro add-on: register extra modules on the free kernel and shared container bindings.
 *
 * @package DoubleScale
 */

defined( 'ABSPATH' ) || exit;

/**
 * Module directories that already ship with DoubleScale (free); do not load a second Module.php.
 *
 * @return string[]
 */
function doublescale_pro_module_dir_exclude_basenames(): array {
	return array( 'Activities', 'Automations', 'Campaigns', 'Contacts', 'Forms', 'Tracking' );
}

require_once DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Forms/register-pro-integrations.php';

/**
 * Eager-load Pro core (General) merge tags.
 *
 * These files self-register with MergeTagsManager on include. They used to be
 * loaded by Pro's CoreModule via loadModuleMergeTagFiles(); after Core moved
 * to Free that call disappeared and the General group went empty in the UI.
 */
function doublescale_pro_load_core_merge_tag_files(): void {
	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
		return;
	}

	$dir = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Core/MergeTags';
	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( glob( $dir . '/*.php' ) ?: array() as $file ) {
		require_once $file;
	}
}

add_action(
	'doublescale_ready',
	static function (): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}
		doublescale_pro_load_core_merge_tag_files();
		doublescale_pro_load_form_merge_tag_files();
		doublescale_pro_load_form_integration_files();

		// Register SaaS form webhooks (Typeform, Jotform) directly from automations
		// so those triggers work without a Forms → SaaS Forms connection.
		\DoubleScale\Pro\Modules\Forms\SaasFormAutomationWebhookSync::init();
	},
	15
);

add_filter( 'doublescale_forms', 'doublescale_pro_register_form_integrations', 10 );

add_action( 'loco_file_written', array( \DoubleScale\Pro\I18n\LocoJsonSync::class, 'on_file_written' ) );

add_action(
	'doublescale_register_modules',
	static function ( \DoubleScale\Core\ModuleRegistry $registry ): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}
		$registry->discover(
			DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules',
			doublescale_pro_module_dir_exclude_basenames(),
			'DoubleScale\\Pro\\Modules\\'
		);
	},
	10,
	1
);

add_action(
	'doublescale_register_modules',
	static function (): void {
		$container = \DoubleScale\Core\PluginKernel::instance()->get_container();
		$container->singleton(
			\DoubleScale\Pro\Modules\Deals\Services\PipelineManager::class,
			static function () {
				return \DoubleScale\Pro\Modules\Deals\Services\PipelineManager::instance();
			}
		);
		$container->singleton(
			\DoubleScale\Pro\Modules\Deals\Services\DealManager::class,
			static function () {
				return \DoubleScale\Pro\Modules\Deals\Services\DealManager::instance();
			}
		);
		$container->singleton(
			\DoubleScale\Pro\Modules\Projects\Services\ProjectManager::class,
			static function () {
				return \DoubleScale\Pro\Modules\Projects\Services\ProjectManager::instance();
			}
		);

		// Task rows outlive the Tasks module toggle, so orphan cleanup must not
		// be gated on the module being enabled — register it unconditionally.
		( new \DoubleScale\Pro\Modules\Tasks\Services\TaskCleanup() )->register();
	},
	20
);

add_filter(
	'doublescale_aliases_map',
	static function ( array $base ): array {
		$file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Core/Deprecated/aliases-map.php';
		if ( ! is_readable( $file ) ) {
			return $base;
		}
		$extra = (array) require $file;

		return array_merge( $base, $extra );
	},
	10,
	1
);

if ( ! function_exists( 'doublescale_pro_apply_bc_class_aliases' ) ) {
	/**
	 * Alias legacy FQCNs whose target class is already loaded.
	 *
	 * @param array<string, string> $map Legacy FQCN => current FQCN.
	 * @return void
	 */
	function doublescale_pro_apply_bc_class_aliases( array $map ): void {
		foreach ( $map as $legacy => $current ) {
			if ( ! is_string( $legacy ) || ! is_string( $current ) ) {
				continue;
			}
			if ( class_exists( $legacy, false ) ) {
				continue;
			}
			if ( class_exists( $current, false ) ) {
				class_alias( $current, $legacy );
			}
		}
	}
}

if ( ! function_exists( 'doublescale_pro_register_bc_class_aliases' ) ) {
	/**
	 * Register legacy FQCN aliases from {@see doublescale_aliases_map}.
	 *
	 * Eager autoload of every mapped Pro class breaks boot: many Pro classes extend
	 * free/legacy parents that are not available until modules have booted.
	 *
	 * @return void
	 */
	function doublescale_pro_register_bc_class_aliases(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$map = apply_filters( 'doublescale_aliases_map', array() );

		spl_autoload_register(
			static function ( string $class ) use ( $map ): void {
				if ( ! isset( $map[ $class ] ) || class_exists( $class, false ) ) {
					return;
				}
				$current = $map[ $class ];
				if ( ! class_exists( $current, true ) || class_exists( $class, false ) ) {
					return;
				}
				class_alias( $current, $class );
			},
			true,
			true
		);

		doublescale_pro_apply_bc_class_aliases( $map );
	}
}

add_action( 'plugins_loaded', 'doublescale_pro_register_bc_class_aliases', 20 );

add_action(
	'doublescale_modules_booted',
	static function (): void {
		$map = apply_filters( 'doublescale_aliases_map', array() );
		doublescale_pro_apply_bc_class_aliases( $map );
	},
	999
);

add_action(
	'doublescale_modules_booted',
	static function (): void {
		if ( class_exists( \DoubleScale\Modules\Campaigns\Abstracts\AbstractCampaignProcessing::class, false ) ) {
			\DoubleScale\Pro\Modules\Campaigns\Automated\AutomatedCampaignsFeature::instance();
			\DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::instance();
			// Pro's own WhatsApp engine owns WhatsApp wherever free can hand it
			// over (free 1.7.0+); an older free keeps running its own engine.
			\DoubleScale\Pro\Modules\Campaigns\Whatsapp\WhatsappEngine::register();
			// Keeps WhatsApp campaigns inside the account's Meta tier (off
			// against a free that cannot pause, or still ships its own copy).
			\DoubleScale\Pro\Modules\Campaigns\Whatsapp\MetaTierGuard::register();
			add_action(
				'rest_api_init',
				static function (): void {
					( new \DoubleScale\Pro\Modules\Campaigns\Whatsapp\RestWhatsappLimitsController() )->register_routes();
				}
			);
			$container = \DoubleScale\Core\PluginKernel::instance()->get_container();
			$container->singleton(
				\DoubleScale\Pro\Modules\Campaigns\Sequences\EmailSequencesManager::class,
				static function () {
					return \DoubleScale\Pro\Modules\Campaigns\Sequences\EmailSequencesManager::instance();
				}
			);
			\DoubleScale\Pro\Modules\Campaigns\Sequences\EmailSequencesManager::instance();
			add_action(
				'rest_api_init',
				static function (): void {
					( new \DoubleScale\Pro\Modules\Campaigns\Sequences\RestEmailSequenceController() )->register_routes();
				}
			);
			add_action(
				'init',
				static function (): void {
					if ( get_transient( 'doublescale_register_tasks_lock_email_sequences_pro' ) ) {
						return;
					}
					set_transient( 'doublescale_register_tasks_lock_email_sequences_pro', 1, MINUTE_IN_SECONDS );
					$tasks = new \DoubleScale\Core\Tasks( 'doublescale_campaigns' );
					if ( $tasks->get_next_timestamp( 'doublescale_email_sequences' ) === false ) {
						$tasks->schedule_recurring( time(), 60, 'doublescale_email_sequences' );
					}
				},
				25
			);
		}
		if ( class_exists( \DoubleScale\Modules\Tracking\Abstracts\AbstractTracking::class, false ) ) {
			\DoubleScale\Pro\Modules\Campaigns\Sms\SmsTracking::instance();
			// Pro's WhatsApp receiver answers Meta wherever free can hand it over.
			\DoubleScale\Pro\Modules\Campaigns\Whatsapp\WhatsappEngine::register_tracking();
		}
	},
	20
);

add_action(
	'doublescale_register_modules',
	static function (): void {
		if ( class_exists( 'DoubleScale\\Managers\\CustomFieldsManager', false ) ) {
			return;
		}
		if ( ! class_exists( \DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager::class, true ) ) {
			return;
		}
		class_alias(
			\DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager::class,
			'DoubleScale\\Managers\\CustomFieldsManager'
		);
	},
	30
);

/**
 * Pro-only automation triggers/actions/goals: definitions live in the free plugin catalog + stubs;
 * implementations are instantiated here when the Pro add-on is active.
 *
 * The catalog must be read when filters run (or lazily cached after success), not when this file is
 * first parsed: `DOUBLESCALE_PLUGIN_DIR` is defined by the free plugin, which may load after Pro
 * depending on `active_plugins` order. Reading the catalog too early yields an empty list forever
 * inside `use ( $catalog )` closures.
 *
 * @return array{triggers?: array<int, string>, actions?: array<int, string>, goals?: array<int, string>}
 */
if ( ! function_exists( 'doublescale_pro_get_automation_catalog' ) ) {
	function doublescale_pro_get_automation_catalog(): array {
		static $cached = null;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return array(
				'triggers' => array(),
				'actions'  => array(),
				'goals'    => array(),
			);
		}
		$file = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Automations/Config/ProAutomationCatalog.php';
		if ( ! is_readable( $file ) ) {
			return array(
				'triggers' => array(),
				'actions'  => array(),
				'goals'    => array(),
			);
		}
		$cached = (array) require $file;

		return $cached;
	}
}

/**
 * Pro modules' data models for doublescale_module_data_ready() (free): the
 * table that stands for each module's data.
 *
 * @return array<string, class-string>
 */
function doublescale_pro_module_data_models(): array {
	return array(
		'contracts'          => \DoubleScale\Pro\Modules\Contracts\Models\ContractModel::class,
		'credit_notes'       => \DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel::class,
		'deals'              => \DoubleScale\Pro\Modules\Deals\Models\DealModel::class,
		'leadscoring'        => \DoubleScale\Pro\Modules\LeadScoring\Models\LeadScoringRuleModel::class,
		'product_catalog'    => \DoubleScale\Pro\Modules\ProductCatalog\Models\ProductModel::class,
		'projects'           => \DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class,
		'recurring_invoices' => \DoubleScale\Pro\Modules\RecurringInvoices\Models\InvoiceRecurrenceModel::class,
		'tasks'              => \DoubleScale\Pro\Modules\Tasks\Models\TaskModel::class,
		'websitetracking'    => \DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel::class,
	);
}

add_filter(
	'doublescale_module_data_models',
	static function ( $models ) {
		return array_merge( (array) $models, doublescale_pro_module_data_models() );
	}
);

/**
 * Whether Support automation catalog entries may register (module on + tables exist).
 */
if ( ! function_exists( 'doublescale_pro_is_module_automation_storage_ready' ) ) {
	function doublescale_pro_is_module_automation_storage_ready( string $module_slug, string $model_class ): bool {
		if ( function_exists( 'doublescale_is_module_storage_ready' ) ) {
			return doublescale_is_module_storage_ready( $module_slug, $model_class );
		}

		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( $module_slug );
	}
}

if ( ! function_exists( 'doublescale_pro_is_support_automation_available' ) ) {
	function doublescale_pro_is_support_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'support',
			\DoubleScale\Modules\Support\Models\MailboxModel::class
		);
	}
}

if ( ! function_exists( 'doublescale_pro_is_sales_automation_available' ) ) {
	function doublescale_pro_is_sales_automation_available(): bool {
		return function_exists( 'doublescale_automation_modules_available' )
			&& doublescale_automation_modules_available( array( 'sales', 'documents' ) );
	}
}

if ( ! function_exists( 'doublescale_pro_is_contracts_automation_available' ) ) {
	function doublescale_pro_is_contracts_automation_available(): bool {
		return function_exists( 'doublescale_automation_modules_available' )
			&& doublescale_automation_modules_available( array( 'sales', 'contracts' ) );
	}
}

if ( ! function_exists( 'doublescale_pro_is_credit_notes_automation_available' ) ) {
	function doublescale_pro_is_credit_notes_automation_available(): bool {
		return function_exists( 'doublescale_automation_modules_available' )
			&& doublescale_automation_modules_available( array( 'sales', 'credit_notes' ) );
	}
}

if ( ! function_exists( 'doublescale_pro_is_deals_automation_available' ) ) {
	function doublescale_pro_is_deals_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'deals',
			\DoubleScale\Pro\Modules\Deals\Models\PipelineModel::class
		);
	}
}

if ( ! function_exists( 'doublescale_pro_is_tasks_automation_available' ) ) {
	function doublescale_pro_is_tasks_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'tasks',
			\DoubleScale\Pro\Modules\Tasks\Models\TaskModel::class
		);
	}
}

/**
 * Whether cart recovery runs: it is part of Automations. Cart tracking, its
 * WooCommerce checkout hooks, routes, conditions and merge tags read the
 * abandoned-carts table, which exists only while Automations is on.
 */
if ( ! function_exists( 'doublescale_pro_is_abandoned_carts_available' ) ) {
	function doublescale_pro_is_abandoned_carts_available(): bool {
		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'automations' );
	}
}

if ( ! function_exists( 'doublescale_pro_is_projects_automation_available' ) ) {
	function doublescale_pro_is_projects_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'projects',
			\DoubleScale\Pro\Modules\Projects\Models\ProjectModel::class
		);
	}
}

if ( ! function_exists( 'doublescale_pro_is_booking_automation_available' ) ) {
	function doublescale_pro_is_booking_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'booking',
			\DoubleScale\Modules\Booking\Models\BookingModel::class
		);
	}
}

if ( ! function_exists( 'doublescale_pro_is_forms_automation_available' ) ) {
	function doublescale_pro_is_forms_automation_available(): bool {
		return doublescale_pro_is_module_automation_storage_ready(
			'forms',
			\DoubleScale\Modules\Forms\Models\FormModel::class
		);
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_support' ) ) {
	function doublescale_pro_automation_class_is_support( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Support\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Support\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_sales' ) ) {
	function doublescale_pro_automation_class_is_sales( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Sales\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Sales\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_contracts_sales' ) ) {
	function doublescale_pro_automation_class_is_contracts_sales( string $class ): bool {
		return false !== strpos( $class, '\\Triggers\\Sales\\Contract' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_credit_notes_sales' ) ) {
	function doublescale_pro_automation_class_is_credit_notes_sales( string $class ): bool {
		return false !== strpos( $class, '\\Triggers\\Sales\\CreditNote' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_deal' ) ) {
	function doublescale_pro_automation_class_is_deal( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Deal\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Deal\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_task' ) ) {
	function doublescale_pro_automation_class_is_task( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Task\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Task\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_project' ) ) {
	function doublescale_pro_automation_class_is_project( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Project\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Project\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_booking' ) ) {
	function doublescale_pro_automation_class_is_booking( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Booking\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Booking\\' );
	}
}

/**
 * @param string $class FQCN from {@see doublescale_pro_get_automation_catalog()}.
 */
if ( ! function_exists( 'doublescale_pro_automation_class_is_forms' ) ) {
	function doublescale_pro_automation_class_is_forms( string $class ): bool {
		return false !== strpos( $class, '\\Automations\\Actions\\Forms\\' )
			|| false !== strpos( $class, '\\Automations\\Triggers\\Forms\\' );
	}
}

add_filter(
	'doublescale_automation_triggers',
	static function ( array $triggers ): array {
		$catalog         = doublescale_pro_get_automation_catalog();
		$support_enabled   = doublescale_pro_is_support_automation_available();
		$sales_enabled     = doublescale_pro_is_sales_automation_available();
		$contracts_enabled = doublescale_pro_is_contracts_automation_available();
		$credit_notes_enabled = doublescale_pro_is_credit_notes_automation_available();
		$deals_enabled     = doublescale_pro_is_deals_automation_available();
		$tasks_enabled     = doublescale_pro_is_tasks_automation_available();
		$projects_enabled  = doublescale_pro_is_projects_automation_available();
		$booking_enabled = doublescale_pro_is_booking_automation_available();
		$forms_enabled   = doublescale_pro_is_forms_automation_available();
		foreach ( $catalog['triggers'] ?? array() as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			if ( ! $support_enabled && doublescale_pro_automation_class_is_support( $class ) ) {
				continue;
			}
			if ( doublescale_pro_automation_class_is_contracts_sales( $class ) ) {
				if ( ! $contracts_enabled ) {
					continue;
				}
			} elseif ( doublescale_pro_automation_class_is_credit_notes_sales( $class ) ) {
				if ( ! $credit_notes_enabled ) {
					continue;
				}
			} elseif ( ! $sales_enabled && doublescale_pro_automation_class_is_sales( $class ) ) {
				continue;
			}
			if ( ! $deals_enabled && doublescale_pro_automation_class_is_deal( $class ) ) {
				continue;
			}
			if ( ! $tasks_enabled && doublescale_pro_automation_class_is_task( $class ) ) {
				continue;
			}
			if ( ! $projects_enabled && doublescale_pro_automation_class_is_project( $class ) ) {
				continue;
			}
			if ( ! $booking_enabled && doublescale_pro_automation_class_is_booking( $class ) ) {
				continue;
			}
			if ( ! $forms_enabled && doublescale_pro_automation_class_is_forms( $class ) ) {
				continue;
			}
			$instance = new $class();
			// Replace any same-slug free definition (e.g. TriggerPro form stubs) with the Pro runtime trigger.
			$triggers[ $instance->slug ] = $instance;
		}
		return $triggers;
	},
	100,
	1
);

add_filter(
	'doublescale_automation_actions',
	static function ( array $actions ): array {
		$catalog         = doublescale_pro_get_automation_catalog();
		$support_enabled   = doublescale_pro_is_support_automation_available();
		$sales_enabled     = doublescale_pro_is_sales_automation_available();
		$contracts_enabled = doublescale_pro_is_contracts_automation_available();
		$credit_notes_enabled = doublescale_pro_is_credit_notes_automation_available();
		$deals_enabled     = doublescale_pro_is_deals_automation_available();
		$tasks_enabled     = doublescale_pro_is_tasks_automation_available();
		$projects_enabled  = doublescale_pro_is_projects_automation_available();
		$booking_enabled = doublescale_pro_is_booking_automation_available();
		$forms_enabled   = doublescale_pro_is_forms_automation_available();
		foreach ( $catalog['actions'] ?? array() as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			if ( ! $support_enabled && doublescale_pro_automation_class_is_support( $class ) ) {
				continue;
			}
			if ( doublescale_pro_automation_class_is_contracts_sales( $class ) ) {
				if ( ! $contracts_enabled ) {
					continue;
				}
			} elseif ( doublescale_pro_automation_class_is_credit_notes_sales( $class ) ) {
				if ( ! $credit_notes_enabled ) {
					continue;
				}
			} elseif ( ! $sales_enabled && doublescale_pro_automation_class_is_sales( $class ) ) {
				continue;
			}
			if ( ! $deals_enabled && doublescale_pro_automation_class_is_deal( $class ) ) {
				continue;
			}
			if ( ! $tasks_enabled && doublescale_pro_automation_class_is_task( $class ) ) {
				continue;
			}
			if ( ! $projects_enabled && doublescale_pro_automation_class_is_project( $class ) ) {
				continue;
			}
			if ( ! $booking_enabled && doublescale_pro_automation_class_is_booking( $class ) ) {
				continue;
			}
			if ( ! $forms_enabled && doublescale_pro_automation_class_is_forms( $class ) ) {
				continue;
			}
			$instance = new $class();
			$actions[ $instance->slug ] = $instance;
		}
		return $actions;
	},
	100,
	1
);

add_filter(
	'doublescale_automation_goals',
	static function ( array $goals ): array {
		$catalog = doublescale_pro_get_automation_catalog();
		foreach ( $catalog['goals'] ?? array() as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}
			$instance = new $class();
			$goals[ $instance->slug ] = $instance;
		}
		return $goals;
	},
	100,
	1
);

/**
 * Expose the "Helpdesk" trigger source/group so the support lifecycle triggers
 * (registered via the automation catalog) render under DoubleScale → Helpdesk.
 * Gated on the support module being active.
 */
add_filter(
	'doublescale_automation_trigger_sources',
	static function ( array $sources ): array {
		if ( ! isset( $sources['modules']['tabs']['support']['groups']['support'] ) ) {
			return $sources;
		}

		$disabled = ! function_exists( 'doublescale_is_module_active' )
			|| ! doublescale_is_module_active( 'support' );

		$sources['modules']['tabs']['support']['groups']['support']['is_disabled'] = $disabled;

		return $sources;
	},
	10,
	1
);

/**
 * Restrict the Helpdesk merge-tag group to support lifecycle triggers only.
 * Without `triggers`, the UI treats the group as global and shows it for every
 * automation (see enhanced-selector group filter: !group.triggers).
 */
add_filter(
	'doublescale_mail_merge_tag_groups',
	static function ( array $groups ): array {
		$disabled = ! function_exists( 'doublescale_is_module_active' )
			|| ! doublescale_is_module_active( 'support' );

		$groups['support'] = array(
			'name'        => __( 'Helpdesk', 'doublescale' ),
			'mergeTags'   => isset( $groups['support']['mergeTags'] ) ? $groups['support']['mergeTags'] : array(),
			'triggers'    => array(
				'ticket_created',
				'ticket_reply_added',
				'ticket_note_added',
				'ticket_status_changed',
				'ticket_priority_changed',
				'ticket_agent_assigned',
				'ticket_closed',
			),
			'is_disabled' => $disabled,
		);

		return $groups;
	},
	10,
	1
);

/**
 * Register Pro email blocks on Free's BlockRegistry.
 *
 * Mirrors the React-side pattern where Pro's client/index.tsx calls
 * `registerBlocks({...})` to extend Free's blocks-registry store. Here Pro
 * extends the PHP-side BlockRegistry via the same
 * `doublescale_mail_block_register` action that Free's
 * BlockRegistry::register_default_blocks() fires.
 *
 * If Pro isn't installed/active, these types are absent and
 * BlockRegistry::render_block() returns '' (graceful fallback).
 */
add_action(
	'doublescale_mail_block_register',
	static function ( \DoubleScale\Modules\Emails\BlockRegistry $registry ): void {
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\BannerBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\DividerBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\HtmlBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\ImageBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\MenuBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\PreheaderBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\ProductBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\SocialMediaBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\TableBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\TimerBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\VideoBlock() );
		$registry->register_block( new \DoubleScale\Pro\Modules\Emails\Blocks\PostsBlock() );
	},
	10,
	1
);

/**
 * Builder endpoints for the Latest Posts email block (post types, terms, preview).
 */
add_action( 'rest_api_init', array( \DoubleScale\Pro\Modules\Emails\Rest\RestLatestPostsController::class, 'register_routes' ) );

/**
 * Public countdown GIF for the email timer block.
 *
 * Gmail (and most inbox clients) do not execute JavaScript, so the timer
 * cannot tick as HTML/CSS. Recipients fetch this image through the same
 * query-string pattern as the open-tracking pixel; remaining time is
 * computed at fetch and the GIF animates for up to 60 seconds.
 */
add_action(
	'init',
	static function (): void {
		\DoubleScale\Pro\Modules\Emails\Services\TimerGifEndpoint::maybe_serve();
	},
	0
);

/**
 * Abandoned-cart feature wiring (moved from free in 2026).
 *
 * The moved files keep the original `DoubleScale\Modules\Automations\...`
 * namespaces, which are NOT covered by Pro's Composer PSR-4 map
 * (`DoubleScale\Pro\` → includes/). They are loaded explicitly here so the
 * runtime hooks fire and the self-registering merge tags + rules slot into
 * their respective managers on file include.
 */
add_action(
	'doublescale_register_modules',
	static function (): void {
		$container = \DoubleScale\Core\PluginKernel::instance()->get_container();
		$container->singleton(
			'tasks.abandoned_cart',
			static function () {
				return new \DoubleScale\Core\Tasks( 'doublescale_abandoned_cart' );
			}
		);
	},
	5
);

add_action(
	'doublescale_ready',
	static function (): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}

		$base = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Automations/';

		// Eloquent model (no side effects on include).
		$model_file = $base . 'Models/AbandonedCartModel.php';
		if ( is_readable( $model_file ) ) {
			require_once $model_file;
		}

		// Conditions (rules) and merge tags register themselves when their file is
		// included. They are read only when a message is rendered, an automation
		// checks a condition or the builder lists them, so the files are included
		// on first read (Free's DefersRegistration). Older Free: include now.
		$carts = doublescale_pro_is_abandoned_carts_available();
		$rules = $carts ? array( 'Rules/Cart/*.php' ) : array();
		$tags  = $carts ? array( 'MergeTags/Woocommerce/AbandonedCart/*.php' ) : array();

		// Support automation conditions + merge tags. Gated on the support module
		// so the rule/merge-tag groups only register when the feature is on.
		if ( function_exists( 'doublescale_pro_is_support_automation_available' )
			&& doublescale_pro_is_support_automation_available() ) {
			$rules[] = 'Rules/Support/*.php';
			$tags[]  = 'MergeTags/Support/*.php';
		}

		// Sales proposal/invoice automation conditions. Gated like Support above.
		if ( function_exists( 'doublescale_pro_is_sales_automation_available' )
			&& doublescale_pro_is_sales_automation_available() ) {
			$rules[] = 'Rules/Proposal/*.php';
			$rules[] = 'Rules/Invoice/*.php';
		}

		// Contract automation conditions.
		if ( function_exists( 'doublescale_pro_is_contracts_automation_available' )
			&& doublescale_pro_is_contracts_automation_available() ) {
			$rules[] = 'Rules/Contract/*.php';
		}

		// Credit note automation conditions.
		if ( function_exists( 'doublescale_pro_is_credit_notes_automation_available' )
			&& doublescale_pro_is_credit_notes_automation_available() ) {
			$rules[] = 'Rules/CreditNote/*.php';
		}

		// Task automation conditions. Gated like Support above.
		if ( function_exists( 'doublescale_pro_is_tasks_automation_available' )
			&& doublescale_pro_is_tasks_automation_available() ) {
			$rules[] = 'Rules/Task/*.php';
		}

		// Project automation conditions.
		if ( function_exists( 'doublescale_pro_is_projects_automation_available' )
			&& doublescale_pro_is_projects_automation_available() ) {
			$rules[] = 'Rules/Project/*.php';
		}

		$include = static function ( array $patterns ) use ( $base ): void {
			foreach ( $patterns as $pattern ) {
				foreach ( glob( $base . $pattern ) ?: array() as $file ) {
					require_once $file;
				}
			}
		};
		$defer   = static function ( $manager, callable $loader ): void {
			if ( method_exists( $manager, 'defer' ) ) {
				$manager->defer( $loader );
			} else {
				$loader();
			}
		};

		$defer(
			\DoubleScale\Modules\Automations\Services\RulesManager::instance(),
			static function () use ( $include, $rules ): void {
				$include( $rules );
			}
		);
		$defer(
			\DoubleScale\Core\MergeTags\MergeTagsManager::instance(),
			static function () use ( $include, $tags ): void {
				$include( $tags );
			}
		);

		// Service class — registers WC hooks, AJAX handlers, scheduled tasks.
		// Only while cart recovery is available: its checkout hooks read the
		// abandoned-carts table.
		$service_file = $base . 'AbandonedCart/AbandonedCart.php';
		if ( $carts && is_readable( $service_file ) ) {
			require_once $service_file;
			\DoubleScale\Modules\Automations\AbandonedCart\AbandonedCart::instance();
		}
	},
	20
);

add_action(
	'rest_api_init',
	static function (): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}
		$controller_file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Automations/Rest/Controllers/RestAbandonedCartController.php';
		if ( doublescale_pro_is_abandoned_carts_available() && is_readable( $controller_file ) ) {
			require_once $controller_file;
			( new \DoubleScale\Modules\Automations\Rest\Controllers\RestAbandonedCartController() )->register_routes();
		}

		// Inbound webhook receiver for Pro automations. Migrated from the
		// free plugin so the free build no longer ships a public REST route.
		// It starts automations, so it exists only while Automations is on.
		$webhook_controller_file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Automations/Rest/Controllers/RestWebhookController.php';
		if ( function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'automations' )
			&& is_readable( $webhook_controller_file ) ) {
			require_once $webhook_controller_file;
			( new \DoubleScale\Modules\Automations\Rest\Controllers\RestWebhookController() )->register_routes();
		}
	}
);

/**
 * One-time upgrade: the pipeline (`deals`) became a child of the free Sales
 * module, and `sales` defaults to off. Installs that were running the pipeline
 * before the split would silently lose it — turn the parent on for them.
 *
 * Runs on `doublescale_ready` so `ModuleManager::register_hooks()` is already
 * wired: the option update fires the normal lifecycle (Sales migrations + role
 * provisioning + dependent pipeline migrations). Modules already booted this
 * request, so Sales REST routes appear from the next request — acceptable for
 * a one-time upgrade step.
 */
add_action(
	'doublescale_ready',
	static function (): void {
		if ( get_option( 'doublescale_sales_parent_toggle_migrated' ) ) {
			return;
		}
		update_option( 'doublescale_sales_parent_toggle_migrated', 1, false );

		$stored = get_option( 'doublescale_enabled_modules', array() );
		if ( ! is_array( $stored ) || array_key_exists( 'sales', $stored ) ) {
			return;
		}
		if ( empty( $stored['deals'] ) ) {
			return;
		}

		$stored['sales'] = true;
		update_option( 'doublescale_enabled_modules', $stored );
	},
	30
);

add_action(
	'doublescale_ready',
	static function (): void {
		\DoubleScale\Pro\Website\Updater::instance();
	},
	100
);

/*
 * "Projects" contact filters (campaign audiences, segments): "CMS is WordPress"
 * picks the clients of WordPress projects. Registered here, not in the Projects
 * module, so saved rules still match nobody while that module is off.
 */
\DoubleScale\Pro\Modules\Projects\Filters\ProjectContactFilters::init();

/*
 * Real-time push through relay.doublescale.io (live chat, live screens).
 * Licence-gated: only while the licence is active. Not a module: always on.
 */
\DoubleScale\Pro\Core\Realtime\Realtime::register();
