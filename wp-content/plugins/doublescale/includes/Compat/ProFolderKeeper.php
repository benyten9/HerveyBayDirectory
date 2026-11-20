<?php
/**
 * Keep DoubleScale Pro active when an update moves it to another folder.
 *
 * WordPress remembers an active plugin by its path ("folder/file.php"). Some
 * Pro downloads unpacked into "vixgrow-DoubleScale-Pro-<sha>/" instead of
 * "doublescale-pro/". The next update installs into "doublescale-pro/" and
 * deletes the old folder, so the remembered path points at nothing: WordPress
 * stops loading Pro, and the Plugins screen then drops it from the active list.
 *
 * Pro cannot repair this itself, as it is not loaded once its path is gone, so
 * the free plugin does: it remembers where active Pro lives and, when that file
 * disappears while Pro exists in another folder, points WordPress at the new
 * folder. A deliberate deactivation clears the memory, so it is never undone.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Compat;

defined( 'ABSPATH' ) || exit;

final class ProFolderKeeper {

	/** Option holding the path of the Pro plugin last seen active. */
	const OPTION = 'doublescale_pro_active_basename';

	/** Pro's main file, whatever folder it is in. */
	const MAIN_FILE = 'doublescale-pro.php';

	/** The folder Pro should live in. */
	const FOLDER = 'doublescale-pro';

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'heal' ), 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'heal' ), 99 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'forget_on_deactivation' ), 10, 2 );
	}

	/**
	 * Repair the site's (and the network's) active plugin list.
	 */
	public static function heal(): void {
		self::heal_list( false );

		if ( is_multisite() ) {
			self::heal_list( true );
		}
	}

	/**
	 * A deliberate deactivation of Pro must not be undone.
	 *
	 * @param string $plugin       Deactivated plugin path.
	 * @param bool   $network_wide Whether it was network-wide.
	 */
	public static function forget_on_deactivation( $plugin, $network_wide = false ): void {
		if ( ! self::is_pro( (string) $plugin ) ) {
			return;
		}

		if ( $network_wide ) {
			delete_site_option( self::OPTION . '_network' );
		} else {
			delete_option( self::OPTION );
		}
	}

	/**
	 * @param bool $network Work on the network-activated list.
	 */
	private static function heal_list( bool $network ): void {
		$list       = $network ? (array) get_site_option( 'active_sitewide_plugins', array() ) : (array) get_option( 'active_plugins', array() );
		$paths      = $network ? array_map( 'strval', array_keys( $list ) ) : array_map( 'strval', array_values( $list ) );
		$active_pro = array_values( array_filter( $paths, array( __CLASS__, 'is_pro' ) ) );
		$remembered = (string) ( $network ? get_site_option( self::OPTION . '_network', '' ) : get_option( self::OPTION, '' ) );

		foreach ( $active_pro as $path ) {
			if ( self::exists( $path ) ) {
				if ( $remembered !== $path ) {
					self::remember( $network, $path );
				}
				return;
			}
		}

		// The active entry points at a file that is gone, or WordPress already
		// dropped it while the Pro we remember being active is gone from disk.
		if ( $active_pro ) {
			$missing = $active_pro[0];
		} elseif ( '' !== $remembered && ! self::exists( $remembered ) ) {
			$missing = $remembered;
		} else {
			return;
		}

		$found = self::locate();
		if ( '' === $found || $found === $missing ) {
			return;
		}

		if ( $network ) {
			$since = $list[ $missing ] ?? time();
			unset( $list[ $missing ] );
			$list[ $found ] = $since;
			update_site_option( 'active_sitewide_plugins', $list );
		} else {
			$list   = array_values( array_diff( array_map( 'strval', $list ), array( $missing ) ) );
			$list[] = $found;
			sort( $list );
			update_option( 'active_plugins', $list );
		}

		self::remember( $network, $found );
	}

	/**
	 * Where Pro is installed now: "doublescale-pro/" first, else any folder.
	 *
	 * @return string Plugin path, or '' when Pro is not installed.
	 */
	private static function locate(): string {
		$preferred = self::FOLDER . '/' . self::MAIN_FILE;
		if ( self::exists( $preferred ) ) {
			return $preferred;
		}

		foreach ( (array) glob( WP_PLUGIN_DIR . '/*/' . self::MAIN_FILE ) as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 2048 );
			if ( false !== stripos( $head, 'Plugin Name:' ) && false !== stripos( $head, 'DoubleScale Pro' ) ) {
				return basename( dirname( $file ) ) . '/' . self::MAIN_FILE;
			}
		}

		return '';
	}

	/**
	 * @param string $path Plugin path.
	 */
	private static function is_pro( $path ): bool {
		return '/' . self::MAIN_FILE === substr( (string) $path, -strlen( '/' . self::MAIN_FILE ) );
	}

	/**
	 * @param string $path Plugin path.
	 */
	private static function exists( string $path ): bool {
		return '' !== $path && false === strpos( $path, '..' ) && is_file( WP_PLUGIN_DIR . '/' . $path );
	}

	/**
	 * @param bool   $network Network list.
	 * @param string $path    Plugin path.
	 */
	private static function remember( bool $network, string $path ): void {
		if ( $network ) {
			update_site_option( self::OPTION . '_network', $path );
		} else {
			update_option( self::OPTION, $path, true );
		}
	}
}
