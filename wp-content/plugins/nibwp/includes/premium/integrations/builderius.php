<?php

declare(strict_types=1);

/**
 * NIBWP × Builderius integration.
 *
 * Exposes Builderius (wordpress.org/plugins/builderius) — a GraphQL-driven,
 * git-versioned visual builder — to any MCP client through NIBWP abilities:
 * read the template/version graph, components, releases, fragments, global
 * settings and form submissions; and author templates/components/fragments/
 * global-settings by committing configs through Builderius's own storage model.
 *
 * Data model + write path are documented in lib/model.php and lib/writer.php,
 * all verified from the Builderius source — no meta-guessing. Loaded only when
 * the integration is enabled + unlocked (see premium/bootstrap.php).
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/builderius/lib/config.php';
require_once __DIR__ . '/builderius/lib/model.php';
require_once __DIR__ . '/builderius/lib/writer.php';

/**
 * Thin registrar to keep the ability declarations readable.
 *
 * @param array<string,mixed>  $schema
 * @param callable-string      $callback
 */
function nibwp_builderius_register(
    string $slug,
    string $label,
    string $description,
    array $schema,
    string $callback,
    bool $destructive = false,
    string $instructions = ''
): void {
    wp_register_ability('nibwp/' . $slug, [
        'label'               => $label,
        'description'         => $description,
        'category'            => 'builderius',
        'input_schema'        => $schema,
        'execute_callback'    => $callback,
        'permission_callback' => 'nibwp_permission_callback',
        'meta'                => [
            'show_in_rest' => true,
            'mcp'          => ['public' => true],
            'annotations'  => [
                'instructions' => $instructions !== '' ? $instructions : $description,
                'readonly'     => !$destructive && (
                    str_starts_with($slug, 'builderius-list')
                    || str_starts_with($slug, 'builderius-get')
                    || $slug === 'builderius-export-config'
                    || $slug === 'builderius-build-config'
                ),
                'destructive'  => $destructive,
                'idempotent'   => false,
            ],
        ],
    ]);
}

/** Shared object schema for a nested authoring tree node. */
function nibwp_builderius_tree_schema(): array
{
    return [
        'type'        => 'array',
        'description' => 'Nested tree of modules. Each node: {name, label?, settings?, children?}. '
            . 'name = a Builderius module type (see builderius-list-modules). settings = a map '
            . '{tag:"div", css:"%local%{...}"} or a list [{name,value}]. Dynamic data uses [[[wp.*]]] tokens.',
        'items'       => [
            'type'       => 'object',
            'properties' => [
                'name'     => ['type' => 'string'],
                'label'    => ['type' => 'string'],
                'settings' => ['type' => ['object', 'array']],
                'children' => ['type' => 'array'],
            ],
            'required'   => ['name'],
        ],
    ];
}

// ===========================================================================
// READ abilities
// ===========================================================================

nibwp_builderius_register(
    'builderius-list-templates',
    __('Builderius – List Templates', domain: 'nibwp'),
    __('List all Builderius templates with type/subtype, branch count, active commit and module count.', domain: 'nibwp'),
    ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'nibwp_builderius_ability_list_templates'
);
function nibwp_builderius_ability_list_templates(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return ['templates' => nibwp_builderius_list_templates()];
}

nibwp_builderius_register(
    'builderius-get-template',
    __('Builderius – Get Template', domain: 'nibwp'),
    __('Get a template\'s current config (module tree) plus its resolved branch/commit.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => ['template_id' => ['type' => 'integer', 'description' => 'The builderius_template post ID.']],
        'required'   => ['template_id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_get_template'
);
function nibwp_builderius_ability_get_template(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $id = (int) ($input['template_id'] ?? 0);
    if (get_post_type($id) !== NIBWP_BLDR_CPT_TEMPLATE) {
        return new WP_Error('not_found', __('No Builderius template with that id.', domain: 'nibwp'));
    }
    $resolved = nibwp_builderius_resolve_template($id);
    return [
        'template_id' => $id,
        'title'       => get_the_title($id),
        'branch_id'   => $resolved['branch'] instanceof WP_Post ? (int) $resolved['branch']->ID : null,
        'commit'      => $resolved['commit'] instanceof WP_Post ? $resolved['commit']->post_name : null,
        'config'      => $resolved['config'],
    ];
}

nibwp_builderius_register(
    'builderius-list-versions',
    __('Builderius – List Versions', domain: 'nibwp'),
    __('The git-like version graph for a template: branches and their commits.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => ['template_id' => ['type' => 'integer']],
        'required'   => ['template_id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_list_versions'
);
function nibwp_builderius_ability_list_versions(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return nibwp_builderius_list_versions((int) ($input['template_id'] ?? 0));
}

/** Register the simple CPT listers in one loop. */
foreach ([
    'builderius-list-components'        => [NIBWP_BLDR_CPT_COMPONENT, __('Builderius – List Components', domain: 'nibwp'), __('List reusable Builderius components.', domain: 'nibwp')],
    'builderius-list-releases'          => [NIBWP_BLDR_CPT_RELEASE, __('Builderius – List Releases', domain: 'nibwp'), __('List Builderius releases (published commits).', domain: 'nibwp')],
    'builderius-list-fragments'         => [NIBWP_BLDR_CPT_FRAGMENT, __('Builderius – List Fragments', domain: 'nibwp'), __('List saved Builderius fragments.', domain: 'nibwp')],
    'builderius-list-global-settings'   => [NIBWP_BLDR_CPT_SETTINGS, __('Builderius – List Global Settings', domain: 'nibwp'), __('List Builderius global settings sets (CSS vars / design tokens).', domain: 'nibwp')],
    'builderius-list-form-submissions'  => [NIBWP_BLDR_CPT_FORM_SUBM, __('Builderius – List Form Submissions', domain: 'nibwp'), __('List Builderius SmartForm submissions.', domain: 'nibwp')],
    'builderius-list-starters'          => [NIBWP_BLDR_CPT_STARTER, __('Builderius – List Starters', domain: 'nibwp'), __('List Builderius starter sites.', domain: 'nibwp')],
] as $slug => $meta) {
    [$pt, $label, $desc] = $meta;
    nibwp_builderius_register(
        $slug,
        $label,
        $desc,
        ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 200]], 'additionalProperties' => false],
        'nibwp_builderius_ability_list_cpt_' . str_replace('-', '_', substr($slug, strlen('builderius-list-')))
    );
}

// Concrete callbacks for the generic listers (named so schema-reflection is stable).
function nibwp_builderius_list_cpt_response(string $pt, array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return ['items' => nibwp_builderius_list_cpt($pt, (int) ($input['limit'] ?? 200))];
}
function nibwp_builderius_ability_list_cpt_components(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_COMPONENT, $input); }
function nibwp_builderius_ability_list_cpt_releases(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_RELEASE, $input); }
function nibwp_builderius_ability_list_cpt_fragments(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_FRAGMENT, $input); }
function nibwp_builderius_ability_list_cpt_global_settings(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_SETTINGS, $input); }
function nibwp_builderius_ability_list_cpt_form_submissions(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_FORM_SUBM, $input); }
function nibwp_builderius_ability_list_cpt_starters(array $input): array|WP_Error { return nibwp_builderius_list_cpt_response(NIBWP_BLDR_CPT_STARTER, $input); }

nibwp_builderius_register(
    'builderius-get-component',
    __('Builderius – Get Component', domain: 'nibwp'),
    __('Get a reusable component\'s config.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => ['component_id' => ['type' => 'integer']],
        'required'   => ['component_id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_get_component'
);
function nibwp_builderius_ability_get_component(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $id = (int) ($input['component_id'] ?? 0);
    if (get_post_type($id) !== NIBWP_BLDR_CPT_COMPONENT) {
        return new WP_Error('not_found', __('No Builderius component with that id.', domain: 'nibwp'));
    }
    $post = get_post($id);
    $config = $post ? nibwp_builderius_commit_config($post) : [];
    return ['component_id' => $id, 'title' => get_the_title($id), 'config' => $config];
}

nibwp_builderius_register(
    'builderius-list-modules',
    __('Builderius – List Modules', domain: 'nibwp'),
    __('The Builderius module library — the element types you can author with, and the config format.', domain: 'nibwp'),
    ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'nibwp_builderius_ability_list_modules'
);
function nibwp_builderius_ability_list_modules(array $input): array
{
    return [
        'modules'       => nibwp_builderius_module_library(),
        'config_format' => 'A config is {"modules":{"<id>":{"id","name","label","settings":[{"name","value"}],"parent","index"}}}. '
            . 'Author with a nested tree via builderius-build-config, or pass a tree directly to builderius-create-template.',
        'dynamic_data'  => 'Reference WordPress data with [[[wp.*]]] tokens, e.g. [[[wp.site_url]]] or [[[wp.post_title]]].',
        'css'           => 'Per-module CSS goes in a "css" setting using the %local% selector, e.g. "%local% { color: red }".',
    ];
}

// ===========================================================================
// AUTHORING helper (no write)
// ===========================================================================

nibwp_builderius_register(
    'builderius-build-config',
    __('Builderius – Build Config', domain: 'nibwp'),
    __('Turn a nested module tree into a valid Builderius config (flat module map) and validate it — no write.', domain: 'nibwp'),
    ['type' => 'object', 'properties' => ['tree' => nibwp_builderius_tree_schema()], 'required' => ['tree'], 'additionalProperties' => false],
    'nibwp_builderius_ability_build_config'
);
function nibwp_builderius_ability_build_config(array $input): array|WP_Error
{
    $tree = (array) ($input['tree'] ?? []);
    if ($tree === []) {
        return new WP_Error('empty_tree', __('Provide a non-empty module tree.', domain: 'nibwp'));
    }
    $config = nibwp_builderius_build_config($tree);
    return ['config' => $config, 'validation' => nibwp_builderius_validate_config($config)];
}

// ===========================================================================
// WRITE abilities
// ===========================================================================

nibwp_builderius_register(
    'builderius-create-template',
    __('Builderius – Create Template', domain: 'nibwp'),
    __('Create a Builderius template (optionally with authored content). Fires Builderius\'s initial-commit listener.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'title'   => ['type' => 'string', 'minLength' => 1],
            'type'    => ['type' => 'string', 'description' => 'Template type taxonomy slug, e.g. "single", "archive", "page", "hook".', 'default' => ''],
            'subtype' => ['type' => 'string', 'default' => ''],
            'tree'    => nibwp_builderius_tree_schema(),
            'config'  => ['type' => 'object', 'description' => 'A pre-built config ({modules:{…}}). Use instead of tree if you already have one.'],
            'dry_run' => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['title'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_create_template',
    false,
    "Creates a builderius_template. Provide content as `tree` (nested, converted for you) or `config` (a ready {modules:{…}} map). Set dry_run:true to validate without writing. Type/subtype are Builderius template taxonomy slugs."
);
function nibwp_builderius_ability_create_template(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $config = nibwp_builderius_config_from_input($input);
    if ($config instanceof WP_Error) {
        return $config;
    }
    return nibwp_builderius_create_template(
        (string) ($input['title'] ?? ''),
        (string) ($input['type'] ?? ''),
        (string) ($input['subtype'] ?? ''),
        $config,
        ['dry_run' => (bool) ($input['dry_run'] ?? false)]
    );
}

nibwp_builderius_register(
    'builderius-update-template',
    __('Builderius – Update Template', domain: 'nibwp'),
    __('Author/replace a template\'s content by committing a new config snapshot on its branch.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer'],
            'tree'        => nibwp_builderius_tree_schema(),
            'config'      => ['type' => 'object'],
            'message'     => ['type' => 'string', 'default' => ''],
            'dry_run'     => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['template_id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_update_template',
    false,
    "Commits a new config to the template's branch (git-like). Provide `tree` or `config`. Reads the result back to confirm."
);
function nibwp_builderius_ability_update_template(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $config = nibwp_builderius_config_from_input($input);
    if ($config instanceof WP_Error) {
        return $config;
    }
    if ($config === []) {
        return new WP_Error('empty_config', __('Provide tree or config to commit.', domain: 'nibwp'));
    }
    return nibwp_builderius_update_template(
        (int) ($input['template_id'] ?? 0),
        $config,
        (string) ($input['message'] ?? ''),
        ['dry_run' => (bool) ($input['dry_run'] ?? false)]
    );
}

nibwp_builderius_register(
    'builderius-create-component',
    __('Builderius – Create Component', domain: 'nibwp'),
    __('Save a reusable Builderius component from a module tree/config.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'title'   => ['type' => 'string', 'minLength' => 1],
            'tree'    => nibwp_builderius_tree_schema(),
            'config'  => ['type' => 'object'],
            'dry_run' => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['title'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_create_component'
);
function nibwp_builderius_ability_create_component(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $config = nibwp_builderius_config_from_input($input);
    if ($config instanceof WP_Error) {
        return $config;
    }
    return nibwp_builderius_create_record(NIBWP_BLDR_CPT_COMPONENT, (string) ($input['title'] ?? ''), $config, ['dry_run' => (bool) ($input['dry_run'] ?? false)]);
}

nibwp_builderius_register(
    'builderius-create-fragment',
    __('Builderius – Create Fragment', domain: 'nibwp'),
    __('Save a reusable Builderius fragment from a module tree/config.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'title'   => ['type' => 'string', 'minLength' => 1],
            'tree'    => nibwp_builderius_tree_schema(),
            'config'  => ['type' => 'object'],
            'dry_run' => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['title'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_create_fragment'
);
function nibwp_builderius_ability_create_fragment(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $config = nibwp_builderius_config_from_input($input);
    if ($config instanceof WP_Error) {
        return $config;
    }
    return nibwp_builderius_create_record(NIBWP_BLDR_CPT_FRAGMENT, (string) ($input['title'] ?? ''), $config, ['dry_run' => (bool) ($input['dry_run'] ?? false)]);
}

nibwp_builderius_register(
    'builderius-update-global-settings',
    __('Builderius – Update Global Settings', domain: 'nibwp'),
    __('Create a Builderius global-settings set (CSS variables / design tokens).', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'title'    => ['type' => 'string', 'minLength' => 1],
            'settings' => ['type' => 'object', 'description' => 'Arbitrary settings/design-token object stored on the set.'],
            'dry_run'  => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['title'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_update_global_settings'
);
function nibwp_builderius_ability_update_global_settings(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return nibwp_builderius_create_record(
        NIBWP_BLDR_CPT_SETTINGS,
        (string) ($input['title'] ?? ''),
        (array) ($input['settings'] ?? []),
        ['dry_run' => (bool) ($input['dry_run'] ?? false)]
    );
}

nibwp_builderius_register(
    'builderius-create-branch',
    __('Builderius – Create Branch', domain: 'nibwp'),
    __('Create a new branch on a template (git-like), optionally based on a commit.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer'],
            'name'        => ['type' => 'string', 'minLength' => 1],
            'base_commit' => ['type' => 'string', 'default' => ''],
            'dry_run'     => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['template_id', 'name'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_create_branch'
);
function nibwp_builderius_ability_create_branch(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return nibwp_builderius_create_branch(
        (int) ($input['template_id'] ?? 0),
        (string) ($input['name'] ?? ''),
        (string) ($input['base_commit'] ?? ''),
        ['dry_run' => (bool) ($input['dry_run'] ?? false)]
    );
}

nibwp_builderius_register(
    'builderius-export-config',
    __('Builderius – Export Config', domain: 'nibwp'),
    __('Export a template\'s current config (module tree) as portable JSON.', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => ['template_id' => ['type' => 'integer']],
        'required'   => ['template_id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_export_config'
);
function nibwp_builderius_ability_export_config(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $id = (int) ($input['template_id'] ?? 0);
    if (get_post_type($id) !== NIBWP_BLDR_CPT_TEMPLATE) {
        return new WP_Error('not_found', __('No Builderius template with that id.', domain: 'nibwp'));
    }
    $resolved = nibwp_builderius_resolve_template($id);
    return ['template_id' => $id, 'title' => get_the_title($id), 'config' => $resolved['config']];
}

nibwp_builderius_register(
    'builderius-import-config',
    __('Builderius – Import Config', domain: 'nibwp'),
    __('Create a template from an exported config ({modules:{…}}).', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'title'   => ['type' => 'string', 'minLength' => 1],
            'config'  => ['type' => 'object'],
            'type'    => ['type' => 'string', 'default' => ''],
            'dry_run' => ['type' => 'boolean', 'default' => false],
        ],
        'required'   => ['title', 'config'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_import_config'
);
function nibwp_builderius_ability_import_config(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    $config = (array) ($input['config'] ?? []);
    if (!isset($config['modules'])) {
        return new WP_Error('bad_config', __('config must contain a "modules" map.', domain: 'nibwp'));
    }
    return nibwp_builderius_create_template(
        (string) ($input['title'] ?? ''),
        (string) ($input['type'] ?? ''),
        '',
        $config,
        ['dry_run' => (bool) ($input['dry_run'] ?? false)]
    );
}

nibwp_builderius_register(
    'builderius-delete',
    __('Builderius – Delete', domain: 'nibwp'),
    __('Delete a Builderius record (template, component, fragment, release, branch, settings set).', domain: 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'id'    => ['type' => 'integer'],
            'force' => ['type' => 'boolean', 'description' => 'Bypass trash and delete permanently.', 'default' => false],
        ],
        'required'   => ['id'],
        'additionalProperties' => false,
    ],
    'nibwp_builderius_ability_delete',
    true,
    'DESTRUCTIVE. Deletes a Builderius record by id. force:true skips the trash. Only Builderius post types are deletable.'
);
function nibwp_builderius_ability_delete(array $input): array|WP_Error
{
    if ($e = nibwp_builderius_guard()) {
        return $e;
    }
    return nibwp_builderius_delete((int) ($input['id'] ?? 0), (bool) ($input['force'] ?? false));
}

/**
 * Resolve a config from an ability input: prefer an explicit `config`, else
 * build one from a `tree`. Returns [] when neither is present (empty shell).
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_builderius_config_from_input(array $input): array|WP_Error
{
    if (!empty($input['config']) && is_array($input['config'])) {
        return $input['config'];
    }
    if (!empty($input['tree']) && is_array($input['tree'])) {
        return nibwp_builderius_build_config($input['tree']);
    }
    return [];
}
