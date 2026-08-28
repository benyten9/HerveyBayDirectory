<?php

declare(strict_types=1);

/**
 * Kadence Blocks registry — the authoritative element + attribute catalog.
 *
 * The one law (see authoring/SKILL.md, references/workflow.md): every Kadence
 * block is a DYNAMIC block (render_callback) that compiles a scoped stylesheet
 * FROM ITS ATTRIBUTES at render time. So design = attributes, never a custom
 * class + CSS, never core/html. This file carries the verified attribute names,
 * types and defaults so the converter emits real attributes and the validator
 * rejects the traps. Re-verify on any site with the live registry (see
 * nibwp/kadence-pro-block-attributes) — do NOT guess an attribute name.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The valid Kadence + leaned-on core block names (validation whitelist).
 *
 * @return array<int,string>
 */
function nibwp_kadence_block_names(): array
{
    return [
        'kadence/rowlayout', 'kadence/column', 'kadence/spacer',
        'kadence/advancedheading', 'kadence/image', 'kadence/advancedgallery',
        'kadence/icon', 'kadence/single-icon', 'kadence/iconlist', 'kadence/listitem',
        'kadence/advancedbtn', 'kadence/singlebtn',
        'kadence/infobox', 'kadence/accordion', 'kadence/tabs', 'kadence/tab',
        'kadence/testimonials', 'kadence/testimonial', 'kadence/posts', 'kadence/table',
        'kadence/table-row', 'kadence/table-data', 'kadence/countdown', 'kadence/countup',
        'kadence/progress-bar', 'kadence/lottie', 'kadence/googlemaps', 'kadence/show-more',
        'kadence/tableofcontents', 'kadence/search', 'kadence/identity', 'kadence/navigation',
        'kadence/form', 'kadence/advanced-form',
        'kadence/header', 'kadence/header-row', 'kadence/header-column', 'kadence/header-section',
        'kadence/off-canvas', 'kadence/off-canvas-trigger',
        // core blocks Kadence styles + the two we lean on
        'core/paragraph', 'core/list', 'core/list-item', 'core/heading', 'core/image',
        'core/shortcode', 'core/html',
    ];
}

/**
 * `source:html` / rich-text fields — the ONLY values NOT regenerated from
 * attributes at render. They must be present in the block markup (the converter
 * places them). An attribute-only version of these blocks renders blank text.
 *
 * @return array<string,array<int,string>>
 */
function nibwp_kadence_source_html_fields(): array
{
    return [
        'kadence/advancedheading' => ['content'],
        'kadence/infobox'         => ['title', 'contentText', 'number'],
        'kadence/listitem'        => ['text'],
    ];
}

/**
 * Trap attributes: expected PHP type + notes. The validator uses this to reject
 * the classic silent failures (fontSize as a scalar, lineHeight as an array,
 * overlay as an object, size used instead of fontSize). Verified against the
 * live Kadence Blocks Pro registry.
 *
 * name => ['type' => 'array|number|string|bool', 'legacy' => bool, 'note' => string]
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function nibwp_kadence_attr_types(): array
{
    return [
        'kadence/advancedheading' => [
            'content'       => ['type' => 'string', 'source_html' => true],
            'fontSize'      => ['type' => 'array',  'note' => '[desktop,tablet,mobile] — the responsive one'],
            'size'          => ['type' => 'number', 'legacy' => true, 'note' => 'legacy scalar — use fontSize instead'],
            'lineHeight'    => ['type' => 'number', 'note' => 'a number, not an array'],
            'mobileLineHeight' => ['type' => 'number'],
            'letterSpacing' => ['type' => 'number', 'note' => 'a number, not an array'],
            'lineType'      => ['type' => 'string', 'note' => "use '' for unitless — 'px' gives a 1.08px line box"],
            'sizeType'      => ['type' => 'string'],
            'htmlTag'       => ['type' => 'string', 'default' => 'heading'],
            'level'         => ['type' => 'number', 'default' => 2],
            'color'         => ['type' => 'string'],
            'typography'    => ['type' => 'string', 'note' => 'family name only — does not load the font'],
            'googleFont'    => ['type' => 'bool'],
            'loadGoogleFont' => ['type' => 'bool'],
            'margin'        => ['type' => 'array'],
            'maxWidth'      => ['type' => 'array', 'note' => 'array on advancedheading, number on rowlayout'],
        ],
        'kadence/rowlayout' => [
            'htmlTag'          => ['type' => 'string', 'default' => 'div', 'note' => "set 'section'"],
            'columns'          => ['type' => 'number', 'default' => 2],
            'bgColor'          => ['type' => 'string'],
            'bgImg'            => ['type' => 'string'],
            'bgImgID'          => ['type' => 'number'],
            'overlay'          => ['type' => 'string', 'note' => 'a color STRING, not an array/object'],
            'currentOverlayTab' => ['type' => 'string', 'default' => 'normal', 'note' => "set 'gradient' to use overlayGradient"],
            'overlayGradient'  => ['type' => 'string'],
            'overlayOpacity'   => ['type' => 'number', 'default' => 30, 'note' => 'set 100'],
            'minHeight'        => ['type' => 'number', 'default' => 0],
            'minHeightUnit'    => ['type' => 'string', 'default' => 'px'],
            'maxWidth'         => ['type' => 'number', 'note' => 'number on rowlayout'],
            'padding'          => ['type' => 'array'],
            'verticalAlignment' => ['type' => 'string', 'default' => 'top'],
        ],
        'kadence/advancedbtn' => [
            'hAlign' => ['type' => 'string', 'default' => 'center'],
        ],
        'kadence/singlebtn' => [
            'text'          => ['type' => 'string', 'note' => 'plain attribute, headless-safe'],
            'link'          => ['type' => 'string'],
            'inheritStyles' => ['type' => 'string', 'default' => 'fill'],
        ],
    ];
}

/**
 * Blocks whose `className` attribute actually renders (for the rare CSS you are
 * allowed). Column / singlebtn / advancedbtn do NOT — target their generated
 * selectors instead.
 *
 * @return array<int,string>
 */
function nibwp_kadence_classname_blocks(): array
{
    return ['kadence/rowlayout', 'kadence/advancedheading', 'kadence/iconlist', 'core/image'];
}

/**
 * Rich per-block authoring metadata (containers, nesting, uid, HTML mapping).
 *
 * @return array<string,array<string,mixed>>
 */
function nibwp_kadence_block_meta(): array
{
    return [
        'kadence/rowlayout' => [
            'container' => true, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['section', 'header', 'footer', 'main', 'div.row', 'div.container'],
            'note' => 'A section. Set htmlTag:"section" (default is div). Holds kadence/column children; columns = child count.',
        ],
        'kadence/column' => [
            'container' => true, 'parents' => ['kadence/rowlayout'], 'needs_uid' => false,
            'maps_from' => ['div.col', 'div.column'],
            'note' => 'ONLY inside kadence/rowlayout. Does not render className — target .kadence-column{uid}.',
        ],
        'kadence/advancedheading' => [
            'container' => false, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            'note' => 'content is source:html (goes in markup). Set htmlTag:"heading"+level, fontSize:[d,t,m], lineHeight (number), lineType:"", color, typography.',
        ],
        'kadence/advancedbtn' => [
            'container' => true, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['div.buttons', 'div.btn-group'],
            'note' => 'Button wrapper (hAlign default "center"). Holds kadence/singlebtn.',
        ],
        'kadence/singlebtn' => [
            'container' => false, 'parents' => ['kadence/advancedbtn'], 'needs_uid' => true,
            'maps_from' => ['a.button', 'a.btn', 'button'],
            'note' => 'ONLY inside kadence/advancedbtn. text + link are plain attributes (headless-safe).',
        ],
        'kadence/image' => [
            'container' => false, 'parents' => null, 'needs_uid' => false,
            'maps_from' => ['img'],
            'note' => 'url + alt + id. Use core/image where a static save must survive (kadence/image save can return null).',
        ],
        'kadence/infobox' => [
            'container' => false, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['div.card', 'div.feature', 'article.card'],
            'note' => 'title, contentText, number are source:html (in markup). One per column for feature grids.',
        ],
        'kadence/iconlist' => [
            'container' => true, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['ul', 'ol'],
            'note' => 'Holds kadence/listitem children (item text is source:html). Renders className.',
        ],
        'kadence/listitem' => [
            'container' => false, 'parents' => ['kadence/iconlist'], 'needs_uid' => false,
            'maps_from' => ['li'],
            'note' => 'text is source:html (in markup).',
        ],
        'kadence/icon' => [
            'container' => false, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['svg', 'i.fa', 'span.icon'],
            'note' => 'icons:[{icon,size}]. SVG is generated on editor save (recovery-save).',
        ],
        'kadence/spacer' => [
            'container' => false, 'parents' => null, 'needs_uid' => false,
            'maps_from' => ['hr', 'div.spacer'],
            'note' => 'spacerHeight / dividerEnable.',
        ],
        'kadence/posts' => [
            'container' => false, 'parents' => null, 'needs_uid' => true,
            'maps_from' => ['div.posts', 'div.blog-grid'],
            'note' => 'Dynamic query loop — fully dynamic, never needs materialising. Use instead of repeated static cards.',
        ],
        'core/paragraph' => [
            'container' => false, 'parents' => null, 'needs_uid' => false,
            'maps_from' => ['p'],
            'note' => 'Body text (Kadence has no paragraph block).',
        ],
        'core/shortcode' => [
            'container' => false, 'parents' => null, 'needs_uid' => false,
            'maps_from' => [],
            'note' => 'Wrap an ACF-repeater display shortcode here (NOT core/html) for client-editable content.',
        ],
    ];
}

/** True if $name is a real Kadence (or leaned-on core) block. */
function nibwp_kadence_is_block(string $name): bool
{
    return in_array($name, nibwp_kadence_block_names(), true);
}

/**
 * Allowed parents for a block, or null when it can live anywhere.
 *
 * @return array<int,string>|null
 */
function nibwp_kadence_block_parents(string $name): ?array
{
    $meta = nibwp_kadence_block_meta();
    if (isset($meta[$name]) && array_key_exists('parents', $meta[$name])) {
        return $meta[$name]['parents'];
    }
    return match ($name) {
        'kadence/column'      => ['kadence/rowlayout'],
        'kadence/singlebtn'   => ['kadence/advancedbtn'],
        'kadence/tab'         => ['kadence/tabs'],
        'kadence/testimonial' => ['kadence/testimonials'],
        'kadence/listitem'    => ['kadence/iconlist'],
        'kadence/table-row'   => ['kadence/table'],
        'kadence/table-data'  => ['kadence/table-row'],
        default => null,
    };
}

/** True if the block requires a uniqueID attribute (to emit its wrapper). */
function nibwp_kadence_block_needs_uid(string $name): bool
{
    $meta = nibwp_kadence_block_meta();
    if (isset($meta[$name]['needs_uid'])) {
        return (bool) $meta[$name]['needs_uid'];
    }
    return str_starts_with($name, 'kadence/');
}

/**
 * Whether a colLayout key is one Kadence offers for that column count.
 *
 * Read out of the layout picker in blocks-rowlayout.js, which branches on the
 * column count and offers a different set for each. "equal" and "row" are
 * everywhere; the rest belong to specific counts. A key that does not fit the
 * count sends the editor back to its picker exactly as an empty one does — so a
 * two-column row asking for "left-forty" is as broken as one asking for
 * nothing. The union of the desktop and responsive pickers is taken, since both
 * write the same attribute.
 */
function nibwp_kadence_col_layout_valid(string $layout, int $columns): bool
{
    static $by_count = [
        1 => ['equal', 'row'],
        2 => ['equal', 'left-golden', 'right-golden', 'row'],
        3 => ['equal', 'left-half', 'right-half', 'center-half', 'center-wide', 'center-exwide', 'first-row', 'last-row', 'two-grid', 'row'],
        4 => ['equal', 'left-forty', 'right-forty', 'two-grid', 'row'],
        5 => ['equal', 'two-grid', 'three-grid', 'row'],
        6 => ['equal', 'two-grid', 'three-grid', 'row'],
    ];

    return in_array($layout, $by_count[$columns] ?? ['equal'], true);
}
