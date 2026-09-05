<?php
/**
 * One-off: copy full Trigger/Action implementations from free DoubleScale to Pro,
 * replace free copies with TriggerPro / ProAutomationStubAction definitions.
 *
 * Run: php tools/sync-automation-pro-from-free.php
 *
 * @package DoubleScale
 */

declare( strict_types = 1 );

$free_base = dirname( __DIR__ ) . '/includes/Modules/Automations';
$pro_base = dirname( __DIR__, 2 ) . '/doublescale-pro/includes/Modules/Automations';

if ( ! is_dir( $free_base ) || ! is_dir( dirname( $pro_base ) ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI dev tool (not shipped); WP_Filesystem unavailable outside WP runtime.
	fwrite( STDERR, "Paths not found. free_base=$free_base pro_parent=" . dirname( $pro_base ) . "\n" );
	exit( 1 );
}

$free_only_trigger_basenames = array( 'UserLogin.php', 'UserRegister.php', 'UserRoleUpdate.php' );
$free_only_action_paths = array(
	'AddLists.php',
	'AddTags.php',
	'ChangeStatus.php',
	'RemoveLists.php',
	'RemoveTags.php',
	'Delays/Delay.php',
);

/**
 * @param string $content
 * @param string $extends Needle e.g. "extends Trigger" or "extends Action"
 */
function extract_property_block( string $content, string $extends ): ?string {
	$needle = 'extends ' . ( 'Trigger' === $extends ? 'Trigger(?!\w)' : 'Action(?!\w)' );
	if ( ! preg_match( '/class\s+\w+\s+' . $needle . '\s*(?:\{|\n\s*\{)(.*)$/s', $content, $m ) ) {
		return null;
	}
	$tail = $m[1];
	if ( preg_match( '/^(.*?)(?=public\s+function\s+)/s', $tail, $m2 ) ) {
		return trim( $m2[1] );
	}
	return null;
}

function strip_trailing_registration( string $php ): string {
	$php = preg_replace( '/\s*TriggersManager::instance\(\)->register\([^;]+\);\s*$/', '', $php ) ?? $php;
	$php = preg_replace( '/\s*\w+::instance\(\);\s*$/', '', $php ) ?? $php;
	return rtrim( $php ) . "\n";
}

function build_pro_trigger( string $free_content ): string {
	$c = $free_content;
	$c = str_replace(
		'namespace DoubleScale\\Modules\\Automations\\',
		'namespace DoubleScale\\Pro\\Modules\\Automations\\',
		$c
	);
	$c = strip_trailing_registration( $c );
	return $c;
}

function build_pro_action( string $free_content ): string {
	$c = $free_content;
	$c = str_replace(
		'namespace DoubleScale\\Modules\\Automations\\',
		'namespace DoubleScale\\Pro\\Modules\\Automations\\',
		$c
	);
	$c = strip_trailing_registration( $c );
	return $c;
}

function write_if_changed( string $path, string $content ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- CLI dev tool (not shipped); WP_Filesystem unavailable outside WP runtime.
		mkdir( $dir, 0775, true );
	}
	$old = is_file( $path ) ? (string) file_get_contents( $path ) : null;
	if ( $old !== $content ) {
		file_put_contents( $path, $content );
		echo 'Wrote ' . esc_html( $path ) . "\n";
	}
}

$trigger_catalog = array();
$action_catalog  = array();

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $free_base . '/Triggers', FilesystemIterator::SKIP_DOTS ) );
/** @var SplFileInfo $file */
foreach ( $rii as $file ) {
	if ( $file->isDir() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$rel  = substr( $file->getPathname(), strlen( $free_base . '/' ) );
	$base = basename( $file->getPathname() );
	if ( in_array( $base, $free_only_trigger_basenames, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( str_contains( $src, 'extends TriggerPro' ) ) {
		continue;
	}
	if ( ! str_contains( $src, 'extends Trigger' ) || preg_match( '/class\s+\w+\s+extends\s+Trigger\w/', $src ) ) {
		continue;
	}
	$pro_rel = $rel;
	$pro_path = $pro_base . '/' . $pro_rel;
	write_if_changed( $pro_path, build_pro_trigger( $src ) );

	if ( preg_match( '/^namespace\s+([^;]+);/m', $src, $nm ) && preg_match( '/class\s+(\w+)\s+extends\s+Trigger(?!\w)/', $src, $cm ) ) {
		$free_ns = trim( $nm[1] );
		$class   = $cm[1];
		$props   = extract_property_block( $src, 'Trigger' );
		if ( null === $props || '' === $props ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI dev tool (not shipped).
			fwrite( STDERR, "Skip stub (no props): {$file->getPathname()}\n" );
			continue;
		}
		$pro_ns = str_replace( 'DoubleScale\\Modules\\', 'DoubleScale\\Pro\\Modules\\', $free_ns );
		$stub     = <<<PHP
<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace {$free_ns};

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * {$class} trigger stub.
 */
class {$class} extends TriggerPro {

{$props}
}

TriggersManager::instance()->register( new {$class}() );

PHP;
		write_if_changed( $file->getPathname(), $stub );
		$pro_fqcn = $pro_ns . '\\' . $class;
		$trigger_catalog[] = $pro_fqcn;
	}
}

// Catalog entries for triggers already stubbed in free (TriggerPro).
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $free_base . '/Triggers', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	if ( $file->isDir() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( ! str_contains( $src, 'extends TriggerPro' ) ) {
		continue;
	}
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $src, $nm ) || ! preg_match( '/class\s+(\w+)\s+extends\s+TriggerPro/', $src, $cm ) ) {
		continue;
	}
	$free_ns = trim( $nm[1] );
	$class   = $cm[1];
	$pro_ns  = str_replace( 'DoubleScale\\Modules\\', 'DoubleScale\\Pro\\Modules\\', $free_ns );
	$trigger_catalog[] = $pro_ns . '\\' . $class;
}

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $free_base . '/Actions', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	if ( $file->isDir() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$rel       = substr( $file->getPathname(), strlen( $free_base . '/' ) );
	$rel_short = substr( $rel, 8 );
	if ( in_array( $rel_short, $free_only_action_paths, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( str_contains( $src, 'extends ProAutomationStubAction' ) || str_contains( $src, 'extends ActionPro' ) ) {
		continue;
	}
	if ( ! preg_match( '/class\s+\w+\s+extends\s+Action(?!\w)/', $src ) ) {
		continue;
	}
	$pro_rel = $rel;
	$pro_path = $pro_base . '/' . $pro_rel;
	write_if_changed( $pro_path, build_pro_action( $src ) );

	if ( preg_match( '/^namespace\s+([^;]+);/m', $src, $nm ) && preg_match( '/class\s+(\w+)\s+extends\s+Action(?!\w)/', $src, $cm ) ) {
		$free_ns = trim( $nm[1] );
		$class   = $cm[1];
		$props   = extract_property_block( $src, 'Action' );
		if ( null === $props || '' === $props ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI dev tool (not shipped).
			fwrite( STDERR, "Skip action stub (no props): {$file->getPathname()}\n" );
			continue;
		}
		$pro_ns = str_replace( 'DoubleScale\\Modules\\', 'DoubleScale\\Pro\\Modules\\', $free_ns );
		$stub   = <<<PHP
<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace {$free_ns};

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * {$class} action stub.
 */
class {$class} extends ProAutomationStubAction {

{$props}
}

{$class}::instance();

PHP;
		write_if_changed( $file->getPathname(), $stub );
		$action_catalog[] = $pro_ns . '\\' . $class;
	}
}

// Catalog: actions already stubbed in free (ProAutomationStubAction / ActionPro metadata-only).
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $free_base . '/Actions', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	if ( $file->isDir() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$rel_short = substr( substr( $file->getPathname(), strlen( $free_base . '/' ) ), 8 );
	if ( in_array( $rel_short, $free_only_action_paths, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( ! preg_match( '/class\s+\w+\s+extends\s+(?:ProAutomationStubAction|ActionPro)\b/', $src ) ) {
		continue;
	}
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $src, $nm ) || ! preg_match( '/class\s+(\w+)\s+extends\s+(?:ProAutomationStubAction|ActionPro)\b/', $src, $cm ) ) {
		continue;
	}
	$free_ns = trim( $nm[1] );
	$class   = $cm[1];
	$pro_ns  = str_replace( 'DoubleScale\\Modules\\', 'DoubleScale\\Pro\\Modules\\', $free_ns );
	$action_catalog[] = $pro_ns . '\\' . $class;
}

$trigger_catalog = array_values( array_unique( $trigger_catalog ) );
$action_catalog  = array_values( array_unique( $action_catalog ) );
sort( $trigger_catalog, SORT_STRING );
sort( $action_catalog, SORT_STRING );

echo "\nCatalog trigger count: " . count( $trigger_catalog ) . "\n";
echo 'Catalog action count: ' . count( $action_catalog ) . "\n";

$catalog_path = dirname( __DIR__ ) . '/includes/Modules/Automations/Config/ProAutomationCatalog.php';
$goals_block  = <<<'PHP'
	'goals'    => array(
		\DoubleScale\Pro\Modules\Automations\Goals\UsedDynamicCoupon::class,
		\DoubleScale\Pro\Modules\Automations\Goals\Woocommerce\CartRecovered::class,
		\DoubleScale\Pro\Modules\Automations\Goals\Surecart\OrderReceived::class,
	),
PHP;
$lines_t = array_map(
	static fn( string $c ) => "\t\t\\" . $c . '::class,',
	$trigger_catalog
);
$lines_a = array_map(
	static fn( string $c ) => "\t\t\\" . $c . '::class,',
	$action_catalog
);
$catalog_out = "<?php\n/**\n * Canonical list of automation trigger/action/goal classes that ship in DoubleScale Pro.\n *\n * @package DoubleScale\\Pro\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn array(\n\t'triggers' => array(\n"
	. implode( "\n", $lines_t )
	. "\n\t),\n\t'actions'  => array(\n"
	. implode( "\n", $lines_a )
	. "\n\t),\n"
	. $goals_block
	. "\n);\n";
write_if_changed( $catalog_path, $catalog_out );
