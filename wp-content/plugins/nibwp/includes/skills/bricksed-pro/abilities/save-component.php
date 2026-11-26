<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The libs this ability calls. Loaded here, not left to the integration file:
 * the skill loader checks that Bricksed is present, not that its integration
 * card is switched on, and with the card off the integration never loads. Each
 * guard names a function from its own file, so a copy already loaded from
 * another path is never declared twice.
 */
$nibwp_bricksed_lib = dirname(__DIR__) . '/lib/';
if (!function_exists('nibwp_bricksed_available')) {
    require_once $nibwp_bricksed_lib . 'library-client.php';
}
if (!function_exists('nibwp_bricksed_write_body')) {
    require_once $nibwp_bricksed_lib . 'library.php';
}
if (!function_exists('nibwp_bricksed_validate_portability')) {
    require_once $nibwp_bricksed_lib . 'portability.php';
}
if (!function_exists('nibwp_bricksed_normalize_findings')) {
    require_once $nibwp_bricksed_lib . 'findings.php';
}
if (!function_exists('nibwp_bricksed_place')) {
    require_once $nibwp_bricksed_lib . 'placement.php';
}
unset($nibwp_bricksed_lib);

wp_register_ability('nibwp/bricksed-pro-save-component', [
    'label' => __('Bricksed Pro — Save Component', 'nibwp'),
    'description' => 'Validate a Bricks component and save it into the Bricksed library. Takes Bricks data (elements, global classes, Bricks components) or copies a built section straight off a page. Runs the Bricks Pro build rules (ACSS tokens, BEM brand prefix, no hardcoded colours or font sizes, native elements) and the library portability rules (nothing tied to this site: no attachment or post ids, no uploads URLs, every class and component included) before writing. Supports dry_run. There is one Bricksed library on the site, whichever edition of the Bricksed plugin is installed (free or Pro): nibwp/bricksed-manage and the nibwp/bricksed-pro-* abilities all read and write that same library. Call it Bricksed and never ask the person to choose between Bricksed and Bricksed Pro.',
    'category' => 'bricksed',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Display name. The file slug is derived from it.'],
            'type' => ['type' => 'string', 'enum' => ['layout', 'element', 'component', 'template']],
            'category' => ['type' => 'string', 'description' => 'Sub-folder, e.g. "Hero". Required.'],
            'brand' => ['type' => 'string', 'description' => 'The BEM brand prefix every global class starts with, e.g. "acme". Also the library brand. Required.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'At least one, three or more for discoverability.'],
            'description' => ['type' => 'string', 'description' => 'What this component is for. Required.'],
            'preview_image' => ['type' => 'string', 'description' => 'URL or data URI. Counts against the 8 MB payload cap.'],
            'demo_url' => ['type' => 'string', 'description' => 'Optional live demo URL.'],
            'block' => ['type' => 'object', 'description' => 'Bricks data: { bricks: { content, globalClasses, components } }, or Bricks\' own copy shape { content, globalClasses, components }. Give this or source.'],
            'source' => [
                'type' => 'object',
                'description' => 'Copy a section that is already built on a page: the element, its descendants, the global classes they use and the components they are instances of. Give this or block.',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'element_id' => ['type' => 'string', 'description' => 'The Bricks element id of the section\'s root.'],
                ],
                'required' => ['post_id', 'element_id'],
            ],
            'dry_run' => ['type' => 'boolean', 'description' => 'Validate and report without writing. Default false.'],
        ],
        'required' => ['name', 'type', 'category', 'brand', 'tags', 'description'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_bricksed_pro_save_execute',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
            'instructions' => implode("\n", [
                'Run with dry_run true first and show the user the warnings before writing.',
                'To keep a section already built on a page, pass source {post_id, element_id} instead of rebuilding it as a block.',
                'A failed rule is not advice. The write is refused until it is fixed.',
                'Make what repeats or varies a Bricks component and expose the text, images and links that change as properties with defaults. A section with no properties is a snapshot, not a library item.',
            ]),
        ],
    ],
]);

/**
 * The library metadata a save needs, refused in the agent's own vocabulary
 * when something is missing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_library_meta(array $input)
{
    foreach (['brand', 'category', 'description'] as $field) {
        if (trim((string) ($input[$field] ?? '')) === '') {
            return new WP_Error(
                'bricksed_missing_' . $field,
                sprintf(
                    /* translators: %s: field name */
                    __('"%s" is required to save into the library.', 'nibwp'),
                    $field
                ),
                ['status' => 400]
            );
        }
    }

    $tags = array_values(array_filter(array_map('strval', (array) ($input['tags'] ?? [])), static function (string $t): bool {
        return trim($t) !== '';
    }));
    if ($tags === []) {
        return new WP_Error(
            'bricksed_missing_tags',
            __('At least one tag is required to save into the library. Three or more makes it findable.', 'nibwp'),
            ['status' => 400]
        );
    }

    return [
        'brand' => trim((string) $input['brand']),
        'type' => (string) ($input['type'] ?? ''),
        'category' => trim((string) $input['category']),
        'tags' => $tags,
        'name' => trim((string) ($input['name'] ?? '')),
        'description' => trim((string) $input['description']),
    ];
}

/**
 * What a component exposes: its Bricks components and their properties.
 *
 * @param array<string, mixed> $bricks
 * @return array<int, array<string, mixed>>
 */
function nibwp_bricksed_describe_properties(array $bricks): array
{
    $out = [];
    foreach ((array) ($bricks['components'] ?? []) as $component) {
        if (!is_array($component)) {
            continue;
        }
        $out[] = [
            'component' => (string) ($component['elements'][0]['label'] ?? $component['id'] ?? ''),
            'id' => (string) ($component['id'] ?? ''),
            'properties' => array_values(array_map(static function ($property): array {
                $property = (array) $property;

                return [
                    'id' => (string) ($property['id'] ?? ''),
                    'label' => (string) ($property['label'] ?? ''),
                    'type' => (string) ($property['type'] ?? ''),
                    'default' => $property['default'] ?? null,
                ];
            }, (array) ($component['properties'] ?? []))),
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_bricksed_pro_save_execute(array $input)
{
    $dry_run = !empty($input['dry_run']);

    $meta = nibwp_bricksed_library_meta($input);
    if (is_wp_error($meta)) {
        return $meta;
    }

    // The content: Bricks data handed in, or a section copied off a page.
    $has_block = isset($input['block']) && is_array($input['block']) && $input['block'] !== [];
    $source = (array) ($input['source'] ?? []);
    $has_source = !empty($source['post_id']) && trim((string) ($source['element_id'] ?? '')) !== '';
    if ($has_block === $has_source) {
        return new WP_Error(
            'bricksed_missing_content',
            __('Give either "block" (Bricks data) or "source" ({post_id, element_id} of a section built on a page) — one of them.', 'nibwp'),
            ['status' => 400]
        );
    }

    if ($has_source) {
        $bricks = nibwp_bricksed_extract((int) $source['post_id'], trim((string) $source['element_id']));
        if (is_wp_error($bricks)) {
            return $bricks;
        }
    } else {
        $bricks = nibwp_bricksed_bricks_of($input['block']);
        if ($bricks === null) {
            return new WP_Error(
                'bricksed_not_bricks_data',
                __('"block" must be Bricks data: { bricks: { content, globalClasses, components } }, or Bricks\' own copy shape { content, globalClasses, components }. To keep a section already built on a page, pass "source" instead.', 'nibwp'),
                ['status' => 400]
            );
        }
    }
    if ($bricks['content'] === []) {
        return nibwp_bricksed_empty_block_error();
    }

    // The body Bricksed will be sent, built now so the size check measures
    // what Bricksed will actually store — preview image and all.
    $body = nibwp_bricksed_write_body(array_merge($input, ['block' => ['bricks' => $bricks]]));
    if (is_wp_error($body)) {
        return $body;
    }
    $too_large = nibwp_bricksed_payload_too_large($body);
    if ($too_large !== null) {
        return $too_large;
    }

    $missing = nibwp_bricksed_require_bricks_pro();
    if (is_wp_error($missing)) {
        return $missing;
    }

    $quality = nibwp_bricksed_quality($bricks, $meta['brand']);
    $portability = nibwp_bricksed_validate_portability(['bricks' => $bricks]);

    $failed = array_merge(
        nibwp_bricksed_normalize_findings($quality['failed']),
        nibwp_bricksed_normalize_findings($portability['failed'])
    );
    $warnings = array_merge(
        nibwp_bricksed_normalize_findings($quality['warnings']),
        nibwp_bricksed_normalize_findings($portability['warnings'])
    );

    if ($failed !== []) {
        return new WP_Error(
            'bricksed_validation_failed',
            __('The component was not saved. Fix the rules below and try again.', 'nibwp'),
            ['status' => 422, 'failed' => $failed, 'warnings' => $warnings]
        );
    }

    $summary = [
        'elements' => count($bricks['content']),
        'global_classes' => count($bricks['globalClasses']),
        'components' => nibwp_bricksed_describe_properties($bricks),
    ];

    if ($dry_run) {
        return [
            'dry_run' => true,
            'would_save' => true,
            'content' => $summary,
            'warnings' => $warnings,
            'message' => __('Validation passed. Review the warnings, then run again with dry_run false to save.', 'nibwp'),
        ];
    }

    $result = nibwp_bricksed_request('/save-component', 'POST', $body);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['content'] = $summary;
    $result['warnings'] = $warnings;
    $result['warning'] = nibwp_bricksed_update_warning();

    return $result;
}
