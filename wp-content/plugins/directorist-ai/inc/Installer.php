<?php
/**
 * Plugin lifecycle entry point.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro;

use DirectoristAIPro\Traits\Singleton;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Schema;

/**
 * Registers product lifecycle operations.
 */
class Installer {
	use Singleton;

	/**
	 * Register lifecycle hooks against the actual plugin entry point.
	 */
	protected function __construct() {
		register_activation_hook( DIRECTORIST_AI_PRO_FILE, array( $this, 'activate' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_storage' ) );
	}

	/**
	 * Install only owned runtime storage without changing native content.
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 * @return void
	 */
	public function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Directorist AI separately on each site. Network activation is not supported.', 'directorist-ai' ) );
		}
		add_option( 'directorist_ai_pro_installed_version', DIRECTORIST_AI_PRO_VERSION, '', false );
		// Activation may include this plugin after plugins_loaded, before Action
		// Scheduler's version registry is populated. Initialize a wholly new owned
		// schema then; defer upgrades of existing records until every dependency
		// is healthy, as their migrations can replace owned derived graph data.
		global $wpdb;
		try {
			$status = ( new Database( $wpdb ) )->readiness()->status();
			if ( 'uninstalled' !== $status['storage_status'] && ! Core\Plugin::get_instance()->dependencies_available() ) {
				return;
			}
			$this->install_storage();
		} catch ( \Throwable $error ) {
			try {
				$status = ( new Database( $wpdb ) )->readiness()->status();
			} catch ( \Throwable $diagnostic_error ) {
				$status = array( 'storage_status' => 'unavailable' );
			}
			if ( 'future_schema' === $status['storage_status'] ) {
				/* translators: 1: installed schema version, 2: supported schema version. */
				wp_die( esc_html( sprintf( __( 'Directorist AI runtime schema %1$d is newer than this plugin supports (%2$d). Install a matching newer plugin build. No downgrade was attempted. Existing records are retained.', 'directorist-ai' ), $status['storage_version'], Schema::VERSION ) ) );
			}
			wp_die( esc_html__( 'Directorist AI could not initialize compatible runtime storage. Check database access and install a matching complete build. New requests and effects are disabled. Existing records are retained.', 'directorist-ai' ) );
		}
	}

	/**
	 * Upgrade an already-active development installation from administrator UI.
	 *
	 * @return void
	 */
	public function maybe_upgrade_storage(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Core\Plugin::get_instance()->dependencies_available() ) {
			return;
		}
		global $wpdb;
		try {
			$db     = new Database( $wpdb );
			$status = $db->readiness()->status();
			if ( in_array( $status['storage_status'], array( 'migration_required', 'uninstalled' ), true ) ) {
				$this->install_storage();
				$db->readiness()->invalidate();
				$status = $db->readiness()->status();
			}
			if ( $status['effects_enabled'] ) {
				// The owned tables and their metadata have already passed verification.
				// Repair the WordPress bookkeeping option without running another migration.
				if ( $status['option_drift'] ) {
					update_option( 'directorist_ai_pro_storage_version', $status['storage_version'], false );
					$status = $db->readiness()->status();
				}
				if ( ! $status['option_drift'] ) {
					return;
				}
			}
			if ( 'future_schema' === $status['storage_status'] ) {
				/* translators: 1: installed schema version, 2: supported schema version. */
				$message = sprintf( __( 'Directorist AI runtime schema %1$d is newer than this plugin supports (%2$d). Install a matching newer plugin build. New requests and effects are disabled. No downgrade was attempted. Existing records are retained.', 'directorist-ai' ), $status['storage_version'], Schema::VERSION );
			} elseif ( $status['effects_enabled'] ) {
				$message = __( 'Directorist AI storage is ready, but its schema-version cache could not be updated. AI functionality remains available.', 'directorist-ai' );
			} else {
				$message = __( 'Directorist AI runtime storage is incomplete or unavailable. New requests and effects are disabled. Check database access and install a matching complete plugin build. Existing records are retained.', 'directorist-ai' );
			}
		} catch ( \Throwable $error ) {
			$message = __( 'Directorist AI runtime storage is unavailable. New requests and effects are disabled. Check database access. Existing records are retained.', 'directorist-ai' );
		}
		$notice_class = isset( $status['effects_enabled'] ) && $status['effects_enabled'] ? 'notice-warning' : 'notice-error';
		add_action(
			'admin_notices',
			static function () use ( $message, $notice_class ) {
				echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $message ) . '</p></div>';
			}
		);
	}

	/**
	 * Publish the storage version only after all required DDL succeeds.
	 *
	 * @return void
	 */
	private function install_storage(): void {
		global $wpdb;
		$db     = new Database( $wpdb );
		$status = $db->readiness()->status();
		if ( ! in_array( $status['storage_status'], array( 'ready', 'uninstalled', 'migration_required' ), true ) ) {
			$db->readiness()->assert_ready();
		}
		Schema::migrate( $db );
		$db->readiness()->assert_ready();
		update_option( 'directorist_ai_pro_storage_version', Schema::VERSION, false );
	}
}
