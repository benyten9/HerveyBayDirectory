<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/converter.php';

/**
 * Elementor Pro skill — validator.
 *
 * Hard FAILS block a persist. Soft WARNINGS are surfaced but allowed. The
 * distinction matters: Elementor group-controls (typography, border, box-shadow)
 * expand a single control id into many setting keys (typography_font_size, …), so
 * a strict "every setting key must equal a control id" rule would false-fail
 * legitimate output. Unknown *widgetType* and structural breakage are the real,
 * page-breaking errors — those fail; questionable control ids only warn.
 *
 * @return array{passed:bool,failed:array,warnings:array,element_count:int,widget_count:int}
 */
function nibwp_elementor_pro_validate(array $tree): array
{
    $failed   = [];
    $warnings = [];
    $flat     = nibwp_elementor_pro_flatten($tree);

    if ($flat === []) {
        return ['passed' => false, 'failed' => ['Empty element tree.'], 'warnings' => [], 'element_count' => 0, 'widget_count' => 0];
    }

    $elementor_live = nibwp_elementor_pro_active();
    $has_pro        = nibwp_elementor_pro_has_pro();
    $valid_widgets  = $elementor_live ? array_flip(nibwp_elementor_pro_widget_names()) : [];
    $ids_seen       = [];
    $widget_count   = 0;
    $responsive_hit = false;
    $control_cache  = [];

    foreach ($flat as $el) {
        $id     = (string) ($el['id'] ?? '');
        $elType = (string) ($el['elType'] ?? '');

        // ── ids ──
        if ($id === '') {
            $failed[] = 'An element has no id.';
        } elseif (isset($ids_seen[$id])) {
            $failed[] = sprintf('Duplicate element id "%s" — every element must be unique.', $id);
        } else {
            $ids_seen[$id] = true;
        }

        // ── legacy structure ──
        if ($elType === 'section' || $elType === 'column') {
            $warnings[] = sprintf('Legacy "%s" used — prefer a flexbox container for new layouts.', $elType);
        }

        if ($elType !== 'widget') {
            continue;
        }

        // ── widget ──
        $widget_count++;
        $wt = (string) ($el['widgetType'] ?? '');
        if ($wt === '') {
            $failed[] = sprintf('Widget %s has no widgetType.', $id);
            continue;
        }
        if ($elementor_live && !isset($valid_widgets[$wt])) {
            $failed[] = sprintf('Unknown widgetType "%s" (not registered on this site). Use nibwp/elementor-pro-list-widgets.', $wt);
            continue;
        }

        // Pro gate.
        if (!isset($control_cache[$wt])) {
            $control_cache[$wt] = $elementor_live ? nibwp_elementor_pro_widget_controls($wt) : ['is_pro' => false, 'controls' => []];
        }
        $schema = $control_cache[$wt];
        if (!empty($schema['is_pro']) && !$has_pro) {
            $failed[] = sprintf('Widget "%s" needs Elementor Pro, which is not active.', $wt);
            continue;
        }

        $settings = (array) ($el['settings'] ?? []);

        // HTML widget = last resort.
        if ($wt === 'html') {
            $warnings[] = 'HTML widget used — only acceptable when no native widget fits.';
        }

        // Image alt / attachment id.
        if ($wt === 'image') {
            $img = $settings['image'] ?? [];
            if (is_array($img) && empty($img['id'])) {
                $warnings[] = sprintf('Image %s has no attachment id — sideload the media so it has a real ID (not a hotlinked URL).', $id);
            }
        }

        // ── soft control-id sanity ──
        if ($elementor_live && !empty($schema['controls'])) {
            $ids = array_column($schema['controls'], 'id');
            $idset = array_flip($ids);
            $bps = nibwp_elementor_pro_breakpoints();
            foreach ($settings as $k => $v) {
                if (str_starts_with($k, '_') || str_starts_with($k, '__')) {
                    continue; // _element_*, __globals__, __dynamic__ — engine keys
                }
                if (nibwp_elementor_pro_setting_key_known((string) $k, $idset, $ids, $bps)) {
                    // responsive value present?
                    foreach ($bps as $bp) {
                        if ($bp !== 'desktop' && str_ends_with($k, '_' . $bp)) {
                            $responsive_hit = true;
                        }
                    }
                    continue;
                }
                $warnings[] = sprintf('Setting "%s" on %s ("%s") is not a known control id — verify with nibwp/elementor-pro-widget-schema.', $k, $id, $wt);
            }
        }
    }

    if ($elementor_live && !$responsive_hit && $widget_count > 0) {
        $warnings[] = 'No responsive (tablet/mobile) values found — add breakpoint variants so it is not desktop-only.';
    }

    // De-dupe messages.
    $failed   = array_values(array_unique($failed));
    $warnings = array_values(array_unique($warnings));

    return [
        'passed'        => $failed === [],
        'failed'        => $failed,
        'warnings'      => $warnings,
        'element_count' => count($flat),
        'widget_count'  => $widget_count,
    ];
}

/**
 * A setting key is "known" if it equals a control id, a control id + breakpoint
 * suffix, or begins with a control-id prefix (group-controls expand into
 * <id>_font_size, <id>_border_width, …).
 */
function nibwp_elementor_pro_setting_key_known(string $key, array $idset, array $ids, array $bps): bool
{
    if (isset($idset[$key])) {
        return true;
    }
    // strip a trailing _<breakpoint>
    foreach ($bps as $bp) {
        if ($bp !== 'desktop' && str_ends_with($key, '_' . $bp)) {
            $base = substr($key, 0, -strlen('_' . $bp));
            if (isset($idset[$base])) {
                return true;
            }
            $key = $base; // continue prefix check against the base
            break;
        }
    }
    // group-control expansion: key starts with "<control_id>_" or "<control_id>" (for typography_typography style)
    foreach ($ids as $cid) {
        if ($cid !== '' && (str_starts_with($key, $cid . '_') || $key === $cid)) {
            return true;
        }
    }
    return false;
}

/**
 * Is this an Elementor v4 atomic widget?
 *
 * Atomic widgets are all named `e-*` (e-heading, e-paragraph, e-button …) and
 * use a different rendering model from v3: no wrapper div, no widget class —
 * the widget IS the semantic tag.
 */
function nibwp_elementor_pro_is_atomic_widget(string $widget): bool
{
    return str_starts_with($widget, 'e-');
}

/**
 * The class that proves a widget drew itself, whichever generation it belongs to.
 *
 * v3 wraps every widget in `.elementor-widget-<type>`. v4 atomic widgets emit
 * no wrapper at all — they render as a bare tag carrying the base-style class
 * `<type>-base`, e.g. `<h2 class="e-heading-base">`. Verified against Elementor
 * 4.1.4 for e-heading, e-paragraph and e-button; asking for the v3 wrapper on
 * an atomic page is a marker that can never appear, so the render gate failed
 * pages that were rendering perfectly.
 */
function nibwp_elementor_pro_render_marker(string $widget): ?string
{
    if (!nibwp_elementor_pro_is_atomic_widget($widget)) {
        return 'elementor-widget-' . $widget;
    }

    // Ask Elementor rather than assuming the suffix. 18 of the 19 atomic
    // widgets on 4.1.4 do follow `<type>-base`, but e-component declares no
    // base styles at all — guessing there would demand a class that can never
    // appear, which is the exact bug this function exists to end. No declared
    // base style means we cannot prove the widget rendered, so we assert
    // nothing rather than assert something false.
    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::$instance->widgets_manager)) {
        $type = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget);

        if ($type && method_exists($type, 'get_base_styles')) {
            $keys = array_keys((array) $type->get_base_styles());

            // Prefer the widget's own base class over its variants
            // (e-heading declares e-heading-base and e-heading-link-base).
            if (in_array($widget . '-base', $keys, true)) {
                return $widget . '-base';
            }

            return $keys === [] ? null : (string) $keys[0];
        }
    }

    // Elementor not loaded (dry runs, tests): fall back to the convention.
    return $widget . '-base';
}

/**
 * The text an `html-v3` rich-text node actually puts on the page.
 *
 * Atomic rich text is a tree: a `content` string plus `children`, and children
 * are where mixed weight lives — "Read the <b>docs</b>" is one content string
 * and one child node.
 *
 * Only `content` is returned, deliberately. Elementor's own server-side
 * renderer discards the rest — Html_V3_Transformer::transform() is literally
 * `return $value['content'] ?? ''` on 4.1.4, verified by rendering a heading
 * with a bold child and getting back only the leading text. Asserting child
 * text would therefore fail pages that are rendering exactly as Elementor
 * intends, which is the same false negative this whole fix exists to remove.
 * Under-asserting is safe here; over-asserting blocks the customer.
 *
 * @param mixed $node
 */
function nibwp_elementor_pro_html_v3_text($node): string
{
    if (is_string($node)) {
        return $node;
    }

    if (!is_array($node)) {
        return '';
    }

    $content = $node['content'] ?? null;

    if (is_string($content)) {
        return $content;
    }

    // content may itself be a prop: ['$$type' => 'string', 'value' => '…']
    if (is_array($content) && isset($content['value']) && is_string($content['value'])) {
        return $content['value'];
    }

    return '';
}

/**
 * Read a settings value authored in any of the shapes the two generations use.
 *
 * v3 stores a plain string. v4 wraps every prop as `['$$type' => …, 'value' => …]`,
 * and for rich text that value is an `html-v3` tree rather than a string —
 * which is the shape Elementor itself writes for a heading or paragraph.
 *
 * Getting this wrong does not fail loudly. It yields no text, so the render
 * check on an atomic page verifies that the widget drew itself and never
 * checks that the words arrived — while still reporting that it checked.
 *
 * @param mixed $value
 */
function nibwp_elementor_pro_setting_text($value): ?string
{
    if (is_string($value)) {
        return $value;
    }

    if (!is_array($value) || !array_key_exists('value', $value)) {
        return null;
    }

    $inner = $value['value'];

    if (is_string($inner)) {
        return $inner;
    }

    // html-v3 (or anything else shaped like a rich-text node).
    if (is_array($inner)) {
        $text = nibwp_elementor_pro_html_v3_text($inner);

        return $text === '' ? null : $text;
    }

    return null;
}

/**
 * What an Elementor document must show once rendered.
 *
 * Derived from the tree so nobody has to remember to write it. A widget that
 * renders nothing is Elementor's normal response to a setting it reads before
 * it draws: it returns early and says nothing, which looks identical to markup
 * that was never written.
 *
 * @param array<int,array<string,mixed>> $elements
 * @return array{expect_text: array<int,string>, expect_markup: array<int,string>}
 */
function nibwp_elementor_pro_render_expectations(array $elements): array
{
    $text = [];
    $markup = [];

    $walk = static function (array $nodes) use (&$walk, &$text, &$markup): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $elType = (string) ($node['elType'] ?? '');
            $widget = (string) ($node['widgetType'] ?? '');
            $settings = (array) ($node['settings'] ?? []);

            if ($elType === 'widget' && $widget !== '') {
                // The class that proves the widget actually drew itself —
                // which differs between v3 and v4. Null means the widget
                // declares nothing we could look for; see render_marker().
                $marker = nibwp_elementor_pro_render_marker($widget);
                if ($marker !== null) {
                    $markup[] = $marker;
                }
            }

            // Text the user authored, taken from the settings Elementor renders.
            // `paragraph` is where atomic keeps body copy; the other keys are v3
            // names that atomic reuses (title on e-heading, text on e-button).
            foreach (['title', 'editor', 'text', 'paragraph', 'description_text', 'title_text'] as $key) {
                $val = nibwp_elementor_pro_setting_text($settings[$key] ?? null);
                if ($val !== null) {
                    $plain = trim(wp_strip_all_tags($val));
                    if ($plain !== '' && strlen($plain) >= 8) {
                        $text[] = mb_substr($plain, 0, 40);
                    }
                }
            }

            $walk((array) ($node['elements'] ?? []));
        }
    };
    $walk($elements);

    return [
        'expect_text'   => array_values(array_slice(array_unique($text), 0, 6)),
        'expect_markup' => array_values(array_slice(array_unique($markup), 0, 8)),
    ];
}
