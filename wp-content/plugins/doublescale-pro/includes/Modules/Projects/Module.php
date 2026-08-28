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
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestProjectController::class,
			Rest\Controllers\RestProjectStatusController::class,
			Rest\Controllers\RestPortalProjectController::class,
			Rest\Controllers\RestPublicProjectController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );
		Migrations\ProjectStatusesTableProtectedColumn::ensure();
		Migrations\ProjectsTableHashColumn::ensure();
		Migrations\ProjectsTableProgressColumn::ensure();
		// Roles may have been provisioned before Pro Projects loaded (shell caps
		// only). Re-provision + sync so project_* caps land on Project Manager /
		// Member and are stripped from Sales roles.
		UserRoles::provision_project_roles();
		Capabilities::ensure_capabilities_synced();
		self::maybe_migrate_crm_managers_to_project_managers();
		add_action( 'admin_notices', array( self::class, 'maybe_show_role_migration_notice' ) );
		add_action( 'wp_ajax_doublescale_dismiss_projects_role_notice', array( self::class, 'dismiss_role_migration_notice' ) );

		$container->get( Services\ProjectManager::class );
		$container->get( Services\ProjectActivityLogger::class )->register();
		$container->get( Services\ProjectPortalProvider::class );
		new Renderer\ProjectFrontendHandler();

		add_action( 'admin_menu', array( self::class, 'scope_menu_for_project_only_users' ), 9999 );

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
		update_option( 'doublescale_projects_role_migration_notice', 'pending', false );
	}

	/**
	 * @return void
	 */
	public static function maybe_show_role_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_option( 'doublescale_projects_role_migration_notice' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'doublescale_dismissed_projects_role_notice', true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=doublescale_dismiss_projects_role_notice' ),
			'doublescale_dismiss_projects_role_notice'
		);

		printf(
			'<div class="notice notice-info is-dismissible" data-dismiss-url="%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_url( $dismiss_url ),
			esc_html__( 'DoubleScale Projects:', 'doublescale' ),
			esc_html__(
				'Existing CRM Managers were automatically granted the Project Manager role so they keep project access. Review team roles in Settings → Team.',
				'doublescale'
			)
		);

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script(
			'jquery',
			'jQuery(function($){$(\'.notice.is-dismissible[data-dismiss-url]\').on(\'click\',\'.notice-dismiss\',function(){var u=$(this).closest(\'.notice\').data(\'dismiss-url\');if(u){$.post(u);}});});'
		);
	}

	/**
	 * @return void
	 */
	public static function dismiss_role_migration_notice(): void {
		check_ajax_referer( 'doublescale_dismiss_projects_role_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}
		update_user_meta( get_current_user_id(), 'doublescale_dismissed_projects_role_notice', true );
		wp_send_json_success();
	}

	/**
	 * Remove every DoubleScale submenu except Projects for project-only staff.
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

		foreach ( $submenu[ $menu_slug ] as $key => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false === strpos( $slug, 'path=projects' ) ) {
				unset( $submenu[ $menu_slug ][ $key ] );
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu trimming (re-indexing the same global filtered above).
		$submenu[ $menu_slug ] = array_values( $submenu[ $menu_slug ] );
	}
}
