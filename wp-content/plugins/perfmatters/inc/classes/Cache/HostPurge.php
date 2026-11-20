<?php
namespace Perfmatters\Cache;

if(!defined('ABSPATH')) {
	exit;
}

class HostPurge
{
	//drop the host copy after a full clear of our files
	public static function clear()
	{
		if(\Perfmatters\Cache::host_cache() !== '') {
			return;
		}

		self::purge_varnish(home_url('/'));
		self::purge_siteground();
		self::purge_litespeed_all();
		self::purge_godaddy_stamp();
		self::purge_godaddy(home_url('/'));
		self::purge_pressidium_all();
	}

	//drop the host copy for the same urls we just deleted from disk
	public static function clear_urls($urls)
	{
		if(\Perfmatters\Cache::host_cache() !== '' || !is_array($urls) || empty($urls)) {
			return;
		}

		$paths = array();

		self::purge_godaddy_stamp();

		foreach(array_unique($urls) as $url) {
			if(!is_string($url) || $url === '') {
				continue;
			}

			self::purge_varnish($url);
			self::purge_litespeed_url($url);
			self::purge_godaddy($url);

			$path = wp_parse_url($url, PHP_URL_PATH);

			if(is_string($path) && $path !== '') {
				$paths[] = $path;
			}
		}

		self::purge_pressidium_paths($paths);
	}

	//Cloudways, WPX, O2Switch, one.com, and ProISP all speak a Varnish PURGE (regex so a URL wipe covers variants)
	private static function purge_varnish($url)
	{
		$ips = self::varnish_ips();

		if($ips === null) {
			return;
		}

		$parts = wp_parse_url($url);

		if(!is_array($parts) || empty($parts['host'])) {
			return;
		}

		$path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
		$host_header = $parts['host'];
		$headers = array(
			'host' => $host_header,
			'X-Purge-Method' => 'regex'
		);

		//O2Switch expects the key in the request, and the regex in a header instead of the path
		if(defined('O2SWITCH_VARNISH_PURGE_KEY') && O2SWITCH_VARNISH_PURGE_KEY) {
			$headers['X-VC-Purge-Key'] = O2SWITCH_VARNISH_PURGE_KEY;
			$headers['X-Purge-Regex'] = '.*';
			unset($headers['X-Purge-Method']);
		}

		$args = array(
			'method' => 'PURGE',
			'blocking' => false,
			'redirection' => 0,
			'timeout' => 0.01,
			'sslverify' => false,
			'headers' => $headers
		);

		foreach($ips as $ip) {
			$target_host = ($ip !== '') ? $ip : $host_header;
			$purge_url = 'http://' . $target_host . $path;

			if(empty($headers['X-Purge-Regex'])) {
				$purge_url .= '.*';
			}

			wp_remote_request($purge_url, $args);
		}
	}

	//null when this request is not on a Varnish host we purge. empty string ip means use the site host
	private static function varnish_ips()
	{
		if(self::is_cloudways()) {
			if(!self::cloudways_varnish_running()) {
				return null;
			}

			return array('127.0.0.1:8080');
		}

		if(!empty($_SERVER['HTTP_WPXCLOUD'])) {
			return array('127.0.0.1:6081');
		}

		if(defined('O2SWITCH_VARNISH_PURGE_KEY') && O2SWITCH_VARNISH_PURGE_KEY) {
			return array('');
		}

		if(self::groupone_varnish_active()) {
			return array('');
		}

		return null;
	}

	private static function is_cloudways()
	{
		if(isset($_SERVER['cw_allowed_ip'])) {
			return true;
		}

		if(!isset($_SERVER['DOCUMENT_ROOT']) || !is_string($_SERVER['DOCUMENT_ROOT'])) {
			return false;
		}

		return strpos($_SERVER['DOCUMENT_ROOT'], '.cloudwaysapps.com/') !== false;
	}

	private static function cloudways_varnish_running()
	{
		if(!isset($_SERVER['HTTP_X_VARNISH']) || !isset($_SERVER['HTTP_X_APPLICATION'])) {
			return false;
		}

		$application = is_string($_SERVER['HTTP_X_APPLICATION']) ? strtolower(trim($_SERVER['HTTP_X_APPLICATION'])) : '';

		return $application !== 'varnishpass';
	}

	//one.com and ProISP share the same Varnish option
	private static function groupone_varnish_active()
	{
		if(!isset($_SERVER['GROUPONE_BRAND_NAME']) || !is_string($_SERVER['GROUPONE_BRAND_NAME'])) {
			return false;
		}

		$brand = strtolower(sanitize_text_field(wp_unslash($_SERVER['GROUPONE_BRAND_NAME'])));

		if($brand !== 'one.com' && $brand !== 'proisp.no') {
			return false;
		}

		if(!defined('vcaching') || !vcaching) {
			return false;
		}

		return (bool) rest_sanitize_boolean(get_option('varnish_caching_enable'));
	}

	private static function purge_siteground()
	{
		if(function_exists('sg_cachepress_purge_everything')) {
			sg_cachepress_purge_everything();
			return;
		}

		if(class_exists('SiteGround_Optimizer\Supercacher\Supercacher', false)) {
			\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
		}
	}

	private static function purge_litespeed_all()
	{
		if(!self::is_litespeed()) {
			return;
		}

		self::send_litespeed_header('X-LiteSpeed-Purge: *', true);
	}

	private static function purge_litespeed_url($url)
	{
		if(!self::is_litespeed()) {
			return;
		}

		$path = wp_parse_url(trailingslashit($url), PHP_URL_PATH);

		if(!is_string($path) || $path === '') {
			return;
		}

		self::send_litespeed_header('X-LiteSpeed-Purge: ' . $path);
	}

	private static function is_litespeed()
	{
		return isset($_SERVER['X-LSCACHE']);
	}

	private static function send_litespeed_header($header, $replace = false)
	{
		if(headers_sent() || in_array($header, headers_list(), true)) {
			return;
		}

		header($header, $replace);
	}

	//once per clear/clear_urls — GoDaddy watches this option after an object-cache flush
	private static function purge_godaddy_stamp()
	{
		if(!class_exists('\WPaas\Plugin', false) || !method_exists('\WPaas\Plugin', 'vip')) {
			return;
		}

		wp_cache_flush();
		update_option('gd_system_last_cache_flush', time());
	}

	private static function purge_godaddy($url)
	{
		if(!class_exists('\WPaas\Plugin', false) || !method_exists('\WPaas\Plugin', 'vip')) {
			return;
		}

		$vip = \WPaas\Plugin::vip();

		if(!is_string($vip) || $vip === '') {
			return;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);

		if(!is_string($host) || $host === '') {
			return;
		}

		$purge_url = untrailingslashit(set_url_scheme(str_replace($host, $vip, $url), 'http'));

		wp_remote_request(esc_url_raw($purge_url), array(
			'method' => 'BAN',
			'blocking' => false,
			'timeout' => 0.01,
			'headers' => array(
				'Host' => $host
			)
		));
	}

	private static function purge_pressidium_all()
	{
		if(!class_exists('NinukisCaching', false)) {
			return;
		}

		$plugin = \NinukisCaching::get_instance();

		if(is_object($plugin) && method_exists($plugin, 'purgeAllCaches')) {
			$plugin->purgeAllCaches();
		}
	}

	private static function purge_pressidium_paths($paths)
	{
		if(empty($paths) || !class_exists('NinukisCaching', false)) {
			return;
		}

		$plugin = \NinukisCaching::get_instance();

		if(is_object($plugin) && method_exists($plugin, 'purge_cache')) {
			$plugin->purge_cache(array_values(array_unique($paths)));
		}
	}
}
