<?php
/**
 * Product bootstrap adapted from the BasePlugin lifecycle.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Core;

use DirectoristAIPro\Admin\Dashboard;
use DirectoristAIPro\Assets\Assets;
use DirectoristAIPro\Builder\BuilderModule;
use DirectoristAIPro\Chat\Placement;
use DirectoristAIPro\Chat\OwnerPlacement;
use DirectoristAIPro\Domain\DirectoristModule;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;
use DirectoristAIPro\Retrieval\ListingProjectionLifecycle;
use DirectoristAIPro\Retrieval\ListingProjectionStore;
use DirectoristAIPro\Retrieval\GroundedResponseModule;
use DirectoristAIPro\Retrieval\KnowledgeModule;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Traits\Singleton;
use DirectoristAIPro\UI\EditorAssistant;
use DirectoristAIPro\UI\ListingAssistant;

/**
 * Initializes the supported product foundation.
 */
final class Plugin {
	use Singleton;

	/**
	 * Unmet dependency messages.
	 *
	 * @var string[]
	 */
	private array $requirement_errors = array();
	/**
	 * Authoritative storage probe connection.
	 *
	 * @var Database|null
	 */
	private ?Database $db = null;

	/**
	 * Initialize supported services after dependencies have loaded.
	 */
	protected function __construct() {
		load_plugin_textdomain( 'directorist-ai', false, dirname( plugin_basename( DIRECTORIST_AI_PRO_FILE ) ) . '/languages' );
		$this->requirement_errors = $this->check_requirements();
		if ( $this->requirement_errors ) {
			add_action( 'admin_notices', array( $this, 'render_requirement_notice' ) );
			return;
		}
		\DirectoristAIPro\AI\LicenseConnection::hooks();
		\DirectoristAIPro\Extensions\ExtensionRegistry::hooks();
		( new DirectoristModule() )->hooks();
		( new \DirectoristAIPro\Listing\ListingFormModule() )->hooks();
		( new \DirectoristAIPro\Directory\DirectoryModule() )->hooks();
		( new \DirectoristAIPro\Readiness\ReadinessModule() )->hooks();
		global $wpdb;
		try {
			$this->db = new Database( $wpdb );
		} catch ( \Throwable $error ) {
			return;
		}
		( new \DirectoristAIPro\Import\ImportModule( $this->db ) )->hooks();
		( new \DirectoristAIPro\Chat\StarterSuggestions( $this->db ) )->hooks();
		( new BuilderModule() )->hooks();
		if ( $this->db->readiness()->status()['effects_enabled'] ) {
			$inspector = new DirectoristInspector();
			( new ListingProjectionLifecycle( new ListingProjectionStore( $this->db ), $inspector, new ListingQueryCatalog( $inspector ) ) )->hooks();
			$knowledge = new KnowledgeModule( $this->db );
			$knowledge->hooks();
			( new GroundedResponseModule( $this->db, $knowledge->service() ) )->hooks();
		}
		// Native blocks, widgets and theme modules must exist before policies freeze.
		if ( did_action( 'wp_loaded' ) ) {
			Runtime::get_instance();
		} else {
			add_action( 'wp_loaded', array( Runtime::class, 'get_instance' ) );
		}
		Placement::hooks();
		OwnerPlacement::hooks();
		( new EditorAssistant() )->hooks();
		( new \DirectoristAIPro\UI\EditorWorkingCopy() )->hooks();
		( new ListingAssistant() )->hooks();
		\DirectoristAIPro\UI\DirectoryAssistant::hooks();
		Dashboard::get_instance();
		Assets::get_instance();
	}

	/**
	 * Check the supported foundation environment.
	 *
	 * @return string[]
	 */
	private function check_requirements(): array {
		global $wp_version;
		$errors = array();
		if ( version_compare( $wp_version, '7.0', '<' ) || ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_ai_client_prompt' ) ) {
			$errors[] = 'wp_version';
		}
		if ( ! defined( 'ATBDP_VERSION' ) || version_compare( ATBDP_VERSION, '8.9.4', '<' ) ) {
			$errors[] = 'directorist';
		}
		if ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ plugin_basename( DIRECTORIST_AI_PRO_FILE ) ] ) ) {
			$errors[] = 'network_activation';
		}
		if ( ! class_exists( \DirectoristAIPro\Dependencies\Opis\JsonSchema\CompliantValidator::class ) ) {
			$errors[] = 'runtime_dependencies';
		}
		if ( ! class_exists( 'ActionScheduler_Versions', false ) || version_compare( \ActionScheduler_Versions::instance()->latest_version(), '4.2.0', '<' ) ) {
			$errors[] = 'scheduler';
		}
		return $errors;
	}

	/**
	 * Return foundation availability for diagnostics.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->dependencies_available() && null !== $this->db && $this->db->readiness()->status()['effects_enabled'];
	}

	/**
	 * Permit installation and diagnostics before runtime storage is ready.
	 *
	 * @return bool
	 */
	public function dependencies_available(): bool {
		return ! $this->requirement_errors;
	}

	/**
	 * Display dependency guidance to administrators.
	 *
	 * @return void
	 */
	public function render_requirement_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$messages = array(
			'wp_version'           => __( 'Directorist AI requires WordPress 7.0 or later with its AI and Abilities APIs.', 'directorist-ai' ),
			'directorist'          => __( 'Directorist AI requires Directorist 8.9.4 or later to be active.', 'directorist-ai' ),
			'network_activation'   => __( 'Directorist AI supports per-site activation. Network activation is unavailable.', 'directorist-ai' ),
			'runtime_dependencies' => __( 'Directorist AI runtime dependencies are missing. Install a complete plugin build.', 'directorist-ai' ),
			'scheduler'            => __( 'Directorist AI requires Action Scheduler 4.2 or later. Install a complete plugin build and resolve queue conflicts.', 'directorist-ai' ),
		);
		foreach ( $this->requirement_errors as $code ) {
			$message = $messages[ $code ];
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
