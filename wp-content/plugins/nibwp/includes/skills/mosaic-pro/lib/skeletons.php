<?php

declare(strict_types=1);

/**
 * Mosaic Pro — section blueprints for a brief or a screenshot.
 *
 * A screenshot has no markup, and a brief has none either. These are real
 * Mosaic trees with sound structure and responsive spacing, built from the
 * shorthand: a section band, a width-capped inner, content in a flex or grid
 * layout, mobile values where a desktop value would not fit a phone. Copy is in
 * [brackets] so nothing placeholder survives a review unnoticed. They are a
 * start: the design direction decides colours, type and rhythm.
 */

if (!defined('ABSPATH')) {
    exit();
}

function nibwp_mosaic_pro_skeleton_types(): array
{
    return ['hero', 'features', 'cta', 'pricing', 'testimonials', 'faq', 'stats', 'header', 'footer', 'section'];
}

/** A section band with a centred, width-capped inner. */
function nibwp_mosaic_pro_band(string $name, array $children, array $inner_style = [], string $tag = 'section'): array
{
    return [
        'type'     => 'section',
        'name'     => $name,
        'tag'      => $tag,
        'style'    => ['base' => ['padding' => '96px 24px'], 'tablet' => ['padding' => '72px 24px'], 'mobile' => ['padding' => '56px 16px']],
        'children' => [[
            'type'     => 'div',
            'name'     => $name . ' inner',
            'style'    => ['base' => array_merge(['max-width' => '1200px', 'margin' => '0 auto', 'display' => 'flex', 'flex-direction' => 'column', 'gap' => '48px'], $inner_style)],
            'children' => $children,
        ]],
    ];
}

function nibwp_mosaic_pro_heading(string $text, string $tag = 'h2', array $extra = []): array
{
    $sizes = ['h1' => ['56px', '40px'], 'h2' => ['40px', '30px'], 'h3' => ['22px', '20px']];
    [$desktop, $mobile] = $sizes[$tag] ?? ['20px', '18px'];

    return [
        'type'  => 'text',
        'tag'   => $tag,
        'text'  => $text,
        'style' => ['base' => array_merge(['font-size' => $desktop, 'line-height' => '1.1', 'font-weight' => '700', 'letter-spacing' => '-0.02em', 'margin' => '0'], $extra), 'mobile' => ['font-size' => $mobile]],
    ];
}

function nibwp_mosaic_pro_para(string $text, array $extra = []): array
{
    return ['type' => 'text', 'tag' => 'p', 'text' => $text, 'style' => ['base' => array_merge(['font-size' => '18px', 'line-height' => '1.6', 'margin' => '0', 'opacity' => '0.8'], $extra)]];
}

function nibwp_mosaic_pro_cta(string $label, string $url = '#', bool $primary = true): array
{
    return [
        'type'  => 'button',
        'url'   => $url,
        'text'  => $label,
        'style' => [
            'base'  => [
                'display'          => 'inline-flex',
                'align-items'      => 'center',
                'padding'          => '14px 26px',
                'font-weight'      => '600',
                'background-color' => $primary ? '#111827' : 'transparent',
                'color'            => $primary ? '#ffffff' : '#111827',
                'border-radius'    => '10px',
                'border'           => $primary ? 'none' : '1px solid currentColor',
                'transition'       => 'transform .15s ease, opacity .15s ease',
            ],
            'hover' => ['opacity' => '0.9', 'transform' => 'translateY(-1px)'],
        ],
    ];
}

/** Columns that stack on a phone. */
function nibwp_mosaic_pro_grid(array $children, int $cols = 3, string $gap = '24px'): array
{
    return [
        'type'     => 'div',
        'name'     => 'Grid',
        'css'      => sprintf('grid-template-columns: repeat(%d, minmax(0, 1fr));', $cols),
        'style'    => [
            'base'   => ['display' => 'grid', 'gap' => $gap],
            'tablet' => ['css' => sprintf('grid-template-columns: repeat(%d, minmax(0, 1fr));', min($cols, 2))],
            'mobile' => ['css' => 'grid-template-columns: minmax(0, 1fr);'],
        ],
        'children' => $children,
    ];
}

function nibwp_mosaic_pro_card(array $children): array
{
    return [
        'type'     => 'div',
        'name'     => 'Card',
        'style'    => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '12px', 'padding' => '28px', 'background-color' => '#ffffff', 'border-radius' => '16px', 'border' => '1px solid rgba(17, 24, 39, 0.08)']],
        'children' => $children,
    ];
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_pro_skeleton(string $type, string $brief = ''): array
{
    $intro = static fn(string $h, string $p) => [
        'type'     => 'div',
        'name'     => 'Intro',
        'style'    => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '16px', 'max-width' => '720px']],
        'children' => [nibwp_mosaic_pro_heading($h), nibwp_mosaic_pro_para($p)],
    ];

    switch ($type) {
        case 'hero':
            return [nibwp_mosaic_pro_band('Hero', [[
                'type'     => 'div',
                'name'     => 'Hero layout',
                'css'      => 'grid-template-columns: 1.1fr 0.9fr;',
                'style'    => ['base' => ['display' => 'grid', 'gap' => '56px', 'align-items' => 'center'], 'tablet' => ['css' => 'grid-template-columns: minmax(0, 1fr);']],
                'children' => [
                    [
                        'type'     => 'div',
                        'name'     => 'Copy',
                        'style'    => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '24px']],
                        'children' => [
                            nibwp_mosaic_pro_heading('[The promise, in one line]', 'h1'),
                            nibwp_mosaic_pro_para('[Who it is for and what changes for them, in two sentences.]', ['font-size' => '20px']),
                            ['type' => 'div', 'name' => 'Actions', 'style' => ['base' => ['display' => 'flex', 'flex-wrap' => 'wrap', 'gap' => '12px']], 'children' => [nibwp_mosaic_pro_cta('[Primary action]'), nibwp_mosaic_pro_cta('[Secondary action]', '#', false)]],
                        ],
                    ],
                    ['type' => 'image', 'image' => 'https://placehold.co/960x720', 'alt' => '[What the picture shows]', 'css' => 'border-radius: 20px; width: 100%; height: auto; object-fit: cover;'],
                ],
            ]], [], 'header')];

        case 'features':
            $card = static fn(int $n) => nibwp_mosaic_pro_card([nibwp_mosaic_pro_heading('[Benefit ' . $n . ']', 'h3'), nibwp_mosaic_pro_para('[One or two sentences on why it matters.]', ['font-size' => '16px'])]);

            return [nibwp_mosaic_pro_band('Features', [$intro('[What you get]', '[One line that frames the list.]'), nibwp_mosaic_pro_grid([$card(1), $card(2), $card(3), $card(4), $card(5), $card(6)], 3)])];

        case 'cta':
            return [nibwp_mosaic_pro_band('Call to action', [[
                'type'     => 'div',
                'name'     => 'Panel',
                'style'    => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'align-items' => 'center', 'text-align' => 'center', 'gap' => '20px', 'padding' => '64px 32px', 'background-color' => '#111827', 'color' => '#ffffff', 'border-radius' => '24px'], 'mobile' => ['padding' => '40px 20px']],
                'children' => [nibwp_mosaic_pro_heading('[Ask for the one thing]'), nibwp_mosaic_pro_para('[Remove the last doubt.]'), nibwp_mosaic_pro_cta('[Action]')],
            ]])];

        case 'pricing':
            $plan = static fn(string $name, string $price, bool $featured) => nibwp_mosaic_pro_card([
                nibwp_mosaic_pro_heading('[' . $name . ']', 'h3'),
                nibwp_mosaic_pro_heading('[' . $price . ']', 'h2'),
                ['type' => 'list', 'children' => [['type' => 'list-item', 'text' => '[Included]'], ['type' => 'list-item', 'text' => '[Included]'], ['type' => 'list-item', 'text' => '[Included]']]],
                nibwp_mosaic_pro_cta('[Choose ' . $name . ']', '#', $featured),
            ]);

            return [nibwp_mosaic_pro_band('Pricing', [$intro('[Pick a plan]', '[What every plan includes.]'), nibwp_mosaic_pro_grid([$plan('Starter', '€0', false), $plan('Pro', '€29/mo', true), $plan('Team', '€99/mo', false)], 3)])];

        case 'testimonials':
            $quote = static fn() => nibwp_mosaic_pro_card([nibwp_mosaic_pro_para('“[A specific result, in the customer\'s words.]”', ['font-size' => '18px', 'opacity' => '1']), nibwp_mosaic_pro_para('[Name], [role]', ['font-size' => '14px'])]);

            return [nibwp_mosaic_pro_band('Testimonials', [$intro('[What customers say]', '[Social proof framing.]'), nibwp_mosaic_pro_grid([$quote(), $quote(), $quote()], 3)])];

        case 'faq':
            return [nibwp_mosaic_pro_band('FAQ', [$intro('[Questions]', '[Answers before anyone has to ask.]'), ['type' => 'accordion']], ['max-width' => '880px'])];

        case 'stats':
            $stat = static fn() => ['type' => 'div', 'name' => 'Stat', 'style' => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '4px']], 'children' => [nibwp_mosaic_pro_heading('[00k]', 'h2'), nibwp_mosaic_pro_para('[what it counts]', ['font-size' => '15px'])]];

            return [nibwp_mosaic_pro_band('Stats', [nibwp_mosaic_pro_grid([$stat(), $stat(), $stat(), $stat()], 4, '32px')])];

        case 'header':
            return [[
                'type'     => 'section',
                'name'     => 'Header',
                'tag'      => 'header',
                'style'    => ['base' => ['padding' => '20px 24px'], 'mobile' => ['padding' => '14px 16px']],
                'children' => [[
                    'type'     => 'div',
                    'name'     => 'Header bar',
                    'style'    => ['base' => ['max-width' => '1200px', 'margin' => '0 auto', 'display' => 'flex', 'align-items' => 'center', 'justify-content' => 'space-between', 'gap' => '24px']],
                    'children' => [
                        ['type' => 'text', 'tag' => 'p', 'text' => '[Brand]', 'style' => ['base' => ['font-weight' => '700', 'font-size' => '20px', 'margin' => '0']]],
                        ['type' => 'navbar'],
                        nibwp_mosaic_pro_cta('[Action]'),
                    ],
                ]],
            ]];

        case 'footer':
            $col = static fn(string $h) => ['type' => 'div', 'name' => $h, 'style' => ['base' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '10px']], 'children' => [nibwp_mosaic_pro_heading('[' . $h . ']', 'h3', ['font-size' => '15px']), ['type' => 'list', 'css' => 'list-style: none; padding: 0; margin: 0;', 'children' => [['type' => 'list-item', 'text' => '[Link]'], ['type' => 'list-item', 'text' => '[Link]'], ['type' => 'list-item', 'text' => '[Link]']]]]];

            return [nibwp_mosaic_pro_band('Footer', [nibwp_mosaic_pro_grid([$col('Company'), $col('Product'), $col('Help'), $col('Legal')], 4, '32px'), nibwp_mosaic_pro_para('© [Year] [Brand]', ['font-size' => '14px'])], [], 'footer')];

        default:
            return [nibwp_mosaic_pro_band('Section', [$intro($brief !== '' ? '[' . $brief . ']' : '[Heading]', '[Supporting line.]')])];
    }
}
