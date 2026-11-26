<?php

declare(strict_types=1);

/**
 * Mosaic Pro — validation before anything is written.
 *
 * Mosaic accepts a write and drops every value its validators reject, with no
 * error. The symptom is a section that renders unstyled or empty, found later by
 * a person. So the rules Mosaic enforces silently are enforced here loudly:
 * element types, which children an element takes, which data keys it has, which
 * states and breakpoints exist, which style properties exist and which values
 * the enumerated ones accept. All of it comes from the integration's catalog,
 * itself read from the Mosaic source, so the two cannot drift apart.
 *
 * Errors stop a build. Warnings are real problems a page can survive (an image
 * with no alt). Recommendations are how to make it better (a repeated style
 * that should be a class).
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param array<int,array<string,mixed>> $roots   shorthand or native nodes
 * @param array<string,mixed>            $context {classes: string[] known class names, components: string[]|null, live_types: bool}
 * @return array{valid:bool, errors:array, warnings:array, recommendations:array, stats:array}
 */
function nibwp_mosaic_pro_validate(array $roots, array $context = []): array
{
    $report = ['errors' => [], 'warnings' => [], 'recommendations' => [], 'stats' => ['nodes' => 0, 'h1' => 0, 'images' => 0, 'buttons' => 0]];
    $notes = [];
    $state = ['headings' => [], 'styles' => [], 'colors' => []];

    foreach (array_values($roots) as $i => $root) {
        if (!is_array($root)) {
            $report['errors'][] = ['path' => '[' . $i . ']', 'rule' => 'not_a_node', 'message' => 'Each root must be a node object.'];
            continue;
        }
        $native = nibwp_mosaic_normalize_tree($root, $notes);
        nibwp_mosaic_pro_validate_node($native, null, (string) ($native['type'] ?? '?') . '[' . $i . ']', $context, $report, $state);
    }
    foreach ($notes as $n) {
        $report['warnings'][] = ['path' => $n['path'], 'rule' => 'shorthand', 'message' => $n['message']];
    }

    nibwp_mosaic_pro_page_rules($state, $report);

    $report['valid'] = $report['errors'] === [];

    return $report;
}

function nibwp_mosaic_pro_validate_node(array $node, ?string $parent_type, string $path, array $context, array &$report, array &$state): void
{
    $catalog = nibwp_mosaic_catalog();
    $type = (string) ($node['type'] ?? '');
    $data = (array) ($node['data'] ?? []);
    $children = (array) ($node['children'] ?? []);
    $report['stats']['nodes']++;
    $err = static function (string $rule, string $message) use (&$report, $path): void {
        $report['errors'][] = ['path' => $path, 'rule' => $rule, 'message' => $message];
    };
    $warn = static function (string $rule, string $message) use (&$report, $path): void {
        $report['warnings'][] = ['path' => $path, 'rule' => $rule, 'message' => $message];
    };

    $is_instance = str_starts_with($type, 'component-instance/');
    $meta = $catalog['elements'][$type] ?? null;

    if ($type === '') {
        $err('no_type', 'Every node needs a type.');

        return;
    }
    if (in_array($type, $catalog['system'], true)) {
        $err('system_node', sprintf('"%s" is created and owned by Mosaic. Insert content into it; never author it.', $type));

        return;
    }
    if ($is_instance) {
        $id = substr($type, strlen('component-instance/'));
        if (isset($context['components']) && is_array($context['components']) && !in_array($id, $context['components'], true)) {
            $err('unknown_component', sprintf('No component %s in this theme. List them with nibwp/mosaic-list-components.', $id));
        }
        if ($children !== []) {
            $err('instance_children', 'A component instance takes no children: edit the component itself to change every instance.');
        }

        return;
    }
    if ($meta === null && isset($catalog['parts'][$type])) {
        if ($parent_type !== null && $parent_type !== $catalog['parts'][$type]) {
            $err('part_parent', sprintf('"%s" belongs directly inside "%s", not "%s".', $type, $catalog['parts'][$type], $parent_type));
        }
        if (isset($node['data']['style'])) {
            nibwp_mosaic_pro_validate_style((array) $node['data']['style'], $path, $context, $report, $state);
        }
        foreach (array_values($children) as $i => $child) {
            if (is_array($child)) {
                nibwp_mosaic_pro_validate_node($child, $type, $path . ' > ' . (string) ($child['type'] ?? '?') . '[' . $i . ']', $context, $report, $state);
            }
        }

        return;
    }
    if ($meta === null) {
        $live =!empty($context['live_types']) && function_exists('nibwp_mosaic_type_known') && nibwp_mosaic_type_known($type);
        if (!$live) {
            $err('unknown_type', sprintf('"%s" is not a Mosaic element. Use one of: %s.', $type, implode(', ', array_keys($catalog['elements']))));

            return;
        }
        $warn('uncatalogued_type', sprintf('"%s" exists on this install but is not in the catalog; its data keys are not checked.', $type));
    }

    // Where inline runs may live.
    $inline_parents = ['text', 'wysiwyg-link', 'wysiwyg-span'];
    if ($meta !== null && $meta['role'] === 'inline' && !in_array((string) $parent_type, $inline_parents, true)) {
        $err('inline_outside_text', sprintf('"%s" only lives inside a text, wysiwyg-link or wysiwyg-span. Wrap it in {"type":"text"}.', $type));
    }

    // Data keys.
    if ($meta !== null) {
        $allowed = $meta['role'] === 'inline' ? array_merge($meta['data'], ['name', 'style', 'cssClasses', 'attributes']) : array_merge($catalog['common'], $meta['data']);
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                $err('unknown_data_key', sprintf('"%s" has no data key "%s" (Mosaic would drop it). Accepted: %s.', $type, $key, implode(', ', $allowed)));
            }
        }
    }
    if (isset($data['tagName']) && in_array(strtolower((string) $data['tagName']), ['script', 'style'], true)) {
        $err('banned_tag', 'Mosaic refuses script and style tags. Use the code element (Mosaic Pro) for scripts.');
    }

    // Children.
    if ($meta !== null) {
        $rule = $meta['children'];
        if ($rule === 'none' && $children !== []) {
            $err('leaf_children', sprintf('"%s" takes no children.', $type));
        }
        if ($rule === 'inline') {
            foreach ($children as $c) {
                $ct = (string) ($c['type'] ?? '');
                if (($catalog['elements'][$ct]['role'] ?? '') !== 'inline') {
                    $err('text_children', sprintf('A %s holds only wysiwyg-* runs (its words), not "%s". Put block elements beside it, not inside.', $type, $ct));
                }
            }
            if ($type === 'text' && $children === []) {
                $warn('empty_text', 'This text has no words. Give it text or remove it.');
            }
        }
        if (is_array($rule)) {
            foreach ($children as $c) {
                $ct = (string) ($c['type'] ?? '');
                if (!in_array($ct, $rule, true)) {
                    $err('wrong_child', sprintf('"%s" takes only %s as children, not "%s".', $type, implode(', ', $rule), $ct));
                }
            }
        }
    }

    // Style.
    if (isset($data['style'])) {
        nibwp_mosaic_pro_validate_style((array) $data['style'], $path, $context, $report, $state);
    }

    // Content checks.
    if ($type === 'image') {
        $report['stats']['images']++;
        if (trim((string) ($data['alt'] ?? '')) === '') {
            $warn('image_alt', 'Image without alt text. Describe it, or set alt to a single space only if it is decoration.');
        }
        if (empty($data['image'])) {
            $err('image_source', 'Image without an image (a URL or an attachment).');
        }
    }
    if ($type === 'button') {
        $report['stats']['buttons']++;
        if (empty($data['url']) && empty($data['inactive'])) {
            $warn('button_url', 'Button without url: it renders as a <button>. Give it a url if it should navigate.');
        }
        if (nibwp_mosaic_node_text($node) === '') {
            $warn('button_label', 'Button without a label.');
        }
    }
    if ($type === 'text' && preg_match('/^h([1-6])$/', strtolower((string) ($data['tagName'] ?? ''))) === 1) {
        $level = (int) substr(strtolower((string) $data['tagName']), 1);
        $state['headings'][] = ['level' => $level, 'path' => $path];
        if ($level === 1) {
            $report['stats']['h1']++;
        }
    }

    foreach (array_values($children) as $i => $child) {
        if (is_array($child)) {
            nibwp_mosaic_pro_validate_node($child, $type, $path . ' > ' . (string) ($child['type'] ?? '?') . '[' . $i . ']', $context, $report, $state);
        }
    }
}

function nibwp_mosaic_pro_validate_style(array $style, string $path, array $context, array &$report, array &$state): void
{
    $catalog = nibwp_mosaic_catalog();
    $props = nibwp_mosaic_style_properties();
    $enums = $catalog['style']['enums'];
    $structured = $catalog['style']['structured'];
    $states = array_keys($catalog['states']);
    $known_bps = array_keys($catalog['breakpoints']);

    if (isset($style['_classNames'])) {
        $known = array_merge((array) ($context['classes'] ?? []), (array) ($context['new_classes'] ?? []));
        foreach ((array) $style['_classNames'] as $name) {
            if (isset($context['classes']) && !in_array($name, $known, true)) {
                $report['errors'][] = ['path' => $path, 'rule' => 'unknown_class', 'message' => sprintf('No Mosaic class "%s". Define it in classes, or create it with nibwp/mosaic-create-class.', $name)];
            }
        }
    }

    foreach ((array) ($style['states'] ?? []) as $sid => $bps) {
        if (!in_array((string) $sid, $states, true)) {
            $report['errors'][] = ['path' => $path, 'rule' => 'unknown_state', 'message' => sprintf('No style state "%s". States: %s.', $sid, implode(', ', $states))];
            continue;
        }
        foreach ((array) $bps as $bp => $block) {
            $bp = (string) $bp;
            if (!in_array($bp, $known_bps, true) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $bp) !== 1) {
                $report['errors'][] = ['path' => $path, 'rule' => 'unknown_breakpoint', 'message' => sprintf('No breakpoint "%s". Use base/tablet/mobile (_/_t/_m) or a custom breakpoint id.', $bp)];
                continue;
            }
            foreach ((array) $block as $prop => $value) {
                $prop = (string) $prop;
                if (!in_array($prop, $props, true)) {
                    $report['errors'][] = ['path' => $path, 'rule' => 'unknown_style_property', 'message' => sprintf('Mosaic has no style property "%s". Write it as CSS in css instead.', $prop)];
                    continue;
                }
                if (isset($enums[$prop]) && is_scalar($value) && !in_array((string) $value, $enums[$prop], true)) {
                    $report['errors'][] = ['path' => $path, 'rule' => 'style_value', 'message' => sprintf('%s: "%s" is not accepted (Mosaic would drop it). Accepted: %s.', $prop, (string) $value, implode(', ', $enums[$prop]))];
                }
                if (in_array($prop, $structured, true) && is_scalar($value)) {
                    $report['errors'][] = ['path' => $path, 'rule' => 'structured_property', 'message' => sprintf('%s is stored in Mosaic\'s own structure, not as a string. Put it in css: "%s: %s;".', $prop, strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $prop)), (string) $value)];
                }
                if ($sid === '&' && $bp === '_' && in_array($prop, ['color', 'backgroundColor'], true) && is_scalar($value)) {
                    $state['colors'][strtolower((string) $value)][] = $path;
                }
            }
            if ($sid === '&' && $bp === '_' && is_array($block) && count($block) >= 3) {
                ksort($block);
                $state['styles'][md5((string) json_encode($block))][] = ['path' => $path, 'block' => $block];
            }
        }
        if ($sid === '&') {
            $base = (array) ($bps['_'] ?? []);
            $mobile = (array) ($bps['_m'] ?? []);
            $size = (string) ($base['fontSize'] ?? '');
            if (preg_match('/^(\d+(?:\.\d+)?)px$/', $size, $m) === 1 && (float) $m[1] >= 40 && !isset($mobile['fontSize'])) {
                $report['warnings'][] = ['path' => $path, 'rule' => 'mobile_font', 'message' => sprintf('fontSize %s has no mobile size. Add style.mobile.font-size (or use clamp()).', $size)];
            }
            foreach (['paddingTop', 'paddingBottom'] as $pad) {
                $v = (string) ($base[$pad] ?? '');
                if (preg_match('/^(\d+)px$/', $v, $m) === 1 && (int) $m[1] >= 96 && !isset($mobile[$pad])) {
                    $report['warnings'][] = ['path' => $path, 'rule' => 'mobile_spacing', 'message' => sprintf('%s %s has no mobile value; it will eat a phone screen.', $pad, $v)];
                    break;
                }
            }
        }
    }
}

function nibwp_mosaic_pro_page_rules(array $state, array &$report): void
{
    if ($report['stats']['h1'] > 1) {
        $report['warnings'][] = ['path' => '', 'rule' => 'one_h1', 'message' => sprintf('%d h1 headings. A page has one.', $report['stats']['h1'])];
    }
    $prev = 0;
    foreach ($state['headings'] as $h) {
        if ($prev > 0 && $h['level'] > $prev + 1) {
            $report['warnings'][] = ['path' => $h['path'], 'rule' => 'heading_skip', 'message' => sprintf('h%d follows h%d; a level is skipped.', $h['level'], $prev)];
        }
        $prev = $h['level'];
    }
    foreach ($state['styles'] as $group) {
        if (count($group) >= 3) {
            $report['recommendations'][] = [
                'rule'    => 'make_a_class',
                'message' => sprintf('The same style is written on %d elements. Make it one Mosaic class (classes: [...]) so it is edited in one place.', count($group)),
                'paths'   => array_column($group, 'path'),
                'style'   => $group[0]['block'],
            ];
        }
    }
    foreach ($state['colors'] as $color => $paths) {
        if (count($paths) >= 4) {
            $report['recommendations'][] = [
                'rule'    => 'use_a_variable',
                'message' => sprintf('Colour %s is hard-coded %d times. If the theme has a matching variable (nibwp/mosaic-list-design), reference it as {"var": id}.', $color, count($paths)),
            ];
        }
    }
}
