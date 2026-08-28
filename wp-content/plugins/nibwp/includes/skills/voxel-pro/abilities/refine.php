<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — change an existing template.
 *
 * Rebuilding a whole document to change one heading throws away every tweak
 * somebody made in Elementor since. This edits in place: name the element,
 * name the change. The search wiring is recomputed afterwards, because any
 * insert or move shifts the positions it is stored as.
 */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/relations.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/scorer.php';
require_once __DIR__ . '/../lib/persister.php';

wp_register_ability('nibwp/voxel-pro-refine', [
    'label'       => __('Voxel Pro — change an existing Voxel template', 'nibwp'),
    'description' => __('Edit a Voxel template in place: change a widget\'s settings, insert, move or remove an element, add a visibility rule, or restore the version from before the last rebuild. Re-validates the whole document and re-computes the search wiring afterwards.', 'nibwp'),
    'category'    => 'voxel-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'template_id' => ['type' => 'integer', 'description' => 'The template or page to change.'],
            'operations' => [
                'type' => 'array',
                'description' => 'Applied in order. {op:"set_settings", element_id, settings:{}} merges settings; {op:"insert", parent_id|null, index?, element:{}}; {op:"remove", element_id}; {op:"move", element_id, parent_id|null, index?}.',
                'items' => ['type' => 'object'],
            ],
            'post_type'      => ['type' => 'string', 'description' => 'Which post type the template belongs to — used to pick the listing its dynamic tags are tested against.'],
            'kind'           => ['type' => 'string', 'description' => 'Template kind, for the kind-specific checks. Inferred from the document when omitted.'],
            'restore_backup' => ['type' => 'boolean', 'description' => 'Put back the version from before this skill last rebuilt the template. Ignores operations.'],
            'dry_run'        => ['type' => 'boolean', 'default' => false],
            '_preflight_token' => ['type' => 'string'],
        ],
        'required' => ['template_id'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_voxel_pro_refine_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => "Read the document first — nibwp/voxel-pro-refine with no operations and dry_run:true returns its element tree with ids, which is what the operations address. Prefer this over rebuilding: it keeps everything you are not changing.",
            'readonly' => false, 'destructive' => false, 'idempotent' => false,
        ],
    ],
]);

function nibwp_voxel_pro_refine_execute(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('voxel-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $guard = nibwp_voxel_pro_guard();
    if (is_wp_error($guard)) {
        return $guard;
    }

    $template_id = (int) ($input['template_id'] ?? 0);
    $post = $template_id > 0 ? get_post($template_id) : null;
    if (!$post || !in_array($post->post_type, ['elementor_library', 'page'], true)) {
        return new WP_Error('unknown_template', sprintf('There is no Voxel template or page with id %d.', $template_id));
    }

    if (!empty($input['restore_backup'])) {
        return nibwp_voxel_pro_restore($template_id);
    }

    $elements = json_decode((string) get_post_meta($template_id, '_elementor_data', true), true);
    if (!is_array($elements)) {
        $elements = [];
    }

    $operations = (array) ($input['operations'] ?? []);
    $dry_run    = !empty($input['dry_run']);

    // No operations is a read: return the tree so the agent can address it.
    if ($operations === []) {
        return [
            'success'     => true,
            'template_id' => $template_id,
            'title'       => $post->post_title,
            'kind'        => nibwp_voxel_pro_infer_kind($template_id),
            'outline'     => nibwp_voxel_pro_outline($elements),
            'relations'   => (array) (\Voxel\get_custom_page_settings($template_id)['relations'] ?? []),
            'has_backup'  => get_post_meta($template_id, NIBWP_VOXEL_PRO_BACKUP_META, true) !== '',
            'summary'     => sprintf('Read "%s" (#%d): %d top-level element(s). Address changes by element id.', $post->post_title, $template_id, count($elements)),
        ];
    }

    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        require_once __DIR__ . '/../../../abilities/skill-preflight.php';
    }
    $raw_token = (string) ($input['_preflight_token'] ?? '');
    $token_payload = nibwp_skill_preflight_consume_token($raw_token, 'voxel-pro');
    if (is_wp_error($token_payload)) {
        return [
            'success' => false,
            'requires_user_input' => true,
            'question' => 'Run nibwp/skill-preflight { skill_id:"voxel-pro" } first to obtain a _preflight_token.',
            'next_action' => 'call_preflight',
            'summary' => 'Preflight gate: ' . $token_payload->get_error_message(),
        ];
    }

    $applied = [];
    foreach ($operations as $i => $op) {
        $result = nibwp_voxel_pro_apply_op($elements, (array) $op);
        if (is_wp_error($result)) {
            return new WP_Error(
                (string) $result->get_error_code(),
                sprintf('Operation %d (%s) could not be applied: %s. Nothing was changed.', $i + 1, (string) (((array) $op)['op'] ?? '?'), $result->get_error_message())
            );
        }
        $elements = $result['elements'];
        $applied[] = $result['note'];
    }

    $kind      = (string) ($input['kind'] ?? '') ?: nibwp_voxel_pro_infer_kind($template_id);
    $post_type = sanitize_key((string) ($input['post_type'] ?? '')) ?: nibwp_voxel_pro_infer_post_type($template_id);

    $elements   = nibwp_voxel_pro_normalize_tree($elements);
    $relations  = nibwp_voxel_pro_resolve_relations($elements, 'auto');
    $validation = nibwp_voxel_pro_validate($elements, ['kind' => $kind, 'post_type' => $post_type], $relations);
    $score      = nibwp_voxel_pro_score($elements, $validation, $kind);

    if (!$validation['passed']) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return [
            'success'         => false,
            'applied'         => $applied,
            'validation'      => $validation,
            'unchecked_items' => $validation['failed'],
            'summary'         => sprintf('The changes apply cleanly but leave the template invalid (%d issue(s)). Nothing was written.', count($validation['failed'])),
        ];
    }

    if ($dry_run) {
        return [
            'success'    => true,
            'dry_run'    => true,
            'applied'    => $applied,
            'validation' => $validation,
            'score'      => $score,
            'outline'    => nibwp_voxel_pro_outline($elements),
            'summary'    => sprintf('%d change(s) validate. Resubmit dry_run:false to write them.', count($applied)),
        ];
    }

    $result = nibwp_voxel_pro_persist($elements, ['kind' => $kind, 'post_type' => $post_type, 'existing_id' => $template_id], $relations, []);
    if (is_wp_error($result)) {
        nibwp_skill_preflight_bump_attempts($raw_token);
        return $result;
    }
    nibwp_skill_preflight_clear_token($raw_token);

    return [
        'success'    => true,
        'applied'    => $applied,
        'validation' => $validation,
        'score'      => $score,
        'result'     => $result,
        'summary'    => sprintf(
            '%d change(s) written to "%s" (#%d). %s',
            count($applied),
            $result['title'],
            $template_id,
            $result['relations_written'] > 0 ? sprintf('%d search connection(s) re-wired to their new positions.', (int) $result['relations_written']) : 'The previous version is kept and can be restored.'
        ),
        'next_steps' => ['Open the edit link and confirm the change', 'Restore with { template_id, restore_backup: true } if it is not what was wanted'],
    ];
}

/**
 * @return array{elements:array, note:string}|WP_Error
 */
function nibwp_voxel_pro_apply_op(array $elements, array $op): array|WP_Error
{
    $kind = (string) ($op['op'] ?? '');

    switch ($kind) {
        case 'set_settings':
            $id = (string) ($op['element_id'] ?? '');
            $settings = (array) ($op['settings'] ?? []);
            $found = false;
            $elements = nibwp_voxel_pro_map_tree($elements, static function (array $node) use ($id, $settings, &$found): array {
                if ((string) $node['id'] === $id) {
                    $found = true;
                    $node['settings'] = array_merge((array) ($node['settings'] ?? []), $settings);
                }
                return $node;
            });
            if (!$found) {
                return new WP_Error('unknown_element', sprintf('No element %s in this template.', $id));
            }
            return ['elements' => $elements, 'note' => sprintf('Set %d setting(s) on %s.', count($settings), $id)];

        case 'remove':
            $id = (string) ($op['element_id'] ?? '');
            $removed = false;
            $elements = nibwp_voxel_pro_remove_node($elements, $id, $removed);
            if (!$removed) {
                return new WP_Error('unknown_element', sprintf('No element %s in this template.', $id));
            }
            return ['elements' => $elements, 'note' => sprintf('Removed %s.', $id)];

        case 'insert':
            $element = (array) ($op['element'] ?? []);
            if ($element === []) {
                return new WP_Error('missing_element', 'insert needs an "element" to add.');
            }
            $parent = (string) ($op['parent_id'] ?? '');
            $index  = array_key_exists('index', $op) ? (int) $op['index'] : PHP_INT_MAX;
            $done   = false;
            $elements = nibwp_voxel_pro_insert_node($elements, $parent, $index, $element, $done);
            if (!$done) {
                return new WP_Error('unknown_parent', sprintf('No element %s to insert into.', $parent));
            }
            return ['elements' => $elements, 'note' => sprintf('Inserted a %s%s.', (string) ($element['widgetType'] ?? $element['elType'] ?? 'element'), $parent === '' ? ' at the top level' : ' into ' . $parent)];

        case 'move':
            $id = (string) ($op['element_id'] ?? '');
            $node = nibwp_voxel_pro_find_node($elements, $id);
            if ($node === null) {
                return new WP_Error('unknown_element', sprintf('No element %s in this template.', $id));
            }
            $removed = false;
            $elements = nibwp_voxel_pro_remove_node($elements, $id, $removed);
            $parent = (string) ($op['parent_id'] ?? '');
            $index  = array_key_exists('index', $op) ? (int) $op['index'] : PHP_INT_MAX;
            $done   = false;
            $elements = nibwp_voxel_pro_insert_node($elements, $parent, $index, $node, $done);
            if (!$done) {
                return new WP_Error('unknown_parent', sprintf('No element %s to move into. Nothing was moved.', $parent));
            }
            return ['elements' => $elements, 'note' => sprintf('Moved %s.', $id)];

        default:
            return new WP_Error('unknown_op', sprintf('Unknown operation "%s". Use set_settings, insert, remove or move.', $kind));
    }
}

function nibwp_voxel_pro_map_tree(array $nodes, callable $fn): array
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $node = $fn($node);
        if (!empty($node['elements'])) {
            $node['elements'] = nibwp_voxel_pro_map_tree((array) $node['elements'], $fn);
        }
        $out[] = $node;
    }
    return $out;
}

function nibwp_voxel_pro_remove_node(array $nodes, string $id, bool &$removed): array
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if ((string) ($node['id'] ?? '') === $id) {
            $removed = true;
            continue;
        }
        if (!empty($node['elements'])) {
            $node['elements'] = nibwp_voxel_pro_remove_node((array) $node['elements'], $id, $removed);
        }
        $out[] = $node;
    }
    return $out;
}

function nibwp_voxel_pro_insert_node(array $nodes, string $parent_id, int $index, array $element, bool &$done): array
{
    if ($parent_id === '') {
        $index = max(0, min($index, count($nodes)));
        array_splice($nodes, $index, 0, [$element]);
        $done = true;
        return $nodes;
    }
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if ((string) ($node['id'] ?? '') === $parent_id) {
            $children = (array) ($node['elements'] ?? []);
            $at = max(0, min($index, count($children)));
            array_splice($children, $at, 0, [$element]);
            $node['elements'] = $children;
            $done = true;
        } elseif (!empty($node['elements'])) {
            $node['elements'] = nibwp_voxel_pro_insert_node((array) $node['elements'], $parent_id, $index, $element, $done);
        }
        $out[] = $node;
    }
    return $out;
}

/** A readable map of the document, with the ids operations address. */
function nibwp_voxel_pro_outline(array $nodes, int $depth = 0): array
{
    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $row = [
            'id'   => (string) ($node['id'] ?? ''),
            'type' => (string) ($node['widgetType'] ?? $node['elType'] ?? ''),
        ];
        foreach (['title', 'content', 'ts_search_text', 'editor'] as $label_key) {
            $label = $node['settings'][$label_key] ?? null;
            if (is_string($label) && trim($label) !== '') {
                $row['label'] = mb_substr(wp_strip_all_tags($label), 0, 60);
                break;
            }
        }
        if (!empty($node['elements'])) {
            $row['children'] = nibwp_voxel_pro_outline((array) $node['elements'], $depth + 1);
        }
        $out[] = $row;
    }
    return $out;
}

/** What kind of template this is, read from how it is stored and assigned. */
function nibwp_voxel_pro_infer_kind(int $template_id): string
{
    $type = (string) get_post_meta($template_id, '_elementor_template_type', true);
    $map = ['card' => 'card', 'single-post' => 'single-post', 'archive' => 'archive', 'single-term' => 'single-term', 'header' => 'header', 'footer' => 'footer'];
    if (isset($map[$type])) {
        return $map[$type];
    }
    $site = (array) \Voxel\get('templates', []);
    if ((int) ($site['kit_popups'] ?? 0) === $template_id) {
        return 'style-kit-popup';
    }
    if ((int) ($site['kit_timeline'] ?? 0) === $template_id) {
        return 'style-kit-timeline';
    }
    return 'page';
}

/** Which post type's data this template renders, if any. */
function nibwp_voxel_pro_infer_post_type(int $template_id): string
{
    foreach ((array) \Voxel\get('post_types', []) as $key => $cfg) {
        $cfg = (array) $cfg;
        foreach ((array) ($cfg['templates'] ?? []) as $id) {
            if ((int) $id === $template_id) {
                return (string) $key;
            }
        }
        foreach ((array) ($cfg['custom_templates'] ?? []) as $group) {
            foreach ((array) $group as $entry) {
                $entry = (array) $entry;
                if ((int) ($entry['id'] ?? 0) === $template_id) {
                    return (string) $key;
                }
            }
        }
    }
    $settings = (array) get_post_meta($template_id, '_elementor_page_settings', true);
    $preview  = (int) ($settings['voxel_preview_post'] ?? 0);
    return $preview > 0 ? (string) get_post_type($preview) : '';
}
