<?php

declare(strict_types=1);

/**
 * Kadence Pro — authoring-tree → Kadence block tree.
 *
 * The one law: design lives in ATTRIBUTES; Kadence renders the CSS from them
 * (dynamic blocks). So this converter passes the node's `attrs` straight through
 * (they ARE the design) and mints a uniqueID. The ONLY things it writes into
 * block markup are the `source:html` content fields (advancedheading `content`,
 * listitem `text`, infobox text) — those are the one exception that render does
 * not regenerate. It never invents styling markup and never emits CSS.
 *
 * Front end renders immediately (dynamic). An editor recovery-save is only
 * needed to generate icon SVGs / iconlist <li> / image markup and clear the
 * "Attempt Block Recovery" prompt — see references/recovery-save.md.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';

/**
 * @param array<int,array<string,mixed>> $tree Root-level nodes.
 * @return array<int,array<string,mixed>>
 */
function nibwp_kadence_build_blocks(array $tree): array
{
    return array_values(array_filter(array_map(
        static fn($n) => is_array($n) ? nibwp_kadence_build_block_node($n) : null,
        $tree
    )));
}

/**
 * @param array<string,mixed> $node
 * @return array<string,mixed>|null
 */
function nibwp_kadence_build_block_node(array $node): ?array
{
    $name = (string) ($node['block'] ?? $node['name'] ?? '');
    if ($name === '') {
        return null;
    }

    $attrs = (array) ($node['attrs'] ?? []);
    // The authored `content` / `text` for source:html blocks (goes in markup, not attrs).
    $content = (string) ($node['content'] ?? $node['text'] ?? $node['innerHTML'] ?? '');

    // Conveniences → attributes.
    if ($name === 'kadence/advancedheading' && isset($node['level']) && !isset($attrs['level'])) {
        $attrs['level'] = (int) $node['level'];
    }
    // singlebtn `text` is a plain (non-source:html) attribute → headless-safe.
    if ($name === 'kadence/singlebtn' && $content !== '' && !isset($attrs['text'])) {
        $attrs['text'] = $content;
        $content = '';
    }

    // Mint a uniqueID for blocks that need one to emit their wrapper/CSS.
    if (nibwp_kadence_block_needs_uid($name) && empty($attrs['uniqueID'])) {
        $attrs['uniqueID'] = bin2hex(random_bytes(3)); // 6 hex, unique per block
    }

    $inner = [];
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $built = nibwp_kadence_build_block_node($child);
            if ($built !== null) {
                $inner[] = $built;
            }
        }
    }

    $attrs = nibwp_kadence_structural_attrs($name, $attrs, $inner);

    return [
        'blockName'    => $name,
        'attrs'        => $attrs,
        'innerBlocks'  => $inner,
        'innerHTML'    => nibwp_kadence_source_html_markup($name, $attrs, $content),
        'innerContent' => [],
    ];
}

/**
 * Give a row the attributes that describe the children it actually has.
 *
 * A rowlayout with no `colLayout` is not a styling nit: the editor treats an
 * empty one as "not configured yet" and replaces the whole row with its layout
 * picker — the "Select Your Layout" / "Design Library" placeholder. The page
 * renders on the front end and is unusable in the editor, which is exactly the
 * shape of "I can see the elements but I cannot edit them".
 *
 * `columns` is the same story one step quieter. It defaults to 2, so a three
 * column row silently lays out as two, and an authored count that disagrees
 * with the children produces an empty slot. The children are the truth here —
 * they are what will actually be rendered — so they win, and the validator
 * reports the disagreement rather than the converter hiding it.
 *
 * @param array<string,mixed>            $attrs
 * @param array<int,array<string,mixed>> $inner Column ids are stamped in place.
 * @return array<string,mixed>
 */
function nibwp_kadence_structural_attrs(string $name, array $attrs, array &$inner): array
{
    // Kadence branches its render on kbVersion, and without it the block falls
    // back to a path that emits no wrapper at all. Measured on 3.7.8: a row
    // without it renders zero kb-row-layout classes and no <section>, so every
    // row-level attribute — background, padding, min-height, overlay — is
    // silently dropped while the text inside still appears. That is worse than
    // the empty colLayout above, because the page looks built and is not.
    //
    // The editor stamps this on all five of these, so the converter does too.
    static $kb_version = [
        'kadence/rowlayout'    => 2,
        'kadence/infobox'      => 2,
        'kadence/testimonials' => 2,
        'kadence/googlemaps'   => 2,
        'kadence/show-more'    => 2,
    ];
    if (isset($kb_version[$name]) && !isset($attrs['kbVersion'])) {
        $attrs['kbVersion'] = $kb_version[$name];
    }

    if ($name === 'kadence/rowlayout') {
        $columns = 0;
        foreach ($inner as $i => $child) {
            if ((string) ($child['blockName'] ?? '') !== 'kadence/column') {
                continue;
            }
            $columns++;
            // Kadence numbers columns from 1, and uses the id to match a column
            // to its per-column settings. Left at the default every column in
            // the row claims to be the first one.
            if (empty($inner[$i]['attrs']['id'])) {
                $inner[$i]['attrs']['id'] = $columns;
            }
        }
        // A row built without column children is still a row; Kadence lays it
        // out as one. Better a usable single column than an empty picker.
        $columns = max(1, $columns);
        $attrs['columns'] = $columns;

        $layout = (string) ($attrs['colLayout'] ?? '');
        if ($layout === '' || !nibwp_kadence_col_layout_valid($layout, $columns)) {
            $attrs['colLayout'] = 'equal';
        }
    }

    // Every column needs its own id and uniqueID: the id is its position, and
    // the uniqueID is what its generated CSS is keyed to. Without one a styled
    // column renders unstyled, because the rule it was written for has no
    // element to match.
    if ($name === 'kadence/column' && empty($attrs['uniqueID'])) {
        $attrs['uniqueID'] = bin2hex(random_bytes(3));
    }

    return $attrs;
}

// nibwp_kadence_col_layout_valid() lives in registry.php — the validator needs
// it too and loads only that file.

/**
 * Emit the `source:html` markup for the one class of fields render does not
 * regenerate. Verified pattern for advancedheading; simple wrappers for the
 * others. Attribute-only styling still renders dynamically — this only carries
 * the TEXT.
 *
 * @param array<string,mixed> $attrs
 */
function nibwp_kadence_source_html_markup(string $name, array $attrs, string $content): string
{
    // kadence/image builds its markup from attributes rather than from authored
    // text, so it must reach the switch even with no content.
    if ($content === '' && $name !== 'kadence/listitem' && $name !== 'kadence/image') {
        // No authored text and not a list item → dynamic render handles the rest.
        return $name === 'core/shortcode' ? '' : '';
    }
    $uid = (string) ($attrs['uniqueID'] ?? '');

    switch ($name) {
        case 'kadence/advancedheading':
            // Verified markup (references/attribute-reference.md §Content).
            $tag = (string) ($attrs['htmlTag'] ?? 'heading');
            if ($tag === 'heading' || $tag === '') {
                $lvl = (int) ($attrs['level'] ?? 2);
                $tag = 'h' . (($lvl >= 1 && $lvl <= 6) ? $lvl : 2);
            } elseif (!in_array($tag, ['p', 'div', 'span'], true)) {
                $tag = 'div';
            }
            return sprintf(
                '<%1$s class="kt-adv-heading%2$s wp-block-kadence-advancedheading" data-kb-block="kb-adv-heading%2$s">%3$s</%1$s>',
                $tag,
                esc_attr($uid),
                wp_kses_post($content)
            );

        case 'kadence/listitem':
            return '<li class="wp-block-kadence-listitem">' . wp_kses_post($content) . '</li>';

        case 'kadence/image':
            // Every useful attribute on this block is sourced from its markup,
            // so an attribute-only block has nothing to read and renders no
            // <img> at all — measured at 69 bytes of output with or without a
            // real attachment id. Shape taken from a harvested editor sample.
            $url = (string) ($attrs['url'] ?? '');
            if ($url === '') {
                return '';
            }
            $align = (string) ($attrs['align'] ?? '');
            $fig = $align !== '' ? 'align' . $align : '';
            $dims = '';
            if (!empty($attrs['width'])) {
                $dims .= sprintf(' width="%d"', (int) $attrs['width']);
                $fig = trim($fig . ' is-resized');
            }
            if (!empty($attrs['height'])) {
                $dims .= sprintf(' height="%d"', (int) $attrs['height']);
            }
            return sprintf(
                '<div class="wp-block-kadence-image kb-image%1$s"><figure class="%2$s">'
                . '<img src="%3$s" alt="%4$s" class="kb-img"%5$s/></figure></div>',
                esc_attr($uid),
                esc_attr($fig),
                esc_url($url),
                esc_attr((string) ($attrs['alt'] ?? '')),
                $dims
            );

        case 'core/paragraph':
            return '<p>' . wp_kses_post($content) . '</p>';

        case 'core/heading':
            $lvl = (int) ($attrs['level'] ?? 2);
            $lvl = ($lvl >= 1 && $lvl <= 6) ? $lvl : 2;
            return sprintf('<h%1$d>%2$s</h%1$d>', $lvl, wp_kses_post($content));

        case 'core/shortcode':
            return $content; // the [shortcode] itself

        case 'core/html':
            return $content;

        default:
            // infobox title/contentText etc. — best-effort text carrier; a
            // recovery-save canonicalises the block's real inner structure.
            return $content !== '' ? ('<div class="kb-src-html">' . wp_kses_post($content) . '</div>') : '';
    }
}
