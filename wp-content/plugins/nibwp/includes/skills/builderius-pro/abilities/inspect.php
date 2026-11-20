<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/versions.php';
require_once __DIR__ . '/../lib/commit.php';

/**
 * Builderius Pro — read before you write.
 *
 * Two things an agent cannot guess and should not: the setting vocabulary of this
 * install, and the release state of the thing it is about to change. Both are one
 * call each, and both are read-only.
 */

wp_register_ability('nibwp/builderius-pro-modules', [
    'label'       => __('Builderius Pro — Modules and their settings', 'nibwp'),
    'description' => 'The module library of THIS install together with the setting names each module actually accepts, read from Builderius\'s own setting declarations rather than from documentation (read-only).',
    'category'    => 'builderius-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => ['type' => 'string', 'enum' => ['list', 'get'], 'default' => 'list'],
            'module' => ['type' => 'string', 'description' => 'get: the module to describe, e.g. HtmlElement.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_builderius_pro_modules_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read this before authoring a config. A setting name from another builder\'s vocabulary is dropped silently, and the symptom is a module that renders with nothing in it.',
                'Settings are declared per module: some apply to every module, some to a named few, and a couple are excluded from specific ones. This answers for the install you are on.',
                'If the response says the registry could not be read, say so — the build will go out with its setting names unchecked.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_builderius_pro_modules_ability(array $input)
{
    $gate = nibwp_builderius_pro_gate(false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $modules = nibwp_builderius_pro_modules();
    if ($modules === []) {
        return new WP_Error(
            'nibwp_builderius_pro_no_library',
            __('The Builderius module library could not be read. The Builderius integration has to be enabled — this skill reads the library from it rather than keeping its own copy.', 'nibwp')
        );
    }

    $registry = nibwp_builderius_pro_settings_registry();
    $readable = nibwp_builderius_pro_registry_readable();

    if ((string) ($input['action'] ?? 'list') === 'get') {
        $module = (string) ($input['module'] ?? '');
        if (!nibwp_builderius_pro_module_exists($module)) {
            $suggestions = nibwp_builderius_pro_suggest($module, array_keys($modules));

            return new WP_Error(
                'nibwp_builderius_pro_unknown_module',
                sprintf(
                    /* translators: 1: module name given, 2: suggestion sentence */
                    __('"%1$s" is not a Builderius module on this install.%2$s', 'nibwp'),
                    $module,
                    $suggestions === [] ? '' : ' ' . sprintf(
                        /* translators: %s: comma-separated module names */
                        __('Did you mean %s?', 'nibwp'),
                        implode(', ', $suggestions)
                    )
                )
            );
        }

        return [
            'module'            => $module,
            'description'       => (string) $modules[$module],
            'accepts'           => nibwp_builderius_pro_module_settings($module),
            'registry_readable' => $readable,
            'read_from'         => $registry['files'],
        ];
    }

    $out = [];
    foreach ($modules as $name => $description) {
        $out[$name] = [
            'description' => (string) $description,
            'accepts'     => nibwp_builderius_pro_module_settings($name),
        ];
    }

    return [
        'modules'            => $out,
        'every_module'       => $registry['all_modules'],
        'template_settings'  => $registry['template_settings'],
        'tag_picker_offers'  => nibwp_builderius_pro_html_tags(),
        'registry_readable'  => $readable,
        'read_from'          => $registry['files'],
        'config_format'      => 'A stored config is {"modules":{"<id>":{"id","name","label","settings":[{"name","value"}],"parent","index"}}}. Author it as a nested tree and let the integration flatten it.',
        'note'               => $readable
            ? __('Setting names are checked against this. A name that is not here is refused before anything is committed.', 'nibwp')
            : __('Builderius\'s setting declarations could not be read here, so setting names cannot be checked. Say so rather than implying they were.', 'nibwp'),
    ];
}

wp_register_ability('nibwp/builderius-pro-inspect', [
    'label'       => __('Builderius Pro — Inspect', 'nibwp'),
    'description' => 'Read an existing Builderius template or component: its module tree, any unknown or empty modules, the dynamic-data tokens it depends on, and where it sits in the version graph — including whether it is carrying work no release has published (read-only).',
    'category'    => 'builderius-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action'       => ['type' => 'string', 'enum' => ['template', 'component', 'releases'], 'default' => 'template'],
            'template_id'  => ['type' => 'integer'],
            'component_id' => ['type' => 'integer'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_builderius_pro_inspect_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'action=releases before any build onto an existing template. It says which release is published and which commit that release froze, which is what decides whether work may land on the branch.',
                'A template that reads as "unreleased" looks right in the builder and wrong to a visitor: the branch head is not the commit the release serves. That is usually the answer to "I changed it and nothing happened".',
                'Empty modules are reported because they are the common end state of a config built from guessed setting names — the structure is there and the content is not.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_builderius_pro_inspect_ability(array $input)
{
    $gate = nibwp_builderius_pro_gate(false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $action = (string) ($input['action'] ?? 'template');

    if ($action === 'releases') {
        $template_id = (int) ($input['template_id'] ?? 0);

        return [
            'release_state' => nibwp_builderius_pro_release_state($template_id),
            'how_to_read'   => __('A release is a frozen copy of a commit and is what a visitor sees. Only one is published at a time. Committing adds a version and changes nothing a visitor sees until somebody publishes a release in Builderius.', 'nibwp'),
        ];
    }

    if ($action === 'component') {
        $component_id = (int) ($input['component_id'] ?? 0);
        $fetched = nibwp_builderius_pro_delegate('builderius-get-component', ['component_id' => $component_id]);
        if ($fetched instanceof WP_Error) {
            return $fetched;
        }
        $config = (array) ($fetched['config'] ?? []);

        return nibwp_builderius_pro_inspection($config, [
            'kind'  => 'component',
            'id'    => $component_id,
            'title' => (string) ($fetched['title'] ?? ''),
        ]);
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    if ($template_id <= 0) {
        return new WP_Error(
            'nibwp_builderius_pro_no_target',
            __('Give a template_id, or use action=releases for the site-wide release state.', 'nibwp')
        );
    }

    $fetched = nibwp_builderius_pro_delegate('builderius-get-template', ['template_id' => $template_id]);
    if ($fetched instanceof WP_Error) {
        return $fetched;
    }

    $inspection = nibwp_builderius_pro_inspection((array) ($fetched['config'] ?? []), [
        'kind'  => 'template',
        'id'    => $template_id,
        'title' => (string) ($fetched['title'] ?? ''),
    ]);
    $inspection['branch_id'] = $fetched['branch_id'] ?? null;
    $inspection['commit'] = $fetched['commit'] ?? null;
    $inspection['release_state'] = nibwp_builderius_pro_release_state($template_id);
    $inspection['versions'] = nibwp_builderius_pro_delegate('builderius-list-versions', ['template_id' => $template_id]);

    return $inspection;
}

/**
 * The common report for a config that already exists.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $about
 * @return array<string,mixed>
 */
function nibwp_builderius_pro_inspection(array $config, array $about): array
{
    $report = nibwp_builderius_pro_validate_config($config);

    $unknown = [];
    foreach ($report['errors'] as $error) {
        if ((string) (((array) $error)['code'] ?? '') === 'nibwp_builderius_pro_unknown_module') {
            $unknown[] = (string) (((array) $error)['module'] ?? '');
        }
    }

    $css_modules = 0;
    foreach ((array) ($config['modules'] ?? []) as $module) {
        if (!is_array($module)) {
            continue;
        }
        foreach ((array) ($module['settings'] ?? []) as $setting) {
            if (is_array($setting) && (string) ($setting['name'] ?? '') === 'css') {
                $css_modules++;
            }
        }
    }

    return array_merge($about, [
        'ok'              => $report['passed'],
        'module_count'    => $report['module_count'],
        'modules_used'    => $report['modules_used'],
        'unknown_modules' => array_values(array_unique(array_filter($unknown))),
        'dynamic_tokens'  => $report['tokens'],
        'modules_with_css' => $css_modules,
        'outline'         => nibwp_builderius_pro_config_outline($config),
        'errors'          => $report['errors'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
    ]);
}
