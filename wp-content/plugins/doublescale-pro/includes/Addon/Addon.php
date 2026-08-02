<?php
/**
 * Addon class.
 *
 * @since 1.5.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Addon;

use Exception;
use DoubleScale\Pro\Core\AddonsManager;

/**
 * Abstract class for Plugin addon plugins.
 *
 * @since 1.5.0
 */
abstract class Addon {

	/**
	 * @var string
	 */
	public $name;

	/**
	 * @var string
	 */
	public $slug;

	/**
	 * @var string
	 */
	public $version;

	/**
	 * @var string
	 */
	public $textdomain;

	/**
	 * @var string
	 */
	public $plugin_file;

	/**
	 * @var string
	 */
	public $plugin_dir;

	/**
	 * @var string
	 */
	public $plugin_url;

	/**
	 * @var array
	 */
	public $dependencies = array();

	/**
	 * @var Settings|null
	 */
	public $settings;

	/**
	 * Addon instances keyed by class name.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Get singleton instance.
	 *
	 * @return static
	 */
	public static function instance() {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			self::$instances[ static::class ] = new static();
		}
		return self::$instances[ static::class ];
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		if ( $this->register() ) {
			$this->init();
		}
	}

	/**
	 * Initialize the addon. Override in subclasses to add custom setup.
	 *
	 * @return void
	 */
	protected function init() {
		add_action(
			'init',
			function () {
				$this->load_textdomain();
			}
		);

		$this->settings = new Settings( $this );
	}

	/**
	 * Register with AddonsManager.
	 *
	 * @return boolean
	 */
	private function register() {
		try {
			AddonsManager::instance()->register( $this );
		} catch ( \Throwable $e ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					?>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'Cannot register a Plugin addon', 'doublescale') . ': ' . esc_html( $e->getMessage() ); ?></p>
					</div>
					<?php
				}
			);
			return false;
		}
		return true;
	}

	/**
	 * Load text domain.
	 *
	 * @return void
	 */
	protected function load_textdomain() {
		$plugin_rel_path = substr( $this->plugin_dir, strlen( WP_PLUGIN_DIR ) ) . 'languages';
		load_plugin_textdomain( $this->textdomain, false, $plugin_rel_path );
	}

	/**
	 * Get the root namespace of this addon class.
	 *
	 * @return string
	 */
	public function get_namespace() {
		return explode( '\\', static::class )[0];
	}
}
