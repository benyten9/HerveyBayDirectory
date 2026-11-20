<?php
/**
 * The areas of the Client Portal an admin can show or hide.
 *
 * Settings → Client Portal → "What clients see in the portal" lists one row per
 * area, named and grouped as the portal menu shows it (Support Tickets,
 * Bookings, Projects, Documents → Proposals & Invoices, …). Each area declares what it owns in the
 * portal — menu sections, dashboard cards, recent-activity rows, calendar
 * events, document types and its own REST routes — so a single decision hides
 * the area from every surface at once ({@see PortalAccessGate}).
 *
 * The defaults are stored in `doublescale_settings['client_portal']['features']`
 * next to the keys {@see PortalUserProvisioner}, {@see PortalUploadSettings} and
 * {@see PortalWelcomeSettings} own. An area nobody has touched keeps its
 * built-in default, which is what the portal showed before this setting
 * existed. Files is the exception: its default IS the existing upload switch,
 * so both screens move the same value.
 *
 * Add-ons register their own area through the `doublescale_portal_features`
 * filter. They can only claim what no core area owns already: claiming the
 * Documents section would let an add-on show it while every document module
 * row is hidden. Anything an add-on does not declare is not governed — it
 * shows exactly as before, so an add-on that predates this contract keeps
 * working and is simply not listed.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Core\Settings\Settings;

/**
 * PortalFeatures.
 */
final class PortalFeatures {

	/** Settings key shared with the other client portal settings. */
	public const SETTINGS_KEY = 'client_portal';

	/** Key inside it holding feature key => default visibility. */
	public const DEFAULTS_KEY = 'features';

	/**
	 * Add-on claims already logged this request (the index is built per call).
	 *
	 * @var array<string, true>
	 */
	private static $logged_conflicts = array();

	/** Ownership dimensions an area can claim. */
	public const DIMENSIONS = array( 'sections', 'cards', 'timeline_kinds', 'activity_types', 'document_types', 'calendar_kinds', 'routes' );

	/**
	 * The portal's project page shows a "Time" tab when a project sends
	 * `time_worked`. Pro offers the per-project switch only when this exists,
	 * so an older portal never gets a switch that shows nothing.
	 */
	public const SUPPORTS_PROJECT_TIME = true;

	/**
	 * The portal has the "Time worked" page (section `time`: entries and
	 * reports) and a project Time tab that searches and filters through
	 * GET /portal/time. The Time Tracking add-on registers the page only when
	 * this exists, so an older portal never lists a page it cannot show.
	 */
	public const SUPPORTS_TIME_SECTION = true;

	/**
	 * The portal draws a "records" section (list per type, read-only record
	 * page with fields, sub-records and shared files) from GET
	 * /portal/records. The Custom Objects add-on registers the page only
	 * when this exists, so an older portal never lists a page it cannot show.
	 */
	public const SUPPORTS_RECORDS_SECTION = true;

	/**
	 * The time entries (page and project tab) have a Notes column, shown when
	 * GET /portal/time says `show_notes`. The add-on sends notes, and offers
	 * the project's "show notes" switch, only when this exists.
	 */
	public const SUPPORTS_TIME_NOTES = true;

	/**
	 * The portal's project page has a Tasks tab (board, list and calendar of
	 * GET /portal/projects/{id}/tasks), shown when a project sends
	 * `show_tasks`. Pro offers the per-project switch only when this exists,
	 * so an older portal never gets a switch that shows nothing.
	 */
	public const SUPPORTS_PROJECT_TASKS = true;

	/**
	 * The portal's task window has Comments and Files tabs: the client's thread
	 * with the team (GET/POST …/tasks/{id}/comments) and the task's shared files
	 * with uploads (…/tasks/{id}/files). Pro offers the team's "Visible to
	 * client" switch and file toggle only when this exists.
	 */
	public const SUPPORTS_TASK_THREAD = true;

	/**
	 * The built-in areas, in the order and under the names the client sees them
	 * in the portal menu: Main (the Dashboard), CRM (Support Tickets, Bookings,
	 * Projects) and Other (Documents and its tabs, Files, My Profile).
	 *
	 * One row per module, named as Control Modules names it. `modules`: the
	 * area is available while ANY of them is on. Leaf slugs only — PHP folds
	 * the parent in.
	 * `group` is the portal menu group; `section` gathers rows under a heading
	 * (the Documents page). Keys never change: they are stored.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function core(): array {
		return array(
			array(
				'key'         => 'requests',
				'group'       => 'crm',
				'label'       => __( 'Support Tickets', 'doublescale' ),
				'description' => __( 'Clients open and follow their support tickets, with the Open tickets card and replies in Recent activity.', 'doublescale' ),
				'modules'     => array( 'support' ),
				// The ticket routes are shared with the separate support page,
				// which keeps its own permissions: hiding Support Tickets hides
				// them from the portal only.
				'owns'        => array(
					'sections'       => array( 'tickets' ),
					'cards'          => array( 'open_tickets' ),
					'activity_types' => array( ActivityTypes::SUPPORT_REPLY ),
					'calendar_kinds' => array( 'support' ),
				),
			),
			array(
				'key'         => 'appointments',
				'group'       => 'crm',
				'label'       => __( 'Bookings', 'doublescale' ),
				'description' => __( 'Clients see, reschedule and cancel their bookings, with the Upcoming bookings card and bookings on the calendar.', 'doublescale' ),
				'modules'     => array( 'booking' ),
				'owns'        => array(
					'sections'       => array( 'bookings' ),
					'cards'          => array( 'upcoming_bookings' ),
					'timeline_kinds' => array( 'booking' ),
					'calendar_kinds' => array( 'booking' ),
					'routes'         => array( '#^/doublescale/v1/portal/bookings(?:/\d+(?:/(?:cancel|reschedule-url))?)?$#' ),
				),
			),
			array(
				'key'          => 'projects',
				'group'        => 'crm',
				'label'        => __( 'Projects', 'doublescale' ),
				'description'  => __( 'Clients follow project progress and open the documents linked to each project, with the Active projects card and due dates on the calendar.', 'doublescale' ),
				'requires_pro' => true,
				'modules'      => array( 'projects' ),
				'owns'         => array(
					'sections'       => array( 'projects' ),
					'cards'          => array( 'active_projects' ),
					'timeline_kinds' => array( 'project' ),
					'calendar_kinds' => array( 'project' ),
					// The list, one project, its tasks and a task's thread and files.
					'routes'         => array( '#^/doublescale/v1/portal/projects(?:/\d+(?:/tasks(?:/\d+/(?:comments|files(?:/\d+)?))?)?)?$#' ),
				),
			),
			array(
				'key'         => 'documents',
				'group'       => 'other',
				'section'     => 'documents',
				'label'       => __( 'Proposals & Invoices', 'doublescale' ),
				'description' => __( 'The Proposals, Invoices and Payments tabs, and their dates on the calendar.', 'doublescale' ),
				'modules'     => array( 'documents' ),
				'owns'        => array(
					'sections'       => array( 'documents' ),
					'document_types' => array( 'invoice', 'proposal' ),
					'calendar_kinds' => array( 'invoice', 'proposal' ),
					'routes'         => array( '#^/doublescale/v1/portal/documents(?:/archive)?$#', '#^/doublescale/v1/portal/payments$#', '#^/doublescale/v1/portal/statement(?:/pdf)?$#' ),
				),
			),
			array(
				'key'          => 'contracts',
				'group'        => 'other',
				'section'      => 'documents',
				'label'        => __( 'Contracts', 'doublescale' ),
				'description'  => __( 'The Contracts tab, to read and sign them, and their end dates on the calendar.', 'doublescale' ),
				'requires_pro' => true,
				'modules'      => array( 'contracts' ),
				'owns'         => array(
					'sections'       => array( 'documents' ),
					'document_types' => array( 'contract' ),
					'calendar_kinds' => array( 'contract' ),
					'routes'         => array( '#^/doublescale/v1/portal/documents$#' ),
				),
			),
			array(
				'key'          => 'credit_notes',
				'group'        => 'other',
				'section'      => 'documents',
				'label'        => __( 'Credit Notes', 'doublescale' ),
				'description'  => __( 'The Credit Notes tab.', 'doublescale' ),
				'requires_pro' => true,
				'modules'      => array( 'credit_notes' ),
				'owns'         => array(
					'sections'       => array( 'documents' ),
					'document_types' => array( 'credit_note' ),
					'routes'         => array( '#^/doublescale/v1/portal/documents$#' ),
				),
			),
			array(
				'key'         => 'financial_summaries',
				'group'       => 'other',
				'section'     => 'documents',
				'label'       => __( 'Outstanding balance & totals', 'doublescale' ),
				'description' => __( 'The Outstanding balance card, the Total paid line and the budget and amounts on each project.', 'doublescale' ),
				'modules'     => array( 'documents' ),
				'depends_on'  => array( 'documents' ),
				'owns'        => array(
					'cards' => array( 'outstanding_balance' ),
				),
			),
			array(
				'key'          => 'files',
				'group'        => 'other',
				'label'        => __( 'Files', 'doublescale' ),
				'description'  => __( 'Clients upload files to their contact record.', 'doublescale' ),
				'requires_pro' => true,
				'available'    => array( PortalUploadSettings::class, 'is_supported' ),
				// The Files routes refuse on their own, with the 403 they have
				// always answered while uploads are off — now per contact.
				'owns'         => array(
					'sections' => array( 'files' ),
				),
			),
			array(
				'key'         => 'help_center',
				'group'       => 'other',
				'label'       => __( 'Help Center', 'doublescale' ),
				'description' => __( 'Clients read your help articles, including the ones only portal clients can see.', 'doublescale' ),
				'modules'     => array( 'knowledge_base' ),
				// The /kb/public routes stay open to visitors too (they only
				// ever return public articles to them), so only the section is owned.
				'owns'        => array(
					'sections' => array( 'help' ),
				),
			),
			array(
				'key'         => 'profile',
				'group'       => 'other',
				'label'       => __( 'My Profile', 'doublescale' ),
				'description' => __( 'Clients keep their own name, phone and address up to date.', 'doublescale' ),
				'owns'        => array(
					'sections' => array( 'profile' ),
					'routes'   => array( '#^/doublescale/v1/portal/contact$#' ),
				),
			),
			array(
				'key'         => 'profile_edit',
				'group'       => 'other',
				'section'     => 'profile',
				'label'       => __( 'Edit their details', 'doublescale' ),
				'description' => __( 'Off: clients see their details on My Profile but cannot change them.', 'doublescale' ),
				'depends_on'  => array( 'profile' ),
				// Owns no page or route: the save itself asks
				// (RestPortalContactController::update_item()).
			),
		);
	}

	/**
	 * Every area, core first, then the ones add-ons registered. Normalised.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$core = array_map( array( self::class, 'normalise' ), self::core() );

		/**
		 * Register an area of the Client Portal an admin can show or hide.
		 *
		 * Each entry: key (stable — it is stored), label, description, default,
		 * modules (leaf slugs, any of them on = available), requires_pro, and
		 * `owns` => sections, cards, timeline_kinds, activity_types,
		 * document_types, calendar_kinds, routes (anchored regexes matched
		 * against the lowercased request path, as WordPress matches routes
		 * case-insensitively). An add-on cannot claim what a core area owns;
		 * anything it does not declare is not governed.
		 *
		 * @param array<int, array<string, mixed>> $features Areas added so far (add-ons only).
		 */
		$extra = apply_filters( 'doublescale_portal_features', array() );

		$features = $core;
		$keys     = array_column( $core, 'key' );
		foreach ( is_array( $extra ) ? $extra : array() as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}
			$feature = self::normalise( $feature );
			if ( '' === $feature['key'] || in_array( $feature['key'], $keys, true ) ) {
				continue;
			}
			$keys[]     = $feature['key'];
			$features[] = $feature;
		}

		return $features;
	}

	/**
	 * One area by key.
	 *
	 * @param string $key Feature key.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $key ): ?array {
		foreach ( self::all() as $feature ) {
			if ( $key === $feature['key'] ) {
				return $feature;
			}
		}

		return null;
	}

	/**
	 * Who owns what: dimension => value => feature keys.
	 *
	 * Core areas may share a value (every document module owns the Documents
	 * page); an add-on claim on anything already owned is dropped and
	 * logged, so third-party code can never widen what a core area hides.
	 *
	 * @return array<string, array<string, string[]>>
	 */
	public static function index(): array {
		$index = array_fill_keys( self::DIMENSIONS, array() );
		$core  = array_column( self::core(), 'key' );

		foreach ( self::all() as $feature ) {
			$is_core = in_array( $feature['key'], $core, true );

			foreach ( self::DIMENSIONS as $dimension ) {
				foreach ( $feature['owns'][ $dimension ] as $value ) {
					if ( ! $is_core && isset( $index[ $dimension ][ $value ] ) ) {
						$conflict = $feature['key'] . '|' . $dimension . '|' . $value;
						if ( isset( self::$logged_conflicts[ $conflict ] ) ) {
							continue;
						}
						self::$logged_conflicts[ $conflict ] = true;
						doublescale_get_logger()->warning(
							'Client portal area claims something another area owns',
							array(
								'code'      => 'portal_feature_conflict',
								'feature'   => $feature['key'],
								'dimension' => $dimension,
								'value'     => $value,
								'owner'     => $index[ $dimension ][ $value ],
							)
						);
						continue;
					}
					$index[ $dimension ][ $value ][] = $feature['key'];
				}
			}
		}

		return $index;
	}

	/**
	 * Whether an area can show at all right now: its module is on (and Pro is
	 * there when the area is a Pro one). A saved choice is kept while it is not.
	 *
	 * @param array<string, mixed> $feature Normalised area.
	 * @return bool
	 */
	public static function is_available( array $feature ): bool {
		if ( $feature['requires_pro'] && ! self::is_pro_active() ) {
			return false;
		}

		if ( is_callable( $feature['available'] ) && ! call_user_func( $feature['available'] ) ) {
			return false;
		}

		if ( array() === $feature['modules'] ) {
			return true;
		}

		foreach ( $feature['modules'] as $slug ) {
			if ( self::module_active( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The default visibility of every area (what a contact without their own
	 * access sees, module state aside).
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		$stored = self::stored_defaults();
		$out    = array();

		foreach ( self::all() as $feature ) {
			$key = $feature['key'];
			if ( 'files' === $key ) {
				$out[ $key ] = PortalUploadSettings::is_switched_on();
				continue;
			}
			$out[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : (bool) $feature['default'];
		}

		return $out;
	}

	/**
	 * Save default visibility (known keys only).
	 *
	 * @param array<string, mixed> $input Feature key => visible.
	 * @return true|\WP_Error
	 */
	public static function update_defaults( array $input ) {
		$invalid = self::invalid_values( $input );
		if ( $invalid ) {
			return $invalid;
		}

		$known = array_column( self::all(), 'key' );

		$raw    = Settings::get( self::SETTINGS_KEY, array() );
		$raw    = is_array( $raw ) ? $raw : array();
		$stored = self::stored_defaults();
		$files  = null;

		foreach ( $input as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, $known, true ) ) {
				continue;
			}
			if ( 'files' === $key ) {
				$files = rest_sanitize_boolean( $value );
				continue;
			}
			$stored[ $key ] = rest_sanitize_boolean( $value );
		}

		$raw[ self::DEFAULTS_KEY ] = $stored;
		Settings::update( self::SETTINGS_KEY, $raw );

		// Files and the upload card share one switch. After the write above:
		// PortalUploadSettings re-reads the whole `client_portal` array.
		if ( null !== $files ) {
			$saved = PortalUploadSettings::update( array( 'enabled' => $files ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return true;
	}

	/**
	 * A 400 when any value is not a yes/no. A form or an automation that sends
	 * "off" must not be read as "on", which a plain (bool) cast would do.
	 *
	 * @param array<string, mixed> $input Feature key => visible.
	 * @return \WP_Error|null
	 */
	public static function invalid_values( array $input ): ?\WP_Error {
		foreach ( $input as $key => $value ) {
			if ( ! rest_is_boolean( $value ) ) {
				return new \WP_Error(
					'invalid_feature_value',
					sprintf(
						/* translators: %s: area key */
						__( 'Send true or false for "%s".', 'doublescale' ),
						sanitize_key( (string) $key )
					),
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}

	/**
	 * Whether an area is one of the built-in ones (not an add-on's).
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public static function is_core( string $key ): bool {
		return in_array( $key, array_column( self::core(), 'key' ), true );
	}

	/**
	 * Payload for Settings → Client Portal → What clients see in the portal.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_payload(): array {
		$defaults = self::defaults();
		$rows     = array();

		foreach ( self::all() as $feature ) {
			$rows[] = array_merge(
				self::describe( $feature ),
				array( 'enabled' => (bool) ( $defaults[ $feature['key'] ] ?? $feature['default'] ) )
			);
		}

		$accounts = PortalUserProvisioner::settings();

		return array(
			'features'            => $rows,
			'customized_contacts' => PortalAccess::count_customized(),
			'auto_create_users'   => (bool) $accounts['auto_create_users'],
			'pro_active'          => self::is_pro_active(),
			'can_manage_modules'  => current_user_can( 'manage_options' ),
		);
	}

	/**
	 * The parts of a row both admin screens show: label, state and the modules
	 * behind it (with their parent), so "turn it on first" can name them.
	 *
	 * @param array<string, mixed> $feature Normalised area.
	 * @return array<string, mixed>
	 */
	public static function describe( array $feature ): array {
		$modules = array();
		$active  = 0;
		$counted = 0;
		$stored  = get_option( 'doublescale_enabled_modules', array() );
		$stored  = is_array( $stored ) ? $stored : array();

		foreach ( $feature['modules'] as $slug ) {
			$parent_slug = doublescale_child_module_parent_map()[ $slug ] ?? '';
			$is_active   = self::module_active( $slug );
			$pro_only    = function_exists( 'doublescale_is_phantom_module_toggle_slug' ) && doublescale_is_phantom_module_toggle_slug( $slug );
			$modules[]   = array(
				'slug'     => $slug,
				'label'    => self::module_label( $slug ),
				'active'   => $is_active,
				// Its own switch, parent aside: a child that is on by itself
				// only needs its parent turned on.
				'own_on'   => function_exists( 'doublescale_module_setting_enabled' )
					? doublescale_module_setting_enabled( $slug, $stored, ModuleManager::isToggleable( $slug ) )
					: $is_active,
				'pro_only' => $pro_only,
				'parent'   => '' !== $parent_slug
					? array(
						'slug'   => $parent_slug,
						'label'  => self::module_label( $parent_slug ),
						'active' => self::module_active( $parent_slug ),
					)
					: null,
			);

			// A Pro module counts towards "partly on" only where Pro could run it.
			if ( ! $pro_only || self::is_pro_active() ) {
				++$counted;
				$active += $is_active ? 1 : 0;
			}
		}

		if ( $feature['requires_pro'] && ! self::is_pro_active() ) {
			$state = 'needs_pro';
		} elseif ( ! self::is_available( $feature ) ) {
			$state = array() !== $modules ? 'module_off' : 'unavailable';
		} elseif ( $counted > 1 && $active < $counted ) {
			$state = 'partial';
		} else {
			$state = 'on';
		}

		return array(
			'key'          => $feature['key'],
			'group'        => $feature['group'],
			'section'      => $feature['section'],
			'label'        => $feature['label'],
			'description'  => $feature['description'],
			'state'        => $state,
			'requires_pro' => $feature['requires_pro'],
			'depends_on'   => $feature['depends_on'],
			'modules'      => $modules,
		);
	}

	/**
	 * Whether DoubleScale Pro is active.
	 *
	 * @return bool
	 */
	public static function is_pro_active(): bool {
		return function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active();
	}

	/**
	 * Stored defaults (only keys an admin saved).
	 *
	 * @return array<string, bool>
	 */
	private static function stored_defaults(): array {
		$raw = Settings::get( self::SETTINGS_KEY, array() );

		return is_array( $raw ) && isset( $raw[ self::DEFAULTS_KEY ] ) && is_array( $raw[ self::DEFAULTS_KEY ] )
			? array_map( 'boolval', $raw[ self::DEFAULTS_KEY ] )
			: array();
	}

	/**
	 * @param string $slug Module slug.
	 * @return bool
	 */
	private static function module_active( string $slug ): bool {
		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( $slug );
	}

	/**
	 * The module's own name, as Control Modules shows it.
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	private static function module_label( string $slug ): string {
		$module = ModuleManager::getModule( $slug );
		if ( $module ) {
			return (string) $module->label();
		}

		$meta = function_exists( 'doublescale_phantom_module_admin_meta' ) ? doublescale_phantom_module_admin_meta( $slug ) : null;
		if ( is_array( $meta ) && ! empty( $meta['label'] ) ) {
			return (string) $meta['label'];
		}

		return function_exists( 'doublescale_automation_module_label' )
			? doublescale_automation_module_label( $slug )
			: ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Fill in every field an area may leave out.
	 *
	 * @param array<string, mixed> $feature Raw area.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $feature ): array {
		$owns = isset( $feature['owns'] ) && is_array( $feature['owns'] ) ? $feature['owns'] : array();
		foreach ( self::DIMENSIONS as $dimension ) {
			$values             = isset( $owns[ $dimension ] ) && is_array( $owns[ $dimension ] ) ? $owns[ $dimension ] : array();
			$owns[ $dimension ] = array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) );
		}

		$modules = isset( $feature['modules'] ) && is_array( $feature['modules'] ) ? $feature['modules'] : array();
		$depends = isset( $feature['depends_on'] ) && is_array( $feature['depends_on'] ) ? $feature['depends_on'] : array();

		return array(
			'key'          => isset( $feature['key'] ) ? sanitize_key( (string) $feature['key'] ) : '',
			'group'        => isset( $feature['group'] ) && in_array( $feature['group'], array( 'main', 'crm', 'other' ), true ) ? $feature['group'] : 'other',
			'section'      => isset( $feature['section'] ) ? sanitize_key( (string) $feature['section'] ) : '',
			'label'        => isset( $feature['label'] ) ? (string) $feature['label'] : '',
			'description'  => isset( $feature['description'] ) ? (string) $feature['description'] : '',
			'default'      => ! array_key_exists( 'default', $feature ) || (bool) $feature['default'],
			'requires_pro' => ! empty( $feature['requires_pro'] ),
			'available'    => isset( $feature['available'] ) && is_callable( $feature['available'] ) ? $feature['available'] : null,
			'modules'      => array_values( array_map( 'sanitize_key', array_map( 'strval', $modules ) ) ),
			'depends_on'   => array_values( array_map( 'sanitize_key', array_map( 'strval', $depends ) ) ),
			'owns'         => $owns,
		);
	}
}
