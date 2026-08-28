<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Kadence Pro — read/introspect + the one supported CSS escape hatch.
 *   • kadence-pro-list-blocks       — the block library + rules (static KB).
 *   • kadence-pro-block-attributes  — the LIVE block registry (never guess a name).
 *   • kadence-pro-custom-css        — write page CSS to _kad_blocks_custom_css.
 */

require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/persister.php';

wp_register_ability('nibwp/kadence-pro-list-blocks', [
    'label'       => __('Kadence Pro — list blocks', 'nibwp'),
    'description' => __('The Kadence block library: valid block names, container/nesting rules, which fields are source:html, the attribute traps, and where CSS may live. Read before authoring.', 'nibwp'),
    'category'    => 'kadence-pro',
    // `properties` must encode as a JSON object. An empty PHP array becomes
    // `[]`, which is a JSON *array* — schema validators then reject every call
    // with "input is not of type object", and additionalProperties:false leaves
    // the caller no way around it. new stdClass() encodes as `{}`.
    'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
    'execute_callback'    => 'nibwp_kadence_pro_list_blocks',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_kadence_pro_list_blocks(array $input): array
{
    $meta = nibwp_kadence_block_meta();
    $authoring = [];
    foreach ($meta as $name => $m) {
        $authoring[$name] = [
            'container'      => (bool) ($m['container'] ?? false),
            'parents'        => $m['parents'] ?? null,
            'needs_uniqueID' => nibwp_kadence_block_needs_uid($name),
            'maps_from'      => $m['maps_from'] ?? [],
            'note'           => $m['note'] ?? '',
        ];
    }
    return [
        'law' => 'Design lives in block ATTRIBUTES (Kadence renders the CSS from them). Never a custom class + CSS, never core/html for structure. Verify attribute names with kadence-pro-block-attributes — never guess.',
        'all_block_names'   => nibwp_kadence_block_names(),
        'authoring_blocks'  => $authoring,
        'source_html_fields' => nibwp_kadence_source_html_fields(),
        'attribute_traps'   => nibwp_kadence_attr_types(),
        'className_renders_on' => nibwp_kadence_classname_blocks(),
        'css_storage_order' => [
            '1. block attributes (first choice for ALL design)',
            '2. _kad_blocks_custom_css post meta (Kadence per-page Custom CSS) — only for post-loop .entry-*, third-party markup, @keyframes, :hover. Use kadence-pro-custom-css.',
            '3. Customizer Additional CSS — site-wide only (@font-face, header/footer Element chrome). Never page/section styling.',
        ],
        'rules' => [
            'A section = kadence/rowlayout htmlTag:"section" (default is div) → kadence/column → content.',
            'kadence/column only inside kadence/rowlayout; kadence/singlebtn only inside kadence/advancedbtn.',
            'advancedheading content / listitem text / infobox text are source:html — author the text (goes in markup).',
            'fontSize is [desktop,tablet,mobile]; never the legacy scalar "size". lineHeight/letterSpacing are numbers. overlay is a color string.',
            'Dark hero over image: currentOverlayTab:"gradient" + overlayGradient + overlayOpacity:100. Never a CSS ::before.',
            'Prefer kadence/posts (dynamic) over 3+ static cards. Client-editable rows → ACF repeater in a core/shortcode (not core/html).',
        ],
    ];
}

wp_register_ability('nibwp/kadence-pro-block-attributes', [
    'label'       => __('Kadence Pro — block attributes (live registry)', 'nibwp'),
    'description' => __('Read a Kadence block\'s real attributes (name, type, source, default) from the live block registry on THIS site. Use before authoring so you never guess an attribute name (a wrong name is silently ignored).', 'nibwp'),
    'category'    => 'kadence-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['block' => ['type' => 'string', 'description' => 'e.g. "kadence/rowlayout", "kadence/advancedheading".']],
        'required' => ['block'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_kadence_pro_block_attributes',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

function nibwp_kadence_pro_block_attributes(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    $block = (string) ($input['block'] ?? '');
    if (!class_exists('WP_Block_Type_Registry')) {
        return new WP_Error('no_registry', 'Block registry unavailable.');
    }
    $bt = WP_Block_Type_Registry::get_instance()->get_registered($block);
    if (!$bt) {
        return new WP_Error('not_registered', sprintf('Block "%s" is not registered on this site (is Kadence Blocks active?).', $block));
    }
    $attrs = [];
    foreach ((array) $bt->attributes as $k => $a) {
        $attrs[$k] = [
            'type'    => $a['type'] ?? '?',
            'source'  => $a['source'] ?? null,
            'default' => $a['default'] ?? null,
        ];
    }
    return ['block' => $block, 'attribute_count' => count($attrs), 'attributes' => $attrs];
}

wp_register_ability('nibwp/kadence-pro-custom-css', [
    'label'       => __('Kadence Pro — page custom CSS', 'nibwp'),
    'description' => __('Write page-scoped CSS to Kadence Blocks Pro\'s own _kad_blocks_custom_css field (the correct home for the rare CSS that cannot be a block attribute — post-loop internals, third-party markup, @keyframes, :hover). NOT Customizer Additional CSS, NOT a core/html block.', 'nibwp'),
    'category'    => 'kadence-pro',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'css'     => ['type' => 'string'],
            'mode'    => ['type' => 'string', 'enum' => ['replace', 'append'], 'default' => 'replace'],
        ],
        'required' => ['post_id', 'css'],
        'additionalProperties' => false,
    ],
    'execute_callback'    => 'nibwp_kadence_pro_custom_css',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

function nibwp_kadence_pro_custom_css(array $input): array|WP_Error
{
    $gate = nibwp_skill_gate('kadence-pro');
    if (is_wp_error($gate)) {
        return $gate;
    }
    return nibwp_kadence_set_custom_css(
        (int) ($input['post_id'] ?? 0),
        (string) ($input['css'] ?? ''),
        (string) ($input['mode'] ?? 'replace')
    );
}
