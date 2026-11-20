<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builderius Pro — the module and setting vocabulary of THIS install.
 *
 * Two questions have to be answered before a config can be committed: is this a
 * real module, and does that module accept this setting. Both answers live on the
 * site, not in a document, and both are read here.
 *
 * Modules come from the integration's library (nibwp_builderius_module_library()),
 * which is also what the integration's own validator checks against. Reading it
 * through the integration rather than restating it means the skill and the thing
 * that persists the config cannot disagree about what a module is.
 *
 * Settings come from Builderius's own service container config. Every module
 * setting is declared there with a `name` and either `appliedToModules: [...]`
 * or `appliedToAllModules: true` (with an optional `excludedFromModules`), e.g.
 *
 *     builderius_setting.setting.content_svg:
 *       class: Builderius\…\Model\BuilderiusModuleSetting
 *       arguments:
 *         - name: 'contentSvg'
 *           appliedToModules: [ 'SvgCode' ]
 *
 * — src/Bundle/SettingBundle/Resources/config/settings.yml in the Builderius
 * plugin. Settings declared with the plain `BuilderiusSetting` class instead of
 * `BuilderiusModuleSetting` are template- or global-level, not per-module, and
 * are kept apart so a config that puts one on a module can be told why it is
 * wrong rather than merely that it is.
 *
 * Why parse YAML by hand: WordPress ships no YAML parser, Builderius's own is
 * behind a Symfony kernel it boots into a local variable (see the integration's
 * lib/writer.php), and the subset used by these declarations is two lines deep.
 * If Builderius ever moves or restructures that file, the registry comes back
 * empty and the validator says it could not read it — it does not quietly stop
 * checking settings.
 */

/**
 * Every module type this install can author with, name => human description.
 *
 * @return array<string,string>
 */
function nibwp_builderius_pro_modules(): array
{
    if (!function_exists('nibwp_builderius_module_library')) {
        return [];
    }

    return nibwp_builderius_module_library();
}

/** Is this a real module on this install? */
function nibwp_builderius_pro_module_exists(string $name): bool
{
    return array_key_exists($name, nibwp_builderius_pro_modules());
}

/**
 * Candidate paths for Builderius's setting declarations.
 *
 * Globbed rather than hardcoded to one directory because the plugin folder can
 * be renamed, and because the Pro add-on ships the same bundle layout and
 * declares further settings; every match is merged.
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_settings_files(): array
{
    $files = [];

    if (defined('WP_PLUGIN_DIR')) {
        $globs = [
            WP_PLUGIN_DIR . '/*/src/Bundle/SettingBundle/Resources/config/settings.yml',
            WP_PLUGIN_DIR . '/*/src/Bundle/SettingBundle/Resources/config/setting_*.yml',
            WP_PLUGIN_DIR . '/*/src/Bundle/*/Resources/config/settings.yml',
        ];
        foreach ($globs as $pattern) {
            foreach ((array) glob($pattern) as $match) {
                if (is_string($match) && is_file($match)) {
                    $files[$match] = $match;
                }
            }
        }
    }

    /**
     * Extra setting-declaration files to merge in.
     *
     * A third-party module pack that declares its settings somewhere else can
     * point the validator at them rather than have its settings rejected.
     *
     * @param array<int,string> $files absolute paths
     */
    $extra = apply_filters('nibwp_builderius_pro_settings_files', array_values($files));

    $out = [];
    foreach ((array) $extra as $path) {
        $path = (string) $path;
        if ($path !== '' && is_file($path)) {
            $out[$path] = $path;
        }
    }

    return array_values($out);
}

/**
 * The parsed setting registry.
 *
 * @return array{
 *     modules: array<string,array<int,string>>,
 *     all_modules: array<int,string>,
 *     excluded: array<string,array<int,string>>,
 *     template_settings: array<int,string>,
 *     files: array<int,string>
 * }
 */
function nibwp_builderius_pro_settings_registry(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $registry = [
        'modules'           => [],
        'all_modules'       => [],
        'excluded'          => [],
        'template_settings' => [],
        'files'             => [],
    ];

    foreach (nibwp_builderius_pro_settings_files() as $file) {
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $registry['files'][] = $file;
        foreach (nibwp_builderius_pro_parse_settings_yaml($raw) as $decl) {
            $name = $decl['name'];
            if ($name === '') {
                continue;
            }
            if (!$decl['module_setting']) {
                $registry['template_settings'][$name] = $name;
                continue;
            }
            if ($decl['all_modules']) {
                $registry['all_modules'][$name] = $name;
                if ($decl['excluded'] !== []) {
                    $registry['excluded'][$name] = $decl['excluded'];
                }
                continue;
            }
            foreach ($decl['modules'] as $module) {
                $registry['modules'][$module][$name] = $name;
            }
        }
    }

    // Flatten the dedupe maps to lists so callers get something printable.
    $registry['all_modules']       = array_values($registry['all_modules']);
    $registry['template_settings'] = array_values($registry['template_settings']);
    foreach ($registry['modules'] as $module => $names) {
        $registry['modules'][$module] = array_values($names);
    }

    $cache = $registry;

    return $cache;
}

/**
 * Parse the setting declarations out of one container config file.
 *
 * @return array<int,array{name:string,modules:array<int,string>,all_modules:bool,excluded:array<int,string>,module_setting:bool}>
 */
function nibwp_builderius_pro_parse_settings_yaml(string $raw): array
{
    $out = [];
    $current = null;

    $flush = static function (?array $block) use (&$out): void {
        if ($block === null || $block['name'] === '') {
            return;
        }
        $out[] = $block;
    };

    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        // A service declaration starts a new block: two spaces, a key, nothing else.
        if (preg_match('/^  ([A-Za-z0-9_.\-]+):\s*$/', $line) === 1) {
            $flush($current);
            $current = [
                'name'           => '',
                'modules'        => [],
                'all_modules'    => false,
                'excluded'       => [],
                'module_setting' => false,
            ];
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^    class:\s*(\S+)/', $line, $m) === 1) {
            // Only the per-module setting class is a module setting. The plain
            // BuilderiusSetting class is a template/global setting.
            $current['module_setting'] = str_ends_with(rtrim($m[1], '\\'), 'BuilderiusModuleSetting');
            continue;
        }
        // Argument keys sit at column 8, either as the first entry of the list
        // item ("      - name: 'tag'") or as a following key ("        name: …").
        if (preg_match('/^ {6}- ([A-Za-z]+):\s*(.*)$/', $line, $m) === 1
            || preg_match('/^ {8}([A-Za-z]+):\s*(.*)$/', $line, $m) === 1) {
            $key = $m[1];
            $value = trim($m[2]);
            if ($key === 'name' && $current['name'] === '') {
                $current['name'] = trim($value, "'\" ");
            } elseif ($key === 'appliedToModules') {
                $current['modules'] = nibwp_builderius_pro_parse_inline_list($value);
            } elseif ($key === 'appliedToAllModules') {
                $current['all_modules'] = in_array(strtolower($value), ['true', 'yes', '1'], true);
            } elseif ($key === 'excludedFromModules') {
                $current['excluded'] = nibwp_builderius_pro_parse_inline_list($value);
            }
        }
    }

    $flush($current);

    return $out;
}

/**
 * `[ 'HtmlElement', 'Collection' ]` → ['HtmlElement', 'Collection'].
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_parse_inline_list(string $value): array
{
    $value = trim($value, "[] \t");
    if ($value === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part, "'\" \t");
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return $out;
}

/**
 * Setting names already in use on this site, per module.
 *
 * The container config is the authority on what is valid, but it is only as
 * complete as the add-ons installed. A setting that a config stored on this site
 * already carries is real whatever the registry says, so it is unioned in —
 * never used as the sole source, because a name no config uses yet is not
 * thereby wrong.
 *
 * @return array<string,array<int,string>>
 */
function nibwp_builderius_pro_observed_settings(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];

    if (!function_exists('nibwp_builderius_resolve_template') || !function_exists('nibwp_builderius_list_templates')) {
        return $cache;
    }

    $configs = [];
    // Capped: this walks stored configs, and a big site has hundreds.
    foreach (array_slice(nibwp_builderius_pro_list_templates_safe(), 0, 25) as $template) {
        $resolved = nibwp_builderius_resolve_template((int) ($template['id'] ?? 0));
        if (isset($resolved['config']) && is_array($resolved['config'])) {
            $configs[] = $resolved['config'];
        }
    }

    foreach ($configs as $config) {
        foreach ((array) ($config['modules'] ?? []) as $module) {
            if (!is_array($module)) {
                continue;
            }
            $name = (string) ($module['name'] ?? '');
            if ($name === '') {
                continue;
            }
            foreach ((array) ($module['settings'] ?? []) as $setting) {
                if (is_array($setting) && isset($setting['name'])) {
                    $key = (string) $setting['name'];
                    $cache[$name][$key] = $key;
                }
            }
        }
    }

    foreach ($cache as $module => $names) {
        $cache[$module] = array_values($names);
    }

    return $cache;
}

/**
 * Template list that tolerates the integration being absent.
 *
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_pro_list_templates_safe(): array
{
    if (!function_exists('nibwp_builderius_list_templates')) {
        return [];
    }

    return nibwp_builderius_list_templates();
}

/**
 * Every setting name one module accepts.
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_module_settings(string $module): array
{
    $registry = nibwp_builderius_pro_settings_registry();

    $names = (array) ($registry['modules'][$module] ?? []);
    foreach ($registry['all_modules'] as $name) {
        $excluded = (array) ($registry['excluded'][$name] ?? []);
        if (!in_array($module, $excluded, true)) {
            $names[] = $name;
        }
    }
    foreach ((array) (nibwp_builderius_pro_observed_settings()[$module] ?? []) as $name) {
        $names[] = $name;
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * Which modules accept a setting name — the answer to "then where does this go?".
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_setting_owners(string $setting): array
{
    $registry = nibwp_builderius_pro_settings_registry();

    if (in_array($setting, $registry['all_modules'], true)) {
        $excluded = (array) ($registry['excluded'][$setting] ?? []);
        $owners = [];
        foreach (array_keys(nibwp_builderius_pro_modules()) as $module) {
            if (!in_array($module, $excluded, true)) {
                $owners[] = $module;
            }
        }

        return $owners;
    }

    $owners = [];
    foreach ($registry['modules'] as $module => $names) {
        if (in_array($setting, (array) $names, true)) {
            $owners[] = (string) $module;
        }
    }
    foreach (nibwp_builderius_pro_observed_settings() as $module => $names) {
        if (in_array($setting, (array) $names, true)) {
            $owners[] = (string) $module;
        }
    }

    return array_values(array_unique($owners));
}

/** Is this a template- or global-level setting rather than a module one? */
function nibwp_builderius_pro_is_template_setting(string $setting): bool
{
    $registry = nibwp_builderius_pro_settings_registry();

    return in_array($setting, $registry['template_settings'], true)
        && !in_array($setting, $registry['all_modules'], true)
        && nibwp_builderius_pro_setting_owners($setting) === [];
}

/** Did the setting registry read at all? Nothing may be validated against [] . */
function nibwp_builderius_pro_registry_readable(): bool
{
    $registry = nibwp_builderius_pro_settings_registry();

    return $registry['all_modules'] !== [] || $registry['modules'] !== [];
}

/**
 * The closest candidates to a name that does not exist.
 *
 * An agent that writes `textContent` where the setting is `content` should be
 * told which it meant. The threshold scales with the length of the word so a
 * three-letter name does not match everything.
 *
 * @param array<int,string> $candidates
 * @return array<int,string>
 */
function nibwp_builderius_pro_suggest(string $wanted, array $candidates, int $limit = 3): array
{
    $needle = strtolower($wanted);
    $scored = [];

    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;
        $distance = levenshtein($needle, strtolower($candidate));
        $ceiling = max(2, (int) floor(strlen($needle) / 2));
        // A candidate that contains the wanted name (or the reverse) is a match
        // whatever the edit distance says: "content" inside "textContent" is the
        // most common way this goes wrong and levenshtein scores it 4.
        $contains = $needle !== '' && (str_contains(strtolower($candidate), $needle) || str_contains($needle, strtolower($candidate)));
        if ($distance <= $ceiling || $contains) {
            $scored[$candidate] = $contains ? min($distance, 1) : $distance;
        }
    }

    asort($scored);

    return array_slice(array_keys($scored), 0, $limit);
}
