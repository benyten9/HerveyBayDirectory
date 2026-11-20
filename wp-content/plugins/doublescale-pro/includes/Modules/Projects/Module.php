<?php
/**
 * Projects module bootstrap.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Projects;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Core\ModuleManager;
use DoubleScale\Modules\Portal\Services\PortalFeatures;
use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Pro\Modules\Projects\Abilities\ProjectAbilities;
use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;

final class Module extends AbstractModule {

	/**
	 * Read-only project abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return ProjectAbilities::definitions();
	}

	public function slug(): string {
		return 'projects';
	}

	public function label(): string {
		return __( 'Projects', 'doublescale' );
	}

	public function description(): string {
		return __( 'Manage projects with kanban statuses, tasks, and linked invoices.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function dependencies(): array {
		return array( 'core', 'contacts' );
	}

	public function onActivate(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		UserRoles::provision_project_roles();
		Capabilities::sync_capabilities_for_user_roles();
	}

	public function onDeactivate(): void {
		UserRoles::deprovision_project_roles();
		UserRoles::enforce_module_scoped_roles();
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\ProjectManager::class,
			static fn() => Services\ProjectManager::instance()
		);
		$container->singleton(
			Services\ProjectActivityLogger::class,
			static fn() => new Services\ProjectActivityLogger()
		);
		$container->singleton(
			Services\ProjectPortalProvider::class,
			static fn() => new Services\ProjectPortalProvider()
		);
		$container->singleton(
			Services\ProjectDocumentLinker::class,
			static fn() => new Services\ProjectDocumentLinker()
		);
		$container->singleton(
			Services\ProjectCalendarProvider::class,
			static fn() => new Services\ProjectCalendarProvider()
		);
		$container->singleton(
			Services\ProjectTeamSync::class,
			static fn() => new Services\ProjectTeamSync()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestProjectController::class,
			Rest\Controllers\RestProjectStatusController::class,
			Rest\Controllers\RestPortalProjectController::class,
			Rest\Controllers\RestPublicProjectController::class,
			Rest\Controllers\RestProjectTeamController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );
		$checks = static function () {
			Migrations\ProjectStatusesTableProtectedColumn::ensure();
			Migrations\ProjectsTableHashColumn::ensure();
			Migrations\ProjectsTableProgressColumn::ensure();
			Migrations\ProjectsTableBillingColumns::ensure();
			Migrations\ProjectMembersTable::ensure();
		};
		if ( function_exists( 'doublescale_ensure_once' ) ) {
			doublescale_ensure_once( 'pro_projects', DOUBLESCALE_PRO_VERSION, $checks );
		} else {
			$checks();
		}
		Services\ProjectTeam::mark_table_ready();

		// Support tickets can be linked to projects (free stores the link).
		Services\TicketProjectLink::register();
		// Roles may have been provisioned before Pro Projects loaded (shell caps
		// only). Re-provision + sync so project_* caps land on Project Manager /
		// Member and are stripped from Sales roles.
		UserRoles::provision_project_roles();
		Capabilities::ensure_capabilities_synced();
		self::maybe_migrate_crm_managers_to_project_managers();
		// The migration notice is retired: drop its leftover flag.
		if ( false !== get_option( 'doublescale_projects_role_migration_notice', false ) ) {
			delete_option( 'doublescale_projects_role_migration_notice' );
		}

		// The project form offers "Show tasks in the client portal" only when
		// the installed free portal can show them and tasks are on.
		add_filter(
			'doublescale_admin_config',
			static function ( $config ) {
				if ( is_array( $config ) ) {
					$config['portalProjectTasks'] = defined( PortalFeatures::class . '::SUPPORTS_PROJECT_TASKS' )
						&& ModuleManager::isEnabled( 'tasks' );
				}
				return $config;
			}
		);

		$container->get( Services\ProjectManager::class );
		$container->get( Services\ProjectActivityLogger::class )->register();
		$container->get( Services\ProjectPortalProvider::class );
		$container->get( Services\ProjectCalendarProvider::class );
		$container->get( Services\ProjectDocumentLinker::class )->register();
		$container->get( Services\ProjectTeamSync::class )->register();
		// A time tracking add-on from before project teams shows members the rates.
		add_action( 'rest_api_init', array( Services\TimeTrackingMoneyGuard::class, 'register' ) );
		new Renderer\ProjectFrontendHandler();

		// Auto-create the page hosting that shortcode, so a shared project links
		// somewhere instead of resolving to an empty URL. Guarded: an older free
		// has no such class, and Pro must degrade silently rather than fatal.
		if ( class_exists( ShortcodePageProvisioner::class ) ) {
			ShortcodePageProvisioner::register(
				Renderer\ProjectFrontendHandler::SHORTCODE_NAME,
				array(
					'title' => __( 'Project', 'doublescale-pro' ),
					'slug'  => 'doublescale-project',
				)
			);
		}

		add_action( 'admin_menu', array( self::class, 'scope_menu_for_project_only_users' ), 9999 );

		// The Projects tab and figure on a company page.
		Services\CompanyProjects::register();

		// Deleting a company detaches its projects too.
		add_filter(
			'doublescale_company_tables',
			static function ( $tables ) {
				$tables[] = 'doublescale_projects';
				return $tables;
			}
		);

		// projects.deal_id has no FK; detach it when the source deal is deleted
		// so project views never resolve a dead deal.
		add_action(
			'doublescale_deal_deleted',
			static function ( $deal_id ) {
				if (
					function_exists( 'doublescale_is_module_storage_ready' )
					&& ! doublescale_is_module_storage_ready( 'projects', Models\ProjectModel::class )
				) {
					return;
				}
				try {
					Models\ProjectModel::query()
						->where( 'deal_id', (int) $deal_id )
						->update( array( 'deal_id' => null ) );
				} catch ( \Throwable $e ) {
					// Table missing — nothing to detach.
				}
			}
		);

		// Deleting a contact cascades to their projects (one model at a time so
		// the deleting/deleted events clean associations, discussions, tasks).
		add_action(
			'doublescale_contact_deleting',
			static function ( $contact ) {
				if ( ! $contact || ! isset( $contact->id ) ) {
					return;
				}
				if (
					function_exists( 'doublescale_is_module_storage_ready' )
					&& ! doublescale_is_module_storage_ready( 'projects', Models\ProjectModel::class )
				) {
					return;
				}
				try {
					$projects = Models\ProjectModel::where( 'contact_id', (int) $contact->id )->get();
					foreach ( $projects as $project ) {
						$project->delete();
					}
				} catch ( \Throwable $e ) {
					// Table missing or mid-migration — skip cascade.
				}
			}
		);

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Projects', 'doublescale' ),
				'menu_title'      => __( 'Projects', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=projects',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 47,
				'group'           => 'sales',
				'requires_module' => 'projects',
			)
		);

		$this->loadModuleMergeTagFiles();
		Reminders\ProjectAutomationSweeper::instance();
	}

	/**
	 * One-time: grant Project Manager to every CRM Manager on upgrade.
	 *
	 * @return void
	 */
	private static function maybe_migrate_crm_managers_to_project_managers(): void {
		if ( get_option( 'doublescale_projects_role_migrated' ) ) {
			return;
		}

		$users = get_users(
			array(
				'role'   => UserRoles::CRM_MANAGER,
				'fields' => array( 'ID' ),
			)
		);

		foreach ( $users as $user ) {
			$wp_user = get_userdata( (int) $user->ID );
			if ( ! $wp_user ) {
				continue;
			}
			if ( ! in_array( UserRoles::PROJECT_MANAGER, (array) $wp_user->roles, true ) ) {
				$wp_user->add_role( UserRoles::PROJECT_MANAGER );
			}
		}

		update_option( 'doublescale_projects_role_migrated', true, false );
	}

	/**
	 * Remove every DoubleScale submenu project-only staff do not use (see doublescale_project_staff_admin_paths).
	 *
	 * @return void
	 */
	public static function scope_menu_for_project_only_users(): void {
		if ( ! Permissions::is_project_only() ) {
			return;
		}

		$menu_slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming
		global $submenu;
		if ( empty( $submenu[ $menu_slug ] ) || ! is_array( $submenu[ $menu_slug ] ) ) {
			return;
		}

		/**
		 * Admin pages project-only staff keep in the menu: projects, their
		 * tasks and their own settings. Add-ons add theirs (e.g. `time`).
		 *
		 * @param string[] $paths `path=` values.
		 */
		$paths = (array) apply_filters( 'doublescale_project_staff_admin_paths', array( 'projects', 'tasks', 'settings' ) );

		foreach ( $submenu[ $menu_slug ] as $key => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			$keep = false;
			foreach ( $paths as $path ) {
				if ( false !== strpos( $slug, 'path=' . $path ) ) {
					$keep = true;
					break;
				}
			}
			if ( ! $keep ) {
				unset( $submenu[ $menu_slug ][ $key ] );
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming (re-indexing the same global filtered above).
		$submenu[ $menu_slug ] = array_values( $submenu[ $menu_slug ] );
	}
}
