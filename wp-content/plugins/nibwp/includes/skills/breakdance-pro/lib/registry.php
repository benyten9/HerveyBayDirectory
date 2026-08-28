<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Breakdance Pro — element registry.
 *
 * Every other builder skill in this plugin ships a written element reference
 * that drifts from reality with each release of the builder. Breakdance does
 * not need one: `Breakdance\Elements\get_elements_for_builder()` returns every
 * registered element together with its control schema, read from the install
 * the agent is actually building on.
 *
 * That matters because the element set is not fixed. It depends on the license,
 * on which subplugins are active (breakdance-elements ships ~165 on its own,
 * breakdance-woocommerce adds more), and on any third-party element packs. A
 * static whitelist would be wrong on most sites.
 */

/**
 * Every registered element, keyed by slug.
 *
 * Cached per request. The underlying call assembles control schemas for every
 * element, which is not cheap, and the validator asks for it repeatedly.
 *
 * @return array<string, array<string, mixed>>
 */
function nibwp_bdpro_elements(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    if (!function_exists('Breakdance\\Elements\\get_elements_for_builder')) {
        return $cache;
    }

    try {
        $elements = \Breakdance\Elements\get_elements_for_builder();
    } catch (\Throwable $e) {
        return $cache;
    }

    foreach ((array) $elements as $element) {
        $element = (array) $element;
        $slug = (string) ($element['slug'] ?? '');
        if ($slug !== '') {
            $cache[$slug] = $element;
        }
    }

    return $cache;
}

/** Is this slug a real element on this install? */
function nibwp_bdpro_element_exists(string $slug): bool
{
    return isset(nibwp_bdpro_elements()[$slug]);
}

/**
 * Suggest the closest registered slug to one that does not exist.
 *
 * An agent that writes `EssentialElements\Header` when the site has
 * `EssentialElements\Heading` should be told which it meant, not merely that it
 * was wrong.
 *
 * @return list<string>
 */
function nibwp_bdpro_suggest_slugs(string $wanted, int $limit = 3): array
{
    $scored = [];
    $needle = strtolower(nibwp_bdpro_short_name($wanted));

    foreach (array_keys(nibwp_bdpro_elements()) as $slug) {
        $candidate = strtolower(nibwp_bdpro_short_name($slug));
        $distance = levenshtein($needle, $candidate);
        if ($distance <= max(3, (int) floor(strlen($needle) / 2))) {
            $scored[$slug] = $distance;
        }
    }

    asort($scored);

    return array_slice(array_keys($scored), 0, $limit);
}

/** `EssentialElements\Heading` → `Heading`. */
function nibwp_bdpro_short_name(string $slug): string
{
    $parts = explode('\\', $slug);

    return (string) end($parts);
}

/**
 * The property paths one element accepts, flattened to dotted strings.
 *
 * Breakdance control schemas nest sections inside sections, so this walks the
 * whole structure and returns the leaf paths a tree may legally set — which is
 * what lets the validator reject a property that will be silently dropped
 * rather than let it through and render nothing.
 *
 * @return list<string>
 */
function nibwp_bdpro_element_property_paths(string $slug): array
{
    $element = nibwp_bdpro_elements()[$slug] ?? null;
    if ($element === null) {
        return [];
    }

    $paths = [];

    $walk = static function ($controls, string $prefix) use (&$walk, &$paths): void {
        foreach ((array) $controls as $control) {
            $control = (array) $control;
            $key = (string) ($control['key'] ?? ($control['id'] ?? ''));
            if ($key === '') {
                // A section with no key of its own still holds children worth
                // reaching, so recurse without extending the prefix.
                if (!empty($control['controls'])) {
                    $walk($control['controls'], $prefix);
                }
                continue;
            }

            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (!empty($control['controls'])) {
                $walk($control['controls'], $path);
            } else {
                $paths[] = $path;
            }
        }
    };

    foreach (['content', 'design', 'settings', 'advanced'] as $section) {
        if (!empty($element[$section])) {
            $walk($element[$section], $section);
        }
    }

    if (!empty($element['controls'])) {
        $walk($element['controls'], '');
    }

    return array_values(array_unique($paths));
}

/**
 * A compact catalogue for the agent: slug, short name, category.
 *
 * Deliberately not the full schema — the whole registry serialised is far
 * larger than any useful context window, and an agent choosing an element only
 * needs to know what exists. It asks for one element's schema afterwards.
 *
 * @return list<array{slug:string, name:string, category:string}>
 */
function nibwp_bdpro_element_catalogue(string $search = ''): array
{
    $search = strtolower(trim($search));
    $rows = [];

    foreach (nibwp_bdpro_elements() as $slug => $element) {
        $name = (string) ($element['name'] ?? ($element['label'] ?? nibwp_bdpro_short_name($slug)));
        $category = (string) ($element['category'] ?? '');

        if ($search !== '' && !str_contains(strtolower($slug . ' ' . $name . ' ' . $category), $search)) {
            continue;
        }

        $rows[] = ['slug' => $slug, 'name' => $name, 'category' => $category];
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));

    return $rows;
}

/**
 * Elements that can hold children.
 *
 * A tree that puts a heading inside another heading is accepted by the writer
 * and renders as nonsense, so the validator needs to know which slugs are
 * containers. Breakdance does not flag this in the schema, so the check is by
 * short name against the known layout elements plus anything whose registered
 * category says it is a layout element.
 */
function nibwp_bdpro_is_container(string $slug): bool
{
    $short = strtolower(nibwp_bdpro_short_name($slug));

    $known = [
        'section', 'container', 'div', 'columns', 'column', 'grid', 'flexbox',
        'root', 'popup', 'header', 'footer', 'slider', 'advancedslider',
        'advancedslide', 'tabs', 'advanced_tabs', 'accordion', 'advanced_accordion',
        'accordion_content', 'posts_loop', 'dynamic_data_loop', 'term_loop_builder',
        'wrapper', 'link_wrapper', 'form_builder',
    ];

    if (in_array($short, $known, strict: true)) {
        return true;
    }

    $element = nibwp_bdpro_elements()[$slug] ?? [];

    return !empty($element['acceptsChildren']) || (($element['category'] ?? '') === 'layout');
}

/** The loop elements this install offers, for turning repetition into a query. */
function nibwp_bdpro_loop_elements(): array
{
    $out = [];

    foreach (array_keys(nibwp_bdpro_elements()) as $slug) {
        $short = strtolower(nibwp_bdpro_short_name($slug));
        if (str_contains($short, 'loop') || $short === 'postslist') {
            $out[] = $slug;
        }
    }

    return $out;
}
