<?php

declare(strict_types=1);

/**
 * NIBWP Pro self-updater.
 *
 * Ships inside the Pro plugin (nibwp-pro). Polls nibwp.com on the standard
 * WordPress update cycle and injects an entry into the plugins-update
 * transient so the host site sees "Update available" for nibwp-pro and can
 * upgrade through the normal Plugins screen / WP-CLI.
 *
 * Requires the Free plugin (nibwp) to be active because we reuse Free's
 * license client helpers (`nibwp_license_server()`, `nibwp_licenses_get()`).
 *
 * Server endpoint: POST /wp-json/fluent-cart/v2/pro/info
 *   request:  { license_key, site_url, plugin, plugin_version }
 *   response: { name, slug, version, package_url, requires, requires_php,
 *               tested, homepage, sections, last_updated, icons, banners }
 * Implemented by nibwp-license-server on nibwp.com.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Standalone Pro plugin context only. In the monorepo dev install (premium/
// shipped inside Free) the Free plugin's own includes/updater.php owns the
// nibwp plugin update channel; we must NOT register a competing updater here
// or WP's update system will see two updaters for the same plugin file.
if (!defined('NIBWP_PRO_PLUGIN_FILE')) {
    return;
}

if (!defined('NIBWP_PRO_UPDATER_TTL')) {
    define('NIBWP_PRO_UPDATER_TTL', 6 * HOUR_IN_SECONDS);
}

add_filter('pre_set_site_transient_update_plugins', 'nibwp_pro_inject_update_entry');
add_filter('plugins_api', 'nibwp_pro_plugins_api', 10, 3);
add_action('upgrader_process_complete', 'nibwp_pro_clear_update_cache', 10, 0);
add_action('update_option_nibwp_licenses', 'nibwp_pro_clear_update_cache');

/**
 * Plugin file path relative to wp-content/plugins/.
 */
function nibwp_pro_plugin_file(): string
{
    return defined('NIBWP_PRO_PLUGIN_FILE')
        ? plugin_basename(NIBWP_PRO_PLUGIN_FILE)
        : 'nibwp-pro/nibwp-pro.php';
}

/**
 * Pick the first active license stored by the Free client. Any active license
 * is enough — Pro is a single plugin gated by entitlement codes at runtime.
 *
 * @return array<string, mixed>|null
 */
function nibwp_pro_pick_license(): ?array
{
    if (!function_exists('nibwp_licenses_get')) {
        return null;
    }
    foreach (nibwp_licenses_get() as $license) {
        if (is_array($license) && ($license['status'] ?? '') === 'active' && !empty($license['key'])) {
            return $license;
        }
    }
    return null;
}

/**
 * Fetch (and cache) the latest Pro version info from nibwp.com.
 *
 * @return array<string, mixed>|null
 */
function nibwp_pro_fetch_info(): ?array
{
    $cached = get_site_transient('nibwp_pro_update_info');
    if (is_array($cached)) {
        return $cached;
    }

    if (!function_exists('nibwp_license_server')) {
        return null;
    }
    $license = nibwp_pro_pick_license();
    if ($license === null) {
        return null;
    }

    $url = trailingslashit(nibwp_license_server()) . 'wp-json/fluent-cart/v2/pro/info';
    $body = [
        'license_key'    => (string) $license['key'],
        'site_url'       => home_url(),
        'plugin'         => 'nibwp-pro',
        'plugin_version' => defined('NIBWP_PRO_VERSION') ? NIBWP_PRO_VERSION : '0.0.0',
    ];

    $response = wp_remote_post($url, [
        'timeout'     => 20,
        'redirection' => 3,
        'headers'     => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return null;
    }
    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($decoded) || empty($decoded['version']) || empty($decoded['package_url'])) {
        return null;
    }

    set_site_transient('nibwp_pro_update_info', $decoded, NIBWP_PRO_UPDATER_TTL);
    return $decoded;
}

/**
 * Inject the nibwp-pro entry into the plugins-update transient so WP's
 * standard update UI lights up when a newer version is available.
 *
 * @param mixed $transient
 * @return mixed
 */
function nibwp_pro_inject_update_entry($transient)
{
    if (!is_object($transient)) {
        return $transient;
    }
    $info = nibwp_pro_fetch_info();
    if ($info === null) {
        return $transient;
    }

    $current     = defined('NIBWP_PRO_VERSION') ? NIBWP_PRO_VERSION : '0.0.0';
    $plugin_file = nibwp_pro_plugin_file();

    $entry = (object) [
        'id'            => $plugin_file,
        'slug'          => 'nibwp-pro',
        'plugin'        => $plugin_file,
        'new_version'   => (string) $info['version'],
        'url'           => (string) ($info['homepage']     ?? 'https://nibwp.com'),
        'package'       => (string) $info['package_url'],
        'tested'        => (string) ($info['tested']       ?? ''),
        'requires'      => (string) ($info['requires']     ?? '6.5'),
        'requires_php'  => (string) ($info['requires_php'] ?? '8.0'),
        'icons'         => (array)  ($info['icons']        ?? []),
        'banners'       => (array)  ($info['banners']      ?? []),
        'compatibility' => new \stdClass(),
    ];

    if (!isset($transient->response)  || !is_array($transient->response))  { $transient->response  = []; }
    if (!isset($transient->no_update) || !is_array($transient->no_update)) { $transient->no_update = []; }

    if (version_compare($current, (string) $info['version'], '<')) {
        $transient->response[$plugin_file] = $entry;
        unset($transient->no_update[$plugin_file]);
    } else {
        $transient->no_update[$plugin_file] = $entry;
        unset($transient->response[$plugin_file]);
    }

    return $transient;
}

/**
 * Provide the "View details" / install info modal data via plugins_api.
 *
 * @param mixed  $result
 * @param string $action
 * @param object $args
 * @return mixed
 */
function nibwp_pro_plugins_api($result, $action, $args)
{
    if ($action !== 'plugin_information') {
        return $result;
    }
    if (!isset($args->slug) || $args->slug !== 'nibwp-pro') {
        return $result;
    }
    $info = nibwp_pro_fetch_info();
    if ($info === null) {
        return $result;
    }

    return (object) [
        'name'          => (string) ($info['name']         ?? 'NIBWP Pro'),
        'slug'          => 'nibwp-pro',
        'version'       => (string) $info['version'],
        'author'        => (string) ($info['author']       ?? '<a href="https://nibwp.com">NIBWP</a>'),
        'homepage'      => (string) ($info['homepage']     ?? 'https://nibwp.com'),
        'download_link' => (string) $info['package_url'],
        'requires'      => (string) ($info['requires']     ?? '6.5'),
        'requires_php'  => (string) ($info['requires_php'] ?? '8.0'),
        'tested'        => (string) ($info['tested']       ?? ''),
        'last_updated'  => (string) ($info['last_updated'] ?? ''),
        'sections'      => (array)  ($info['sections']     ?? [
            'description' => 'Premium add-on for NIBWP — premium integrations, toolkits, skill packs, file ops, and PHP execution.',
        ]),
        'banners'       => (array)  ($info['banners']      ?? []),
        'icons'         => (array)  ($info['icons']        ?? []),
    ];
}

function nibwp_pro_clear_update_cache(): void
{
    delete_site_transient('nibwp_pro_update_info');
}
