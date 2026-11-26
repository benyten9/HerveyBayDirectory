<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks data in and out of a page.
 *
 * Placing follows the rules Bricksed's own builder insert follows, so a
 * component placed by an agent is indistinguishable from one a person added
 * from the Bricksed panel:
 *
 *  - every element gets a fresh id, unique in the area it lands in;
 *  - a global class the site already has (same id or same name) is used as
 *    the site has it, never overwritten — restyling a class here would
 *    restyle every page that uses it; only missing classes are added;
 *  - a Bricks component the site already has is kept as it is, with its
 *    edits; only missing ones are added;
 *  - a CSS id that is already on the page is dropped, as Bricks does on paste.
 *
 * Copying off a page is Bricksed's "copy" in reverse: the element, its
 * descendants, the global classes they use and the components they are
 * instances of — the same shape the Bricksed panel saves.
 */

const NIBWP_BRICKSED_PLACEMENTS_META = '_nibwp_bricksed_placements';
const NIBWP_BRICKSED_REUSE_MODES = ['append', 'replace_section', 'new_page'];

function nibwp_bricksed_components_option(): string
{
    return defined('BRICKS_DB_COMPONENTS') ? (string) BRICKS_DB_COMPONENTS : 'bricks_components';
}

/** Where a post keeps its Bricks tree: header and footer templates have their own keys. */
function nibwp_bricksed_area_key(int $post_id): string
{
    $type = (string) get_post_meta($post_id, '_bricks_template_type', true);

    return $type === 'header' ? '_bricks_page_header_2' : ($type === 'footer' ? '_bricks_page_footer_2' : '_bricks_page_content_2');
}

/**
 * A fresh Bricks element id: six lowercase letters and digits, starting with a
 * letter, unused in $used (which it then joins).
 *
 * @param array<string, bool> $used
 */
function nibwp_bricksed_fresh_id(array &$used): string
{
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $chars = $letters . '0123456789';
    do {
        $id = $letters[random_int(0, 25)];
        for ($i = 0; $i < 5; $i++) {
            $id .= $chars[random_int(0, 35)];
        }
    } while (isset($used[$id]));
    $used[$id] = true;

    return $id;
}

/**
 * Join the payload's global classes to the site's.
 *
 * @param array<int, mixed> $classes    Payload classes.
 * @param array<int, mixed> $categories Payload class categories (added when a new class needs one).
 * @return array{map: array<string, string>, known: array<string, bool>, added: array<int, string>}
 */
function nibwp_bricksed_merge_classes(array $classes, array $categories = []): array
{
    $all = get_option('bricks_global_classes', []);
    $all = is_array($all) ? array_values($all) : [];

    $by_id = [];
    $by_name = [];
    foreach ($all as $class) {
        if (is_array($class) && isset($class['id'], $class['name'])) {
            $by_id[(string) $class['id']] = (string) $class['id'];
            $by_name[(string) $class['name']] = (string) $class['id'];
        }
    }

    $map = [];
    $added = [];
    $wanted_categories = [];
    foreach ($classes as $class) {
        if (!is_array($class) || empty($class['id']) || empty($class['name'])) {
            continue;
        }
        $id = (string) $class['id'];
        $name = (string) $class['name'];
        if (isset($by_id[$id])) {
            $map[$id] = $id;
            continue;
        }
        if (isset($by_name[$name])) {
            $map[$id] = $by_name[$name];
            continue;
        }
        // Bricks reads id, name and settings without checking they exist.
        $class['settings'] = is_array($class['settings'] ?? null) ? $class['settings'] : [];
        $class['modified'] = time();
        $class['user_id'] = get_current_user_id();
        $all[] = $class;
        $by_id[$id] = $id;
        $by_name[$name] = $id;
        $map[$id] = $id;
        $added[] = $name;
        if (!empty($class['category'])) {
            $wanted_categories[(string) $class['category']] = true;
        }
    }

    if ($added !== []) {
        update_option('bricks_global_classes', $all, false);
        update_option('bricks_global_classes_timestamp', time(), false);

        if ($wanted_categories !== []) {
            $site_categories = get_option('bricks_global_classes_categories', []);
            $site_categories = is_array($site_categories) ? array_values($site_categories) : [];
            $have = [];
            foreach ($site_categories as $category) {
                if (is_array($category) && isset($category['id'])) {
                    $have[(string) $category['id']] = true;
                }
            }
            $changed = false;
            foreach ($categories as $category) {
                if (is_array($category) && isset($category['id']) && isset($wanted_categories[(string) $category['id']]) && !isset($have[(string) $category['id']])) {
                    $site_categories[] = $category;
                    $have[(string) $category['id']] = true;
                    $changed = true;
                }
            }
            if ($changed) {
                update_option('bricks_global_classes_categories', $site_categories, false);
            }
        }
    }

    return ['map' => $map, 'known' => array_fill_keys(array_values($by_id), true), 'added' => $added];
}

/**
 * Point an element's classes at the ones the site really has, and drop the
 * ones it has not got (a reference to nothing only confuses the editor).
 *
 * @param array<string, mixed>  $element
 * @param array<string, string> $map
 * @param array<string, bool>   $known
 * @return array<string, mixed>
 */
function nibwp_bricksed_fix_classes(array $element, array $map, array $known): array
{
    if (!isset($element['settings']['_cssGlobalClasses']) || !is_array($element['settings']['_cssGlobalClasses'])) {
        return $element;
    }

    $ids = [];
    foreach ($element['settings']['_cssGlobalClasses'] as $id) {
        $id = (string) ($map[(string) $id] ?? $id);
        if (isset($known[$id]) && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        unset($element['settings']['_cssGlobalClasses']);
    } else {
        $element['settings']['_cssGlobalClasses'] = $ids;
    }

    return $element;
}

/**
 * Add the components the site lacks; keep the ones it has.
 *
 * @param array<int, mixed>     $components
 * @param array<string, string> $map
 * @param array<string, bool>   $known
 * @return array<int, string> Ids added.
 */
function nibwp_bricksed_merge_components(array $components, array $map, array $known): array
{
    if ($components === []) {
        return [];
    }

    $option = nibwp_bricksed_components_option();
    $site = get_option($option, []);
    $site = is_array($site) ? array_values($site) : [];
    $have = [];
    foreach ($site as $component) {
        if (is_array($component) && isset($component['id'])) {
            $have[(string) $component['id']] = true;
        }
    }

    $added = [];
    foreach ($components as $component) {
        if (!is_array($component) || empty($component['id']) || isset($have[(string) $component['id']])) {
            continue;
        }
        foreach ((array) ($component['elements'] ?? []) as $i => $element) {
            if (is_array($element)) {
                $component['elements'][$i] = nibwp_bricksed_fix_classes($element, $map, $known);
            }
        }
        $site[] = $component;
        $have[(string) $component['id']] = true;
        $added[] = (string) $component['id'];
    }

    if ($added !== []) {
        update_option($option, $site, false);
    }

    return $added;
}

/**
 * Structural checks a placement needs: every element has an id and a name,
 * and every parent and child it names is in the payload.
 *
 * @param array<int, mixed> $content
 */
function nibwp_bricksed_check_tree(array $content): ?WP_Error
{
    $ids = [];
    foreach ($content as $i => $element) {
        if (!is_array($element) || empty($element['id']) || empty($element['name'])) {
            return new WP_Error('bricksed_invalid_tree', sprintf(
                /* translators: %d: element index */
                __('Element %d has no id or no name, so Bricks could not show it. Nothing was placed.', 'nibwp'),
                $i
            ), ['status' => 422]);
        }
        $ids[(string) $element['id']] = true;
    }

    foreach ($content as $element) {
        $parent = $element['parent'] ?? 0;
        if ($parent && !isset($ids[(string) $parent])) {
            return new WP_Error('bricksed_invalid_tree', sprintf(
                /* translators: 1: element id, 2: parent id */
                __('Element %1$s names a parent (%2$s) that is not in the component. Nothing was placed.', 'nibwp'),
                (string) $element['id'],
                (string) $parent
            ), ['status' => 422]);
        }
        foreach ((array) ($element['children'] ?? []) as $child) {
            if (!isset($ids[(string) $child])) {
                return new WP_Error('bricksed_invalid_tree', sprintf(
                    /* translators: 1: element id, 2: child id */
                    __('Element %1$s names a child (%2$s) that is not in the component. Nothing was placed.', 'nibwp'),
                    (string) $element['id'],
                    (string) $child
                ), ['status' => 422]);
            }
        }
    }

    // Element names Bricks does not know render as nothing. Checked only when
    // Bricks says which it knows.
    if (class_exists('\Bricks\Elements') && property_exists('\Bricks\Elements', 'elements') && is_array(\Bricks\Elements::$elements) && \Bricks\Elements::$elements !== []) {
        foreach ($content as $element) {
            if (empty($element['cid']) && !isset(\Bricks\Elements::$elements[(string) $element['name']])) {
                return new WP_Error('bricksed_unknown_element', sprintf(
                    /* translators: %s: element name */
                    __('The component uses a Bricks element this site does not have ("%s"). Activate the plugin that provides it, then try again. Nothing was placed.', 'nibwp'),
                    (string) $element['name']
                ), ['status' => 422]);
            }
        }
    }

    return null;
}

/**
 * Every descendant id of the given roots in an element list, roots included.
 *
 * @param array<int, array<string, mixed>> $elements
 * @param array<int, string>               $roots
 * @return array<string, bool>
 */
function nibwp_bricksed_subtree_ids(array $elements, array $roots): array
{
    $children = [];
    foreach ($elements as $element) {
        $children[(string) $element['id']] = array_map('strval', (array) ($element['children'] ?? []));
    }

    $out = [];
    $stack = $roots;
    while ($stack !== []) {
        $id = (string) array_pop($stack);
        if (isset($out[$id]) || !isset($children[$id])) {
            continue;
        }
        $out[$id] = true;
        foreach ($children[$id] as $child) {
            $stack[] = $child;
        }
    }

    return $out;
}

/**
 * Place Bricks data on a page.
 *
 * @param array<string, mixed> $bricks    From nibwp_bricksed_bricks_of(), overrides applied.
 * @param array<string, mixed> $target    post_id, mode, new_page_title, new_page_type, switch_editor.
 * @param string               $source_id The library id, so a later replace_section finds this placement.
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_place(array $bricks, array $target, string $source_id)
{
    if (!defined('BRICKS_VERSION')) {
        return new WP_Error('bricks_inactive', __('Bricks is not the active theme on this site, so a Bricks component has nowhere to render. Activate Bricks, then try again.', 'nibwp'), ['status' => 409]);
    }

    $content = array_values((array) ($bricks['content'] ?? []));
    if ($content === []) {
        return nibwp_bricksed_empty_block_error();
    }

    $components = array_values((array) ($bricks['components'] ?? []));
    if ($components !== [] && version_compare((string) BRICKS_VERSION, '2.0', '<')) {
        return new WP_Error('bricksed_needs_bricks_2', sprintf(
            /* translators: %s: Bricks version */
            __('This component is built with Bricks components, which need Bricks 2.0 or newer. This site runs Bricks %s. Update Bricks, then try again. Nothing was placed.', 'nibwp'),
            (string) BRICKS_VERSION
        ), ['status' => 409]);
    }

    $tree = nibwp_bricksed_check_tree($content);
    if ($tree !== null) {
        return $tree;
    }

    $mode = (string) ($target['mode'] ?? 'append');
    $post_id = (int) ($target['post_id'] ?? 0);
    $created = false;

    if ($mode === 'new_page') {
        $title = trim((string) ($target['new_page_title'] ?? ''));
        $post_type = (string) ($target['new_page_type'] ?? 'page');
        if (!post_type_exists($post_type)) {
            return new WP_Error('bricksed_invalid_post_type', sprintf(
                /* translators: %s: post type */
                __('There is no post type "%s" on this site.', 'nibwp'),
                $post_type
            ), ['status' => 400]);
        }
        $post_id = wp_insert_post(wp_slash([
            'post_type' => $post_type,
            'post_title' => $title !== '' ? $title : __('New page', 'nibwp'),
            'post_status' => 'draft',
        ]), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $post_id = (int) $post_id;
        $created = true;
    } else {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('bricksed_target_missing', sprintf(
                /* translators: %d: post id */
                __('There is no post %d on this site.', 'nibwp'),
                $post_id
            ), ['status' => 404]);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('rest_forbidden', __('You are not allowed to edit that page.', 'nibwp'), ['status' => 403]);
        }
    }

    $key = nibwp_bricksed_area_key($post_id);
    $elements = get_post_meta($post_id, $key, true);
    $elements = is_array($elements) ? array_values($elements) : [];

    // A page built in the WordPress editor shows its own content, not Bricks'.
    // Switching it over hides that content, so it is the user's call.
    if (!$created && $elements === [] && $key === '_bricks_page_content_2'
        && get_post_meta($post_id, '_bricks_editor_mode', true) !== 'bricks'
        && trim((string) get_post_field('post_content', $post_id)) !== ''
        && empty($target['switch_editor'])) {
        return new WP_Error('bricksed_page_uses_wordpress_editor', __('This page is built with the WordPress editor and has content. Placing a Bricks component switches it to Bricks, which hides that content. Ask the user; to go ahead, run again with switch_editor true — or place it on a new page.', 'nibwp'), ['status' => 409]);
    }

    $classes = nibwp_bricksed_merge_classes((array) ($bricks['globalClasses'] ?? []), (array) ($bricks['globalClassesCategories'] ?? []));
    $components_added = nibwp_bricksed_merge_components($components, $classes['map'], $classes['known']);

    // Fresh ids, unique in the area.
    $used = [];
    $css_ids = [];
    foreach ($elements as $element) {
        if (is_array($element) && isset($element['id'])) {
            $used[(string) $element['id']] = true;
            if (!empty($element['settings']['_cssId'])) {
                $css_ids[(string) $element['settings']['_cssId']] = true;
            }
        }
    }
    $ids = [];
    foreach ($content as $element) {
        $ids[(string) $element['id']] = nibwp_bricksed_fresh_id($used);
    }

    $fresh = [];
    foreach ($content as $source) {
        $element = $source;
        $element['id'] = $ids[(string) $source['id']];
        $parent = (string) ($source['parent'] ?? '');
        $element['parent'] = $parent !== '' && $parent !== '0' && isset($ids[$parent]) ? $ids[$parent] : 0;
        $element['children'] = array_values(array_filter(array_map(static function ($child) use ($ids) {
            return $ids[(string) $child] ?? null;
        }, (array) ($source['children'] ?? []))));
        $element['settings'] = isset($element['settings']) && is_array($element['settings']) && !nibwp_bricksed_is_list($element['settings']) ? $element['settings'] : [];
        $element = nibwp_bricksed_fix_classes($element, $classes['map'], $classes['known']);
        // Slot ids belong to the component; the children in a slot are new elements.
        if (isset($element['slotChildren']) && is_array($element['slotChildren'])) {
            foreach ($element['slotChildren'] as $slot => $slot_children) {
                $element['slotChildren'][$slot] = array_values(array_filter(array_map(static function ($child) use ($ids) {
                    return $ids[(string) $child] ?? null;
                }, (array) $slot_children)));
            }
        }
        if (!empty($element['settings']['_cssId']) && isset($css_ids[(string) $element['settings']['_cssId']])) {
            unset($element['settings']['_cssId']);
        }
        $fresh[] = $element;
    }
    $roots = array_values(array_filter($fresh, static function (array $element): bool {
        return empty($element['parent']);
    }));
    $root_ids = array_map(static function (array $element): string {
        return (string) $element['id'];
    }, $roots);

    $placements = get_post_meta($post_id, NIBWP_BRICKSED_PLACEMENTS_META, true);
    $placements = is_array($placements) ? $placements : [];

    // replace_section: where THIS component was placed on this page before.
    $replaced = false;
    $at = count($elements);
    $previous = $mode === 'replace_section' ? array_map('strval', (array) ($placements[$source_id] ?? [])) : [];
    $present = [];
    foreach ($elements as $element) {
        $present[(string) $element['id']] = $element;
    }
    $previous = array_values(array_filter($previous, static function (string $id) use ($present): bool {
        return isset($present[$id]);
    }));

    if ($previous !== []) {
        $first = $present[$previous[0]];
        $parent = (string) ($first['parent'] ?? '');
        $gone = nibwp_bricksed_subtree_ids($elements, $previous);

        foreach ($elements as $i => $element) {
            if ((string) $element['id'] === $previous[0]) {
                $at = $i;
                break;
            }
        }
        $at -= count(array_filter(array_slice($elements, 0, $at), static function ($element) use ($gone): bool {
            return isset($gone[(string) $element['id']]);
        }));
        $elements = array_values(array_filter($elements, static function ($element) use ($gone): bool {
            return !isset($gone[(string) $element['id']]);
        }));

        // Nested: the new roots take the old ones' place among their siblings.
        if ($parent !== '' && $parent !== '0') {
            foreach ($elements as $i => $element) {
                if ((string) $element['id'] !== $parent) {
                    continue;
                }
                $kids = array_map('strval', (array) ($element['children'] ?? []));
                $pos = array_search($previous[0], $kids, true);
                $kids = array_values(array_filter($kids, static function (string $kid) use ($gone): bool {
                    return !isset($gone[$kid]);
                }));
                array_splice($kids, $pos === false ? count($kids) : (int) $pos, 0, $root_ids);
                $elements[$i]['children'] = $kids;
            }
            foreach ($fresh as $i => $element) {
                if (in_array((string) $element['id'], $root_ids, true)) {
                    $fresh[$i]['parent'] = $parent;
                }
            }
        }
        $replaced = true;
    }

    array_splice($elements, $at, 0, $fresh);

    update_post_meta($post_id, $key, wp_slash($elements));
    if ($key === '_bricks_page_content_2') {
        update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
    }
    $placements[$source_id] = $root_ids;
    update_post_meta($post_id, NIBWP_BRICKSED_PLACEMENTS_META, $placements);

    if (class_exists('\Bricks\Assets_Files') && method_exists('\Bricks\Assets_Files', 'generate_post_css_file')) {
        $area = $key === '_bricks_page_header_2' ? 'header' : ($key === '_bricks_page_footer_2' ? 'footer' : 'content');
        \Bricks\Assets_Files::generate_post_css_file($post_id, $area, $elements);
    }

    $url = get_post_status($post_id) === 'publish' ? (string) get_permalink($post_id) : (string) get_preview_post_link($post_id);

    return [
        'post_id' => $post_id,
        'created' => $created,
        'mode' => $mode,
        'replaced' => $replaced,
        'area' => $key,
        'roots' => $root_ids,
        'elements' => count($fresh),
        'classes_added' => $classes['added'],
        'components_added' => $components_added,
        'url' => $url,
        'edit_url' => add_query_arg('bricks', 'run', (string) get_permalink($post_id)),
    ];
}

/**
 * Whether an array is a list. Bricks keeps empty settings as [] — a list —
 * and filled ones as a map; a list in place of settings is treated as empty.
 *
 * @param array<mixed> $value
 */
function nibwp_bricksed_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/**
 * Copy a built section off a page, the way the Bricksed panel copies one.
 *
 * @return array{content: array<int, mixed>, globalClasses: array<int, mixed>, components?: array<int, mixed>}|WP_Error
 */
function nibwp_bricksed_extract(int $post_id, string $element_id)
{
    if (!get_post($post_id)) {
        return new WP_Error('bricksed_source_missing', sprintf(
            /* translators: %d: post id */
            __('There is no post %d on this site.', 'nibwp'),
            $post_id
        ), ['status' => 404]);
    }

    $found = null;
    foreach (['_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2'] as $key) {
        $elements = get_post_meta($post_id, $key, true);
        if (!is_array($elements)) {
            continue;
        }
        foreach ($elements as $element) {
            if (is_array($element) && (string) ($element['id'] ?? '') === $element_id) {
                $found = array_values($elements);
                break 2;
            }
        }
    }
    if ($found === null) {
        return new WP_Error('bricksed_source_element_missing', sprintf(
            /* translators: 1: element id, 2: post id */
            __('There is no element %1$s on post %2$d. Read the page\'s Bricks tree to find the section\'s id.', 'nibwp'),
            $element_id,
            $post_id
        ), ['status' => 404]);
    }

    $by_id = [];
    foreach ($found as $element) {
        $by_id[(string) $element['id']] = $element;
    }

    $content = [];
    $class_ids = [];
    $walk = static function (string $id, bool $is_root) use (&$walk, &$content, &$class_ids, $by_id): void {
        if (!isset($by_id[$id])) {
            return;
        }
        $element = $by_id[$id];
        if ($is_root) {
            $element['parent'] = 0;
        }
        $content[] = $element;
        foreach ((array) ($element['settings']['_cssGlobalClasses'] ?? []) as $class) {
            $class_ids[(string) $class] = true;
        }
        foreach ((array) ($element['children'] ?? []) as $child) {
            $walk((string) $child, false);
        }
    };
    $walk($element_id, true);

    // The components the copy is made of, and the ones those are made of.
    $site_components = get_option(nibwp_bricksed_components_option(), []);
    $site_components = is_array($site_components) ? $site_components : [];
    $by_component = [];
    foreach ($site_components as $component) {
        if (is_array($component) && isset($component['id'])) {
            $by_component[(string) $component['id']] = $component;
        }
    }
    $components = [];
    $queue = $content;
    while ($queue !== []) {
        $element = array_shift($queue);
        $cid = (string) ($element['cid'] ?? '');
        if ($cid === '' || isset($components[$cid]) || !isset($by_component[$cid])) {
            continue;
        }
        $components[$cid] = $by_component[$cid];
        foreach ((array) ($by_component[$cid]['elements'] ?? []) as $inner) {
            foreach ((array) ($inner['settings']['_cssGlobalClasses'] ?? []) as $class) {
                $class_ids[(string) $class] = true;
            }
            $queue[] = $inner;
        }
    }

    $classes = [];
    foreach ((array) get_option('bricks_global_classes', []) as $class) {
        if (is_array($class) && isset($class['id']) && isset($class_ids[(string) $class['id']])) {
            $classes[] = $class;
        }
    }

    $out = ['content' => $content, 'globalClasses' => $classes];
    if ($components !== []) {
        $out['components'] = array_values($components);
    }

    return $out;
}
