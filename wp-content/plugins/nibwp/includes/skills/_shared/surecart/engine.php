<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared SureCart commerce-templates engine.
 *
 * The single source of SureCart storefront knowledge, consumed by BOTH the
 * etchwp-pro and bricks-pro skills. It holds builder-neutral section templates
 * (product, product grid, pricing, buy button, checkout, customer dashboard)
 * expressed as SureCart block markup + a styled layout wrapper, and emits them
 * as either an EtchWP payload (styles dict + gutenbergBlock tree) or a Bricks
 * payload (global_classes + elements). The SureCart blocks are rendered
 * server-side to <sc-*> web components, so both builders host the same markup
 * and only the surrounding layout/styling differs per builder.
 *
 * Functions use the nibwp_surecart_* prefix (neutral — no collision with
 * nibwp_etchwp_* / nibwp_bricks_*).
 */

/** The available SureCart section templates. */
function nibwp_surecart_templates(): array
{
    return [
        'buy-button' => [
            'name' => 'Buy button', 'params' => ['price_id'],
            'desc' => 'A single SureCart buy button bound to a price.',
        ],
        'product' => [
            'name' => 'Product page', 'params' => ['product_id'],
            'desc' => 'A full product form (media, title, price, variants, buy button) in a styled section.',
        ],
        'product-grid' => [
            'name' => 'Product grid', 'params' => ['columns', 'limit', 'collection_id'],
            'desc' => 'A responsive grid/list of products.',
        ],
        'pricing' => [
            'name' => 'Pricing table', 'params' => ['products'],
            'desc' => 'Side-by-side product cards (title, price, buy button) for a set of products.',
        ],
        'checkout' => [
            'name' => 'Checkout', 'params' => ['form_id'],
            'desc' => 'A checkout form region (renders the SureCart checkout form).',
        ],
        'dashboard' => [
            'name' => 'Customer dashboard', 'params' => [],
            'desc' => 'The tabbed SureCart customer dashboard in a styled wrapper.',
        ],
    ];
}

/** Build the inner SureCart block markup (string) for a template. */
function nibwp_surecart_block_markup(string $id, array $params): string
{
    $pid  = sanitize_text_field((string) ($params['product_id'] ?? ''));
    $prc  = sanitize_text_field((string) ($params['price_id'] ?? ''));
    $cols = max(1, min((int) ($params['columns'] ?? 3), 6));
    $lim  = max(1, min((int) ($params['limit'] ?? 12), 48));
    $coll = sanitize_text_field((string) ($params['collection_id'] ?? ''));

    switch ($id) {
        case 'buy-button':
            return '<!-- wp:surecart/buy-button ' . wp_json_encode(['price_id' => $prc, 'label' => (string) ($params['label'] ?? 'Buy now')], JSON_UNESCAPED_SLASHES) . ' /-->';
        case 'product':
            return '<!-- wp:surecart/product-page ' . wp_json_encode(['product_id' => $pid], JSON_UNESCAPED_SLASHES) . ' /-->';
        case 'product-grid':
            $attrs = ['columns' => $cols, 'limit' => $lim];
            if ($coll !== '') { $attrs['collection_id'] = $coll; }
            return '<!-- wp:surecart/product-list ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' /-->';
        case 'pricing':
            $out = '';
            foreach ((array) ($params['products'] ?? []) as $p) {
                $p = sanitize_text_field((string) $p);
                $out .= '<!-- wp:surecart/product-page ' . wp_json_encode(['product_id' => $p], JSON_UNESCAPED_SLASHES) . ' /-->';
            }
            return $out;
        case 'checkout':
            return '<!-- wp:surecart/checkout-form /-->';
        case 'dashboard':
            return '<!-- wp:surecart/customer-dashboard /-->';
    }
    return '';
}

/**
 * Build a per-builder payload for a SureCart template.
 *
 * @return array{builder:string,payload:array,meta:array}|WP_Error
 */
function nibwp_surecart_build(string $id, array $params, string $builder)
{
    $templates = nibwp_surecart_templates();
    if (!isset($templates[$id])) {
        return new WP_Error('bad_template', 'Unknown SureCart template: ' . $id, ['status' => 400]);
    }
    // Required-param check.
    foreach ((array) $templates[$id]['params'] as $req) {
        if (in_array($req, ['price_id', 'product_id', 'products'], true) && empty($params[$req])) {
            return new WP_Error('missing_param', sprintf('Template "%s" needs the "%s" param.', $id, $req), ['status' => 400]);
        }
    }
    $markup = nibwp_surecart_block_markup($id, $params);
    if ($markup === '') {
        return new WP_Error('empty_markup', 'Could not build SureCart block markup.', ['status' => 400]);
    }
    $brand = sanitize_key((string) ($params['brand'] ?? 'sc'));
    $comp  = $brand . '-sc' . str_replace('-', '', $id); // e.g. acme-scproduct

    if ($builder === 'bricks') {
        return nibwp_surecart_emit_bricks($id, $comp, $markup, $params);
    }
    return nibwp_surecart_emit_etch($id, $comp, $markup, $params, $id, $brand);
}

/** Layout CSS (ACSS-token, BEM) shared by both emitters, keyed by template. */
function nibwp_surecart_layout_css(string $id): string
{
    switch ($id) {
        case 'product-grid':
        case 'pricing':
            return 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--card-gap, 1.5rem);max-width:var(--content-width, 1200px);margin-inline:auto;padding-block:var(--section-space-m, 3rem);padding-inline:var(--content-padding, 1rem)';
        case 'buy-button':
            return 'display:flex;justify-content:center;padding-block:var(--space-m, 1.25rem)';
        case 'checkout':
            return 'max-width:var(--content-width-narrow, 720px);margin-inline:auto;padding-block:var(--section-space-m, 3rem);padding-inline:var(--content-padding, 1rem)';
        default: // product, dashboard
            return 'max-width:var(--content-width, 1200px);margin-inline:auto;padding-block:var(--section-space-m, 3rem);padding-inline:var(--content-padding, 1rem)';
    }
}

/**
 * Emit an EtchWP payload: a styled etch/element section wrapping the SureCart
 * block(s). Compliant with the etchwp validator (BEM brand prefix + ACSS tokens
 * with fallback).
 */
function nibwp_surecart_emit_etch(string $id, string $comp, string $markup, array $params, string $tpl, string $brand): array
{
    $style_id = $comp . '__inner-style';
    $section_block = [
        'blockName' => 'etch/element',
        'attrs' => [
            'tag' => 'div',
            'attributes' => ['class' => $comp . '__inner'],
            'styles' => [$style_id],
        ],
        'innerBlocks'  => array_map('nibwp_surecart_parse_block', nibwp_surecart_split_blocks($markup)),
        'innerHTML'    => '',
        'innerContent' => [],
    ];

    $name = 'SureCart ' . ucfirst(str_replace('-', ' ', $tpl));
    return [
        'builder' => 'etch',
        'payload' => [
            '__libraryMeta' => [
                'name'        => $name,
                'slug'        => sanitize_title($name),
                'brand'       => $brand,
                'type'        => 'section',
                'category'    => 'commerce',
                'tags'        => ['surecart', 'commerce', $tpl],
                'description' => 'SureCart ' . $tpl . ' section.',
            ],
            'styles' => [
                $style_id => [
                    'selector' => '.' . $comp . '__inner',
                    'css'      => nibwp_surecart_layout_css($id),
                ],
            ],
            'gutenbergBlock' => [
                'blockName' => 'etch/element',
                'attrs' => ['tag' => 'section', 'attributes' => ['class' => $comp], 'styles' => []],
                'innerBlocks'  => [$section_block],
                'innerHTML'    => '',
                'innerContent' => [],
            ],
        ],
        'meta' => ['template' => $tpl, 'surecart_markup' => $markup],
    ];
}

/** Emit a Bricks payload: a section/container hosting the SureCart block via a code element. */
function nibwp_surecart_emit_bricks(string $id, string $comp, string $markup, array $params): array
{
    // Bricks can't host Gutenberg blocks natively; render the SureCart block(s)
    // through a code element that runs do_blocks() so the <sc-*> web components
    // output on the front end. Styling lives on a BEM global class.
    $elements = [
        ['name' => 'section', 'settings' => ['_cssGlobalClasses' => [$comp]], '_id' => substr(md5($comp . 'sec'), 0, 6), 'children' => [1]],
        ['name' => 'container', 'settings' => [], '_id' => substr(md5($comp . 'con'), 0, 6), 'parent' => 0, 'children' => [2]],
        ['name' => 'code', 'settings' => [
            'executeCode' => true,
            'code' => "<?php echo do_blocks(" . var_export($markup, true) . "); ?>",
        ], '_id' => substr(md5($comp . 'code'), 0, 6), 'parent' => 1],
    ];
    return [
        'builder' => 'bricks',
        'payload' => [
            'global_classes' => [[
                'id'   => substr(md5($comp), 0, 6),
                'name' => $comp,
                'settings' => ['_cssCustom' => '%root%{' . nibwp_surecart_layout_css($id) . '}'],
            ]],
            'elements' => $elements,
        ],
        'meta' => ['template' => $id, 'surecart_markup' => $markup, 'note' => 'SureCart blocks render via a Bricks code element (do_blocks).'],
    ];
}

/** Split a markup string into top-level block comment chunks. */
function nibwp_surecart_split_blocks(string $markup): array
{
    if (preg_match_all('/<!--\s*wp:[^>]*?\/-->/s', $markup, $m) && $m[0]) {
        return $m[0];
    }
    return [$markup];
}

/** Parse one self-closing block comment into a WP block array. */
function nibwp_surecart_parse_block(string $chunk): array
{
    if (function_exists('parse_blocks')) {
        $parsed = parse_blocks($chunk);
        foreach ($parsed as $b) {
            if (!empty($b['blockName'])) {
                return $b;
            }
        }
    }
    return ['blockName' => 'core/html', 'attrs' => [], 'innerHTML' => $chunk, 'innerContent' => [$chunk], 'innerBlocks' => []];
}
