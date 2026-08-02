<?php
/**
 * SEOPress PRO Dashboard "SEOPress Suite" block.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Expose the AI credit balance in the Dashboard "SEOPress Suite" block.
 *
 * The row is built by SEOPress with an upsell CTA. When a credits token is
 * configured, we turn it into a live balance: the endpoint is passed to the
 * React app, which fetches it once the Dashboard is rendered.
 *
 * @param array $credits The AI Credits product row.
 * @return array The filtered row.
 */
function seopress_pro_dashboard_suite_credits( $credits ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $credits;
	}

	$usage_service = seopress_pro_get_service( 'Usage' );
	if ( null === $usage_service || ! method_exists( $usage_service, 'getLicenseKey' ) ) {
		return $credits;
	}

	$api_key = $usage_service->getLicenseKey( 'seopress' );
	if ( empty( $api_key ) ) {
		return $credits;
	}

	$credits['label'] = __( 'Checking your credit balance…', 'wp-seopress-pro' );

	// Nothing to sell to a subscriber: the row only shows their balance. The
	// React app brings back a Renew button of its own if the subscription turns
	// out to be inactive.
	$credits['showUpsell'] = false;
	$credits['ctaText']    = '';
	$credits['url']        = '';

	$credits['balance'] = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'action'  => 'seopress_ai_credits_balance',
		'nonce'   => wp_create_nonce( 'seopress_ai_credits_nonce' ),
	);

	return $credits;
}
add_filter( 'seopress_dashboard_suite_credits', 'seopress_pro_dashboard_suite_credits' );
