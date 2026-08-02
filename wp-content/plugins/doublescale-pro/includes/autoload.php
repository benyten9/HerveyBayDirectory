<?php
/**
 * PSR-4 style autoloader fallback for DoubleScale classes shipped in this plugin.
 *
 * Prefer the Composer-generated autoloader (vendor/autoload.php). This file is
 * kept as a safety net so the plugin still boots on installs that have not yet
 * run `composer install`.
 *
 * Classes under `DoubleScale\Pro\` that also exist in the free plugin are loaded
 * from the free tree first (free Composer autoload). This mapping only resolves
 * Pro-only paths under `includes/`.
 *
 * Mapping: DoubleScale\Pro\Foo\Bar -> includes/Foo/Bar.php
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class ) {
		$prefix = 'DoubleScale\\Pro\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$legacy_map = array(
			'DoubleScale\\Pro\\Settings'         => array(
				'file'  => __DIR__ . '/Core/Settings/Settings.php',
				'class' => 'DoubleScale\\Core\\Settings\\Settings',
			),
			'DoubleScale\\Pro\\Utils'            => array(
				'file'  => __DIR__ . '/Core/Utils/Utils.php',
				'class' => 'DoubleScale\\Core\\Utils\\Utils',
			),
			'DoubleScale\\Pro\\Logger'           => array(
				'file'  => __DIR__ . '/Core/Logger/Logger.php',
				'class' => 'DoubleScale\\Core\\Logger\\Logger',
			),
			'DoubleScale\\Pro\\PermissionsCompat' => array(
				'file'  => __DIR__ . '/Core/UserRoles/PermissionsCompat.php',
				'class' => 'DoubleScale\\Pro\\Core\\UserRoles\\PermissionsCompat',
			),
			'DoubleScale\\Pro\\CustomMetabox'    => array(
				'file'  => defined( 'DOUBLESCALE_PLUGIN_DIR' )
					? \DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Contacts/CustomMetabox.php'
					: '',
				'class' => 'DoubleScale\\Modules\\Contacts\\CustomMetabox',
			),
		);
		if ( isset( $legacy_map[ $class ] ) ) {
			$target = $legacy_map[ $class ];
			if ( is_file( $target['file'] ) ) {
				require_once $target['file'];
			}
			if ( class_exists( $target['class'] ) && ! class_exists( $class, false ) ) {
				\class_alias( $target['class'], $class );
			}
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
			return;
		}
	}
);
