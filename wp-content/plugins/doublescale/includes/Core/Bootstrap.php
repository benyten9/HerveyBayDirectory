<?php
/**
 * Public entry for the modular stack.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function init(): PluginKernel {
		ModuleManager::register_hooks();
		return PluginKernel::instance();
	}
}
