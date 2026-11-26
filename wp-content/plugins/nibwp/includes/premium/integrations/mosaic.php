<?php

declare(strict_types=1);

/**
 * NIBWP × Mosaic integration.
 *
 * Mosaic (mosaicbuilder.com) is a theme builder with its own data layer: a
 * Mosaic theme holds masters (page shells), templates (what a URL renders),
 * components, universal classes, variables and breakpoints, and every element is
 * a row in its own node table. This integration reads that model straight off
 * the tables and writes through Mosaic's own editor classes — the same calls its
 * onboarding wizard makes — so revisions, orderings, generated class names and
 * heal rules all stay Mosaic's.
 *
 * Mosaic validates every value and silently drops what it rejects; every write
 * here reads back what Mosaic kept and returns the difference as `dropped`.
 * Loaded only when the integration is enabled + unlocked (premium/bootstrap.php).
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/mosaic/lib/catalog.php';
require_once __DIR__ . '/mosaic/lib/tree.php';
require_once __DIR__ . '/mosaic/lib/model.php';
require_once __DIR__ . '/mosaic/lib/writer.php';

/**
 * @param array<string,mixed> $schema
 * @param callable-string     $callback
 */
function nibwp_mosaic_register(string $slug, string $label, string $description, array $schema, string $callback, bool $readonly, bool $destructive = false, string $instructions = ''): void
{
    wp_register_ability('nibwp/' . $slug, [
        'label'               => $label,
        'description'         => $description,
        'category'            => 'mosaic',
        'input_schema'        => $schema,
        'execute_callback'    => $callback,
        'permission_callback' => 'nibwp_permission_callback',
        'meta'                => [
            'show_in_rest' => true,
            'mcp'          => ['public' => true],
            'annotations'  => [
                'instructions' => $instructions !== '' ? $instructions : $description,
                'readonly'     => $readonly,
                'destructive'  => $destructive,
                'idempotent'   => $readonly,
            ],
        ],
    ]);
}

function nibwp_mosaic_tree_schema(): array
{
    return [
        'type'        => ['object', 'array'],
        'description' => 'One node or a list of nodes. Node: {type, name?, tag?, text?, style?, css?, classes?, cssClasses?, url?, alt?, image?, data?, children?}. '
            . 'type = an element from nibwp/mosaic-list-elements (section, div, text, button, image, list, icon, ...) or "component-instance/<id>". '
            . 'text on a text/button = its words. style = {base|tablet|mobile|hover|"hover@mobile": {css props}}. css = extra CSS declarations. '
            . 'classes = Mosaic class NAMES. data = Mosaic\'s own data object, merged last.',
    ];
}

function nibwp_mosaic_theme_prop(): array
{
    return ['type' => 'string', 'description' => 'Mosaic theme id. Default: the live theme, or the only theme.'];
}

// ===========================================================================
// READ
// ===========================================================================

nibwp_mosaic_register(
    'mosaic-status',
    __('Mosaic – Status', 'nibwp'),
    __('Is Mosaic running and set up: version, themes, which theme is live, and what to do next. Call first.', 'nibwp'),
    ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'nibwp_mosaic_ability_status',
    true
);
function nibwp_mosaic_ability_status(array $input = []): array
{
    return nibwp_mosaic_status();
}

nibwp_mosaic_register(
    'mosaic-list-themes',
    __('Mosaic – List Themes', 'nibwp'),
    __('List Mosaic themes and which one is live.', 'nibwp'),
    ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'nibwp_mosaic_ability_list_themes',
    true
);
function nibwp_mosaic_ability_list_themes(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return ['themes' => nibwp_mosaic_list_themes(), 'live_theme' => nibwp_mosaic_live_theme_id()];
}

nibwp_mosaic_register(
    'mosaic-list-templates',
    __('Mosaic – List Templates', 'nibwp'),
    __('List a theme\'s masters (page shells) and templates (what each URL renders), with the posts each template is assigned to.', 'nibwp'),
    ['type' => 'object', 'properties' => ['theme_id' => nibwp_mosaic_theme_prop()], 'additionalProperties' => false],
    'nibwp_mosaic_ability_list_templates',
    true
);
function nibwp_mosaic_ability_list_templates(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }
    $theme_id = nibwp_mosaic_resolve_theme((string) ($input['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }

    return ['theme_id' => $theme_id, 'masters' => nibwp_mosaic_list_masters($theme_id), 'templates' => nibwp_mosaic_list_templates($theme_id)];
}

nibwp_mosaic_register(
    'mosaic-list-components',
    __('Mosaic – List Components', 'nibwp'),
    __('List reusable components with the node type that places an instance of each.', 'nibwp'),
    ['type' => 'object', 'properties' => ['theme_id' => nibwp_mosaic_theme_prop()], 'additionalProperties' => false],
    'nibwp_mosaic_ability_list_components',
    true
);
function nibwp_mosaic_ability_list_components(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }
    $theme_id = nibwp_mosaic_resolve_theme((string) ($input['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }

    return ['theme_id' => $theme_id, 'components' => nibwp_mosaic_list_components($theme_id)];
}

nibwp_mosaic_register(
    'mosaic-list-design',
    __('Mosaic – List Design System', 'nibwp'),
    __('The theme\'s design system: universal classes (with how to apply each), variables (with how to reference each) and breakpoints.', 'nibwp'),
    ['type' => 'object', 'properties' => ['theme_id' => nibwp_mosaic_theme_prop()], 'additionalProperties' => false],
    'nibwp_mosaic_ability_list_design',
    true
);
function nibwp_mosaic_ability_list_design(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }
    $theme_id = nibwp_mosaic_resolve_theme((string) ($input['theme_id'] ?? ''));
    if ($theme_id instanceof WP_Error) {
        return $theme_id;
    }

    return [
        'theme_id'    => $theme_id,
        'classes'     => nibwp_mosaic_list_classes($theme_id),
        'variables'   => nibwp_mosaic_list_variables($theme_id),
        'breakpoints' => nibwp_mosaic_list_breakpoints($theme_id),
    ];
}

nibwp_mosaic_register(
    'mosaic-list-elements',
    __('Mosaic – List Elements', 'nibwp'),
    __('The element types, data keys, style properties (with accepted values), states and breakpoints Mosaic accepts on this install.', 'nibwp'),
    ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'nibwp_mosaic_ability_list_elements',
    true
);
function nibwp_mosaic_ability_list_elements(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }
    $catalog = nibwp_mosaic_catalog();
    foreach ($catalog['elements'] as $type => &$meta) {
        $meta['available'] = nibwp_mosaic_type_known($type);
    }
    unset($meta);

    return $catalog;
}

nibwp_mosaic_register(
    'mosaic-get-tree',
    __('Mosaic – Get Tree', 'nibwp'),
    __('Read the element tree of a page (post_id), template, master or component, with node ids, plus a one-line-per-element outline.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id'      => nibwp_mosaic_theme_prop(),
            'post_id'       => ['type' => 'integer', 'description' => 'A post whose own Mosaic template to read.'],
            'document_type' => ['type' => 'string', 'enum' => ['template', 'master', 'component']],
            'document_id'   => ['type' => 'string'],
            'outline_only'  => ['type' => 'boolean', 'description' => 'Return only the outline (cheaper for big pages).'],
        ],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_get_tree',
    true
);
function nibwp_mosaic_ability_get_tree(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }
    $target = nibwp_mosaic_resolve_target($input);
    if ($target instanceof WP_Error) {
        return $target;
    }
    [$theme_id, $type, $id] = $target;
    $tree = nibwp_mosaic_rows_to_tree(nibwp_mosaic_document_rows($theme_id, $type, $id));
    $out = [
        'theme_id'      => $theme_id,
        'document_type' => $type,
        'document_id'   => $id,
        'outline'       => nibwp_mosaic_outline($tree),
    ];
    if (empty($input['outline_only'])) {
        $out['tree'] = $tree;
    }

    return $out;
}

// ===========================================================================
// WRITE
// ===========================================================================

$nibwp_mosaic_write_note = 'Mosaic drops any value its validators reject without an error, so read `dropped` in the result: every entry is something that did not land. '
    . 'Run with dry_run: true first. If someone has this document open in the Mosaic editor, ask them to reload it afterwards.';

nibwp_mosaic_register(
    'mosaic-create-page',
    __('Mosaic – Create Page', 'nibwp'),
    __('Give a page its own Mosaic template (creating the page from a title, or using post_id), optionally filled with a tree. Creates a plain master if the theme has none.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id'  => nibwp_mosaic_theme_prop(),
            'post_id'   => ['type' => 'integer', 'description' => 'An existing post/page to give a Mosaic template.'],
            'title'     => ['type' => 'string', 'description' => 'Title for a new page (when no post_id).'],
            'post_type' => ['type' => 'string', 'description' => 'For a new post. Default page.'],
            'status'    => ['type' => 'string', 'enum' => ['draft', 'publish', 'private', 'pending'], 'description' => 'For a new post. Default draft.'],
            'master_id' => ['type' => 'string', 'description' => 'Master (page shell) to use. Default: the first one.'],
            'tree'      => nibwp_mosaic_tree_schema(),
            'dry_run'   => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_create_page',
    false,
    false,
    $nibwp_mosaic_write_note
);
function nibwp_mosaic_ability_create_page(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_page($input);
}

nibwp_mosaic_register(
    'mosaic-insert-nodes',
    __('Mosaic – Insert Elements', 'nibwp'),
    __('Insert a tree of elements into a page (post_id), template, master or component: at the end of the content, first/last inside parent_id, or before/after relative_to.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id'      => nibwp_mosaic_theme_prop(),
            'post_id'       => ['type' => 'integer'],
            'document_type' => ['type' => 'string', 'enum' => ['template', 'master', 'component']],
            'document_id'   => ['type' => 'string'],
            'tree'          => nibwp_mosaic_tree_schema(),
            'parent_id'     => ['type' => 'string', 'description' => 'Insert inside this node.'],
            'relative_to'   => ['type' => 'string', 'description' => 'Insert next to this node (position before/after).'],
            'position'      => ['type' => 'string', 'enum' => ['first', 'last', 'before', 'after']],
            'dry_run'       => ['type' => 'boolean'],
        ],
        'required'             => ['tree'],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_insert_nodes',
    false,
    false,
    $nibwp_mosaic_write_note
);
function nibwp_mosaic_ability_insert_nodes(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_insert($input);
}

nibwp_mosaic_register(
    'mosaic-update-node',
    __('Mosaic – Update Element', 'nibwp'),
    __('Change one element: merge style (base/tablet/mobile/hover), css, classes, tag, name, url, alt or raw data into it, and/or replace its words with text.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id'      => nibwp_mosaic_theme_prop(),
            'node_id'       => ['type' => 'string'],
            'text'          => ['type' => 'string', 'description' => 'New words for a text, button or link.'],
            'style'         => ['type' => 'object'],
            'replace_style' => ['type' => 'boolean', 'description' => 'Replace the whole style instead of merging.'],
            'css'           => ['type' => 'string'],
            'classes'       => ['type' => 'array', 'items' => ['type' => 'string']],
            'cssClasses'    => ['type' => 'array', 'items' => ['type' => 'string']],
            'tag'           => ['type' => 'string'],
            'name'          => ['type' => 'string'],
            'url'           => ['type' => 'string'],
            'target'        => ['type' => 'string'],
            'alt'           => ['type' => 'string'],
            'image'         => ['type' => 'string'],
            'data'          => ['type' => 'object'],
            'dry_run'       => ['type' => 'boolean'],
        ],
        'required'             => ['node_id'],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_update_node',
    false,
    false,
    $nibwp_mosaic_write_note
);
function nibwp_mosaic_ability_update_node(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_update($input);
}

nibwp_mosaic_register(
    'mosaic-delete-node',
    __('Mosaic – Delete Element', 'nibwp'),
    __('Remove an element and everything inside it.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id' => nibwp_mosaic_theme_prop(),
            'node_id'  => ['type' => 'string'],
            'dry_run'  => ['type' => 'boolean'],
        ],
        'required'             => ['node_id'],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_delete_node',
    false,
    true
);
function nibwp_mosaic_ability_delete_node(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_delete($input);
}

nibwp_mosaic_register(
    'mosaic-create-class',
    __('Mosaic – Create Class', 'nibwp'),
    __('Create a universal class (a named, reusable style in the theme\'s design system), or update its styles with update: true. Apply it to elements with classes: ["name"].', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id' => nibwp_mosaic_theme_prop(),
            'name'     => ['type' => 'string', 'description' => 'lowercase-with-hyphens, e.g. card, btn-primary.'],
            'label'    => ['type' => 'string'],
            'style'    => ['type' => 'object', 'description' => '{base|tablet|mobile|hover: {css props}}'],
            'css'      => ['type' => 'string'],
            'update'   => ['type' => 'boolean'],
            'dry_run'  => ['type' => 'boolean'],
        ],
        'required'             => ['name'],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_create_class',
    false,
    false,
    $nibwp_mosaic_write_note
);
function nibwp_mosaic_ability_create_class(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_class($input);
}

nibwp_mosaic_register(
    'mosaic-create-component',
    __('Mosaic – Create Component', 'nibwp'),
    __('Create a reusable component from a tree. Place it anywhere with {"type": "component-instance/<id>"}; editing the component updates every instance.', 'nibwp'),
    [
        'type'       => 'object',
        'properties' => [
            'theme_id' => nibwp_mosaic_theme_prop(),
            'name'     => ['type' => 'string'],
            'category' => ['type' => 'string', 'description' => 'Component category name. Default NibWP.'],
            'tree'     => nibwp_mosaic_tree_schema(),
            'dry_run'  => ['type' => 'boolean'],
        ],
        'required'             => ['name', 'tree'],
        'additionalProperties' => false,
    ],
    'nibwp_mosaic_ability_create_component',
    false,
    false,
    $nibwp_mosaic_write_note
);
function nibwp_mosaic_ability_create_component(array $input = [])
{
    if ($e = nibwp_mosaic_guard()) {
        return $e;
    }

    return nibwp_mosaic_write_component($input);
}
