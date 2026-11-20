<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Royal element pack for Elementor.
 *
 * Three post types, and only the first of them is a template library:
 * `wpr_templates` holds headers, footers, archive and single layouts and popups
 * together, `wpr_mega_menu` holds the dropdown a nav menu item opens, and
 * `wpr_custom_widget` holds a widget built with the pack's Widget Builder.
 *
 * `_elementor_template_type` is the kind. The pack keeps a second key,
 * `_wpr_template_type`, naming which part of the theme a template is, and the
 * widgets inside an archive or single template read that one before they render
 * anything — so it is filled in after a write rather than left empty.
 *
 * WHERE a template appears is not post meta: the pack keeps seven site options,
 * each holding JSON of {template slug: [locations]}. That is what the condition
 * adapter below reads and writes, one label per option, keyed by the template's
 * own post slug — which is also why rules cannot be set on a draft, since
 * WordPress gives a draft no slug.
 *
 * The on/off list is one option per toggle: `wpr-element-<slug>` for a widget,
 * `wpr-<name>` for a section extension, each holding 'on' or anything else. The
 * read below rebuilds that from the pack's own `Utilities` lists and
 * `get_option` rather than through the pack, whose extension reader memoises
 * into a private static on first call and answers a read after a write with the
 * value it cached.
 *
 * Everything else — the actions, the capability checks, the refusal to touch a
 * post that is not one of these — comes from the shared engine.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

// ---------------------------------------------------------------------------
// Display rules: seven site options, keyed by a template's post slug
// ---------------------------------------------------------------------------

/**
 * The site option each rule set lives in, in the order the pack's tabs read.
 *
 * The labels are the pack's own part names, which is not a coincidence — the
 * same word is the taxonomy term, the value of `_wpr_template_type` and the tab
 * the rules are edited on, so one vocabulary covers all of them.
 *
 * @return array<string, string> Label => option name.
 */
function nibwp_royal_addons_rule_options(): array
{
    return [
        'header' => 'wpr_header_conditions',
        'footer' => 'wpr_footer_conditions',
        'archive' => 'wpr_archive_conditions',
        'single' => 'wpr_single_conditions',
        'product_archive' => 'wpr_product_archive_conditions',
        'product_single' => 'wpr_product_single_conditions',
        'popup' => 'wpr_popup_conditions',
    ];
}

/**
 * One rule set as a map, read straight from its option every time.
 *
 * Nothing is cached: the pack stores these as a JSON string and re-reads the
 * option on every request, and a read taken right after a write here has to
 * show the write.
 *
 * @return array<string, mixed>|WP_Error
 */
function nibwp_royal_addons_rule_map(string $option): array|WP_Error
{
    $raw = get_option($option, '[]');

    if (is_array($raw)) {
        return $raw;
    }

    $raw = is_string($raw) ? trim($raw) : '';

    if ($raw === '' || $raw === '[]') {
        return [];
    }

    $map = json_decode($raw, true);

    if (!is_array($map)) {
        // The pack decodes this option the same way and is already behaving as
        // if it held no rules, so nothing is broken by refusing — but writing
        // over it would throw away the one copy a human could still repair.
        return new WP_Error(
            'nibwp_royal_addons_rules_unreadable',
            sprintf(
                /* translators: %s: name of a WordPress option */
                __('The add-on\'s "%s" setting is not readable as JSON, so this integration will not overwrite it. Repair it from the add-on\'s own screen first.', 'nibwp'),
                $option
            ),
            ['status' => 409]
        );
    }

    return $map;
}

/**
 * The pack's own location list, from whatever the caller sent.
 *
 * @return array<int, string>|WP_Error
 */
function nibwp_royal_addons_locations(mixed $value): array|WP_Error
{
    // One location sent as a bare string is the mistake worth absorbing rather
    // than refusing: the pack tests this list with in_array, so a string that
    // reached the option would be a TypeError on every front-end request.
    if (is_string($value)) {
        $value = $value === '' ? [] : [$value];
    }

    if ($value === null) {
        $value = [];
    }

    if (!is_array($value)) {
        return new WP_Error(
            'nibwp_royal_addons_bad_rule',
            __('A rule set is a list of location strings, for example ["global"]. Send an empty list to clear it.', 'nibwp'),
            ['status' => 400]
        );
    }

    $locations = [];

    foreach ($value as $location) {
        if (!is_scalar($location)) {
            return new WP_Error(
                'nibwp_royal_addons_bad_rule',
                __('A rule set is a list of location strings, for example ["global"]. Send an empty list to clear it.', 'nibwp'),
                ['status' => 400]
            );
        }

        $location = trim((string) $location);

        if ($location === '') {
            continue;
        }

        // A location is a path the pack splits on '/' and then scans for ids, so
        // anything outside that alphabet is a value it can never match — and a
        // value it can never match is a rule that silently does nothing.
        if (preg_match('#^[A-Za-z0-9_,/-]+$#', $location) !== 1) {
            return new WP_Error(
                'nibwp_royal_addons_bad_location',
                sprintf(
                    /* translators: %s: display-rule location that was rejected */
                    __('"%s" is not a location this add-on can match. A location reads like global, archive/posts or single/pages/12.', 'nibwp'),
                    $location
                ),
                ['status' => 400]
            );
        }

        $locations[] = $location;
    }

    return array_values(array_unique($locations));
}

/**
 * Which locations each rule set names for this template.
 *
 * @param array<string, mixed> $group
 * @return array<string, array<int, string>>
 */
function nibwp_royal_addons_read_rules(WP_Post $post, array $group): array
{
    $slug = (string) $post->post_name;
    $out = [];

    foreach (nibwp_royal_addons_rule_options() as $label => $option) {
        $map = nibwp_royal_addons_rule_map($option);
        $locations = $map instanceof WP_Error || $slug === '' ? [] : ($map[$slug] ?? []);
        $clean = [];

        if (is_array($locations)) {
            foreach ($locations as $location) {
                if (is_scalar($location)) {
                    $clean[] = (string) $location;
                }
            }
        }

        $out[$label] = $clean;
    }

    return $out;
}

/**
 * Point one rule set at this template, or clear it.
 *
 * @param array<string, mixed> $group
 */
function nibwp_royal_addons_write_rule(WP_Post $post, string $label, mixed $value, array $group): bool|WP_Error
{
    $option = (string) (nibwp_royal_addons_rule_options()[$label] ?? '');

    if ($option === '') {
        return new WP_Error(
            'nibwp_royal_addons_unknown_rule_set',
            __('That is not one of this add-on\'s rule sets.', 'nibwp'),
            ['status' => 400]
        );
    }

    $slug = (string) $post->post_name;

    if ($slug === '') {
        // Not a quirk of this integration: the pack files rules under a slug,
        // and wp_insert_post leaves post_name empty for a draft. There is
        // nothing to key the rule on until the template is published.
        return new WP_Error(
            'nibwp_royal_addons_no_slug',
            __('This add-on files display rules under a template\'s slug, and WordPress gives a draft no slug. Publish the template first, then set its rules.', 'nibwp'),
            ['status' => 400]
        );
    }

    $locations = nibwp_royal_addons_locations($value);

    if ($locations instanceof WP_Error) {
        return $locations;
    }

    $map = nibwp_royal_addons_rule_map($option);

    if ($map instanceof WP_Error) {
        return $map;
    }

    if ($locations === []) {
        // The pack's own save runs array_filter across this map, so a template
        // with nothing left is absent from it rather than present with an empty
        // list. Clearing rules therefore means removing the key, not blanking it.
        unset($map[$slug]);
    } else {
        $map[$slug] = $locations;
    }

    // Only this template's key is touched; every other one is written back
    // exactly as it was found. Slashes are left unescaped because the pack's own
    // save passes its JSON through wp_unslash, so that is the shape on disk.
    update_option($option, wp_json_encode($map, JSON_UNESCAPED_SLASHES));

    return true;
}

/**
 * Fill in the part key the pack's own templates carry, when it is missing.
 *
 * `_elementor_template_type` is what Elementor loads the editor from, but the
 * theme-builder widgets INSIDE an archive or single template check
 * `_wpr_template_type` before they render anything — so a template created
 * without it opens fine, saves fine and outputs nothing at all.
 *
 * Only ever filled in when it is absent. A value already there was written by
 * the pack from its own screen, and recomputing over it would quietly move a
 * template between the tabs the owner filed it under.
 *
 * @param array<string, mixed> $pack
 */
function nibwp_royal_addons_after_template_write(int $post_id, array $pack): void
{
    $post = get_post($post_id);

    if (!$post instanceof WP_Post || $post->post_type !== 'wpr_templates' || $post->post_status === 'trash') {
        return;
    }

    if ((string) get_post_meta($post_id, '_wpr_template_type', true) !== '') {
        return;
    }

    $kind = (string) get_post_meta($post_id, '_elementor_template_type', true);

    // A popup has no part key of its own — the pack writes one for theme parts
    // only, and a popup's kind already says what it is.
    if ($kind === '' || $kind === 'wpr-popups') {
        return;
    }

    $parts = [
        'wpr-theme-builder-header' => 'header',
        'wpr-theme-builder-footer' => 'footer',
    ];

    $part = (string) ($parts[$kind] ?? '');

    if ($part === '') {
        // The generic kind covers four parts and only the rule set a template
        // was filed under tells them apart. First match in the declared order
        // wins, so two calls on the same template give the same answer.
        foreach (nibwp_royal_addons_read_rules($post, []) as $label => $locations) {
            if ($label !== 'popup' && $locations !== []) {
                $part = (string) $label;
                break;
            }
        }
    }

    if ($part === '') {
        return;
    }

    update_post_meta($post_id, '_wpr_template_type', $part);
}

/**
 * Take this template out of every rule set while its slug is still readable.
 *
 * A key whose template has gone is not inert. The pack picks the LAST template
 * marked global across a whole option and only afterwards checks that it still
 * exists — and when it does not, it gives up rather than trying the next
 * candidate. So one abandoned key can beat the template that replaced it and
 * leave the site with no header at all.
 *
 * This runs for a trash as much as for a forced delete. The hook is not told
 * which, and it would not change the answer: WordPress suffixes a trashed post's
 * slug, so the key stops resolving either way and the breakage is identical.
 * What is removed is copied onto the post first — pointless on a forced delete,
 * where it goes with everything else, and the whole point on a trash the owner
 * later restores.
 *
 * @param array<string, mixed> $pack
 */
function nibwp_royal_addons_before_template_delete(WP_Post $post, array $pack): void
{
    if ($post->post_type !== 'wpr_templates') {
        return;
    }

    $slug = (string) $post->post_name;
    $suffix = '__trashed';

    // Already in the trash, on its way out for good: WordPress renamed the slug
    // when it went in, but the rules are still filed under the original.
    if ($post->post_status === 'trash' && str_ends_with($slug, $suffix)) {
        $slug = substr($slug, 0, -strlen($suffix));
    }

    if ($slug === '') {
        return;
    }

    $removed = [];

    foreach (nibwp_royal_addons_rule_options() as $label => $option) {
        $map = nibwp_royal_addons_rule_map($option);

        // An option that is not readable as JSON is left exactly as it is, the
        // same way the writer refuses to overwrite one.
        if ($map instanceof WP_Error || !array_key_exists($slug, $map)) {
            continue;
        }

        $removed[$label] = $map[$slug];
        unset($map[$slug]);

        // Only this template's key goes; every other one is written back as it
        // was found, and an option this template was never in is not touched.
        update_option($option, wp_json_encode($map, JSON_UNESCAPED_SLASHES));
    }

    if ($removed !== []) {
        // Kept under our own key, so the pack never reads it and cannot be
        // confused by it. A restored template still carries what it used to
        // match, ready to be set again with update_template.
        update_post_meta($post->ID, '_nibwp_royal_addons_removed_rules', $removed);
    }
}

// ---------------------------------------------------------------------------
// Mega menus: attached to a nav menu item, not to a rule
// ---------------------------------------------------------------------------

/**
 * Which nav menu items open each mega menu.
 *
 * The link is a meta key on the MENU ITEM pointing at the mega menu post, so
 * the only way to ask "is this dropdown attached to anything" is from the other
 * end. Collected once per request rather than per row: listing templates would
 * otherwise run one query per mega menu, and nothing here ever writes the link,
 * so there is no write for a cached answer to fall behind.
 *
 * @return array<int, array<int, int>> Mega menu post id => nav menu item ids.
 */
function nibwp_royal_addons_mega_menu_links(): array
{
    static $links = null;

    if (is_array($links)) {
        return $links;
    }

    $links = [];

    $items = get_posts([
        'post_type' => 'nav_menu_item',
        'post_status' => 'any',
        'numberposts' => 500,
        'meta_key' => 'wpr-mega-menu-item',
        'fields' => 'ids',
        'suppress_filters' => false,
    ]);

    foreach ($items as $item_id) {
        $target = (int) get_post_meta((int) $item_id, 'wpr-mega-menu-item', true);

        if ($target > 0) {
            $links[$target][] = (int) $item_id;
        }
    }

    return $links;
}

/**
 * The menu items this mega menu is attached to. Read only.
 *
 * @param array<string, mixed> $group
 * @return array<string, array<int, int>>
 */
function nibwp_royal_addons_read_menu_links(WP_Post $post, array $group): array
{
    $links = nibwp_royal_addons_mega_menu_links();

    return ['menu_items' => $links[$post->ID] ?? []];
}

// ---------------------------------------------------------------------------
// Widgets and section extensions: one option per toggle
// ---------------------------------------------------------------------------

/**
 * Every toggle the pack offers, grouped as the pack itself groups them.
 *
 * The grouping is not cosmetic: the pack's module loader treats a group with
 * nothing left switched on as a group that was never configured, so the reader
 * below needs to know which slugs belong together.
 *
 * @return array<string, array<string, string>>|WP_Error Group => (slug => option name).
 */
function nibwp_royal_addons_toggle_groups(): array|WP_Error
{
    $utilities = 'WprAddons\\Classes\\Utilities';

    // The class only exists once Elementor has loaded and the pack's own
    // plugin.php has been required, which is the same condition as the pack
    // being usable at all.
    if (!class_exists($utilities)) {
        return new WP_Error(
            'nibwp_royal_addons_no_widget_list',
            __('This version of the add-on does not publish a list of widgets that can be switched off.', 'nibwp'),
            ['status' => 400]
        );
    }

    $lists = [
        'widgets' => 'get_registered_modules',
        'theme_builder' => 'get_theme_builder_modules',
        'woocommerce' => 'get_woocommerce_builder_modules',
    ];

    $groups = [];

    foreach ($lists as $group => $method) {
        if (!method_exists($utilities, $method)) {
            continue;
        }

        $entries = call_user_func([$utilities, $method]);

        if (!is_array($entries)) {
            continue;
        }

        foreach ($entries as $entry) {
            // A row is [slug, demo url, anchor, badge], except in the list the
            // pack's premium build contributes, where a row may be the slug on
            // its own — the pack's own settings screen allows for both.
            $slug = is_array($entry) ? (string) ($entry[0] ?? '') : (string) $entry;

            if ($slug === '') {
                continue;
            }

            $groups[$group][$slug] = 'wpr-element-' . $slug;
        }
    }

    // The eight extension toggles are named here rather than asked of the pack:
    // it registers them on admin_init only, so on a REST request there is
    // nothing to ask. Each one is its own option, named exactly as the slug.
    $extensions = [
        'wpr-particles',
        'wpr-parallax-background',
        'wpr-parallax-multi-layer',
        'wpr-custom-css',
        'wpr-display-conditions',
        'wpr-equal-height',
        'wpr-column-slider',
        'wpr-sticky-section',
    ];

    foreach ($extensions as $extension) {
        $groups['extensions'][$extension] = $extension;
    }

    return $groups;
}

/**
 * The widget and extension slugs this pack offers and whether each is on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_royal_addons_module_state(): array|WP_Error
{
    $groups = nibwp_royal_addons_toggle_groups();

    if ($groups instanceof WP_Error) {
        return $groups;
    }

    // Has the Elements screen ever been saved? If it has not, the pack ignores
    // the per-widget options for any group in which nothing is left on and
    // loads the whole group anyway. Reporting that group as off would be a lie
    // the editor contradicts, so the same test is made here.
    $never_saved = get_option('wpr-element-toggle-all') === false;

    $state = [];

    foreach ($groups as $group => $options) {
        $group_state = [];

        foreach ($options as $slug => $option) {
            // Anything that is not exactly 'on' is off, and an option the pack
            // has never written is ON — the test the pack makes itself.
            $group_state[$slug] = get_option($option, 'on') === 'on';
        }

        if ($never_saved && $group !== 'extensions' && array_filter($group_state) === []) {
            $group_state = array_map(static fn(bool $on): bool => true, $group_state);
        }

        $state = array_merge($state, $group_state);
    }

    return $state;
}

/**
 * Switch one widget or extension on or off, in the pack's own storage format.
 */
function nibwp_royal_addons_set_module(string $slug, bool $on): bool|WP_Error
{
    $groups = nibwp_royal_addons_toggle_groups();

    if ($groups instanceof WP_Error) {
        return $groups;
    }

    $option = '';

    foreach ($groups as $options) {
        if (isset($options[$slug])) {
            $option = (string) $options[$slug];
            break;
        }
    }

    if ($option === '') {
        return new WP_Error(
            'nibwp_royal_addons_unknown_module',
            __('That widget or extension is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    // Every toggle is an option of its own, so writing one cannot disturb any
    // other setting. 'off' rather than the empty string the pack's own form
    // leaves behind: the pack compares against 'on' and nothing else, so the two
    // behave identically and 'off' says what it means to whoever reads the row.
    update_option($option, $on ? 'on' : 'off');

    return true;
}

nibwp_elpack_register([
    'key' => 'royal-addons',
    'name' => 'Royal Addons for Elementor',
    'dir' => 'royal-elementor-addons',
    'constants' => ['WPR_ADDONS_VERSION'],
    'classes' => ['WprAddons\\Plugin', 'WprAddons\\Classes\\Utilities'],
    'description' => 'Manage this element pack on this site: list the headers, footers, archive and single layouts and popups it keeps in one template library, '
        . 'create one, and set which pages each of them appears on. Also lists its mega menu dropdowns and the widgets built with its widget builder, switches '
        . 'any of its widgets or section extensions on or off, and reports templates that are published with no display rules, so they render nowhere.',
    'after_template_write' => 'nibwp_royal_addons_after_template_write',
    'before_template_delete' => 'nibwp_royal_addons_before_template_delete',
    'templates' => [
        [
            // The library: every theme part and every popup is one of these,
            // told apart by the kind.
            'post_type' => 'wpr_templates',
            'kind_meta' => '_elementor_template_type',
            'kinds' => [
                'wpr-theme-builder',
                'wpr-theme-builder-header',
                'wpr-theme-builder-footer',
                'wpr-popups',
            ],
            // One label per rule set, because that is how the pack stores them:
            // seven options, each a JSON map of template slug => locations. No
            // meta key can name any of them, hence the adapter.
            'conditions_labels' => [
                'header',
                'footer',
                'archive',
                'single',
                'product_archive',
                'product_single',
                'popup',
            ],
            'conditions_read' => 'nibwp_royal_addons_read_rules',
            'conditions_write' => 'nibwp_royal_addons_write_rule',
            // The pack stamps this on every template it creates so the layout
            // outputs without the theme's header, footer and sidebars around it.
            'create_meta' => [
                '_wp_page_template' => 'elementor_canvas',
            ],
            // No kinds_without_conditions: all four of this pack's kinds are
            // output by a rule, and every one of those rule sets is declared
            // above, so a published template with none really does render
            // nowhere.
        ],
        [
            // One per nav menu item that has a mega menu: it holds the dropdown
            // layout and nothing else.
            'post_type' => 'wpr_mega_menu',
            // Reported, never written — the link lives on the menu item. Worth
            // declaring anyway: it is what makes the audit name a dropdown no
            // menu item opens any more.
            'conditions_labels' => ['menu_items'],
            'conditions_read' => 'nibwp_royal_addons_read_menu_links',
        ],
        [
            // A widget built in the pack's widget builder. Its definition is
            // meta, and the PHP it generates lives in the uploads folder.
            'post_type' => 'wpr_custom_widget',
        ],
    ],
    'modules' => [
        'label' => 'widget or extension',
        'read' => 'nibwp_royal_addons_module_state',
        'write' => 'nibwp_royal_addons_set_module',
    ],
    'notes' => [
        "POST TYPES: wpr_templates is the template library — headers, footers,\n"
        . "archive layouts, single layouts and popups all live in it, and the kind\n"
        . "is the only thing telling them apart. wpr_mega_menu is the dropdown a\n"
        . "nav menu item opens; the pack makes one per item, titled\n"
        . "wpr-mega-menu-item-<item id>, and the link is a meta key on the MENU\n"
        . "ITEM pointing at the post. Deleting the post does NOT switch the\n"
        . "dropdown off: whether an item has one is a separate setting on the item\n"
        . "(wpr-mega-menu-settings.wpr_mm_enable), and while it says yes the item's\n"
        . "own WordPress sub-items stay hidden — so the visitor gets an empty\n"
        . "dropdown. Neither key can be reached from here; turn the item's mega menu\n"
        . "off on the WordPress menus screen if the dropdown is really going away.\n"
        . "wpr_custom_widget is a widget built in the pack's widget builder.",

        "DISPLAY RULES: one label per rule set — header, footer, archive, single,\n"
        . "product_archive, product_single, popup — and each value is a list of\n"
        . "locations: ['global'], ['archive/posts'], ['single/pages/12'],\n"
        . "['product_single/product/9']. A location is 'global', or\n"
        . "<set>/<what>, or <set>/<what>/<object ids>. Send [] to clear a set.\n"
        . "The rule set, not the kind, is what decides where a template is output:\n"
        . "filing a footer template under header renders it as the site header, so\n"
        . "keep the two in step. And the pack resolves 'global' last-one-wins\n"
        . "across a whole option, so two templates both marked global means the\n"
        . "later one shows and the earlier one silently does not.",

        "RULES ARE FILED UNDER A TEMPLATE'S SLUG, not its id, which has two\n"
        . "consequences. A draft has no slug in WordPress, so setting rules on one\n"
        . "is refused until it is published. And renaming a published template's\n"
        . "slug unhooks every rule it had, because the key stops matching — set the\n"
        . "rules again afterwards.",

        "DELETING A TEMPLATE ALSO CLEARS ITS RULES, from all seven options, on a\n"
        . "trash as much as on a forced delete — an abandoned key can win the\n"
        . "last-one-wins resolution and leave the site with no header at all, so it\n"
        . "cannot be left behind. Nothing else in any option is touched. What was\n"
        . "removed is first copied onto the template itself as\n"
        . "_nibwp_royal_addons_removed_rules: on a forced delete that goes with the\n"
        . "post, but a template that was trashed and then restored still carries\n"
        . "what it used to match, ready to set again with update_template.",

        "TEMPLATE KINDS: wpr-theme-builder, wpr-theme-builder-header,\n"
        . "wpr-theme-builder-footer and wpr-popups. Pair the kind with the rule\n"
        . "set: header with wpr-theme-builder-header, footer with -footer, archive,\n"
        . "single, product_archive and product_single with the generic\n"
        . "wpr-theme-builder, popup with wpr-popups. The pack's second key,\n"
        . "_wpr_template_type, is filled in for you after a write — the widgets\n"
        . "inside an archive or single template check it before they render, so\n"
        . "without it the template opens and saves and still outputs nothing. It is\n"
        . "derived from the kind, or for the generic kind from whichever rule set\n"
        . "the template was filed under, and is never written over a value the\n"
        . "pack put there itself.",

        "A TEMPLATE CREATED HERE RENDERS BUT DOES NOT APPEAR ON THE ADD-ON'S OWN\n"
        . "SCREENS. To be listed there a template needs all four of: post status\n"
        . "publish; the wpr_template_type taxonomy carrying BOTH the part name and\n"
        . "the term 'user'; a post slug containing 'user-'; and _wpr_template_type\n"
        . "set. Only the last is written from here — the taxonomy cannot be\n"
        . "expressed, and the slug is the key its rules are filed under so it is\n"
        . "not rewritten. Create theme parts and popups from the add-on's own\n"
        . "dashboard when the owner needs to manage them there, and use this\n"
        . "ability for everything afterwards.",

        "POPUP TRIGGERS are Elementor document settings on the popup itself, in\n"
        . "the _elementor_page_settings meta (popup_trigger, popup_load_delay,\n"
        . "popup_show_for_roles, popup_animation and the rest). That one meta key\n"
        . "holds every page setting the document has, so it is not exposed here:\n"
        . "writing it wholesale would drop whatever else was in it. Edit a popup's\n"
        . "triggers in the Elementor editor. Its rule set only decides which pages\n"
        . "carry the popup at all.",

        "MEGA MENUS report menu_items and refuse a write to it: the link is a meta\n"
        . "key on the nav menu item, so only the WordPress menus screen can attach\n"
        . "one. An audit finding against a mega menu means no menu item opens it\n"
        . "any more — attach it there, do not try update_template. Creating one\n"
        . "from here gives you a dropdown nothing opens, for the same reason.",

        "AUDIT, ONE FALSE POSITIVE: a wpr_templates post used only as an\n"
        . "embeddable block — dropped into a page with the Template widget or the\n"
        . "[wpr-template] shortcode — is correct with no rules at all, and nothing\n"
        . "in its meta distinguishes it from a theme part, so it is reported as\n"
        . "published with no display rules. Check what a template is for before\n"
        . "acting on that finding.",

        "WIDGETS AND EXTENSIONS share one list because they are switched the same\n"
        . "way, one option per toggle. A slug the pack has never written is ON, not\n"
        . "off. Two things do not follow from the reported state: the WooCommerce\n"
        . "builder widgets only load when WooCommerce is active however they are\n"
        . "set, and widgets that exist only in the pack's premium build are absent\n"
        . "from the list, so enable_module refuses them by name.",

        "SWITCHING EVERY WIDGET IN A GROUP OFF does not switch the group off. If\n"
        . "the add-on's Elements screen has never been saved, the pack reads a\n"
        . "group with nothing left on as a group nobody configured and loads all of\n"
        . "it. list_modules reports that resolved state, which is why disabling the\n"
        . "last widget of a group can come back reporting the group as on. Save the\n"
        . "Elements screen once from wp-admin, or leave one widget on.",

        "CUSTOM WIDGETS only reach the editor when the post is published AND the\n"
        . "PHP the widget builder generated for it still exists under\n"
        . "uploads/wpr-addons/custom_widgets/. create_template makes the post and\n"
        . "nothing else, so the result is an empty shell until its markup and\n"
        . "controls are defined in the widget builder. delete_template with force\n"
        . "removes the generated files too; trashing it leaves them in place.",
    ],
]);
