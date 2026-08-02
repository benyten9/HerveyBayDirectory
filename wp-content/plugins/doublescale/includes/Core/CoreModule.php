<?php
/**
 * Core module — admin shell, DB lifecycle, roles, logging, and general REST.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class CoreModule extends AbstractModule {

	public function slug(): string {
		return 'core';
	}

	public function label(): string {
		return __( 'Core', 'doublescale' );
	}

	public function description(): string {
		return __( 'Essential platform infrastructure: admin UI, database, user roles, and settings.', 'doublescale' );
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function version(): string {
		return '1.0.0';
	}

	public function dependencies(): array {
		return array();
	}

	public function migrations(): array {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return array();
		}
		$base   = DOUBLESCALE_PLUGIN_DIR;
		$logger = (array) glob( $base . 'includes/Core/Logger/Migrations/*.php' );
		sort( $logger );
		$task_meta        = $base . 'includes/Core/Database/Migrations/TaskMetaTable.php';
		$attachments      = $base . 'includes/Core/Database/Migrations/AttachmentsTable.php';
		$migrate_legacy   = $base . 'includes/Core/Database/Migrations/MigrateLegacyAttachments.php';
		return array_merge( array( $task_meta, $attachments, $migrate_legacy ), $logger );
	}

	public function register( Container $container ): void {
		$container->singleton(
			\DoubleScale\Core\MergeTags\MergeTagsManager::class,
			static function () {
				return \DoubleScale\Core\MergeTags\MergeTagsManager::instance();
			}
		);

		$container->singleton( Events\EventDispatcher::class );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestGeneralController::class,
			Rest\Controllers\RestListPreferencesController::class,
			Rest\Controllers\RestAdminCalendarController::class,
			Rest\Controllers\RestModulesController::class,
			Rest\Controllers\RestPluginsController::class,
			Rest\Controllers\RestSiteVerificationController::class,
			Rest\Controllers\RestLicenseController::class,
			Rest\Controllers\RestDeviceController::class,
			Settings\Rest\RestSettingsController::class,
			Settings\Rest\RestSettingsControllerPro::class,
			Logger\Rest\RestLogController::class,
			UserRoles\Rest\RestUserManagementController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		\DoubleScale\Admin\Admin::instance();
		\DoubleScale\Admin\AdminLoader::instance();
		\DoubleScale\Modules\Contacts\Services\SubscriptionManager::instance();
		\DoubleScale\Modules\Contacts\CustomMetabox::get_instance();
		\DoubleScale\Core\UserRoles\UserRoles::instance();
		\DoubleScale\Core\UserRoles\UserRoles::register_woocommerce_bypass();
		\DoubleScale\Core\UserRoles\UserRoles::register_enforcement_hooks();
		\DoubleScale\Core\UserRoles\UserRoles::ensure_provisioned();
		\DoubleScale\Core\UserRoles\LoginRedirect::instance();
		\DoubleScale\Core\UserRoles\WooCommerceCompat::instance();
		\DoubleScale\Database\Install::init();
		\DoubleScale\Website\Site::instance();

		new \DoubleScale\Core\Renderer\AttachmentServeHandler();

		// The DoubleScale top-level admin menu and every CRM REST route check the
		// `doublescale_access` capability. Historically this cap was granted as a
		// side-effect of Booking module boot. Booking now installs on explicit
		// opt-in only, so grant the base access cap here (Core) to keep the admin
		// usable on fresh installs.
		$this->ensure_base_access_capability();

		if ( is_multisite() ) {
			add_filter( 'user_has_cap', array( $this, 'grant_super_admin_caps' ), 10, 4 );
		}
		add_filter( 'doublescale_log_handler_register', array( $this, 'register_log_handlers' ) );
		add_action( 'init', array( $this, 'register_contact_meta_table' ) );
		add_action( 'init', array( $this, 'register_cron_schedules' ) );

		add_action( 'doublescale_daily_doublescale_daily3', array( \DoubleScale\Core\Tasks::class, 'cleanup_old_tasks' ) );

		// Use a smaller batch size so Action Scheduler's own cleanup deletes happen
		// in short bursts that won't lock the table long enough to cause latency
		// spikes. This is safe to set globally — it only affects delete batch size,
		// not which records get deleted or when.
		add_filter(
			'action_scheduler_cleanup_batch_size',
			static function () {
				return 100;
			}
		);

		// Cap the queue runner's per-request time budget so DoubleScale tasks cannot
		// monopolize the entire WP-Cron tick. Other plugins (e.g. UpdraftPlus) share
		// the same Action Scheduler queue and need processing time.
		add_filter(
			'action_scheduler_queue_runner_time_limit',
			static function ( $time_limit ) {
				return min( (int) $time_limit, 15 );
			}
		);

		// Load the field-type files via the cached manifest instead of globbing
		// the directory on every request. Falls back to a glob when no manifest
		// is available (same behaviour as before, but cached once built).
		$this->loadManifestOrGlobs(
			array( 'includes/Core/Fields/Types/*.php' ),
			'core-field-types'
		);
	}

	private function ensure_base_access_capability(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		// The role → capability scan (and any add_cap() DB write) only needs to
		// run once per plugin version. Skip the get_role()/has_cap() loop on
		// every request via a version stamp; re-arm on upgrade so a release that
		// adds new roles re-provisions the base cap.
		if ( get_option( 'doublescale_base_caps_version' ) === DOUBLESCALE_VERSION ) {
			return;
		}

		$roles = array_merge(
			array( 'administrator' ),
			\DoubleScale\Core\UserRoles\UserRoles::get_known_role_slugs()
		);

		foreach ( array_unique( $roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! $role->has_cap( 'doublescale_access' ) ) {
				$role->add_cap( 'doublescale_access' );
			}
		}

		update_option( 'doublescale_base_caps_version', DOUBLESCALE_VERSION );
	}

	/**
	 * @param mixed $handlers
	 * @return mixed
	 */
	public function register_log_handlers( $handlers ) {
		$handlers   = is_array( $handlers ) ? $handlers : array();
		$handlers[] = new Logger\LogHandlerDb();
		return $handlers;
	}

	public function register_contact_meta_table(): void {
		global $wpdb;
		$wpdb->contactmeta = $wpdb->prefix . 'doublescale_contact_meta';

		add_filter(
			'get_meta_table',
			static function ( $table, $meta_type ) {
				global $wpdb;
				if ( 'contact' === $meta_type ) {
					return $wpdb->contactmeta;
				}
				return $table;
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @param \WP_User            $user
	 * @return array<string, bool>
	 */
	public function grant_super_admin_caps( $allcaps, $caps, $args, $user ) {
		if ( is_super_admin( $user->ID ) ) {
			$crm_caps = \DoubleScale\Core\UserRoles\UserRoles::get_all_caps();
			foreach ( $crm_caps as $cap => $grant ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	public function register_cron_schedules(): void {
		// Once the recurring actions are scheduled they stay scheduled, so skip
		// the Action Scheduler lookups on every request. The stamp carries the
		// plugin version so a release that adds new recurring hooks re-arms the
		// scheduling pass. Previously this fired a `set_transient` write on
		// every request that didn't hold the lock.
		if ( get_option( 'doublescale_cron_scheduled_version' ) === DOUBLESCALE_VERSION ) {
			return;
		}

		$daily = new \DoubleScale\Core\Tasks( 'doublescale_daily' );

		if ( $daily->get_next_timestamp( 'doublescale_daily3' ) === false ) {
			$daily->schedule_recurring( time(), DAY_IN_SECONDS, 'doublescale_daily3' );
		}
		if ( $daily->get_next_timestamp( 'doublescale_daily4' ) === false ) {
			$daily->schedule_recurring( time(), DAY_IN_SECONDS, 'doublescale_daily4' );
		}
		if ( $daily->get_next_timestamp( 'doublescale_cleanup_page_visits' ) === false ) {
			$daily->schedule_recurring( time(), DAY_IN_SECONDS, 'doublescale_cleanup_page_visits' );
		}

		update_option( 'doublescale_cron_scheduled_version', DOUBLESCALE_VERSION );
	}
}
