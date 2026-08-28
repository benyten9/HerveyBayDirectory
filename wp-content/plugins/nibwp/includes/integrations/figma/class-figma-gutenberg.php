<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Gutenberg — NDO → WordPress core-block markup.
 *
 * The universal builder target: core blocks render on any WordPress site with no
 * page-builder plugin. Emits valid block markup (comment-delimited) with rich
 * inline styles carried from the NDO so the output is pixel-faithful to the frame.
 * Container/section → core/group (flex), text → core/heading|paragraph, image
 * fills → group with a background image.
 */
class NIBWP_Figma_Gutenberg
{
    /**
     * @param array<string,mixed> $ndo
     */
    public function render(array $ndo): string
    {
        $root = $ndo['root'] ?? null;
        if (!is_array($root)) {
            return '';
        }
        return $this->block($root, true);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function block(array $node, bool $is_root = false): string
    {
        $type = (string) ($node['type'] ?? 'container');

        if ($type === 'text') {
            return $this->text_block($node);
        }

        // Everything else = a group (section / container / shape / component).
        return $this->group_block($node, $is_root);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function group_block(array $node, bool $is_root): string
    {
        $style = $this->style_string($node);
        $attrs = ['tagName' => 'div'];

        $layout = (array) ($node['layout'] ?? []);
        if (($layout['display'] ?? '') === 'flex') {
            $attrs['layout'] = [
                'type'        => 'flex',
                'orientation' => ($layout['flex-direction'] ?? 'row') === 'column' ? 'vertical' : 'horizontal',
                'flexWrap'    => ($layout['flex-wrap'] ?? '') === 'wrap' ? 'wrap' : 'nowrap',
            ];
            if (isset($layout['justify-content'])) {
                $attrs['layout']['justifyContent'] = $this->just_name((string) $layout['justify-content']);
            }
        } else {
            $attrs['layout'] = ['type' => 'constrained'];
        }

        $inner = '';
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $inner .= $this->block($child);
            }
        }

        $class = 'wp-block-group';
        if ($is_root) {
            $class .= ' nibwp-figma-root';
        }

        $style_attr = $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
        $json = (string) wp_json_encode($attrs);

        return "<!-- wp:group " . $json . " -->\n"
            . '<div class="' . esc_attr($class) . '"' . $style_attr . '>'
            . $inner
            . "</div>\n"
            . "<!-- /wp:group -->\n";
    }

    /**
     * @param array<string,mixed> $node
     */
    private function text_block(array $node): string
    {
        $text = (string) ($node['text'] ?? '');
        $tag = (string) ($node['tag'] ?? 'p');
        $style = $this->style_string($node);
        $style_attr = $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
        $html = nl2br(esc_html($text));

        if ($tag !== 'p' && preg_match('/^h[1-6]$/', $tag)) {
            $level = (int) substr($tag, 1);
            return '<!-- wp:heading {"level":' . $level . '} -->' . "\n"
                . '<' . $tag . ' class="wp-block-heading"' . $style_attr . '>' . $html . '</' . $tag . ">\n"
                . "<!-- /wp:heading -->\n";
        }

        return "<!-- wp:paragraph -->\n"
            . '<p' . $style_attr . '>' . $html . "</p>\n"
            . "<!-- /wp:paragraph -->\n";
    }

    private function just_name(string $css): string
    {
        return match ($css) {
            'center'        => 'center',
            'flex-end'      => 'right',
            'space-between' => 'space-between',
            default         => 'left',
        };
    }

    /**
     * Merge the node's layout + visual style into one inline CSS string.
     *
     * @param array<string,mixed> $node
     */
    private function style_string(array $node): string
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
        $out = '';
        foreach ($props as $k => $v) {
            $out .= $k . ':' . $v . ';';
        }
        return $out;
    }
}
