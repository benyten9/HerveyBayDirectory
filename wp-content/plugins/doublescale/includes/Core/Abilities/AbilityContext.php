<?php
/**
 * Discovery ability: what this site has, and what this caller may do.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Builds the payload for doublescale/get-context.
 *
 * DoubleScale has 34 toggleable modules, so an agent cannot know what exists
 * without asking. This is the entry point it should call first.
 */
final class AbilityContext {

	/**
	 * Sections returned when the caller passes no `include`.
	 */
	public const DEFAULT_SECTIONS = array( 'identity', 'modules' );

	/**
	 * Every section this ability can return.
	 */
	public const SECTIONS = array( 'identity', 'modules', 'enums' );

	/**
	 * The ability definition, owned by Core rather than a feature module.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			'doublescale/get-context' => array(
				'module_slug'      => 'core',
				'label'            => __( 'Get CRM context', 'doublescale' ),
				'description'      => __( 'Start here. Returns who you are, which DoubleScale modules are active on this site, which tools you can actually call, and the site currency and timezone. Modules that are switched off are absent from the list — their data is unavailable, not empty. Some tools write records or email customers; this call only describes what is available.', 'doublescale' ),
				'category'         => AbilityCategories::CORE,
				'permission'       => null,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'include' => array(
							'type'        => 'array',
							'description' => 'Sections to return. Defaults to identity + modules.',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'identity', 'modules', 'enums', 'all' ),
							),
						),
					),
				),
				'output_schema'    => array(
					'type'                 => 'object',
					'description'          => 'Site identity, active modules, and optional enums. Shape depends on the include argument.',
					'additionalProperties' => true,
				),
				'execute_callback' => array( self::class, 'execute' ),
			),
		);
	}

	/**
	 * Build the context payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function execute( array $input = array() ): array {
		$include = isset( $input['include'] ) && is_array( $input['include'] ) && array() !== $input['include']
			? array_map( 'strval', $input['include'] )
			: self::DEFAULT_SECTIONS;

		if ( in_array( 'all', $include, true ) ) {
			$include = self::SECTIONS;
		}

		$out = array();

		if ( in_array( 'identity', $include, true ) ) {
			$out['identity'] = self::identity();
			$out['site']     = self::site();
		}

		if ( in_array( 'modules', $include, true ) ) {
			$out['modules'] = self::modules();
		}

		if ( in_array( 'enums', $include, true ) ) {
			$out['enums'] = self::enums();
		}

		return $out;
	}

	/**
	 * Caller identity and the breadth of what they can see.
	 *
	 * The `scope` block matters as much as the role: a Sales Rep sees only
	 * their own records, so an agent that does not know this will report "you
	 * have 4 open invoices" when the site has 400.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function identity(): array {
		$user = wp_get_current_user();

		return array(
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'role'         => Permissions::get_user_role( (int) $user->ID ),
			'scope'        => array(
				'sales'   => self::sales_scope(),
				'support' => Permissions::can_manage_all_tickets() ? 'all' : 'own',
			),
			'scope_note'   => __( 'Where scope is "own", results are limited to records assigned to you. Totals and counts reflect only your own records, not the whole site.', 'doublescale' ),
		);
	}

	/**
	 * Sales visibility breadth, guarded for the case where the Sales module
	 * (and therefore its Capabilities class) is not loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function sales_scope(): string {
		if ( ! class_exists( \DoubleScale\Modules\Sales\Capabilities::class ) ) {
			return 'none';
		}

		$caps = \DoubleScale\Modules\Sales\Capabilities::class;

		if ( $caps::can_manage_all_sales() || $caps::can_assign_sales_rep() ) {
			return 'all';
		}

		return 'own';
	}

	/**
	 * Site-level facts an agent needs before formatting anything.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function site(): array {
		return array(
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url(),
			'timezone' => wp_timezone_string(),
			'currency' => Settings::get_currency(),
		);
	}

	/**
	 * Active modules and the tools the caller can actually invoke.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function modules(): array {
		$active = array();
		$tools  = array();

		foreach ( ModuleManager::all() as $slug => $module ) {
			$slug = (string) $slug;

			// Live check, never a boot-time snapshot.
			if ( ! AbilityGuard::module_active( $slug ) ) {
				continue;
			}

			$active[] = array(
				'slug'  => $slug,
				'label' => $module->label(),
				'pro'   => 0 === strpos( get_class( $module ), 'DoubleScale\\Pro\\' ),
			);

			if ( ! $module instanceof ProvidesAbilities ) {
				continue;
			}

			foreach ( array_keys( $module->abilities() ) as $name ) {
				if ( self::caller_can_use( (string) $name ) ) {
					$tools[] = $name;
				}
			}
		}

		foreach ( array_keys( self::definitions() ) as $name ) {
			if ( self::caller_can_use( (string) $name ) ) {
				$tools[] = $name;
			}
		}

		sort( $tools );

		return array(
			'active'         => $active,
			'callable_tools' => $tools,
			'inactive_note'  => __( 'Modules not listed here are switched off on this site. If asked about one, say the feature is not enabled — do not report its data as empty or zero.', 'doublescale' ),
		);
	}

	/**
	 * Whether the current user would pass this ability's permission callback.
	 *
	 * Re-running the registered callback keeps the advertised tool list exactly
	 * consistent with what a call will do, so the agent never burns a turn on a
	 * tool that always returns 403.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Full ability name.
	 * @return bool
	 */
	private static function caller_can_use( string $name ): bool {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'check_permissions' ) ) {
			return false;
		}

		return true === $ability->check_permissions();
	}

	/**
	 * Status vocabularies, so an agent filters with real values instead of guesses.
	 *
	 * Each block is included only when its module is active and the constants
	 * are actually loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function enums(): array {
		$enums = array();

		if ( AbilityGuard::module_active( 'documents' ) ) {
			$invoice  = '\DoubleScale\Modules\Documents\Constants\InvoiceStatus';
			$proposal = '\DoubleScale\Modules\Documents\Constants\ProposalStatus';

			if ( class_exists( $invoice ) && method_exists( $invoice, 'all' ) ) {
				$enums['invoice_status'] = $invoice::all();
			}
			if ( class_exists( $proposal ) && method_exists( $proposal, 'all' ) ) {
				$enums['proposal_status'] = $proposal::all();
			}
		}

		if ( AbilityGuard::module_active( 'support' ) ) {
			$status   = '\DoubleScale\Modules\Support\Constants\TicketStatus';
			$priority = '\DoubleScale\Modules\Support\Constants\TicketPriority';

			if ( class_exists( $status ) && method_exists( $status, 'all' ) ) {
				$enums['ticket_status'] = $status::all();
			}
			if ( class_exists( $priority ) && method_exists( $priority, 'all' ) ) {
				$enums['ticket_priority'] = $priority::all();
			}
		}

		return $enums;
	}
}
