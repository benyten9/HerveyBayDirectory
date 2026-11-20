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
 * Builderius Pro — change named modules, keep the rest.
 *
 * Rebuilding a template to change a heading throws away everything somebody
 * adjusted by hand since it was built, and in a versioned builder it also buries
 * that work under a commit that looks like a rewrite. This reads the current
 * config, changes the modules it was asked to change, and commits the result as a
 * new version — so the previous one is still there to go back to.
 */

wp_register_ability('nibwp/builderius-pro-refine', [
    'label'       => __('Builderius Pro — Refine', 'nibwp'),
    'description' => 'Change named modules in an existing Builderius template without rebuilding the rest, validated and committed as a new version through the Builderius integration.',
    'category'    => 'builderius-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer'],
            'edits' => [
                'type'        => 'array',
                'description' => 'Each edit targets one module by its id or its label: {module_id?, label?, settings?: {name:value}, remove?: true}. settings are merged into the module\'s existing settings; a value of null removes that one setting.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'module_id' => ['type' => 'string'],
                        'label'     => ['type' => 'string'],
                        'settings'  => ['type' => 'object'],
                        'remove'    => ['type' => 'boolean'],
                    ],
                ],
            ],
            'version_mode' => ['type' => 'string', 'enum' => ['new_version', 'new_branch', 'overwrite'], 'default' => 'new_version'],
            'branch_name'  => ['type' => 'string'],
            'message'      => ['type' => 'string'],
            'dry_run'      => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['template_id', 'edits'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback'    => 'nibwp_builderius_pro_refine_ability',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Read the template with nibwp/builderius-pro-inspect first — the outline gives you the module ids and labels to target.',
                'An edit that matches no module is refused with the labels that do exist, rather than applied to nothing and reported as done.',
                'Removing a module removes its children with it. The response says how many went.',
                'dry_run true first. The response shows the modules that would change and the version plan.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_builderius_pro_refine_ability(array $input)
{
    $gate = nibwp_builderius_pro_gate(true);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    $edits = array_values((array) ($input['edits'] ?? []));
    $version_mode = (string) ($input['version_mode'] ?? 'new_version');
    $dry_run = !array_key_exists('dry_run', $input) || (bool) $input['dry_run'];

    if ($template_id <= 0) {
        return new WP_Error('nibwp_builderius_pro_no_target', __('Give the template_id to refine.', 'nibwp'));
    }
    if ($edits === []) {
        return new WP_Error('nibwp_builderius_pro_no_edits', __('Give at least one edit. Refining nothing is a no-op dressed up as a commit.', 'nibwp'));
    }

    $guard = nibwp_builderius_pro_version_guard($template_id, $version_mode);
    if ($guard instanceof WP_Error) {
        return $guard;
    }

    $fetched = nibwp_builderius_pro_delegate('builderius-get-template', ['template_id' => $template_id]);
    if ($fetched instanceof WP_Error) {
        return $fetched;
    }
    $config = (array) ($fetched['config'] ?? []);
    if (!isset($config['modules']) || !is_array($config['modules']) || $config['modules'] === []) {
        return new WP_Error(
            'nibwp_builderius_pro_empty_template',
            __('That template has no modules to refine yet. Build it first with nibwp/builderius-pro-build.', 'nibwp')
        );
    }

    $applied = nibwp_builderius_pro_apply_edits($config, $edits);
    if ($applied instanceof WP_Error) {
        return $applied;
    }
    $config = $applied['config'];

    $report = nibwp_builderius_pro_validate_config($config);
    $refusal = nibwp_builderius_pro_report_error($report);
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    $plan = nibwp_builderius_pro_version_plan($template_id, $version_mode);

    $result = nibwp_builderius_pro_commit('template', $config, [
        'template_id'  => $template_id,
        'title'        => (string) ($fetched['title'] ?? ''),
        'message'      => (string) ($input['message'] ?? '') !== ''
            ? (string) $input['message']
            : sprintf(
                /* translators: %d: number of modules changed */
                __('Refined %d module(s) via NIBWP', 'nibwp'),
                count($applied['changed'])
            ),
        'version_mode' => $version_mode,
        'branch_name'  => (string) ($input['branch_name'] ?? ''),
        'dry_run'      => $dry_run,
    ]);
    if ($result instanceof WP_Error) {
        return $result;
    }

    return [
        'ok'              => true,
        'written'         => !$dry_run,
        'dry_run'         => $dry_run,
        'template_id'     => $template_id,
        'changed'         => $applied['changed'],
        'removed'         => $applied['removed'],
        'module_count'    => $report['module_count'],
        'version_plan'    => $plan,
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'integration'     => $result,
        'delegated_to'    => nibwp_builderius_pro_delegations(),
        'next_step'       => $dry_run
            ? __('Nothing was written. Confirm the changed modules and the version plan, then call again with dry_run false.', 'nibwp')
            : __('Committed as a new version. The previous version is still in the template\'s history.', 'nibwp'),
    ];
}

/**
 * Apply the edits to a stored config.
 *
 * @param array<string,mixed>  $config
 * @param array<int,mixed>     $edits
 * @return array{config:array<string,mixed>,changed:array<int,string>,removed:array<int,string>}|WP_Error
 */
function nibwp_builderius_pro_apply_edits(array $config, array $edits)
{
    $modules = (array) $config['modules'];
    $changed = [];
    $removed = [];

    foreach ($edits as $edit) {
        if (!is_array($edit)) {
            return new WP_Error('nibwp_builderius_pro_bad_edit', __('Each edit must be an object: {module_id|label, settings?, remove?}.', 'nibwp'));
        }

        $matches = nibwp_builderius_pro_match_modules($modules, $edit);
        if ($matches === []) {
            $labels = [];
            foreach ($modules as $id => $module) {
                if (is_array($module)) {
                    $labels[] = (string) ($module['label'] ?? $id);
                }
            }

            return new WP_Error(
                'nibwp_builderius_pro_no_such_module',
                sprintf(
                    /* translators: 1: the target asked for, 2: comma-separated labels that do exist */
                    __('No module matches "%1$s" in this template. The modules it has are: %2$s. An edit that matches nothing is refused rather than reported as applied.', 'nibwp'),
                    (string) ($edit['module_id'] ?? $edit['label'] ?? '(nothing given)'),
                    implode(', ', array_slice(array_values(array_unique($labels)), 0, 40))
                )
            );
        }

        foreach ($matches as $id) {
            if (!empty($edit['remove'])) {
                foreach (nibwp_builderius_pro_descendants($modules, $id) as $descendant) {
                    unset($modules[$descendant]);
                    $removed[] = $descendant;
                }
                unset($modules[$id]);
                $removed[] = $id;
                continue;
            }

            $settings = (array) ($edit['settings'] ?? []);
            if ($settings === []) {
                return new WP_Error(
                    'nibwp_builderius_pro_bad_edit',
                    sprintf(
                        /* translators: %s: module id */
                        __('The edit for module %s changes nothing. Give settings, or remove: true.', 'nibwp'),
                        $id
                    )
                );
            }

            $modules[$id]['settings'] = nibwp_builderius_pro_merge_settings(
                (array) ($modules[$id]['settings'] ?? []),
                $settings
            );
            $changed[] = $id;
        }
    }

    $config['modules'] = $modules;

    return [
        'config'  => $config,
        'changed' => array_values(array_unique($changed)),
        'removed' => array_values(array_unique($removed)),
    ];
}

/**
 * Which modules an edit targets.
 *
 * @param array<string,mixed> $modules
 * @param array<string,mixed> $edit
 * @return array<int,string>
 */
function nibwp_builderius_pro_match_modules(array $modules, array $edit): array
{
    $id = (string) ($edit['module_id'] ?? '');
    if ($id !== '') {
        return isset($modules[$id]) ? [$id] : [];
    }

    $label = (string) ($edit['label'] ?? '');
    if ($label === '') {
        return [];
    }

    $out = [];
    foreach ($modules as $key => $module) {
        if (is_array($module) && (string) ($module['label'] ?? '') === $label) {
            $out[] = (string) $key;
        }
    }

    return $out;
}

/**
 * Every module under this one. Removing a parent and leaving its children behind
 * leaves modules whose parent does not exist, which never render and are hard to
 * find in the builder.
 *
 * @param array<string,mixed> $modules
 * @return array<int,string>
 */
function nibwp_builderius_pro_descendants(array $modules, string $parent_id): array
{
    $out = [];
    foreach ($modules as $key => $module) {
        if (!is_array($module) || (string) ($module['parent'] ?? '') !== $parent_id) {
            continue;
        }
        $out[] = (string) $key;
        foreach (nibwp_builderius_pro_descendants($modules, (string) $key) as $deeper) {
            $out[] = $deeper;
        }
    }

    return $out;
}

/**
 * Merge an authoring map of settings into the stored `[{name,value}]` list.
 *
 * A null value removes the setting, which is the only way to take one off a
 * module — Builderius keeps whatever is in the list.
 *
 * @param array<int,mixed>    $existing
 * @param array<string,mixed> $changes
 * @return array<int,array<string,mixed>>
 */
function nibwp_builderius_pro_merge_settings(array $existing, array $changes): array
{
    $out = [];
    $seen = [];

    foreach ($existing as $setting) {
        if (!is_array($setting) || !isset($setting['name'])) {
            continue;
        }
        $name = (string) $setting['name'];
        if (array_key_exists($name, $changes)) {
            $seen[$name] = true;
            if ($changes[$name] === null) {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $changes[$name]];
            continue;
        }
        $out[] = ['name' => $name, 'value' => $setting['value'] ?? ''];
    }

    foreach ($changes as $name => $value) {
        if (isset($seen[(string) $name]) || $value === null) {
            continue;
        }
        $out[] = ['name' => (string) $name, 'value' => $value];
    }

    return $out;
}
