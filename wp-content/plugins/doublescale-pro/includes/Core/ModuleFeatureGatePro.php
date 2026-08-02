<?php
/**
 * Pro extensions for module/feature gates — registers filters only (no duplicate globals).
 * Canonical helpers live in the free plugin {@see DoubleScale/includes/Core/ModuleFeatureGate.php}.
 *
 * Loaded from doublescale-pro.php on plugins_loaded priority 1 so merges run before free Bootstrap (priority 5).
 *
 * @package DoubleScale\Pro\Pro
 */

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Forms\Services\FormsManager;

/**
 * Merge Pro module classes into the slug → class map (Pro overrides same slug).
 *
 * @param array<string, class-string> $map Base map from free plugin.
 * @return array<string, class-string>
 */
function doublescale_pro_merge_module_slug_to_class_map( array $map ): array {
	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
		return $map;
	}

	$root = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/';
	foreach ( (array) glob( $root . '*', GLOB_ONLYDIR ) as $dir ) {
		if ( ! is_file( $dir . '/Module.php' ) ) {
			continue;
		}
		$basename = basename( $dir );
		$class    = 'DoubleScale\\Pro\\Modules\\' . $basename . '\\Module';
		if ( ! class_exists( $class ) ) {
			continue;
		}
		$module = new $class();
		if ( ! $module instanceof \DoubleScale\Core\ModuleInterface ) {
			continue;
		}
		$map[ $module->slug() ] = $class;
	}

	return $map;
}
add_filter( 'doublescale_module_slug_to_class_map', 'doublescale_pro_merge_module_slug_to_class_map', 10, 1 );

/**
 * Default UI group → module slug mappings for Pro (merged onto free empty scaffold).
 *
 * @param array<string, array<string, string>> $map
 * @return array<string, array<string, string>>
 */
function doublescale_pro_merge_feature_group_module_slug_map( array $map ): array {
	$defaults = array(
		'contact_filters'  => array(
			'lead_scoring' => 'leadscoring',
			'submission'   => 'forms',
		),
		'automation_rules' => array(
			'lead_scoring'              => 'leadscoring',
			'deal'                      => 'deals',
			'deal_fields'               => 'deals',
			'submission'                => 'forms',
			'automation'                => 'automations',
			'support'                   => 'support',
			'proposal'                  => 'documents',
			'invoice'                   => 'documents',
			'contract'                  => 'contracts',
			'woocommerce'               => 'campaigns',
			'woocommerce_current_order' => 'campaigns',
			'woocommerce_membership'    => 'campaigns',
			'woocommerce_whishlist'     => 'campaigns',
			'woocommerce_subscription'  => 'campaigns',
			'woocommerce_review'        => 'campaigns',
			'cart'                      => 'campaigns',
			'surecart_current_order'    => 'campaigns',
		),
		'merge_tags'       => array(
			'deal'           => 'deals',
			'order'          => 'campaigns',
			'abandoned_cart' => 'campaigns',
			'membership'     => 'campaigns',
			'wishlist'       => 'campaigns',
			'subscription'   => 'campaigns',
			'review'         => 'campaigns',
			'coupon'         => 'campaigns',
			'edd_customer'   => 'campaigns',
			'edd_order'      => 'campaigns',
			'last_post'      => 'campaigns',
			'messaging'      => 'inbox',
		),
	);

	foreach ( $defaults as $ctx => $rows ) {
		if ( ! isset( $map[ $ctx ] ) ) {
			$map[ $ctx ] = array();
		}
		$map[ $ctx ] = array_merge( $map[ $ctx ], $rows );
	}

	return $map;
}
add_filter( 'doublescale_feature_group_module_slug_map', 'doublescale_pro_merge_feature_group_module_slug_map', 10, 1 );

/**
 * Strip per-form automation rule groups when Forms module is off (Pro FormsManager).
 *
 * @param array<string, array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function doublescale_pro_strip_automation_rules_when_forms_disabled( array $groups ): array {
	if ( doublescale_is_module_active( 'forms' ) || ! class_exists( FormsManager::class ) ) {
		return $groups;
	}
	foreach ( FormsManager::instance()->get_all_forms() as $form ) {
		unset( $groups[ $form->slug ] );
	}
	return $groups;
}
add_filter( 'doublescale_automation_rules_groups_for_modules', 'doublescale_pro_strip_automation_rules_when_forms_disabled', 20, 1 );

/**
 * Strip per-form merge-tag groups when Forms module is off (Pro FormsManager).
 *
 * @param array<string, array<string, mixed>> $groups
 * @return array<string, array<string, mixed>>
 */
function doublescale_pro_strip_merge_tags_when_forms_disabled( array $groups ): array {
	if ( doublescale_is_module_active( 'forms' ) || ! class_exists( FormsManager::class ) ) {
		return $groups;
	}
	foreach ( FormsManager::instance()->get_all_forms() as $form ) {
		unset( $groups[ $form->slug ] );
	}
	return $groups;
}
add_filter( 'doublescale_merge_tag_groups_module_filtered', 'doublescale_pro_strip_merge_tags_when_forms_disabled', 20, 1 );

if ( ! function_exists( 'doublescale_is_lead_scoring_module_enabled' ) ) {
	/**
	 * @deprecated 1.13.1 Use {@see doublescale_is_module_active()} with slug leadscoring.
	 */
	function doublescale_is_lead_scoring_module_enabled(): bool {
		return doublescale_is_module_active( 'leadscoring' );
	}
}
