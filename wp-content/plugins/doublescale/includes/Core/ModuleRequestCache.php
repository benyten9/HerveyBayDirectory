<?php
/**
 * Request-scoped caches for module slug maps and enabled flags (no $GLOBALS).
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleRequestCache {

	/** @var array<string, class-string<\DoubleScale\Core\ModuleInterface>>|null */
	private static ?array $slug_class_map = null;

	/** @var array<string, bool> */
	private static array $enabled = array();

	public static function flush(): void {
		self::$slug_class_map = null;
		self::$enabled        = array();
	}

	/**
	 * @return array<string, class-string<\DoubleScale\Core\ModuleInterface>>|null
	 */
	public static function get_slug_class_map(): ?array {
		return self::$slug_class_map;
	}

	/**
	 * @param array<string, class-string<\DoubleScale\Core\ModuleInterface>> $map
	 */
	public static function set_slug_class_map( array $map ): void {
		self::$slug_class_map = $map;
	}

	public static function get_enabled( string $slug ): ?bool {
		return array_key_exists( $slug, self::$enabled ) ? self::$enabled[ $slug ] : null;
	}

	public static function set_enabled( string $slug, bool $value ): void {
		self::$enabled[ $slug ] = $value;
	}
}
