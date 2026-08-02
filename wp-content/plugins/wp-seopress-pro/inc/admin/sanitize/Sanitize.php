<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Sanitize.
 *
 * @package SEOPress PRO
 * @subpackage Sanitize
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Sanitize options fields
 *
 * @param array $input Input.
 *
 * @return array Sanitized input.
 */
function seopress_pro_sanitize_options_fields( $input ) {
	$seopress_pro_sanitize_fields = array(
		'seopress_404_redirect_custom_url',
		'seopress_404_enable_mails_from',
		'seopress_404_exclude_paths',
		'seopress_404_default_param',
		'seopress_news_name',
		'seopress_htaccess_file',
		'seopress_google_analytics_auth_secret_id',
		'seopress_google_analytics_auth_client_id',
		'seopress_bot_scan_settings_timeout',
		'seopress_bot_scan_settings_number',
		'seopress_local_business_street_address',
		'seopress_local_business_address_locality',
		'seopress_local_business_address_region',
		// 'seopress_local_business_postal_code',
		'seopress_local_business_address_country',
		'seopress_local_business_lat',
		'seopress_local_business_lon',
		'seopress_local_business_place_id',
		'seopress_local_business_url',
		'seopress_local_business_phone',
		'seopress_local_business_email',
		'seopress_local_business_price_range',
		'seopress_local_business_cuisine',
		'seopress_local_business_accepts_reservations',
		// 'seopress_local_business_opening_hours',
		'seopress_robots_file',
		'seopress_mu_robots_file',
		'seopress_llms_file',
		'seopress_mu_llms_file',
		'seopress_rss_before_html',
		'seopress_rss_after_html',
		'seopress_rewrite_search',
		// 'seopress_breadcrumbs_i18n_home',
		// seopress_breadcrumbs_i18n_here,
		'seopress_breadcrumbs_i18n_author',
		'seopress_breadcrumbs_i18n_404',
		'seopress_breadcrumbs_i18n_search',
		'seopress_breadcrumbs_i18n_no_results',
		'seopress_breadcrumbs_i18n_attachments',
		'seopress_breadcrumbs_i18n_paged',
		'seopress_white_label_admin_menu',
		// 'seopress_white_label_admin_bar_icon',
		'seopress_white_label_plugin_list_title',
		'seopress_white_label_plugin_list_title_pro',
		'seopress_white_label_plugin_list_desc',
		'seopress_white_label_plugin_list_desc_pro',
		'seopress_white_label_plugin_list_author',
		'seopress_white_label_plugin_list_website',
		'seopress_mu_white_label_admin_menu',
		'seopress_mu_white_label_admin_bar_icon',
		'seopress_mu_white_label_admin_bar_logo',
		'seopress_mu_white_label_plugin_list_title',
		'seopress_mu_white_label_plugin_list_title_pro',
		'seopress_mu_white_label_plugin_list_desc',
		'seopress_mu_white_label_plugin_list_desc_pro',
		'seopress_mu_white_label_plugin_list_author',
		'seopress_mu_white_label_plugin_list_website',
		'seopress_ps_api_key',
		'seopress_ps_url',
		'seopress_ai_openai_api_key',
		'seopress_ai_deepseek_api_key',
		'seopress_ai_gemini_api_key',
		'seopress_ai_mistral_api_key',
		'seopress_ai_claude_api_key',
		'seopress_ai_seopress_api_key',
		'seopress_ai_custom_instructions',
		'seopress_seo_alerts_recipients',
		'seopress_seo_alerts_slack_webhook_url',
		'seopress_bot_scan_settings_email',
		'seopress_bot_scan_settings_audit_batch_size',
		'seopress_gsc_site_url',
		'seopress_woocommerce_return_policy_applicable_country',
		'seopress_woocommerce_return_policy_country',
		'seopress_woocommerce_return_policy_category',
		'seopress_woocommerce_return_policy_days',
		'seopress_woocommerce_return_policy_method',
		'seopress_woocommerce_return_policy_fees',
		'seopress_woocommerce_return_policy_fees_amount',
		'seopress_woocommerce_return_policy_refund_type',
		'seopress_woocommerce_return_policy_item_condition',
	);

	foreach ( $seopress_pro_sanitize_fields as $key => $value ) {
		if ( isset( $input[ $value ] ) ) {
			if ( 'seopress_woocommerce_return_policy_applicable_country' == $value ) {
				// Stored as an array of ISO country codes from the picker.
				$input[ $value ] = is_array( $input[ $value ] ) ? array_map( 'sanitize_text_field', $input[ $value ] ) : array();
			} elseif ( 'seopress_woocommerce_return_policy_days' == $value ) {
				// Return window is a positive number of days; never negative.
				$input[ $value ] = '' === $input[ $value ] ? '' : absint( $input[ $value ] );
			} elseif ( 'seopress_robots_file' == $value ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( 'seopress_mu_robots_file' == $value && is_multisite() ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( 'seopress_llms_file' == $value ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( 'seopress_mu_llms_file' == $value && is_multisite() ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( 'seopress_rss_after_html' == $value || 'seopress_rss_before_html' == $value ) {
				$args            = array(
					'strong' => array(),
					'em'     => array(),
					'br'     => array(),
					'a'      => array(
						'href' => array(),
						'rel'  => array(),
					),
				);
				$input[ $value ] = wp_kses( $input[ $value ], $args );
			} elseif ( 'seopress_ai_openai_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_openai_api_key'] ) ? $options['seopress_ai_openai_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_ai_deepseek_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_deepseek_api_key'] ) ? $options['seopress_ai_deepseek_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_ai_gemini_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_gemini_api_key'] ) ? $options['seopress_ai_gemini_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_ai_mistral_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_mistral_api_key'] ) ? $options['seopress_ai_mistral_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_ai_claude_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_claude_api_key'] ) ? $options['seopress_ai_claude_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_ai_seopress_api_key' == $value ) {
				$options = get_option( 'seopress_pro_option_name' );
				$old     = isset( $options['seopress_ai_seopress_api_key'] ) ? $options['seopress_ai_seopress_api_key'] : null;

				if ( '__DELETE__' === $input[ $value ] ) {
					$input[ $value ] = '';
				} elseif ( 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' === $input[ $value ] || empty( $input[ $value ] ) ) {
					$input[ $value ] = $old;
				} else {
					$input[ $value ] = sanitize_text_field( $input[ $value ] );
				}
			} elseif ( 'seopress_gsc_site_url' === $value ) {
				$input[ $value ] = esc_url_raw( $input[ $value ] );
			} elseif ( 'seopress_local_business_opening_hours' == $value ) {
				continue;
			} elseif ( 'seopress_404_exclude_paths' == $value ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( 'seopress_ai_custom_instructions' == $value ) {
				$input[ $value ] = sanitize_textarea_field( $input[ $value ] );
			} elseif ( ! empty( $input[ $value ] ) ) {
				$input[ $value ] = sanitize_text_field( $input[ $value ] );
			}
		} else {
			if ( 'seopress_local_business_opening_hours' == $value ) {
				$input['seopress_local_business_opening_hours'] = ( isset( $_POST['seopress_local_business_opening_hours'] ) ) ? $_POST['seopress_local_business_opening_hours'] : null;
			}
			if ( 'seopress_local_business_postal_code' === $value ) {
				$input['seopress_local_business_postal_code'] = ( isset( $_POST['seopress_local_business_postal_code'] ) ) ? sanitize_text_field( $_POST['seopress_local_business_postal_code'] ) : null;
			}
		}
	}

	return $input;
}
