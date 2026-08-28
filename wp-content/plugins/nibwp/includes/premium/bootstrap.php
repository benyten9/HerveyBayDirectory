<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// Idempotency guard. premium/bootstrap.php may load via TWO paths:
//   1. Monorepo install (Free's nibwp.php includes premium/bootstrap.php).
//   2. Standalone Pro plugin (nibwp-pro/nibwp-pro.php requires it).
// If both are active on the same site (e.g. customer auto-installed nibwp-pro
// onto a monorepo dev box) every function declaration in this file would
// fatal-redeclare. First loader wins; the second short-circuits here.
if (defined('NIBWP_PREMIUM_AVAILABLE')) {
    return;
}

/**
 * NIBWP Premium bootstrap.
 *
 * Ships inside the standalone Pro plugin (slug: nibwp-pro). Required from the
 * Pro plugin's entry file (nibwp-pro.php) once it has confirmed:
 *   - The Free plugin (nibwp) is active (NIBWP_VERSION defined).
 *   - The customer has an active license that grants Pro entitlement.
 *
 * The license client itself (license.php, license-page.php, lock-popup.php)
 * ships in the Free plugin so customers can enter a key even before Pro is
 * installed. The first successful activate auto-downloads + installs Pro.
 *
 * Responsibilities:
 *   1. Mark Pro available + define sandbox dir.
 *   2. Load Pro's self-updater (premium/updater.php) — queries nibwp.com.
 *   3. Register Pro-only abilities (execute-php, write/edit/delete/disable/enable-file,
 *      create-upload-link) on wp_abilities_api_init.
 *   4. Register premium integration / toolkit ability files on the same hook.
 *   5. Initialize the sandbox loader.
 */

define('NIBWP_PREMIUM_AVAILABLE', true);

// Tell the skills registry (lives in Free) to also scan the Pro skills dir.
// Pro's skills/ folder sits at <pro-plugin>/includes/skills/.
add_filter('nibwp_skills_roots', static function (array $roots): array {
    $pro_skills_root = dirname(__DIR__) . '/skills';
    if (is_dir($pro_skills_root) && !in_array($pro_skills_root, $roots, true)) {
        $roots[] = $pro_skills_root;
    }
    return $roots;
});

// Sandbox dir (used by execute-php + write-file abilities).
if (!defined('NIBWP_SANDBOX_DIR')) {
    define('NIBWP_SANDBOX_DIR', WP_CONTENT_DIR . '/nibwp-sandbox/');
}
wp_mkdir_p(NIBWP_SANDBOX_DIR);

// Pro auto-updater (queries nibwp.com for new Pro versions via license server).
// Only loaded when we're actually running as the standalone Pro plugin
// (nibwp-pro). In the monorepo dev install (premium/ shipped inside Free),
// Free's own includes/updater.php handles updates — having both fight over
// the same plugin file would break WP's update flow.
if (defined('NIBWP_PRO_PLUGIN_FILE')) {
    $nibwp_premium_updater = __DIR__ . '/updater.php';
    if (file_exists($nibwp_premium_updater)) {
        require_once $nibwp_premium_updater;
    }
}

// Sandbox plugin loader (loads user code from wp-content/nibwp-sandbox/).
$nibwp_premium_sandbox = __DIR__ . '/sandbox-loader.php';
if (file_exists($nibwp_premium_sandbox)) {
    require_once $nibwp_premium_sandbox;
}

// Register Pro-only abilities (file ops + PHP execution).
add_action('wp_abilities_api_init', static function (): void {
    if (!function_exists('nibwp_is_pro') || !nibwp_is_pro()) {
        return;
    }
    $dir = __DIR__ . '/abilities/';
    foreach ([
        'execute-php.php',
        'write-file.php',
        'edit-file.php',
        'delete-file.php',
        'disable-file.php',
        'enable-file.php',
        'create-upload-link.php',
    ] as $file) {
        $abs = $dir . $file;
        if (file_exists($abs)) {
            require_once $abs;
        }
    }
}, 15);

/**
 * List of integration keys gated behind a Pro / Bundle license OR a matching
 * standalone skill license (e.g. etchwp skill license unlocks etchwp).
 *
 * Read by `nibwp.php` when deciding which ability files to require during
 * `wp_abilities_api_init`. Also read by `integrations-page.php` to render the
 * locked-card state for non-Pro users.
 */
// nibwp_premium_integrations() is declared in includes/integrations-catalog.php
// (shipped in BOTH Free + Pro) so the Integrations admin page can render
// locked cards on Free installs. Keeping the catalog out of premium/ also
// avoids duplicate-function declarations when both trees coexist.
$nibwp_integrations_catalog = dirname(__DIR__) . '/integrations-catalog.php';
if (file_exists($nibwp_integrations_catalog)) {
    require_once $nibwp_integrations_catalog;
}

/**
 * List of built-in toolkit module keys gated behind a Pro / Bundle license.
 *
 * These map to standalone files (security-toolkit.php, notifications-toolkit.php,
 * etc.) that ship in the includes/premium/toolkits/ directory.
 *
 * @return array<string, string>  key => filename (without path)
 */
function nibwp_premium_toolkits(): array
{
    return [
        'security'       => 'security-toolkit.php',
        'notifications'  => 'notifications-toolkit.php',
        'migration'      => 'migration-toolkit.php',
        'content-fetcher' => 'content-fetcher.php',
        'content-planner' => 'content-planner.php',
        'seo-advanced'   => 'seo-advanced.php',
    ];
}

/**
 * Hook premium ability files into the Abilities API init pass.
 *
 * Runs only when the Pro build is installed AND the user has an active Pro
 * (or Bundle) license. Skills are loaded separately by the skills registry.
 */
add_action('wp_abilities_api_init', static function (): void {
    $is_pro = function_exists('nibwp_is_pro') && nibwp_is_pro();

    // Premium toolkits — Pro / Bundle only (a Skill license does NOT grant
    // toolkits, only the toolkit's parent Pro tier does).
    if ($is_pro) {
        $toolkits_dir = __DIR__ . '/toolkits/';
        foreach (nibwp_premium_toolkits() as $key => $file) {
            // Honor the switch on the Integrations page. This loop used to
            // load every toolkit regardless, so turning Security & Maintenance
            // off changed nothing: its abilities stayed registered and the card
            // flipped straight back on.
            if (
                function_exists('nibwp_is_integration_enabled')
                && !nibwp_is_integration_enabled($key)
            ) {
                continue;
            }

            $abs = $toolkits_dir . $file;
            if (file_exists($abs)) {
                require_once $abs;
            }
        }
    }

    // Premium integration abilities. Unlocked when ANY of these hold:
    //   • Pro / Bundle license active (covers every integration),
    //   • the user has the explicit `integration:<key>` entitlement,
    //   • a standalone Skill license they own declares the integration in its
    //     manifest['integration_files'] — e.g. the EtchWP Skill unlocks the
    //     `etchwp` + `automaticcss` integration files without Pro.
    //
    // Most integrations are 1 file = 1 plugin (elementor.php, bricks.php, etc.).
    // A handful (forms / CRM / LMS / membership / utilities) are bundled in
    // plugin-integrations.php — load that file once if ANY bundled key is on.
    $integrations_dir = __DIR__ . '/integrations/';
    $bundled_keys = ['forms', 'fluentcrm', 'edd', 'events', 'buddypress',
                     'learndash', 'lifterlms', 'memberpress', 'givewp',
                     'translatepress', 'wpml'];

    $bundled_loaded = false;
    foreach (nibwp_premium_integrations() as $key) {
        if (!function_exists('nibwp_is_integration_available') || !nibwp_is_integration_available($key)) {
            continue;
        }
        if (!function_exists('nibwp_is_integration_enabled') || !nibwp_is_integration_enabled($key)) {
            continue;
        }
        if (function_exists('nibwp_integration_is_unlocked') && !nibwp_integration_is_unlocked($key)) {
            continue;
        }
        if (in_array($key, $bundled_keys, strict: true)) {
            if (
                !$bundled_loaded
                && file_exists($integrations_dir . 'plugin-integrations.php')
                && nibwp_integration_claim('plugin-integrations', $integrations_dir . 'plugin-integrations.php')
            ) {
                require_once $integrations_dir . 'plugin-integrations.php';
                $bundled_loaded = true;
            }
            continue;
        }
        $abs = $integrations_dir . $key . '.php';
        // Claimed rather than merely required: a standalone Skill add-on ships
        // its own copy of these files, at a different path, so require_once
        // cannot see the duplicate. Two copies means two declarations of the
        // same functions, which is a fatal compile error and a dead site.
        if (file_exists($abs) && nibwp_integration_claim($key, $abs)) {
            require_once $abs;
        }
    }
}, priority: 20);
