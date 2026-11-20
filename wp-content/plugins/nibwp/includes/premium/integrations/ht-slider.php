<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the HT Slider widget pack for Elementor.
 *
 * One post type, `htslider_slider`, one taxonomy grouping it, and no settings of
 * the pack's own. Its posts are owner-managed Elementor content — the pack's menu
 * calls them Sliders and offers "Add New Slider" — but every one of them is a
 * single SLIDE: the pack's widgets query several of these posts and render one
 * per slide, picking them by id or by the `htslider_category` taxonomy.
 *
 * So there are no display conditions to declare, and none are invented here.
 * A slide is output where a page puts the widget or the `[htslider]` shortcode,
 * exactly like any other widget, and nothing on the post says where that is. The
 * group below therefore names only its post type, which is also what keeps the
 * audit from reporting every published slide as rendering nowhere.
 *
 * There is no module list either: the pack holds no option for widget state at
 * all — the only two it reads are a flag that clears its remote-notice cache and
 * a per-notice "already done" flag — and there is no per-widget switch anywhere
 * in it. So the engine offers no list_modules for it, which is the honest answer
 * rather than an empty one.
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

nibwp_elpack_register([
    'key' => 'ht-slider',
    'name' => 'HT Slider for Elementor',
    'dir' => 'ht-slider-for-elementor',
    'constants' => ['HTSLIDER_VERSION', 'HTSLIDER_ADDONS_PL_ROOT'],
    'classes' => ['HTSlider_Addons_Elementor', 'HTSlider_Template_Library'],
    'functions' => ['htslider_render_build_content'],
    'description' => 'Manage this slider pack on this site: list the slides it keeps as its own post type, create one, retitle or delete it, list the widgets it '
        . 'contributes to the Elementor editor, read one widget\'s control schema, and find which pages are built with them. The pack keeps no display rules and no '
        . 'on/off switches, so where a slide appears is decided by the widget or the shortcode that collects it, and this integration says so rather than offering '
        . 'settings that do not exist.',
    'templates' => [
        [
            // One slide. No kind: the pack has a single sort of post and writes
            // no meta saying which sort it is, so there is nothing to list and
            // nothing for kinds_without_conditions to name either.
            'post_type' => 'htslider_slider',
            // No conditions and no create_meta on purpose. The pack forces
            // Elementor's canvas file for this post type through the
            // single_template filter whatever the post's page-template meta
            // says, and a slide is output inside another page anyway, so
            // stamping a page template or a document type here would change
            // nothing the pack itself relies on.
        ],
    ],
    'notes' => [
        "ONE POST IS ONE SLIDE, NOT A SLIDER: the post type is labelled Slider and\n"
        . "kept under the pack's own menu, but each widget queries a set of these\n"
        . "posts and renders one per slide. A finished slider is one widget — or one\n"
        . "shortcode — plus the slides it collects, so create_template gives you a\n"
        . "slide and nothing appears on the front end until something places it.",

        "NO DISPLAY RULES EXIST: there is no rule meta, no rule option and no\n"
        . "settings screen in this pack, so get_template reports no conditions and\n"
        . "update_template accepts none. That is not a gap in this integration. A\n"
        . "published slide with nothing pointing at it is a normal intermediate\n"
        . "state, which is why audit does not flag it.",

        "WHICH SLIDES A SLIDER SHOWS: the three widgets that read slides share the\n"
        . "same controls — slider_show_by is show_byid, taking a list of post ids in\n"
        . "slider_id, or show_bycat, taking a term slug or term id in slider_cat\n"
        . "against the htslider_category taxonomy. So a new slide joins a\n"
        . "category-driven slider by being given the term — use the taxonomy\n"
        . "abilities for that, this one does not set terms — and joins an id-driven\n"
        . "slider only by editing that widget's id list.",

        "THE SHORTCODE IS [htslider]: its attributes are show_by (show_byid or\n"
        . "show_bycat), ids as a comma-separated list, category as a slug or term id,\n"
        . "limit (-1 for all), slides, arrow and pagination. It defaults to\n"
        . "show_byid with no ids, which lists every published slide.",

        "WIDGETS: htslider-slider-addons (HT: Slider), htsliderpro-addons\n"
        . "(HT: Advanced Slider), htslider-postslider-addons (HT: Posts Slider) and\n"
        . "htslider-scrollnavigation-addons (HT Scroll Navigation Slider), all in the\n"
        . "editor's ht-slider category. The first two always render slides, and the\n"
        . "scroll navigation one does so only while its slides_source is\n"
        . "ht_slider_slides. The posts slider is the odd one out: it queries whatever\n"
        . "slider_post_type names and defaults to ordinary posts, so find_usage naming\n"
        . "it says nothing about whether a slide is in use.",

        "THE WIDGETS VANISH INSIDE A SLIDE'S OWN EDITOR: the pack registers them\n"
        . "only when the post being edited is not one of these, so a slider cannot be\n"
        . "nested in a slide. A call made from that editor request therefore sees zero\n"
        . "widgets, and describe_widget and find_usage refuse by name. Deliberate, not\n"
        . "a fault — ask again from anywhere else.",

        "A SLIDE NEVER OPENED IN ELEMENTOR STILL SHOWS SOMETHING: audit reports it as\n"
        . "not built, which for most packs means the template outputs nothing. Here the\n"
        . "pack falls back to the post's own content — blocks rendered one by one, or\n"
        . "the_content otherwise — so read that finding as \"not built with the\n"
        . "builder\" rather than \"renders nothing\".",

        "IMPORTED SLIDES CARRY EXTRA META: the pack's own Templates Library screen\n"
        . "inserts a slide and stamps Elementor's data, document type and page template\n"
        . "from the downloaded payload, so some slides have all three and slides made\n"
        . "by hand or from here have none. Neither state affects how a slide is output.",

        "NOTHING TO SWITCH OFF: the pack ships no widget or module toggles at all, so\n"
        . "there is no list_modules here and a widget missing from the editor is the\n"
        . "plugin or Elementor being inactive, or the slide-editor exception above.",
    ],
]);
