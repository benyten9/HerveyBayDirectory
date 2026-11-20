<?php

if (!defined('ABSPATH')) die('No direct access allowed');

if (!empty($prefix)) echo wp_kses($prefix, array()).' ';
echo wp_kses($title, array()).': ';

echo wp_kses($text, array());

if (isset($button_meta) && 'inline' == $button_meta) echo ' '.wp_kses($button_text, array()).' '.wp_kses($text2, array()).' ';

if (!empty($discount_code)) echo esc_html($discount_code).' ';

if ((!empty($button_report_link) || !empty($button_link)) && (!empty($button_meta) || !empty($button_text))) {

	$link = apply_filters('updraftplus_com_link', !empty($button_report_link) ? $button_report_link : $button_link);

	global $updraftplus_admin;
	$updraftplus_admin->include_template(
		'wp-admin/notices/button-label.php',
		false,
		array(
			'button_meta' => isset($button_meta) ? $button_meta : '',
			'button_text' => isset($button_text) ? $button_text : ''
		)
	);

	echo ' - '.esc_url_raw($link);
	echo "\r\n";
	
}
