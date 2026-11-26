<?php

declare(strict_types=1);

/**
 * Mosaic element and style vocabulary, as Mosaic 1.0.x declares it.
 *
 * Read from the plugin source, not guessed: element types are the ids each
 * NodeTypes/<Element>/<Element>TypeFactory registers, data keys are the
 * createDataSimple() names in each <Element>MResourceData, and the enum values
 * are the ValidatorAcceptedValues lists in Data/StyleData/StyleStateDataMeta.php.
 *
 * Mosaic validates every value itself and silently drops what it rejects, so
 * this list is what lets an agent be told about a mistake BEFORE it lands. The
 * writer still diffs what Mosaic kept against what was sent, so a value this
 * file gets wrong is reported, not lost quietly.
 *
 * Plain arrays only: no Mosaic class is touched here, so the Pro skill and its
 * standalone check can load this file without WordPress or Mosaic.
 */

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('nibwp_mosaic_catalog')) {

    /**
     * @return array{elements: array<string,array<string,mixed>>, common: string[], states: array<string,string>, breakpoints: array<string,string>, style: array<string,mixed>}
     */
    function nibwp_mosaic_catalog(): array
    {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        $catalog = [
            // Keys every element accepts (ElementMResourceDataAbstract).
            'common' => ['name', 'locked', 'hidden', 'conditions', 'attributes', 'cssClasses', 'attrID', 'style', 'interactions', 'interactionShorthand'],

            'elements' => [
                'section' => [
                    'role'     => 'container',
                    'purpose'  => 'A full-width band of the page: hero, features, footer. The outermost box of a section.',
                    'data'     => ['tagName'],
                    'children' => 'any',
                ],
                'div' => [
                    'role'     => 'container',
                    'purpose'  => 'Any box: a row, a column, a grid, a card, an inner wrapper that caps the width.',
                    'data'     => ['tagName', 'dynamicSources'],
                    'children' => 'any',
                ],
                'text' => [
                    'role'     => 'text',
                    'purpose'  => 'A heading or paragraph. tagName picks h1-h6, p, span, blockquote and so on. Its words live in wysiwyg-* children, never in its own data.',
                    'data'     => ['tagName'],
                    'children' => 'inline',
                ],
                'wysiwyg-text' => [
                    'role'     => 'inline',
                    'purpose'  => 'A run of words inside a text, button label or link.',
                    'data'     => ['text', 'format'],
                    'children' => 'none',
                ],
                'wysiwyg-link' => [
                    'role'     => 'inline',
                    'purpose'  => 'An inline link inside a text. Holds wysiwyg-text children.',
                    'data'     => ['url', 'target', 'rel'],
                    'children' => 'inline',
                ],
                'wysiwyg-span' => [
                    'role'     => 'inline',
                    'purpose'  => 'A styled span of words inside a text (a highlighted word, a coloured phrase).',
                    'data'     => [],
                    'children' => 'inline',
                ],
                'wysiwyg-linebreak' => [
                    'role'     => 'inline',
                    'purpose'  => 'A line break inside a text.',
                    'data'     => [],
                    'children' => 'none',
                ],
                'wysiwyg-variable' => [
                    'role'     => 'inline',
                    'purpose'  => 'A dynamic value inside a text (post title, field value).',
                    'data'     => [],
                    'children' => 'none',
                ],
                'button' => [
                    'role'     => 'container',
                    'purpose'  => 'A link or button. Its label is a text child; an icon child is allowed beside it.',
                    'data'     => ['url', 'target', 'rel', 'inactive'],
                    'children' => 'any',
                ],
                'image' => [
                    'role'     => 'leaf',
                    'purpose'  => 'An image. image takes a URL or an attachment; always give alt.',
                    'data'     => ['image', 'alt', 'width', 'height', 'loading', 'fetchPriority', 'srcset', 'sizes'],
                    'children' => 'none',
                ],
                'icon' => [
                    'role'     => 'leaf',
                    'purpose'  => 'An inline SVG icon. svg takes the markup.',
                    'data'     => ['svg', 'colors', 'autoScale'],
                    'children' => 'none',
                ],
                'list' => [
                    'role'     => 'container',
                    'purpose'  => 'A ul/ol list. Children are list-item.',
                    'data'     => ['tagName', 'listType', 'listStart'],
                    'children' => ['list-item', 'list-loop-items'],
                ],
                'list-item' => [
                    'role'     => 'container',
                    'purpose'  => 'One li. Holds a text, or anything else.',
                    'data'     => ['mode'],
                    'children' => 'any',
                ],
                'video' => [
                    'role'     => 'leaf',
                    'purpose'  => 'A self-hosted video.',
                    'data'     => ['video', 'sources', 'autoplay', 'muted', 'loop', 'controls', 'playsinline', 'width', 'height', 'preload', 'poster', 'threshold'],
                    'children' => 'none',
                ],
                'youtube' => [
                    'role'     => 'leaf',
                    'purpose'  => 'A YouTube embed (youtubeUrl).',
                    'data'     => ['youtubeUrl', 'autoplay', 'muted', 'controls', 'playsinline', 'privacy', 'lazyLoad', 'startTime', 'suggestedVideos', 'threshold'],
                    'children' => 'none',
                ],
                'vimeo' => [
                    'role'     => 'leaf',
                    'purpose'  => 'A Vimeo embed (vimeoUrl).',
                    'data'     => ['vimeoUrl', 'autoplay', 'muted', 'controls', 'playsinline', 'privacy', 'lazyLoad', 'startTime', 'threshold'],
                    'children' => 'none',
                ],
                'googlemaps' => [
                    'role'     => 'leaf',
                    'purpose'  => 'A Google Maps embed (query = address or place).',
                    'data'     => ['query', 'zoom', 'maptype', 'lazyLoad'],
                    'children' => 'none',
                ],
                'openstreetmap' => [
                    'role'     => 'leaf',
                    'purpose'  => 'An OpenStreetMap embed.',
                    'data'     => ['latitude', 'longitude', 'zoom', 'layer', 'lazyLoad'],
                    'children' => 'none',
                ],
                'accordion' => [
                    'role'     => 'widget',
                    'purpose'  => 'FAQ-style accordion. Send it with no children and Mosaic builds its default items; then fill them in.',
                    'data'     => ['openMode', 'firstOpened', 'requireOneOpen', 'animation'],
                    'children' => ['accordion-item', 'accordion-loop-items'],
                    'preset'   => true,
                ],
                'tabs' => [
                    'role'     => 'widget',
                    'purpose'  => 'Tabbed panels. Send it with no children and Mosaic builds its default tabs; then fill them in.',
                    'data'     => ['defaultTabIndex'],
                    'children' => ['tabs-menu', 'tabs-content'],
                    'preset'   => true,
                ],
                'navbar' => [
                    'role'     => 'widget',
                    'purpose'  => 'A responsive navigation bar with its mobile toggle. Send it with no children for Mosaic\'s default structure.',
                    'data'     => ['buttonBreakpoint', 'animation', 'disableScroll'],
                    'children' => ['navigation', 'navbar-toggle'],
                    'preset'   => true,
                ],
                'slider' => [
                    'role'     => 'widget',
                    'purpose'  => 'A slider. Structure: slider-slides > slider-slide, plus optional arrows and navigation.',
                    'data'     => ['duration', 'easing', 'animation', 'isAutoplay', 'autoplayDelay', 'autoplayLimit', 'isCarousel', 'defaultSlideIndex', 'ariaLabel'],
                    'children' => ['slider-slides', 'slider-loop-slides', 'slider-arrow-left', 'slider-arrow-right', 'slider-navigation'],
                ],
                'wp-menu' => [
                    'role'     => 'leaf',
                    'purpose'  => 'A WordPress menu.',
                    'data'     => [],
                    'children' => 'none',
                ],
                'code' => [
                    'role'     => 'leaf',
                    'purpose'  => 'Raw HTML/JS (Mosaic Pro). Use only for what no element can express.',
                    'data'     => ['insertLocation', 'content', 'processShortcodes'],
                    'children' => 'none',
                ],
            ],

            // Inner parts of the widgets above, with the parent each belongs in. Their data is checked by Mosaic only.
            'parts' => [
                'accordion-item'          => 'accordion',
                'accordion-loop-items'    => 'accordion',
                'accordion-title'         => 'accordion-item',
                'accordion-content'       => 'accordion-item',
                'tabs-menu'               => 'tabs',
                'tabs-tab'                => 'tabs-menu',
                'tabs-loop-tabs'          => 'tabs-menu',
                'tabs-content'            => 'tabs',
                'tabs-tab-pane'           => 'tabs-content',
                'tabs-loop-tab-panes'     => 'tabs-content',
                'slider-slides'           => 'slider',
                'slider-loop-slides'      => 'slider',
                'slider-slide'            => 'slider-slides',
                'slider-arrow-left'       => 'slider',
                'slider-arrow-right'      => 'slider',
                'slider-navigation'       => 'slider',
                'slider-navigation-bullet' => 'slider-navigation',
                'navigation'              => 'navbar',
                'navbar-toggle'           => 'navbar',
                'list-loop-items'         => 'list',
            ],

            // Internal node types: Mosaic owns them. Never author them.
            'system' => ['template-internal', 'template-external', 'master-root', 'document', 'body', 'component-internal', 'component-external', 'component-root', 'styleguide-root'],

            // StatesMeta::init().
            'states' => [
                '&'               => 'default',
                'hover'           => ':hover',
                'focus'           => ':focus',
                'focus-visible'   => ':focus-visible',
                'hover-when-link' => ':hover when the element is a link or button',
                'hover-on-link'   => 'while an ancestor link or button is hovered',
            ],

            // BreakpointsRevisionModel::$DEFAULT_BREAKPOINTS_DATA (+ the base).
            'breakpoints' => [
                '_'  => 'base (all widths)',
                '_t' => 'tablet: max-width 1079px',
                '_m' => 'mobile: max-width 767px',
            ],

            'style' => [
                'enums' => [
                    'display'            => ['none', 'block', 'flex', 'grid', 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'contents'],
                    'flexWrap'           => ['nowrap', 'wrap', 'wrap-reverse'],
                    'flexDirection'      => ['row', 'row-reverse', 'column', 'column-reverse'],
                    'gridAutoFlow'       => ['row', 'row dense', 'column', 'column dense'],
                    'alignItems'         => ['normal', 'start', 'center', 'end', 'stretch', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline', 'first baseline', 'last baseline'],
                    'justifyItems'       => ['normal', 'start', 'center', 'end', 'stretch', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline', 'first baseline', 'last baseline'],
                    'alignContent'       => ['normal', 'start', 'center', 'end', 'stretch', 'space-between', 'space-around', 'space-evenly', 'flex-start', 'flex-end', 'baseline'],
                    'justifyContent'     => ['normal', 'start', 'center', 'end', 'stretch', 'space-between', 'space-around', 'space-evenly', 'flex-start', 'flex-end', 'left', 'right'],
                    'alignSelf'          => ['auto', 'start', 'center', 'end', 'stretch', 'normal', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline', 'first baseline', 'last baseline'],
                    'justifySelf'        => ['auto', 'start', 'center', 'end', 'stretch', 'normal', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline', 'first baseline', 'last baseline'],
                    'listStylePosition'  => ['inside', 'outside'],
                    'listStyleType'      => ['disc', 'circle', 'square', 'decimal', 'georgian', 'trad-chinese-informal', 'kannada', 'none'],
                    'textDecorationLine' => ['none', 'underline', 'overline', 'line-through'],
                    'fontStyle'          => ['normal', 'italic'],
                    'fontWeight'         => ['100', '200', '300', '400', '500', '600', '700', '800', '900'],
                    'textAlign'          => ['start', 'center', 'end', 'justify', 'left', 'right', 'inherit'],
                    'textTransform'      => ['none', 'lowercase', 'uppercase', 'capitalize'],
                    'whiteSpace'         => ['normal', 'pre-wrap', 'nowrap', 'pre'],
                ],
                // Length / colour / free-form properties stored as a plain CSS value string.
                'values' => [
                    'columnGap', 'rowGap', 'gridAutoColumns', 'gridAutoRows',
                    'flexBasis', 'order', 'fontFamily', 'color', 'fontSize', 'lineHeight', 'letterSpacing', 'wordSpacing',
                    'width', 'minWidth', 'maxWidth', 'height', 'minHeight', 'maxHeight',
                    'position', 'zIndex', 'top', 'right', 'bottom', 'left',
                    'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
                    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
                    'opacity', 'visibility', 'overflow', 'transformStyle', 'perspective', 'mixBlendMode', 'boxSizing',
                    'aspectRatio', 'backgroundColor', 'cursor',
                ],
                // Structured groups Mosaic stores in its own shape. Write these as CSS in customDeclarations.
                'structured' => [
                    'gridTemplateColumns', 'gridTemplateRows', 'gridTemplateAreas', 'gridChildPosition', 'flexSizing', 'textShadow', 'borderRadius', 'borderStyle', 'outlineStyle',
                    'boxShadow', 'filter', 'backdropFilter', 'transform', 'translate', 'scale', 'rotate', 'transformOrigin',
                    'transition', 'mask', 'perspectiveOrigin', 'objectFitStyle', 'backgroundStyle', 'collectionModesData',
                ],
                // The escape hatch: a CSS declaration block, validated and kept as written.
                'free' => 'customDeclarations',
            ],
        ];

        return $catalog;
    }

    /** Every style property name Mosaic accepts inside a breakpoint. */
    function nibwp_mosaic_style_properties(): array
    {
        $style = nibwp_mosaic_catalog()['style'];

        return array_merge(array_keys($style['enums']), $style['values'], $style['structured'], [$style['free']]);
    }

    /** CSS property (kebab or camel) to its Mosaic key, or '' when Mosaic has no plain-string key for it. */
    function nibwp_mosaic_css_to_key(string $property): string
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', strtolower(trim($property))))));
        $style = nibwp_mosaic_catalog()['style'];
        if (isset($style['enums'][$camel]) || in_array($camel, $style['values'], true)) {
            return $camel;
        }
        if ($camel === 'gap') {
            return 'gap';
        }

        return '';
    }
}
