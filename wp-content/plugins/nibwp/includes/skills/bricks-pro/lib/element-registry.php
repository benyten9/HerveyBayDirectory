<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Canonical catalog of Bricks core elements (v1.10+).
 *
 * Used by the validator to reject `name` values that aren't real Bricks
 * elements. Custom elements registered by add-ons (Bricks Forge,
 * Frames, etc.) merge in via the `nibwp_bricks_pro_element_whitelist`
 * filter.
 *
 * Each element entry: { settings_keys: [], allowed_children: 'any'|'leaf'|'list:...' }
 * — settings_keys is informational (what's commonly used), not strict.
 * Bricks accepts arbitrary settings; we only police the element NAME.
 */

const NIBWP_BRICKS_CORE_ELEMENTS = [
    // Layout
    'section'        => ['settings_keys' => ['_id', 'tag', '_cssClasses'], 'allowed_children' => 'any'],
    'container'      => ['settings_keys' => ['_id', 'tag', '_cssClasses', '_gridGap'], 'allowed_children' => 'any'],
    'block'          => ['settings_keys' => ['_id', 'tag', '_cssClasses'], 'allowed_children' => 'any'],
    'div'            => ['settings_keys' => ['_id', 'tag', '_cssClasses'], 'allowed_children' => 'any'],

    // Content
    'heading'        => ['settings_keys' => ['_id', 'tag', 'text'], 'allowed_children' => 'leaf'],
    'text'           => ['settings_keys' => ['_id', 'tag', 'text'], 'allowed_children' => 'leaf'],
    'text-basic'     => ['settings_keys' => ['_id', 'tag', 'text'], 'allowed_children' => 'leaf'],
    'text-link'      => ['settings_keys' => ['_id', 'link', 'text'], 'allowed_children' => 'leaf'],
    'rich-text'      => ['settings_keys' => ['_id', 'text'], 'allowed_children' => 'leaf'],
    'list'           => ['settings_keys' => ['_id', 'tag', 'items'], 'allowed_children' => 'leaf'],
    'code'           => ['settings_keys' => ['_id', 'code'], 'allowed_children' => 'leaf'],

    // Media
    'image'          => ['settings_keys' => ['_id', 'image', 'caption'], 'allowed_children' => 'leaf'],
    'image-gallery'  => ['settings_keys' => ['_id', 'items'], 'allowed_children' => 'leaf'],
    'video'          => ['settings_keys' => ['_id', 'videoType', 'url'], 'allowed_children' => 'leaf'],
    'audio'          => ['settings_keys' => ['_id', 'audio'], 'allowed_children' => 'leaf'],
    'icon'           => ['settings_keys' => ['_id', 'icon'], 'allowed_children' => 'leaf'],
    'svg'            => ['settings_keys' => ['_id', 'source', 'code'], 'allowed_children' => 'leaf'],
    'logo'           => ['settings_keys' => ['_id', 'logo'], 'allowed_children' => 'leaf'],

    // Interactive
    'button'         => ['settings_keys' => ['_id', 'text', 'link'], 'allowed_children' => 'leaf'],
    'form'           => ['settings_keys' => ['_id', 'fields', 'submitButtonText'], 'allowed_children' => 'leaf'],
    'accordion'      => ['settings_keys' => ['_id', 'items'], 'allowed_children' => 'any'],
    'accordion-nested'=> ['settings_keys' => ['_id'], 'allowed_children' => 'any'],
    'tabs'           => ['settings_keys' => ['_id', 'items'], 'allowed_children' => 'any'],
    'tabs-nested'    => ['settings_keys' => ['_id'], 'allowed_children' => 'any'],
    'slider'         => ['settings_keys' => ['_id', 'items'], 'allowed_children' => 'leaf'],
    'slider-nested'  => ['settings_keys' => ['_id'], 'allowed_children' => 'any'],
    'toggle'         => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],

    // Site
    'nav-menu'       => ['settings_keys' => ['_id', 'menu'], 'allowed_children' => 'leaf'],
    'nav-nested'     => ['settings_keys' => ['_id'], 'allowed_children' => 'any'],
    'breadcrumbs'    => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'search'         => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'social-icons'   => ['settings_keys' => ['_id', 'items'], 'allowed_children' => 'leaf'],

    // Posts & Loops
    'post-title'     => ['settings_keys' => ['_id', 'tag'], 'allowed_children' => 'leaf'],
    'post-content'   => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'post-excerpt'   => ['settings_keys' => ['_id', 'wordsLimit'], 'allowed_children' => 'leaf'],
    'post-meta'      => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'post-author'    => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'post-comments'  => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'post-taxonomy'  => ['settings_keys' => ['_id', 'taxonomy'], 'allowed_children' => 'leaf'],
    'post-sharing'   => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'related-posts'  => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'pagination'     => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'posts'          => ['settings_keys' => ['_id', 'query'], 'allowed_children' => 'leaf'],

    // WooCommerce (gated; only valid when WooCommerce active)
    'woocommerce-cart-page'             => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-checkout-page'         => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-account-page'          => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-products'              => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-title'         => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-price'         => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-images'        => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-short-description'=> ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-add-to-cart'   => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-meta'          => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-product-tabs'          => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-related-products'      => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'woocommerce-mini-cart'             => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],

    // Other
    'map'            => ['settings_keys' => ['_id', 'addresses'], 'allowed_children' => 'leaf'],
    'countdown'      => ['settings_keys' => ['_id', 'date'], 'allowed_children' => 'leaf'],
    'counter'        => ['settings_keys' => ['_id', 'targetNumber'], 'allowed_children' => 'leaf'],
    'progress-bar'   => ['settings_keys' => ['_id', 'percentage'], 'allowed_children' => 'leaf'],
    'pie-chart'      => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'animated-typing'=> ['settings_keys' => ['_id', 'strings'], 'allowed_children' => 'leaf'],
    'typing-effect'  => ['settings_keys' => ['_id', 'strings'], 'allowed_children' => 'leaf'],
    'alert'          => ['settings_keys' => ['_id', 'text'], 'allowed_children' => 'leaf'],
    'divider'        => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'shape-divider'  => ['settings_keys' => ['_id'], 'allowed_children' => 'leaf'],
    'shortcode'      => ['settings_keys' => ['_id', 'shortcode'], 'allowed_children' => 'leaf'],
    'html'           => ['settings_keys' => ['_id', 'code'], 'allowed_children' => 'leaf'],
    'template'       => ['settings_keys' => ['_id', 'template'], 'allowed_children' => 'leaf'],
    // Bricks 1.10+ visual / animation elements
    'lottie'         => ['settings_keys' => ['_id', 'lottieUrl', 'loop', 'autoplay'], 'allowed_children' => 'leaf'],
    'mouse-cursor-effect' => ['settings_keys' => ['_id', 'effect'], 'allowed_children' => 'any'],
    'parallax'       => ['settings_keys' => ['_id', 'speed'], 'allowed_children' => 'any'],
    'reading-progress-bar' => ['settings_keys' => ['_id', 'color'], 'allowed_children' => 'leaf'],
    'before-after'   => ['settings_keys' => ['_id', 'beforeImage', 'afterImage'], 'allowed_children' => 'leaf'],
    'audio'          => ['settings_keys' => ['_id', 'audio'], 'allowed_children' => 'leaf'],
];

/**
 * Return the merged element whitelist (core + filtered add-on additions).
 *
 * @return array<string,array{settings_keys:array<int,string>,allowed_children:string}>
 */
function nibwp_bricks_pro_element_whitelist(): array
{
    /** @var array<string,array<string,mixed>> $list */
    $list = (array) apply_filters('nibwp_bricks_pro_element_whitelist', NIBWP_BRICKS_CORE_ELEMENTS);
    return $list;
}

/**
 * Quick membership check.
 */
function nibwp_bricks_pro_element_exists(string $name): bool
{
    return array_key_exists($name, nibwp_bricks_pro_element_whitelist());
}
