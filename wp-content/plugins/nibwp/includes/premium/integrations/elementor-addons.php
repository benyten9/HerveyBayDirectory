<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP — Elementor add-on packs.
 *
 * The sibling elementor.php integration speaks Elementor core. This one speaks
 * whatever else is bolted onto it: Ultimate Addons, Essential Addons,
 * ElementsKit, Premium Addons, Royal Addons, HappyAddons, Unlimited Elements,
 * Jeg Kit, Qi Addons, The Plus Addons, HT Mega, Addon Elements — and the fifty
 * others, and next year's. Between them that is tens of thousands of widgets,
 * changing every release, none of them documented in any machine-readable form.
 *
 * Nothing about them is hardcoded here. Two runtime sources answer everything:
 *
 * 1. THE WIDGET REGISTRY — what exists.
 *    \Elementor\Plugin::$instance->widgets_manager->get_widget_types() returns
 *    one constructed Widget_Base per widget type, keyed by name. Each object
 *    reports its own get_name(), get_title(), get_icon(), get_categories() and
 *    get_keywords(), and get_controls() returns its entire control stack —
 *    every control with its `name`, `type`, `tab`, `section`, `default` and
 *    responsive flags, exactly as Elementor's own editor panel reads it. That
 *    is the schema, live, at this version, for a pack nobody has ever seen.
 *
 * 2. THE CLASS FILE — who provided it.
 *    ReflectionClass::getFileName() gives the file a widget class was declared
 *    in. The first path segment under WP_PLUGIN_DIR is the owning plugin
 *    directory, and get_plugins() resolves that directory to the plugin's own
 *    header: its real name and version. Elementor core and Elementor Pro
 *    resolve to their own directories and are labelled core.
 *
 * That identifies every pack on the site, free or premium, listed or not, with
 * nothing guessed. A pack's constants are never consulted — twenty packs would
 * be twenty guesses that rot on each release, and the path is simply true.
 *
 * The only hardcoded table in this file is nibwp_elementor_addons_display_names(),
 * which is cosmetic: a nicer label for a handful of directories whose names do
 * not resemble the product ("header-footer-elementor" is sold as Ultimate
 * Addons for Elementor). It never decides whether a pack is present.
 *
 * COST. Reflecting several hundred widget classes is not free — roughly one
 * file-path lookup per distinct class, and get_widget_types() itself forces
 * Elementor to load every registered widget class if the request had not
 * already. So the class-to-plugin map, the inventory built on top of it and the
 * installed-plugin list are each resolved once and memoised for the request.
 * Every ability here shares those three caches.
 *
 * Everything degrades: no Elementor, no packs, a widget object that throws from
 * get_title(), a class declared in eval()'d code with no file at all. Each one
 * narrows what is reported. None of them fatals.
 */

// ---------------------------------------------------------------------------
// Cosmetic display names — never used for detection.
// ---------------------------------------------------------------------------

/**
 * Nicer labels for plugin directories whose name does not resemble the product.
 *
 * COSMETIC ONLY. Detection never reads this: a pack is present because one of
 * its widget classes was declared inside its directory, full stop. This map is
 * consulted after get_plugins() and only when the plugin header is missing or
 * unhelpful — and when a directory is not listed here, the header wins anyway.
 *
 * Populated for the twelve packs the owner prioritised, because those are the
 * ones whose directory names mislead: "header-footer-elementor" is sold as
 * Ultimate Addons for Elementor, "royal-elementor-addons" as Royal Addons for
 * Elementor, "jeg-elementor-kit" as Jeg Kit, "ht-mega-for-elementor" as HT Mega
 * Addons. Everything else in the world resolves from its own header.
 *
 * @return array<string, string> plugin directory => display name
 */
function nibwp_elementor_addons_display_names(): array
{
    return [
        'header-footer-elementor' => 'Ultimate Addons for Elementor',
        'essential-addons-for-elementor-lite' => 'Essential Addons for Elementor',
        'elementskit-lite' => 'ElementsKit',
        'premium-addons-for-elementor' => 'Premium Addons for Elementor',
        'royal-elementor-addons' => 'Royal Addons for Elementor',
        'happy-elementor-addons' => 'HappyAddons for Elementor',
        'unlimited-elements-for-elementor' => 'Unlimited Elements for Elementor',
        'jeg-elementor-kit' => 'Jeg Kit for Elementor',
        'qi-addons-for-elementor' => 'Qi Addons for Elementor',
        'the-plus-addons-for-elementor-page-builder' => 'The Plus Addons for Elementor',
        'ht-mega-for-elementor' => 'HT Mega Addons for Elementor',
        'addon-elements-for-elementor-page-builder' => 'Addon Elements for Elementor',
    ];
}

/**
 * Widget-name prefixes, only for naming a pack that is already gone.
 *
 * A widget type sitting in a page's saved data whose plugin has been
 * deactivated has no class and therefore no file, so provenance cannot be
 * resolved the honest way. The name itself is the only evidence left, so the
 * audit falls back to this — and says so, as a guess, never as a fact.
 *
 * It is also why the fallback is deliberately thin. These prefixes are not
 * reliable: Ultimate Addons registers both "hfe-site-title" and plain
 * "page-title", so a prefix table can never be the detection mechanism.
 *
 * @return array<string, string> widget-name prefix => plugin directory
 */
function nibwp_elementor_addons_widget_prefixes(): array
{
    return [
        'hfe-' => 'header-footer-elementor',
        'uael-' => 'header-footer-elementor',
        'eael-' => 'essential-addons-for-elementor-lite',
        'elementskit-' => 'elementskit-lite',
        'ekit-' => 'elementskit-lite',
        'premium-' => 'premium-addons-for-elementor',
        'wpr-' => 'royal-elementor-addons',
        'ha-' => 'happy-elementor-addons',
        'ucaddon_' => 'unlimited-elements-for-elementor',
        'jkit_' => 'jeg-elementor-kit',
        'jkit-' => 'jeg-elementor-kit',
        'qi_addons_for_elementor_' => 'qi-addons-for-elementor',
        'tp-' => 'the-plus-addons-for-elementor-page-builder',
        'htmega-' => 'ht-mega-for-elementor',
        'eae-' => 'addon-elements-for-elementor-page-builder',
    ];
}

// ---------------------------------------------------------------------------
// Detection
// ---------------------------------------------------------------------------

/**
 * Is Elementor itself loaded far enough to have a widget registry?
 *
 * The constant alone is not enough: a half-booted Elementor defines it before
 * Plugin::$instance exists, and reading the registry then is a fatal.
 */
function nibwp_elementor_addons_elementor_active(): bool
{
    return defined('ELEMENTOR_VERSION') && class_exists('\\Elementor\\Plugin');
}

/**
 * The plugin folders Elementor's own widgets come from.
 *
 * Normally "elementor" and "elementor-pro", but a renamed folder is legal, so
 * the paths Elementor reports about itself are preferred over the literals.
 *
 * @return array<int, string>
 */
function nibwp_elementor_addons_core_dirs(): array
{
    static $dirs = null;

    if ($dirs !== null) {
        return $dirs;
    }

    $found = ['elementor', 'elementor-pro'];

    foreach (['ELEMENTOR_PLUGIN_BASE', 'ELEMENTOR_PRO_PLUGIN_BASE'] as $constant) {
        if (!defined($constant)) {
            continue;
        }
        $dir = trim(str_replace('\\', '/', dirname((string) constant($constant))), '/');
        if ($dir !== '' && $dir !== '.') {
            $found[] = $dir;
        }
    }

    foreach (['ELEMENTOR_PATH', 'ELEMENTOR_PRO_PATH'] as $constant) {
        if (!defined($constant)) {
            continue;
        }
        $dir = basename(rtrim(str_replace('\\', '/', (string) constant($constant)), '/'));
        if ($dir !== '') {
            $found[] = $dir;
        }
    }

    $dirs = array_values(array_unique($found));

    return $dirs;
}

/**
 * Plugin directories, other than Elementor's own, that registered a widget.
 *
 * This is the whole of pack detection. Every entry is a directory a widget
 * class was genuinely declared in, so it cannot be a false positive, and a pack
 * this build has never heard of appears here the day it is installed.
 *
 * @return array<int, string>
 */
function nibwp_elementor_addons_pack_dirs(): array
{
    $core = nibwp_elementor_addons_core_dirs();
    $dirs = [];

    foreach (nibwp_elementor_addons_inventory() as $widget) {
        $dir = $widget['provider'];
        if ($dir === null || $dir === '' || in_array($dir, $core, true)) {
            continue;
        }
        $dirs[$dir] = true;
    }

    return array_keys($dirs);
}

/**
 * Detection for the integration as a whole: Elementor, plus at least one pack.
 *
 * Elementor on its own is the sibling elementor.php integration's business.
 */
function nibwp_elementor_addons_available(): bool
{
    return nibwp_elementor_addons_elementor_active() && nibwp_elementor_addons_pack_dirs() !== [];
}

/**
 * One refusal for every ability: no Elementor, no registry, nothing to say.
 *
 * Deliberately gated on Elementor only, not on the packs. A site that switched
 * a pack off still wants the audit to tell it which pages just broke.
 */
function nibwp_elementor_addons_guard(): ?WP_Error
{
    if (!nibwp_elementor_addons_elementor_active()) {
        return new WP_Error(
            'nibwp_elementor_addons_missing',
            __('Elementor is not active on this site, so there is no widget registry to read.', 'nibwp'),
        );
    }

    return null;
}

// ---------------------------------------------------------------------------
// Provenance: a widget class, its file, its plugin
// ---------------------------------------------------------------------------

/**
 * The content roots a widget class can legitimately live under, longest first.
 *
 * Longest first matters: WPMU_PLUGIN_DIR is usually inside WP_CONTENT_DIR, and
 * on some installs the plugins directory is moved inside another one. Matching
 * the shortest root first would attribute a must-use plugin to the wrong place.
 *
 * @return array<int, array{path: string, kind: string}>
 */
function nibwp_elementor_addons_origin_roots(): array
{
    static $roots = null;

    if ($roots !== null) {
        return $roots;
    }

    $candidates = [];

    if (defined('WP_PLUGIN_DIR')) {
        $candidates[] = ['path' => (string) constant('WP_PLUGIN_DIR'), 'kind' => 'plugin'];
    }
    if (defined('WPMU_PLUGIN_DIR')) {
        $candidates[] = ['path' => (string) constant('WPMU_PLUGIN_DIR'), 'kind' => 'mu-plugin'];
    }
    if (function_exists('get_theme_root')) {
        $candidates[] = ['path' => (string) get_theme_root(), 'kind' => 'theme'];
    }

    $roots = [];
    foreach ($candidates as $candidate) {
        $path = rtrim(str_replace('\\', '/', $candidate['path']), '/');
        if ($path === '') {
            continue;
        }
        $roots[] = ['path' => $path . '/', 'kind' => $candidate['kind']];
    }

    usort($roots, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));

    return $roots;
}

/**
 * The plugin (or theme, or must-use plugin) directory a file path sits in.
 *
 * Two traps here, both real:
 *
 *   • A class declared in eval()'d code reports its file as
 *     "/real/file.php(123) : eval()'d code". Left alone that path matches
 *     nothing. Unlimited Elements builds its widgets this way.
 *   • Packs have their own subdirectories called "plugins" — Qi Addons keeps
 *     integrations in inc/plugins/. A regex for "/plugins/<x>/" must therefore
 *     take the LEFTMOST match, which is what preg_match does, and the
 *     WP_PLUGIN_DIR prefix comparison above it avoids the question entirely.
 *
 * @return array{dir: string|null, kind: string}
 */
function nibwp_elementor_addons_path_origin(?string $file): array
{
    if ($file === null || $file === '') {
        return ['dir' => null, 'kind' => 'unknown'];
    }

    $path = str_replace('\\', '/', $file);

    // Strip the eval()'d-code suffix back to the file that ran the eval.
    $eval = strpos($path, '(');
    if ($eval !== false && str_contains($path, "eval()'d code")) {
        $path = substr($path, 0, $eval);
    }

    foreach (nibwp_elementor_addons_origin_roots() as $root) {
        if (!str_starts_with($path, $root['path'])) {
            continue;
        }
        $rest = substr($path, strlen($root['path']));
        $dir = strtok($rest, '/');
        if (!is_string($dir) || $dir === '' || $dir === $rest) {
            // A file directly inside the root (a single-file mu-plugin) has no
            // directory of its own; its basename is the best identity there is.
            $dir = basename($rest, '.php');
        }

        return ['dir' => $dir !== '' ? $dir : null, 'kind' => $root['kind']];
    }

    foreach (['mu-plugins' => 'mu-plugin', 'plugins' => 'plugin', 'themes' => 'theme'] as $segment => $kind) {
        if (preg_match('#/' . $segment . '/([^/]+)/#', $path, $matches) === 1) {
            return ['dir' => $matches[1], 'kind' => $kind];
        }
    }

    return ['dir' => null, 'kind' => 'unknown'];
}

/**
 * Where a widget object's class was declared — the class-to-plugin map.
 *
 * Memoised by class name for the request. Distinct widget types routinely share
 * a class (Unlimited Elements builds every one of its widgets from a single
 * class), and reflection is the expensive half of this file, so the cache is
 * what keeps a thousand-widget site cheap.
 *
 * @return array{class: string, file: string|null, provider: string|null, kind: string}
 */
function nibwp_elementor_addons_class_origin(object $widget): array
{
    static $cache = [];

    $class = get_class($widget);
    if (isset($cache[$class])) {
        return $cache[$class];
    }

    $file = null;
    try {
        $reflection = new ReflectionClass($widget);
        $found = $reflection->getFileName();
        if (is_string($found) && $found !== '') {
            $file = str_replace('\\', '/', $found);
        }
    } catch (\Throwable $e) {
        $file = null;
    }

    $origin = nibwp_elementor_addons_path_origin($file);

    $cache[$class] = [
        'class' => $class,
        'file' => $file,
        'provider' => $origin['dir'],
        'kind' => $origin['kind'],
    ];

    return $cache[$class];
}

/**
 * Every installed plugin, keyed by its directory, with its own header data.
 *
 * get_plugins() lives in wp-admin and is not loaded on a front-end or REST
 * request, so it is required on demand. When even that is unavailable — a
 * stripped install, or this code running outside WordPress — the main plugin
 * file is found by glob and its header read with get_file_data(), which ships
 * in wp-includes and is always there.
 *
 * Inactive plugins are included deliberately: naming the plugin a broken page
 * needs back is only possible because the deactivated one is still on disk.
 *
 * @return array<string, array{file: string, name: string, version: string, active: bool}>
 */
function nibwp_elementor_addons_installed_plugins(): array
{
    static $plugins = null;

    if ($plugins !== null) {
        return $plugins;
    }

    $plugins = [];

    if (!function_exists('get_plugins') && defined('ABSPATH') && is_readable(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $active = nibwp_elementor_addons_active_plugin_files();

    if (function_exists('get_plugins')) {
        foreach ((array) get_plugins() as $file => $data) {
            $file = (string) $file;
            $dir = str_contains($file, '/') ? explode('/', $file)[0] : basename($file, '.php');
            if ($dir === '' || isset($plugins[$dir])) {
                continue;
            }
            $plugins[$dir] = [
                'file' => $file,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => in_array($file, $active, true),
            ];
        }

        return $plugins;
    }

    if (!defined('WP_PLUGIN_DIR') || !function_exists('get_file_data')) {
        return $plugins;
    }

    $root = rtrim(str_replace('\\', '/', (string) constant('WP_PLUGIN_DIR')), '/');
    $files = glob($root . '/*/*.php');
    if (!is_array($files)) {
        return $plugins;
    }

    foreach ($files as $path) {
        $path = str_replace('\\', '/', $path);
        $relative = substr($path, strlen($root) + 1);
        $dir = explode('/', $relative)[0];
        if ($dir === '' || isset($plugins[$dir])) {
            continue;
        }
        $data = get_file_data($path, ['Name' => 'Plugin Name', 'Version' => 'Version'], 'plugin');
        if (!is_array($data) || ($data['Name'] ?? '') === '') {
            continue;
        }
        $plugins[$dir] = [
            'file' => $relative,
            'name' => (string) $data['Name'],
            'version' => (string) ($data['Version'] ?? ''),
            'active' => in_array($relative, $active, true),
        ];
    }

    return $plugins;
}

/**
 * The plugin files WordPress considers active, site and network alike.
 *
 * @return array<int, string>
 */
function nibwp_elementor_addons_active_plugin_files(): array
{
    $active = [];

    if (function_exists('get_option')) {
        foreach ((array) get_option('active_plugins', []) as $file) {
            $active[] = (string) $file;
        }
    }
    if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
        foreach (array_keys((array) get_site_option('active_sitewide_plugins', [])) as $file) {
            $active[] = (string) $file;
        }
    }

    return array_values(array_unique($active));
}

/**
 * Everything known about one provider directory.
 *
 * Resolution order is evidence first, cosmetics last: the plugin's own header,
 * then the display-name map for the handful of directories whose names mislead,
 * then the directory name itself. `source` says which one answered, so a caller
 * can tell a real product name from a folder name.
 *
 * @return array<string, mixed>
 */
function nibwp_elementor_addons_provider_meta(?string $dir, string $kind = 'plugin'): array
{
    static $cache = [];

    if ($dir === null || $dir === '') {
        return [
            'provider' => null,
            'name' => 'unresolved',
            'version' => null,
            'installed' => false,
            'active' => false,
            'is_core' => false,
            'kind' => $kind,
            'source' => 'unresolved',
        ];
    }

    $key = $kind . '|' . $dir;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $installed = nibwp_elementor_addons_installed_plugins();
    $entry = $installed[$dir] ?? null;
    $display = nibwp_elementor_addons_display_names();

    $name = '';
    $source = 'directory';

    if ($entry !== null && $entry['name'] !== '') {
        $name = $entry['name'];
        $source = 'plugin_header';
    }

    // A header that is just the directory name, or missing, is what the
    // cosmetic map exists for.
    if (($name === '' || strcasecmp($name, $dir) === 0) && isset($display[$dir])) {
        $name = $display[$dir];
        $source = 'display_map';
    }

    if ($name === '') {
        $name = $dir;
        $source = 'directory';
    }

    $cache[$key] = [
        'provider' => $dir,
        'name' => $name,
        'version' => $entry !== null && $entry['version'] !== '' ? $entry['version'] : null,
        'installed' => $entry !== null,
        'active' => $entry !== null ? $entry['active'] : false,
        'is_core' => in_array($dir, nibwp_elementor_addons_core_dirs(), true),
        'kind' => $kind,
        'source' => $source,
        'plugin_file' => $entry !== null ? $entry['file'] : null,
    ];

    return $cache[$key];
}

// ---------------------------------------------------------------------------
// The live widget registry
// ---------------------------------------------------------------------------

/**
 * Elementor's registry of every widget every plugin registered.
 *
 * `Widgets_Manager::get_widget_types()` builds the list on first call and hands
 * back `[ widget_name => Widget_Base ]`. Every guard is load-bearing: a plugin
 * calling this during `plugins_loaded` finds the class but not the singleton,
 * and a pack that throws while registering leaves the manager in a state where
 * the property exists and the method does not.
 *
 * @return array<string, object>
 */
function nibwp_elementor_addons_widget_types(): array
{
    if (!nibwp_elementor_addons_elementor_active()) {
        return [];
    }

    try {
        if (!property_exists('\\Elementor\\Plugin', 'instance')) {
            return [];
        }

        $plugin = \Elementor\Plugin::$instance;
        if (!is_object($plugin) || !isset($plugin->widgets_manager)) {
            return [];
        }

        $manager = $plugin->widgets_manager;
        if (!is_object($manager) || !method_exists($manager, 'get_widget_types')) {
            return [];
        }

        $types = $manager->get_widget_types();
    } catch (\Throwable $e) {
        return [];
    }

    if (!is_array($types)) {
        return [];
    }

    $widgets = [];
    foreach ($types as $name => $widget) {
        if (is_object($widget)) {
            $widgets[(string) $name] = $widget;
        }
    }

    return $widgets;
}

/**
 * Everything the registry will tell us about one widget, safely.
 *
 * Each accessor is a call into third-party code, so each one is guarded and
 * each is wrapped: a pack whose get_title() throws costs us that widget's
 * title, not the ability.
 *
 * @return array<string, mixed>
 */
function nibwp_elementor_addons_widget_summary(string $key, object $widget): array
{
    $name = $key;
    if (method_exists($widget, 'get_name')) {
        try {
            $reported = $widget->get_name();
            if (is_string($reported) && $reported !== '') {
                $name = $reported;
            }
        } catch (\Throwable $e) {
            $name = $key;
        }
    }

    $title = '';
    if (method_exists($widget, 'get_title')) {
        try {
            $title = (string) $widget->get_title();
        } catch (\Throwable $e) {
            $title = '';
        }
    }

    $icon = '';
    if (method_exists($widget, 'get_icon')) {
        try {
            $icon = (string) $widget->get_icon();
        } catch (\Throwable $e) {
            $icon = '';
        }
    }

    $categories = [];
    if (method_exists($widget, 'get_categories')) {
        try {
            $categories = array_values(array_map('strval', (array) $widget->get_categories()));
        } catch (\Throwable $e) {
            $categories = [];
        }
    }

    $keywords = [];
    if (method_exists($widget, 'get_keywords')) {
        try {
            $keywords = array_values(array_map('strval', (array) $widget->get_keywords()));
        } catch (\Throwable $e) {
            $keywords = [];
        }
    }

    $origin = nibwp_elementor_addons_class_origin($widget);
    $meta = nibwp_elementor_addons_provider_meta($origin['provider'], $origin['kind']);

    return [
        'name' => $name,
        'title' => $title,
        'icon' => $icon,
        'categories' => $categories,
        'keywords' => $keywords,
        'provider' => $origin['provider'],
        'provider_name' => (string) $meta['name'],
        'provider_version' => $meta['version'],
        'provider_kind' => (string) $meta['kind'],
        'provider_source' => (string) $meta['source'],
        'is_core' => (bool) $meta['is_core'],
        'class' => (string) $origin['class'],
        'file' => $origin['file'],
    ];
}

/**
 * One summary per registered widget, keyed by widget name. Built once.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_elementor_addons_inventory(bool $fresh = false): array
{
    static $inventory = null;

    if ($inventory !== null && !$fresh) {
        return $inventory;
    }

    $inventory = [];
    foreach (nibwp_elementor_addons_widget_types() as $key => $widget) {
        $summary = nibwp_elementor_addons_widget_summary($key, $widget);
        $inventory[$summary['name']] = $summary;
    }

    return $inventory;
}

/**
 * The registry folded into one entry per provider, widget counts included.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_elementor_addons_providers(): array
{
    $providers = [];

    foreach (nibwp_elementor_addons_inventory() as $widget) {
        $key = $widget['provider'] ?? 'unresolved';

        if (!isset($providers[$key])) {
            $providers[$key] = [
                'provider' => $widget['provider'],
                'name' => (string) $widget['provider_name'],
                'version' => $widget['provider_version'],
                'kind' => (string) $widget['provider_kind'],
                'resolved_from' => (string) $widget['provider_source'],
                'is_core' => (bool) $widget['is_core'],
                'widgets' => 0,
                'sample' => [],
            ];
        }

        $providers[$key]['widgets']++;
        if (count($providers[$key]['sample']) < 5) {
            $providers[$key]['sample'][] = (string) $widget['name'];
        }
    }

    // Core first, then packs by widget count, then whatever could not be placed.
    uasort($providers, static function (array $a, array $b): int {
        $rank = static function (array $provider): int {
            if ($provider['provider'] === null) {
                return 2;
            }

            return $provider['is_core'] ? 0 : 1;
        };

        $order = $rank($a) <=> $rank($b);
        if ($order !== 0) {
            return $order;
        }

        $order = $b['widgets'] <=> $a['widgets'];

        return $order !== 0 ? $order : strcmp((string) $a['provider'], (string) $b['provider']);
    });

    return $providers;
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Clamp a caller-supplied number into a range, with a default for silence.
 */
function nibwp_elementor_addons_clamp(mixed $value, int $min, int $max, int $default): int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

/**
 * Shrink a control default (or a stored setting) to something worth returning.
 *
 * Elementor defaults reach several kilobytes for a repeater or an icon library
 * entry, and a describe call that returned all of them verbatim would be mostly
 * noise. Scalars pass through; structures are kept but summarised past a couple
 * of levels.
 */
function nibwp_elementor_addons_compact(mixed $value, int $depth = 0): mixed
{
    if (is_string($value)) {
        return strlen($value) > 400 ? substr($value, 0, 400) . '...' : $value;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (!is_array($value)) {
        return is_object($value) ? '(' . get_class($value) . ')' : '(' . gettype($value) . ')';
    }

    if ($depth >= 2) {
        return ['...' => count($value) . ' more'];
    }

    $out = [];
    $seen = 0;
    foreach ($value as $key => $item) {
        if ($seen >= 20) {
            $out['...'] = (count($value) - $seen) . ' more';
            break;
        }
        $out[$key] = nibwp_elementor_addons_compact($item, $depth + 1);
        $seen++;
    }

    return $out;
}

/**
 * Labels come from third-party code and routinely carry markup and entities.
 */
function nibwp_elementor_addons_plain(string $text): string
{
    $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    $text = trim(is_string($collapsed) ? $collapsed : $text);

    return strlen($text) > 300 ? substr($text, 0, 300) . '...' : $text;
}

/**
 * Turn a `_elementor_data` meta value into an element tree.
 *
 * The meta is JSON text. Elementor's own save slashes it on the way in and
 * get_post_meta unslashes it on the way out, but a value written by something
 * that skipped the slashing comes back still escaped — so a failed decode gets
 * one more try through wp_unslash before being called empty.
 *
 * @return array<int|string, mixed>
 */
function nibwp_elementor_addons_decode(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data) && function_exists('wp_unslash')) {
        $data = json_decode((string) wp_unslash($raw), true);
    }

    return is_array($data) ? $data : [];
}

/**
 * Walk an Elementor element tree, visiting every node once.
 *
 * Elementor has had three layout models and a page can hold all three at once:
 * section -> column -> widget from before 3.6, flex `container` nesting
 * containers directly, and inner sections nested inside columns. They all use
 * the same `elements` child key, so one walk covers every shape — including a
 * container inside a column inside a section, which is what a half-migrated
 * site actually looks like.
 *
 * The depth ceiling is not theoretical: a corrupted tree, or one assembled by a
 * plugin that re-parented a node into itself, recurses until PHP dies.
 *
 * @param array<int|string, mixed> $nodes
 * @param callable                 $visit fn(array $node, int $depth): void
 */
function nibwp_elementor_addons_walk(array $nodes, callable $visit, int $depth = 0): void
{
    if ($depth > 64) {
        return;
    }

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $visit($node, $depth);

        if (!empty($node['elements']) && is_array($node['elements'])) {
            nibwp_elementor_addons_walk($node['elements'], $visit, $depth + 1);
        }
    }
}

/**
 * One pass over a tree, returning the numbers every caller here wants.
 *
 * @param array<int|string, mixed> $nodes
 * @param callable|null            $on_widget fn(array $node, string $widget_type): void
 * @return array{nodes: int, depth: int, widgets: array<string, int>, globals: array<int, int>, containers: int}
 */
function nibwp_elementor_addons_scan(array $nodes, ?callable $on_widget = null): array
{
    $result = ['nodes' => 0, 'depth' => 0, 'widgets' => [], 'globals' => [], 'containers' => 0];

    nibwp_elementor_addons_walk($nodes, static function (array $node, int $depth) use (&$result, $on_widget): void {
        $result['nodes']++;
        if ($depth > $result['depth']) {
            $result['depth'] = $depth;
        }

        $el_type = (string) ($node['elType'] ?? '');

        if ($el_type === 'section' || $el_type === 'container' || $el_type === 'column') {
            $result['containers']++;
        }

        if ($el_type !== 'widget') {
            return;
        }

        $widget_type = (string) ($node['widgetType'] ?? '');
        if ($widget_type === '') {
            $widget_type = 'unknown';
        }

        $result['widgets'][$widget_type] = ($result['widgets'][$widget_type] ?? 0) + 1;

        // A global widget is a reference, not a widget: its content lives in an
        // elementor_library post named by templateID.
        if ($widget_type === 'global' && !empty($node['templateID'])) {
            $template_id = (int) $node['templateID'];
            $result['globals'][$template_id] = ($result['globals'][$template_id] ?? 0) + 1;
        }

        if ($on_widget !== null) {
            $on_widget($node, $widget_type);
        }
    });

    return $result;
}

/**
 * Name the plugin a page needs back for a widget nothing registers any more.
 *
 * A deactivated pack leaves no class, so there is no file to reflect and
 * provenance cannot be resolved honestly. What is left is the widget's own name
 * and the list of plugins still installed. Matching the name's prefix against
 * the thin cosmetic table gives a candidate directory; looking that directory
 * up in the installed plugins turns the candidate into a fact — "Essential
 * Addons for Elementor 6.8.5 is installed but switched off" — or leaves it a
 * labelled guess.
 *
 * @return array<string, mixed>|null
 */
function nibwp_elementor_addons_locate_missing(string $widget_name): ?array
{
    $dir = null;
    $longest = 0;

    // Longest prefix wins, so "elementskit-" beats a hypothetical "e-".
    foreach (nibwp_elementor_addons_widget_prefixes() as $prefix => $candidate) {
        if (str_starts_with($widget_name, $prefix) && strlen($prefix) > $longest) {
            $dir = $candidate;
            $longest = strlen($prefix);
        }
    }

    if ($dir === null) {
        return null;
    }

    $installed = nibwp_elementor_addons_installed_plugins();
    $entry = $installed[$dir] ?? null;
    $meta = nibwp_elementor_addons_provider_meta($dir, 'plugin');
    $active = $entry !== null && $entry['active'];

    // The prefix match is a guess; whether that directory is on disk, and
    // switched on, is not. An installed-and-active plugin that no longer
    // registers the widget dropped it in an update, which is a different
    // problem from a pack somebody turned off, so the two are named apart.
    if ($entry === null) {
        $confidence = 'guess-from-widget-name';
    } elseif ($active) {
        $confidence = 'installed-and-active';
    } else {
        $confidence = 'installed-but-inactive';
    }

    return [
        'provider' => $dir,
        'name' => (string) $meta['name'],
        'version' => $meta['version'],
        'installed' => $entry !== null,
        'active' => $active,
        'confidence' => $confidence,
    ];
}

/**
 * A widget's text-bearing content controls and the defaults they ship with.
 *
 * Read from the live registry, never from a list of known placeholder strings.
 * Elementor's heading widget defaults its title to "Add Your Heading Text
 * Here"; a pack's hero widget defaults to whatever its author wrote. Both are
 * placeholders, and only the registry knows the second one.
 *
 * @return array<string, string> control name => default string
 */
function nibwp_elementor_addons_content_defaults(string $widget_name): array
{
    static $cache = [];

    if (array_key_exists($widget_name, $cache)) {
        return $cache[$widget_name];
    }

    $defaults = [];
    $widgets = nibwp_elementor_addons_widget_types();
    $widget = $widgets[$widget_name] ?? null;

    if (is_object($widget) && method_exists($widget, 'get_controls')) {
        try {
            $controls = (array) $widget->get_controls();
        } catch (\Throwable $e) {
            $controls = [];
        }

        foreach ($controls as $control_name => $control) {
            if (!is_array($control)) {
                continue;
            }
            if (!in_array((string) ($control['type'] ?? ''), ['text', 'textarea', 'wysiwyg'], true)) {
                continue;
            }
            $default = $control['default'] ?? null;
            if (!is_string($default) || trim($default) === '') {
                continue;
            }
            $defaults[(string) $control_name] = $default;
        }
    }

    $cache[$widget_name] = $defaults;

    return $defaults;
}

/**
 * Has nobody touched this widget's text since it was dropped on the page?
 *
 * True when the widget has at least one text control that ships with a default,
 * and every one of them is either absent, blank, or still exactly the default.
 * A widget with no such control cannot be judged this way and is never flagged.
 *
 * @param array<string, mixed> $node One `elType: widget` element.
 */
function nibwp_elementor_addons_is_placeholder(array $node): bool
{
    $widget_type = (string) ($node['widgetType'] ?? '');
    if ($widget_type === '') {
        return false;
    }

    $defaults = nibwp_elementor_addons_content_defaults($widget_type);
    if ($defaults === []) {
        return false;
    }

    $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];

    foreach ($defaults as $control => $default) {
        if (!array_key_exists($control, $settings)) {
            continue;
        }
        $value = $settings[$control];
        if (!is_string($value)) {
            return false;
        }
        if (trim($value) !== '' && $value !== $default) {
            return false;
        }
    }

    return true;
}

/**
 * Post IDs whose `_elementor_data` mentions a string, newest first.
 *
 * The LIKE is the point: `_elementor_data` runs to hundreds of kilobytes per
 * page and there is no way to ask the database for "pages using widget X"
 * without it. Pulling every row and decoding it in PHP is what this exists to
 * avoid, so the filter happens in MySQL and only matching rows are read back,
 * one at a time.
 *
 * Revisions are excluded always. Elementor copies the whole tree into every
 * revision, so including them multiplies every count by the edit history.
 *
 * @return array<int, int>
 */
function nibwp_elementor_addons_candidate_ids(?string $needle, string $post_type, string $status, int $limit): array
{
    global $wpdb;

    if (!is_object($wpdb)) {
        return [];
    }

    $sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm"
        . " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
        . " WHERE pm.meta_key = '_elementor_data'"
        . " AND p.post_type != 'revision'"
        . " AND p.post_status != 'auto-draft'";
    $args = [];

    if ($needle !== null && $needle !== '') {
        $sql .= ' AND pm.meta_value LIKE %s';
        $args[] = '%' . $wpdb->esc_like($needle) . '%';
    }
    if ($post_type !== '' && $post_type !== 'any') {
        $sql .= ' AND p.post_type = %s';
        $args[] = $post_type;
    }
    if ($status !== '' && $status !== 'any') {
        $sql .= ' AND p.post_status = %s';
        $args[] = $status;
    }

    $sql .= ' ORDER BY pm.post_id DESC LIMIT %d';
    $args[] = $limit;

    $rows = $wpdb->get_col($wpdb->prepare($sql, $args));

    return is_array($rows) ? array_values(array_map('intval', $rows)) : [];
}

/**
 * Read one post's element tree, refusing anything absurd.
 *
 * A single `_elementor_data` value has been seen past 20 MB on sites that paste
 * whole page templates into a widget. Decoding one of those costs several times
 * its size in memory, and doing it inside a loop over 500 posts is how a
 * read-only audit takes a site down.
 *
 * @return array{data: array<int|string, mixed>, skipped: bool}
 */
function nibwp_elementor_addons_read_tree(int $post_id): array
{
    $raw = get_post_meta($post_id, '_elementor_data', true);

    if (is_string($raw) && strlen($raw) > 4194304) {
        return ['data' => [], 'skipped' => true];
    }

    return ['data' => nibwp_elementor_addons_decode($raw), 'skipped' => false];
}

/**
 * Widget names are machine identifiers. Anything else is a caller mistake, and
 * reaching the LIKE with it would be a needlessly wide scan.
 */
function nibwp_elementor_addons_valid_widget_name(string $name): bool
{
    return $name !== '' && strlen($name) <= 128 && preg_match('/^[A-Za-z0-9._\\-]+$/', $name) === 1;
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-addons-detect
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-addons-detect', [
    'label' => __('Elementor Add-ons – Detect Packs', 'nibwp'),
    'description' => 'Report which Elementor add-on packs are active on this site, their versions, and how many widgets each one contributes to the live widget registry. '
        . 'Providers are resolved from where each widget class actually lives on disk, so premium and unlisted packs are identified too. Read-only; start here.',
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'include_core' => [
                'type' => 'boolean',
                'description' => 'Also list Elementor and Elementor Pro among the providers. Default true.',
                'default' => true,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'elementor' => ['type' => 'object', 'description' => 'Elementor core: active flag, version, plugin directories.'],
            'packs' => ['type' => 'array', 'description' => 'Add-on packs: plugin directory, name, version, widget count, how the name was resolved.'],
            'packs_active' => ['type' => 'integer'],
            'providers' => ['type' => 'array', 'description' => 'Every widget provider including core, when include_core is true.'],
            'widgets_total' => ['type' => 'integer'],
            'widgets_core' => ['type' => 'integer'],
            'widgets_from_packs' => ['type' => 'integer'],
            'widgets_unresolved' => ['type' => 'integer', 'description' => 'Widgets whose class file could not be traced to a plugin.'],
        ],
        'required' => ['elementor', 'packs', 'packs_active', 'widgets_total'],
    ],
    'execute_callback' => 'nibwp_elementor_addons_detect',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Inventory of Elementor add-on packs, derived at runtime — nothing hardcoded.',
                '',
                'HOW A PACK IS IDENTIFIED:',
                'Every registered widget object is reflected to find the file its class was',
                'declared in. The plugin directory in that path is the provider, and the',
                'plugin\'s own header gives its real name and version. That works for the',
                'popular free packs, for premium packs with no public listing, and for a pack',
                'released after this build shipped — because the path is simply true.',
                'Pack constants are never consulted.',
                '',
                'READING THE RESULT:',
                '- packs[]     — one entry per add-on plugin: provider (its directory), name,',
                '                version, widgets (how many it contributes), sample names.',
                '- resolved_from — "plugin_header" (the plugin\'s own name), "display_map" (a',
                '                cosmetic label for a directory whose header is unhelpful), or',
                '                "directory" (only the folder name was available).',
                '- widgets_unresolved — widgets whose class has no traceable file, e.g. built',
                '                in eval()\'d code. They still work; only the attribution is',
                '                missing.',
                '',
                'NEXT STEPS:',
                '- elementor-addons-list-widgets — what each pack provides.',
                '- elementor-addons-describe-widget — the control schema for one widget.',
                '- elementor-addons-audit — pages using widgets whose pack is gone.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_elementor_addons_detect(array $input): array|WP_Error
{
    $guard = nibwp_elementor_addons_guard();
    if ($guard !== null) {
        return $guard;
    }

    $include_core = !array_key_exists('include_core', $input) || (bool) $input['include_core'];

    $inventory = nibwp_elementor_addons_inventory();
    $providers = nibwp_elementor_addons_providers();

    $packs = [];
    $core_widgets = 0;
    $pack_widgets = 0;
    $unresolved = 0;

    foreach ($providers as $provider) {
        if ($provider['provider'] === null) {
            $unresolved += (int) $provider['widgets'];
            continue;
        }
        if ($provider['is_core']) {
            $core_widgets += (int) $provider['widgets'];
            continue;
        }
        $pack_widgets += (int) $provider['widgets'];
        $packs[] = $provider;
    }

    return [
        'elementor' => [
            'active' => true,
            'version' => defined('ELEMENTOR_VERSION') ? (string) constant('ELEMENTOR_VERSION') : null,
            'pro' => defined('ELEMENTOR_PRO_VERSION') ? (string) constant('ELEMENTOR_PRO_VERSION') : null,
            'plugin_dirs' => nibwp_elementor_addons_core_dirs(),
        ],
        'packs' => $packs,
        'packs_active' => count($packs),
        'providers' => $include_core ? array_values($providers) : $packs,
        'widgets_total' => count($inventory),
        'widgets_core' => $core_widgets,
        'widgets_from_packs' => $pack_widgets,
        'widgets_unresolved' => $unresolved,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-addons-list-widgets
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-addons-list-widgets', [
    'label' => __('Elementor Add-ons – List Widgets', 'nibwp'),
    'description' => 'List every widget registered with Elementor on this site, grouped by the pack that provides it, with name, title, panel category and whether it is an Elementor core widget. '
        . 'Filterable by pack or category. Read-only.',
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'pack' => [
                'type' => 'string',
                'description' => 'Restrict to one provider: the plugin directory from elementor-addons-detect, or "core" for Elementor and Elementor Pro, or "unresolved".',
            ],
            'category' => [
                'type' => 'string',
                'description' => 'Restrict to one Elementor panel category slug, e.g. "basic", "general", "happy_addons_category".',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Case-insensitive substring match against widget name, title and keywords.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Widgets per page, 1-500. Default 100.',
                'default' => 100,
            ],
            'page' => [
                'type' => 'integer',
                'description' => 'Page number, 1-based. Default 1.',
                'default' => 1,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'groups' => ['type' => 'array', 'description' => 'One entry per providing pack, each with its widgets.'],
            'total' => ['type' => 'integer', 'description' => 'Widgets matching the filters, before paging.'],
            'returned' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'pages' => ['type' => 'integer'],
            'categories' => ['type' => 'array', 'description' => 'Every panel category seen, with a widget count.'],
        ],
        'required' => ['groups', 'total', 'returned'],
    ],
    'execute_callback' => 'nibwp_elementor_addons_list_widgets',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Every widget the live Elementor registry holds, grouped by the plugin that',
                'provided it.',
                '',
                'GROUPS:',
                'One group per provider. `provider` is the plugin directory, `provider_name`',
                'the plugin\'s own name from its header, `is_core` true for Elementor and',
                'Elementor Pro. A provider of null means the widget class had no traceable',
                'file — the widget still works, only its attribution is unknown.',
                '',
                'WIDGET ENTRIES:',
                'name (the widgetType to write into page data), title, icon, categories,',
                'keywords, is_core.',
                '',
                'FILTERS:',
                'pack, category and search combine. `pack` takes a plugin directory, or the',
                'literal "core" for Elementor\'s own widgets, or "unresolved". Paging is over',
                'the flat list, so one page may hold several groups or part of one.',
                '',
                'USING THE RESULT:',
                'Take a `name` to elementor-addons-describe-widget for its full control',
                'schema, then write it into a page with nibwp/elementor-create-page as',
                '{"type":"widget","widgetType":"<name>","settings":{...}}.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_elementor_addons_list_widgets(array $input): array|WP_Error
{
    $guard = nibwp_elementor_addons_guard();
    if ($guard !== null) {
        return $guard;
    }

    $pack_filter = isset($input['pack']) ? (string) $input['pack'] : '';
    $category_filter = isset($input['category']) ? (string) $input['category'] : '';
    $search = isset($input['search']) ? strtolower(trim((string) $input['search'])) : '';
    $per_page = nibwp_elementor_addons_clamp($input['per_page'] ?? null, 1, 500, 100);
    $page = nibwp_elementor_addons_clamp($input['page'] ?? null, 1, 10000, 1);

    $matched = [];
    $categories = [];

    foreach (nibwp_elementor_addons_inventory() as $widget) {
        foreach ($widget['categories'] as $category) {
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        if ($pack_filter !== '') {
            $keep = match ($pack_filter) {
                'core', 'elementor-core' => (bool) $widget['is_core'],
                'unresolved' => $widget['provider'] === null,
                default => $widget['provider'] === $pack_filter,
            };
            if (!$keep) {
                continue;
            }
        }
        if ($category_filter !== '' && !in_array($category_filter, $widget['categories'], true)) {
            continue;
        }
        if ($search !== '') {
            $haystack = strtolower($widget['name'] . ' ' . $widget['title'] . ' ' . implode(' ', $widget['keywords']));
            if (!str_contains($haystack, $search)) {
                continue;
            }
        }

        $matched[] = $widget;
    }

    // Stable, useful order: core first, then packs by directory, then the
    // unresolved, and by widget name inside each.
    usort($matched, static function (array $a, array $b): int {
        $rank = static function (array $widget): int {
            if ($widget['provider'] === null) {
                return 2;
            }

            return $widget['is_core'] ? 0 : 1;
        };

        $order = $rank($a) <=> $rank($b);
        if ($order !== 0) {
            return $order;
        }

        $order = strcmp((string) $a['provider'], (string) $b['provider']);

        return $order !== 0 ? $order : strcmp($a['name'], $b['name']);
    });

    $total = count($matched);
    $slice = array_slice($matched, ($page - 1) * $per_page, $per_page);

    $groups = [];
    foreach ($slice as $widget) {
        $key = $widget['provider'] ?? 'unresolved';
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'provider' => $widget['provider'],
                'provider_name' => (string) $widget['provider_name'],
                'provider_version' => $widget['provider_version'],
                'resolved_from' => (string) $widget['provider_source'],
                'is_core' => (bool) $widget['is_core'],
                'widgets' => [],
            ];
        }
        $groups[$key]['widgets'][] = [
            'name' => $widget['name'],
            'title' => $widget['title'],
            'icon' => $widget['icon'],
            'categories' => $widget['categories'],
            'keywords' => $widget['keywords'],
            'is_core' => (bool) $widget['is_core'],
        ];
    }

    foreach ($groups as $key => $group) {
        $groups[$key]['count'] = count($group['widgets']);
    }

    ksort($categories);

    $category_list = [];
    foreach ($categories as $slug => $count) {
        $category_list[] = ['slug' => (string) $slug, 'widgets' => (int) $count];
    }

    return [
        'groups' => array_values($groups),
        'total' => $total,
        'returned' => count($slice),
        'page' => $page,
        'pages' => (int) ceil($total / $per_page),
        'per_page' => $per_page,
        'categories' => $category_list,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-addons-describe-widget
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-addons-describe-widget', [
    'label' => __('Elementor Add-ons – Describe Widget', 'nibwp'),
    'description' => 'Read one widget\'s complete control schema out of the live Elementor registry: every control with its name, type, default, section, tab and responsive flag. '
        . 'This is what lets you build with a third-party widget you have never seen. Read-only.',
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'widget' => [
                'type' => 'string',
                'description' => 'The widget name (widgetType), e.g. "heading", "eael-fancy-text", "ha-card".',
                'minLength' => 1,
            ],
            'tab' => [
                'type' => 'string',
                'description' => 'Restrict to one control tab: content, style, advanced, layout, settings, responsive. Omit for all.',
            ],
            'sections_only' => [
                'type' => 'boolean',
                'description' => 'Return the section list without the controls inside them. Cheap way to see the shape of a large widget first. Default false.',
                'default' => false,
            ],
            'max_controls' => [
                'type' => 'integer',
                'description' => 'Cap on controls returned, 1-2000. Default 400. Style-heavy widgets have thousands.',
                'default' => 400,
            ],
        ],
        'required' => ['widget'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'widget' => ['type' => 'object', 'description' => 'Identity: name, title, icon, categories, provider, class, file.'],
            'sections' => ['type' => 'array', 'description' => 'Control sections in panel order, each with its controls.'],
            'controls_total' => ['type' => 'integer'],
            'controls_returned' => ['type' => 'integer'],
            'truncated' => ['type' => 'boolean'],
            'tabs' => ['type' => 'array', 'description' => 'Tabs this widget uses, with a control count each.'],
        ],
        'required' => ['widget', 'sections', 'controls_total'],
    ],
    'execute_callback' => 'nibwp_elementor_addons_describe_widget',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'The full control schema for one widget, read from the widget object itself.',
                '',
                'WHY THIS EXISTS:',
                'Add-on packs do not document their widgets in any machine-readable form. This',
                'asks the registered widget object what controls it declared, so you can build',
                'with a widget nobody has written a schema for.',
                '',
                'SHAPE:',
                'sections[] — each is one panel section: { id, label, tab, controls[] }.',
                'Each control carries:',
                '  name        — the settings key to write.',
                '  type        — Elementor control type: text, textarea, wysiwyg, select,',
                '                switcher, color, media, url, slider, dimensions, repeater, ...',
                '  default     — the registered default, truncated if huge.',
                '  options     — for select/choose controls, the accepted values.',
                '  responsive  — true when Elementor also accepts name_tablet / name_mobile.',
                '  dynamic     — true when the control accepts Elementor dynamic tags.',
                '  conditional — true when the control only applies given other settings.',
                '  fields      — for a repeater, the schema of one row.',
                '',
                'WRITING SETTINGS:',
                'A widget element is {"type":"widget","widgetType":"<name>","settings":{...}}',
                'where settings keys are the control `name` values from here. Pass that to',
                'nibwp/elementor-create-page. Omitted settings fall back to the defaults',
                'reported here, so only send what you mean to change.',
                '',
                'SIZE:',
                'Style-heavy pack widgets declare 1000+ controls. Call with sections_only first',
                'to see the shape, then filter with `tab` — "content" is usually all you need',
                'to build, "style" only to restyle.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_elementor_addons_describe_widget(array $input): array|WP_Error
{
    $guard = nibwp_elementor_addons_guard();
    if ($guard !== null) {
        return $guard;
    }

    $name = trim((string) ($input['widget'] ?? ''));
    if (!nibwp_elementor_addons_valid_widget_name($name)) {
        return new WP_Error(
            'nibwp_elementor_addons_bad_widget',
            __('widget must be a widget name such as "heading" or "eael-fancy-text".', 'nibwp'),
        );
    }

    $widgets = nibwp_elementor_addons_widget_types();
    $widget = $widgets[$name] ?? null;

    if (!is_object($widget)) {
        return new WP_Error(
            'nibwp_elementor_addons_unknown_widget',
            sprintf(
                /* translators: %s: widget name. */
                __('No widget named "%s" is registered. Use elementor-addons-list-widgets to see what is.', 'nibwp'),
                $name,
            ),
            [
                'sample' => array_slice(array_keys(nibwp_elementor_addons_inventory()), 0, 20),
                'missing_plugin' => nibwp_elementor_addons_locate_missing($name),
            ],
        );
    }

    $tab_filter = isset($input['tab']) ? (string) $input['tab'] : '';
    $sections_only = !empty($input['sections_only']);
    $max_controls = nibwp_elementor_addons_clamp($input['max_controls'] ?? null, 1, 2000, 400);

    $summary = nibwp_elementor_addons_widget_summary($name, $widget);

    if (!method_exists($widget, 'get_controls')) {
        return [
            'widget' => $summary,
            'sections' => [],
            'controls_total' => 0,
            'controls_returned' => 0,
            'truncated' => false,
            'tabs' => [],
            'note' => 'This widget object does not expose get_controls(), so it has no readable schema.',
        ];
    }

    try {
        $controls = (array) $widget->get_controls();
    } catch (\Throwable $e) {
        return new WP_Error(
            'nibwp_elementor_addons_controls_failed',
            sprintf(
                /* translators: 1: widget name, 2: error message. */
                __('Reading the controls of "%1$s" failed: %2$s', 'nibwp'),
                $name,
                $e->getMessage(),
            ),
        );
    }

    // Elementor keeps sections in the same flat stack as the controls: a section
    // is a control of type "section", and every control that follows it carries
    // that section's id in its own `section` key. Walking the stack in order
    // therefore reproduces the panel exactly.
    $sections = [];
    $order = [];
    $tabs = [];
    $controls_total = 0;
    $controls_returned = 0;
    $truncated = false;

    foreach ($controls as $control_name => $control) {
        if (!is_array($control)) {
            continue;
        }

        $control_name = (string) $control_name;
        $type = (string) ($control['type'] ?? '');
        $tab = (string) ($control['tab'] ?? 'content');

        if ($type === 'section') {
            $sections[$control_name] = [
                'id' => $control_name,
                'label' => nibwp_elementor_addons_plain((string) ($control['label'] ?? '')),
                'tab' => $tab,
                'controls' => [],
            ];
            $order[] = $control_name;
            continue;
        }

        if ($tab_filter !== '' && $tab !== $tab_filter) {
            continue;
        }

        $controls_total++;
        $tabs[$tab] = ($tabs[$tab] ?? 0) + 1;

        if ($sections_only) {
            continue;
        }

        if ($controls_returned >= $max_controls) {
            $truncated = true;
            continue;
        }

        $section_id = (string) ($control['section'] ?? '');
        if ($section_id === '') {
            $section_id = '_ungrouped';
        }
        if (!isset($sections[$section_id])) {
            $sections[$section_id] = [
                'id' => $section_id,
                'label' => $section_id === '_ungrouped' ? 'Ungrouped' : $section_id,
                'tab' => $tab,
                'controls' => [],
            ];
            $order[] = $section_id;
        }

        $sections[$section_id]['controls'][] = nibwp_elementor_addons_control_shape($control_name, $control);
        $controls_returned++;
    }

    // A section filtered down to nothing is noise, so drop it — unless the
    // caller asked for the section list itself.
    $out_sections = [];
    foreach ($order as $section_id) {
        if (!isset($sections[$section_id])) {
            continue;
        }
        $section = $sections[$section_id];
        if (!$sections_only && $section['controls'] === []) {
            continue;
        }
        if ($sections_only) {
            unset($section['controls']);
        }
        $out_sections[] = $section;
    }

    $tab_list = [];
    foreach ($tabs as $tab => $count) {
        $tab_list[] = ['tab' => (string) $tab, 'controls' => (int) $count];
    }

    return [
        'widget' => $summary,
        'sections' => $out_sections,
        'controls_total' => $controls_total,
        'controls_returned' => $controls_returned,
        'truncated' => $truncated,
        'tabs' => $tab_list,
    ];
}

/**
 * One control, reduced to what an agent needs to write a value for it.
 *
 * @param array<string, mixed> $control A raw entry from Widget_Base::get_controls().
 * @return array<string, mixed>
 */
function nibwp_elementor_addons_control_shape(string $name, array $control): array
{
    $shape = [
        'name' => $name,
        'type' => (string) ($control['type'] ?? ''),
        'label' => nibwp_elementor_addons_plain((string) ($control['label'] ?? '')),
        'tab' => (string) ($control['tab'] ?? 'content'),
    ];

    if (isset($control['section']) && is_string($control['section'])) {
        $shape['section'] = $control['section'];
    }

    if (array_key_exists('default', $control)) {
        $shape['default'] = nibwp_elementor_addons_compact($control['default']);
    }

    // add_responsive_control() marks the control one of two ways depending on
    // the site's breakpoint duplication mode: either it sets is_responsive on a
    // single control, or it clones the control per device and tags each clone
    // with a `responsive` array. Both mean "this accepts _tablet / _mobile".
    $responsive = !empty($control['is_responsive']) || isset($control['responsive']);
    $shape['responsive'] = $responsive;

    if ($responsive && isset($control['responsive']) && is_array($control['responsive'])) {
        $devices = $control['responsive']['devices'] ?? null;
        if (is_array($devices)) {
            $shape['devices'] = array_values(array_map('strval', $devices));
        }
        foreach (['min', 'max'] as $edge) {
            if (isset($control['responsive'][$edge])) {
                $shape['device'] = (string) $control['responsive'][$edge];
            }
        }
    }

    if (!empty($control['options']) && is_array($control['options'])) {
        $options = [];
        foreach ($control['options'] as $value => $label) {
            if (count($options) >= 60) {
                break;
            }
            $options[(string) $value] = is_string($label) || is_int($label) || is_float($label)
                ? nibwp_elementor_addons_plain((string) $label)
                : '';
        }
        $shape['options'] = $options;
    }

    if (isset($control['description']) && is_string($control['description']) && $control['description'] !== '') {
        $shape['description'] = nibwp_elementor_addons_plain($control['description']);
    }
    if (isset($control['placeholder']) && (is_string($control['placeholder']) || is_numeric($control['placeholder']))) {
        $shape['placeholder'] = (string) $control['placeholder'];
    }
    if (!empty($control['dynamic']['active'])) {
        $shape['dynamic'] = true;
    }
    if (!empty($control['condition']) || !empty($control['conditions'])) {
        $shape['conditional'] = true;
        if (!empty($control['condition']) && is_array($control['condition'])) {
            $shape['condition'] = nibwp_elementor_addons_compact($control['condition']);
        }
    }
    if (!empty($control['label_block'])) {
        $shape['label_block'] = true;
    }
    if (isset($control['min']) && is_numeric($control['min'])) {
        $shape['min'] = $control['min'] + 0;
    }
    if (isset($control['max']) && is_numeric($control['max'])) {
        $shape['max'] = $control['max'] + 0;
    }
    if (!empty($control['size_units']) && is_array($control['size_units'])) {
        $shape['size_units'] = array_values(array_map('strval', $control['size_units']));
    }
    if (!empty($control['fields']) && is_array($control['fields'])) {
        // A repeater's row schema. The fields are controls in the same shape.
        $fields = [];
        foreach ($control['fields'] as $field_name => $field) {
            if (!is_array($field) || count($fields) >= 40) {
                continue;
            }
            $fields[] = nibwp_elementor_addons_control_shape((string) ($field['name'] ?? $field_name), $field);
        }
        $shape['fields'] = $fields;
    }

    return $shape;
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-addons-find-usage
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-addons-find-usage', [
    'label' => __('Elementor Add-ons – Find Widget Usage', 'nibwp'),
    'description' => 'Find where a widget is used across the site: which posts and pages contain it and how many instances each one holds. '
        . 'Reads Elementor\'s _elementor_data post meta and walks the element tree. Read-only.',
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'widget' => [
                'type' => 'string',
                'description' => 'The widget name (widgetType) to look for, e.g. "eael-fancy-text".',
                'minLength' => 1,
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Restrict to one post type. Default "any". Revisions are always excluded.',
                'default' => 'any',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Restrict to one post status, e.g. "publish". Default "any".',
                'default' => 'any',
            ],
            'scan_limit' => [
                'type' => 'integer',
                'description' => 'How many candidate posts to open and walk, 1-500. Default 200.',
                'default' => 200,
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'How many matching posts to return, 1-200. Default 50.',
                'default' => 50,
            ],
        ],
        'required' => ['widget'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'widget' => ['type' => 'string'],
            'registered' => ['type' => 'boolean', 'description' => 'False means no active plugin provides it and every instance renders broken.'],
            'provider' => ['type' => ['object', 'null'], 'description' => 'The plugin that provides it, or the one the site needs back.'],
            'posts' => ['type' => 'array', 'description' => 'Matching posts, most instances first.'],
            'posts_matched' => ['type' => 'integer'],
            'instances_total' => ['type' => 'integer'],
            'scanned' => ['type' => 'integer'],
            'truncated' => ['type' => 'boolean'],
        ],
        'required' => ['widget', 'posts', 'posts_matched', 'instances_total'],
    ],
    'execute_callback' => 'nibwp_elementor_addons_find_usage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Where a widget is actually used on this site.',
                '',
                'HOW IT WORKS:',
                'Elementor stores each page as JSON in the _elementor_data post meta. Candidate',
                'rows are filtered in MySQL on the widget name, then each candidate is opened',
                'one at a time and its element tree walked to count real instances — the LIKE',
                'can match a name inside a setting, the tree walk cannot.',
                '',
                'WHAT COMES BACK:',
                'posts[] = { id, title, post_type, status, instances, nodes, edit_url,',
                'permalink }, ordered by instance count. `registered` says whether the widget',
                'still exists in the registry; when it does, `provider` names the plugin that',
                'provides it, resolved from the widget class\'s own file. When it does not,',
                '`provider` is the best available answer for which plugin the site needs back,',
                'with a `confidence` field saying how firm that is.',
                '',
                'LIMITS:',
                'Revisions are excluded (Elementor copies the whole tree into each one).',
                'scan_limit caps how many posts are opened; `truncated` says more matched than',
                'were walked. A single _elementor_data value over 4 MB is skipped and counted',
                'in `skipped_oversized` rather than decoded.',
                '',
                'TYPICAL USE:',
                'Before switching a pack off, or before deleting a widget, ask this first.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_elementor_addons_find_usage(array $input): array|WP_Error
{
    $guard = nibwp_elementor_addons_guard();
    if ($guard !== null) {
        return $guard;
    }

    $widget = trim((string) ($input['widget'] ?? ''));
    if (!nibwp_elementor_addons_valid_widget_name($widget)) {
        return new WP_Error(
            'nibwp_elementor_addons_bad_widget',
            __('widget must be a widget name such as "heading" or "eael-fancy-text".', 'nibwp'),
        );
    }

    $post_type = isset($input['post_type']) ? (string) $input['post_type'] : 'any';
    $status = isset($input['status']) ? (string) $input['status'] : 'any';
    $scan_limit = nibwp_elementor_addons_clamp($input['scan_limit'] ?? null, 1, 500, 200);
    $per_page = nibwp_elementor_addons_clamp($input['per_page'] ?? null, 1, 200, 50);

    // One extra row, purely to learn whether there were more than we will walk.
    $needle = '"widgetType":"' . $widget . '"';
    $ids = nibwp_elementor_addons_candidate_ids($needle, $post_type, $status, $scan_limit + 1);
    $truncated = count($ids) > $scan_limit;
    $ids = array_slice($ids, 0, $scan_limit);

    $posts = [];
    $instances_total = 0;
    $skipped = 0;

    foreach ($ids as $post_id) {
        $read = nibwp_elementor_addons_read_tree($post_id);
        if ($read['skipped']) {
            $skipped++;
            continue;
        }

        $scan = nibwp_elementor_addons_scan($read['data']);
        $instances = (int) ($scan['widgets'][$widget] ?? 0);
        if ($instances === 0) {
            continue;
        }

        $post = get_post($post_id);
        $instances_total += $instances;

        $posts[] = [
            'id' => $post_id,
            'title' => $post ? (string) $post->post_title : '',
            'post_type' => $post ? (string) $post->post_type : '',
            'status' => $post ? (string) $post->post_status : '',
            'instances' => $instances,
            'nodes' => (int) $scan['nodes'],
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'permalink' => (string) get_permalink($post_id),
        ];
    }

    usort($posts, static function (array $a, array $b): int {
        $order = $b['instances'] <=> $a['instances'];

        return $order !== 0 ? $order : ($a['id'] <=> $b['id']);
    });

    $inventory = nibwp_elementor_addons_inventory();
    $summary = $inventory[$widget] ?? null;

    if ($summary !== null) {
        $provider = [
            'provider' => $summary['provider'],
            'name' => (string) $summary['provider_name'],
            'version' => $summary['provider_version'],
            'installed' => true,
            'active' => true,
            'confidence' => 'class-file',
        ];
    } else {
        $provider = nibwp_elementor_addons_locate_missing($widget);
    }

    return [
        'widget' => $widget,
        'registered' => $summary !== null,
        'provider' => $provider,
        'posts' => array_slice($posts, 0, $per_page),
        'posts_matched' => count($posts),
        'instances_total' => $instances_total,
        'scanned' => count($ids),
        'skipped_oversized' => $skipped,
        'truncated' => $truncated || count($posts) > $per_page,
    ];
}

// ---------------------------------------------------------------------------
// Ability: nibwp/elementor-addons-audit
// ---------------------------------------------------------------------------

wp_register_ability('nibwp/elementor-addons-audit', [
    'label' => __('Elementor Add-ons – Audit', 'nibwp'),
    'description' => 'Read-only health check of Elementor content against the live widget registry: widgets whose providing pack is no longer active (these render broken or empty), '
        . 'duplicate global widgets, pages with an enormous element count, and widgets still holding their default placeholder content.',
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'checks' => [
                'type' => 'array',
                'description' => 'Which checks to run. Default: all of them.',
                'items' => [
                    'type' => 'string',
                    'enum' => ['orphaned', 'duplicate_globals', 'heavy_pages', 'placeholders'],
                ],
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Restrict the scan to one post type. Default "any". Revisions are always excluded.',
                'default' => 'any',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Restrict the scan to one post status. Default "any".',
                'default' => 'any',
            ],
            'scan_limit' => [
                'type' => 'integer',
                'description' => 'How many Elementor posts to open and walk, 1-500. Default 100, newest first.',
                'default' => 100,
            ],
            'node_threshold' => [
                'type' => 'integer',
                'description' => 'Element count above which a page is reported as heavy, 50-20000. Default 500.',
                'default' => 500,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'verdict' => ['type' => 'string', 'description' => 'ok | warnings | blockers'],
            'scanned' => ['type' => 'integer'],
            'blockers' => ['type' => 'array'],
            'warnings' => ['type' => 'array'],
            'orphaned_widgets' => ['type' => 'array', 'description' => 'Widget types used on pages but absent from the registry, with the plugin the site needs back.'],
            'duplicate_globals' => ['type' => 'array', 'description' => 'Global widget templates with byte-identical content.'],
            'heavy_pages' => ['type' => 'array'],
            'placeholder_widgets' => ['type' => 'array'],
            'truncated' => ['type' => 'boolean'],
        ],
        'required' => ['verdict', 'scanned', 'blockers', 'warnings'],
    ],
    'execute_callback' => 'nibwp_elementor_addons_audit',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read-only audit. Changes nothing, ever.',
                '',
                'CHECKS:',
                '- orphaned — a page uses a widgetType that no active plugin registers. The pack',
                '  was switched off, renamed or removed. Elementor renders these as empty space',
                '  on the front end while the editor still shows the element. Each finding',
                '  carries `missing_plugin`: when that pack is still installed but deactivated,',
                '  it names the plugin and version to switch back on. This is the only check',
                '  that produces blockers.',
                '- duplicate_globals — global widget templates (elementor_library posts of type',
                '  "widget") whose content is byte-identical. Editing one leaves the other',
                '  stale, which is how a "fixed" header comes back on half the site.',
                '- heavy_pages — element count over node_threshold. Elementor renders every',
                '  node server-side, so this is the usual cause of a slow Elementor page.',
                '- placeholders — widgets whose text still equals the default the widget was',
                '  registered with ("Add Your Heading Text Here" and its equivalent in every',
                '  pack). The defaults are read from the live registry, not from a word list,',
                '  so a pack\'s own placeholder text is caught too.',
                '',
                'VERDICT:',
                'blockers -> something is rendering wrong now. warnings -> worth fixing.',
                'ok -> nothing found within the scanned window.',
                '',
                'SCOPE:',
                'scan_limit posts, newest first, revisions excluded. `truncated` means the site',
                'has more Elementor content than was scanned — raise scan_limit or narrow',
                'post_type and scan again rather than trusting a clean verdict.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_elementor_addons_audit(array $input): array|WP_Error
{
    $guard = nibwp_elementor_addons_guard();
    if ($guard !== null) {
        return $guard;
    }

    $all_checks = ['orphaned', 'duplicate_globals', 'heavy_pages', 'placeholders'];
    $requested = isset($input['checks']) && is_array($input['checks'])
        ? array_values(array_intersect($all_checks, array_map('strval', $input['checks'])))
        : $all_checks;
    if ($requested === []) {
        $requested = $all_checks;
    }

    $post_type = isset($input['post_type']) ? (string) $input['post_type'] : 'any';
    $status = isset($input['status']) ? (string) $input['status'] : 'any';
    $scan_limit = nibwp_elementor_addons_clamp($input['scan_limit'] ?? null, 1, 500, 100);
    $node_threshold = nibwp_elementor_addons_clamp($input['node_threshold'] ?? null, 50, 20000, 500);

    $inventory = nibwp_elementor_addons_inventory();

    $ids = nibwp_elementor_addons_candidate_ids(null, $post_type, $status, $scan_limit + 1);
    $truncated = count($ids) > $scan_limit;
    $ids = array_slice($ids, 0, $scan_limit);

    $orphans = [];
    $heavy = [];
    $placeholders = [];
    $global_refs = [];
    $skipped = 0;
    $scanned = 0;

    foreach ($ids as $post_id) {
        $read = nibwp_elementor_addons_read_tree($post_id);
        if ($read['skipped']) {
            $skipped++;
            continue;
        }
        if ($read['data'] === []) {
            continue;
        }

        $scanned++;
        $found_placeholders = [];

        $on_widget = null;
        if (in_array('placeholders', $requested, true)) {
            $on_widget = static function (array $node, string $widget_type) use (&$found_placeholders): void {
                if (count($found_placeholders) >= 20) {
                    return;
                }
                if (nibwp_elementor_addons_is_placeholder($node)) {
                    $found_placeholders[$widget_type] = ($found_placeholders[$widget_type] ?? 0) + 1;
                }
            };
        }

        $scan = nibwp_elementor_addons_scan($read['data'], $on_widget);

        if (in_array('orphaned', $requested, true)) {
            foreach ($scan['widgets'] as $widget_type => $count) {
                // "global" is Elementor's own reference element, never a
                // registered widget, so it is not an orphan.
                if ($widget_type === 'global' || $widget_type === 'unknown' || isset($inventory[$widget_type])) {
                    continue;
                }
                if (!isset($orphans[$widget_type])) {
                    $orphans[$widget_type] = [
                        'widget' => $widget_type,
                        'instances' => 0,
                        'posts' => 0,
                        'post_ids' => [],
                        'missing_plugin' => nibwp_elementor_addons_locate_missing($widget_type),
                    ];
                }
                $orphans[$widget_type]['instances'] += (int) $count;
                $orphans[$widget_type]['posts']++;
                if (count($orphans[$widget_type]['post_ids']) < 10) {
                    $orphans[$widget_type]['post_ids'][] = $post_id;
                }
            }
        }

        if (in_array('heavy_pages', $requested, true) && $scan['nodes'] >= $node_threshold) {
            $post = get_post($post_id);
            $heavy[] = [
                'id' => $post_id,
                'title' => $post ? (string) $post->post_title : '',
                'post_type' => $post ? (string) $post->post_type : '',
                'nodes' => (int) $scan['nodes'],
                'max_depth' => (int) $scan['depth'],
                'widget_types' => count($scan['widgets']),
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            ];
        }

        if ($found_placeholders !== []) {
            $post = get_post($post_id);
            $total = 0;
            foreach ($found_placeholders as $count) {
                $total += (int) $count;
            }
            $placeholders[] = [
                'id' => $post_id,
                'title' => $post ? (string) $post->post_title : '',
                'widgets' => $found_placeholders,
                'instances' => $total,
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            ];
        }

        foreach ($scan['globals'] as $template_id => $count) {
            $global_refs[$template_id] = ($global_refs[$template_id] ?? 0) + (int) $count;
        }
    }

    $duplicate_globals = in_array('duplicate_globals', $requested, true)
        ? nibwp_elementor_addons_duplicate_globals($global_refs)
        : [];

    $orphans = array_values($orphans);

    usort($heavy, static fn (array $a, array $b): int => $b['nodes'] <=> $a['nodes']);
    usort($placeholders, static fn (array $a, array $b): int => $b['instances'] <=> $a['instances']);
    usort($orphans, static fn (array $a, array $b): int => $b['instances'] <=> $a['instances']);

    $heavy = array_slice($heavy, 0, 50);
    $placeholders = array_slice($placeholders, 0, 50);
    $orphans = array_slice($orphans, 0, 50);

    $blockers = [];
    $warnings = [];

    foreach ($orphans as $orphan) {
        $missing = $orphan['missing_plugin'];
        $needs = '';
        if (is_array($missing)) {
            $needs = match ((string) $missing['confidence']) {
                'installed-but-inactive' => sprintf(' — switch %s back on', (string) $missing['name']),
                'installed-and-active' => sprintf(' — %s is active but no longer registers it', (string) $missing['name']),
                default => sprintf(' — looks like %s', (string) $missing['name']),
            };
        }
        $blockers[] = sprintf(
            'Widget "%s" is used %d time(s) across %d post(s) but no active plugin registers it%s. Those instances render empty.',
            (string) $orphan['widget'],
            (int) $orphan['instances'],
            (int) $orphan['posts'],
            $needs,
        );
    }
    foreach ($duplicate_globals as $group) {
        $warnings[] = sprintf(
            '%d global widget templates hold identical content (#%s) — editing one leaves the others stale.',
            count($group['templates']),
            implode(', #', array_map(static fn (array $t): string => (string) $t['id'], $group['templates'])),
        );
    }
    foreach ($heavy as $page) {
        $warnings[] = sprintf(
            'Post %d ("%s") holds %d elements, over the %d threshold — Elementor renders every one server-side.',
            (int) $page['id'],
            (string) $page['title'],
            (int) $page['nodes'],
            $node_threshold,
        );
    }
    foreach ($placeholders as $page) {
        $warnings[] = sprintf(
            'Post %d ("%s") has %d widget(s) still holding the text they were registered with.',
            (int) $page['id'],
            (string) $page['title'],
            (int) $page['instances'],
        );
    }

    $verdict = 'ok';
    if ($blockers !== []) {
        $verdict = 'blockers';
    } elseif ($warnings !== []) {
        $verdict = 'warnings';
    }

    return [
        'verdict' => $verdict,
        'checks' => $requested,
        'scanned' => $scanned,
        'skipped_oversized' => $skipped,
        'blockers' => $blockers,
        'warnings' => $warnings,
        'orphaned_widgets' => $orphans,
        'duplicate_globals' => $duplicate_globals,
        'heavy_pages' => $heavy,
        'placeholder_widgets' => $placeholders,
        'node_threshold' => $node_threshold,
        'truncated' => $truncated,
    ];
}

/**
 * Global widget templates whose content is identical.
 *
 * Elementor Pro stores a global widget as an `elementor_library` post whose
 * `_elementor_template_type` is "widget"; a page references one by templateID
 * on an element with widgetType "global". Two templates holding the same
 * content are the failure people hit: they edit one, half the site changes, and
 * nothing on the page says the other exists.
 *
 * Element ids are stripped before hashing — they are random per save, so two
 * genuine copies never hash the same with them left in.
 *
 * @param array<int, int> $reference_counts templateID => instances seen while scanning.
 * @return array<int, array<string, mixed>>
 */
function nibwp_elementor_addons_duplicate_globals(array $reference_counts): array
{
    if (!function_exists('get_posts')) {
        return [];
    }

    $posts = get_posts([
        'post_type' => 'elementor_library',
        'post_status' => 'any',
        'posts_per_page' => 300,
        'meta_key' => '_elementor_template_type',
        'meta_value' => 'widget',
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => false,
    ]);

    if (!is_array($posts)) {
        return [];
    }

    $by_hash = [];
    foreach ($posts as $post) {
        if (!is_object($post) || !isset($post->ID)) {
            continue;
        }
        $post_id = (int) $post->ID;
        $read = nibwp_elementor_addons_read_tree($post_id);
        if ($read['skipped'] || $read['data'] === []) {
            continue;
        }

        $normalised = wp_json_encode(nibwp_elementor_addons_strip_ids($read['data']));
        if (!is_string($normalised)) {
            continue;
        }

        $hash = md5($normalised);
        if (!isset($by_hash[$hash])) {
            $by_hash[$hash] = [];
        }
        $by_hash[$hash][] = [
            'id' => $post_id,
            'title' => (string) ($post->post_title ?? ''),
            'references' => (int) ($reference_counts[$post_id] ?? 0),
        ];
    }

    $groups = [];
    foreach ($by_hash as $hash => $templates) {
        if (count($templates) < 2) {
            continue;
        }
        $groups[] = [
            'hash' => substr($hash, 0, 12),
            'templates' => $templates,
        ];
    }

    return $groups;
}

/**
 * Drop the random per-element ids so two copies of the same content compare equal.
 *
 * @param array<int|string, mixed> $nodes
 * @return array<int|string, mixed>
 */
function nibwp_elementor_addons_strip_ids(array $nodes): array
{
    $out = [];

    foreach ($nodes as $key => $node) {
        if (!is_array($node)) {
            $out[$key] = $node;
            continue;
        }
        unset($node['id']);
        if (isset($node['elements']) && is_array($node['elements'])) {
            $node['elements'] = nibwp_elementor_addons_strip_ids($node['elements']);
        }
        $out[$key] = $node;
    }

    return $out;
}
