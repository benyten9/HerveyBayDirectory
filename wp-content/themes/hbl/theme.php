<?php

namespace HBLTheme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use HBLTheme\Includes\Module_Base;

final class Theme {

	private static ?Theme $instance = null;

	private array $modules = [];

	private array $classes_aliases = [];

	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf( 'Cloning instances of the singleton "%s" class is forbidden.', get_class( $this ) ),
			'1.0.0'
		);
	}

	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf( 'Deserializing instances of the singleton "%s" class is forbidden.', get_class( $this ) ),
			'1.0.0'
		);
	}

	public function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ ) ) {
			return;
		}

		$has_class_alias = isset( $this->classes_aliases[ $class_name ] );

		if ( $has_class_alias ) {
			$class_alias_name = $this->classes_aliases[ $class_name ];
			$class_to_load = $class_alias_name;
		} else {
			$class_to_load = $class_name;
		}

		if ( ! class_exists( $class_to_load ) ) {
			$filename = strtolower(
				preg_replace(
					[ '/^' . __NAMESPACE__ . '\\\/', '/([a-z])([A-Z])/', '/_/', '/\\\/' ],
					[ '', '$1-$2', '-', DIRECTORY_SEPARATOR ],
					$class_to_load
				)
			);
			$filename = trailingslashit( HBL_THEME_DIR ) . $filename . '.php';

			if ( is_readable( $filename ) ) {
				include $filename;
			}
		}

		if ( $has_class_alias ) {
			class_alias( $class_alias_name, $class_name );
		}
	}

	public static function instance(): Theme {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function get_module( string $module_name ): ?Module_Base {
		if ( isset( $this->modules[ $module_name ] ) ) {
			return $this->modules[ $module_name ];
		}

		return null;
	}

	public function add_module( Module_Base $module ) {
		$class_name = $module->get_reflection()->getName();
		if ( $module::is_active() ) {
			$this->modules[ $class_name ] = $module::instance();
		}
	}

	public function activate() {
		do_action( 'hbl_theme/after_switch_theme' );
	}

	private function init_modules() {
		$modules_list = [];

		$modules_list = apply_filters( 'hbl_theme/modules_list', $modules_list );

		foreach ( $modules_list as $module_name ) {
			$class_name = str_replace( '-', ' ', $module_name );
			$class_name = str_replace( ' ', '', ucwords( $class_name ) );
			$class_name = __NAMESPACE__ . '\\Modules\\' . $class_name . '\\Module';

			if ( class_exists( $class_name ) && empty( $this->classes_aliases[ $module_name ] ) ) {
				if ( $class_name::is_active() ) {
					$this->modules[ $module_name ] = $class_name::instance();
				}
			}
		}
	}

	private function __construct() {
		static $autoloader_registered = false;

		if ( ! $autoloader_registered ) {
			$autoloader_registered = spl_autoload_register( [ $this, 'autoload' ] );
		}

		add_action( 'after_switch_theme', [ $this, 'activate' ] );

		$this->init_modules();
	}
}

