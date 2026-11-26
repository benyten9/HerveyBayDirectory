<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validator.php';

/**
 * Mosaic Pro — build. Validate, create the classes, then hand the tree to the
 * integration: a new page, an existing page, a template/master/component, or a
 * new component. dry_run is the default because the first look should cost
 * nothing.
 */

nibwp_mosaic_pro_register(
    'mosaic-pro-build',
    __('Mosaic Pro — Build', 'nibwp'),
    'Validate a Mosaic tree (from mosaic-pro-convert), create its classes, and write it: as a new page (title), into a page (post_id), into a template/master/component, or as a new reusable component. Refuses on any validation error. dry_run defaults to true.',
    [
        'tree'           => ['type' => ['object', 'array']],
        'classes'        => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Class definitions [{name, style, css}] from convert, created before the tree.'],
        'theme_id'       => ['type' => 'string'],
        'title'          => ['type' => 'string', 'description' => 'Build a NEW page with this title.'],
        'status'         => ['type' => 'string', 'enum' => ['draft', 'publish', 'private', 'pending']],
        'post_id'        => ['type' => 'integer', 'description' => 'Build into this page (its Mosaic template is created if it has none).'],
        'document_type'  => ['type' => 'string', 'enum' => ['template', 'master', 'component']],
        'document_id'    => ['type' => 'string'],
        'component_name' => ['type' => 'string', 'description' => 'Save the tree as a NEW reusable component with this name.'],
        'parent_id'      => ['type' => 'string'],
        'relative_to'    => ['type' => 'string'],
        'position'       => ['type' => 'string', 'enum' => ['first', 'last', 'before', 'after']],
        'dry_run'        => ['type' => 'boolean', 'description' => 'Default true. Set false to write.'],
    ],
    'nibwp_mosaic_pro_build_ability',
    false,
    [
        'Run convert first and build from its output. Errors stop here before anything is written.',
        'Default is a dry run: read `dropped` and `would` in the result, then call again with dry_run: false.',
        'Content shows to visitors only when its Mosaic theme is live. Say so if `live` is false; never switch the site theme yourself.',
        'A section repeated across pages belongs in a component (component_name), placed with {"type": "component-instance/<id>"}.',
    ],
    ['tree']
);

function nibwp_mosaic_pro_build_ability(array $input)
{
    $dry_run = !array_key_exists('dry_run', $input) || !empty($input['dry_run']);
    $gate = nibwp_mosaic_pro_gate(!$dry_run);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $tree = nibwp_mosaic_roots($input['tree'] ?? []);
    if ($tree === []) {
        return new WP_Error('nibwp_mosaic_pro_no_tree', __('tree is empty. Convert something first.', 'nibwp'));
    }

    $defs = [];
    foreach ((array) ($input['classes'] ?? []) as $def) {
        if (is_array($def) && !empty($def['name'])) {
            $defs[(string) $def['name']] = $def;
        }
    }

    $context = nibwp_mosaic_pro_context($input, array_keys($defs));
    $report = nibwp_mosaic_pro_validate($tree, $context);
    if (!$report['valid']) {
        return new WP_Error('nibwp_mosaic_pro_invalid', __('The tree has errors; nothing was written. Fix them and convert again.', 'nibwp'), ['errors' => $report['errors'], 'warnings' => $report['warnings']]);
    }

    $theme = (string) ($context['theme_id'] ?? ($input['theme_id'] ?? ''));
    $existing = (array) ($context['classes'] ?? []);
    $class_results = [];
    $pending = [];
    foreach ($defs as $name => $def) {
        if (in_array($name, $existing, true)) {
            $class_results[] = ['name' => $name, 'status' => 'exists, reused'];
            continue;
        }
        $made = nibwp_mosaic_pro_delegate('mosaic-create-class', array_filter([
            'theme_id' => $theme,
            'name'     => $name,
            'style'    => (array) ($def['style'] ?? []),
            'css'      => (string) ($def['css'] ?? ''),
            'dry_run'  => $dry_run,
        ], static fn($v) => $v !== '' && $v !== []));
        if ($made instanceof WP_Error) {
            return $made;
        }
        $class_results[] = ['name' => $name, 'status' => $dry_run ? 'would be created' : 'created', 'dropped' => $made['dropped'] ?? []];
        $pending[] = $name;
    }

    // On a dry run the new classes do not exist yet, so their names cannot resolve; check the rest without them.
    $send = $dry_run && $pending !== [] ? nibwp_mosaic_pro_without_classes($tree, $pending) : $tree;
    $common = array_filter(['theme_id' => $theme, 'dry_run' => $dry_run], static fn($v) => $v !== '');

    if (!empty($input['component_name'])) {
        $result = nibwp_mosaic_pro_delegate('mosaic-create-component', $common + ['name' => (string) $input['component_name'], 'tree' => $send]);
        $would = 'a new component "' . $input['component_name'] . '"';
    } elseif (!empty($input['title']) && empty($input['post_id'])) {
        $result = nibwp_mosaic_pro_delegate('mosaic-create-page', $common + ['title' => (string) $input['title'], 'status' => (string) ($input['status'] ?? 'draft'), 'tree' => $send]);
        $would = 'a new page "' . $input['title'] . '" with its own Mosaic template';
    } elseif (!empty($input['post_id']) && !empty($context['theme_id']) && function_exists('nibwp_mosaic_template_for_post') && nibwp_mosaic_template_for_post((string) $context['theme_id'], (int) $input['post_id']) === '') {
        $result = nibwp_mosaic_pro_delegate('mosaic-create-page', $common + ['post_id' => (int) $input['post_id'], 'tree' => $send]);
        $would = 'page ' . (int) $input['post_id'] . ', which gets its own Mosaic template';
    } else {
        $target = array_filter([
            'post_id'       => (int) ($input['post_id'] ?? 0),
            'document_type' => (string) ($input['document_type'] ?? ''),
            'document_id'   => (string) ($input['document_id'] ?? ''),
            'parent_id'     => (string) ($input['parent_id'] ?? ''),
            'relative_to'   => (string) ($input['relative_to'] ?? ''),
            'position'      => (string) ($input['position'] ?? ''),
        ], static fn($v) => $v !== '' && $v !== 0);
        if (empty($target['post_id']) && empty($target['document_id'])) {
            return new WP_Error('nibwp_mosaic_pro_no_target', __('Say where it goes: title (new page), post_id, document_type + document_id, or component_name.', 'nibwp'));
        }
        $result = nibwp_mosaic_pro_delegate('mosaic-insert-nodes', $common + $target + ['tree' => $send]);
        $would = 'an insert into ' . (!empty($target['post_id']) ? 'page ' . $target['post_id'] : $target['document_type'] . ' ' . $target['document_id']);
    }
    if ($result instanceof WP_Error) {
        return $result;
    }

    $live = function_exists('nibwp_mosaic_live_theme_id') ? nibwp_mosaic_live_theme_id() : '';

    return [
        'dry_run'         => $dry_run,
        'would'           => $would,
        'classes'         => $class_results,
        'result'          => $result,
        'dropped'         => $result['dropped'] ?? [],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'live'            => $live !== '' && ($theme === '' || $live === $theme),
        'next'            => $dry_run
            ? 'Nothing was written. If `dropped` is empty and the plan is right, call again with dry_run: false.'
            : (($live !== '') ? 'Built. Review it on the page.' : 'Built. The Mosaic theme is not live, so visitors do not see it yet; the owner makes it live from wp-admin → Mosaic.'),
    ];
}

/** Remove named classes from every node (a dry run cannot reference classes that do not exist yet). */
function nibwp_mosaic_pro_without_classes(array $tree, array $names): array
{
    $out = [];
    foreach ($tree as $node) {
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['classes'])) {
            $node['classes'] = array_values(array_diff((array) $node['classes'], $names));
            if ($node['classes'] === []) {
                unset($node['classes']);
            }
        }
        if (isset($node['children'])) {
            $node['children'] = nibwp_mosaic_pro_without_classes((array) $node['children'], $names);
        }
        $out[] = $node;
    }

    return $out;
}
