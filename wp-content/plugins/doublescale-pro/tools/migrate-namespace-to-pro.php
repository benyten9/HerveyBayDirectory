<?php
/**
 * One-off: migrate doublescale-pro from DoubleScale\* to DoubleScale\Pro\* while
 * keeping references to classes that live only in the free plugin unprefixed.
 *
 * Usage: php tools/migrate-namespace-to-pro.php
 */
declare( strict_types = 1 );

$pro_root  = dirname( __DIR__ );
$free_root = dirname( $pro_root ) . '/DoubleScale';

function load_classmap( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$map = require $path;
	return is_array( $map ) ? $map : array();
}

function psr4_file_candidate( string $includes_root, string $fqcn_suffix ): string {
	$rel = str_replace( '\\', '/', $fqcn_suffix ) . '.php';

	return $includes_root . '/' . $rel;
}

$pro_classmap_path = $pro_root . '/vendor/composer/autoload_classmap.php';
$pro_includes      = $pro_root . '/includes/';
$pro_defined       = array();
foreach ( load_classmap( $pro_classmap_path ) as $fqcn => $path ) {
	if ( 0 !== strpos( $fqcn, 'DoubleScale\\' ) || 0 === strpos( $fqcn, 'DoubleScale\\Tests\\' ) ) {
		continue;
	}
	if ( 0 !== strpos( (string) $path, $pro_includes ) ) {
		continue;
	}
	$pro_defined[ $fqcn ] = true;
}

$pro_defined_migrated = array();
foreach ( array_keys( $pro_defined ) as $fqcn ) {
	$migrated = 'DoubleScale\\Pro\\' . substr( $fqcn, strlen( 'DoubleScale\\' ) );
	$pro_defined_migrated[ $migrated ] = true;
}

$free_classmap_path = $free_root . '/vendor/composer/autoload_classmap.php';
$free_includes      = $free_root . '/includes/';
$free_defined       = array();
foreach ( load_classmap( $free_classmap_path ) as $fqcn => $path ) {
	if ( 0 !== strpos( $fqcn, 'DoubleScale\\' ) || 0 === strpos( $fqcn, 'DoubleScale\\Tests\\' ) ) {
		continue;
	}
	if ( 0 !== strpos( (string) $path, $free_includes ) ) {
		continue;
	}
	$free_defined[ $fqcn ] = true;
}

$exclude = array( 'vendor', 'node_modules', 'dependencies', 'build', '.git' );

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $pro_root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $current ) use ( $pro_root, $exclude ): bool {
			if ( ! $current->isDir() ) {
				return true;
			}
			$rel = substr( $current->getPathname(), strlen( $pro_root ) + 1 );
			$top = explode( DIRECTORY_SEPARATOR, $rel )[0] ?? '';

			return ! in_array( $top, $exclude, true );
		}
	)
);

$files = array();
foreach ( $iterator as $f ) {
	if ( ! $f->isFile() || strtolower( $f->getExtension() ) !== 'php' ) {
		continue;
	}
	$files[] = $f->getPathname();
}

$phase1 = 0;
foreach ( $files as $file ) {
	$content = file_get_contents( $file );
	if ( false === $content || strpos( $content, 'DoubleScale\\' ) === false ) {
		continue;
	}
	$orig    = $content;
	$content = str_replace( 'DoubleScale\\Pro\\', '__DS_PRO_GUARD__\\', $content );
	$content = str_replace( 'DoubleScale\\', 'DoubleScale\\Pro\\', $content );
	$content = str_replace( '__DS_PRO_GUARD__\\', 'DoubleScale\\Pro\\', $content );
	if ( $content !== $orig ) {
		file_put_contents( $file, $content );
		++$phase1;
	}
}

$fqcn_pattern = '/DoubleScale\\\\Pro\\\\([A-Za-z0-9_\\\\]+)/';

$phase2 = 0;
foreach ( $files as $file ) {
	$content = file_get_contents( $file );
	if ( false === $content || strpos( $content, 'DoubleScale\\Pro\\' ) === false ) {
		continue;
	}
	$orig = $content;
	$content = preg_replace_callback(
		$fqcn_pattern,
		static function ( array $m ) use ( $pro_defined_migrated, $free_defined, $free_includes ): string {
			$full = 'DoubleScale\\Pro\\' . $m[1];
			if ( isset( $pro_defined_migrated[ $full ] ) ) {
				return $full;
			}
			$free_fqcn = 'DoubleScale\\' . $m[1];
			if ( isset( $free_defined[ $free_fqcn ] ) ) {
				return $free_fqcn;
			}
			$free_psr = psr4_file_candidate( $free_includes, $m[1] );
			if ( is_file( $free_psr ) ) {
				return $free_fqcn;
			}

			return $full;
		},
		$content
	);
	if ( $content !== $orig ) {
		file_put_contents( $file, $content );
		++$phase2;
	}
}

echo "Phase1 files touched: {$phase1}\n";
echo "Phase2 files touched: {$phase2}\n";
