<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Integration: the Rometheme Elementor kit and its theme builder.
 *
 * Templates are `rometheme_template` posts. Which slot one fills is
 * `rometheme_template_type`, and the front end only outputs a template whose
 * type matches AND whose `rometheme_template_active` is the string 'true' — the
 * two are separate meta keys and the query requires both
 * (Inc/Modules/Themebuilder/ThemebuilderStorage.php, get_themebuilder_template).
 * `rometheme_template_condition` narrows it further. So `active` is exposed as a
 * condition label of its own: without it an agent could write a perfect set of
 * conditions onto a template that is switched off and never see it render.
 *
 * Modules live in `rtm_modules` as slug => ['status' => …, …]. The pack reads
 * that with `isset($option['status']) && $option['status']`, so a slug missing
 * from the option is OFF — the opposite of most packs, and the reason the read
 * below does not fill an absent slug in as enabled.
 *
 * The defaults are read out of the pack's own metadata/modules.json rather than
 * through `Storage::get_modules()`, which goes via its CacheManager, and rather
 * than `Storage::instance()`, which is a singleton: either would hand back a
 * pre-write value inside the same request.
 */

// Guarded at the require site: a Skill add-on may ship its own copy of the
// engine at a different path, and require_once cannot see a duplicate it was
// never given. Two copies means two declarations and a fatal on compile.
if (!function_exists('nibwp_elpack_register')) {
    require_once __DIR__ . '/elementor-pack-engine.php';
}

/**
 * The pack's own module catalogue, straight off disk.
 *
 * @return array<string, array<string, mixed>>|WP_Error
 */
function nibwp_rometheme_catalogue(): array|WP_Error
{
    if (!defined('RTM_KIT_DIR')) {
        return new WP_Error(
            'nibwp_rometheme_not_loaded',
            __('The add-on did not define its own directory, so its module list cannot be found.', 'nibwp'),
            ['status' => 400]
        );
    }

    $file = (string) constant('RTM_KIT_DIR') . 'metadata/modules.json';

    if (!is_readable($file)) {
        return new WP_Error(
            'nibwp_rometheme_no_catalogue',
            __('This version of the add-on does not ship a module list where this integration can find it.', 'nibwp'),
            ['status' => 400]
        );
    }

    $decoded = json_decode((string) file_get_contents($file), true);

    if (!is_array($decoded)) {
        return new WP_Error(
            'nibwp_rometheme_catalogue_unreadable',
            __('The add-on\'s module list could not be read.', 'nibwp'),
            ['status' => 500]
        );
    }

    $out = [];
    foreach ($decoded as $slug => $module) {
        if (is_string($slug) && $slug !== '') {
            $out[$slug] = is_array($module) ? $module : [];
        }
    }

    return $out;
}

/**
 * Which of the pack's modules are switched on.
 *
 * @return array<string, bool>|WP_Error
 */
function nibwp_rometheme_module_state(): array|WP_Error
{
    $catalogue = nibwp_rometheme_catalogue();

    if ($catalogue instanceof WP_Error) {
        return $catalogue;
    }

    $saved = get_option('rtm_modules', []);
    $saved = is_array($saved) ? $saved : [];

    $state = [];

    foreach (array_keys($catalogue) as $slug) {
        $row = $saved[$slug] ?? null;

        // ABSENT MEANS OFF. The pack tests isset($row['status']) && $row['status'],
        // so a slug the option has never carried is inactive — even though the
        // shipped catalogue marks it status:true, which is only the value used to
        // seed the option the first time the pack runs.
        $state[$slug] = is_array($row) && !empty($row['status']);
    }

    return $state;
}

/**
 * Switch one module on or off, in the pack's own storage format.
 *
 * The row is preserved and only its status replaced: the row also carries the
 * module's class, category and icon, and the pack reads those to decide what to
 * load. Replacing the row with a bare status would leave it unable to.
 */
function nibwp_rometheme_set_module(string $slug, bool $on): bool|WP_Error
{
    $catalogue = nibwp_rometheme_catalogue();

    if ($catalogue instanceof WP_Error) {
        return $catalogue;
    }

    if (!array_key_exists($slug, $catalogue)) {
        return new WP_Error(
            'nibwp_rometheme_unknown_module',
            __('That module is not one this add-on offers.', 'nibwp'),
            ['status' => 400]
        );
    }

    $saved = get_option('rtm_modules', []);
    $saved = is_array($saved) ? $saved : [];

    // A slug the option has never carried is seeded from the shipped catalogue,
    // so switching one on for the first time leaves a row the pack can act on
    // rather than a status with nothing attached.
    $row = isset($saved[$slug]) && is_array($saved[$slug]) ? $saved[$slug] : $catalogue[$slug];
    $row['status'] = $on;
    $saved[$slug] = $row;

    update_option('rtm_modules', $saved);

    return true;
}

nibwp_elpack_register([
    'key' => 'rometheme',
    'name' => 'Rometheme for Elementor',
    'dir' => 'rometheme-for-elementor',
    'constants' => ['RTM_KIT_VERSION', 'RTM_KIT_DIR'],
    'classes' => ['RTMKit\Core\Plugin', 'RTMKit\Modules\Storage'],
    'description' => 'Manage the Rometheme kit on this site: its header, footer, archive, single, search and 404 templates, which pages each applies to, whether each is switched on, '
        . 'and which of the kit\'s modules are enabled. Also reports templates that are published but inactive, so they render nowhere.',
    'templates' => [
        [
            'post_type' => 'rometheme_template',
            'kind_meta' => 'rometheme_template_type',
            'kinds' => ['header', 'footer', 'single_post', 'archive_post', 'search', 'error_404', 'product'],
            'conditions' => [
                'display' => 'rometheme_template_condition',
                'active' => 'rometheme_template_active',
            ],
            // The pack's own "Add New" defaults a template to inactive unless the
            // form said otherwise, and so does this: a template created here has
            // no layout yet, and one that went live the moment it existed would
            // replace a working header with a blank one.
            'create_meta' => ['rometheme_template_active' => 'false'],
        ],
    ],
    'modules' => [
        'label' => 'module',
        'read' => 'nibwp_rometheme_module_state',
        'write' => 'nibwp_rometheme_set_module',
    ],
    'notes' => [
        "ACTIVE IS SEPARATE FROM CONDITIONS: the front end matches on the type and\n"
        . "on rometheme_template_active being the STRING 'true' — not a boolean, not\n"
        . "1. A template with perfect conditions and active 'false' renders nowhere,\n"
        . "so set both. update_template takes active under conditions.",
        "DISPLAY CONDITIONS are the pack's own structure, stored as an array. Read\n"
        . "one that already works with get_template before writing a new one and\n"
        . "pass the same shape back. Leaving them out means the template applies\n"
        . "wherever its type applies.",
        "KINDS: header, footer, single_post, archive_post, search, error_404 and\n"
        . "product. The pack also mirrors the kind into Elementor's own\n"
        . "_elementor_template_type when its screen creates a template, mapping 404\n"
        . "to error-404; this integration does not, so a template created here opens\n"
        . "in Elementor as an ordinary document. It still outputs correctly.",
        "MODULES: a slug the rtm_modules option has never carried is OFF, even\n"
        . "though the shipped catalogue marks it on — that value only seeds the\n"
        . "option the first time the pack runs. A paid build keeps its own modules\n"
        . "in a second option this integration does not touch.",
    ],
]);
