<?php
/**
 * Aggregates built-in and module-registered admin submenu entries.
 *
 * Built-ins ship from {@see MenuRegistry::builtin_entries()}; modules append via
 * {@see MenuRegistry::add()}. The merged list is filtered by `doublescale_admin_menu`.
 *
 * Entries use this shape:
 *
 *   array(
 *     'slug'        => 'doublescale&path=contacts',
 *     'page_title'  => __( 'Contacts', 'doublescale' ),
 *     'menu_title'  => __( 'Contacts', 'doublescale' ),
 *     'capability'  => 'doublescale_access',
 *     'callback'    => array( AdminLoader::class, 'page_wrapper' ),
 *     'position'    => 20,
 *     'group'            => 'contacts', // dashboard|contacts|sales|marketing|automations|settings
 *     'requires_module'  => 'campaigns', // optional: module slug; row hidden unless module is registered and enabled
 *   )
 *
 * @package DoubleScale
 */

namespace DoubleScale\Admin;

defined( 'ABSPATH' ) || exit;

class MenuRegistry {

	/** @var array<int, array<string, mixed>> */
	private static array $entries = array();

	public static function add( array $entry ): void {
		self::$entries[] = $entry;
	}

	/**
	 * Default submenu rows for the primary DoubleScale top-level menu.
	 *
	 * Six logical groups (`group`): dashboard, contacts, sales, marketing,
	 * automations, settings — used for filtering / docs; WP still renders a
	 * flat submenu unless extenders insert separators.
	 *
	 * @param string $menu_slug Slug passed to add_menu_page / parent slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function builtin_entries( string $menu_slug ): array {
		return array(
			array(
				'page_title' => __( 'Dashboard', 'doublescale' ),
				'menu_title' => __( 'Dashboard', 'doublescale' ),
				'capability' => 'doublescale_access',
				'slug'       => $menu_slug,
				'callback'   => array( AdminLoader::class, 'page_wrapper' ),
				'position'   => 10,
				'group'      => 'dashboard',
			),
			array(
				'page_title' => __( 'Contacts', 'doublescale' ),
				'menu_title' => __( 'Contacts', 'doublescale' ),
				'capability' => 'doublescale_access',
				'slug'       => $menu_slug . '&path=contacts',
				'callback'   => array( AdminLoader::class, 'page_wrapper' ),
				'position'   => 20,
				'group'      => 'contacts',
			),
			array(
				'page_title'      => __( 'Campaigns', 'doublescale' ),
				'menu_title'      => __( 'Campaigns', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => $menu_slug . '&path=campaigns',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 30,
				'group'           => 'marketing',
				'requires_module' => 'campaigns',
			),
			array(
				'page_title'      => __( 'Pipelines', 'doublescale' ),
				'menu_title'      => __( 'Pipelines', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => $menu_slug . '&path=sales-pipeline',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 40,
				'group'           => 'sales',
				'requires_module' => 'deals',
			),
			array(
				'page_title' => __( 'Automations', 'doublescale' ),
				'menu_title' => __( 'Automations', 'doublescale' ),
				'capability' => 'doublescale_access',
				'slug'       => $menu_slug . '&path=automations',
				'callback'   => array( AdminLoader::class, 'page_wrapper' ),
				'position'   => 50,
				'group'      => 'automations',
			),
			array(
				'page_title'      => __( 'Forms', 'doublescale' ),
				'menu_title'      => __( 'Forms', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => $menu_slug . '&path=forms',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 60,
				'group'           => 'marketing',
				'requires_module' => 'forms',
			),
			array(
				'page_title' => __( 'Integrations', 'doublescale' ),
				'menu_title' => __( 'Integrations', 'doublescale' ),
				'capability' => 'doublescale_access',
				'slug'       => $menu_slug . '&path=integrations',
				'callback'   => array( AdminLoader::class, 'page_wrapper' ),
				'position'   => 70,
				'group'      => 'automations',
			),
			array(
				'page_title'      => __( 'SMTP', 'doublescale' ),
				'menu_title'      => __( 'SMTP', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => $menu_slug . '&path=smtp',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 75,
				'group'           => 'settings',
				'requires_module' => 'smtp',
			),
			array(
				'page_title'      => __( 'Analytics', 'doublescale' ),
				'menu_title'      => __( 'Analytics', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => $menu_slug . '&path=analytics',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 80,
				'group'           => 'settings',
				'requires_module' => 'analytics',
			),
			array(
				'page_title' => __( 'Settings', 'doublescale' ),
				'menu_title' => __( 'Settings', 'doublescale' ),
				'capability' => 'doublescale_access',
				'slug'       => $menu_slug . '&path=settings',
				'callback'   => array( AdminLoader::class, 'page_wrapper' ),
				'position'   => 90,
				'group'      => 'settings',
			),
		);
	}

	/**
	 * Built-in defaults plus {@see MenuRegistry::$entries}, filtered and sorted.
	 *
	 * @param array<int, array<string, mixed>> $builtin Rows prepended before module rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( array $builtin = array() ): array {
		$entries = array_merge( $builtin, self::$entries );

		/**
		 * Filter the full DoubleScale admin submenu definition.
		 *
		 * @param array<int, array<string, mixed>> $entries
		 */
		$entries = apply_filters( 'doublescale_admin_menu', $entries );

		$entries = self::filter_entries_by_registered_modules( $entries );

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				$pa = isset( $a['position'] ) ? (int) $a['position'] : 100;
				$pb = isset( $b['position'] ) ? (int) $b['position'] : 100;
				return $pa <=> $pb;
			}
		);

		return $entries;
	}

	public static function reset_for_tests(): void {
		self::$entries = array();
	}

	/**
	 * Drops rows when {@see requires_module} is set and that slug is not installed or is disabled.
	 *
	 * Uses the canonical merged module map from the free plugin ({@see doublescale_module_slug_to_class_map})
	 * and {@see doublescale_is_module_active()} so free-only installs (e.g. Campaigns in core) work without Pro.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_entries_by_registered_modules( array $entries ): array {
		return array_values(
			array_filter(
				$entries,
				static function ( array $item ): bool {
					$module_slug = isset( $item['requires_module'] ) ? (string) $item['requires_module'] : '';
					if ( '' === $module_slug ) {
						return true;
					}
					if ( ! function_exists( 'doublescale_module_slug_to_class_map' ) || ! function_exists( 'doublescale_is_module_active' ) ) {
						return true;
					}
					$classes = doublescale_module_slug_to_class_map();
					if ( ! isset( $classes[ $module_slug ] ) ) {
						return false;
					}

					return doublescale_is_module_active( $module_slug );
				}
			)
		);
	}
}
