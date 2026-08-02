<?php
/**
 * Class: AddonsManager
 *
 * @since 1.5.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core;

use Exception;
use DoubleScale\Pro\Addon\Addon;

/**
 * AddonsManager class.
 *
 * @since 1.5.0
 */
final class AddonsManager {

	const NOT_ADDON_INSTANCE         = 1;
	const EMPTY_SLUG                 = 2;
	const DISALLOWED_SLUG_CHARACTERS = 3;
	const ALREADY_USED_SLUG          = 4;
	const INCOMPATIBLE_DEPENDENCIES  = 5;

	/**
	 * @var Addon[]
	 */
	private $registered = array();

	/**
	 * @deprecated Retained for backward compatibility; prefer container resolution.
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register an addon.
	 *
	 * @param Addon $addon Addon instance.
	 * @throws Exception On validation failure.
	 * @return void
	 */
	public function register( $addon ) {
		if ( ! $addon instanceof Addon ) {
			throw new Exception(
				sprintf( '%s is not an instance of %s', get_class( $addon ), Addon::class ),
				self::NOT_ADDON_INSTANCE
			);
		}

		if ( empty( $addon->slug ) ) {
			throw new Exception(
				sprintf( '%s addon slug is empty', get_class( $addon ) ),
				self::EMPTY_SLUG
			);
		}

		if ( ! preg_match( '/^[a-z0-9_-]+$/', $addon->slug ) ) {
			throw new Exception(
				sprintf( '%s addon slug has illegal characters (only a-z0-9_- allowed)', get_class( $addon ) ),
				self::DISALLOWED_SLUG_CHARACTERS
			);
		}

		if ( isset( $this->registered[ $addon->slug ] ) ) {
			throw new Exception(
				sprintf( '%s addon slug is already used by %s', $addon->slug, get_class( $this->registered[ $addon->slug ] ) ),
				self::ALREADY_USED_SLUG
			);
		}

		foreach ( $addon->dependencies ?? array() as $key => $value ) {
			if ( 'doublescale' === $key ) {
				if ( ! defined( 'DOUBLESCALE_VERSION' ) ) {
					throw new Exception(
						sprintf(
							'%s addon requires the DoubleScale plugin to be active (version %s or later).',
							$addon->slug,
							$value['version'] ?? '0.0.0'
						),
						self::INCOMPATIBLE_DEPENDENCIES
					);
				}
				if ( version_compare( DOUBLESCALE_VERSION, $value['version'], '<' ) ) {
					throw new Exception(
						sprintf( '%s addon requires at least Plugin version %s', $addon->slug, $value['version'] ),
						self::INCOMPATIBLE_DEPENDENCIES
					);
				}
			} elseif ( substr( $key, -6 ) === '_addon' ) {
				$dep_slug  = substr( $key, 0, -6 );
				$dep_addon = $this->registered[ $dep_slug ] ?? null;
				if ( ( ! $dep_addon && ( $value['required'] ?? false ) )
					|| ( $dep_addon && version_compare( $dep_addon->version, $value['version'], '<' ) ) ) {
					throw new Exception(
						sprintf( '%s addon requires at least %s addon version %s', $addon->slug, $dep_slug, $value['version'] ),
						self::INCOMPATIBLE_DEPENDENCIES
					);
				}
			}
		}

		$this->registered[ $addon->slug ] = $addon;
	}

	/**
	 * @return Addon[]
	 */
	public function get_all_registered() {
		return $this->registered;
	}

	/**
	 * @param string $slug Addon slug.
	 * @return Addon|null
	 */
	public function get_registered( $slug ) {
		return $this->registered[ $slug ] ?? null;
	}

	/**
	 * @param string $namespace Root namespace.
	 * @return Addon|null
	 */
	public function get_registered_by_namespace( $namespace ) {
		foreach ( $this->registered as $addon ) {
			if ( $addon->get_namespace() === $namespace ) {
				return $addon;
			}
		}
		return null;
	}
}
