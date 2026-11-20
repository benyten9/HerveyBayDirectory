<?php
/**
 * Database installation entry point.
 *
 * @package DoubleScale\Database
 */

namespace DoubleScale\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Container;
use DoubleScale\Core\CoreModule;
use DoubleScale\Core\Database\MigrationRunner;
use DoubleScale\Core\ModuleRegistry;

/**
 * Install entry for DoubleScale.
 */
class Install {

	/**
	 * Option storing the last deferred activities.contact_id column drop error.
	 */
	private const CONTACT_ID_DROP_DEFERRED_OPTION = 'doublescale_activity_contact_id_drop_deferred';

	private static function migration_registry(): ModuleRegistry {
		$container = new Container();
		$registry  = new ModuleRegistry( $container );
		$registry->register( new CoreModule() );
		$registry->discover( DOUBLESCALE_PLUGIN_DIR . 'includes/Modules' );

		return $registry;
	}

	/**
	 * Ensure critical tables exist before the kernel boots (plugins_loaded runs before init).
	 *
	 * If only part of the schema exists (e.g. `doublescale_contacts` without `doublescale_terms`
	 * after a partial install), run migrations again so boot-time code (automation rules, etc.)
	 * does not query missing tables.
	 */
	public static function ensure_db_ready(): void {
		global $wpdb;

		$critical = array(
			$wpdb->prefix . 'doublescale_contacts',
			$wpdb->prefix . 'doublescale_terms',
		);

		foreach ( $critical as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				self::install();
				return;
			}
		}

		self::repair_missing_schema_tables();
		self::maybe_sync_declared_columns();
		self::protect_attachment_storage();
	}

	/**
	 * Lock the default attachment folder against direct web access. Sites
	 * stored contact, deal and project files there before it was locked; every
	 * download already goes through the signed `?ds_file=` handler.
	 *
	 * @return void
	 */
	private static function protect_attachment_storage(): void {
		if ( ! class_exists( \DoubleScale\Core\Services\AttachmentService::class ) ) {
			return;
		}

		try {
			\DoubleScale\Core\Services\AttachmentService::protect_default_storage();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Could not protect the attachments folder',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Once per schema revision: add columns that exist on CREATE but not on disk.
	 *
	 * @return void
	 */
	private static function maybe_sync_declared_columns(): void {
		$revision = defined( 'DOUBLESCALE_SCHEMA_REV' ) ? DOUBLESCALE_SCHEMA_REV : '0';
		if ( (string) get_option( 'doublescale_schema_sync_rev', '' ) === $revision ) {
			return;
		}

		MigrationRunner::ensure_declared_columns( self::migration_registry() );
		update_option( 'doublescale_schema_sync_rev', $revision );
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_create_tables_for_older_pro' ), 6 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_deferred_migration_notices' ) );
	}

	/**
	 * Temporary safety net for DoubleScale Pro before 1.5.8. Remove once those
	 * Pro versions are no longer supported.
	 *
	 * Since 1.9.9 a module's tables exist only once it has been switched on.
	 * Pro before 1.5.8 still reads the tables of switched-off modules (deleting
	 * a project clears its tickets, the credit notes report reads invoices),
	 * and DoubleScale 1.9.8 created every table, which hid that. A site that
	 * updates DoubleScale before Pro would hit those errors, so while such an
	 * older Pro is active the tables are created as 1.9.8 did. Once Pro is
	 * updated nothing happens here. Tables are never deleted either way.
	 *
	 * Pro 1.5.8+ is recognised by doublescale_pro_module_data_models() (it
	 * follows the cross-module rule), not by its version number.
	 *
	 * @return void
	 */
	public static function maybe_create_tables_for_older_pro(): void {
		if ( ! defined( 'DOUBLESCALE_PRO_VERSION' ) || function_exists( 'doublescale_pro_module_data_models' ) ) {
			return;
		}

		$done = DOUBLESCALE_VERSION . '|' . DOUBLESCALE_PRO_VERSION;
		if ( get_option( 'doublescale_older_pro_tables' ) === $done ) {
			return;
		}

		// The Sales modules declare no migrations while Sales is off.
		$declare_sales = static function () {
			return true;
		};
		add_filter( 'doublescale_sales_documents_ready', $declare_sales, PHP_INT_MAX );
		try {
			foreach ( self::migration_registry()->all_sorted_by_dependencies() as $module ) {
				if ( $module->is_enabled() ) {
					continue;
				}
				try {
					MigrationRunner::run_for_module( $module, true );
				} catch ( \Throwable $e ) {
					// One module's schema must not stop the others.
					if ( function_exists( 'doublescale_get_logger' ) ) {
						doublescale_get_logger()->error(
							'Could not create the tables of a switched-off module for an older Pro',
							array(
								'source' => 'install',
								'module' => $module->slug(),
								'error'  => $e->getMessage(),
							)
						);
					}
				}
			}
		} finally {
			remove_filter( 'doublescale_sales_documents_ready', $declare_sales, PHP_INT_MAX );
		}

		update_option( 'doublescale_older_pro_tables', $done );
	}

	public static function check_version(): void {
		$current_version = get_option( 'doublescale_version' );

		if ( version_compare( (string) $current_version, DOUBLESCALE_VERSION, '<' ) ) {
			self::install();
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

		if ( is_plugin_active_for_network( plugin_basename( DOUBLESCALE_PLUGIN_FILE ) ) ) {
			switch_to_blog( $blog_id );
			self::install();
			restore_current_blog();
		}
	}

	public static function install(): void {
		// Before the version below is written: a site with none yet is new.
		\DoubleScale\Core\ModuleNews::stamp_first_version();

		if ( 'yes' === get_transient( 'doublescale_installing' ) ) {
			return;
		}

		set_transient( 'doublescale_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		$registry = self::migration_registry();

		MigrationRunner::run_all( $registry );

		MigrationRunner::repair_missing_tables( $registry );

		MigrationRunner::ensure_declared_columns( $registry );

		self::maybe_unify_legacy_attachments();

		self::maybe_backfill_activity_contact_associations();

		self::maybe_ensure_activity_association_unique_index();

		self::maybe_repair_terms_schema();

		self::maybe_drop_activity_contact_id_column();

		// Provision DoubleScale roles (Support roles always; CRM roles only
		// when Pro is loaded — gating lives inside `add_roles_and_capabilities`).
		if ( class_exists( \DoubleScale\Core\UserRoles\UserRoles::class ) ) {
			\DoubleScale\Core\UserRoles\UserRoles::add_roles_and_capabilities();
		}

		self::update_doublescale_version();
		update_option( 'doublescale_schema_sync_rev', defined( 'DOUBLESCALE_SCHEMA_REV' ) ? DOUBLESCALE_SCHEMA_REV : '0' );

		delete_transient( 'doublescale_installing' );
	}

	private static function update_doublescale_version(): void {
		update_option( 'doublescale_version', DOUBLESCALE_VERSION );
	}

	/**
	 * Re-create module tables when the migrations ledger is ahead of the physical schema.
	 *
	 * @return void
	 */
	private static function repair_missing_schema_tables(): void {
		MigrationRunner::repair_missing_tables( self::migration_registry() );
	}

	/**
	 * Retry the legacy attachment data migration until the unified flag is set.
	 *
	 * @return void
	 */
	private static function maybe_unify_legacy_attachments(): void {
		if ( get_option( 'doublescale_attachments_unified' ) ) {
			return;
		}

		if ( ! class_exists( \DoubleScale\Core\Database\Migrations\MigrateLegacyAttachments::class ) ) {
			return;
		}

		try {
			( new \DoubleScale\Core\Database\Migrations\MigrateLegacyAttachments() )->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Legacy attachment unification deferred',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Retry the contact→association backfill until the migrated flag is set.
	 *
	 * @return void
	 */
	private static function maybe_backfill_activity_contact_associations(): void {
		if ( get_option( 'doublescale_activity_contact_assoc_migrated' ) ) {
			return;
		}

		if ( ! class_exists( \DoubleScale\Modules\Activities\Migrations\BackfillContactActivityAssociations::class ) ) {
			return;
		}

		try {
			( new \DoubleScale\Modules\Activities\Migrations\BackfillContactActivityAssociations() )->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Contact activity association backfill deferred',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Ensure activity_associations has unique_activity_entity (idempotent, no flag).
	 *
	 * Runs on every install so fresh installs and sites that skipped the backfill
	 * path still enforce one association per (activity, entity_type, entity_id).
	 *
	 * @return void
	 */
	private static function maybe_ensure_activity_association_unique_index(): void {
		if ( ! class_exists( \DoubleScale\Modules\Activities\Migrations\EnsureActivityAssociationUniqueIndex::class ) ) {
			return;
		}

		try {
			( new \DoubleScale\Modules\Activities\Migrations\EnsureActivityAssociationUniqueIndex() )->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Activity association unique index deferred',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Repair doublescale_terms when the physical table predates unified schema.
	 *
	 * @return void
	 */
	private static function maybe_repair_terms_schema(): void {
		if ( ! class_exists( \DoubleScale\Modules\Contacts\Migrations\TermsSchemaRepair::class ) ) {
			return;
		}

		try {
			( new \DoubleScale\Modules\Contacts\Migrations\TermsSchemaRepair() )->run();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Terms schema repair deferred',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Retry dropping activities.contact_id until the dropped flag is set.
	 *
	 * @return void
	 */
	private static function maybe_drop_activity_contact_id_column(): void {
		if ( get_option( 'doublescale_activity_contact_id_dropped' ) ) {
			return;
		}

		if ( ! class_exists( \DoubleScale\Modules\Activities\Migrations\DropActivityContactIdColumn::class ) ) {
			return;
		}

		try {
			( new \DoubleScale\Modules\Activities\Migrations\DropActivityContactIdColumn() )->run();
			delete_option( self::CONTACT_ID_DROP_DEFERRED_OPTION );
		} catch ( \Throwable $e ) {
			update_option(
				self::CONTACT_ID_DROP_DEFERRED_OPTION,
				array(
					'message' => $e->getMessage(),
					'at'      => time(),
				)
			);

			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Activity contact_id column drop deferred',
					array(
						'source' => 'install',
						'error'  => $e->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Surface deferred activity schema migrations to site administrators.
	 *
	 * @return void
	 */
	public static function maybe_show_deferred_migration_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'doublescale_activity_contact_id_dropped' ) ) {
			return;
		}

		$deferred = get_option( self::CONTACT_ID_DROP_DEFERRED_OPTION );
		if ( ! is_array( $deferred ) || empty( $deferred['message'] ) ) {
			return;
		}

		$message = esc_html( (string) $deferred['message'] );
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'DoubleScale database migration pending:', 'doublescale' ),
			$message
		);
	}
}
