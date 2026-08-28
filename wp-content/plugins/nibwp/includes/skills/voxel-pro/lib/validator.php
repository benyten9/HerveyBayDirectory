<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — check the document against the site it is going to be written to.
 *
 * Everything checked here is checked against something real: Elementor's own
 * widget registry, the post type's own filter and field keys, and — for every
 * dynamic tag — Voxel's renderer running against an actual listing. A tag that
 * does not resolve renders as literal text on the page, which is the one
 * failure a person notices before the agent does.
 */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/relations.php';

/**
 * @return array{passed:bool, failed:array, warnings:array, checked:array, element_count:int, widget_count:int, dtags:array}
 */
function nibwp_voxel_pro_validate(array $elements, array $template, array $relations, array $assign = []): array
{
    $v = [
        'passed'        => true,
        'failed'        => [],
        'warnings'      => [],
        'checked'       => [],
        'element_count' => 0,
        'widget_count'  => 0,
        'dtags'         => [],
    ];

    $kind      = (string) ($template['kind'] ?? '');
    $kinds     = nibwp_voxel_pro_kinds();
    $post_type = sanitize_key((string) ($template['post_type'] ?? ''));

    // ── shape ────────────────────────────────────────────────────────────
    $stats = ['elements' => 0, 'widgets' => 0, 'types' => [], 'ids' => []];
    nibwp_voxel_pro_walk($elements, $stats, $v);
    $v['element_count'] = $stats['elements'];
    $v['widget_count']  = $stats['widgets'];
    nibwp_voxel_pro_ok($v, sprintf('%d elements, %d of them widgets.', $stats['elements'], $stats['widgets']));

    if ($stats['widgets'] === 0) {
        nibwp_voxel_pro_fail($v, 'no_widgets', 'The document has containers but no widgets, so it would render as an empty page.');
    }

    // ── widget types exist on this site ──────────────────────────────────
    $live = nibwp_voxel_pro_live_widget_types();
    if ($live === []) {
        nibwp_voxel_pro_warn($v, 'Elementor\'s widget registry could not be read, so widget names were not verified.');
    } else {
        $unknown = array_values(array_diff(array_keys($stats['types']), $live));
        if ($unknown !== []) {
            $voxel_widgets = array_values(array_filter($live, static fn(string $t): bool => str_starts_with($t, 'ts-') || str_starts_with($t, 'vx-') || $t === 'quick-search'));
            nibwp_voxel_pro_fail(
                $v,
                'unknown_widget',
                sprintf('No such widget on this site: %s. Voxel\'s own widgets here are: %s. Call nibwp/voxel-pro-catalog for the full list.', implode(', ', $unknown), implode(', ', $voxel_widgets))
            );
        } else {
            nibwp_voxel_pro_ok($v, sprintf('All %d widget type(s) exist on this site.', count($stats['types'])));
        }
    }

    // ── required settings ────────────────────────────────────────────────
    $curated = nibwp_voxel_pro_widgets();
    foreach ($stats['types'] as $type => $nodes) {
        foreach ((array) ($curated[$type]['required'] ?? []) as $required) {
            foreach ($nodes as $node) {
                $value = $node['settings'][$required] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    nibwp_voxel_pro_fail(
                        $v,
                        'missing_setting',
                        sprintf('%s (element %s) needs "%s" — %s', $type, $node['id'], $required, $curated[$type]['notes'] ?: 'see nibwp/voxel-pro-catalog.')
                    );
                }
            }
        }
    }

    // ── Voxel keys are real ──────────────────────────────────────────────
    nibwp_voxel_pro_check_voxel_keys($stats['types'], $v);

    // ── card references ──────────────────────────────────────────────────
    nibwp_voxel_pro_check_card_refs($stats['types'], $v);

    // ── relations ────────────────────────────────────────────────────────
    foreach ((array) ($relations['unpaired'] ?? []) as $bad) {
        nibwp_voxel_pro_fail($v, 'relation_unknown_element', sprintf('%s pairs %s with %s, but %s', $bad['group'], $bad['left'], $bad['right'], $bad['reason']));
    }
    $has_search = isset($stats['types']['ts-search-form']);
    $wired      = count((array) $relations['feedToSearch']) + count((array) $relations['mapToSearch']);
    $needs_wire = 0;
    foreach (['ts-post-feed', 'ts-map'] as $type) {
        foreach ((array) ($stats['types'][$type] ?? []) as $node) {
            $source = (string) ($node['settings']['ts_source'] ?? 'search-form');
            if ($type === 'ts-map' || $source === '' || $source === 'search-form') {
                $needs_wire++;
            }
        }
    }
    if ($needs_wire > 0 && !$has_search) {
        nibwp_voxel_pro_fail(
            $v,
            'feed_without_search',
            sprintf('%d feed/map widget(s) are sourced from a search form, but the document has no ts-search-form. Add one, or set ts_source to "search-filters", "manual" or "archive".', $needs_wire)
        );
    } elseif ($needs_wire > 0 && $wired < $needs_wire) {
        nibwp_voxel_pro_fail(
            $v,
            'unwired_feed',
            sprintf('%d of %d feed/map widget(s) are not connected to a search form. Voxel keeps that connection in post meta, so an unconnected feed silently shows nothing.', $needs_wire - $wired, $needs_wire)
        );
    } elseif ($wired > 0) {
        nibwp_voxel_pro_ok($v, sprintf('%d search connection(s) resolved and their paths computed.', $wired));
    }

    // ── dynamic tags render ──────────────────────────────────────────────
    nibwp_voxel_pro_check_dtags($elements, $post_type, $v);

    // ── kind constraints ─────────────────────────────────────────────────
    if (($kinds[$kind]['needs_post_type'] ?? false) && $post_type === '') {
        nibwp_voxel_pro_fail($v, 'missing_post_type', sprintf('A %s belongs to a post type. Pass template.post_type — this site has: %s.', $kind, implode(', ', array_keys((array) \Voxel\get('post_types', [])))));
    } elseif ($post_type !== '' && !\Voxel\Post_Type::get($post_type)) {
        nibwp_voxel_pro_fail($v, 'unknown_post_type', sprintf('No Voxel post type "%s". This site has: %s.', $post_type, implode(', ', array_keys((array) \Voxel\get('post_types', [])))));
    }

    $kit_widget = nibwp_voxel_pro_kit_widget($kind);
    if ($kit_widget !== '') {
        $only = array_keys($stats['types']);
        if ($only !== [$kit_widget]) {
            nibwp_voxel_pro_fail(
                $v,
                'kit_content',
                sprintf('A %s holds exactly one %s widget and nothing else — its whole job is to carry style settings. This document has: %s.', $kind, $kit_widget, $only === [] ? 'no widgets' : implode(', ', $only))
            );
        }
    }
    if ($kind === 'card') {
        foreach (['ts-search-form', 'ts-map'] as $forbidden) {
            if (isset($stats['types'][$forbidden])) {
                nibwp_voxel_pro_fail($v, 'card_content', sprintf('A preview card renders once per result, so it cannot contain a %s.', $forbidden));
            }
        }
    }

    // ── assignment ───────────────────────────────────────────────────────
    nibwp_voxel_pro_check_assign($assign, $kind, $v);

    // ── round trip ───────────────────────────────────────────────────────
    $json = wp_json_encode($elements);
    if ($json === false) {
        nibwp_voxel_pro_fail($v, 'not_serializable', 'The document could not be encoded as JSON — usually a non-UTF-8 string somewhere in the settings.');
    } else {
        $back = json_decode($json, true);
        $re   = ['elements' => 0, 'widgets' => 0, 'types' => [], 'ids' => []];
        $sink = ['failed' => [], 'warnings' => [], 'passed' => true, 'checked' => []];
        nibwp_voxel_pro_walk((array) $back, $re, $sink);
        if ($re['elements'] !== $stats['elements']) {
            nibwp_voxel_pro_fail($v, 'roundtrip', sprintf('Encoding the document loses elements (%d in, %d out). Nothing will be written.', $stats['elements'], $re['elements']));
        } else {
            nibwp_voxel_pro_ok($v, 'Round-trips through JSON without losing an element.');
        }
        if (strlen($json) > 1500000) {
            nibwp_voxel_pro_warn($v, sprintf('The document is %s — large enough to slow the editor down.', size_format(strlen($json))));
        }
    }

    return $v;
}

/** Walk the tree, collecting counts and grouping widget nodes by type. */
function nibwp_voxel_pro_walk(array $nodes, array &$stats, array &$v): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            nibwp_voxel_pro_fail($v, 'bad_node', 'One entry in the tree is not an object.');
            continue;
        }
        $stats['elements']++;
        $id = (string) ($node['id'] ?? '');
        if (isset($stats['ids'][$id])) {
            nibwp_voxel_pro_fail($v, 'duplicate_id', sprintf('Element id %s appears twice. Elementor keys its generated CSS off these.', $id));
        }
        $stats['ids'][$id] = true;

        $el_type = (string) ($node['elType'] ?? '');
        if (!in_array($el_type, ['container', 'widget', 'section', 'column'], true)) {
            nibwp_voxel_pro_fail($v, 'bad_eltype', sprintf('Element %s has elType "%s". Use container, widget, section or column.', $id, $el_type));
        }
        if ($el_type === 'widget') {
            $stats['widgets']++;
            $type = (string) ($node['widgetType'] ?? '');
            if ($type === '') {
                nibwp_voxel_pro_fail($v, 'missing_widgettype', sprintf('Element %s is a widget with no widgetType.', $id));
            } else {
                $stats['types'][$type][] = $node;
            }
        }
        if (!empty($node['elements'])) {
            nibwp_voxel_pro_walk((array) $node['elements'], $stats, $v);
        }
    }
}

/** Filter keys, taxonomies and post types named in settings have to exist here. */
function nibwp_voxel_pro_check_voxel_keys(array $types, array &$v): void
{
    $known_types = array_keys((array) \Voxel\get('post_types', []));

    foreach ((array) ($types['ts-search-form'] ?? []) as $node) {
        $chosen = (array) ($node['settings']['ts_choose_post_types'] ?? []);
        foreach ($chosen as $key) {
            $key = (string) $key;
            if (!in_array($key, $known_types, true)) {
                nibwp_voxel_pro_fail($v, 'unknown_post_type', sprintf('The search form (element %s) points at post type "%s", which this site does not have. It has: %s.', $node['id'], $key, implode(', ', $known_types)));
                continue;
            }
            $pt = \Voxel\Post_Type::get($key);
            $valid = $pt ? array_keys((array) $pt->get_filters()) : [];
            $rows  = (array) ($node['settings']['ts_filter_list__' . $key] ?? []);
            if ($rows === []) {
                nibwp_voxel_pro_warn($v, sprintf('The search form offers "%s" but lists no filters for it (ts_filter_list__%s is empty), so that tab will be blank.', $key, $key));
            }
            foreach ($rows as $row) {
                $filter = (string) ($row['ts_choose_filter'] ?? '');
                if ($filter === '') {
                    nibwp_voxel_pro_fail($v, 'filter_missing_key', sprintf('A filter row in ts_filter_list__%s has no ts_choose_filter.', $key));
                } elseif (!in_array($filter, $valid, true)) {
                    nibwp_voxel_pro_fail($v, 'unknown_filter', sprintf('"%s" is not a filter on the %s post type. Its filters are: %s.', $filter, $key, $valid === [] ? '(none configured)' : implode(', ', $valid)));
                }
            }
        }
    }

    foreach ((array) ($types['ts-create-post'] ?? []) as $node) {
        $key = (string) ($node['settings']['ts_post_type'] ?? '');
        if ($key !== '' && !in_array($key, $known_types, true)) {
            nibwp_voxel_pro_fail($v, 'unknown_post_type', sprintf('The create-post form (element %s) points at post type "%s". This site has: %s.', $node['id'], $key, implode(', ', $known_types)));
        }
    }

    foreach ((array) ($types['ts-term-feed'] ?? []) as $node) {
        $tax = (string) ($node['settings']['ts_choose_taxonomy'] ?? '');
        if ($tax !== '' && !taxonomy_exists($tax)) {
            nibwp_voxel_pro_fail($v, 'unknown_taxonomy', sprintf('The term feed (element %s) points at taxonomy "%s", which is not registered.', $node['id'], $tax));
        }
    }

    foreach ((array) ($types['ts-work-hours'] ?? []) as $node) {
        $field = (string) ($node['settings']['ts_source_field'] ?? '');
        if ($field === '') {
            continue;
        }
        $found = false;
        foreach ($known_types as $key) {
            $pt = \Voxel\Post_Type::get($key);
            if ($pt && array_key_exists($field, (array) $pt->get_fields())) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            nibwp_voxel_pro_warn($v, sprintf('The work-hours widget reads field "%s", which no post type on this site defines.', $field));
        }
    }
}

/**
 * ts_card_template__* should name "main" or a card template that exists.
 *
 * A warning rather than a failure: a missing card template makes the feed fall
 * back to the post type's main card, so the page still works. Templates that
 * have been around a while accumulate these — the demo content ships with one
 * — and refusing to touch a document because of a reference nobody added today
 * would make the refine ability useless.
 */
function nibwp_voxel_pro_check_card_refs(array $types, array &$v): void
{
    foreach ($types as $nodes) {
        foreach ($nodes as $node) {
            foreach ((array) $node['settings'] as $key => $value) {
                if (!is_string($key) || !str_starts_with($key, 'ts_card_template')) {
                    continue;
                }
                $value = (string) $value;
                if ($value === '' || $value === 'main') {
                    continue;
                }
                $post = get_post((int) $value);
                if (!$post || $post->post_type !== 'elementor_library') {
                    nibwp_voxel_pro_warn($v, sprintf('%s = %s, but no template has that id — the feed will fall back to the post type\'s main card. Use "main", or an id from nibwp/voxel-pro-catalog { topic:"slots" }.', $key, $value));
                }
            }
        }
    }
}

/** Every dynamic tag, rendered against a real listing. */
function nibwp_voxel_pro_check_dtags(array $elements, string $post_type, array &$v): void
{
    $tags = [];
    nibwp_voxel_pro_collect_dtags($elements, $tags);
    if ($tags === []) {
        return;
    }

    $groups = nibwp_voxel_pro_render_groups($post_type);
    if ($groups === null) {
        nibwp_voxel_pro_warn($v, sprintf('No published %s to test dynamic tags against, so %d tag(s) went unverified. Create one listing and rebuild to have them checked.', $post_type !== '' ? $post_type : 'post', count($tags)));
        return;
    }

    nibwp_voxel_pro_check_dtag_properties($tags, $groups, $v);

    $unresolved = [];
    $empty      = [];
    foreach ($tags as $where => $tag) {
        try {
            $out = (string) \Voxel\render($tag, $groups);
        } catch (\Throwable $e) {
            $unresolved[] = sprintf('%s — %s (%s)', $where, $tag, $e->getMessage());
            continue;
        }
        $v['dtags'][] = ['where' => $where, 'tag' => $tag, 'renders_as' => $out];
        if (preg_match('/@(?:tags|post|user|site|term|author|value)\s*\(/', $out) === 1) {
            $unresolved[] = sprintf('%s — %s rendered as "%s"', $where, $tag, $out);
        } elseif (trim($out) === '') {
            $empty[] = sprintf('%s — %s', $where, $tag);
        }
    }

    if ($unresolved !== []) {
        nibwp_voxel_pro_fail(
            $v,
            'dtag_unresolved',
            sprintf('%d dynamic tag(s) do not resolve on this site and would print as literal text: %s', count($unresolved), implode(' | ', array_slice($unresolved, 0, 8)))
        );
    }
    if ($empty !== []) {
        nibwp_voxel_pro_warn(
            $v,
            sprintf('%d tag(s) render empty against the test listing — correct if the field is optional, wrong if the key is: %s', count($empty), implode(' | ', array_slice($empty, 0, 8)))
        );
    }
    if ($unresolved === [] && $empty === []) {
        nibwp_voxel_pro_ok($v, sprintf('All %d dynamic tag(s) render against a real listing.', count($tags)));
    }
}

/**
 * Check the property each tag names against the group's own vocabulary.
 *
 * This is the check that matters, because Voxel renders a property it does not
 * recognize as an empty string — exactly what a real but unset field renders
 * as. Without this, a misspelled field key produces a card with a blank
 * heading and a validation pass, and nobody finds out until someone looks at
 * the page.
 */
function nibwp_voxel_pro_check_dtag_properties(array $tags, array $groups, array &$v): void
{
    $vocab = [];
    foreach ($groups as $key => $group) {
        if (!is_object($group) || !method_exists($group, 'get_properties')) {
            continue;
        }
        try {
            $names = array_keys((array) $group->get_properties());
            if (method_exists($group, 'get_aliases')) {
                $names = array_merge($names, array_keys((array) $group->get_aliases()));
            }
            if (method_exists($group, 'get_methods')) {
                $names = array_merge($names, array_keys((array) $group->get_methods()));
            }
            $vocab[(string) $key] = $names;
        } catch (\Throwable $e) {
            continue;
        }
    }
    if ($vocab === []) {
        return;
    }

    $unknown = [];
    foreach ($tags as $where => $tag) {
        if (preg_match_all('/@(post|user|site|term|author)\(([^)]*)\)/', (string) $tag, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $hit) {
                $group = $hit[1];
                $path  = trim($hit[2]);
                if ($path === '' || !isset($vocab[$group])) {
                    continue; // A bare @group() is a method call target, not a property.
                }
                $first = preg_split('/[.\[]/', $path)[0] ?? '';
                if ($first === '' || in_array($first, $vocab[$group], true)) {
                    continue;
                }
                $unknown[$group . '(' . $first . ')'] = sprintf(
                    '%s names "%s" on @%s(), which has no such property. Its properties are: %s.',
                    $where,
                    $first,
                    $group,
                    implode(', ', array_slice($vocab[$group], 0, 40))
                );
            }
        }
    }

    if ($unknown !== []) {
        nibwp_voxel_pro_fail(
            $v,
            'dtag_unknown_property',
            sprintf('%d dynamic tag(s) name a property that does not exist, and Voxel renders those as nothing at all rather than as an error: %s', count($unknown), implode(' | ', array_slice(array_values($unknown), 0, 4)))
        );
    } else {
        nibwp_voxel_pro_ok($v, sprintf('Every property named by a dynamic tag exists in its group (%d tag(s)).', count($tags)));
    }
}

/** Default render groups with the post group bound to a real listing. */
function nibwp_voxel_pro_render_groups(string $post_type): ?array
{
    $args = ['post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'];
    if ($post_type !== '') {
        $args['post_type'] = $post_type;
    }
    $ids = get_posts($args);
    if ($ids === []) {
        return null;
    }
    try {
        $post = \Voxel\Post::get((int) $ids[0]);
        if ($post === null) {
            return null;
        }
        $groups = function_exists('\\Voxel\\_get_default_render_groups') ? (array) \Voxel\_get_default_render_groups() : [];
        $groups['post'] = \Voxel\Dynamic_Data\Group::Post($post);
        return $groups;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Pull every dynamic-tag-bearing string out of the tree, with where it came from. */
function nibwp_voxel_pro_collect_dtags(array $nodes, array &$tags, string $trail = ''): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $where = ($trail === '' ? '' : $trail . ' › ') . (string) ($node['widgetType'] ?? $node['elType'] ?? 'element') . ' ' . (string) ($node['id'] ?? '');
        nibwp_voxel_pro_collect_dtags_in($node['settings'] ?? [], $tags, $where, '');
        if (!empty($node['elements'])) {
            nibwp_voxel_pro_collect_dtags((array) $node['elements'], $tags, $where);
        }
    }
}

function nibwp_voxel_pro_collect_dtags_in($value, array &$tags, string $where, string $key_path): void
{
    if (is_string($value)) {
        if (str_contains($value, '@tags(') || preg_match('/@(?:post|user|site|term|author)\s*\(/', $value) === 1) {
            $tags[$where . ' [' . $key_path . ']'] = $value;
        }
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $k => $child) {
        nibwp_voxel_pro_collect_dtags_in($child, $tags, $where, $key_path === '' ? (string) $k : $key_path . '.' . $k);
    }
}

/** The assign block has to name a slot this site actually has. */
function nibwp_voxel_pro_check_assign(array $assign, string $kind, array &$v): void
{
    if ($assign === []) {
        return;
    }
    $slot = (string) ($assign['slot'] ?? '');
    if ($slot !== '') {
        $slots = nibwp_voxel_pro_site_slots();
        if (!isset($slots[$slot])) {
            nibwp_voxel_pro_fail($v, 'unknown_slot', sprintf('"%s" is not a site slot. Available: %s.', $slot, implode(', ', array_keys($slots))));
        } elseif (in_array($slot, ['kit_popups', 'kit_timeline'], true) && empty($assign['confirm_kit'])) {
            nibwp_voxel_pro_fail(
                $v,
                'kit_needs_confirmation',
                sprintf('Assigning %s restyles every %s on the site, not just this template. Tell the user what changes, then pass assign.confirm_kit:true.', $slot, $slot === 'kit_popups' ? 'popup' : 'timeline')
            );
        }
    }

    $pt = sanitize_key((string) ($assign['post_type'] ?? ''));
    if ($pt !== '') {
        if (!\Voxel\Post_Type::get($pt)) {
            nibwp_voxel_pro_fail($v, 'unknown_post_type', sprintf('Cannot assign to post type "%s" — it does not exist here.', $pt));
        }
        $pt_slot = (string) ($assign['pt_slot'] ?? '');
        if ($pt_slot !== '' && !in_array($pt_slot, ['single', 'card', 'archive', 'form'], true)) {
            nibwp_voxel_pro_fail($v, 'unknown_pt_slot', sprintf('"%s" is not a post type slot. Use single, card, archive or form.', $pt_slot));
        }
        if ((string) ($assign['mode'] ?? 'main') === 'custom' && $pt_slot !== 'card') {
            nibwp_voxel_pro_fail($v, 'custom_needs_card', 'Named alternates exist for cards only. Use pt_slot:"card" with mode:"custom", or mode:"main".');
        }
        if ((string) ($assign['mode'] ?? '') === 'custom' && (string) ($assign['label'] ?? '') === '') {
            nibwp_voxel_pro_fail($v, 'custom_needs_label', 'A named alternate card needs a label — that is how someone picks it in a feed later.');
        }
    }

    if ($slot === '' && $pt === '' && (string) ($assign['taxonomy'] ?? '') === '') {
        nibwp_voxel_pro_warn($v, 'The assign block names neither a site slot nor a post type, so nothing will be assigned.');
    }
    if ($kind === 'style-kit-popup' && $slot !== '' && $slot !== 'kit_popups') {
        nibwp_voxel_pro_warn($v, sprintf('A popup style kit assigned to "%s" will not style anything — it belongs in kit_popups.', $slot));
    }
}

function nibwp_voxel_pro_fail(array &$v, string $code, string $message): void
{
    $v['passed'] = false;
    $v['failed'][] = ['code' => $code, 'message' => $message];
}

function nibwp_voxel_pro_warn(array &$v, string $message): void
{
    $v['warnings'][] = $message;
}

function nibwp_voxel_pro_ok(array &$v, string $message): void
{
    $v['checked'][] = $message;
}
