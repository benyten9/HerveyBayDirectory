<?php
/**
 * Ability category catalog and registration.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the DoubleScale ability categories.
 *
 * Must run on wp_abilities_api_categories_init, which WP core fires before
 * wp_abilities_api_init. An ability naming an unregistered category does not
 * throw — WP_Abilities_Registry::register() returns null, so the ability
 * silently vanishes. Ordering is therefore mandatory, not merely tidy.
 */
final class AbilityCategories {

	public const CORE      = 'doublescale-core';
	public const CONTACTS  = 'doublescale-contacts';
	public const SALES     = 'doublescale-sales';
	public const SUPPORT   = 'doublescale-support';
	public const DEALS     = 'doublescale-deals';
	public const TASKS     = 'doublescale-tasks';
	public const PROJECTS  = 'doublescale-projects';
	public const ANALYTICS = 'doublescale-analytics';
	public const MARKETING = 'doublescale-marketing';
	public const BOOKING   = 'doublescale-booking';

	/**
	 * Module slug => category slug.
	 *
	 * Categories group tools the way a USER thinks about them, which is not the
	 * same as the module tree: invoices, proposals, contracts, credit notes, and
	 * products are five separate modules but one "Sales" idea, so they share a
	 * category. Deals get their own because pipeline work is its own activity.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function module_category_map(): array {
		return array(
			'core'               => self::CORE,
			'contacts'           => self::CONTACTS,
			'activities'         => self::CONTACTS,
			'documents'          => self::SALES,
			'contracts'          => self::SALES,
			'credit_notes'       => self::SALES,
			'product_catalog'    => self::SALES,
			// Billing schedules are a property of invoices, so they read as a
			// Sales concern rather than an automation one.
			'recurring_invoices' => self::SALES,
			'support'            => self::SUPPORT,
			'deals'              => self::DEALS,
			'tasks'              => self::TASKS,
			'projects'           => self::PROJECTS,
			'analytics'          => self::ANALYTICS,
			// Campaigns and automations are two halves of one marketing idea.
			'campaigns'          => self::MARKETING,
			'automations'        => self::MARKETING,
			// Form submissions are how contacts arrive, so they read as a
			// contacts concern rather than a category of their own.
			'forms'              => self::CONTACTS,
			'booking'            => self::BOOKING,
			// Lead scoring ranks contacts, so it belongs with Contacts.
			'leadscoring'        => self::CONTACTS,
			// Page-visit history is engagement data, same idea as messages.
			'websitetracking'    => self::CONTACTS,
			'notifications'      => self::CORE,
			'custom-fields'      => self::CONTACTS,
		);
	}

	/**
	 * Category slug for a module, falling back to the core category.
	 *
	 * @since 1.0.0
	 *
	 * @param string $module_slug Module slug.
	 * @return string
	 */
	public static function slug_for_module( string $module_slug ): string {
		$map = self::module_category_map();
		return $map[ $module_slug ] ?? self::CORE;
	}

	/**
	 * Catalog of registered categories.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function catalog(): array {
		return array(
			self::CORE      => array(
				'label'       => __( 'DoubleScale: Core', 'doublescale' ),
				'description' => __( 'Identity, active modules, and site context.', 'doublescale' ),
			),
			self::CONTACTS  => array(
				'label'       => __( 'DoubleScale: Contacts', 'doublescale' ),
				'description' => __( 'CRM contacts, tags, lists, and activity timelines.', 'doublescale' ),
			),
			self::SALES     => array(
				'label'       => __( 'DoubleScale: Sales', 'doublescale' ),
				'description' => __( 'Invoices, proposals, contracts, credit notes, products, and sales summaries.', 'doublescale' ),
			),
			self::SUPPORT   => array(
				'label'       => __( 'DoubleScale: Support', 'doublescale' ),
				'description' => __( 'Support tickets, threads, and workload summaries.', 'doublescale' ),
			),
			self::DEALS     => array(
				'label'       => __( 'DoubleScale: Deals', 'doublescale' ),
				'description' => __( 'Sales pipelines, deal stages, and pipeline value.', 'doublescale' ),
			),
			self::TASKS     => array(
				'label'       => __( 'DoubleScale: Tasks', 'doublescale' ),
				'description' => __( 'Tasks, statuses, due dates, and workload.', 'doublescale' ),
			),
			self::PROJECTS  => array(
				'label'       => __( 'DoubleScale: Projects', 'doublescale' ),
				'description' => __( 'Projects, statuses, and linked contacts and deals.', 'doublescale' ),
			),
			self::ANALYTICS => array(
				'label'       => __( 'DoubleScale: Reports', 'doublescale' ),
				'description' => __( 'Revenue, pipeline, and task reporting.', 'doublescale' ),
			),
			self::MARKETING => array(
				'label'       => __( 'DoubleScale: Marketing', 'doublescale' ),
				'description' => __( 'Campaigns and automation workflows, read-only — nothing here sends or runs.', 'doublescale' ),
			),
			self::BOOKING   => array(
				'label'       => __( 'DoubleScale: Booking', 'doublescale' ),
				'description' => __( 'Bookings, events, and calendars.', 'doublescale' ),
			),
		);
	}

	/**
	 * Register every category unconditionally.
	 *
	 * Registering a category whose module is disabled is harmless and costs
	 * nothing; registering an ability whose category is missing loses the
	 * ability silently. Unconditional is strictly safer, and it keeps a
	 * runtime module toggle from ever hitting a missing category.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::catalog() as $slug => $spec ) {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $spec['label'],
					'description' => $spec['description'],
				)
			);
		}
	}
}
