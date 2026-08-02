<?php
/**
 * Module Base
 *
 * Abstract class providing the properties and methods needed to
 * manage and handle modules in inheriting classes.
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBLTheme\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Module Base.
 *
 * An abstract class providing the properties and methods needed to
 * manage and handle modules in inheriting classes.
 *
 * @abstract
 * @package HBLTheme
 * @subpackage HBLThemeModules
 */
abstract class Module_Base {

	/**
	 * Module class reflection.
	 *
	 * Holds the information about a class.
	 *
	 * @access private
	 * @var ?\ReflectionClass
	 */
	private ?\ReflectionClass $reflection = null;

	/**
	 * Module components.
	 *
	 * Holds the module components.
	 *
	 * @access private
	 * @var array
	 */
	private array $components = [];

	/**
	 * Module instance.
	 *
	 * Holds the module instance.
	 *
	 * @access protected
	 * @var Module_Base[]
	 */
	protected static array $instances = [];

	/**
	 * Get module name.
	 *
	 * Retrieve the module name.
	 *
	 * @access public
	 * @abstract
	 *
	 * @return string Module name.
	 */
	abstract public static function get_name(): string;

	/**
	 * Get component IDs.
	 *
	 * @abstract
	 * @access protected
	 *
	 * @return string[]
	 */
	abstract protected function get_component_ids(): array;

	/**
	 * Singleton Instance.
	 *
	 * Ensures only one instance of the module class is loaded or can be loaded.
	 *
	 * @access public
	 * @static
	 *
	 * @return Module_Base An instance of the class.
	 */
	public static function instance(): Module_Base {
		$class_name = static::class_name();

		if ( empty( static::$instances[ $class_name ] ) ) {
			static::$instances[ $class_name ] = new static();
		}

		return static::$instances[ $class_name ];
	}

	/**
	 * Is active
	 *
	 * @access public
	 * @static
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		/**
		 * Allow enabling/disabling the module on run-time
		 *
		 * @param bool $is_active The filters value.
		 */
		return apply_filters( 'hbl_theme/modules/' . static::get_name() . '/is-active', true );
	}

	/**
	 * Class name.
	 *
	 * Retrieve the name of the class.
	 *
	 * @access public
	 * @static
	 *
	 * @return string
	 */
	public static function class_name(): string {
		return get_called_class();
	}

	/**
	 * Get reflection
	 *
	 * @access public
	 *
	 * @return \ReflectionClass
	 */
	public function get_reflection(): \ReflectionClass {
		if ( null === $this->reflection ) {
			try {
				$this->reflection = new \ReflectionClass( $this );
			} catch ( \ReflectionException $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( $e->getMessage() ); // phpcs:ignore
				}
			}
		}

		return $this->reflection;
	}

	/**
	 * Add module component.
	 *
	 * Add new component to the current module.
	 *
	 * @access public
	 *
	 * @param string $id       Component ID.
	 * @param mixed  $instance An instance of the component.
	 *
	 * @return void
	 */
	public function add_component( string $id, $instance ) {
		$this->components[ $id ] = $instance;
	}

	/**
	 * Get components
	 *
	 * @access public
	 *
	 * @return array
	 */
	public function get_components(): array {
		return $this->components;
	}

	/**
	 * Get module component.
	 *
	 * Retrieve the module component.
	 *
	 * @access public
	 *
	 * @param string $id Component ID.
	 *
	 * @return mixed An instance of the component, or `null` if the component doesn't exist.
	 */
	public function get_component( string $id ) {
		if ( isset( $this->components[ $id ] ) ) {
			return $this->components[ $id ];
		}

		return null;
	}

	/**
	 * Retrieve the namespace of the class
	 *
	 * @static
	 * @access public
	 *
	 * @return string
	 */
	public static function namespace_name(): string {
		$class_name = static::class_name();
		return substr( $class_name, 0, strrpos( $class_name, '\\' ) );
	}

	/**
	 * Adds an array of components.
	 * Assumes namespace structure contains `\Components\`
	 *
	 * @access protected
	 *
	 * @param ?array $components_ids Component's class name.
	 *
	 * @return void
	 */
	protected function register_components( ?array $components_ids = null ): void {
		if ( empty( $components_ids ) ) {
			$components_ids = $this->get_component_ids();
		}
		$namespace = static::namespace_name();
		foreach ( $components_ids as $component_id ) {
			$class_name = $namespace . '\\Components\\' . $component_id;
			if ( class_exists( $class_name ) ) {
				$this->add_component( $component_id, new $class_name() );
			}
		}
	}

	/**
	 * Registers the Module's widgets.
	 * Assumes namespace structure contains `\Widgets\`
	 *
	 * @access protected
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager instance.
	 *
	 * @return void
	 */
	public function register_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
		$widget_ids = $this->get_widget_ids();
		$namespace = static::namespace_name();

		foreach ( $widget_ids as $widget_id ) {
			$class_name = $namespace . '\\Widgets\\' . $widget_id;
			if ( class_exists( $class_name ) ) {
				$widgets_manager->register( new $class_name() );
			}
		}
	}

	/**
	 * Get widget IDs
	 *
	 * @access protected
	 *
	 * @return string[]
	 */
	protected function get_widget_ids(): array {
		return [];
	}

	/**
	 * Register hooks
	 *
	 * @access protected
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		if ( did_action( 'elementor/loaded' ) ) {
			add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		}
	}

	/**
	 * Clone.
	 *
	 * Disable class cloning and throw an error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object. Therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'hbl' ), '1.0.0' );
	}

	/**
	 * Wakeup.
	 *
	 * Disable un-serializing of the class.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function __wakeup() {
		// Un-serializing instances of the class is forbidden
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'hbl' ), '1.0.0' );
	}

	/**
	 * Class constructor
	 *
	 * @access protected
	 *
	 * @param ?string[] $components_list List of components.
	 */
	protected function __construct( ?array $components_list = null ) {
		$this->register_components( $components_list );
		$this->register_hooks();
	}
}

