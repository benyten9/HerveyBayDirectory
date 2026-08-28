<?php

declare(strict_types=1);

/**
 * Builderius config model — module library, config builder, validator.
 *
 * Everything here is derived from the Builderius plugin SOURCE (v1.3.5-beta),
 * not guessed. A Builderius template's authored content is a "config": a flat
 * map of modules keyed by a unique id, linked into a tree via `parent` + `index`.
 * This is exactly the shape of Builderius's own bundled component configs
 * (site_header.json / site_footer.json):
 *
 *   { "modules": { "<uniqId>": {
 *       "id": "<uniqId>",
 *       "name": "HtmlElement",          // a module type from the library below
 *       "label": "header",
 *       "settings": [ {"name":"tag","value":"header"}, ... ],
 *       "parent": "<parentId or ''>",
 *       "index": 0
 *   } } }
 *
 * Settings are always a list of {name, value}. Dynamic data is referenced with
 * `[[[wp.*]]]` tokens; per-module CSS goes in a `css` setting using the
 * `%local%` selector.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Builderius module library — the authoritative element set (the Twig views
 * in Builderius ModuleBundle/Resources/views/modules/). name => human label.
 *
 * @return array<string,string>
 */
function nibwp_builderius_module_library(): array
{
    return [
        'HtmlElement'       => 'Universal HTML element (any tag) — the workhorse. Settings: tag, tagClass[], css, htmlAttribute[], textContent.',
        'HtmlCode'          => 'Raw HTML block.',
        'SvgCode'           => 'Inline SVG (contentSvg setting).',
        'Collection'        => 'Query loop over a data source (posts, terms, etc.).',
        'SubCollection'     => 'Nested loop inside a Collection.',
        'Component'         => 'Reference to a reusable Builderius component.',
        'Template'          => 'Include another Builderius template.',
        'RecursiveTemplate' => 'Self-including template (menus, trees).',
        'MenuBuilder'       => 'WordPress nav-menu renderer.',
        'MenuToggle'        => 'Responsive menu toggle / hamburger.',
        'SmartForm'         => 'Builderius form (submissions stored as builderius_form_subm).',
        'Accordion'         => 'Accordion / collapsible widget.',
        'Notification'      => 'Dismissible notification bar.',
        'CookieNotice'      => 'Cookie-consent notice.',
        'Cookieconsent'     => 'Cookie-consent manager.',
        'Time'              => 'Dynamic date/time output.',
    ];
}

/** True if $name is a real Builderius module type. */
function nibwp_builderius_is_module(string $name): bool
{
    return array_key_exists($name, nibwp_builderius_module_library());
}

/**
 * Mint a Builderius-style module id: 'u' + 8 hex (matches ids like 'ua39009f29').
 */
function nibwp_builderius_new_id(): string
{
    return 'u' . bin2hex(random_bytes(4));
}

/**
 * Normalise a settings value into Builderius's `[{name,value}, ...]` list.
 *
 * Accepts either the native list form (passed through) or an associative map
 * ({tag:'div', css:'...'}) for authoring convenience.
 *
 * @param array<mixed> $settings
 * @return array<int,array{name:string,value:mixed}>
 */
function nibwp_builderius_normalise_settings(array $settings): array
{
    // Already the native list of {name,value}? Pass through untouched.
    $is_list = array_is_list($settings);
    if ($is_list) {
        $out = [];
        foreach ($settings as $s) {
            if (is_array($s) && isset($s['name'])) {
                $out[] = ['name' => (string) $s['name'], 'value' => $s['value'] ?? ''];
            }
        }
        return $out;
    }
    // Associative convenience map → list.
    $out = [];
    foreach ($settings as $name => $value) {
        $out[] = ['name' => (string) $name, 'value' => $value];
    }
    return $out;
}

/**
 * Build a Builderius config (flat module map) from a nested authoring tree.
 *
 * Input tree node: {
 *   name: 'HtmlElement',                 // required, module type
 *   label?: 'header',
 *   settings?: {tag:'div'} | [{name,value}],
 *   children?: [ ...nodes ]
 * }
 *
 * @param array<int,array<string,mixed>> $tree Root-level nodes.
 * @return array{modules: array<string,array<string,mixed>>}
 */
function nibwp_builderius_build_config(array $tree): array
{
    $modules = [];

    $walk = function (array $nodes, string $parent) use (&$walk, &$modules): void {
        $index = 0;
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['name'])) {
                continue;
            }
            $id = isset($node['id']) && is_string($node['id']) && $node['id'] !== ''
                ? $node['id']
                : nibwp_builderius_new_id();

            $module = [
                'id'       => $id,
                'name'     => (string) $node['name'],
                'label'    => (string) ($node['label'] ?? $node['name']),
                'settings' => nibwp_builderius_normalise_settings((array) ($node['settings'] ?? [])),
                'parent'   => $parent,
            ];
            // Root modules carry no index (matches Builderius's own configs);
            // children are ordered by index among their siblings.
            if ($parent !== '') {
                $module['index'] = $index;
            }
            $modules[$id] = $module;
            $index++;

            if (!empty($node['children']) && is_array($node['children'])) {
                $walk($node['children'], $id);
            }
        }
    };

    $walk($tree, '');

    return ['modules' => $modules];
}

/**
 * Validate a Builderius config against the real schema. Structural only — we
 * check the invariants Builderius relies on (valid module types, well-formed
 * settings, resolvable parent refs, unique ids), not every possible setting
 * name (Builderius accepts a large, evolving setting set per module).
 *
 * @param array<string,mixed> $config
 * @return array{passed:bool, errors:array<int,string>, module_count:int}
 */
function nibwp_builderius_validate_config(array $config): array
{
    $errors = [];

    if (!isset($config['modules']) || !is_array($config['modules'])) {
        return ['passed' => false, 'errors' => ['config must be an object with a "modules" map.'], 'module_count' => 0];
    }
    $modules = $config['modules'];
    $ids = array_keys($modules);

    foreach ($modules as $key => $m) {
        if (!is_array($m)) {
            $errors[] = "module '{$key}' is not an object.";
            continue;
        }
        $id = (string) ($m['id'] ?? '');
        if ($id === '') {
            $errors[] = "module '{$key}' is missing its id.";
        } elseif ($id !== (string) $key) {
            $errors[] = "module '{$key}' id '{$id}' does not match its map key.";
        }
        $name = (string) ($m['name'] ?? '');
        if (!nibwp_builderius_is_module($name)) {
            $errors[] = "module '{$key}' has unknown type '{$name}'. Use nibwp/builderius-list-modules for the valid set.";
        }
        if (isset($m['settings'])) {
            if (!is_array($m['settings']) || !array_is_list($m['settings'])) {
                $errors[] = "module '{$key}' settings must be a list of {name,value}.";
            } else {
                foreach ($m['settings'] as $s) {
                    if (!is_array($s) || !isset($s['name'])) {
                        $errors[] = "module '{$key}' has a malformed setting (needs a name).";
                        break;
                    }
                }
            }
        }
        $parent = (string) ($m['parent'] ?? '');
        if ($parent !== '' && !in_array($parent, $ids, true)) {
            $errors[] = "module '{$key}' references missing parent '{$parent}'.";
        }
    }

    if (count($ids) !== count(array_unique($ids))) {
        $errors[] = 'duplicate module ids in config.';
    }

    return [
        'passed'       => $errors === [],
        'errors'       => $errors,
        'module_count' => count($modules),
    ];
}
