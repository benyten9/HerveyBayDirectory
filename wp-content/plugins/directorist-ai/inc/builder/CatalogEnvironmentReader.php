<?php
/**
 * Active WordPress dependency-version reader for builder negotiation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact native environment adapter.

/** Reads active plugin/theme headers only for dependencies registrations require. */
final class CatalogEnvironmentReader {
	private IntegrationRegistry $registry;

	public function __construct( IntegrationRegistry $registry ) {
		$this->registry = $registry;
	}

	/** Return current relevant versions; file presence alone never establishes availability. */
	public function read(): CatalogEnvironment {
		global $wp_version;
		$required = array_flip( $this->registry->dependency_ids() );
		$versions = array( 'wordpress' => (string) $wp_version );
		$aliases  = (array) apply_filters( 'directorist_ai_pro_builder_dependency_aliases', array( 'directorist/core' => 'directorist' ) );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active    = array_fill_keys( (array) get_option( 'active_plugins', array() ), true );
		$conflicts = array();
		if ( is_multisite() ) {
			$active += array_fill_keys( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), true );
		}
		foreach ( get_plugins() as $file => $headers ) {
			if ( ! isset( $active[ $file ] ) || empty( $headers['Version'] ) ) {
				continue;
			}
			$directory = dirname( $file );
			$id        = '.' === $directory ? basename( $file, '.php' ) : $directory;
			$id        = strtolower( $id );
			$version   = (string) $headers['Version'];
			if ( isset( $conflicts[ $id ] ) || ! $this->valid_version( $version ) ) {
				continue;
			}
			$identities = array( $id );
			foreach ( $aliases as $alias => $plugin_id ) {
				if ( is_string( $alias ) && CatalogEnvironment::valid_id( $alias ) && $plugin_id === $id ) {
					$identities[] = $alias;
				}
			}
			foreach ( $identities as $identity ) {
				if ( ! isset( $required[ $identity ] ) ) {
					continue;
				}
				if ( isset( $versions[ $identity ] ) && $versions[ $identity ] !== $version ) {
					unset( $versions[ $identity ] );
					$conflicts[ $identity ] = true;
					continue;
				}
				$versions[ $identity ] = $version;
			}
		}
		if ( isset( $required['divi'] ) ) {
			$theme  = wp_get_theme();
			$parent = $theme->parent();
			$source = $parent && 'divi' === strtolower( $parent->get_template() ) ? $parent : $theme;
			if ( 'divi' === strtolower( $source->get_template() ) && $this->valid_version( (string) $source->get( 'Version' ) ) ) {
				$versions['divi'] = (string) $source->get( 'Version' );
			}
		}
		return new CatalogEnvironment( array_intersect_key( $versions, $required ) );
	}

	private function valid_version( string $version ): bool {
		return 1 === preg_match( '/^[0-9][0-9A-Za-z.+-]{0,63}$/D', $version );
	}
}
