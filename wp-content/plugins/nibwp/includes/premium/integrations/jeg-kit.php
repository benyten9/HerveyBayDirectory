<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Jeg Kit widget pack and its theme builder.
 *
 * Templates are split across three post types rather than one: `jkit-header`,
 * `jkit-footer` and `jkit-template`, the last of which holds every other slot
 * and says which in its `jkit-template-type` meta. All three keep their display
 * rules in the single `jkit-condition` meta key, so this descriptor reports one
 * condition group called `display` for each of them.
 *
 * The element on/off list is the `jkit_elements_enable` option, keyed by the
 * same slug the pack gives the Elementor widget. It lists what is ON, and a slug
 * that is absent is ON too — the pack registers an element unless the option
 * says otherwise, so an empty option is a site with everything enabled.
 *
 * The read below takes the slug list from the pack and the state from the option
 * directly: the pack's own writer merges into the stored array and nothing
 * memoises it, so a read after a write in the same request sees the write.
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

/**
 * The element slugs this pack offers and whether each is switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_jeg_kit_element_state(): array|WP_Error
{
    if (
        !class_exists('Jeg\Elementor_Kit\Elements\Element')
        || !method_exists('Jeg\Elementor_Kit\Elements\Element', 'instance')
        || !method_exists('Jeg\Elementor_Kit\Elements\Element', 'list_elements')
    ) {
        return new WP_Error(
            'nibwp_jeg_kit_no_element_list',
            __('This version of the add-on does not expose an element list.', 'nibwp'),
            ['status' => 400]
        );
    }

    // list_elements() is a plain list plus a filter and holds no saved state, so
    // asking the pack for it cannot go stale. Its singleton is built at
    // plugins_loaded, long before an ability runs; the catch is for the site
    // where the plugin half-loaded and the framework it leans on is not there.
    try {
        $elements = \Jeg\Elementor_Kit\Elements\Element::instance()->list_elements();
    } catch (\Throwable $error) {
        return new WP_Error(
            'nibwp_jeg_kit_element_list_failed',
            __('The add-on could not report its element list on this request.', 'nibwp'),
            ['status' => 500]
        );
    }

    if (!is_array($elements)) {
        return new WP_Error(
            'nibwp_jeg_kit_no_element_list',
            __('This version of the add-on does not expose an element list.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('jkit_elements_enable', []);
    $saved = is_array($saved) ? $saved : [];
    $state = [];

    foreach ($elements as $element) {
        // The pack derives both the stored key and the Elementor widget name
        // from the class name this way, which is why a module slug here can be
        // handed straight to describe_widget and find_usage.
        $slug = 'jkit_' . strtolower((string) $element);

        // ABSENT MEANS ON: the pack registers an element when the option has no
        // entry for it, so a site that never opened the Elements screen has an
        // empty option and every element enabled. Treating absence as off would
        // report a fresh install as having nothing switched on.
        $state[$slug] = !array_key_exists($slug, $saved)
            || filter_var($saved[$slug], FILTER_VALIDATE_BOOLEAN);
    }

    return $state;
}

/**
 * Switch one element on or off, in the pack's own storage format.
 */
function nibwp_jeg_kit_set_element(string $slug, bool $on): bool|WP_Error
{
    $state = nibwp_jeg_kit_element_state();

    if ($state instanceof WP_Error) {
        return $state;
    }

    if (!array_key_exists($slug, $state)) {
        return new WP_Error(
            'nibwp_jeg_kit_unknown_element',
            __('That element is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('jkit_elements_enable', []);
    $saved = is_array($saved) ? $saved : [];

    // Merged into what is stored, exactly as the pack's own save does: the
    // option also carries keys for the paid elements and for anything a filter
    // added, and rewriting it from the list above would drop them. A real
    // boolean is what the pack's own settings screen stores, and the older
    // 'true'/'false' strings it may find there read the same through filter_var.
    $saved[$slug] = $on;

    update_option('jkit_elements_enable', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'jeg-kit',
    'name' => 'Jeg Kit for Elementor',
    'dir' => 'jeg-elementor-kit',
    'constants' => ['JEG_ELEMENTOR_KIT', 'JEG_ELEMENTOR_KIT_VERSION'],
    'classes' => ['Jeg\Elementor_Kit\Elements\Element'],
    'description' => 'Manage the Jeg Kit pack on this site: list the elements it contributes to the editor and switch any of them on or off, and work with its header, footer '
        . 'and theme builder templates — list them, create one, and set which pages, archives or post types each one is shown on. Also reports templates that were never '
        . 'opened in Elementor, and published ones whose display rules were never stored, which output nowhere.',
    'templates' => [
        [
            'post_type' => 'jkit-header',
            'conditions' => [
                'display' => 'jkit-condition',
            ],
            // This pack reads a stored-but-empty rule list as every page and a
            // missing one as nowhere, so the audit must not call an empty list a
            // template that shows nowhere.
            'conditions_empty_matches_all' => true,
        ],
        [
            'post_type' => 'jkit-footer',
            'conditions' => [
                'display' => 'jkit-condition',
            ],
            // This pack reads a stored-but-empty rule list as every page and a
            // missing one as nowhere, so the audit must not call an empty list a
            // template that shows nowhere.
            'conditions_empty_matches_all' => true,
        ],
        [
            'post_type' => 'jkit-template',
            'kind_meta' => 'jkit-template-type',
            'kinds' => ['jkit-single-post', 'jkit-single-product', 'jkit-archive-template', 'jkit-popup-template'],
            'conditions' => [
                'display' => 'jkit-condition',
            ],
            // This pack reads a stored-but-empty rule list as every page and a
            // missing one as nowhere, so the audit must not call an empty list a
            // template that shows nowhere.
            'conditions_empty_matches_all' => true,
        ],
    ],
    'modules' => [
        'label' => 'element',
        'read' => 'nibwp_jeg_kit_element_state',
        'write' => 'nibwp_jeg_kit_set_element',
    ],
    'notes' => [
        "THREE POST TYPES, ONE RULE KEY: a header is a jkit-header post, a footer a\n"
        . "jkit-footer post, and every other slot a jkit-template post whose kind says\n"
        . "which — jkit-single-post, jkit-single-product, jkit-archive-template or\n"
        . "jkit-popup-template. Only headers and footers are output by the free pack;\n"
        . "the other kinds are stored the same way but rendered by its paid extension,\n"
        . "so one created here sits unused until that is installed. Pass post_type to\n"
        . "list_templates and create_template, and kind only for jkit-template.",
        "WHICH TEMPLATE WINS: only a published template is a candidate — a draft is\n"
        . "the pack's 'unused' list, not a hidden one. It then walks its templates in\n"
        . "menu_order and outputs the FIRST whose rules match the page, so the rules\n"
        . "are not additive and order decides ties. create_template leaves menu_order\n"
        . "at 0, which puts the new template at the front; reorder from the pack's own\n"
        . "Templates screen if an existing one should still win.",
        "EMPTY RULES MEAN EVERYWHERE, MISSING RULES MEAN NOWHERE: the pack matches a\n"
        . "template whose display value is an empty list against every page, and never\n"
        . "matches one whose value is not a list at all. A template created here has\n"
        . "no display meta until update_template writes one, so it is in the second\n"
        . "state and audit is right to call it out. A template the pack's own dialog\n"
        . "saved with nothing selected is in the first state and is live site-wide,\n"
        . "which audit reports the same way and reads the wrong way round. Check the\n"
        . "value with get_template before believing either.",
        "RULE FORMAT: display is a list of rows, each with location and enclose.\n"
        . "location is all_site, singular or archives, and the front end also honours\n"
        . "post_single, category, post_tag, shop_page, product_cat, product_tag and\n"
        . "product_single. enclose is include or exclude. Which posts, terms or\n"
        . "authors a row narrows to is held in its own key as a comma-joined list of\n"
        . "ids, not an array. Read a template that already works with get_template and\n"
        . "pass the same shape back rather than inventing one; the pack refuses\n"
        . "nothing here, it simply never matches.",
        "ELEMENT SLUG IS THE WIDGET NAME: the module slugs list_modules reports —\n"
        . "jkit_nav_menu, jkit_post_block and so on — are the widget names Elementor\n"
        . "knows, so one can be handed straight to describe_widget or find_usage. Ask\n"
        . "find_usage before switching an element off: it stays in the saved data of\n"
        . "any page using it and that section then renders as nothing.",
        "WHAT IS NOT IN THE MODULE LIST: the jkit_pro_* entries list_widgets reports\n"
        . "are promotion placeholders for the paid extension. They are hidden from the\n"
        . "editor panel, render nothing on the front end, and cannot be switched, so\n"
        . "they are deliberately absent from list_modules. The three product elements\n"
        . "appear in the list only while WooCommerce is active.",
        "A TEMPLATE CREATED HERE misses two things the pack's own Add New writes: the\n"
        . "page template (Elementor Canvas for a header or footer, Elementor Full\n"
        . "Width for the rest) and, for a header or footer, the pack's free-tier limit\n"
        . "of one each. Set the layout in the editor's page settings — it affects\n"
        . "previewing the template on its own, not how it is output — and expect the\n"
        . "pack's own screen to still offer an upgrade rather than a second slot.",
    ],
]);
