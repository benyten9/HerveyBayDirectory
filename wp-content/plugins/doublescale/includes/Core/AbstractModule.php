<?php
/**
 * Convenience base class for modules.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule implements ModuleInterface {

	public function label(): string {
		return ucfirst( $this->slug() );
	}

	public function description(): string {
		return '';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function version(): string {
		return '1.0.0';
	}

	public function dependencies(): array {
		return array( 'core' );
	}

	public function is_enabled(): bool {
		if ( ! $this->is_toggleable() ) {
			return true;
		}

		$stored  = get_option( 'doublescale_enabled_modules', array() );
		$default = is_array( $stored )
			&& array_key_exists( $this->slug(), $stored )
			&& (bool) $stored[ $this->slug() ];

		return (bool) apply_filters( 'doublescale_module_enabled_' . $this->slug(), $default );
	}

	public function isActive(): bool {
		return $this->is_enabled();
	}

	public function onActivate(): void {
	}

	public function onDeactivate(): void {
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function scheduledHooks(): array {
		return array();
	}

	public function register( Container $container ): void {
	}

	public function boot( Container $container ): void {
		if ( $controllers = $this->restControllers() ) {
			add_action(
				'rest_api_init',
				static function () use ( $controllers ) {
					foreach ( $controllers as $class ) {
						( new $class() )->register_routes();
					}
				}
			);
		}
	}

	public function restControllers(): array {
		return array();
	}

	public function migrations(): array {
		$dir = $this->module_dir() . '/Migrations';
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( $dir . '/*.php' ) ?: array();
		sort( $files );
		return $files;
	}

	protected function module_dir(): string {
		$ref = new \ReflectionClass( static::class );
		return \dirname( $ref->getFileName() );
	}

	protected function manifest_has_all_glob_files( array $manifest_files, array $patterns ): bool {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return false;
		}
		$set = array();
		foreach ( $manifest_files as $f ) {
			if ( is_string( $f ) && '' !== $f ) {
				$set[ wp_normalize_path( $f ) ] = true;
			}
		}
		foreach ( $patterns as $rel ) {
			foreach ( glob( \DOUBLESCALE_PLUGIN_DIR . $rel ) ?: array() as $file ) {
				$norm = wp_normalize_path( $file );
				if ( ! isset( $set[ $norm ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	protected function loadGlobs( array $patterns ): void {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return;
		}
		foreach ( $patterns as $rel ) {
			foreach ( glob( \DOUBLESCALE_PLUGIN_DIR . $rel ) ?: array() as $file ) {
				require_once $file;
			}
		}
	}

	protected function loadManifest( string $manifest_path ): void {
		if ( ! is_file( $manifest_path ) ) {
			return;
		}

		$files = require $manifest_path;
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				continue;
			}
			if ( ! $this->is_path_under_doublescale_plugin( $file ) ) {
				continue;
			}
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	protected function loadManifestOrGlobs( array $patterns, string $cache_key ): void {
		if ( ! function_exists( 'doublescale_get_manifest_path' ) ) {
			$this->loadGlobs( $patterns );
			return;
		}

		$manifest_path = doublescale_get_manifest_path( $cache_key );
		if ( $manifest_path && is_file( $manifest_path ) ) {
			$manifest_files = require $manifest_path;
			if ( is_array( $manifest_files ) && $this->manifest_paths_are_only_this_plugin( $manifest_files )
				&& $this->manifest_has_all_glob_files( $manifest_files, $patterns ) ) {
				$this->loadManifest( $manifest_path );
				return;
			}
			// Stale manifest (e.g. paths from another plugin directory) — fall through to rebuild or glob.
		}

		if ( $manifest_path && $this->buildManifest( $patterns, $manifest_path ) ) {
			$this->loadManifest( $manifest_path );
			return;
		}

		$this->loadGlobs( $patterns );
	}

	/**
	 * Whether every manifest entry is a PHP file under this plugin's root (prevents loading another plugin's tree).
	 *
	 * @param array $files File paths from manifest.
	 */
	protected function manifest_paths_are_only_this_plugin( array $files ): bool {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return false;
		}
		if ( array() === $files ) {
			return false;
		}
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
				return false;
			}
			if ( ! $this->is_path_under_doublescale_plugin( $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $path Absolute filesystem path.
	 */
	protected function is_path_under_doublescale_plugin( string $path ): bool {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return false;
		}
		$root = rtrim( wp_normalize_path( \DOUBLESCALE_PLUGIN_DIR ), '/' ) . '/';
		$norm = wp_normalize_path( $path );
		if ( '' === $norm ) {
			return false;
		}
		return strpos( $norm, $root ) === 0;
	}

	protected function loadModuleMergeTagFiles(): void {
		$dir = $this->module_dir() . '/MergeTags';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		$ri = new \RecursiveIteratorIterator( $it );
		$re = new \RegexIterator( $ri, '/\\.php$/' );
		foreach ( $re as $file ) {
			require_once $file->getPathname();
		}
	}

	protected function buildManifest( array $patterns, string $manifest_path ): bool {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return false;
		}
		$files = array();
		foreach ( $patterns as $rel ) {
			foreach ( glob( \DOUBLESCALE_PLUGIN_DIR . $rel ) ?: array() as $file ) {
				$files[] = $file;
			}
		}
		sort( $files );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- intentional: serialises file paths into a PHP manifest written at build time.
		$export = "<?php\n// Auto-generated manifest — do not edit.\nreturn " . var_export( $files, true ) . ";\n";
		$dir    = \dirname( $manifest_path );
		if ( $dir && ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is unavailable in this bootstrap context; direct check is required.
			return false;
		}
		$ok = (bool) @\file_put_contents( $manifest_path, $export );

		return $ok && is_file( $manifest_path );
	}
}
