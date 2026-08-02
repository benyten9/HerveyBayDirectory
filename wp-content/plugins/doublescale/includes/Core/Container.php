<?php
/**
 * Thin wrapper around Illuminate\Container.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

use Illuminate\Container\Container as IlluminateContainer;

class Container {

	private IlluminateContainer $illuminate;

	/** @var self|null */
	private static ?self $global = null;

	public function __construct( ?IlluminateContainer $illuminate = null ) {
		$this->illuminate = $illuminate ?? new IlluminateContainer();
	}

	public function set_as_global(): void {
		self::$global = $this;
	}

	public static function global(): ?self {
		return self::$global;
	}

	public function singleton( string $abstract, $concrete = null ): void {
		$this->illuminate->singleton( $abstract, $concrete );
	}

	public function bind( string $abstract, $concrete = null ): void {
		$this->illuminate->bind( $abstract, $concrete );
	}

	public function instance( string $abstract, $instance ): void {
		$this->illuminate->instance( $abstract, $instance );
	}

	/**
	 * @return mixed
	 */
	public function get( string $abstract ) {
		return $this->illuminate->make( $abstract );
	}

	public function has( string $abstract ): bool {
		return $this->illuminate->bound( $abstract );
	}

	public function illuminate(): IlluminateContainer {
		return $this->illuminate;
	}

	/**
	 * @internal Test-only.
	 */
	public static function reset_global_for_tests(): void {
		self::$global = null;
	}
}
