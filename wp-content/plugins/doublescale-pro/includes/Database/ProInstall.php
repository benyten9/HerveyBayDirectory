<?php
/**
 * Pro plugin DB install / migrations (separate from free {@see \DoubleScale\Database\Install}).
 *
 * @package DoubleScale\Pro\Database
 */

namespace DoubleScale\Pro\Database;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Container;
use DoubleScale\Core\CoreModule;
use DoubleScale\Core\Database\MigrationRunner;
use DoubleScale\Core\ModuleRegistry;
use DoubleScale\Modules\Automations\Migrations\AbandonedCartsTable;
use DoubleScale\Modules\Smtp\Migrations\SmtpEmailLogTable;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * Handles migrations for Pro-only modules and Pro-specific table checks.
 */
final class ProInstall {

	/**
	 * Module graph for Pro module migrations (no boot).
	 */
	private static function migration_registry(): ModuleRegistry {
		$container = new Container();
		$registry  = new ModuleRegistry( $container );
		$registry->register( new CoreModule() );
		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			$registry->discover(
				DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules',
				array( 'Activities', 'Automations', 'Campaigns', 'Contacts', 'Tracking' ),
				'DoubleScale\\Pro\\Modules\\'
			);
		}

		return $registry;
	}

	/**
	 * Extra critical tables beyond free {@see Install::ensure_db_ready()}.
	 */
	public static function ensure_db_ready(): void {
		if ( class_exists( Install::class ) ) {
			Install::ensure_db_ready();
		}

		global $wpdb;

		$critical = array(
			$wpdb->prefix . 'doublescale_contact_taxonomy_relationship',
			$wpdb->prefix . 'doublescale_terms',
		);

		foreach ( $critical as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- SHOW TABLES LIKE.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				self::install();
				return;
			}
		}

		$smtp_log_table = $wpdb->prefix . 'doublescale_smtp_email_log';
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- SHOW TABLES LIKE.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $smtp_log_table ) ) !== $smtp_log_table ) {
			$migration = new SmtpEmailLogTable();
			$migration->run();
		}

		$abandoned_carts_table = $wpdb->prefix . 'doublescale_abandoned_carts';
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- SHOW TABLES LIKE.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $abandoned_carts_table ) ) !== $abandoned_carts_table ) {
			self::run_abandoned_carts_migration();
		}

		self::ensure_sales_approvals_table();
	}

	/**
	 * Run the abandoned-carts migration. The class lives under the
	 * `DoubleScale\Modules\Automations\Migrations` namespace (kept from the
	 * free→Pro move) and is outside Pro's PSR-4 autoload prefix, so we load
	 * the file explicitly before instantiating it.
	 */
	private static function run_abandoned_carts_migration(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return;
		}
		$file = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Automations/Migrations/AbandonedCartsTable.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		require_once $file;
		if ( class_exists( AbandonedCartsTable::class ) ) {
			( new AbandonedCartsTable() )->run();
		}
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
	}

	public static function check_version(): void {
		$current_version = get_option( 'doublescale_pro_version' );
		$plugin_version  = defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '0';

		if ( version_compare( (string) $current_version, $plugin_version, '<' ) ) {
			self::install();
			do_action( 'doublescale_pro_updated' );
		}
	}

	/**
	 * @param bool $network_wide Network activation flag.
	 */
	public static function multisite_activate( $network_wide ): void {
		global $wpdb;

		if ( is_multisite() && $network_wide ) {
			$blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( $blog_id );
				self::install();
				restore_current_blog();
			}
		} else {
			self::install();
		}
	}

	/**
	 * @param int $blog_id New blog ID.
	 */
	public static function activate_new_site( $blog_id ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active_for_network( plugin_basename( DOUBLESCALE_PRO_PLUGIN_FILE ) ) ) {
			switch_to_blog( $blog_id );
			self::install();
			restore_current_blog();
		}
	}

	public static function install(): void {
		if ( ! class_exists( \DoubleScale\Core\ModuleRegistry::class ) ) {
			return;
		}

		if ( 'yes' === get_transient( 'doublescale_pro_installing' ) ) {
			return;
		}

		set_transient( 'doublescale_pro_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		MigrationRunner::run_all( self::migration_registry() );

		MigrationRunner::repair_missing_tables( self::migration_registry() );

		self::run_abandoned_carts_migration();

		self::run_version_migrations();

		if ( class_exists( UserRoles::class ) ) {
			UserRoles::add_roles_and_capabilities();
		}

		self::update_pro_version();

		delete_transient( 'doublescale_pro_installing' );
	}

	private static function update_pro_version(): void {
		if ( defined( 'DOUBLESCALE_PRO_VERSION' ) ) {
			update_option( 'doublescale_pro_version', DOUBLESCALE_PRO_VERSION );
		}
	}

	private static function run_version_migrations(): void {
		$current_version = get_option( 'doublescale_pro_version' );
		if ( ! $current_version ) {
			return;
		}

		do_action( 'doublescale_pro_run_version_migrations', $current_version );
	}

	/**
	 * Ensure the sales approvals table exists when the workflow toggle is on.
	 *
	 * @return void
	 */
	private static function ensure_sales_approvals_table(): void {
		if ( ! class_exists( \DoubleScale\Modules\Sales\Services\SalesSettings::class ) ) {
			return;
		}

		if ( ! (bool) \DoubleScale\Modules\Sales\Services\SalesSettings::get( 'approval_workflow_enabled', false ) ) {
			return;
		}

		if ( ! class_exists( \DoubleScale\Pro\Modules\Sales\Approvals\Services\ApprovalWorkflow::class ) ) {
			return;
		}

		\DoubleScale\Pro\Modules\Sales\Approvals\Services\ApprovalWorkflow::ensure_storage();
	}
}
