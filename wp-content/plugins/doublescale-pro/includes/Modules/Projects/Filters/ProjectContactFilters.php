<?php
/**
 * Registers the "Projects" group of contact filters.
 *
 * Hooked from the Pro bootstrap, not the Projects module: a disabled module
 * does not boot, and a saved audience rule whose filter is missing is skipped,
 * which would widen a campaign to every contact. Registered even while
 * Projects is off, the rules match nobody and the group is hidden.
 *
 * @package DoubleScale\Pro\Modules\Projects\Filters
 */

namespace DoubleScale\Pro\Modules\Projects\Filters;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Filters\FiltersManager;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

/**
 * Registrar for project-based contact filters.
 */
final class ProjectContactFilters {

	/**
	 * Hook into Free's filter registry.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'doublescale_ready', array( self::class, 'register' ), 20 );
		add_filter( 'doublescale_contact_filters_groups', array( self::class, 'group' ) );
	}

	/**
	 * Register the status filter and one filter per project custom field.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( FiltersManager::class, true )
			|| ! class_exists( \DoubleScale\Modules\Contacts\Filters\ContactFields\Fields::class, true ) ) {
			return;
		}

		$manager = FiltersManager::instance();

		try {
			if ( null === $manager->get_filter( 'project_status' ) ) {
				$manager->register( new ProjectStatusFilter() );
			}

			if ( ! self::custom_fields_ready() ) {
				return;
			}

			$fields = method_exists( CustomFieldModel::class, 'cached_for_scope' )
				? CustomFieldModel::cached_for_scope( 'project' )
				: CustomFieldModel::where( 'scope', 'project' )->get();

			foreach ( $fields as $field ) {
				if ( null === $manager->get_filter( ProjectFieldFilter::slug_for( (int) $field->id ) ) ) {
					$manager->register( new ProjectFieldFilter( $field ) );
				}
			}
		} catch ( \Throwable $e ) {
			// Tables may not exist yet before migrations.
			return;
		}
	}

	/**
	 * Name the group and show it after Contact Fields, only while Projects is on.
	 *
	 * @param array<string, array<string, mixed>> $groups Filter groups.
	 * @return array<string, array<string, mixed>>
	 */
	public static function group( $groups ) {
		if ( ! is_array( $groups ) || ! isset( $groups[ ProjectAudience::GROUP ] ) ) {
			return $groups;
		}

		$projects = $groups[ ProjectAudience::GROUP ];
		unset( $groups[ ProjectAudience::GROUP ] );

		if ( ! ProjectAudience::ready() || empty( $projects['filters'] ) ) {
			return $groups;
		}

		$projects['name'] = __( 'Projects', 'doublescale' );

		$ordered = array();
		foreach ( $groups as $key => $group ) {
			$ordered[ $key ] = $group;
			if ( 'contact_fields' === $key ) {
				$ordered[ ProjectAudience::GROUP ] = $projects;
			}
		}
		if ( ! isset( $ordered[ ProjectAudience::GROUP ] ) ) {
			$ordered[ ProjectAudience::GROUP ] = $projects;
		}

		return $ordered;
	}

	/**
	 * Whether project custom fields can be read.
	 *
	 * @return bool
	 */
	private static function custom_fields_ready(): bool {
		if ( ! class_exists( CustomFieldModel::class, true ) ) {
			return false;
		}
		if ( function_exists( 'doublescale_is_module_storage_ready' ) ) {
			return doublescale_is_module_storage_ready( 'custom-fields', CustomFieldModel::class );
		}

		return true;
	}
}
