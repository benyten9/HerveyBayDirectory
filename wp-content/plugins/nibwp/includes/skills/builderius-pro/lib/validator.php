<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';

/**
 * Builderius Pro — validation, before anything is committed.
 *
 * Builderius does not complain about a module type it has never heard of, and it
 * does not complain about a setting a module does not accept. It renders what it
 * understands and drops the rest, which is why the usual symptom of a bad config
 * is a section that exists, has the right number of boxes in it, and is empty.
 *
 * So everything is checked here first, and an error names the module it is about.
 * Errors stop a commit; warnings and recommendations are reported and do not.
 */

/**
 * Validate a nested authoring tree — the shape the integration's `tree` input
 * takes: [{name, label?, settings?, children?}].
 *
 * @param array<int,mixed> $tree
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_validate_tree(array $tree): array
{
    $report = [
        'passed'          => true,
        'errors'          => [],
        'warnings'        => [],
        'recommendations' => [],
        'module_count'    => 0,
        'modules_used'    => [],
        'tokens'          => [],
    ];

    if ($tree === []) {
        $report['passed'] = false;
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_empty_tree',
            'message' => __('The tree is empty — there is nothing to commit.', 'nibwp'),
        ];

        return $report;
    }

    if (nibwp_builderius_pro_modules() === []) {
        $report['passed'] = false;
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_no_library',
            'message' => __('The Builderius module library could not be read, so no module name can be checked. The Builderius integration has to be enabled for this skill to validate anything.', 'nibwp'),
        ];

        return $report;
    }

    if (!nibwp_builderius_pro_registry_readable()) {
        // Said out loud rather than skipped silently: if the setting registry
        // cannot be read, the build is going out with its setting names unchecked
        // and the user should know that is what happened.
        $report['warnings'][] = [
            'rule'    => 'settings_registry_unreadable',
            'message' => __('Builderius\'s setting declarations could not be read on this install, so setting names were not checked — only module names were. Module settings are declared in the plugin\'s SettingBundle config; if Builderius moved it, this skill needs updating.', 'nibwp'),
        ];
    }

    nibwp_builderius_pro_walk_tree($tree, '', $report);

    $report['modules_used'] = array_values(array_unique($report['modules_used']));
    $report['tokens']       = array_values(array_unique($report['tokens']));
    $report['passed']       = $report['errors'] === [];

    return $report;
}

/**
 * Validate a flat config ({modules:{id:{…}}}) — what is already stored, or what
 * the integration's build-config returned.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_validate_config(array $config): array
{
    $report = [
        'passed'          => true,
        'errors'          => [],
        'warnings'        => [],
        'recommendations' => [],
        'module_count'    => 0,
        'modules_used'    => [],
        'tokens'          => [],
    ];

    if (!isset($config['modules']) || !is_array($config['modules'])) {
        $report['passed'] = false;
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_bad_config',
            'message' => __('A Builderius config is an object with a "modules" map. This one has none.', 'nibwp'),
        ];

        return $report;
    }

    $ids = array_keys($config['modules']);

    foreach ($config['modules'] as $key => $module) {
        $key = (string) $key;
        if (!is_array($module)) {
            $report['errors'][] = [
                'code'    => 'nibwp_builderius_pro_bad_config',
                'message' => sprintf(
                    /* translators: %s: module id */
                    __('Module "%s" is not an object.', 'nibwp'),
                    $key
                ),
            ];
            continue;
        }
        $report['module_count']++;
        $label = (string) ($module['label'] ?? $key);
        nibwp_builderius_pro_check_module(
            (string) ($module['name'] ?? ''),
            $label,
            $key,
            (array) ($module['settings'] ?? []),
            $report
        );

        $parent = (string) ($module['parent'] ?? '');
        if ($parent !== '' && !in_array($parent, $ids, true)) {
            $report['errors'][] = [
                'code'    => 'nibwp_builderius_pro_orphan_module',
                'message' => sprintf(
                    /* translators: 1: module id, 2: parent id */
                    __('Module "%1$s" names parent "%2$s", which is not in this config. A module whose parent is missing is not in the tree and never renders.', 'nibwp'),
                    $key,
                    $parent
                ),
                'module'  => (string) ($module['name'] ?? ''),
            ];
        }
    }

    $report['modules_used'] = array_values(array_unique($report['modules_used']));
    $report['tokens']       = array_values(array_unique($report['tokens']));
    $report['passed']       = $report['errors'] === [];

    return $report;
}

/**
 * Walk the nested authoring tree.
 *
 * @param array<int,mixed>    $nodes
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_walk_tree(array $nodes, string $path, array &$report): void
{
    $index = 0;
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            $report['errors'][] = [
                'code'    => 'nibwp_builderius_pro_bad_node',
                'message' => sprintf(
                    /* translators: %s: position in the tree */
                    __('The node at %s is not an object.', 'nibwp'),
                    $path === '' ? (string) $index : $path . ' > ' . $index
                ),
            ];
            $index++;
            continue;
        }

        $name  = (string) ($node['name'] ?? '');
        $label = (string) ($node['label'] ?? ($name !== '' ? $name : (string) $index));
        $here  = $path === '' ? $label : $path . ' > ' . $label;

        $report['module_count']++;
        nibwp_builderius_pro_check_module($name, $label, $here, (array) ($node['settings'] ?? []), $report);

        $children = (array) ($node['children'] ?? []);
        if ($children === []) {
            nibwp_builderius_pro_check_leaf($name, $here, (array) ($node['settings'] ?? []), $report);
        } else {
            nibwp_builderius_pro_walk_tree($children, $here, $report);
            nibwp_builderius_pro_check_repetition($children, $here, $report);
        }

        $index++;
    }
}

/**
 * The two checks that block a commit: is the module real, and does it accept
 * every setting given.
 *
 * @param array<mixed>        $settings
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_check_module(string $name, string $label, string $path, array $settings, array &$report): void
{
    if ($name === '') {
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_missing_module_name',
            'message' => sprintf(
                /* translators: %s: position in the tree */
                __('The module at %s has no name. Every module needs a type from the module library.', 'nibwp'),
                $path
            ),
            'path'    => $path,
        ];

        return;
    }

    $report['modules_used'][] = $name;

    if (!nibwp_builderius_pro_module_exists($name)) {
        $suggestions = nibwp_builderius_pro_suggest($name, array_keys(nibwp_builderius_pro_modules()));
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_unknown_module',
            'message' => sprintf(
                /* translators: 1: module name given, 2: position in the tree, 3: suggestion sentence */
                __('"%1$s" at %2$s is not a Builderius module on this install.%3$s Read nibwp/builderius-list-modules for the set this site has.', 'nibwp'),
                $name,
                $path,
                $suggestions === [] ? '' : ' ' . sprintf(
                    /* translators: %s: comma-separated module names */
                    __('Did you mean %s?', 'nibwp'),
                    implode(', ', $suggestions)
                )
            ),
            'module'  => $name,
            'path'    => $path,
        ];

        // No point checking settings against a module that does not exist — the
        // answer would be "nothing accepts this", which is true and useless.
        return;
    }

    $normalised = nibwp_builderius_pro_setting_names($settings);
    if ($normalised === null) {
        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_bad_settings',
            'message' => sprintf(
                /* translators: 1: module name, 2: position in the tree */
                __('Module %1$s at %2$s has malformed settings. Give either a map {tag:"div"} or a list [{"name":"tag","value":"div"}]; every entry in a list needs a name.', 'nibwp'),
                $name,
                $path
            ),
            'module'  => $name,
            'path'    => $path,
        ];

        return;
    }

    if (!nibwp_builderius_pro_registry_readable()) {
        return;
    }

    $accepted = nibwp_builderius_pro_module_settings($name);

    foreach ($normalised as $setting => $value) {
        nibwp_builderius_pro_collect_tokens($value, $report);

        if (in_array($setting, $accepted, true)) {
            nibwp_builderius_pro_check_setting_value($name, $setting, $value, $path, $report);
            continue;
        }

        $owners = nibwp_builderius_pro_setting_owners($setting);
        $detail = '';
        if ($owners !== []) {
            $detail = ' ' . sprintf(
                /* translators: 1: setting name, 2: comma-separated module names */
                __('%1$s belongs to %2$s.', 'nibwp'),
                $setting,
                implode(', ', array_slice($owners, 0, 6))
            );
        } elseif (nibwp_builderius_pro_is_template_setting($setting)) {
            $detail = ' ' . sprintf(
                /* translators: %s: setting name */
                __('%s is a template-level setting, not a module one — it belongs on the template or the global settings set, not inside a module.', 'nibwp'),
                $setting
            );
        } else {
            $suggestions = nibwp_builderius_pro_suggest($setting, $accepted);
            if ($suggestions !== []) {
                $detail = ' ' . sprintf(
                    /* translators: %s: comma-separated setting names */
                    __('Did you mean %s?', 'nibwp'),
                    implode(', ', $suggestions)
                );
            }
        }

        $report['errors'][] = [
            'code'    => 'nibwp_builderius_pro_unknown_setting',
            'message' => sprintf(
                /* translators: 1: setting name, 2: module name, 3: position in the tree, 4: the "belongs to" sentence, 5: comma-separated accepted names */
                __('Setting "%1$s" is not accepted by module %2$s (at %3$s).%4$s %2$s accepts: %5$s.', 'nibwp'),
                $setting,
                $name,
                $path,
                $detail,
                implode(', ', $accepted)
            ),
            'module'  => $name,
            'setting' => $setting,
            'path'    => $path,
        ];
    }
}

/**
 * Settings as name => value, or null when the shape is wrong.
 *
 * Accepts both forms the integration accepts, because an agent writes the map
 * and storage holds the list.
 *
 * @param array<mixed> $settings
 * @return array<string,mixed>|null
 */
function nibwp_builderius_pro_setting_names(array $settings): ?array
{
    if ($settings === []) {
        return [];
    }

    if (array_is_list($settings)) {
        $out = [];
        foreach ($settings as $setting) {
            if (!is_array($setting) || !isset($setting['name']) || !is_string($setting['name']) || $setting['name'] === '') {
                return null;
            }
            $out[$setting['name']] = $setting['value'] ?? '';
        }

        return $out;
    }

    $out = [];
    foreach ($settings as $name => $value) {
        if (!is_string($name) || $name === '') {
            return null;
        }
        $out[$name] = $value;
    }

    return $out;
}

/**
 * Per-setting value checks. Warnings, not errors: a value Builderius will accept
 * but a human would not have meant.
 *
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_check_setting_value(string $module, string $setting, mixed $value, string $path, array &$report): void
{
    if ($setting === 'css' && is_string($value) && $value !== '' && !str_contains($value, '%local%')) {
        $report['warnings'][] = [
            'rule'    => 'css_without_local',
            'message' => sprintf(
                /* translators: 1: module name, 2: position in the tree */
                __('The css on %1$s at %2$s does not use %%local%%. Builderius scopes per-module CSS through that placeholder; a real selector either leaks to every other module or matches nothing.', 'nibwp'),
                $module,
                $path
            ),
        ];
    }

    if ($setting === 'tag' && is_string($value) && $value !== '') {
        $offered = nibwp_builderius_pro_html_tags();
        if ($offered !== [] && !in_array($value, $offered, true) && !nibwp_builderius_pro_known_html_tag($value)) {
            $report['warnings'][] = [
                'rule'    => 'unlisted_tag',
                'message' => sprintf(
                    /* translators: 1: tag given, 2: position in the tree */
                    __('Tag "%1$s" at %2$s is neither offered by this install\'s tag picker nor an HTML element. Check the spelling.', 'nibwp'),
                    $value,
                    $path
                ),
            ];
        }
    }

    if ($setting === 'tagClass' && is_string($value)) {
        $report['warnings'][] = [
            'rule'    => 'tagclass_not_a_list',
            'message' => sprintf(
                /* translators: %s: position in the tree */
                __('tagClass at %s is a string. Builderius declares it as an array of class names; a single string works by accident and does not survive an edit in the builder.', 'nibwp'),
                $path
            ),
        ];
    }
}

/**
 * Collect the dynamic-data tokens a value carries, so the report can list what
 * the config depends on.
 *
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_collect_tokens(mixed $value, array &$report): void
{
    if (is_array($value)) {
        foreach ($value as $item) {
            nibwp_builderius_pro_collect_tokens($item, $report);
        }

        return;
    }
    if (!is_string($value) || $value === '') {
        return;
    }
    if (preg_match_all('/\[\[\[[^\]]+\]\]\]/', $value, $matches) === 0) {
        return;
    }
    foreach ($matches[0] as $token) {
        $report['tokens'][] = (string) $token;
    }
}

/**
 * A module with no children and nothing to show.
 *
 * This is the failure this skill exists to prevent: the tree is structurally
 * perfect and the section is blank because every box is empty.
 *
 * @param array<mixed>        $settings
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_check_leaf(string $name, string $path, array $settings, array &$report): void
{
    $named = nibwp_builderius_pro_setting_names($settings);
    if ($named === null) {
        return;
    }

    // One of these means the module has something to render. A container tag with
    // no children and no content is the empty case.
    $content_keys = ['content', 'contentHtml', 'contentSvg', 'contentTime', 'contentShortcode', 'mediaUrl', 'componentName'];
    foreach ($content_keys as $key) {
        $value = $named[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return;
        }
        if (is_array($value) && $value !== []) {
            return;
        }
    }

    // Modules that are meaningful with no content of their own.
    if (in_array($name, ['MenuBuilder', 'MenuToggle', 'SmartForm', 'Collection', 'SubCollection', 'Component', 'Template', 'RecursiveTemplate', 'CookieNotice', 'Cookieconsent'], true)) {
        return;
    }

    $tag = (string) ($named['tag'] ?? '');
    if (in_array($tag, ['img', 'br', 'hr', 'input', 'source', 'video', 'audio', 'script'], true)) {
        return;
    }

    $report['warnings'][] = [
        'rule'    => 'empty_module',
        'message' => sprintf(
            /* translators: 1: module name, 2: position in the tree */
            __('%1$s at %2$s has no children and nothing to render. Give it content, or drop it — an empty module is the usual reason a section looks like it did not build.', 'nibwp'),
            $name !== '' ? $name : __('the module', 'nibwp'),
            $path
        ),
    ];
}

/**
 * Repeated sibling structure, which is a loop somebody is about to maintain by
 * hand.
 *
 * @param array<int,mixed>    $children
 * @param array<string,mixed> $report
 */
function nibwp_builderius_pro_check_repetition(array $children, string $path, array &$report): void
{
    if (count($children) < 3) {
        return;
    }

    $shapes = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            return;
        }
        $shapes[] = nibwp_builderius_pro_shape($child);
    }

    $unique = array_unique($shapes);
    if (count($unique) !== 1) {
        return;
    }

    $report['recommendations'][] = [
        'rule'    => 'loop_candidate',
        'message' => sprintf(
            /* translators: 1: number of repeated children, 2: position in the tree */
            __('%1$d children of %2$s have an identical shape. If that content comes from posts, a Collection module bound to a query is one thing to edit instead of %1$d. If it is static copy nobody will change, leave it.', 'nibwp'),
            count($children),
            $path
        ),
    ];
}

/** A structural fingerprint for a node: module names and nesting, no values. */
function nibwp_builderius_pro_shape(array $node): string
{
    $out = (string) ($node['name'] ?? '?');
    $children = (array) ($node['children'] ?? []);
    if ($children === []) {
        return $out;
    }
    $parts = [];
    foreach ($children as $child) {
        $parts[] = is_array($child) ? nibwp_builderius_pro_shape($child) : '?';
    }

    return $out . '(' . implode(',', $parts) . ')';
}

/**
 * The tags this install's tag picker offers for the `tag` setting.
 *
 * Read from the same container config as the settings themselves — the
 * parameters `…html_element_tag.values` and `…heading_tag.values`, unioned
 * because the editor presents them as two groups of one control.
 *
 * This list is deliberately NOT treated as the set of legal tags. Builderius's
 * own bundled site_header.json sets tag "a", and "a" is not in the picker list —
 * the parameter says what the editor offers, not what Builderius will render. So
 * a tag outside it is only reported when it is not an HTML element either.
 *
 * @return array<int,string>
 */
function nibwp_builderius_pro_html_tags(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];

    foreach (nibwp_builderius_pro_settings_registry()['files'] as $file) {
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            continue;
        }
        foreach (['html_element_tag', 'heading_tag'] as $param) {
            if (preg_match('/' . $param . '\.values:\s*\[([^\]]*)\]/', $raw, $m) === 1) {
                foreach (nibwp_builderius_pro_parse_inline_list($m[1]) as $tag) {
                    $cache[] = $tag;
                }
            }
        }
    }

    $cache = array_values(array_unique($cache));

    return $cache;
}

/**
 * Is this an HTML element name at all?
 *
 * Hardcoded, because HTML's element set is not something the install publishes.
 * It is only used to decide whether an unoffered tag is a typo or a deliberate
 * choice, so the cost of it going stale is a missing warning on a tag HTML added
 * after this was written — never a blocked build.
 */
function nibwp_builderius_pro_known_html_tag(string $tag): bool
{
    static $tags = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'blockquote',
        'br', 'button', 'canvas', 'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist',
        'dd', 'del', 'details', 'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'main',
        'map', 'mark', 'menu', 'meter', 'nav', 'object', 'ol', 'optgroup', 'option', 'output', 'p',
        'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'script', 'search',
        'section', 'select', 'small', 'source', 'span', 'strong', 'sub', 'summary', 'sup', 'table',
        'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'track', 'u',
        'ul', 'var', 'video', 'wbr',
    ];

    return in_array(strtolower($tag), $tags, true);
}

/**
 * The first error as a WP_Error, so an ability can refuse with a typed code and
 * the whole report attached.
 *
 * @param array<string,mixed> $report
 * @return WP_Error|null
 */
function nibwp_builderius_pro_report_error(array $report)
{
    $errors = (array) ($report['errors'] ?? []);
    if ($errors === []) {
        return null;
    }

    $first = (array) $errors[0];
    $messages = [];
    foreach ($errors as $error) {
        $messages[] = (string) (((array) $error)['message'] ?? '');
    }

    return new WP_Error(
        (string) ($first['code'] ?? 'nibwp_builderius_pro_invalid'),
        implode(' ', $messages),
        ['report' => $report]
    );
}
