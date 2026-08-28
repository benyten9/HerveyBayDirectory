<?php

declare(strict_types=1);

/**
 * Kadence Pro — block-tree validator. Enforces the KB (references/workflow.md,
 * troubleshooting.md): design as attributes, native blocks only, no core/html
 * for structure, the responsive/type traps, overlay coherence, source:html
 * content present. Runs on the BUILT tree and re-runs at the persist gate.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';

/**
 * @param array<int,array<string,mixed>> $blocks Built block nodes.
 * @param array<string,mixed>            $ctx
 * @return array{passed:bool, failed:array<int,string>, warnings:array<int,string>, block_count:int}
 */
function nibwp_kadence_validate_blocks(array $blocks, array $ctx = []): array
{
    $failed = [];
    $warnings = [];
    $seen_uids = [];
    $count = 0;
    $classname_ok = nibwp_kadence_classname_blocks();

    $walk = function (array $nodes, ?string $parent) use (&$walk, &$failed, &$warnings, &$seen_uids, &$count, $classname_ok): void {
        // Loop candidate: 3+ identical content-card siblings.
        $names = array_map(static fn($n) => is_array($n) ? (string) ($n['blockName'] ?? '') : '', $nodes);
        foreach (array_count_values(array_filter($names)) as $bn => $n) {
            if ($n >= 3 && in_array($bn, ['kadence/infobox', 'kadence/testimonial'], true)) {
                $warnings[] = sprintf('%d repeated %s siblings — consider a dynamic kadence/posts loop.', $n, $bn);
            }
        }

        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['blockName'])) {
                continue;
            }
            $name  = (string) $node['blockName'];
            $attrs = (array) ($node['attrs'] ?? []);
            $html  = (string) ($node['innerHTML'] ?? '');
            $count++;

            if (!nibwp_kadence_is_block($name)) {
                $failed[] = sprintf('Unknown block "%s". Use nibwp/kadence-pro-list-blocks for the valid set.', $name);
                continue;
            }

            // Iron rule: no core/html for structure.
            if ($name === 'core/html') {
                $failed[] = 'core/html is not allowed — rebuild with native Kadence blocks (rowlayout/column/advancedheading/…), or a core/shortcode for ACF-driven content.';
            }

            // Nesting.
            $allowed_parents = nibwp_kadence_block_parents($name);
            if ($allowed_parents !== null && !in_array((string) $parent, $allowed_parents, true)) {
                $failed[] = sprintf('"%s" must be inside %s (got parent "%s").', $name, implode(' / ', $allowed_parents), $parent ?? 'root');
            }

            // uniqueID.
            if (nibwp_kadence_block_needs_uid($name)) {
                $uid = (string) ($attrs['uniqueID'] ?? '');
                if ($uid === '') {
                    $failed[] = sprintf('"%s" is missing its uniqueID.', $name);
                } elseif (isset($seen_uids[$uid])) {
                    $failed[] = sprintf('Duplicate uniqueID "%s" (on %s).', $uid, $name);
                } else {
                    $seen_uids[$uid] = true;
                }
            }

            // className on a block that does not render it.
            if (!empty($attrs['className']) && !in_array($name, $classname_ok, true)) {
                $warnings[] = sprintf('%s does not render className ("%s") — target .kadence-column{uid} / .kb-btn{uid} instead, or style via attributes.', $name, (string) $attrs['className']);
            }
            // An inline style dict = design-in-CSS, which the client rejects.
            if (isset($attrs['style']) && (is_string($attrs['style']) || is_array($attrs['style']))) {
                $failed[] = sprintf('%s carries an inline style — move the design into Kadence attributes (typography/color/spacing/overlay…).', $name);
            }

            // ---- The attribute traps (references/attribute-reference.md) ----
            if (array_key_exists('fontSize', $attrs) && !is_array($attrs['fontSize'])) {
                $failed[] = sprintf('%s fontSize must be a [desktop,tablet,mobile] array, not a scalar.', $name);
            }
            if (array_key_exists('size', $attrs)) {
                $warnings[] = sprintf('%s uses the legacy "size" — prefer fontSize:[d,t,m] (size is silently the wrong one).', $name);
            }
            foreach (['lineHeight', 'letterSpacing'] as $numAttr) {
                if (array_key_exists($numAttr, $attrs) && is_array($attrs[$numAttr])) {
                    $failed[] = sprintf('%s %s must be a number, not an array.', $name, $numAttr);
                }
            }

            // ---- Section + overlay coherence (rowlayout) ----
            if ($name === 'kadence/rowlayout') {
                // A row with no layout is not a styling nit. The editor reads
                // an empty colLayout as "not configured" and shows its layout
                // picker in place of the row — the page renders on the front
                // end and cannot be edited at all. The converter fills this in;
                // the check is here so a hand-built or refined tree cannot
                // reintroduce it silently.
                $cols = 0;
                foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
                    if ((string) ($child['blockName'] ?? '') === 'kadence/column') {
                        $cols++;
                    }
                }
                $layout = (string) ($attrs['colLayout'] ?? '');
                if ($layout === '') {
                    $failed[] = 'kadence/rowlayout has no colLayout — the editor replaces the row with its layout picker. Set "equal" (valid for 1-6 columns).';
                } elseif ($cols > 0 && !nibwp_kadence_col_layout_valid($layout, $cols)) {
                    $failed[] = sprintf('colLayout "%s" is not offered for %d column(s) — the editor falls back to its layout picker.', $layout, $cols);
                }
                if ($cols > 0 && (int) ($attrs['columns'] ?? 0) !== $cols) {
                    $failed[] = sprintf(
                        'rowlayout columns is %s but it holds %d kadence/column child(ren) — the editor lays out by the attribute, so the difference shows as empty or missing columns.',
                        wp_json_encode($attrs['columns'] ?? null),
                        $cols
                    );
                }
                if (($attrs['htmlTag'] ?? 'div') === 'div') {
                    $warnings[] = 'kadence/rowlayout htmlTag is "div" — set "section" for a real <section> element.';
                }
                if (!empty($attrs['overlayGradient']) && ($attrs['currentOverlayTab'] ?? 'normal') !== 'gradient') {
                    $failed[] = 'overlayGradient is ignored unless currentOverlayTab is "gradient".';
                }
                if (array_key_exists('overlay', $attrs) && !is_string($attrs['overlay'])) {
                    $failed[] = 'rowlayout overlay must be a color STRING (not an array/object).';
                }
                $hasOverlay = !empty($attrs['overlayGradient']) || !empty($attrs['overlay']);
                if ($hasOverlay && (int) ($attrs['overlayOpacity'] ?? 30) < 50) {
                    $warnings[] = 'overlayOpacity is low (default 30) — set 100 and bake alpha into the gradient stops.';
                }
                if (!empty($attrs['minHeight']) && empty($attrs['minHeightUnit'])) {
                    $warnings[] = 'minHeight set without minHeightUnit — pair with minHeightUnit:"vh" (or px).';
                }
            }

            // ---- source:html content present ----
            if ($name === 'kadence/advancedheading' && trim(wp_strip_all_tags($html)) === '') {
                $failed[] = 'kadence/advancedheading has no content (content is source:html — it must be authored, not attribute-only).';
            }
            if ($name === 'kadence/singlebtn' && trim((string) ($attrs['text'] ?? '')) === '') {
                $failed[] = 'kadence/singlebtn has no text.';
            }
            if ($name === 'kadence/image' && trim((string) ($attrs['url'] ?? '')) === '') {
                $failed[] = 'kadence/image has no url.';
            }
            if ($name === 'kadence/image' && trim((string) ($attrs['alt'] ?? '')) === '') {
                $warnings[] = 'kadence/image has no alt text (accessibility).';
            }

            $sub = (array) ($node['innerBlocks'] ?? []);
            if ($sub !== []) {
                $walk($sub, $name);
            }
        }
    };

    $walk($blocks, null);

    // Prove the text survives being written.
    //
    // Everything above inspects the tree we hold in memory. What lands in the
    // post is the SERIALIZED form, and the two can disagree: a block whose
    // innerContent did not describe its innerHTML serialized self-closing and
    // silently lost every word on the page, while this function reported clean.
    // A dry run that passes at the exact moment data is about to be dropped is
    // worse than no dry run, so the check is now on the output, not the input.
    foreach (nibwp_kadence_authored_text($blocks) as $needle) {
        $failed[] = sprintf(
            'Authored text "%s" does not survive serialization — it would be written as an empty block. This is a bug in NibWP, not in your tree; please report it.',
            mb_strimwidth($needle, 0, 60, '…')
        );
        break; // One report is enough; they share a cause.
    }

    return [
        'passed'      => $failed === [],
        'failed'      => $failed,
        'warnings'    => $warnings,
        'block_count' => $count,
    ];
}

/**
 * Authored text that does NOT appear in the serialized markup.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array<int,string>
 */
function nibwp_kadence_authored_text(array $blocks): array
{
    if (!function_exists('nibwp_kadence_serialize_block')) {
        return [];
    }

    $markup = '';
    foreach ($blocks as $b) {
        if (is_array($b)) {
            $markup .= nibwp_kadence_serialize_block($b);
        }
    }

    $missing = [];
    $scan = static function (array $nodes) use (&$scan, &$missing, $markup): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $text = trim(wp_strip_all_tags((string) ($node['innerHTML'] ?? '')));
            if ($text !== '' && !str_contains($markup, $text)) {
                $missing[] = $text;
            }
            $scan((array) ($node['innerBlocks'] ?? []));
        }
    };
    $scan($blocks);

    return $missing;
}

/**
 * What this document must show once a browser has it.
 *
 * Derived from the tree rather than configured, because an expectation someone
 * has to remember to write is an expectation that goes missing on the day it
 * matters. Every entry here corresponds to a bug that shipped: the row wrapper
 * to the missing kbVersion, the img tag to the attribute-only image, the
 * authored text to the innerContent loss before that.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array{expect_text: array<int,string>, expect_markup: array<int,string>}
 */
function nibwp_kadence_render_expectations(array $blocks): array
{
    $text = [];
    $markup = [];

    $walk = static function (array $nodes) use (&$walk, &$text, &$markup): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $name = (string) ($node['blockName'] ?? '');
            $attrs = (array) ($node['attrs'] ?? []);

            if ($name === 'kadence/rowlayout') {
                $markup[] = 'kb-row-layout-id' . (string) ($attrs['uniqueID'] ?? '');
                $tag = (string) ($attrs['htmlTag'] ?? 'div');
                if (in_array($tag, ['section', 'article', 'header', 'footer'], true)) {
                    $markup[] = '<' . $tag;
                }
            }
            if ($name === 'kadence/image' && !empty($attrs['url'])) {
                $markup[] = '<img';
            }

            // A short, distinctive slice of the authored copy. The whole string
            // would break on any entity the renderer normalises, and a single
            // word would collide with boilerplate.
            $html = (string) ($node['innerHTML'] ?? '');
            $plain = trim(wp_strip_all_tags($html));
            if ($plain !== '' && strlen($plain) >= 8) {
                $text[] = mb_substr($plain, 0, 40);
            }

            $walk((array) ($node['innerBlocks'] ?? []));
        }
    };
    $walk($blocks);

    return [
        'expect_text'   => array_values(array_slice(array_unique($text), 0, 6)),
        'expect_markup' => array_values(array_unique(array_filter($markup))),
    ];
}
