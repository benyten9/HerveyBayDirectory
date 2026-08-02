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
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_deferred_migration_notices' ) );
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
		if ( 'yes' === get_transient( 'doublescale_installing' ) ) {
			return;
		}

		set_transient( 'doublescale_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		$registry = self::migration_registry();

		MigrationRunner::run_all( $registry );

		MigrationRunner::repair_missing_tables( $registry );

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
