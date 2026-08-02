<?php
/**
 * Pro form integrations — extends the free Forms module via {@see 'doublescale_forms'}.
 *
 * The Pro Forms Module.php is excluded from discovery so the free module stays canonical.
 *
 * @package DoubleScale\Pro\Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load Pro Forms merge-tag files (self-register on include).
 */
function doublescale_pro_load_form_merge_tag_files(): void {
	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
		return;
	}

	$dir = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Forms/MergeTags';
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

/**
 * Require each vendor Form.php so side-effect registration runs before {@see load_forms()}.
 */
function doublescale_pro_load_form_integration_files(): void {
	if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
		$free_pattern = DOUBLESCALE_PLUGIN_DIR . 'includes/Modules/Forms/*/Form.php';
		$free_files   = glob( $free_pattern ) ?: array();
		sort( $free_files, SORT_STRING );
		foreach ( $free_files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;
			}
		}
	}

	if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
		return;
	}

	$pattern = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Forms/*/Form.php';
	$files   = glob( $pattern ) ?: array();
	sort( $files, SORT_STRING );

	foreach ( $files as $file ) {
		if ( is_string( $file ) && is_file( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Register Pro-only form integrations.
 *
 * @param array<string, mixed> $forms
 * @return array<string, mixed>
 */
function doublescale_pro_register_form_integrations( $forms ) {
	$forms['contactform7'] = new \DoubleScale\Modules\Forms\Contactform7\Form();
	$forms['fluentforms']  = new \DoubleScale\Modules\Forms\Fluentforms\Form();
	$forms['quillforms']   = new \DoubleScale\Modules\Forms\Quillforms\Form();
	$forms['wpforms']      = new \DoubleScale\Modules\Forms\Wpforms\Form();

	$pro_forms = array(
		'elementor'      => new \DoubleScale\Pro\Modules\Forms\Elementor\Form(),
		'formidable'     => new \DoubleScale\Pro\Modules\Forms\Formidable\Form(),
		'forminator'     => new \DoubleScale\Pro\Modules\Forms\Forminator\Form(),
		'gravityforms'   => new \DoubleScale\Pro\Modules\Forms\Gravityforms\Form(),
		'metform'        => new \DoubleScale\Pro\Modules\Forms\Metform\Form(),
		'ninjaforms'     => new \DoubleScale\Pro\Modules\Forms\Ninjaforms\Form(),
		'wsform'         => new \DoubleScale\Pro\Modules\Forms\Wsform\Form(),
		'bitform'        => new \DoubleScale\Pro\Modules\Forms\Bitform\Form(),
		'sureforms'      => new \DoubleScale\Pro\Modules\Forms\Sureforms\Form(),
		'eform'          => new \DoubleScale\Pro\Modules\Forms\Eform\Form(),
		'jetformbuilder' => new \DoubleScale\Pro\Modules\Forms\Jetformbuilder\Form(),
		'typeform'       => new \DoubleScale\Pro\Modules\Forms\Typeform\Form(),
		'jotform'        => new \DoubleScale\Pro\Modules\Forms\Jotform\Form(),
	);

	foreach ( $pro_forms as $slug => $form ) {
		$form->is_pro = true;
		$forms[ $slug ] = $form;
	}

	return $forms;
}
