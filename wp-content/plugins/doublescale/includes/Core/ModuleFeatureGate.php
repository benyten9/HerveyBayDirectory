<?php
/**
 * Module / feature gate helpers (DoubleScale free — canonical definitions).
 * Pro extends via {@see 'doublescale_module_slug_to_class_map'} and related filters.
 *
 * @package DoubleScale
 */

defined( 'ABSPATH' ) || exit;

/**
 * Modules registered on the free application kernel (includes Pro modules discovered onto it).
 *
 * @return array<string, \DoubleScale\Core\ModuleInterface>
 */
function doublescale_kernel_registry_modules(): array {
	if ( ! class_exists( \DoubleScale\Core\PluginKernel::class, false ) ) {
		return array();
	}
	try {
		return \DoubleScale\Core\PluginKernel::instance()->get_module_registry()->all();
	} catch ( \Throwable $e ) {
		return array();
	}
}

/**
 * Discovered Module class map (free modules). Pro merges via filter.
 *
 * @return array<string, class-string<\DoubleScale\Core\ModuleInterface>>
 */
function doublescale_module_slug_to_class_map(): array {
	$cached = \DoubleScale\Core\ModuleRequestCache::get_slug_class_map();
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$base = array();
	foreach ( doublescale_kernel_registry_modules() as $slug => $module ) {
		$base[ $slug ] = get_class( $module );
	}

	if ( array() === $base && defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
		$root = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/';
		foreach ( (array) glob( $root . '*', GLOB_ONLYDIR ) as $dir ) {
			$basename = basename( $dir );
			$class    = 'DoubleScale\\Modules\\' . $basename . '\\Module';
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class();
			if ( ! $module instanceof \DoubleScale\Core\ModuleInterface ) {
				continue;
			}
			$base[ $module->slug() ] = $class;
		}
	}

	$filtered = apply_filters( 'doublescale_module_slug_to_class_map', $base );
	\DoubleScale\Core\ModuleRequestCache::set_slug_class_map( $filtered );

	return $filtered;
}

/**
 * Module slugs that ship in DoubleScale Pro and can be toggled while Pro is inactive.
 * Values persist in {@see 'doublescale_enabled_modules'} so enabling Pipelines (etc.)
 * before Pro loads still applies once Pro registers the real module class.
 *
 * @return string[]
 */
function doublescale_phantom_module_toggle_slugs(): array {
	$slugs = array(
		'analytics',
		'deals',
		'inbox',
		'integrations',
		'leadscoring',
		'credit_notes',
		'contracts',
		'product_catalog',
		'tasks',
		'projects',
	);

	return array_values( array_unique( apply_filters( 'doublescale_phantom_module_toggle_slugs', $slugs ) ) );
}

/**
 * @param string $slug Module slug.
 */
function doublescale_is_phantom_module_toggle_slug( string $slug ): bool {
	return in_array( $slug, doublescale_phantom_module_toggle_slugs(), true );
}

// `doublescale_sales_documents_ready()` lives in includes/Core/functions.php and
// returns true whenever the Sales module is active (the Documents/Portal feature
// has shipped). The temporary WP_DEBUG release-gate that previously lived here was
// removed on merge to avoid a duplicate definition; do not re-add it here.

/**
 * Child module slug => parent module slug.
 *
 * A child is a sub-feature with its own toggle nested inside the parent's:
 * its effective state is `parent active AND own stored intent`, and the
 * intent DEFAULTS TO ON when the key is absent (the child follows the parent
 * until the user opts out). The pipeline (`deals`, Pro) is a child of the
 * free Sales module.
 *
 * @return array<string, string>
 */
function doublescale_child_module_parent_map(): array {
	$map = array(
		'deals'           => 'sales',
		'documents'       => 'sales',
		'contracts'       => 'sales',
		'subscriptions'   => 'sales',
		'credit_notes'    => 'sales',
		'product_catalog' => 'sales',
	);

	/**
	 * @param array<string, string> $map Child slug => parent slug.
	 */
	return (array) apply_filters( 'doublescale_child_module_parent_map', $map );
}

/**
 * Whether a Sales document sub-feature (proposals, invoices, contracts) is active.
 *
 * @param string $slug Child slug (`proposals`, `invoices`, or `contracts`).
 */
function doublescale_sales_child_module_active( string $slug ): bool {
	return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( $slug );
}

/**
 * True when at least one Sales document sub-feature is enabled.
 */
function doublescale_any_sales_document_module_active(): bool {
	return doublescale_sales_child_module_active( 'documents' )
		|| doublescale_sales_child_module_active( 'contracts' );
}

/**
 * Stored intent for a module slug: the raw option flag, falling back to the
 * slug's default when no key exists (children default on, other toggleables
 * default off). This deliberately ignores the parent gate — the settings UI
 * shows the child's remembered position even while the parent is off.
 *
 * @param string               $slug       Module slug.
 * @param array<string, mixed> $stored     Normalized `doublescale_enabled_modules` array.
 * @param bool                 $toggleable Whether the slug is user-toggleable.
 */
function doublescale_module_setting_enabled( string $slug, array $stored, bool $toggleable ): bool {
	if ( array_key_exists( $slug, $stored ) ) {
		return (bool) $stored[ $slug ];
	}
	if ( ! $toggleable ) {
		return true;
	}
	if ( 'documents' === $slug ) {
		$proposals = ! array_key_exists( 'proposals', $stored ) || (bool) $stored['proposals'];
		$invoices  = ! array_key_exists( 'invoices', $stored ) || (bool) $stored['invoices'];

		return $proposals || $invoices;
	}

	return array_key_exists( $slug, doublescale_child_module_parent_map() );
}

/**
 * Label + description for REST / admin config when the Pro module class is not loaded.
 *
 * @param string $slug Module slug.
 * @return array{label: string, description: string, dependencies?: array<int, string>}|null
 */
function doublescale_phantom_module_admin_meta( string $slug ): ?array {
	switch ( $slug ) {
		case 'analytics':
			return array(
				'label'       => __( 'Analytics', 'doublescale' ),
				'description' => __( 'Advanced reporting, dashboards, and revenue analytics.', 'doublescale' ),
			);
		case 'deals':
			return array(
				'label'        => __( 'Pipelines & Deals', 'doublescale' ),
				'description'  => __( 'Manage sales pipelines, deal stages, and track deal progress.', 'doublescale' ),
				// Mirrors the real Pro module's dependencies() so the pre-Pro
				// upsell row nests under Sales identically in the settings UI.
				'dependencies' => array( 'contacts', 'sales' ),
			);
		case 'inbox':
			return array(
				'label'       => __( 'Inbox', 'doublescale' ),
				'description' => __( 'Unified messaging inbox for email, SMS, and WhatsApp conversations.', 'doublescale' ),
			);
		case 'integrations':
			return array(
				'label'       => __( 'Integrations', 'doublescale' ),
				'description' => __( 'Third-party integrations for Twilio, Slack, Meta WhatsApp, and more.', 'doublescale' ),
			);
		case 'leadscoring':
			return array(
				'label'       => __( 'Lead scoring', 'doublescale' ),
				'description' => __( 'Score contacts from behavior and profile data for prioritization.', 'doublescale' ),
			);
		case 'credit_notes':
			return array(
				'label'        => __( 'Credit Notes', 'doublescale' ),
				'description'  => __( 'Issue credit notes, apply credit to invoices, and track open customer balances.', 'doublescale' ),
				'dependencies' => array( 'contacts', 'sales' ),
			);
		case 'contracts':
			return array(
				'label'        => __( 'Contracts', 'doublescale' ),
				'description'  => __( 'Manage customer contracts, types, attachments, and e-signatures.', 'doublescale' ),
				'dependencies' => array( 'contacts', 'sales' ),
			);
		case 'product_catalog':
			return array(
				'label'        => __( 'Products', 'doublescale' ),
				'description'  => __( 'Save reusable products and services, then insert them as line items on invoices, proposals, and credit notes.', 'doublescale' ),
				'dependencies' => array( 'contacts', 'sales' ),
			);
		case 'tasks':
			return array(
				'label'       => __( 'Tasks', 'doublescale' ),
				'description' => __( 'Create tasks, due dates, and reminders linked to contacts and deals.', 'doublescale' ),
			);
		case 'projects':
			return array(
				'label'        => __( 'Projects', 'doublescale' ),
				'description'  => __( 'Manage projects with kanban statuses, tasks, and linked invoices.', 'doublescale' ),
				'dependencies' => array( 'core', 'contacts' ),
			);
		default:
			return null;
	}
}

/**
 * Whether a phantom slug is enabled (same option semantics as {@see AbstractModule::is_enabled()},
 * with child slugs additionally gated on their parent and defaulting to the
 * parent's state — mirrors the real Pro module's derived `is_enabled()`).
 *
 * @param string               $slug   Module slug.
 * @param array<string, mixed> $stored Normalized `doublescale_enabled_modules` array.
 */
function doublescale_phantom_module_is_enabled( string $slug, array $stored ): bool {
	$parents = doublescale_child_module_parent_map();

	if ( isset( $parents[ $slug ] ) ) {
		$intent = ! array_key_exists( $slug, $stored ) || (bool) $stored[ $slug ];
		$intent = (bool) apply_filters( 'doublescale_module_enabled_' . $slug, $intent );

		return $intent && doublescale_is_module_active( $parents[ $slug ] );
	}

	$default = array_key_exists( $slug, $stored ) && (bool) $stored[ $slug ];

	return (bool) apply_filters( 'doublescale_module_enabled_' . $slug, $default );
}

/**
 * Merged module rows for REST and `window.doublescaleConfig.modules`.
 *
 * @param array<string, \DoubleScale\Core\ModuleInterface> $all Registered modules.
 * @return array<int, array<string, mixed>>
 */
function doublescale_build_modules_list_payload( array $all ): array {
	$stored = get_option( 'doublescale_enabled_modules', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$result = array();

	foreach ( $all as $slug => $module ) {
		$deps = array_filter(
			$module->dependencies(),
			static function ( $d ) {
				return 'core' !== $d;
			}
		);

		$enabled = $module->is_enabled();

		$result[] = array(
			'slug'            => $slug,
			'label'           => $module->label(),
			'description'     => $module->description(),
			'enabled'         => $enabled,
			'active'          => $enabled,
			// Stored intent without the parent gate — a child toggle keeps its
			// remembered position in the settings UI while its parent is off.
			'setting_enabled' => doublescale_module_setting_enabled( $slug, $stored, $module->is_toggleable() ),
			'is_toggleable'   => $module->is_toggleable(),
			'is_explicit'     => array_key_exists( $slug, $stored ),
			'dependencies'    => array_values( $deps ),
		);
	}

	foreach ( doublescale_phantom_module_toggle_slugs() as $slug ) {
		if ( isset( $all[ $slug ] ) ) {
			continue;
		}
		$meta = doublescale_phantom_module_admin_meta( $slug );
		if ( null === $meta ) {
			continue;
		}
		$enabled  = doublescale_phantom_module_is_enabled( $slug, $stored );
		$result[] = array(
			'slug'            => $slug,
			'label'           => $meta['label'],
			'description'     => $meta['description'],
			'enabled'         => $enabled,
			'active'          => $enabled,
			'setting_enabled' => doublescale_module_setting_enabled( $slug, $stored, true ),
			'is_toggleable'   => true,
			'is_explicit'     => array_key_exists( $slug, $stored ),
			'dependencies'    => isset( $meta['dependencies'] ) ? (array) $meta['dependencies'] : array(),
		);
	}

	// Standalone-plugin modules (e.g. Subscriptions) always get a row so the admin
	// SPA can authoritatively gate their routes/sidebar via
	// `config.isModuleToggleEnabled()` — even when the owning plugin is INACTIVE,
	// in which case `enabled` is false and the SPA redirects away (no stub upsell).
	// `is_toggleable: false` keeps them out of the Modules settings UI: activation
	// is owned by the Plugins screen, not a toggle here.
	foreach ( doublescale_standalone_plugin_module_slugs() as $slug ) {
		if ( isset( $all[ $slug ] ) ) {
			continue;
		}
		$meta     = doublescale_standalone_plugin_module_meta( $slug );
		$enabled  = doublescale_is_module_active( $slug );
		$result[] = array(
			'slug'            => $slug,
			'label'           => $meta['label'],
			'description'     => $meta['description'],
			'enabled'         => $enabled,
			'active'          => $enabled,
			'setting_enabled' => $enabled,
			'is_toggleable'   => false,
			'is_explicit'     => array_key_exists( $slug, $stored ),
			'dependencies'    => isset( $meta['dependencies'] ) ? (array) $meta['dependencies'] : array(),
		);
	}

	return $result;
}

/**
 * Module slugs that ship as their own standalone WordPress plugin: activation is
 * owned by the Plugins screen, so they never render a toggle in
 * DoubleScale → Modules. They DO appear in the modules payload (as a
 * non-toggleable row) so the admin SPA can gate their routes/sidebar.
 *
 * @return string[]
 */
function doublescale_standalone_plugin_module_slugs(): array {
	$slugs = array(
		'subscriptions',
	);

	/**
	 * @param string[] $slugs Standalone-plugin module slugs.
	 */
	return (array) apply_filters( 'doublescale_standalone_plugin_module_slugs', $slugs );
}

/**
 * Label + description for a standalone-plugin module row when its module class
 * is not registered (plugin inactive).
 *
 * @param string $slug Module slug.
 * @return array{label: string, description: string, dependencies?: array<int, string>}
 */
function doublescale_standalone_plugin_module_meta( string $slug ): array {
	if ( 'subscriptions' === $slug ) {
		return array(
			'label'        => __( 'Subscriptions', 'doublescale' ),
			'description'  => __( 'Recurring Stripe billing — auto-charge customers each cycle and record a child invoice per charge.', 'doublescale' ),
			'dependencies' => array( 'contacts', 'sales' ),
		);
	}

	return array(
		'label'       => ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ),
		'description' => '',
	);
}

/**
 * Whether a discovered module is active (same storage as {@see ModuleInterface::is_enabled()}).
 * Unknown slugs return true so third-party groups are not stripped by mistake.
 *
 * @param string $slug Module slug.
 */
function doublescale_is_module_active( string $slug ): bool {
	$cached = \DoubleScale\Core\ModuleRequestCache::get_enabled( $slug );
	if ( null !== $cached ) {
		return $cached;
	}

	$live = doublescale_kernel_registry_modules()[ $slug ] ?? null;
	if ( $live instanceof \DoubleScale\Core\ModuleInterface ) {
		$v = $live->is_enabled();
		\DoubleScale\Core\ModuleRequestCache::set_enabled( $slug, $v );

		return $v;
	}

	$classes = doublescale_module_slug_to_class_map();
	if ( ! isset( $classes[ $slug ] ) ) {
		if ( doublescale_is_phantom_module_toggle_slug( $slug ) ) {
			$stored = get_option( 'doublescale_enabled_modules', array() );
			$stored = is_array( $stored ) ? $stored : array();
			$v      = doublescale_phantom_module_is_enabled( $slug, $stored );
			\DoubleScale\Core\ModuleRequestCache::set_enabled( $slug, $v );

			return $v;
		}

		// A known child sub-feature whose module class is not registered: phantom
		// toggles resolve via doublescale_phantom_module_is_enabled() above; any
		// other child without a class (add-on inactive) reports inactive.
		if ( array_key_exists( $slug, doublescale_child_module_parent_map() ) ) {
			\DoubleScale\Core\ModuleRequestCache::set_enabled( $slug, false );

			return false;
		}

		\DoubleScale\Core\ModuleRequestCache::set_enabled( $slug, true );

		return true;
	}

	$module = new $classes[ $slug ]();
	$v      = $module->is_enabled();
	\DoubleScale\Core\ModuleRequestCache::set_enabled( $slug, $v );

	return $v;
}

/**
 * Whether a module is active and its representative DB table exists.
 *
 * Use before eager automation catalog queries (get_fields / get_options / rule
 * registration) so missing migrations do not fatal the admin bootstrap.
 *
 * @param string $slug        Module slug.
 * @param string $model_class Eloquent model class that maps to the table name.
 */
function doublescale_is_module_storage_ready( string $slug, string $model_class ): bool {
	if ( function_exists( 'doublescale_is_module_active' ) && ! doublescale_is_module_active( $slug ) ) {
		return false;
	}

	if ( ! class_exists( $model_class ) ) {
		return false;
	}

	try {
		global $wpdb;
		$table = ( new $model_class() )->getTable();
		// Escape `_` / `%` — SHOW TABLES uses LIKE, and table names contain underscores.
		$like = $wpdb->esc_like( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
	} catch ( \Throwable $e ) {
		return false;
	}
}

if ( ! function_exists( 'doublescale_flush_module_enabled_cache' ) ) {
	/**
	 * Clears request-level module caches (slug map + enabled flags).
	 */
	function doublescale_flush_module_enabled_cache(): void {
		\DoubleScale\Core\ModuleRequestCache::flush();
	}
}

/**
 * @return array<string, array<string, string>>
 */
function doublescale_feature_group_module_slug_map(): array {
	$map = array(
		'contact_filters'  => array(),
		'automation_rules' => array(),
		'merge_tags'       => array(),
	);

	return apply_filters( 'doublescale_feature_group_module_slug_map', $map );
}

/**
 * @param string $group_key Group key.
 * @param string $context   contact_filters | automation_rules | merge_tags.
 */
function doublescale_feature_group_owned_module( string $group_key, string $context ): ?string {
	$maps = doublescale_feature_group_module_slug_map();
	$slug = $maps[ $context ][ $group_key ] ?? null;

	return apply_filters( 'doublescale_feature_group_owned_module', $slug, $group_key, $context );
}

/**
 * @param array<string, array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function doublescale_filter_contact_filters_groups_for_modules( array $groups ): array {
	foreach ( array_keys( $groups ) as $key ) {
		$owner = doublescale_feature_group_owned_module( $key, 'contact_filters' );
		if ( null !== $owner && ! doublescale_is_module_active( $owner ) ) {
			unset( $groups[ $key ] );
			continue;
		}
		$filters = $groups[ $key ]['filters'] ?? array();
		if ( ! is_array( $filters ) || array() === $filters ) {
			unset( $groups[ $key ] );
		}
	}

	return apply_filters( 'doublescale_contact_filters_groups_for_modules', $groups );
}

/**
 * @param array<string, array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function doublescale_filter_automation_rules_groups_for_modules( array $groups ): array {
	foreach ( array_keys( $groups ) as $key ) {
		$owner = doublescale_feature_group_owned_module( $key, 'automation_rules' );
		if ( null !== $owner && ! doublescale_is_module_active( $owner ) ) {
			unset( $groups[ $key ] );
			continue;
		}
		$rules = $groups[ $key ]['rules'] ?? array();
		if ( ! is_array( $rules ) || array() === $rules ) {
			unset( $groups[ $key ] );
		}
	}

	return apply_filters( 'doublescale_automation_rules_groups_for_modules', $groups );
}

/**
 * @param array<string, array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function doublescale_filter_merge_tag_groups_for_modules( array $groups ): array {
	foreach ( array_keys( $groups ) as $key ) {
		$owner = doublescale_feature_group_owned_module( $key, 'merge_tags' );
		if ( null !== $owner && ! doublescale_is_module_active( $owner ) ) {
			unset( $groups[ $key ] );
		}
	}

	$groups = apply_filters( 'doublescale_merge_tag_groups_module_filtered', $groups );

	return $groups;
}

/**
 * Human-readable module label for automation dependency warnings.
 *
 * @param string $slug Module slug.
 */
function doublescale_automation_module_label( string $slug ): string {
	$labels = array(
		'support'   => __( 'Helpdesk', 'doublescale' ),
		'deals'     => __( 'Pipelines & Deals', 'doublescale' ),
		'booking'   => __( 'Booking', 'doublescale' ),
		'forms'     => __( 'Forms', 'doublescale' ),
		'sales'     => __( 'Sales', 'doublescale' ),
		'documents' => __( 'Proposals & Invoices', 'doublescale' ),
		'contracts' => __( 'Contracts', 'doublescale' ),
	);

	return $labels[ $slug ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
}

/**
 * First inactive module in an ordered parent→child chain (e.g. sales then documents).
 *
 * @param array<int, string> $slugs Module slugs in check order.
 */
function doublescale_automation_first_inactive_module( array $slugs ): ?string {
	foreach ( $slugs as $slug ) {
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( $slug ) ) {
			return $slug;
		}
	}

	return null;
}

/**
 * @param array<int, string> $slugs Module slugs in check order.
 */
function doublescale_automation_modules_available( array $slugs ): bool {
	return null === doublescale_automation_first_inactive_module( $slugs );
}

/**
 * Ordered module chain for a Sales lifecycle trigger/action slug.
 *
 * @param string $item_slug Trigger or action slug.
 * @return array<int, string>
 */
function doublescale_automation_sales_item_modules( string $item_slug ): array {
	if ( '' !== $item_slug && 0 === strpos( $item_slug, 'contract_' ) ) {
		return array( 'sales', 'contracts' );
	}

	if ( '' !== $item_slug && 0 === strpos( $item_slug, 'credit_note_' ) ) {
		return array( 'sales', 'credit_notes' );
	}

	return array( 'sales', 'documents' );
}

/**
 * Ordered module chain for an automation condition rule group.
 *
 * @param string $group Rule group key.
 * @return array<int, string>|null Null when the group is not module-owned.
 */
function doublescale_automation_condition_group_modules( string $group ): ?array {
	switch ( $group ) {
		case 'proposal':
		case 'invoice':
			return array( 'sales', 'documents' );
		case 'contract':
			return array( 'sales', 'contracts' );
		case 'credit_note':
			return array( 'sales', 'credit_notes' );
		case 'task':
		case 'task_fields':
			return array( 'tasks' );
		case 'project':
		case 'project_fields':
			return array( 'projects' );
		default:
			return null;
	}
}

/**
 * Whether any Sales merge-tag submodule (documents or contracts) is available.
 */
function doublescale_automation_sales_merge_tags_enabled(): bool {
	return doublescale_automation_modules_available( array( 'sales', 'documents' ) )
		|| doublescale_automation_modules_available( array( 'sales', 'contracts' ) )
		|| doublescale_automation_modules_available( array( 'sales', 'credit_notes' ) );
}

/**
 * Build a dependency warning payload for automations UI / REST.
 *
 * @param array<int, string> $module_slugs Ordered module chain.
 * @param string             $context      trigger|action|condition|goal.
 * @return array{is_active: bool, is_pro: bool, message: string, plugin_label: string}
 */
function doublescale_automation_module_dependency_result( array $module_slugs, string $context ): array {
	$inactive = doublescale_automation_first_inactive_module( $module_slugs );
	if ( null === $inactive ) {
		return array(
			'is_active'    => true,
			'is_pro'       => false,
			'message'      => '',
			'plugin_label' => '',
		);
	}

	$label = doublescale_automation_module_label( $inactive );

	switch ( $context ) {
		case 'action':
			/* translators: %s: module name (e.g. "Deals"). */
			$template = __( 'This action requires the %s module to be enabled under Settings → Modules.', 'doublescale' );
			break;
		case 'condition':
			/* translators: %s: module name (e.g. "Deals"). */
			$template = __( 'This condition uses rules that require the %s module to be enabled under Settings → Modules.', 'doublescale' );
			break;
		case 'goal':
			/* translators: %s: module name (e.g. "Deals"). */
			$template = __( 'This goal requires the %s module to be enabled under Settings → Modules.', 'doublescale' );
			break;
		default:
			/* translators: %s: module name (e.g. "Deals"). */
			$template = __( 'This trigger requires the %s module to be enabled under Settings → Modules.', 'doublescale' );
			break;
	}

	return array(
		'is_active'    => false,
		'is_pro'       => false,
		'message'      => sprintf( $template, $label ),
		'plugin_label' => $label,
	);
}
