<?php //phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Wizard.
 *
 * @package Wizard
 */
defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Surface Pro-specific data on the React setup wizard. Currently used to
 * prompt the user to activate their license from the Ready step when the
 * stored license status is not "valid".
 *
 * @param array $data Wizard data localized as window.SEOPRESS_WIZARD_DATA.
 * @return array
 */
function seopress_pro_wizard_data( $data ) {
	if ( is_multisite() ) {
		return $data;
	}

	$seo_title = 'SEOPress PRO';
	if ( method_exists( seopress_get_service( 'ToggleOption' ), 'getToggleWhiteLabel' ) && '1' === seopress_get_service( 'ToggleOption' )->getToggleWhiteLabel() ) {
		$seo_title = seopress_pro_get_service( 'OptionPro' )->getWhiteLabelListTitlePro()
			? seopress_pro_get_service( 'OptionPro' )->getWhiteLabelListTitlePro()
			: 'SEOPress PRO';
	}

	$data['PRO'] = array(
		'license_status' => (string) get_option( 'seopress_pro_license_status' ),
		'license_url'    => admin_url( 'admin.php?page=seopress-license' ),
		'product_name'   => $seo_title,
	);

	return $data;
}
add_filter( 'seopress_wizard_data', 'seopress_pro_wizard_data' );
