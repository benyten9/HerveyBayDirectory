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

/**
 * Report the real AI state to the wizard's AI Assistant step.
 *
 * The step is rendered by the Free plugin, which knows nothing about AI
 * providers or the PRO options: it ships the row with safe defaults and lets us
 * fill in whether a provider is connected and where the assistant toggle stands.
 *
 * @since 10.2.0
 *
 * @param array $data Wizard data localized as window.SEOPRESS_WIZARD_DATA.
 * @return array
 */
function seopress_pro_wizard_ai_assistant_data( $data ) {
	if ( ! isset( $data['AI'] ) || ! is_array( $data['AI'] ) ) {
		return $data;
	}

	$option_pro = seopress_pro_get_service( 'OptionPro' );
	$usage      = seopress_pro_get_service( 'Usage' );

	if ( null === $option_pro || null === $usage ) {
		return $data;
	}

	// A null value means the site has never answered, and a fresh PRO install
	// now defaults to on — so keep the checkbox checked rather than reading the
	// absence as a refusal.
	$assistant_enabled = $option_pro->getAIAssistantEnabled();
	$assistant_enabled = null === $assistant_enabled ? true : ! empty( $assistant_enabled );

	$data['AI']['pro_active']        = true;
	$data['AI']['assistant_enabled'] = $assistant_enabled;

	if ( ! function_exists( 'seopress_ai_get_providers' ) ) {
		return $data;
	}

	$providers = seopress_ai_get_providers();

	// Mirror what the rest of the plugin resolves at request time (Completions,
	// ChatCompletions, the assistant loader): the saved provider, or OpenAI when
	// none was ever saved. Reporting any other provider would name one the plugin
	// is never going to call.
	$selected = $option_pro->getAIProvider();
	$selected = isset( $providers[ $selected ] ) ? $selected : 'openai';

	$api_key = $usage->getLicenseKey( $selected );

	$offered = array();
	foreach ( $providers as $slug => $provider ) {
		$offered[] = array(
			'value'        => $slug,
			'label'        => $provider['label'],
			// A key pinned in wp-config.php always wins over the option, so the
			// step must not ask for one: picking such a provider is enough.
			'fromConstant' => seopress_ai_provider_key_is_constant( $slug ),
		);
	}

	$data['AI']['provider_configured'] = ! empty( $api_key );
	$data['AI']['connected_provider']  = ! empty( $api_key ) ? $providers[ $selected ]['label'] : '';
	$data['AI']['selected_provider']   = $selected;
	$data['AI']['providers']           = $offered;

	return $data;
}
add_filter( 'seopress_wizard_data', 'seopress_pro_wizard_ai_assistant_data' );

/**
 * Persist the AI Assistant choice made in the wizard, and the provider key when
 * one was pasted there.
 *
 * Also flips the `ai` feature toggle on when the user opts in: without it the AI
 * settings tab and the metabox generation buttons stay hidden, so the assistant
 * would be enabled with no way to configure a provider.
 *
 * @since 10.2.0
 *
 * @param bool   $enabled  Whether the user asked for the assistant to be enabled.
 * @param string $provider Provider slug submitted with the key, empty when none.
 * @param string $api_key  API key or token submitted, empty when none.
 * @return void
 */
function seopress_pro_wizard_ai_assistant_save( $enabled, $provider = '', $api_key = '' ) {
	if ( ! current_user_can( seopress_capability( 'manage_options', 'pro' ) ) ) {
		return;
	}

	$toggle = get_option( 'seopress_toggle' );
	if ( ! is_array( $toggle ) ) {
		$toggle = array();
	}

	// The assistant lives with the other feature modules. Opting in also turns on
	// the `ai` module: without it the AI settings tab and the metabox generation
	// buttons stay hidden, so the assistant would be enabled with no way to
	// configure a provider.
	$toggle['toggle-ai-assistant'] = $enabled ? '1' : '0';
	if ( $enabled && empty( $toggle['toggle-ai'] ) ) {
		$toggle['toggle-ai'] = '1';
	}

	update_option( 'seopress_toggle', $toggle );

	$options = get_option( 'seopress_pro_option_name' );
	if ( ! is_array( $options ) ) {
		$options = array();
	}

	// The provider slug becomes part of an option key, so only ever accept one
	// we published in the step's own dropdown.
	$known = function_exists( 'seopress_ai_get_providers' ) ? seopress_ai_get_providers() : array();

	if ( isset( $known[ $provider ] ) ) {
		$options['seopress_ai_provider'] = $provider;

		// Selecting a provider whose key lives in wp-config.php is enough on its
		// own, and storing an option the constant then overrides would look like
		// a silent failure — so only persist a key we will actually read back.
		if ( '' !== $api_key && ! seopress_ai_provider_key_is_constant( $provider ) ) {
			$options[ 'seopress_ai_' . $provider . '_api_key' ] = $api_key;
		}
	}

	update_option( 'seopress_pro_option_name', $options );
}
add_action( 'seopress_setup_wizard_ai_assistant_save', 'seopress_pro_wizard_ai_assistant_save', 10, 3 );
