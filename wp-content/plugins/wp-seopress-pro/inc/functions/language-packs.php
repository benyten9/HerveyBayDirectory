<?php
/**
 * Registers the SEOPress PRO TranslationsPress language pack with the free
 * plugin's on-demand language pack installer.
 *
 * PRO is not hosted on WordPress.org; its translations are served by
 * TranslationsPress (see SEOPRESS_Language_Packs in inc/admin/updater/t15s-registry.php).
 * That registry only injects entries into site_transient_update_plugins, which
 * are installed during WordPress' translation-update cron — so on a fresh
 * install the PRO UI stays in English until the cron eventually runs. Here we
 * hand the pack to the free plugin's installer, which fetches it for the current
 * locale and installs it on demand (activation / first SEOPress screen).
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the PRO pack to the list installed on demand for the current locale.
 *
 * @param array  $packs  Packs to install.
 * @param string $locale Current admin locale.
 * @return array
 */
function seopress_pro_register_language_pack( $packs, $locale ) {
	if ( ! defined( 'SEOPRESS_PRO_VERSION' ) ) {
		return $packs;
	}

	$packs[] = array(
		'slug'    => 'wp-seopress-pro',
		'version' => SEOPRESS_PRO_VERSION,
		'source'  => 'url',
		'api_url' => 'https://packages.translationspress.com/seopress/wp-seopress-pro/packages.json',
	);

	return $packs;
}
add_filter( 'seopress_language_packs', 'seopress_pro_register_language_pack', 10, 2 );

/**
 * Forces an immediate install on the PRO post-activation request, so the pack
 * is installed even when WordPress lands on a non-SEOPress admin screen
 * (e.g. plugins.php). The transient is set in seopress_pro_activation() and
 * self-expires shortly after.
 *
 * @param bool $force Whether to force the install.
 * @return bool
 */
function seopress_pro_force_install_language_packs( $force ) {
	if ( get_transient( 'seopress_pro_install_langpack' ) ) {
		return true;
	}

	return $force;
}
add_filter( 'seopress_force_install_language_packs', 'seopress_pro_force_install_language_packs' );
