<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel Pro — vocabulary.
 *
 * The curated part of this file says what each widget is FOR and which of its
 * settings a template cannot work without. Which widgets exist is never read
 * from here: that comes from Elementor's live registry, so a theme upgrade
 * that renames a widget is reported rather than guessed around.
 */

/** Voxel + Elementor both have to be present before any of this means anything. */
function nibwp_voxel_pro_guard(): ?WP_Error
{
    if (!class_exists('\\Voxel\\Post') || !function_exists('\\Voxel\\get')) {
        return new WP_Error(
            'voxel_unavailable',
            'The Voxel theme is not active on this site. Activate Voxel (or its child theme) and try again.',
            ['status' => 404]
        );
    }
    if (!did_action('elementor/loaded') && !class_exists('\\Elementor\\Plugin')) {
        return new WP_Error(
            'elementor_unavailable',
            'Elementor is not active. Voxel templates are Elementor documents, so Elementor has to be running to build one.',
            ['status' => 404]
        );
    }
    return null;
}

/**
 * Template kind → how the document is created and what type it is stored as.
 *
 * Style kits and taxonomy templates are stored as `page`-type documents inside
 * the Elementor library — verified against a demo install, not assumed.
 */
function nibwp_voxel_pro_kinds(): array
{
    return [
        'card'               => ['doc' => 'template', 'type' => 'card',        'needs_post_type' => true,  'label' => 'Preview card'],
        'single-post'        => ['doc' => 'template', 'type' => 'single-post', 'needs_post_type' => true,  'label' => 'Single post layout'],
        'archive'            => ['doc' => 'template', 'type' => 'archive',     'needs_post_type' => true,  'label' => 'Archive layout'],
        'single-term'        => ['doc' => 'template', 'type' => 'single-term', 'needs_post_type' => false, 'label' => 'Single term layout'],
        'header'             => ['doc' => 'template', 'type' => 'header',      'needs_post_type' => false, 'label' => 'Header'],
        'footer'             => ['doc' => 'template', 'type' => 'footer',      'needs_post_type' => false, 'label' => 'Footer'],
        'style-kit-popup'    => ['doc' => 'template', 'type' => 'page',        'needs_post_type' => false, 'label' => 'Popup style kit'],
        'style-kit-timeline' => ['doc' => 'template', 'type' => 'page',        'needs_post_type' => false, 'label' => 'Timeline style kit'],
        'search-page'        => ['doc' => 'page',     'type' => 'page',        'needs_post_type' => false, 'label' => 'Search page'],
        'page'               => ['doc' => 'page',     'type' => 'page',        'needs_post_type' => false, 'label' => 'Page'],
    ];
}

/**
 * The curated widget map.
 *
 * `required` names settings the widget cannot function without — missing one
 * fails the build. `role` is what drives automatic search↔feed↔map wiring.
 */
function nibwp_voxel_pro_widgets(): array
{
    return [
        'ts-search-form' => [
            'role'     => 'search',
            'purpose'  => 'Faceted search UI. Drives a post feed and/or a map on the same document.',
            'required' => ['ts_choose_post_types'],
            'notes'    => 'Filters go in the repeater ts_filter_list__{post_type}. Each item picks a filter with ts_choose_filter, and that filter\'s own controls are namespaced "{filter_key}:{control}" — e.g. "terms:display_as". ts_on_submit: post-to-feed (default) | submit-to-archive | submit-to-page.',
        ],
        'ts-post-feed' => [
            'role'     => 'feed',
            'purpose'  => 'Renders results as preview cards, in a grid or a carousel.',
            'required' => [],
            'notes'    => 'ts_source: search-form (the default when omitted) | search-filters | manual | archive. Which card is used per post type: ts_card_template__{post_type} = "main" or a custom card template ID. Grid vs carousel is ts_wrap_feed.',
        ],
        'ts-map' => [
            'role'     => 'map',
            'purpose'  => 'Map with listing markers and popup cards.',
            'required' => [],
            'notes'    => 'Marker style comes from the post type\'s own settings, not from here. ts_card_template_map__{post_type} picks the popup card.',
        ],
        'quick-search' => [
            'role'     => 'search',
            'purpose'  => 'Typeahead search overlay.',
            'required' => ['ts_choose_post_types'],
            'notes'    => 'Standalone — it does not wire to a feed.',
        ],
        'ts-term-feed' => [
            'role'     => 'feed',
            'purpose'  => 'Feed of taxonomy terms as cards.',
            'required' => ['ts_choose_taxonomy'],
            'notes'    => 'Uses term card templates, not post cards.',
        ],
        'ts-create-post' => [
            'role'     => 'form',
            'purpose'  => 'Front-end submission and edit form for a post type.',
            'required' => ['ts_post_type'],
            'notes'    => 'Belongs on the post type\'s "form" template, which is a page rather than a library template.',
        ],
        'ts-advanced-list' => [
            'role'     => 'content',
            'purpose'  => 'Row of actions and meta — follow, share, direct message, verified badge, opening status.',
            'required' => [],
            'notes'    => 'Repeater ts_actions. This is the workhorse of a preview card. Per-item visibility rules go on the repeater item itself.',
        ],
        'ts-gallery'      => ['role' => 'content', 'purpose' => 'Image grid or mosaic with lightbox.', 'required' => [], 'notes' => 'Point it at a gallery field with a dynamic tag.'],
        'ts-slider'       => ['role' => 'content', 'purpose' => 'Carousel of images.', 'required' => [], 'notes' => ''],
        'ts-work-hours'   => ['role' => 'content', 'purpose' => 'Opening hours table.', 'required' => ['ts_source_field'], 'notes' => 'ts_source_field names the post type field holding the hours.'],
        'ts-review-stats' => ['role' => 'content', 'purpose' => 'Rating breakdown for a listing.', 'required' => [], 'notes' => ''],
        'ts-ring-chart'   => ['role' => 'content', 'purpose' => 'Donut chart.', 'required' => [], 'notes' => ''],
        'ts-countdown'    => ['role' => 'content', 'purpose' => 'Countdown to a date.', 'required' => [], 'notes' => ''],
        'ts-visits-chart' => ['role' => 'content', 'purpose' => 'Post visit statistics chart.', 'required' => [], 'notes' => ''],
        'ts-timeline'     => ['role' => 'content', 'purpose' => 'Newsfeed, wall, reviews or comments.', 'required' => [], 'notes' => 'ts_mode picks which of those it is.'],
        'ts-orders'       => ['role' => 'content', 'purpose' => 'Orders dashboard.', 'required' => [], 'notes' => ''],
        'ts-product-form' => ['role' => 'content', 'purpose' => 'Booking / purchase form for a product field.', 'required' => [], 'notes' => ''],
        'ts-product-price'=> ['role' => 'content', 'purpose' => 'Price display for a product field.', 'required' => [], 'notes' => ''],
        'ts-cart-summary' => ['role' => 'content', 'purpose' => 'Cart and checkout summary.', 'required' => [], 'notes' => ''],
        'ts-login'        => ['role' => 'content', 'purpose' => 'Login and registration screens.', 'required' => [], 'notes' => 'Belongs on the template assigned to templates.auth.'],
        'ts-current-role' => ['role' => 'content', 'purpose' => 'Shows or switches the current user role.', 'required' => [], 'notes' => ''],
        'ts-navbar'       => ['role' => 'nav', 'purpose' => 'Menu, tabs, or a nav bound to a search form.', 'required' => [], 'notes' => 'Can bind to a template-tabs or search-form widget on the same document.'],
        'ts-user-bar'     => ['role' => 'nav', 'purpose' => 'Account bar: messages, notifications, cart, menus.', 'required' => [], 'notes' => 'Repeater user_area_repeater, one item per component.'],
        'ts-template-tabs'=> ['role' => 'content', 'purpose' => 'Tabs whose panels are other templates.', 'required' => ['ts_tabs'], 'notes' => 'Each tab names a template_id.'],
        'ts-print-template' => ['role' => 'content', 'purpose' => 'Embeds another Elementor template.', 'required' => ['ts_template_id'], 'notes' => 'Use it to reuse a section instead of duplicating it.'],
        'vx-native-dialog'=> ['role' => 'content', 'purpose' => 'Native dialog wrapper.', 'required' => [], 'notes' => ''],
        'ts-test-widget-1'=> ['role' => 'kit', 'purpose' => 'Popup style kit — styles every popup on the site.', 'required' => [], 'notes' => 'The only widget on the template assigned to templates.kit_popups. Its name is historical.'],
        'ts-timeline-kit' => ['role' => 'kit', 'purpose' => 'Timeline style kit — styles the timeline everywhere.', 'required' => [], 'notes' => 'The only widget on the template assigned to templates.kit_timeline.'],
    ];
}

/** Which curated widget is required to be the sole content of each kit kind. */
function nibwp_voxel_pro_kit_widget(string $kind): string
{
    return $kind === 'style-kit-popup' ? 'ts-test-widget-1' : ($kind === 'style-kit-timeline' ? 'ts-timeline-kit' : '');
}

/** Every widget type Elementor currently knows about on this site. */
function nibwp_voxel_pro_live_widget_types(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!class_exists('\\Elementor\\Plugin')) {
        return $cache;
    }
    try {
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        if ($manager === null) {
            return $cache;
        }
        $cache = array_keys((array) $manager->get_widget_types());
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** The curated map, annotated with what this site actually has. */
function nibwp_voxel_pro_widget_catalog(string $post_type = ''): array
{
    $live     = nibwp_voxel_pro_live_widget_types();
    $curated  = nibwp_voxel_pro_widgets();
    $widgets  = [];
    foreach ($curated as $type => $meta) {
        $widgets[] = [
            'type'      => $type,
            'role'      => $meta['role'],
            'purpose'   => $meta['purpose'],
            'required'  => $meta['required'],
            'notes'     => $meta['notes'],
            'available' => $live === [] ? null : in_array($type, $live, true),
        ];
    }

    $out = [
        'widgets' => $widgets,
        'kinds'   => array_map(
            static fn(array $k): array => ['type' => $k['type'], 'needs_post_type' => $k['needs_post_type'], 'label' => $k['label']],
            nibwp_voxel_pro_kinds()
        ),
        'element_extras' => [
            '_voxel_visibility_behavior' => 'show | hide — pair it with _voxel_visibility_rules to control when an element appears.',
            '_voxel_visibility_rules'    => 'Array of OR-groups of AND-conditions, e.g. [[{"type":"post:is_verified"}]]. Works on any element and on repeater items.',
            '_voxel_loop'                => 'A dynamic tag naming a list, e.g. "@tags()@post(gallery)@endtags()". The element repeats once per entry.',
            '_voxel_dynamic_css'         => 'Raw CSS for this element. Accepts dynamic tags.',
        ],
        'reminder' => 'Any Elementor widget works too — heading, image, text-editor, button, container. Voxel\'s widgets are for what Voxel owns; ordinary widgets carry the rest, bound to data with dynamic tags.',
    ];

    if ($post_type !== '') {
        $out['post_type'] = nibwp_voxel_pro_post_type_vocabulary($post_type);
    }
    return $out;
}

/** Filter, field and card vocabulary for one post type, read from this site. */
function nibwp_voxel_pro_post_type_vocabulary(string $post_type): array
{
    $pt = \Voxel\Post_Type::get($post_type);
    if (!$pt) {
        $types = array_keys((array) \Voxel\get('post_types', []));
        return ['error' => sprintf('No Voxel post type "%s". This site has: %s.', $post_type, implode(', ', $types))];
    }

    $filters = [];
    foreach ((array) $pt->get_filters() as $key => $filter) {
        $filters[] = ['key' => (string) $key, 'type' => method_exists($filter, 'get_type') ? (string) $filter->get_type() : '', 'label' => method_exists($filter, 'get_label') ? (string) $filter->get_label() : ''];
    }
    $fields = [];
    foreach ((array) $pt->get_fields() as $key => $field) {
        $fields[] = ['key' => (string) $key, 'type' => method_exists($field, 'get_type') ? (string) $field->get_type() : ''];
    }

    $templates = (array) $pt->get_templates();
    $custom = (array) $pt->templates->get_custom_templates();
    $cards = [];
    foreach ((array) ($custom['card'] ?? []) as $entry) {
        $entry = (array) $entry;
        $cards[] = ['id' => (int) ($entry['id'] ?? 0), 'label' => (string) ($entry['label'] ?? '')];
    }

    return [
        'key'          => $post_type,
        'filters'      => $filters,
        'fields'       => $fields,
        'templates'    => $templates,
        'custom_cards' => $cards,
        'card_setting' => 'ts_card_template__' . $post_type,
        'filter_repeater' => 'ts_filter_list__' . $post_type,
    ];
}

/** One widget's controls, read live from Elementor. */
function nibwp_voxel_pro_widget_controls(string $widget_type): array|WP_Error
{
    $widget_type = trim($widget_type);
    if ($widget_type === '') {
        return new WP_Error('missing_widget', 'Pass "widget" with a widget type, e.g. "ts-search-form".');
    }
    $live = nibwp_voxel_pro_live_widget_types();
    if ($live !== [] && !in_array($widget_type, $live, true)) {
        $near = array_values(array_filter($live, static fn(string $t): bool => str_starts_with($t, 'ts-') || $t === 'quick-search'));
        return new WP_Error('unknown_widget', sprintf('No widget "%s" on this site. Voxel widgets here: %s.', $widget_type, implode(', ', $near)));
    }

    try {
        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types($widget_type);
    } catch (\Throwable $e) {
        return new WP_Error('widget_unreadable', $e->getMessage());
    }
    if ($widget === null) {
        return new WP_Error('unknown_widget', sprintf('Elementor could not build "%s".', $widget_type));
    }

    $controls = [];
    foreach ((array) $widget->get_controls() as $name => $control) {
        $control = (array) $control;
        if (($control['type'] ?? '') === 'section') {
            continue;
        }
        $row = [
            'name' => (string) $name,
            'type' => (string) ($control['type'] ?? ''),
        ];
        if (isset($control['default']) && (is_scalar($control['default']) || is_array($control['default']))) {
            $row['default'] = $control['default'];
        }
        if (!empty($control['options']) && is_array($control['options'])) {
            $row['options'] = array_slice(array_keys($control['options']), 0, 40);
        }
        if (!empty($control['label'])) {
            $row['label'] = (string) $control['label'];
        }
        $controls[] = $row;
    }

    $curated = nibwp_voxel_pro_widgets()[$widget_type] ?? null;
    return [
        'widget'        => $widget_type,
        'title'         => method_exists($widget, 'get_title') ? (string) $widget->get_title() : '',
        'purpose'       => $curated['purpose'] ?? '',
        'required'      => $curated['required'] ?? [],
        'notes'         => $curated['notes'] ?? '',
        'control_count' => count($controls),
        'controls'      => $controls,
    ];
}

/** Where a finished template can be assigned. */
function nibwp_voxel_pro_slot_catalog(string $post_type = ''): array
{
    $site = (array) \Voxel\get('templates', []);
    $slots = [];
    foreach (nibwp_voxel_pro_site_slots() as $slot => $note) {
        $slots[] = ['slot' => $slot, 'current' => (int) ($site[$slot] ?? 0), 'note' => $note];
    }

    $post_types = [];
    foreach ((array) \Voxel\get('post_types', []) as $key => $cfg) {
        $cfg = (array) $cfg;
        $post_types[(string) $key] = (array) ($cfg['templates'] ?? []);
    }

    $out = [
        'site_slots'   => $slots,
        'post_types'   => $post_types,
        'post_type_slots' => ['single', 'card', 'archive', 'form'],
        'how' => 'Assign with the build ability\'s assign block: {slot} for a site slot, or {post_type, pt_slot} for a post type\'s own. mode:"custom" with a label adds a named alternate card instead of replacing the main one.',
        'warning' => 'kit_popups and kit_timeline restyle the whole site. Assigning either needs confirm_kit:true.',
    ];
    if ($post_type !== '') {
        $out['scoped'] = nibwp_voxel_pro_post_type_vocabulary($post_type);
    }
    return $out;
}

/** Site-wide slots this skill will write, with what each one does. */
function nibwp_voxel_pro_site_slots(): array
{
    return [
        'header'       => 'Site header, unless a conditional header matches first.',
        'footer'       => 'Site footer.',
        'auth'         => 'Login and registration page.',
        'orders'       => 'Orders dashboard page.',
        'checkout'     => 'Cart summary page.',
        'timeline'     => 'Timeline page.',
        'inbox'        => 'Direct messages page.',
        'post_stats'   => 'Listing statistics page.',
        '404'          => 'Not-found template.',
        'restricted'   => 'Restricted-content template.',
        'term_single'  => 'Default single term layout.',
        'term_card'    => 'Default term preview card.',
        'kit_popups'   => 'Popup style kit — site-wide. Needs confirm_kit.',
        'kit_timeline' => 'Timeline style kit — site-wide. Needs confirm_kit.',
        'terms'        => 'Terms page.',
        'privacy_policy' => 'Privacy policy page.',
    ];
}
