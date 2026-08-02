<?php
/**
 * Discovers, orders, and boots modules.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

class ModuleRegistry {

	private Container $container;

	/** @var array<string, ModuleInterface> */
	private array $modules = array();

	/** @var string[] */
	private array $booted = array();

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * @param string[] $exclude_dir_basenames Directory names under $modules_root to skip (e.g. modules already loaded from the free plugin).
	 * @param string   $module_class_fqcn_prefix PSR-4 prefix for Module classes (free: DoubleScale\Modules\, Pro add-on: DoubleScale\Pro\Modules\).
	 */
	public function discover( string $modules_root, array $exclude_dir_basenames = array(), string $module_class_fqcn_prefix = 'DoubleScale\\Modules\\' ): void {
		if ( ! is_dir( $modules_root ) ) {
			return;
		}

		$exclude_lookup = array();
		foreach ( $exclude_dir_basenames as $name ) {
			$exclude_lookup[ strtolower( (string) $name ) ] = true;
		}

		foreach ( (array) glob( $modules_root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$basename = strtolower( basename( $dir ) );
			if ( isset( $exclude_lookup[ $basename ] ) ) {
				continue;
			}
			$module_file = $dir . '/Module.php';
			if ( ! is_file( $module_file ) ) {
				continue;
			}
			require_once $module_file;

			$slug       = basename( $dir );
			$class_name = $module_class_fqcn_prefix . $slug . '\\Module';
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			/** @var ModuleInterface $module */
			$module = new $class_name();
			$this->register( $module );
		}
	}

	public function register( ModuleInterface $module ): void {
		$this->modules[ $module->slug() ] = $module;
	}

	public function get( string $slug ): ?ModuleInterface {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * @return ModuleInterface[]
	 */
	public function all_sorted_by_dependencies(): array {
		return $this->sort_by_dependencies( $this->modules );
	}

	public function boot(): void {
		$ordered = $this->sort_by_dependencies( $this->modules );

		foreach ( $ordered as $module ) {
			if ( ! $module->is_enabled() ) {
				continue;
			}
			$module->register( $this->container );
		}

		foreach ( $ordered as $module ) {
			if ( ! $module->is_enabled() ) {
				continue;
			}
			$module->boot( $this->container );
			$this->booted[] = $module->slug();

			/**
			 * @param ModuleInterface $module
			 */
			do_action( 'doublescale_module_booted_' . $module->slug(), $module );
		}

		/**
		 * @param string[] $booted
		 */
		do_action( 'doublescale_modules_booted', $this->booted );
	}

	/**
	 * @param array<string, ModuleInterface> $modules
	 * @return ModuleInterface[]
	 */
	private function sort_by_dependencies( array $modules ): array {
		$in_degree = array();
		$graph     = array();
		foreach ( $modules as $slug => $module ) {
			$in_degree[ $slug ] = 0;
			$graph[ $slug ]     = array();
		}
		foreach ( $modules as $slug => $module ) {
			foreach ( $module->dependencies() as $dep ) {
				if ( ! isset( $modules[ $dep ] ) ) {
					continue;
				}
				$graph[ $dep ][]     = $slug;
				$in_degree[ $slug ] += 1;
			}
		}

		$queue  = array();
		$sorted = array();
		foreach ( $in_degree as $slug => $deg ) {
			if ( 0 === $deg ) {
				$queue[] = $slug;
			}
		}
		while ( $queue ) {
			$slug     = array_shift( $queue );
			$sorted[] = $modules[ $slug ];
			foreach ( $graph[ $slug ] as $dependent ) {
				$in_degree[ $dependent ] -= 1;
				if ( 0 === $in_degree[ $dependent ] ) {
					$queue[] = $dependent;
				}
			}
		}

		if ( count( $sorted ) !== count( $modules ) ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				$logger = doublescale_get_logger();
				if ( is_object( $logger ) && method_exists( $logger, 'error' ) ) {
					$logger->error(
						'Module dependency graph has a cycle or missing dep; falling back to registration order.',
						array( 'in_degree' => $in_degree )
					);
				}
			}
			return array_values( $modules );
		}

		return $sorted;
	}
}
