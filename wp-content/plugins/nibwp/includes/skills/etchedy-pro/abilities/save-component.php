<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The libs this ability calls. Loaded here, not left to the integration file:
 * the skill loader checks that Etchedy is present, not that its integration
 * card is switched on, and with the card off the integration never loads. Each
 * guard names a function from its own file, so a copy already loaded from
 * another path is never declared twice.
 */
$nibwp_etchedy_lib = dirname(__DIR__) . '/lib/';
if (!function_exists('nibwp_etchedy_available')) {
    require_once $nibwp_etchedy_lib . 'library-client.php';
}
if (!function_exists('nibwp_etchedy_write_body')) {
    require_once $nibwp_etchedy_lib . 'library.php';
}
if (!function_exists('nibwp_etchedy_validate_portability')) {
    require_once $nibwp_etchedy_lib . 'portability.php';
}
if (!function_exists('nibwp_etchedy_normalize_findings')) {
    require_once $nibwp_etchedy_lib . 'findings.php';
}
unset($nibwp_etchedy_lib);

wp_register_ability('nibwp/etchedy-pro-save-component', [
    'label' => __('Etchedy Pro — Save Component', 'nibwp'),
    'description' => 'Validate a component payload and save it into the Etchedy library. Runs the EtchWP build rules (ACSS tokens, BEM, no clamp() font-size, no hardcoded colours) and the library portability rules (no site-specific ids, URLs or unbound fields) before writing. Supports dry_run. There is one Etchedy library on the site, whichever edition of the Etchedy plugin is installed (free or Pro): nibwp/etchedy-manage and the nibwp/etchedy-pro-* abilities all read and write that same library. Call it Etchedy and never ask the person to choose between Etchedy and Etchedy Pro.',
    'category' => 'etchedy',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Display name. The file slug is derived from it.'],
            'type' => ['type' => 'string', 'enum' => ['layout', 'element', 'component', 'template']],
            'category' => ['type' => 'string', 'description' => 'Sub-folder, e.g. "Hero". Required.'],
            'brand' => ['type' => 'string', 'description' => 'The BEM brand prefix every class starts with, e.g. "acme". Also the library brand. Required.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'At least one, three or more for discoverability.'],
            'description' => ['type' => 'string', 'description' => 'What this component is for. Required.'],
            'preview_image' => ['type' => 'string', 'description' => 'URL or data URI. Counts against the 8 MB payload cap.'],
            'block' => ['type' => 'object', 'description' => 'gutenbergBlock + components + styles.'],
            'dry_run' => ['type' => 'boolean', 'description' => 'Validate and report without writing. Default false.'],
        ],
        'required' => ['name', 'type', 'category', 'brand', 'tags', 'description', 'block'],
        'additionalProperties' => true,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => 'nibwp_etchedy_pro_save_execute',
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
                'A failed rule is not advice. The write is refused until it is fixed.',
                'Expose every value that would differ between uses as a component property with a default. A component with no properties is a snapshot, not a library item.',
            ]),
        ],
    ],
]);

/**
 * The library metadata the etchwp validator demands, built from the input.
 *
 * The agent never writes __libraryMeta itself — Etchedy builds its own from
 * name, type, category, brand, tags and description when it saves. But the
 * validator checks the payload it is handed, and a payload without the block
 * fails "__libraryMeta.brand is required" on every save, in words that mean
 * nothing to someone who supplied a "brand" argument. So the metadata is
 * assembled here, from the arguments the agent really passed, and anything
 * missing is refused in the agent's own vocabulary.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_etchedy_library_meta(array $input)
{
    foreach (['brand', 'category', 'description'] as $field) {
        if (trim((string) ($input[$field] ?? '')) === '') {
            return new WP_Error(
                'etchedy_missing_' . $field,
                sprintf(__('"%s" is required to save into the library.', 'nibwp'), $field),
                ['status' => 400]
            );
        }
    }

    $tags = array_values(array_filter(array_map('strval', (array) ($input['tags'] ?? [])), static function (string $t): bool {
        return trim($t) !== '';
    }));
    if ($tags === []) {
        return new WP_Error(
            'etchedy_missing_tags',
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
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function nibwp_etchedy_pro_save_execute(array $input)
{
    $dry_run = !empty($input['dry_run']);

    $meta = nibwp_etchedy_library_meta($input);
    if (is_wp_error($meta)) {
        return $meta;
    }

    // The body Etchedy will be sent, built now so the size check measures what
    // Etchedy will actually store — preview image and all — against its cap.
    $body = nibwp_etchedy_write_body($input);
    if (is_wp_error($body)) {
        return $body;
    }
    $too_large = nibwp_etchedy_payload_too_large($body);
    if ($too_large !== null) {
        return $too_large;
    }

    // The validation copy. The caller's block is left exactly as it was passed.
    $payload = (array) ($input['block'] ?? []);
    $payload['__libraryMeta'] = $meta;

    $missing = nibwp_etchedy_require_etchwp();
    if (is_wp_error($missing)) {
        return $missing;
    }

    $quality = nibwp_etchwp_validate_payload($payload, [
        'element_type' => (string) ($input['type'] ?? ''),
        'brand' => $meta['brand'],
    ]);
    $portability = nibwp_etchedy_validate_portability($payload);

    $failed = array_merge(
        nibwp_etchedy_normalize_findings((array) $quality['failed']),
        nibwp_etchedy_normalize_findings((array) $portability['failed'])
    );
    $warnings = array_merge(
        nibwp_etchedy_normalize_findings((array) $quality['warnings']),
        nibwp_etchedy_normalize_findings((array) $portability['warnings'])
    );

    if ($failed !== []) {
        return new WP_Error(
            'etchedy_validation_failed',
            __('The component was not saved. Fix the rules below and try again.', 'nibwp'),
            ['status' => 422, 'failed' => $failed, 'warnings' => $warnings]
        );
    }

    if ($dry_run) {
        return [
            'dry_run' => true,
            'would_save' => true,
            'warnings' => $warnings,
            'message' => __('Validation passed. Review the warnings, then run again with dry_run false to save.', 'nibwp'),
        ];
    }

    $result = nibwp_etchedy_request('/save-component', 'POST', $body);
    if (is_wp_error($result)) {
        return $result;
    }

    $result['warnings'] = $warnings;
    $result['warning'] = nibwp_etchedy_update_warning();

    return $result;
}
