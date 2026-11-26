<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../lib/delegate.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/html.php';
require_once __DIR__ . '/../lib/skeletons.php';

/**
 * Mosaic Pro — convert, with nothing written.
 *
 * HTML, a URL, a brief or a tree in; a Mosaic tree, the Mosaic classes its
 * <style> rules become, a validation report and an outline out. The build step
 * takes exactly this output, so what was read here is what gets written.
 */

nibwp_mosaic_pro_register(
    'mosaic-pro-convert',
    __('Mosaic Pro — Convert to a Mosaic tree', 'nibwp'),
    'Turn HTML, a URL, a brief (section_type) or your own tree into a validated Mosaic tree plus Mosaic classes from the <style> rules. Writes nothing (read-only).',
    [
        'html'         => ['type' => 'string', 'description' => 'HTML to convert: a section or a whole page. <style> class rules become Mosaic classes.'],
        'url'          => ['type' => 'string', 'description' => 'A page to fetch and convert instead.'],
        'section_type' => ['type' => 'string', 'enum' => nibwp_mosaic_pro_skeleton_types(), 'description' => 'For a brief or a screenshot: a responsive blueprint of this kind of section to fill in.'],
        'brief'        => ['type' => 'string'],
        'tree'         => ['type' => ['object', 'array'], 'description' => 'Or validate a tree you wrote yourself.'],
        'keep_classes' => ['type' => 'boolean', 'description' => 'Keep class names that are not defined in a <style> block as plain cssClasses (only when the site already has that CSS).'],
    ],
    'nibwp_mosaic_pro_convert_ability',
    true,
    [
        'Run before nibwp/mosaic-pro-build, every time. An error here is a value Mosaic would have dropped without a word.',
        'A screenshot has no markup: pass section_type and brief, take the blueprint, then restyle and fill it — keep its responsive values.',
        'Hand the returned tree and classes to build unchanged unless you are fixing a reported error.',
    ]
);

function nibwp_mosaic_pro_convert_ability(array $input)
{
    $gate = nibwp_mosaic_pro_gate(false);
    if ($gate instanceof WP_Error) {
        return $gate;
    }

    $warnings = [];
    $classes = [];
    $source = '';

    if (!empty($input['tree']) && is_array($input['tree'])) {
        $source = 'tree';
        $tree = nibwp_mosaic_roots($input['tree']);
    } elseif (!empty($input['html']) && is_string($input['html'])) {
        $source = 'html';
        $converted = nibwp_mosaic_pro_html_to_tree((string) $input['html'], !empty($input['keep_classes']));
        [$tree, $classes, $warnings] = [$converted['tree'], $converted['classes'], $converted['warnings']];
    } elseif (!empty($input['url']) && is_string($input['url'])) {
        $source = 'url';
        $response = function_exists('wp_safe_remote_get') ? wp_safe_remote_get((string) $input['url'], ['timeout' => 20]) : null;
        if ($response === null || is_wp_error($response)) {
            return $response instanceof WP_Error ? $response : new WP_Error('nibwp_mosaic_pro_fetch', __('The page could not be fetched.', 'nibwp'));
        }
        $converted = nibwp_mosaic_pro_html_to_tree((string) wp_remote_retrieve_body($response), !empty($input['keep_classes']));
        [$tree, $classes, $warnings] = [$converted['tree'], $converted['classes'], $converted['warnings']];
        $warnings[] = ['path' => '', 'message' => 'A fetched page brings its markup, not its stylesheets: only inline styles and <style> class rules came across. Apply the design direction before building.'];
    } elseif (!empty($input['section_type']) || !empty($input['brief'])) {
        $source = 'blueprint';
        $tree = nibwp_mosaic_pro_skeleton((string) ($input['section_type'] ?? 'section'), (string) ($input['brief'] ?? ''));
    } else {
        return new WP_Error('nibwp_mosaic_pro_no_input', __('Give html, a url, a tree, or a section_type with a brief.', 'nibwp'));
    }

    $report = nibwp_mosaic_pro_validate($tree, nibwp_mosaic_pro_context($input, array_column($classes, 'name')));
    $report['warnings'] = array_merge($warnings, $report['warnings']);
    $roots = [];
    foreach ($tree as $root) {
        $roots[] = nibwp_mosaic_normalize_tree($root);
    }

    return [
        'source'          => $source,
        'tree'            => $tree,
        'classes'         => array_values(array_map(static fn($c) => ['name' => $c['name'], 'style' => $c['style']], $classes)),
        'outline'         => nibwp_mosaic_outline($roots),
        'valid'           => $report['valid'],
        'errors'          => $report['errors'],
        'warnings'        => $report['warnings'],
        'recommendations' => $report['recommendations'],
        'stats'           => $report['stats'],
        'next'            => $report['valid']
            ? 'Call nibwp/mosaic-pro-build with this tree and classes, dry_run: true first.'
            : 'Fix every error, then convert again with tree.',
    ];
}
