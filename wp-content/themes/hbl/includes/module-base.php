<?php

namespace HBLTheme\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Module_Base {

	private ?\ReflectionClass $reflection = null;

	private array $components = [];

	protected static array $instances = [];

	abstract public static function get_name(): string;

	abstract protected function get_component_ids(): array;

	public static function instance(): Module_Base {
		$class_name = static::class_name();

		if ( empty( static::$instances[ $class_name ] ) ) {
			static::$instances[ $class_name ] = new static();
		}

		return static::$instances[ $class_name ];
	}

	public static function is_active(): bool {
		return apply_filters( 'hbl_theme/modules/' . static::get_name() . '/is-active', true );
	}

	public static function class_name(): string {
		return get_called_class();
	}

	public function get_reflection(): \ReflectionClass {
		if ( null === $this->reflection ) {
			try {
				$this->reflection = new \ReflectionClass( $this );
			} catch ( \ReflectionException $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( $e->getMessage() );
				}
			}
		}

		return $this->reflection;
	}

	public function add_component( string $id, $instance ) {
		$this->components[ $id ] = $instance;
	}

	public function get_components(): array {
		return $this->components;
	}

	public function get_component( string $id ) {
		if ( isset( $this->components[ $id ] ) ) {
			return $this->components[ $id ];
		}

		return null;
	}

	public static function namespace_name(): string {
		$class_name = static::class_name();
		return substr( $class_name, 0, strrpos( $class_name, '\\' ) );
	}

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

	protected function get_widget_ids(): array {
		return [];
	}

	protected function register_hooks(): void {
		if ( did_action( 'elementor/loaded' ) ) {
			add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );
		}
	}

	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'hbl' ), '1.0.0' );
	}

	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'hbl' ), '1.0.0' );
	}

	protected function __construct( ?array $components_list = null ) {
		$this->register_components( $components_list );
		$this->register_hooks();
	}
}

