<?php
/**
 * Shared logic: collect short class names (e.g. RestContactController) from
 * each module’s restControllers() return array. Only that method is scanned
 * so CoreModule::register() and similar are ignored.
 *
 * @package DoubleScale\Tests
 */

/**
 * @return string[] Absolute paths: CoreModule + each includes/Modules/{slug}/Module.php
 */
function doublescale_rest_module_files( string $plugin_root ): array {
	$files   = array( $plugin_root . '/includes/Core/CoreModule.php' );
	$modules = glob( $plugin_root . '/includes/Modules/*/Module.php' );
	if ( is_array( $modules ) ) {
		$files = array_merge( $files, $modules );
	}

	$pro_root = dirname( rtrim( $plugin_root, '/\\' ) ) . '/doublescale-pro';
	if ( is_dir( $pro_root ) ) {
		$pro_modules = glob( $pro_root . '/includes/Modules/*/Module.php' );
		if ( is_array( $pro_modules ) ) {
			$files = array_merge( $files, $pro_modules );
		}
	}

	return array_values( array_filter( $files, 'is_file' ) );
}

/**
 * Extract the inner of `return array( ... );` inside `restControllers(): array { ... }`.
 */
function doublescale_extract_rest_controllers_array_inner( string $file_contents ): ?string {
	// Allow optional whitespace / return type spacing as in the codebase.
	if ( preg_match(
		'/function\s+restControllers\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*array\(([\s\S]*?)\)\s*;\s*\}/',
		$file_contents,
		$m
	) ) {
		return $m[1];
	}
	return null;
}

/**
 * @return string[] Sorted unique short class names
 */
function doublescale_collect_rest_controller_short_names( string $plugin_root ): array {
	$names = array();
	foreach ( doublescale_rest_module_files( $plugin_root ) as $path ) {
		$contents = (string) file_get_contents( $path );
		// Inherits empty restControllers() from AbstractModule — nothing to parse.
		if ( false === strpos( $contents, 'function restControllers' ) ) {
			continue;
		}
		$inner = doublescale_extract_rest_controllers_array_inner( $contents );
		if ( null === $inner ) {
			continue;
		}
		if ( ! preg_match_all( '/\b([A-Z][A-Za-z0-9_]+)::class\b/', $inner, $m ) ) {
			continue;
		}
		foreach ( $m[1] as $short ) {
			$names[ $short ] = true;
		}
	}
	$out = array_keys( $names );
	sort( $out );
	return $out;
}

/**
 * @return int Number of files that override restControllers() with an unparsable body
 */
function doublescale_count_unparsed_rest_controllers( string $plugin_root ): int {
	$bad = 0;
	foreach ( doublescale_rest_module_files( $plugin_root ) as $path ) {
		$contents = (string) file_get_contents( $path );
		if ( false === strpos( $contents, 'function restControllers' ) ) {
			continue;
		}
		if ( null === doublescale_extract_rest_controllers_array_inner( $contents ) ) {
			++$bad;
		}
	}
	return $bad;
}

/**
 * PHP namespace declaration from a module / core file.
 */
function doublescale_php_file_namespace( string $file ): string {
	$c = (string) file_get_contents( $file );
	if ( preg_match( '/^\s*namespace\s+([^;\s]+)\s*;/m', $c, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

/**
 * Import map (short class name => FQCN) from `use` statements in a PHP file.
 *
 * @return array<string, string>
 */
function doublescale_php_file_use_imports( string $file ): array {
	$c = (string) file_get_contents( $file );
	$imports = array();
	if ( ! preg_match_all( '/^\s*use\s+([^;]+);/m', $c, $matches ) ) {
		return $imports;
	}
	foreach ( $matches[1] as $use_clause ) {
		$use_clause = trim( (string) $use_clause );
		if ( '' === $use_clause || false !== strpos( $use_clause, '{' ) ) {
			continue;
		}
		$parts = explode( ' as ', $use_clause );
		$fqcn  = ltrim( trim( $parts[0] ), '\\' );
		$alias = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		$slash = strrpos( $fqcn, '\\' );
		$short = false !== $slash ? substr( $fqcn, $slash + 1 ) : $fqcn;
		$key   = '' !== $alias ? $alias : $short;
		if ( '' !== $key ) {
			$imports[ $key ] = $fqcn;
		}
	}
	return $imports;
}

/**
 * Turn a `Some\Sub\Class` token from restControllers() into an FQCN.
 */
function doublescale_rest_resolve_fqcn_token( string $file_namespace, string $token, array $imports = array() ): string {
	$token = trim( $token );
	if ( '' === $token ) {
		return $token;
	}
	if ( isset( $imports[ $token ] ) ) {
		return $imports[ $token ];
	}
	if ( '\\' === $token[0] ) {
		return substr( $token, 1 );
	}
	return $file_namespace . '\\' . $token;
}

/**
 * Map short controller class name (e.g. RestContactController) to FQCN from
 * each module’s restControllers() list (authoritative for ambiguous names).
 *
 * @return array<string, string>
 */
function doublescale_collect_rest_controller_fqcn_map( string $plugin_root ): array {
	$map = array();
	foreach ( doublescale_rest_module_files( $plugin_root ) as $path ) {
		$contents = (string) file_get_contents( $path );
		if ( false === strpos( $contents, 'function restControllers' ) ) {
			continue;
		}
		$inner = doublescale_extract_rest_controllers_array_inner( $contents );
		if ( null === $inner ) {
			continue;
		}
		$ns      = doublescale_php_file_namespace( $path );
		$imports = doublescale_php_file_use_imports( $path );
		foreach ( explode( ',', $inner ) as $raw ) {
			$t = trim( (string) preg_replace( '/\s*\/\/.*$/', '', $raw ) );
			if ( '' === $t || substr( $t, -7 ) !== '::class' ) {
				continue;
			}
			$token = trim( substr( $t, 0, -7 ) );
			if ( '' === $token ) {
				continue;
			}
			$fqcn          = doublescale_rest_resolve_fqcn_token( $ns, $token, $imports );
			$slash         = strrpos( $fqcn, '\\' );
			$short         = false !== $slash ? substr( $fqcn, $slash + 1 ) : $fqcn;
			$map[ $short ] = $fqcn;
		}
	}
	ksort( $map );
	return $map;
}
