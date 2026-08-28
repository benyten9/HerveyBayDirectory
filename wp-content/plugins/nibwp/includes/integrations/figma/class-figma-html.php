<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Html — NDO → clean, builder-neutral semantic HTML.
 *
 * This is the artifact every builder skill consumes: etchwp-pro / elementor-pro /
 * bricks-pro / oxygen / kadence-pro all expose an `html-to-*` ability that rebuilds
 * HTML as their native format. So figma-pro reads Figma once → emits this HTML, and
 * the active builder's own ability turns it native. figma-pro never re-implements a
 * per-builder emitter (Gutenberg core is the only built-in fallback).
 *
 * Structure: section/container/shape/component → <section>/<div> (flex from layout),
 * text → <h1..h6>/<p>. Inline styles carry the NDO's pixel-faithful CSS.
 */
class NIBWP_Figma_Html
{
    /** @param array<string,mixed> $ndo */
    public function render(array $ndo): string
    {
        $root = $ndo['root'] ?? null;
        if (!is_array($root)) {
            return '';
        }
        return $this->node($root, true);
    }

    /** @param array<string,mixed> $node */
    private function node(array $node, bool $is_root = false): string
    {
        $type = (string) ($node['type'] ?? 'container');

        if ($type === 'text') {
            $text = nl2br(esc_html((string) ($node['text'] ?? '')));
            $tag = (string) ($node['tag'] ?? 'p');
            if (!preg_match('/^h[1-6]$/', $tag)) {
                $tag = 'p';
            }
            $style = $this->style_attr($node);
            return "<{$tag}{$style}>{$text}</{$tag}>\n";
        }

        // section / container / shape / component → a box.
        $tag = $is_root ? 'section' : 'div';
        $style = $this->style_attr($node);
        $name = (string) ($node['name'] ?? '');
        $class = $this->class_attr($name, $is_root);

        $inner = '';
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $inner .= $this->node($child);
            }
        }

        return "<{$tag}{$class}{$style}>\n{$inner}</{$tag}>\n";
    }

    /** BEM-ish class from the Figma layer name (a hint for the builder). */
    private function class_attr(string $name, bool $is_root): string
    {
        $slug = sanitize_html_class(sanitize_title($name));
        $classes = [];
        if ($is_root) {
            $classes[] = 'figma-root';
        }
        if ($slug !== '') {
            $classes[] = 'figma-' . $slug;
        }
        return $classes === [] ? '' : ' class="' . esc_attr(implode(' ', $classes)) . '"';
    }

    /** Merge NDO layout + style prop maps into one inline style attribute. */
    private function style_attr(array $node): string
    {
        $props = [];
        foreach ((array) ($node['layout'] ?? []) as $k => $v) {
            $props[(string) $k] = (string) $v;
        }
        foreach ((array) ($node['style'] ?? []) as $k => $v) {
            $props[(string) $k] = (string) $v;
        }
        if ($props === []) {
            return '';
        }
        $css = '';
        foreach ($props as $k => $v) {
            $css .= $k . ':' . $v . ';';
        }
        return ' style="' . esc_attr($css) . '"';
    }
}
