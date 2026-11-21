<?php
// Perfmatters Cache Drop-in
// Copied to wp-content/advanced-cache.php while page cache is enabled.
// Runs from wp-settings.php before plugins load. Keep this file standalone.

if(!defined('ABSPATH') || !defined('WPINC')) {
	exit;
}

//warmer loopback is http://127.0.0.1 (Host + this header). Without HTTPS on, is_ssl() is false and
//set_url_scheme rewrites enqueued assets to http:// in the HTML we store.
if(isset($_GET['perfmatters_warmer']) && isset($_SERVER['HTTP_X_PERFMATTERS_WARMER_HTTPS']) && (string) $_SERVER['HTTP_X_PERFMATTERS_WARMER_HTTPS'] === '1') {
	$_SERVER['HTTPS'] = 'on';
}

//Some hosts serve their own HTML. Mu-plugins are not loaded yet, so only these signals exist here.
//The same checks live in Cache::early_host_cache(). PERFMATTERS_CACHE_FORCE skips them for testing.
$perfmatters_cache_force = defined('PERFMATTERS_CACHE_FORCE') && PERFMATTERS_CACHE_FORCE;

if(!$perfmatters_cache_force) {
	if(defined('IS_PRESSABLE') && IS_PRESSABLE) {
		return;
	}

	$perfmatters_cache_spinup = getenv('SPINUPWP_CACHE_PATH');

	if((!is_string($perfmatters_cache_spinup) || $perfmatters_cache_spinup === '') && isset($_SERVER['SPINUPWP_CACHE_PATH']) && is_string($_SERVER['SPINUPWP_CACHE_PATH'])) {
		$perfmatters_cache_spinup = $_SERVER['SPINUPWP_CACHE_PATH'];
	}

	if(is_string($perfmatters_cache_spinup) && $perfmatters_cache_spinup !== '') {
		return;
	}

	unset($perfmatters_cache_spinup);

	$perfmatters_cache_machine = function_exists('gethostname') ? gethostname() : false;
	$perfmatters_cache_user = (isset($_SERVER['DH_USER']) && is_string($_SERVER['DH_USER'])) ? strtolower(preg_replace('/[^a-z0-9_\-]/', '', $_SERVER['DH_USER'])) : '';

	if(is_string($perfmatters_cache_machine) && strncmp($perfmatters_cache_machine, 'dp-', 3) === 0 && strncmp($perfmatters_cache_user, 'wp_', 3) === 0) {
		return;
	}

	unset($perfmatters_cache_machine, $perfmatters_cache_user);

	if(!empty($_SERVER['KINSTA_CACHE_ZONE'])) {
		return;
	}

	//Pantheon edge cache. Same checks live in Cache::is_pantheon().
	$perfmatters_cache_pantheon = (defined('PANTHEON_ENVIRONMENT') && PANTHEON_ENVIRONMENT) ? PANTHEON_ENVIRONMENT : getenv('PANTHEON_ENVIRONMENT');

	if((!is_string($perfmatters_cache_pantheon) || $perfmatters_cache_pantheon === '') && isset($_SERVER['PANTHEON_ENVIRONMENT']) && is_string($_SERVER['PANTHEON_ENVIRONMENT'])) {
		$perfmatters_cache_pantheon = $_SERVER['PANTHEON_ENVIRONMENT'];
	}

	if((!is_string($perfmatters_cache_pantheon) || $perfmatters_cache_pantheon === '') && isset($_ENV['PANTHEON_ENVIRONMENT']) && is_string($_ENV['PANTHEON_ENVIRONMENT'])) {
		$perfmatters_cache_pantheon = $_ENV['PANTHEON_ENVIRONMENT'];
	}

	if(is_string($perfmatters_cache_pantheon) && $perfmatters_cache_pantheon !== '') {
		return;
	}

	unset($perfmatters_cache_pantheon);
}

unset($perfmatters_cache_force);

//bootstrap lives next to host folders (same parent as minify/css). HTML is under {host}/page/.
//Dropin replaces the default path with Config::bootstrap_dir() (honors perfmatters_cache_path).
$perfmatters_cache_bootstrap = WP_CONTENT_DIR . '/cache/perfmatters'; // PERFMATTERS_CACHE_BOOTSTRAP
$config_file = $perfmatters_cache_bootstrap . '/page-config.php';
$runtime_file = $perfmatters_cache_bootstrap . '/runtime.php';

if(!is_file($config_file) || !is_file($runtime_file)) {
	return;
}

$config = include $config_file;

if(!is_array($config) || empty($config['page'])) {
	return;
}

require_once $runtime_file;

if(!function_exists('perfmatters_cache_should_serve') || !function_exists('perfmatters_cache_response_file')) {
	return;
}

$file = perfmatters_cache_should_serve($config);

if($file === '') {
	return;
}

//plain file when gzip is filtered off, or this browser did not ask for it
$gzip_enabled = !isset($config['gzip']) || !empty($config['gzip']);
list($serve, $gzip) = perfmatters_cache_response_file($file, $config['cache_dir'], $gzip_enabled);

//already compressed, so php and apache must not gzip it again
if($gzip) {
	ini_set('zlib.output_compression', '0');

	if(function_exists('apache_setenv')) {
		apache_setenv('no-gzip', '1');
	}
}

//browser Cache-Control from page-config (default no-cache); unchanged file → 304
if(!headers_sent()) {
	header('X-Perfmatters-Cache: HIT');
	if(isset($config['browser_ttl']) && is_numeric($config['browser_ttl']) && (int) $config['browser_ttl'] >= 0) {
		header('Cache-Control: max-age=' . (int) $config['browser_ttl'] . ', must-revalidate');
	}
	else {
		header('Cache-Control: no-cache, must-revalidate');
	}

	//Cloudflare bypass_by_default — only when page-config was written with a purgeable token (cf_edge_ttl > 0)
	if(!empty($config['cloudflare']) && !empty($config['cf_edge_ttl']) && (int) $config['cf_edge_ttl'] > 0) {
		header('CDN-Cache-Control: max-age=' . (int) $config['cf_edge_ttl']);
	}

	//Accept-Encoding for gzip siblings; User-Agent when mobile files are split
	if(!empty($config['page_mobile'])) {
		header('Vary: Accept-Encoding, User-Agent, Sec-CH-UA-Mobile');
	}
	else {
		header('Vary: Accept-Encoding');
	}

	$modified = filemtime($file);

	if($modified) {
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');

		$since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;

		if($since && $since >= $modified) {
			$allowed = array('HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3', 'HTTP/3.0');
			$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';

			if(!in_array($protocol, $allowed, true)) {
				$protocol = 'HTTP/1.1';
			}

			header($protocol . ' 304 Not Modified', true, 304);
			exit;
		}
	}

	header('Content-Type: text/html; charset=UTF-8');

	if($gzip) {
		header('Content-Encoding: gzip');
	}
}

readfile($serve);
exit;
