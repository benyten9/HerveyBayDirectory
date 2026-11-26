<?php

declare(strict_types=1);

/**
 * Agent View Builder: the person sketches a layout with boxes, says what it
 * is for, and the agent builds it with the site's own builder. The sketches
 * are kept per person (user meta), so they can be opened again, changed,
 * dropped on another page or built part by part.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NIBWP_STUDIO_BUILDER_TYPES = ['section', 'container', 'columns', 'column', 'box', 'heading', 'text', 'image', 'button', 'card', 'form', 'nav', 'list', 'video', 'icon', 'spacer'];
const NIBWP_STUDIO_BUILDER_MAX = 40;

require_once __DIR__ . '/studio-framework.php';
require_once __DIR__ . '/studio-classes.php';

add_action('rest_api_init', static function (): void {
    $can = static fn(): bool => current_user_can('manage_options');
    register_rest_route('nibwp/v1', '/studio/designs', [
        ['methods' => 'GET', 'permission_callback' => $can, 'callback' => 'nibwp_studio_rest_builder_designs'],
        ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_studio_rest_builder_save'],
    ]);
    register_rest_route('nibwp/v1', '/studio/design-delete', [
        'methods' => 'POST',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_builder_delete',
    ]);
});

/**
 * One design as the person drew it, cleaned: boxes in board pixels, each
 * with its parent, type, label and what should go in it; and the brief.
 *
 * @return array<string, mixed>|null
 */
function nibwp_studio_builder_clean($raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $str = static fn($v, int $max): string => mb_substr(trim(sanitize_textarea_field(is_scalar($v) ? (string) $v : '')), 0, $max);
    $pick = static fn($v, array $allowed, string $fallback): string => in_array($v, $allowed, true) ? (string) $v : $fallback;

    $boxes = [];
    $ids = [];
    foreach (array_slice(is_array($raw['boxes'] ?? null) ? $raw['boxes'] : [], 0, 150) as $b) {
        if (!is_array($b)) {
            continue;
        }
        $id = substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($b['id'] ?? '')), 0, 24);
        if ($id === '' || isset($ids[$id])) {
            continue;
        }
        $ids[$id] = true;
        $boxes[] = [
            'id' => $id,
            'parent' => substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($b['parent'] ?? '')), 0, 24),
            'type' => $pick($b['type'] ?? '', NIBWP_STUDIO_BUILDER_TYPES, 'box'),
            'label' => $str($b['label'] ?? '', 80),
            'note' => $str($b['note'] ?? '', 1000),
            'layout' => $pick($b['layout'] ?? '', ['auto', 'row', 'column', 'grid'], 'auto'),
            'x' => max(0, min(4000, (int) ($b['x'] ?? 0))),
            'y' => max(0, min(20000, (int) ($b['y'] ?? 0))),
            'w' => max(4, min(4000, (int) ($b['w'] ?? 0))),
            'h' => max(4, min(20000, (int) ($b['h'] ?? 0))),
        ];
    }
    // On the sheet, and a parent that is not on the board is no parent.
    $sheet = max(200, min(4000, (int) ($raw['width'] ?? 1200)));
    foreach ($boxes as &$b) {
        $b['x'] = min($b['x'], $sheet - 4);
        $b['w'] = min($b['w'], $sheet - $b['x']);
        if ($b['parent'] !== '' && (!isset($ids[$b['parent']]) || $b['parent'] === $b['id'])) {
            $b['parent'] = '';
        }
    }
    unset($b);

    $brief = is_array($raw['brief'] ?? null) ? $raw['brief'] : [];
    $out = [
        'id' => substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($raw['id'] ?? '')), 0, 24),
        'name' => $str($raw['name'] ?? '', 80),
        'device' => $pick($raw['device'] ?? '', ['desktop', 'tablet', 'mobile'], 'desktop'),
        'width' => $sheet,
        'boxes' => $boxes,
        'brief' => [
            'text' => $str($brief['text'] ?? '', 3000),
            'system' => $str($brief['system'] ?? '', 1500),
            'builder' => sanitize_key($str($brief['builder'] ?? 'auto', 30)) ?: 'auto',
            'layout' => $pick($brief['layout'] ?? '', ['auto', 'flex', 'grid'], 'auto'),
            'style' => $pick($brief['style'] ?? '', ['system', 'page', 'fresh'], 'system'),
            'content' => $pick($brief['content'] ?? '', ['real', 'placeholder', 'empty'], 'real'),
        ],
    ];

    $place = is_array($raw['place'] ?? null) ? $raw['place'] : [];
    if ($place) {
        $out['place'] = array_filter([
            'mode' => $pick($place['mode'] ?? '', ['drop', 'new', 'end'], 'end'),
            'selector' => $str($place['selector'] ?? '', 600),
            'position' => $pick($place['position'] ?? '', ['before', 'after'], 'after'),
            'near_text' => $str($place['near_text'] ?? '', 200),
            'page_url' => esc_url_raw($str($place['page_url'] ?? '', 2000)),
            'canvas' => $str($place['canvas'] ?? '', 200),
        ]);
    }
    $part = substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($raw['part'] ?? '')), 0, 24);
    if ($part !== '' && isset($ids[$part])) {
        $out['part'] = $part;
    }

    return $out['boxes'] ? $out : null;
}

/**
 * The design as the agent reads it: top to bottom, each part with its type,
 * its label, how wide it is next to its siblings and what should go in it.
 * Parts that sit side by side are grouped into a row.
 */
function nibwp_studio_builder_outline(array $wf): string
{
    $boxes = $wf['boxes'] ?? [];
    $kids = [];
    foreach ($boxes as $b) {
        $kids[$b['parent']][] = $b;
    }
    $names = [
        'section' => 'Section', 'container' => 'Container', 'columns' => 'Columns', 'column' => 'Column', 'box' => 'Box',
        'heading' => 'Heading', 'text' => 'Text', 'image' => 'Image', 'button' => 'Button', 'card' => 'Card',
        'form' => 'Form', 'nav' => 'Navigation', 'list' => 'List', 'video' => 'Video', 'icon' => 'Icon', 'spacer' => 'Spacer',
    ];
    $only = $wf['part'] ?? '';
    $lines = [];

    $walk = static function (string $parent, int $depth, float $width) use (&$walk, &$lines, $kids, $names, $only): void {
        $list = $kids[$parent] ?? [];
        usort($list, static fn($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        // Rows: parts whose heights overlap by half or more sit side by side.
        $rows = [];
        foreach ($list as $b) {
            $placed = false;
            foreach ($rows as &$row) {
                $top = max($row['top'], $b['y']);
                $bottom = min($row['bottom'], $b['y'] + $b['h']);
                if ($bottom - $top >= min($b['h'], $row['bottom'] - $row['top']) / 2) {
                    $row['items'][] = $b;
                    $row['top'] = min($row['top'], $b['y']);
                    $row['bottom'] = max($row['bottom'], $b['y'] + $b['h']);
                    $placed = true;
                    break;
                }
            }
            unset($row);
            if (!$placed) {
                $rows[] = ['top' => $b['y'], 'bottom' => $b['y'] + $b['h'], 'items' => [$b]];
            }
        }
        $pad = str_repeat('  ', $depth);
        foreach ($rows as $row) {
            usort($row['items'], static fn($a, $b) => $a['x'] <=> $b['x']);
            $inner = $depth;
            if (count($row['items']) > 1) {
                $shares = array_map(static fn($b) => max(1, (int) round($b['w'] / max(1.0, $width) * 100)) . '%', $row['items']);
                $lines[] = $pad . '- Side by side, ' . count($row['items']) . ' across (widths ' . implode(' · ', $shares) . ' of the parent; stack them on phones):';
                $inner = $depth + 1;
            }
            foreach ($row['items'] as $b) {
                $mark = $only !== '' && $b['id'] === $only ? ' ← BUILD ONLY THIS PART' : '';
                $line = str_repeat('  ', $inner) . '- [' . $b['id'] . '] ' . ($names[$b['type']] ?? 'Box')
                    . ($b['label'] !== '' ? ' "' . $b['label'] . '"' : '')
                    . ' (' . max(1, (int) round($b['w'] / max(1.0, $width) * 100)) . '% wide, about ' . $b['h'] . 'px tall'
                    . ($b['layout'] !== 'auto' ? ', inner layout: ' . $b['layout'] : '') . ')' . $mark;
                $lines[] = $line;
                if ($b['note'] !== '') {
                    $lines[] = str_repeat('  ', $inner + 1) . 'What goes here: ' . $b['note'];
                }
                $walk($b['id'], $inner + 1, (float) $b['w']);
            }
        }
    };
    $walk('', 0, (float) ($wf['width'] ?? 1200));

    return implode("\n", $lines);
}

/** What the agent is told about a Builder request. */
function nibwp_studio_builder_brief(array $wf): string
{
    $b = $wf['brief'];
    $builder = $b['builder'] === 'auto' ? 'the builder this page already uses (page.builder), or the block editor when there is none' : ($b['builder'] === 'gutenberg' ? 'the block editor (core blocks)' : ($b['builder'] === 'theme' ? 'the theme: a block theme template or template part in the Site Editor' : $b['builder']));
    $layout = ['auto' => 'pick per part: CSS grid for two-dimensional layouts such as card grids, flexbox for rows and stacks', 'flex' => 'flexbox', 'grid' => 'CSS grid'][$b['layout']];
    $style = ['system' => 'the site\'s design system: its tokens, utility classes and theme presets (Automatic.css variables when it is active), never hard-coded values', 'page' => 'the look of the page it lands on: same spacing, type, colours and components as its neighbours', 'fresh' => 'a fresh, modern look, still built on the site\'s tokens and presets'][$b['style']];
    $content = ['real' => 'write real, specific copy for this site (its name, what it sells, its tone); real images from the media library where they fit', 'placeholder' => 'clearly marked placeholder copy and images, ready to replace', 'empty' => 'empty slots with labels only'][$b['content']];

    $place = $wf['place'] ?? ['mode' => 'end'];
    if (($place['mode'] ?? '') === 'drop') {
        $where = 'Put it ' . ($place['position'] ?? 'after') . ' the element at selector "' . ($place['selector'] ?? '') . '"' . (!empty($place['near_text']) ? ' (it reads "' . $place['near_text'] . '")' : '') . ' on ' . ($place['page_url'] ?? 'the page on screen') . (!empty($place['canvas']) ? ', inside the builder canvas ' . $place['canvas'] : '') . '. Find the element in the post or template that renders it.';
    } elseif (($place['mode'] ?? '') === 'new') {
        $where = 'Build it on a new draft page named after the design, then navigate there.';
    } else {
        $where = 'Add it at the end of the page on screen (page.url), before the footer.';
    }

    return 'The person drew a design (a wireframe) in the Agent View Builder and wants it built. design.outline lists its parts top to bottom: type, label, width relative to the parent, and what goes in each part. Proportions are a guide, not pixels: never hard-code the drawn sizes.'
        . (!empty($wf['part']) ? ' Build ONLY the part marked "BUILD ONLY THIS PART" (and what is inside it), inside the design already on the page if it is there.' : '')
        . ' Build with ' . $builder . ', as real elements the editor understands, never a raw HTML block. Layout: ' . $layout . '. Style: ' . $style . '. Content: ' . $content . '.'
        . ($b['system'] !== '' ? ' Their design system and style, follow it exactly: ' . $b['system'] . '.' : '')
        . ' Always responsive (phone, tablet and desktop; side-by-side parts stack on phones, tap targets at least 44px) and accessible (semantic sections and landmarks, one H1 per page and headings in order, alt text, labelled fields, WCAG AA contrast, visible focus, keyboard reachable).'
        . ' ' . $where . ' When done, reply with what you built and where, and highlight it.';
}

/** Builders and themes a design can be built with on this site. */
function nibwp_studio_builder_targets(): array
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $list = [];
    $plugins = [
        'etch' => ['Etch', ['etch/etch.php', 'etchwp/etchwp.php']],
        'elementor' => ['Elementor', ['elementor/elementor.php']],
        'kadence' => ['Kadence Blocks', ['kadence-blocks/kadence-blocks.php']],
        'breakdance' => ['Breakdance', ['breakdance/plugin.php']],
        'builderius' => ['Builderius', ['builderius/builderius.php']],
        'mosaic' => ['Mosaic', ['mosaic/mosaic.php']],
        'oxygen' => ['Oxygen', ['oxygen/functions.php']],
        'generateblocks' => ['GenerateBlocks', ['generateblocks/plugin.php']],
        'stackable' => ['Stackable', ['stackable-ultimate-gutenberg-blocks/plugin.php']],
        'spectra' => ['Spectra', ['ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php']],
    ];
    foreach ($plugins as $id => [$name, $files]) {
        foreach ($files as $file) {
            if (is_plugin_active($file)) {
                $list[] = ['id' => $id, 'name' => $name];
                break;
            }
        }
    }
    $theme = wp_get_theme();
    $template = strtolower((string) $theme->get_template());
    if ($template === 'bricks') {
        array_unshift($list, ['id' => 'bricks', 'name' => 'Bricks']);
    } elseif ($template === 'divi') {
        $list[] = ['id' => 'divi', 'name' => 'Divi'];
    }
    $list[] = ['id' => 'gutenberg', 'name' => __('Block editor', 'nibwp')];
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        /* translators: %s: the active theme's name */
        $list[] = ['id' => 'theme', 'name' => sprintf(__('%s theme template', 'nibwp'), $theme->get('Name'))];
    }

    return $list;
}

/** Whether the site has a token system the brief can name. */
function nibwp_studio_builder_system(): string
{
    if (function_exists('nibwp_framework_state') && nibwp_framework_state()['on']) {
        return __('NibWP framework', 'nibwp');
    }
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (is_plugin_active('automaticcss-plugin/automaticcss-plugin.php') || defined('ACSS_PLUGIN_FILE')) {
        return 'Automatic.css';
    }

    return function_exists('wp_is_block_theme') && wp_is_block_theme() ? __('Theme presets', 'nibwp') : '';
}

/** @return array<int, array<string, mixed>> Newest first. */
function nibwp_studio_builder_designs(int $user_id): array
{
    $all = get_user_meta($user_id, 'nibwp_studio_designs', true);
    // Sketches saved before the Builder had its name move over once.
    $old = get_user_meta($user_id, 'nibwp_studio_wireframes', true);
    if (is_array($old) && $old) {
        $all = array_merge(is_array($all) ? $all : [], array_values($old));
        update_user_meta($user_id, 'nibwp_studio_designs', $all);
        delete_user_meta($user_id, 'nibwp_studio_wireframes');
    }
    $all = is_array($all) ? array_values($all) : [];
    usort($all, static fn($a, $b) => (int) ($b['updated'] ?? 0) <=> (int) ($a['updated'] ?? 0));

    return $all;
}

function nibwp_studio_rest_builder_designs(): WP_REST_Response
{
    return new WP_REST_Response(['designs' => nibwp_studio_builder_designs(get_current_user_id())]);
}

function nibwp_studio_rest_builder_save(WP_REST_Request $request): WP_REST_Response
{
    $wf = nibwp_studio_builder_clean($request->get_param('design'));
    if (!$wf) {
        return new WP_REST_Response(['message' => __('Draw at least one box first.', 'nibwp')], 400);
    }
    unset($wf['place'], $wf['part']);
    $uid = get_current_user_id();
    $all = [];
    foreach (nibwp_studio_builder_designs($uid) as $old) {
        $all[$old['id']] = $old;
    }
    if ($wf['id'] === '' || !isset($all[$wf['id']])) {
        // A full list is said so: no design is dropped to make room.
        if (count($all) >= NIBWP_STUDIO_BUILDER_MAX) {
            return new WP_REST_Response(['message' => sprintf(
                /* translators: %d: how many designs are saved */
                __('You have %d saved designs, as many as there is room for. Delete one you no longer need, then save again.', 'nibwp'),
                count($all)
            )], 409);
        }
        $wf['id'] = $wf['id'] !== '' ? $wf['id'] : 'wf' . strtolower(wp_generate_password(8, false));
        $wf['created'] = time();
    } else {
        $wf['created'] = (int) ($all[$wf['id']]['created'] ?? time());
    }
    $wf['name'] = $wf['name'] !== '' ? $wf['name'] : __('Untitled design', 'nibwp');
    $wf['updated'] = time();
    $all[$wf['id']] = $wf;
    $all = array_values($all);
    usort($all, static fn($a, $b) => (int) ($b['updated'] ?? 0) <=> (int) ($a['updated'] ?? 0));
    update_user_meta($uid, 'nibwp_studio_designs', $all);

    return new WP_REST_Response(['design' => $wf]);
}

function nibwp_studio_rest_builder_delete(WP_REST_Request $request): WP_REST_Response
{
    $id = substr(preg_replace('/[^a-z0-9_-]/i', '', (string) $request->get_param('id')), 0, 24);
    $uid = get_current_user_id();
    $left = array_values(array_filter(nibwp_studio_builder_designs($uid), static fn($w) => ($w['id'] ?? '') !== $id));
    update_user_meta($uid, 'nibwp_studio_designs', $left);

    return new WP_REST_Response(['deleted' => $id]);
}

/**
 * Which template shows each kind of content: the front page, the posts page,
 * single posts and pages, and every public custom post type with its
 * archive. Each row names the template the theme uses (a block template from
 * the Site Editor, or a PHP file in a classic theme), a page to open to see
 * it, and where to edit it.
 *
 * @return array<int, array<string, string>>
 */
function nibwp_studio_builder_templates(): array
{
    $block = function_exists('wp_is_block_theme') && wp_is_block_theme();
    $slugs = [];
    if ($block && function_exists('get_block_templates')) {
        foreach (get_block_templates([], 'wp_template') as $t) {
            $slugs[$t->slug] = ['title' => (string) $t->title, 'id' => (string) $t->id, 'tpl' => nibwp_studio_builder_tpl_ref($t)];
        }
    }
    // The first of these the theme has, as the template hierarchy picks it.
    $pick = static function (array $chain) use ($block, $slugs, &$pick): array {
        foreach ($chain as $slug) {
            if ($block && isset($slugs[$slug])) {
                $ref = $slugs[$slug]['tpl'];
                return array_filter([
                    'tplid' => $slugs[$slug]['id'],
                    'template' => $slugs[$slug]['title'] !== '' ? $slugs[$slug]['title'] : $slug,
                    'source' => !$ref ? __('Theme template', 'nibwp') : ($ref['own'] ? __('Your template', 'nibwp') : __('Theme template, edited', 'nibwp')),
                    'edit' => admin_url('site-editor.php?postType=wp_template&postId=' . rawurlencode($slugs[$slug]['id'])),
                    'tpl' => $slugs[$slug]['tpl'],
                ], static fn($v) => $v !== null);
            }
            if (!$block && ($file = locate_template([$slug . '.php']))) {
                return ['tplid' => nibwp_studio_builder_tpl_file($file), 'template' => basename($file), 'source' => __('Theme file', 'nibwp'), 'edit' => ''];
            }
        }

        // Nothing more specific: the index template, still editable.
        return $block && isset($slugs['index']) && $chain !== ['index'] ? $pick(['index']) : ['tplid' => $block ? '' : 'index.php', 'template' => 'index', 'source' => __('Theme template', 'nibwp'), 'edit' => ''];
    };
    $sample = static function (string $type): string {
        $ids = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => false]);

        return $ids ? (string) get_permalink((int) $ids[0]) : '';
    };

    $rows = [];
    $front = (int) get_option('page_on_front');
    if (get_option('show_on_front') === 'page' && $front) {
        $tpl = (string) get_page_template_slug($front);
        $rows[] = ['kind' => 'front', 'label' => __('Front page', 'nibwp'), 'content' => get_the_title($front), 'url' => home_url('/')]
            + ($tpl !== '' ? ['tplid' => $block ? get_stylesheet() . '//' . $tpl : $tpl, 'template' => $tpl, 'source' => __('Page template', 'nibwp'), 'edit' => ''] : $pick(['front-page', 'page-' . get_post_field('post_name', $front), 'page', 'singular']));
    } else {
        $rows[] = ['kind' => 'front', 'label' => __('Front page', 'nibwp'), 'content' => __('Latest posts', 'nibwp'), 'url' => home_url('/')] + $pick(['front-page', 'home']);
    }
    $posts = (int) get_option('page_for_posts');
    if ($posts) {
        $rows[] = ['kind' => 'posts', 'label' => __('Blog', 'nibwp'), 'content' => get_the_title($posts), 'url' => (string) get_permalink($posts)] + $pick(['home', 'index']);
    }
    $woo = class_exists('WooCommerce') && function_exists('wc_get_page_permalink');
    foreach (get_post_types(['public' => true], 'objects') as $type) {
        // WooCommerce's products get their own group below.
        if ($type->name === 'attachment' || ($woo && $type->name === 'product')) {
            continue;
        }
        $name = (string) $type->labels->singular_name;
        $chain = $type->name === 'page' ? ['page', 'singular'] : ['single-' . $type->name, 'single', 'singular'];
        $rows[] = ['kind' => 'single', 'type' => $type->name, 'label' => $name, 'content' => (string) $type->labels->name, 'url' => $sample($type->name)] + $pick($chain);
        if ($type->has_archive) {
            $rows[] = ['kind' => 'archive', 'type' => $type->name, 'label' => sprintf(__('%s list', 'nibwp'), $name), 'content' => (string) $type->labels->name, 'url' => (string) get_post_type_archive_link($type->name)]
                + $pick(['archive-' . $type->name, 'archive']);
        }
    }
    if ($woo) {
        $shop = (int) wc_get_page_id('shop');
        $wc = [
            ['kind' => 'shop', 'label' => __('Shop', 'nibwp'), 'content' => __('All products', 'nibwp'), 'url' => (string) wc_get_page_permalink('shop')] + $pick(['archive-product', 'woocommerce/archive-product']),
            ['kind' => 'single', 'type' => 'product', 'label' => __('Product', 'nibwp'), 'content' => __('Each product', 'nibwp'), 'url' => $sample('product')] + $pick(['single-product', 'woocommerce/single-product']),
            ['kind' => 'archive', 'label' => __('Product category', 'nibwp'), 'content' => __('Products in a category', 'nibwp'), 'url' => (string) (($t = get_terms(['taxonomy' => 'product_cat', 'number' => 1, 'hide_empty' => true])) && !is_wp_error($t) && $t ? get_term_link($t[0]) : '')] + $pick(['taxonomy-product_cat', 'archive-product', 'woocommerce/taxonomy-product-cat']),
            ['kind' => 'page', 'label' => __('Cart', 'nibwp'), 'content' => get_the_title((int) wc_get_page_id('cart')), 'url' => (string) wc_get_page_permalink('cart')] + $pick(['page-cart', 'page', 'woocommerce/cart/cart']),
            ['kind' => 'page', 'label' => __('Checkout', 'nibwp'), 'content' => get_the_title((int) wc_get_page_id('checkout')), 'url' => (string) wc_get_page_permalink('checkout')] + $pick(['page-checkout', 'page', 'woocommerce/checkout/form-checkout']),
            ['kind' => 'page', 'label' => __('My account', 'nibwp'), 'content' => get_the_title((int) wc_get_page_id('myaccount')), 'url' => (string) wc_get_page_permalink('myaccount')] + $pick(['page-my-account', 'page', 'woocommerce/myaccount/my-account']),
        ];
        foreach ($wc as $r) {
            $rows[] = ['group' => 'WooCommerce'] + $r;
        }
        unset($shop);
    }
    // Templates not tied to a content type, picked per page.
    if ($block && function_exists('get_block_templates')) {
        foreach (get_block_templates([], 'wp_template') as $t) {
            if (empty($t->is_custom)) {
                continue;
            }
            $use = get_posts(['post_type' => 'any', 'post_status' => ['publish', 'draft'], 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_wp_page_template', 'meta_value' => $t->slug]); // phpcs:ignore WordPress.DB.SlowDBQuery -- one row, on Builder load
            $rows[] = [
                'group' => __('Not assigned', 'nibwp'),
                'kind' => 'custom',
                'slug' => (string) $t->slug,
                'label' => (string) ($t->title !== '' ? $t->title : $t->slug),
                'content' => $use ? get_the_title((int) $use[0]) : __('No page uses it yet', 'nibwp'),
                'url' => $use ? (get_post_status((int) $use[0]) === 'publish' ? (string) get_permalink((int) $use[0]) : (string) get_preview_post_link((int) $use[0])) : '',
                'tplid' => (string) $t->id,
                'template' => (string) $t->slug,
                'source' => __('Custom template', 'nibwp'),
                'edit' => admin_url('site-editor.php?postType=wp_template&postId=' . rawurlencode((string) $t->id)),
                'tpl' => nibwp_studio_builder_tpl_ref($t),
            ];
        }
    }
    $rows[] = ['kind' => 'search', 'label' => __('Search results', 'nibwp'), 'content' => '', 'url' => home_url('/?s=tour')] + $pick(['search', 'archive']);
    $rows[] = ['kind' => '404', 'label' => __('Page not found', 'nibwp'), 'content' => '', 'url' => home_url('/nibwp-no-such-page/')] + $pick(['404']);

    $shots = get_option('nibwp_tpl_shots', []);
    $shots = is_array($shots) ? $shots : [];
    $theme = (string) wp_get_theme()->get('Version');
    foreach ($rows as &$r) {
        $r['group'] = $r['group'] ?? (in_array($r['kind'], ['single', 'archive'], true) && !in_array($r['type'] ?? '', ['post', 'page'], true) ? __('Content types', 'nibwp') : __('Site', 'nibwp'));
        if (!empty($r['url'])) {
            $r['shotk'] = md5($r['kind'] . '|' . ($r['type'] ?? '') . '|' . ($r['slug'] ?? '') . '|' . $r['label']);
            $r['shotv'] = md5('draw2|' . $r['url'] . '|' . (!empty($r['tpl']['id']) ? (string) get_post_modified_time('U', true, (int) $r['tpl']['id']) : '') . '|' . $theme);
            $have = $shots[$r['shotk']] ?? null;
            if ($have && is_file(nibwp_studio_shot_dir() . '/' . $r['shotk'] . '.jpg')) {
                $r['shot'] = nibwp_studio_shot_url($r['shotk'], (int) $have['at']);
                $r['stale'] = $have['v'] !== $r['shotv'] || (int) $have['at'] < time() - WEEK_IN_SECONDS;
            }
        }
    }
    unset($r);
    $order = [__('Site', 'nibwp'), __('Content types', 'nibwp'), 'WooCommerce', __('Not assigned', 'nibwp')];
    usort($rows, static fn($a, $b) => array_search($a['group'], $order, true) <=> array_search($b['group'], $order, true));

    return array_map(static fn($r) => array_map(static fn($v) => is_string($v) ? html_entity_decode($v, ENT_QUOTES) : $v, $r), $rows);
}

/**
 * What the Templates tab may do with a template: rename it when it is kept
 * in the database, remove it too when no theme file stands behind it.
 *
 * @return array{id: int, title: string, own: bool}|null
 */
function nibwp_studio_builder_tpl_ref($t): ?array
{
    if (empty($t->wp_id)) {
        return null;
    }

    return ['id' => (int) $t->wp_id, 'title' => (string) ($t->title !== '' ? $t->title : $t->slug), 'own' => empty($t->has_theme_file)];
}

/*
 * New pages and templates from the Builder. A page starts as a draft and
 * opens on the canvas. A template is made directly in a block theme, never
 * over one the theme already has; a classic theme keeps templates in PHP
 * files, so that one is asked of the agent.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/studio/page-new', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            $title = mb_substr(trim(sanitize_text_field((string) $request->get_param('title'))), 0, 200);
            $type = sanitize_key((string) ($request->get_param('type') ?: 'page'));
            $obj = post_type_exists($type) ? get_post_type_object($type) : null;
            if (!$obj || !$obj->public || !current_user_can($obj->cap->create_posts)) {
                return new WP_REST_Response(['message' => __('That kind of content cannot be made here.', 'nibwp')], 400);
            }
            if ($title === '') {
                return new WP_REST_Response(['message' => __('Give it a title.', 'nibwp')], 400);
            }
            $slug = sanitize_title((string) $request->get_param('slug'));
            // A draft keeps the address it is given, and WordPress makes it
            // unique only when it is published: made unique now, so the
            // address shown is the one it gets.
            if ($slug !== '') {
                $slug = wp_unique_post_slug($slug, 0, 'publish', $type, 0);
            }
            $id = wp_insert_post(wp_slash(['post_type' => $type, 'post_status' => 'draft', 'post_title' => $title] + ($slug !== '' ? ['post_name' => $slug] : [])), true);
            if (is_wp_error($id)) {
                return new WP_REST_Response(['message' => $id->get_error_message()], 400);
            }
            $tpl = sanitize_title((string) $request->get_param('template'));
            if ($tpl !== '') {
                update_post_meta($id, '_wp_page_template', $tpl);
            }
            $real = $slug !== '' ? $slug : wp_unique_post_slug(sanitize_title($title), $id, 'publish', $type, 0);

            return new WP_REST_Response(['id' => $id, 'slug' => $real, 'url' => (string) get_preview_post_link($id), 'edit' => (string) get_edit_post_link($id, 'raw')]);
        },
    ]);
    register_rest_route('nibwp/v1', '/studio/content', [
        'methods' => 'GET',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            $type = sanitize_key((string) ($request->get_param('type') ?: 'page'));
            if (!post_type_exists($type)) {
                return new WP_REST_Response(['items' => []]);
            }
            $out = [];
            // Newest first; with "s", the ones whose title or text match.
            $find = sanitize_text_field((string) $request->get_param('s'));
            foreach (get_posts(['post_type' => $type, 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'], 'numberposts' => 8, 'orderby' => 'modified', 'order' => 'DESC'] + ($find !== '' ? ['s' => $find] : [])) as $post) {
                $out[] = [
                    'id' => $post->ID,
                    'title' => html_entity_decode(get_the_title($post) !== '' ? get_the_title($post) : __('(no title)', 'nibwp'), ENT_QUOTES),
                    'status' => (string) (get_post_status_object($post->post_status)->label ?? $post->post_status),
                    'state' => $post->post_status,
                    'slug' => (string) $post->post_name,
                    'url' => $post->post_status === 'publish' ? (string) get_permalink($post) : (string) get_preview_post_link($post),
                    'edit' => (string) get_edit_post_link($post->ID, 'raw'),
                ];
            }

            return new WP_REST_Response(['items' => $out]);
        },
    ]);
    // The Content hub's recent items: rename one, or move it to the trash.
    register_rest_route('nibwp/v1', '/studio/content-edit', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            $id = absint($request->get_param('id'));
            $post = $id ? get_post($id) : null;
            if (!$post || in_array($post->post_type, ['wp_template', 'wp_template_part', 'attachment', 'revision'], true)) {
                return new WP_REST_Response(['message' => __('That item is not there any more.', 'nibwp')], 404);
            }
            $do = (string) $request->get_param('do');
            if ($do === 'rename') {
                $title = sanitize_text_field((string) $request->get_param('title'));
                if ($title === '') {
                    return new WP_REST_Response(['message' => __('Give it a title.', 'nibwp')], 400);
                }
                if (!current_user_can('edit_post', $id)) {
                    return new WP_REST_Response(['message' => __('Your account cannot rename this.', 'nibwp')], 403);
                }
                $change = ['ID' => $id, 'post_title' => $title];
                // The address too, when asked: made unique as it will be once
                // published (a draft would keep a taken one until then).
                $slug = $request->has_param('slug') ? sanitize_title((string) $request->get_param('slug')) : '';
                if ($slug !== '') {
                    $change['post_name'] = wp_unique_post_slug($slug, $id, 'publish', $post->post_type, (int) $post->post_parent);
                }
                $done = wp_update_post(wp_slash($change), true);
                if (is_wp_error($done) || !$done) {
                    return new WP_REST_Response(['message' => is_wp_error($done) ? $done->get_error_message() : __('Could not rename it.', 'nibwp')], 500);
                }
                $post = get_post($id);

                return new WP_REST_Response(['id' => $id, 'title' => $title, 'slug' => (string) $post->post_name, 'url' => $post->post_status === 'publish' ? (string) get_permalink($post) : (string) get_preview_post_link($post)]);
            }
            if ($do === 'trash') {
                if (!current_user_can('delete_post', $id)) {
                    return new WP_REST_Response(['message' => __('Your account cannot move this to the trash.', 'nibwp')], 403);
                }
                // With the trash turned off, WordPress would delete it for good.
                if (!EMPTY_TRASH_DAYS) {
                    return new WP_REST_Response(['message' => __('The trash is turned off on this site, so this would delete it for good. Delete it from wp-admin if that is what you want.', 'nibwp')], 409);
                }
                if (!wp_trash_post($id)) {
                    return new WP_REST_Response(['message' => __('Could not move it to the trash.', 'nibwp')], 500);
                }

                return new WP_REST_Response(['id' => $id, 'trashed' => true]);
            }

            return new WP_REST_Response(['message' => __('Nothing to do.', 'nibwp')], 400);
        },
    ]);
    register_rest_route('nibwp/v1', '/studio/template-new', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options') && current_user_can('edit_theme_options'),
        'callback' => 'nibwp_studio_rest_template_new',
    ]);
});

/* ------------------------------------------------------------------
 * Template pictures: the Builder draws a page that uses each template,
 * small, and keeps it here. A page loaded for a picture has no admin bar.
 * ---------------------------------------------------------------- */

function nibwp_studio_shot_dir(): string
{
    $up = wp_upload_dir(null, false);

    return wp_normalize_path(trailingslashit($up['basedir']) . 'nibwp-shots');
}

function nibwp_studio_shot_url(string $key, int $at): string
{
    $up = wp_upload_dir(null, false);

    return trailingslashit($up['baseurl']) . 'nibwp-shots/' . $key . '.jpg?v=' . $at;
}

add_filter('show_admin_bar', static fn($show) => isset($_GET['nibwp_shot']) ? false : $show); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only hides the bar on a page drawn for a picture

/** Keep a template's picture: a small JPEG, checked to be one. */
function nibwp_studio_rest_template_shot(WP_REST_Request $request): WP_REST_Response
{
    $key = (string) $request->get_param('key');
    $ver = (string) $request->get_param('v');
    $img = (string) $request->get_param('image');
    if (!preg_match('/^[a-f0-9]{32}$/', $key) || !preg_match('/^[a-f0-9]{32}$/', $ver) || strpos($img, 'data:image/jpeg;base64,') !== 0) {
        return new WP_REST_Response(['message' => __('That picture could not be kept.', 'nibwp')], 400);
    }
    $bin = base64_decode(substr($img, 23), true);
    $size = $bin !== false && strlen($bin) <= 200 * 1024 ? getimagesizefromstring($bin) : false;
    if (!$size || ($size['mime'] ?? '') !== 'image/jpeg' || $size[0] > 800 || $size[1] > 600) {
        return new WP_REST_Response(['message' => __('That picture could not be kept.', 'nibwp')], 400);
    }
    $dir = nibwp_studio_shot_dir();
    wp_mkdir_p($dir);
    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- an index guard in our own folder
    }
    file_put_contents($dir . '/' . $key . '.jpg', $bin); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a checked JPEG, named by a hex key
    $shots = get_option('nibwp_tpl_shots', []);
    $shots = is_array($shots) ? $shots : [];
    // Newest last, so the oldest go first; their pictures go with them.
    unset($shots[$key]);
    $shots[$key] = ['v' => $ver, 'at' => time()];
    $keep = array_slice($shots, -200, null, true);
    foreach (array_keys(array_diff_key($shots, $keep)) as $gone) {
        if (preg_match('/^[a-f0-9]{32}$/', (string) $gone)) {
            wp_delete_file($dir . '/' . $gone . '.jpg');
        }
    }
    update_option('nibwp_tpl_shots', $keep, false);

    return new WP_REST_Response(['shot' => nibwp_studio_shot_url($key, time())]);
}

add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/studio/template-shot', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => 'nibwp_studio_rest_template_shot',
    ]);
    register_rest_route('nibwp/v1', '/studio/template-edit', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options') && current_user_can('edit_theme_options'),
        'callback' => 'nibwp_studio_rest_template_edit',
    ]);
});

/**
 * Rename a template, or move it to the trash and back. Only templates kept in
 * the database; one the theme ships as a file is never trashed, since that
 * would only undo the edits made to it.
 */
function nibwp_studio_rest_template_edit(WP_REST_Request $request): WP_REST_Response
{
    $post = get_post(absint($request->get_param('id')));
    if (!$post || $post->post_type !== 'wp_template') {
        return new WP_REST_Response(['message' => __('That template is not there any more.', 'nibwp')], 404);
    }
    $do = (string) $request->get_param('do');
    if ($do === 'rename') {
        $title = sanitize_text_field((string) $request->get_param('title'));
        if ($title === '') {
            return new WP_REST_Response(['message' => __('Give the template a name.', 'nibwp')], 400);
        }
        wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => $title]));
    } elseif ($do === 'trash') {
        // Yours: deleted. The theme's, with your edits: those edits go, and
        // the theme's own version shows again. Either way it is in the trash.
        wp_trash_post($post->ID);
    } elseif ($do === 'restore') {
        wp_untrash_post($post->ID);
        wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);
    } else {
        return new WP_REST_Response(['message' => __('Nothing to do.', 'nibwp')], 400);
    }

    return new WP_REST_Response(['templates' => nibwp_studio_builder_templates()]);
}

function nibwp_studio_rest_template_new(WP_REST_Request $request): WP_REST_Response
{
    $name = mb_substr(trim(sanitize_text_field((string) $request->get_param('name'))), 0, 120);
    // Only kinds of content people visit: never revisions, menu items or blocks.
    $types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $request->get_param('types')), static function ($t): bool {
        $obj = $t !== '' ? get_post_type_object($t) : null;

        return $obj && $obj->public;
    })));
    if ($name === '') {
        return new WP_REST_Response(['message' => __('Give the template a name.', 'nibwp')], 400);
    }
    $labels = array_map(static fn($t) => (string) get_post_type_object($t)->labels->singular_name, $types);
    if (!(function_exists('wp_is_block_theme') && wp_is_block_theme())) {
        $for = $types ? implode(', ', $labels) : __('no content type yet: it is picked per page', 'nibwp');
        /* translators: 1: template name, 2: what it is used for */
        return new WP_REST_Response(['ask' => sprintf(__('Create a new template named “%1$s” for %2$s, in the way this theme and its page builder make templates. Start from the site’s header and footer with the content in between, then tell me how to open it.', 'nibwp'), $name, $for)]);
    }
    $have = array_map(static fn($t) => $t->slug, get_block_templates([], 'wp_template'));
    // One template per content type picked (that is how block themes pick
    // them); none picked, one to choose per page.
    $slugs = $types ? array_map(static fn($t) => $t === 'page' ? 'page' : 'single-' . $t, $types) : ['nibwp-' . sanitize_title($name)];
    // The theme's own header and footer: block themes name them as they like.
    $parts = ['header' => [], 'footer' => []];
    foreach (get_block_templates([], 'wp_template_part') as $t) {
        if (isset($parts[$t->area ?? ''])) {
            $parts[$t->area][] = (string) $t->slug;
        }
    }
    $part = static fn(string $area): string => in_array($area, $parts[$area], true) || !$parts[$area] ? $area : $parts[$area][0];
    $content = '<!-- wp:template-part ' . wp_json_encode(['slug' => $part('header'), 'tagName' => 'header']) . " /-->\n\n<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"type\":\"constrained\"}} -->\n<main class=\"wp-block-group\"><!-- wp:post-title /-->\n\n<!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /--></main>\n<!-- /wp:group -->\n\n<!-- wp:template-part " . wp_json_encode(['slug' => $part('footer'), 'tagName' => 'footer']) . ' /-->';
    $made = [];
    $kept = [];
    foreach ($slugs as $i => $slug) {
        if (in_array($slug, $have, true)) {
            $kept[] = $types ? $labels[$i] : $name;
            continue;
        }
        $id = wp_insert_post(wp_slash(['post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $name, 'post_content' => $content]), true);
        if (is_wp_error($id)) {
            return new WP_REST_Response(['message' => $id->get_error_message()], 400);
        }
        wp_set_post_terms($id, [get_stylesheet()], 'wp_theme');
        $made[] = $types ? $labels[$i] : $name;
    }
    if (!$made) {
        return new WP_REST_Response(['message' => $types ? sprintf(__('%s already have their own template: open it from the list to change it.', 'nibwp'), implode(', ', $kept)) : __('A template with that name exists already.', 'nibwp')], 409);
    }

    return new WP_REST_Response([
        'templates' => nibwp_studio_builder_templates(),
        /* translators: 1: template name, 2: content types it was made for, 3: those that kept their own */
        'message' => $types ? sprintf(__('“%1$s” made for %2$s.', 'nibwp'), $name, implode(', ', $made)) . ($kept ? ' ' . sprintf(__('%s kept their own template.', 'nibwp'), implode(', ', $kept)) : '') : '',
    ]);
}
/*
 * Components saved from the Builder: a name and the element's HTML, kept per
 * person, to drop on any page. Turning one into a real component of the
 * page's builder is the agent's job, asked for when the person saves.
 */
const NIBWP_STUDIO_COMPONENTS_MAX = 60;

add_action('rest_api_init', static function (): void {
    $can = static fn(): bool => current_user_can('manage_options');
    register_rest_route('nibwp/v1', '/studio/components', [
        ['methods' => 'GET', 'permission_callback' => $can, 'callback' => static fn() => new WP_REST_Response(['components' => nibwp_studio_builder_components(get_current_user_id())])],
        ['methods' => 'POST', 'permission_callback' => $can, 'callback' => 'nibwp_studio_rest_component_save'],
    ]);
    register_rest_route('nibwp/v1', '/studio/component-delete', [
        'methods' => 'POST',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_component_delete',
    ]);
});

/** @return array<int, array{id: string, name: string, html: string, updated: int}> Newest first. */
function nibwp_studio_builder_components(int $user_id): array
{
    $all = get_user_meta($user_id, 'nibwp_studio_components', true);

    return is_array($all) ? array_values($all) : [];
}

function nibwp_studio_rest_component_save(WP_REST_Request $request): WP_REST_Response
{
    $name = mb_substr(trim(sanitize_text_field((string) $request->get_param('name'))), 0, 80);
    $kind = $request->get_param('kind') === 'wireframe' ? 'wireframe' : 'component';
    $html = (string) $request->get_param('html');
    if ($kind === 'wireframe') {
        // A wireframe is its layout as JSON: notes, columns, nested sections.
        $spec = json_decode($html, true);
        $html = is_array($spec) ? (string) wp_json_encode(nibwp_studio_builder_wire_clean($spec)) : '';
    } else {
        // Only people trusted with raw HTML keep it as it is.
        $html = current_user_can('unfiltered_html') ? $html : wp_kses_post($html);
    }
    if ($name === '' || trim($html) === '' || strlen($html) > 60000) {
        return new WP_REST_Response(['message' => __('A component needs a name and some HTML, under 60 KB.', 'nibwp')], 400);
    }
    $uid = get_current_user_id();
    $id = substr(preg_replace('/[^a-z0-9]/i', '', (string) $request->get_param('id')), 0, 24);
    $item = ['id' => $id !== '' ? $id : 'c' . strtolower(wp_generate_password(8, false)), 'name' => $name, 'kind' => $kind, 'html' => $html, 'updated' => time()];
    $have = nibwp_studio_builder_components($uid);
    $rest = array_values(array_filter($have, static fn($c) => ($c['id'] ?? '') !== $item['id']));
    // A full list is said so: no component is dropped to make room.
    if (count($rest) === count($have) && count($have) >= NIBWP_STUDIO_COMPONENTS_MAX) {
        return new WP_REST_Response(['message' => sprintf(
            /* translators: %d: how many components are saved */
            __('You have %d saved components, as many as there is room for. Delete one you no longer need, then save again.', 'nibwp'),
            count($have)
        )], 409);
    }
    update_user_meta($uid, 'nibwp_studio_components', array_merge([$item], $rest));

    return new WP_REST_Response(['component' => $item]);
}

/** A wireframe's layout, kept to notes, heights, columns and nested sections. */
function nibwp_studio_builder_wire_clean(array $s, int $depth = 0): array
{
    $kids = $depth < 4 && is_array($s['kids'] ?? null) ? array_slice($s['kids'], 0, 24) : [];

    return [
        'note' => mb_substr(sanitize_textarea_field((string) ($s['note'] ?? '')), 0, 600),
        'h' => max(0, min(2000, (int) ($s['h'] ?? 0))),
        'cols' => max(0, min(4, (int) ($s['cols'] ?? 0))),
        'kids' => array_map(static fn($k) => nibwp_studio_builder_wire_clean(is_array($k) ? $k : [], $depth + 1), $kids),
    ];
}

function nibwp_studio_rest_component_delete(WP_REST_Request $request): WP_REST_Response
{
    $id = substr(preg_replace('/[^a-z0-9]/i', '', (string) $request->get_param('id')), 0, 24);
    $uid = get_current_user_id();
    update_user_meta($uid, 'nibwp_studio_components', array_values(array_filter(nibwp_studio_builder_components($uid), static fn($c) => ($c['id'] ?? '') !== $id)));

    return new WP_REST_Response(['deleted' => $id]);
}

/*
 * CSS and JS from the Builder, saved straight to the site with no agent in
 * between: CSS by selector for the whole site, JS and canvas changes for
 * the page they were made on. Printed on the front end, after the theme's
 * own styles.
 */
const NIBWP_STUDIO_BUILDER_PATCHES_MAX = 300;
const NIBWP_STUDIO_BUILDER_STORED_MAX = 500;
const NIBWP_STUDIO_BUILDER_SELECTOR_MAX = 600;
const NIBWP_STUDIO_BUILDER_HTML_MAX = 60000;
const NIBWP_STUDIO_BUILDER_CSS_RULES_MAX = 200;
const NIBWP_STUDIO_BUILDER_CSS_KEY_MAX = 300;
const NIBWP_STUDIO_BUILDER_CSS_MAX = 20000;
const NIBWP_STUDIO_BUILDER_JS_MAX = 20000;
const NIBWP_STUDIO_BUILDER_SAVES_KEPT = 20;
// Saves a page keeps in its history; an older one can no longer be undone.
const NIBWP_STUDIO_BUILDER_HISTORY_KEPT = 30;
// The query vars that pick what a listing shows (/?s=tour, /?cat=3).
const NIBWP_STUDIO_BUILDER_KEY_VARS = ['s', 'cat', 'tag', 'post_type', 'paged', 'author', 'm', 'year', 'monthnum', 'taxonomy', 'term', 'p', 'page_id'];

add_action('rest_api_init', static function (): void {
    $can = static fn(): bool => current_user_can('manage_options') && current_user_can('unfiltered_html');
    register_rest_route('nibwp/v1', '/studio/builder-save', [
        'methods' => 'POST',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_builder_save_code',
    ]);
    register_rest_route('nibwp/v1', '/studio/builder-history', [
        'methods' => 'GET',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_builder_history',
    ]);
    register_rest_route('nibwp/v1', '/studio/builder-undo', [
        'methods' => 'POST',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_builder_undo',
    ]);
    register_rest_route('nibwp/v1', '/studio/builder-redo', [
        'methods' => 'POST',
        'permission_callback' => $can,
        'callback' => 'nibwp_studio_rest_builder_redo',
    ]);
});

/**
 * Save what the Builder changed, all of it or none of it: CSS rules, the
 * page's scripts and its canvas changes. Nothing is ever cut short: a part
 * that is too big refuses the whole save. A save the site already has (the
 * same save_id) is answered again without storing it twice, and a page that
 * changed since it was opened (base) is refused, since the changes point at
 * the page as it was.
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_builder_save_code(WP_REST_Request $request)
{
    [$post, $key] = nibwp_studio_builder_req_target($request);
    $id = $request->get_param('save_id');
    $id = is_string($id) && preg_match('/^[a-z0-9-]{8,64}$/', $id) ? $id : '';
    $base = $request->get_param('base');
    $base = is_string($base) ? trim($base) : '';

    $in = $request->get_param('css');
    $in = is_array($in) ? $in : [];
    if (count($in) > NIBWP_STUDIO_BUILDER_CSS_RULES_MAX) {
        return new WP_Error('nibwp_builder_too_large', sprintf(
            /* translators: 1: how many CSS rules were sent, 2: the most one save takes */
            __('That is too many CSS rules to save in one go (%1$d; the limit is %2$d).', 'nibwp'),
            count($in),
            NIBWP_STUDIO_BUILDER_CSS_RULES_MAX
        ), ['status' => 413]);
    }
    $rules = [];
    foreach ($in as $sel => $text) {
        $sel = trim((string) $sel);
        $text = is_scalar($text) ? (string) $text : '';
        if ($sel === '') {
            continue;
        }
        if (mb_strlen($sel) > NIBWP_STUDIO_BUILDER_CSS_KEY_MAX) {
            return new WP_Error('nibwp_builder_too_large', sprintf(
                /* translators: 1: length of the selector, 2: the longest one kept */
                __('That selector is too long to save (%1$d characters; the limit is %2$d).', 'nibwp'),
                mb_strlen($sel),
                NIBWP_STUDIO_BUILDER_CSS_KEY_MAX
            ), ['status' => 413]);
        }
        if (mb_strlen($text) > NIBWP_STUDIO_BUILDER_CSS_MAX) {
            return new WP_Error('nibwp_builder_too_large', sprintf(
                /* translators: 1: a CSS selector, 2: size of its CSS in KB, 3: the largest kept, in KB */
                __('The CSS for “%1$s” is too large to save in one go (%2$d KB; the limit is %3$d KB).', 'nibwp'),
                $sel,
                (int) ceil(mb_strlen($text) / 1000),
                (int) (NIBWP_STUDIO_BUILDER_CSS_MAX / 1000)
            ), ['status' => 413]);
        }
        if (trim($text) !== '' && !nibwp_studio_builder_css_ok($text)) {
            return new WP_Error('nibwp_builder_css_open', sprintf(
                /* translators: %s: a CSS selector */
                __('The CSS for “%s” is not finished: a brace, comment or quote is left open. Close it, then save again.', 'nibwp'),
                $sel
            ), ['status' => 400]);
        }
        $rules[$sel] = trim($text) === '' ? '' : $text;
    }

    $js = [];
    foreach ((array) $request->get_param('js') as $code) {
        $code = is_scalar($code) ? trim((string) $code) : '';
        if ($code === '') {
            continue;
        }
        if (mb_strlen($code) > NIBWP_STUDIO_BUILDER_JS_MAX) {
            return new WP_Error('nibwp_builder_too_large', sprintf(
                /* translators: 1: size of the script in KB, 2: the largest kept, in KB */
                __('That script is too large to save in one go (%1$d KB; the limit is %2$d KB).', 'nibwp'),
                (int) ceil(mb_strlen($code) / 1000),
                (int) (NIBWP_STUDIO_BUILDER_JS_MAX / 1000)
            ), ['status' => 413]);
        }
        $js[] = $code;
    }

    // The canvas itself: element changes, replayed on the page in order.
    $patches = nibwp_studio_builder_clean_patches($request->get_param('patches'));
    if (is_wp_error($patches)) {
        return $patches;
    }

    if (!nibwp_studio_builder_lock()) {
        return new WP_Error('nibwp_builder_busy', __('Another save is still running. Try again in a moment.', 'nibwp'), ['status' => 409]);
    }
    try {
        $saves = nibwp_studio_builder_saves_for($key);
        $stored = nibwp_studio_builder_patches_for($key);
        if ($id !== '' && isset($saves[$id])) {
            $was = $saves[$id];

            return new WP_REST_Response(['css' => (int) ($was['css'] ?? 0), 'js' => (int) ($was['js'] ?? 0), 'patches' => (int) ($was['patches'] ?? 0), 'stored' => count($stored), 'repeat' => true]);
        }
        if ($post > 0 && $base !== '' && !nibwp_studio_builder_base_ok($post, $base)) {
            return new WP_Error('nibwp_builder_stale', __('This page changed since you opened it (NibWP or someone else saved it). Refresh it, then make your change again.', 'nibwp'), ['status' => 409]);
        }
        if ($patches && count($stored) + count($patches) > NIBWP_STUDIO_BUILDER_STORED_MAX) {
            return new WP_Error('nibwp_builder_page_full', sprintf(
                /* translators: 1: changes the page holds, 2: changes in this save, 3: the most a page holds */
                __('This page already holds %1$d saved Builder changes, and %2$d more would pass the limit of %3$d. Ask NibWP to build them into the page itself.', 'nibwp'),
                count($stored),
                count($patches),
                NIBWP_STUDIO_BUILDER_STORED_MAX
            ), ['status' => 413]);
        }

        $before = nibwp_studio_builder_css(true);
        $css = $before;
        foreach ($rules as $sel => $text) {
            if ($text === '') {
                unset($css[$sel]);
            } else {
                $css[$sel] = $text;
            }
        }
        if ($css !== $before) {
            update_option('nibwp_builder_css', $css, false);
            nibwp_studio_builder_css(true);
        }
        // For an undo: each rule this save changed, as it was before (null:
        // there was none) and a fingerprint of what it left (null: removed).
        $was = [];
        foreach (array_keys($rules) as $sel) {
            $old = array_key_exists($sel, $before) ? (string) $before[$sel] : null;
            $new = array_key_exists($sel, $css) ? (string) $css[$sel] : null;
            if ($old !== $new) {
                $was[(string) $sel] = ['before' => $old, 'after' => $new === null ? null : md5($new)];
            }
        }

        // The page's scripts: each one once, in the order they were saved.
        $added = 0;
        $jsFrom = 0;
        if ($js) {
            $list = nibwp_studio_builder_js_for($key);
            $jsFrom = count($list);
            foreach ($js as $code) {
                if (!in_array($code, $list, true)) {
                    $list[] = $code;
                    $added++;
                }
            }
            if ($added) {
                nibwp_studio_builder_put($key, 'js', $list);
            }
        }

        $from = count($stored);
        if ($patches) {
            $stored = array_merge($stored, $patches);
            nibwp_studio_builder_store_patches($key, $stored);
        }

        // A save that changed something goes in the page's history, to undo.
        $batch = '';
        if ($was || $added || $patches) {
            $batch = nibwp_studio_builder_history_add($key, $id, [
                'patches' => ['from' => $from, 'n' => count($patches)],
                'js' => ['from' => $jsFrom, 'n' => $added],
                'css' => $was,
            ]);
        }

        $done = ['css' => count($rules), 'js' => $added, 'patches' => count($patches)];
        if ($id !== '') {
            unset($saves[$id]);
            $saves[$id] = $done;
            nibwp_studio_builder_put($key, 'saves', array_slice($saves, -NIBWP_STUDIO_BUILDER_SAVES_KEPT, null, true));
        }
    } finally {
        nibwp_studio_builder_unlock();
    }

    if ($css !== $before || $added || $patches) {
        nibwp_studio_builder_purge($post, $key, $css !== $before);
    }

    return new WP_REST_Response($done + ['stored' => count($stored)] + ($batch !== '' ? ['batch' => $batch] : []));
}

/*
 * History: each save of a page is a batch that can be undone, newest first,
 * one at a time. A page's changes replay in order, so only the newest batch
 * still in force comes off; the one undone last can be put back while nothing
 * was saved after it. CSS is shared by the whole site: a rule changed again
 * since (from another page, or by hand) is not overwritten.
 */

/**
 * The page's last saves, oldest first: id, at, user, undone (a time, or 0),
 * where its changes and scripts sit in the page's lists (from, n), the CSS
 * it changed (before, after), and once undone, what it took off (redo).
 *
 * @return array<int, array<string, mixed>>
 */
function nibwp_studio_builder_history_for(string $key): array
{
    $v = nibwp_studio_builder_get($key, 'history');

    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

/** Add a save to the page's history; returns its id. */
function nibwp_studio_builder_history_add(string $key, string $id, array $did): string
{
    $list = nibwp_studio_builder_history_for($key);
    if ($id === '' || in_array($id, array_column($list, 'id'), true)) {
        $id = 'h' . bin2hex(random_bytes(6));
    }
    // A new save ends what could be redone: it would land after this one.
    foreach ($list as &$b) {
        unset($b['redo']);
    }
    unset($b);
    $list[] = ['id' => $id, 'at' => time(), 'user' => get_current_user_id(), 'undone' => 0] + $did;
    nibwp_studio_builder_put($key, 'history', array_slice($list, -NIBWP_STUDIO_BUILDER_HISTORY_KEPT));

    return $id;
}

/** Where a batch sits in the history, or -1. */
function nibwp_studio_builder_history_find(array $list, string $id): int
{
    foreach ($list as $i => $b) {
        if ($id !== '' && (string) ($b['id'] ?? '') === $id) {
            return (int) $i;
        }
    }

    return -1;
}

/** The newest batch still in force: the only one that can be undone; -1 when none. */
function nibwp_studio_builder_history_top(array $list): int
{
    for ($i = count($list) - 1; $i >= 0; $i--) {
        if (empty($list[$i]['undone'])) {
            return $i;
        }
    }

    return -1;
}

/**
 * The batch a redo puts back: the one undone last, which is the oldest that
 * still holds what it took off (a new save drops that); -1 when none.
 */
function nibwp_studio_builder_history_next(array $list): int
{
    foreach ($list as $i => $b) {
        if (!empty($b['undone']) && isset($b['redo']) && is_array($b['redo'])) {
            return (int) $i;
        }
    }

    return -1;
}

/** The history as the Builder shows it: newest first. */
function nibwp_studio_builder_history_view(array $list): array
{
    $top = nibwp_studio_builder_history_top($list);
    $next = nibwp_studio_builder_history_next($list);
    $names = [];
    $out = [];
    foreach ($list as $i => $b) {
        $uid = (int) ($b['user'] ?? 0);
        if (!isset($names[$uid])) {
            $user = $uid > 0 ? get_userdata($uid) : false;
            $names[$uid] = $user ? (string) $user->display_name : __('Someone', 'nibwp');
        }
        $keys = array_map('strval', array_keys(is_array($b['css'] ?? null) ? $b['css'] : []));
        $out[] = [
            'id' => (string) ($b['id'] ?? ''),
            'at' => gmdate('c', (int) ($b['at'] ?? 0)),
            'user' => $names[$uid],
            'counts' => ['patches' => (int) ($b['patches']['n'] ?? 0), 'css' => count($keys), 'js' => (int) ($b['js']['n'] ?? 0)],
            'css_keys' => array_slice($keys, 0, 3),
            'undone' => !empty($b['undone']),
            'can_undo' => $i === $top,
            'can_redo' => $i === $next,
        ];
    }

    return array_reverse($out);
}

function nibwp_studio_rest_builder_history(WP_REST_Request $request): WP_REST_Response
{
    [, $key] = nibwp_studio_builder_req_target($request);

    return new WP_REST_Response(['history' => nibwp_studio_builder_history_view(nibwp_studio_builder_history_for($key)), 'kept' => NIBWP_STUDIO_BUILDER_HISTORY_KEPT]);
}

/**
 * Undo a save: its changes and scripts come off the page, and each CSS rule
 * it changed is as it was before. Only the newest save still in force; a
 * rule changed again since refuses it. The batch stays in the history,
 * marked undone.
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_builder_undo(WP_REST_Request $request)
{
    [$post, $key] = nibwp_studio_builder_req_target($request);
    $want = $request->get_param('batch');
    $want = is_string($want) ? $want : '';
    if (!nibwp_studio_builder_lock()) {
        return new WP_Error('nibwp_builder_busy', __('Another save is still running. Try again in a moment.', 'nibwp'), ['status' => 409]);
    }
    try {
        $list = nibwp_studio_builder_history_for($key);
        $at = nibwp_studio_builder_history_find($list, $want);
        if ($at < 0) {
            return new WP_Error('nibwp_builder_undo_gone', sprintf(
                /* translators: %d: how many saves a page keeps */
                __('That save is no longer in this page’s history: only its last %d saves can be undone.', 'nibwp'),
                NIBWP_STUDIO_BUILDER_HISTORY_KEPT
            ), ['status' => 409]);
        }
        $b = $list[$at];
        if (!empty($b['undone'])) {
            return new WP_Error('nibwp_builder_undo_done', __('That save is undone already.', 'nibwp'), ['status' => 409]);
        }
        if ($at !== nibwp_studio_builder_history_top($list)) {
            return new WP_Error('nibwp_builder_undo_order', __('Only the newest save can be undone. Undo the newer ones first.', 'nibwp'), ['status' => 409]);
        }
        $rules = is_array($b['css'] ?? null) ? $b['css'] : [];
        $css = nibwp_studio_builder_css(true);
        foreach ($rules as $sel => $c) {
            $now = array_key_exists($sel, $css) ? md5((string) $css[$sel]) : null;
            if ($now !== ($c['after'] ?? null)) {
                return new WP_Error('nibwp_builder_undo_css', sprintf(
                    /* translators: %s: a CSS selector such as .hero */
                    __('“%s” changed again since; undo that first or edit it by hand.', 'nibwp'),
                    (string) $sel
                ), ['status' => 409]);
            }
        }
        $stored = nibwp_studio_builder_patches_for($key);
        $scripts = nibwp_studio_builder_js_for($key);
        $p = ['from' => (int) ($b['patches']['from'] ?? 0), 'n' => (int) ($b['patches']['n'] ?? 0)];
        $j = ['from' => (int) ($b['js']['from'] ?? 0), 'n' => (int) ($b['js']['n'] ?? 0)];
        if (count($stored) !== $p['from'] + $p['n'] || count($scripts) !== $j['from'] + $j['n']) {
            return new WP_Error('nibwp_builder_undo_page', __('This page’s saved changes are no longer as this save left them, so it cannot be undone. Reset the page to start over.', 'nibwp'), ['status' => 409]);
        }

        $back = $css;
        $restored = [];
        $redo = ['patches' => array_slice($stored, $p['from']), 'js' => array_slice($scripts, $j['from']), 'css' => []];
        foreach ($rules as $sel => $c) {
            $redo['css'][(string) $sel] = array_key_exists($sel, $css) ? (string) $css[$sel] : null;
            $old = isset($c['before']) ? (string) $c['before'] : null;
            if ($old === null) {
                unset($back[$sel]);
            } else {
                $back[$sel] = $old;
            }
            $restored[(string) $sel] = $old;
        }
        if ($back !== $css) {
            update_option('nibwp_builder_css', $back, false);
            nibwp_studio_builder_css(true);
        }
        if ($p['n']) {
            nibwp_studio_builder_store_patches($key, array_slice($stored, 0, $p['from']));
        }
        if ($j['n']) {
            nibwp_studio_builder_put($key, 'js', array_slice($scripts, 0, $j['from']));
        }
        $list[$at]['undone'] = time();
        $list[$at]['redo'] = $redo;
        nibwp_studio_builder_put($key, 'history', $list);
    } finally {
        nibwp_studio_builder_unlock();
    }
    nibwp_studio_builder_purge($post, $key, $back !== $css);

    return new WP_REST_Response(['undone' => (string) $b['id'], 'css' => (object) $restored, 'history' => nibwp_studio_builder_history_view($list)]);
}

/**
 * Redo the save undone last, while nothing was saved after it: its changes
 * and scripts go back on the page and its CSS is set again, unless a rule
 * changed since it was undone.
 *
 * @return WP_REST_Response|WP_Error
 */
function nibwp_studio_rest_builder_redo(WP_REST_Request $request)
{
    [$post, $key] = nibwp_studio_builder_req_target($request);
    $want = $request->get_param('batch');
    $want = is_string($want) ? $want : '';
    if (!nibwp_studio_builder_lock()) {
        return new WP_Error('nibwp_builder_busy', __('Another save is still running. Try again in a moment.', 'nibwp'), ['status' => 409]);
    }
    try {
        $list = nibwp_studio_builder_history_for($key);
        $at = nibwp_studio_builder_history_find($list, $want);
        if ($at < 0 || $at !== nibwp_studio_builder_history_next($list)) {
            return new WP_Error('nibwp_builder_redo_order', __('Only the save you undid last can be redone, and only while nothing was saved after it.', 'nibwp'), ['status' => 409]);
        }
        $b = $list[$at];
        $r = $b['redo'];
        $rules = is_array($b['css'] ?? null) ? $b['css'] : [];
        $css = nibwp_studio_builder_css(true);
        foreach ($rules as $sel => $c) {
            $now = array_key_exists($sel, $css) ? (string) $css[$sel] : null;
            if ($now !== (isset($c['before']) ? (string) $c['before'] : null)) {
                return new WP_Error('nibwp_builder_redo_css', sprintf(
                    /* translators: %s: a CSS selector such as .hero */
                    __('“%s” changed since this save was undone, so it cannot be redone.', 'nibwp'),
                    (string) $sel
                ), ['status' => 409]);
            }
        }
        $stored = nibwp_studio_builder_patches_for($key);
        $scripts = nibwp_studio_builder_js_for($key);
        if (count($stored) !== (int) ($b['patches']['from'] ?? 0) || count($scripts) !== (int) ($b['js']['from'] ?? 0)) {
            return new WP_Error('nibwp_builder_redo_page', __('This page’s saved changes are no longer as the undo left them, so this save cannot be redone.', 'nibwp'), ['status' => 409]);
        }

        $again = $css;
        $set = [];
        foreach ((array) ($r['css'] ?? []) as $sel => $text) {
            if ($text === null) {
                unset($again[$sel]);
            } else {
                $again[$sel] = (string) $text;
            }
            $set[(string) $sel] = $text === null ? null : (string) $text;
        }
        if ($again !== $css) {
            update_option('nibwp_builder_css', $again, false);
            nibwp_studio_builder_css(true);
        }
        if (!empty($r['patches'])) {
            nibwp_studio_builder_store_patches($key, array_merge($stored, array_values((array) $r['patches'])));
        }
        if (!empty($r['js'])) {
            nibwp_studio_builder_put($key, 'js', array_merge($scripts, array_values((array) $r['js'])));
        }
        $list[$at]['undone'] = 0;
        unset($list[$at]['redo']);
        nibwp_studio_builder_put($key, 'history', $list);
    } finally {
        nibwp_studio_builder_unlock();
    }
    nibwp_studio_builder_purge($post, $key, $again !== $css);

    return new WP_REST_Response(['redone' => (string) $b['id'], 'css' => (object) $set, 'history' => nibwp_studio_builder_history_view($list)]);
}

/**
 * Whether a CSS text is whole: every brace closed in order, every comment
 * and quoted string ended. One left open would swallow every rule printed
 * after it.
 */
function nibwp_studio_builder_css_ok(string $css): bool
{
    $stops = "{}\"'/\\";
    $len = strlen($css);
    $depth = 0;
    $i = strcspn($css, $stops);
    while ($i < $len) {
        $c = $css[$i];
        if ($c === '\\') {
            // An escaped character is plain text.
            $i++;
        } elseif ($c === '"' || $c === "'") {
            // A string ends at the same quote, on the same line.
            for ($j = $i + 1; $j < $len && $css[$j] !== $c; $j++) {
                if ($css[$j] === '\\') {
                    $j++;
                } elseif ($css[$j] === "\n") {
                    return false;
                }
            }
            if ($j >= $len) {
                return false;
            }
            $i = $j;
        } elseif ($c === '/') {
            if (($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    return false;
                }
                $i = $end + 1;
            }
        } elseif ($c === '{') {
            $depth++;
        } elseif (--$depth < 0) {
            return false;
        }
        $i++;
        if ($i < $len) {
            $i += strcspn($css, $stops, $i);
        }
    }

    return $depth === 0;
}

/**
 * What a page's own content was when it was opened in the Builder: its
 * changes are made against that, and stop fitting once it changes.
 */
function nibwp_studio_builder_base(int $post): string
{
    $p = $post > 0 ? get_post($post) : null;

    return $p ? md5((string) $p->post_content . '|' . (string) $p->post_modified_gmt) : '';
}

/**
 * Whether a save made against $base still fits the page: the page is as it
 * was, or it changed only by a change the Builder made on screen as well (a
 * class renamed across the site), and by nothing since.
 */
function nibwp_studio_builder_base_ok(int $post, string $base): bool
{
    $now = nibwp_studio_builder_base($post);
    if ($now !== '' && hash_equals($now, $base)) {
        return true;
    }
    $alias = get_post_meta($post, '_nibwp_builder_base_alias', true);

    return $now !== '' && is_array($alias) && ($alias['to'] ?? '') === $now && in_array($base, (array) ($alias['from'] ?? []), true);
}

/** The page's content was just changed by the Builder itself ($was: its base before). */
function nibwp_studio_builder_base_follow(int $post, string $was): void
{
    $now = nibwp_studio_builder_base($post);
    if ($was === '' || $now === '' || $now === $was) {
        return;
    }
    $alias = get_post_meta($post, '_nibwp_builder_base_alias', true);
    $from = is_array($alias) && ($alias['to'] ?? '') === $was ? array_values((array) ($alias['from'] ?? [])) : [];
    $from[] = $was;
    update_post_meta($post, '_nibwp_builder_base_alias', ['to' => $now, 'from' => array_slice(array_values(array_unique($from)), -10)]);
}

/**
 * One Builder save at a time: two saves reading and writing the same page
 * at once would lose one of them. The lock is a row in the options table,
 * added with INSERT IGNORE so only one request can get it (add_option()
 * overwrites a row that is already there); it lapses after ten seconds in
 * case a save dies holding it.
 */
function nibwp_studio_builder_lock(): bool
{
    global $wpdb;
    for ($try = 0; $try < 6; $try++) {
        $now = time();
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'nibwp_builder_lock', (string) ($now + 10)))) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock row, never cached
            return true;
        }
        $until = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'nibwp_builder_lock')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock row, never cached
        if ($until === 0) {
            continue;
        }
        if ($until < $now) {
            // Left by a save that died: taken over, unless another save did first.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'nibwp_builder_lock', (string) $until)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock row, never cached
            continue;
        }
        usleep(250000);
    }

    return false;
}

function nibwp_studio_builder_unlock(): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'nibwp_builder_lock')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock row, never cached
}

/**
 * Pages served from a cache show the change too: the post's cached copy,
 * or every page when the change reaches the whole site (CSS, or a listing
 * that has no post of its own).
 */
function nibwp_studio_builder_purge(int $post, string $key, bool $site): void
{
    if ($post > 0) {
        clean_post_cache($post);
    }
    do_action('nibwp_builder_saved', $post, $key);
    if ($site || $post <= 0) {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        return;
    }
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($post);
    }
    if (has_action('litespeed_purge_post')) {
        do_action('litespeed_purge_post', $post);
    }
    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post);
    }
    if (function_exists('wp_cache_post_change')) {
        wp_cache_post_change($post);
    }
}

/**
 * Changes made on the canvas, each one of: move an element, insert HTML,
 * remove an element, replace an element's HTML, set an attribute. Selectors as the Builder
 * computed them on the page, applied in the same order. A change too big to
 * keep whole refuses the save: a selector cut short can name an ancestor,
 * and HTML cut short breaks the page.
 *
 * @return array<int, array<string, string>>|WP_Error
 */
function nibwp_studio_builder_clean_patches($raw)
{
    $raw = is_array($raw) ? $raw : [];
    if (count($raw) > NIBWP_STUDIO_BUILDER_PATCHES_MAX) {
        return new WP_Error('nibwp_builder_too_large', sprintf(
            /* translators: 1: how many changes were sent, 2: the most one save takes */
            __('That is too many changes to save in one go (%1$d; the limit is %2$d).', 'nibwp'),
            count($raw),
            NIBWP_STUDIO_BUILDER_PATCHES_MAX
        ), ['status' => 413]);
    }
    $str = static fn($v): string => is_scalar($v) ? (string) $v : '';
    $out = [];
    foreach ($raw as $p) {
        if (!is_array($p) || !in_array($p['t'] ?? '', ['move', 'insert', 'remove', 'replace', 'attr', 'unattr'], true)) {
            continue;
        }
        $name = $str($p['name'] ?? '');
        if (in_array($p['t'], ['attr', 'unattr'], true) && !preg_match('/^[a-z_:][-a-z0-9_:.]{0,60}$/i', $name)) {
            continue;
        }
        $s = trim($str($p['s'] ?? ''));
        $to = trim($str($p['to'] ?? ''));
        // The markers the site puts around a post's content for the Builder are never saved.
        $html = str_replace(['<!--nibwp:post-->', '<!--/nibwp:post-->'], '', $str($p['html'] ?? ''));
        $long = max(mb_strlen($s), mb_strlen($to));
        if ($long > NIBWP_STUDIO_BUILDER_SELECTOR_MAX) {
            return new WP_Error('nibwp_builder_too_large', sprintf(
                /* translators: 1: length of the selector, 2: the longest one kept */
                __('That change is too large to save in one go (a selector of %1$d characters; the limit is %2$d).', 'nibwp'),
                $long,
                NIBWP_STUDIO_BUILDER_SELECTOR_MAX
            ), ['status' => 413]);
        }
        if (mb_strlen($html) > NIBWP_STUDIO_BUILDER_HTML_MAX) {
            return new WP_Error('nibwp_builder_too_large', sprintf(
                /* translators: 1: size of the HTML in KB, 2: the largest kept, in KB */
                __('That change is too large to save in one go (%1$d KB of HTML; the limit is %2$d KB).', 'nibwp'),
                (int) ceil(mb_strlen($html) / 1000),
                (int) (NIBWP_STUDIO_BUILDER_HTML_MAX / 1000)
            ), ['status' => 413]);
        }
        $out[] = array_filter([
            't' => $p['t'],
            's' => $s,
            'to' => $to,
            'pos' => in_array($p['pos'] ?? '', ['before', 'after', 'inside'], true) ? $p['pos'] : '',
            'name' => in_array($p['t'], ['attr', 'unattr'], true) ? $name : '',
            'html' => $html,
        ], static fn($v) => $v !== '');
    }

    return $out;
}

/**
 * Where a page's canvas changes are kept: its post, or its address. An
 * address is its path plus the query vars that pick what a listing shows
 * (a search, a category, page 2), sorted; the rest of the query (tracking,
 * previews) is left out. A path with none of them keeps the key it always
 * had.
 */
function nibwp_studio_builder_tpl_key(string $tpl): string
{
    return 'tmpl:' . md5($tpl);
}

/** A classic theme's template file, as a path inside the theme. */
function nibwp_studio_builder_tpl_file(string $file): string
{
    $file = wp_normalize_path($file);
    foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
        $dir = trailingslashit(wp_normalize_path($dir));
        if (str_starts_with($file, $dir)) {
            return substr($file, strlen($dir));
        }
    }

    return basename($file);
}

/**
 * What a Builder request is about: [post id, storage key]. With "tpl", one of
 * the site's templates (never a made-up one): its changes show on every page
 * it renders, so there is no single post behind it.
 *
 * @return array{0: int, 1: string}
 */
function nibwp_studio_builder_req_target(WP_REST_Request $request): array
{
    $tpl = (string) $request->get_param('tpl');
    if ($tpl !== '' && strlen($tpl) <= 200 && in_array($tpl, array_column(nibwp_studio_builder_templates(), 'tplid'), true)) {
        return [0, nibwp_studio_builder_tpl_key($tpl)];
    }
    $post = absint($request->get_param('post_id'));

    return [$post, nibwp_studio_builder_page_key($post, (string) $request->get_param('url'))];
}

/** The template rendering this page, named as the Templates tab names it. */
function nibwp_studio_builder_view_tpl(): string
{
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        return (string) ($GLOBALS['_wp_current_template_id'] ?? '');
    }

    return (string) ($GLOBALS['nibwp_studio_template_file'] ?? '');
}

// For administrators on the site: a post's own content between two markers,
// so the Builder, editing a template, can tell it from the template around it.
// Comments: nothing shows, and visitors never get them.
add_filter('the_content', static function ($html) {
    if (!is_string($html) || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed() || !current_user_can('manage_options')) {
        return $html;
    }

    return '<!--nibwp:post-->' . $html . '<!--/nibwp:post-->';
}, PHP_INT_MAX);

add_filter('template_include', static function ($file) {
    if (is_string($file) && $file !== '') {
        $GLOBALS['nibwp_studio_template_file'] = nibwp_studio_builder_tpl_file($file);
    }

    return $file;
}, PHP_INT_MAX);

function nibwp_studio_builder_page_key(int $post, string $url): string
{
    if ($post > 0) {
        return 'post:' . $post;
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $where = untrailingslashit($path === '' ? '/' : $path);
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);
    if ($query !== '') {
        $vars = [];
        parse_str($query, $vars);
        $keep = [];
        foreach (NIBWP_STUDIO_BUILDER_KEY_VARS as $var) {
            if (!isset($vars[$var]) || !is_scalar($vars[$var])) {
                continue;
            }
            $v = strtolower(trim((string) $vars[$var]));
            // An empty search is still a search; page 1 is the page itself.
            if (($v === '' && $var !== 's') || ($var === 'paged' && (int) $v <= 1)) {
                continue;
            }
            $keep[$var] = $v;
        }
        if ($keep) {
            ksort($keep);
            $where .= '?' . http_build_query($keep);
        }
    }

    return 'path:' . md5($where);
}

/**
 * The key of the page being viewed, as the Builder saved it. Empty on a
 * password-protected post the visitor has not unlocked: its saved changes
 * carry its content.
 */
function nibwp_studio_builder_view_key(): string
{
    if (is_singular()) {
        $id = (int) get_queried_object_id();

        return post_password_required($id) ? '' : 'post:' . $id;
    }

    return nibwp_studio_builder_page_key(0, (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
}

/**
 * A page's saved Builder data ($what: patches, js, saves or history): post meta for
 * a post, an option (never autoloaded) for an address.
 *
 * @return mixed
 */
function nibwp_studio_builder_get(string $key, string $what)
{
    if (str_starts_with($key, 'post:')) {
        return get_post_meta((int) substr($key, 5), '_nibwp_builder_' . $what, true);
    }

    return get_option('nibwp_builder_' . $what . '_' . substr($key, 5), []);
}

function nibwp_studio_builder_put(string $key, string $what, array $value): void
{
    if (str_starts_with($key, 'post:')) {
        $id = (int) substr($key, 5);
        if ($value) {
            update_post_meta($id, '_nibwp_builder_' . $what, wp_slash($value));
        } else {
            delete_post_meta($id, '_nibwp_builder_' . $what);
        }

        return;
    }
    $name = 'nibwp_builder_' . $what . '_' . substr($key, 5);
    if ($value) {
        update_option($name, $value, false);
    } else {
        delete_option($name);
    }
}

function nibwp_studio_builder_patches_for(string $key): array
{
    $v = nibwp_studio_builder_get($key, 'patches');

    return is_array($v) ? $v : [];
}

function nibwp_studio_builder_store_patches(string $key, array $patches): void
{
    nibwp_studio_builder_put($key, 'patches', $patches);
}

/**
 * A page's scripts, oldest first, each kept on its own. Scripts saved as one
 * text before they were kept apart read as one.
 *
 * @return array<int, string>
 */
function nibwp_studio_builder_js_for(string $key): array
{
    $v = nibwp_studio_builder_get($key, 'js');
    $v = is_string($v) ? [$v] : (is_array($v) ? $v : []);

    return array_values(array_filter(array_map(static fn($c) => is_string($c) ? trim($c) : '', $v), static fn($c) => $c !== ''));
}

/**
 * The last saves a page took, by save_id, with what each stored.
 *
 * @return array<string, array{css: int, js: int, patches: int}>
 */
function nibwp_studio_builder_saves_for(string $key): array
{
    $v = nibwp_studio_builder_get($key, 'saves');

    return is_array($v) ? $v : [];
}

// Reset: the page as its builder renders it again.
add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/studio/builder-reset', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        'callback' => 'nibwp_studio_rest_builder_reset',
    ]);
});

/** @return WP_REST_Response|WP_Error */
function nibwp_studio_rest_builder_reset(WP_REST_Request $request)
{
    [$post, $key] = nibwp_studio_builder_req_target($request);
    if (!nibwp_studio_builder_lock()) {
        return new WP_Error('nibwp_builder_busy', __('Another save is still running. Try again in a moment.', 'nibwp'), ['status' => 409]);
    }
    try {
        nibwp_studio_builder_store_patches($key, []);
        nibwp_studio_builder_put($key, 'js', []);
        // What the saves put on the page is gone, so they cannot be undone:
        // the CSS they changed stays, as the reset leaves it.
        nibwp_studio_builder_put($key, 'history', []);
    } finally {
        nibwp_studio_builder_unlock();
    }
    nibwp_studio_builder_purge($post, $key, false);

    return new WP_REST_Response(['reset' => true]);
}

// The saved canvas, replayed as the page loads: right after its content,
// before the theme's and plugins' own scripts.
add_action('wp_footer', static function (): void {
    $key = nibwp_studio_builder_view_key();
    $tpl = nibwp_studio_builder_view_tpl();
    $patches = array_merge($tpl !== '' ? nibwp_studio_builder_patches_for(nibwp_studio_builder_tpl_key($tpl)) : [], $key !== '' ? nibwp_studio_builder_patches_for($key) : []);
    if (!$patches) {
        return;
    }
    $data = wp_json_encode($patches, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    echo '<script id="nibwp-builder-canvas">(function(P){var q=function(s){try{return s?document.querySelector(s):null}catch(e){return null}};var mk=function(h){var t=document.createElement("template");t.innerHTML=h||"";return t.content};var put=function(n,to,pos){if(pos==="before"){to.before(n)}else if(pos==="after"){to.after(n)}else{to.append(n)}};P.forEach(function(p){var el=q(p.s),to=q(p.to);if(p.t==="remove"&&el){el.remove()}else if(p.t==="replace"&&el){el.replaceWith(mk(p.html))}else if(p.t==="insert"&&to){put(mk(p.html),to,p.pos)}else if(p.t==="move"&&el&&to){put(el,to,p.pos)}else if(p.t==="attr"&&el){el.setAttribute(p.name,p.html||"")}else if(p.t==="unattr"&&el){el.removeAttribute(p.name)}})})(' . $data . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HTML hex-escaped, saved by an administrator with unfiltered_html
}, 0);

// Elements hidden on the site from the Builder: a rule only where the page
// has some. data-nibwp-hide="" hides it everywhere; "phone", "tablet" and
// "desktop" (space-separated) only on those screens, at the Builder's sizes.
// Inside the Builder (html.nst-x-building) they stay, faded.
add_action('wp_head', static function (): void {
    $key = nibwp_studio_builder_view_key();
    if ($key === '') {
        return;
    }
    $tpl = nibwp_studio_builder_view_tpl();
    $has = strpos((string) wp_json_encode(array_merge($tpl !== '' ? nibwp_studio_builder_patches_for(nibwp_studio_builder_tpl_key($tpl)) : [], nibwp_studio_builder_patches_for($key))), 'data-nibwp-hide') !== false;
    if (!$has && is_singular()) {
        $has = strpos((string) get_post_field('post_content', (int) get_queried_object_id()), 'data-nibwp-hide') !== false;
    }
    if (!$has) {
        return;
    }
    $css = 'html:not(.nst-x-building) [data-nibwp-hide=""]{display:none!important}';
    foreach (['phone' => '(max-width:767px)', 'tablet' => '(min-width:768px) and (max-width:1023px)', 'desktop' => '(min-width:1024px)'] as $size => $media) {
        $css .= '@media ' . $media . '{html:not(.nst-x-building) [data-nibwp-hide~="' . $size . '"]{display:none!important}}';
    }
    echo '<style id="nibwp-hide">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
}, 99);

// The page as the Builder opens it, for those who can save: a save made
// after the page changed under them is refused instead of landing wrong.
add_action('wp_head', static function (): void {
    if (!is_singular() || !current_user_can('manage_options') || !current_user_can('unfiltered_html')) {
        return;
    }
    $base = nibwp_studio_builder_base((int) get_queried_object_id());
    if ($base !== '') {
        echo '<meta name="nibwp-builder-base" content="' . esc_attr($base) . '">' . "\n";
    }
}, 1);

/**
 * The Builder's saved CSS, every rule in one place. Read once a request
 * (never autoloaded: most pages use few of its rules); $fresh reads it again.
 */
function nibwp_studio_builder_css(bool $fresh = false): array
{
    static $css = null;
    if ($css === null || $fresh) {
        $v = get_option('nibwp_builder_css', []);
        $css = is_array($v) ? $v : [];
    }

    return $css;
}

/*
 * Saved classes are a library: a rule is printed only on pages that use its
 * classes and IDs, inline in the head, so no page carries CSS it does not
 * need. A rule with no class or ID in its selector (body, h2) prints always.
 */
add_action('template_redirect', static function (): void {
    if (is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !nibwp_studio_builder_css()) {
        return;
    }
    ob_start('nibwp_studio_builder_css_inline');
}, 0);

function nibwp_studio_builder_css_inline(string $html): string
{
    if (stripos($html, '</head>') === false) {
        return $html;
    }
    $used = [];
    foreach (nibwp_studio_builder_css() as $selector => $rule) {
        preg_match_all('/[.#](-?[_a-zA-Z][-_a-zA-Z0-9]*)/', (string) $selector, $m);
        $ok = true;
        foreach (array_unique($m[1]) as $name) {
            if (strpos($html, $name) === false) {
                $ok = false;
                break;
            }
        }
        // A rule left open (saved before rules were checked) is skipped, so
        // it cannot swallow the ones after it.
        if ($ok && nibwp_studio_builder_css_ok((string) $rule)) {
            $used[] = (string) $rule;
        }
    }
    if (!$used) {
        return $html;
    }
    $style = "<style id=\"nibwp-builder-css\">\n" . str_ireplace('</style', '<\/style', implode("\n", $used)) . "\n</style>\n";
    $at = strripos($html, '</head>');

    return substr($html, 0, $at) . $style . substr($html, $at);
}

/*
 * The page's scripts, each in its own <script> so one that breaks leaves the
 * others running. Scripts saved site-wide before they were kept per page
 * still print everywhere; new ones never go there.
 */
function nibwp_studio_builder_print_js(): void
{
    $legacy = get_option('nibwp_builder_js', '');
    $list = is_string($legacy) && trim($legacy) !== '' ? [trim($legacy)] : [];
    $tpl = nibwp_studio_builder_view_tpl();
    if ($tpl !== '') {
        $list = array_merge($list, nibwp_studio_builder_js_for(nibwp_studio_builder_tpl_key($tpl)));
    }
    $key = nibwp_studio_builder_view_key();
    if ($key !== '') {
        $list = array_merge($list, nibwp_studio_builder_js_for($key));
    }
    // A script for one element is printed once: its newest version (the page's over the template's).
    $list = array_values(array_unique($list));
    $last = [];
    foreach ($list as $i => $js) {
        if (preg_match('#^/\* nibwp:el (\{.*?\}) \*/#', $js, $m)) {
            $last[$m[1]] = $i;
        }
    }
    $list = array_values(array_filter($list, static fn($js, $i) => !preg_match('#^/\* nibwp:el (\{.*?\}) \*/#', $js, $m) || $last[$m[1]] === $i, ARRAY_FILTER_USE_BOTH));
    foreach ($list as $i => $js) {
        echo '<script id="nibwp-builder-js' . ($i ? '-' . ($i + 1) : '') . "\">\n" . str_ireplace('</script', '<\/script', $js) . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JS saved by an administrator with unfiltered_html
    }
}

add_action('wp_footer', 'nibwp_studio_builder_print_js', 100);
