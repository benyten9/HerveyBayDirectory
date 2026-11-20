<?php
if(!defined('ABSPATH')) {
	exit;
}

use Perfmatters\Cache;

$options = Perfmatters\Config::$options['cache'] ?? array();
$detected_host = Cache::detected_host_cache();

//Settings (default Cache tab panel)
echo '<section id="cache-settings" class="section-content" data-pm-subnav="cache-settings">';
	perfmatters_settings_header(__('Cache', 'perfmatters'), 'dashicons-cloud', true);

	echo '<div class="pm-control-bar">';
		Cache::print_subnav('settings');
		Cache::print_toolbar_actions($options);
	echo '</div>';

	//only when force actually let disk run on a known host (not when a foreign drop-in still blocks install)
	if(Cache::force() && $detected_host !== '' && Cache::disk_enabled()) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(
			/* translators: %s: host name, such as Kinsta or WP Engine. */
			__('PERFMATTERS_CACHE_FORCE is on, so disk cache is running alongside %s. Both layers may store HTML.', 'perfmatters'),
			$detected_host
		)) . '</p></div>';
	}

	$install_notice = Cache::install_notice();

	if($install_notice !== '') {
		echo '<div class="notice notice-error inline"><p>' . esc_html($install_notice) . '</p></div>';
	}

	Cache::print_files_notice();
	Cache::print_conflict_notice();
	Cache::print_cloudflare_conflict_notice();

	Cache::print_status($options);

	perfmatters_settings_section('perfmatters_options', 'cache_page');
	perfmatters_settings_section('perfmatters_options', 'cache_warmer');
	perfmatters_settings_section('perfmatters_options', 'cache_cloudflare');
echo '</section>';

//Rules
echo '<section id="cache-rules" class="section-content pm-child" data-pm-parent="cache-settings">';
	perfmatters_settings_header(__('Cache', 'perfmatters'), 'dashicons-cloud', true);
	echo '<div class="pm-control-bar">';
		Cache::print_subnav('rules');
	echo '</div>';
	perfmatters_settings_section('perfmatters_options', 'cache_rules');
echo '</section>';
