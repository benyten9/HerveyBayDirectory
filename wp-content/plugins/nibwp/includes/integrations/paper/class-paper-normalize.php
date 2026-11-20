<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Paper_Normalize — a sanitised Paper tree → a NibWP Design Object (NDO 1.0).
 *
 * This is the whole reuse argument. The NDO is the contract the emitters consume,
 * so emitting the identical shape here means every builder target that already
 * works for a Figma frame works for a Paper design with no new code: core blocks,
 * the neutral HTML every builder skill's html-to-* ability reads, and whatever is
 * added to that set later.
 *
 * The one key that looks wrong is right: `figma_type` carries PAPER's node type.
 * The key is named for the tool that defined the format first, and renaming it
 * would break the Figma side for no gain — the emitters do not branch on it, they
 * branch on the generic `type`. So it stays, with this comment next to it.
 *
 * The real difference from the Figma normalizer is the input, not the output.
 * Figma hands over geometry and 0..1 colour floats that have to be converted into
 * CSS; Paper is CSS-native, so the work here is classifying declarations into the
 * layout and style buckets the emitters expect, and deciding which generic type
 * each node is.
 */
class NIBWP_Paper_Normalize
{
    /**
     * Declarations that belong in `layout` rather than `style`.
     *
     * Same split the Figma normalizer makes: the box's own flow goes in layout so a
     * builder emitter can translate it into a native layout setting, and everything
     * visual goes in style so it can be carried as CSS.
     *
     * @var array<int, string>
     */
    private const LAYOUT_PROPS = [
        'display', 'flex-direction', 'flex-wrap', 'flex-flow', 'gap', 'row-gap', 'column-gap',
        'justify-content', 'justify-items', 'align-items', 'align-content', 'align-self',
        'place-items', 'place-content',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'grid-template-columns', 'grid-template-rows', 'grid-template-areas', 'grid-auto-flow',
        'grid-auto-columns', 'grid-auto-rows', 'grid-column', 'grid-row',
    ];

    /** @var array<string, string> image ref → resolved URL */
    private array $assets = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * @param array<int, array<string, mixed>> $assets  the design's asset rows
     * @param array<int, string>               $warnings warnings already collected by ingest
     */
    public function __construct(array $assets = [], array $warnings = [])
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $ref = (string) ($asset['ref'] ?? '');
            // The attachment wins when there is one: a page should serve the image
            // from this site's own Media Library. Where attach_asset has not run
            // yet, the export URL stands in so a preview is not full of holes.
            $url = (string) ($asset['url'] ?? '');
            if ($url === '') {
                $url = (string) ($asset['source_url'] ?? '');
            }
            if ($ref !== '' && $url !== '') {
                $this->assets[$ref] = $url;
                if ((int) ($asset['attachment_id'] ?? 0) === 0) {
                    $this->warnings[] = sprintf(
                        /* translators: %s: the design's own reference for an image */
                        __('Image "%s" still points at the export URL. Run attach_asset so the page serves it from this site.', 'nibwp'),
                        $ref
                    );
                }
            }
        }
        foreach ($warnings as $warning) {
            if (is_string($warning) && $warning !== '') {
                $this->warnings[] = $warning;
            }
        }
    }

    /**
     * Build a full NDO document from a canonical Paper tree.
     *
     * @param array<string, mixed> $tree   a canonical node from NIBWP_Paper_Ingest
     * @param array<string, mixed> $tokens the design's token map, possibly empty
     * @return array<string, mixed>
     */
    public function document(array $tree, array $tokens = [], string $title = ''): array
    {
        $root = $this->node($tree);

        if ($tokens === [] && class_exists('NIBWP_Paper_Tokens')) {
            // Paper did not hand over a token set, so read one out of what the
            // design actually paints — the same bargain the Figma side makes.
            $tokens = (new NIBWP_Paper_Tokens())->extract($tree);
        }
        if (isset($tokens['colors']) || isset($tokens['text'])) {
            $tokens['css'] = NIBWP_Paper_Tokens::css(
                (array) ($tokens['colors'] ?? []),
                (array) ($tokens['text'] ?? [])
            );
        }

        $title = $title !== '' ? $title : (string) ($tree['name'] ?? 'Paper design');

        return [
            'ndo_version' => '1.0',
            'target' => ['type' => 'page', 'title' => $title],
            'tokens' => $tokens,
            'root' => $root,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private function node(array $n): array
    {
        $ptype = (string) ($n['ptype'] ?? '');
        $css = (array) ($n['css'] ?? []);
        $text = (string) ($n['text'] ?? '');

        [$layout, $style] = $this->split_css($css);

        $out = [
            // Paper's own node type. The key keeps the name the format was born
            // with — the emitters read `type`, never this, and renaming it would
            // break the Figma side of the same pipeline for nothing.
            'figma_type' => $ptype,
            'name' => (string) ($n['name'] ?? ''),
            'type' => $this->map_type($ptype, $text !== ''),
            'style' => $style,
            'layout' => $layout,
            'children' => [],
        ];

        if (isset($n['w']) || isset($n['h'])) {
            $out['bbox'] = [
                'w' => (float) ($n['w'] ?? 0),
                'h' => (float) ($n['h'] ?? 0),
            ];
        }

        if ($out['type'] === 'text') {
            $out['text'] = $text;
            $out['tag'] = $this->tag_for($n, $style);
            if (isset($style['font-family'])) {
                $out['font_family'] = (string) $style['font-family'];
            }
        }

        $this->apply_image($n, $out);

        foreach ((array) ($n['children'] ?? []) as $child) {
            if (is_array($child)) {
                $out['children'][] = $this->node($child);
            }
        }

        return $out;
    }

    /**
     * Paper node type → the NDO's generic vocabulary.
     *
     * Deliberately limited to the values the Figma normalizer already produces —
     * section, container, component, component_instance, text, shape. An emitter
     * that learns a seventh value would have to learn it for both tools, and an
     * image is a shape with a background image here exactly as it is there.
     */
    private function map_type(string $ptype, bool $has_text): string
    {
        $ptype = strtolower($ptype);

        return match ($ptype) {
            // Paper's own primitives.
            'frame', 'artboard', 'board', 'page', 'canvas', 'screen', 'section',
            'main', 'header', 'footer', 'nav', 'aside', 'article' => 'section',
            'stack', 'group', 'flex', 'grid', 'container', 'box', 'div', 'figure', 'ul', 'ol', 'li', 'tr', 'td', 'table', 'tbody' => 'container',
            'component', 'symbol', 'componentset' => 'component',
            'instance', 'componentinstance', 'use' => 'component_instance',
            'text', 'label', 'paragraph', 'heading', 'string',
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'a', 'strong', 'em',
            'blockquote', 'figcaption', 'small', 'code', 'pre' => 'text',
            'image', 'img', 'picture', 'rect', 'rectangle', 'ellipse', 'oval', 'circle',
            'vector', 'path', 'line', 'star', 'polygon', 'svg', 'icon', 'shape', 'hr' => 'shape',
            default => $has_text ? 'text' : 'container',
        };
    }

    /**
     * Split a CSS map into the NDO's layout and style buckets.
     *
     * @param array<string, string> $css
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function split_css(array $css): array
    {
        $layout = [];
        $style = [];
        foreach ($css as $prop => $value) {
            $prop = (string) $prop;
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            if (in_array($prop, self::LAYOUT_PROPS, true)) {
                $layout[$prop] = $value;
                continue;
            }
            $style[$prop] = $value;
        }

        // A flex child order only means something once the parent is flex, and a
        // gap only means something once display is set — Paper usually says so, but
        // a hand-built tree often does not.
        if (!isset($layout['display'])
            && (isset($layout['flex-direction']) || isset($layout['gap']) || isset($layout['justify-content']) || isset($layout['align-items']))
        ) {
            $layout['display'] = 'flex';
        }

        return [$layout, $style];
    }

    /**
     * The HTML tag a text run should become.
     *
     * Paper's export usually names it, and the name is what the designer chose, so
     * it wins. Where it does not, the type ramp decides from the rendered size —
     * the same heuristic the Figma side uses, so one design converted twice through
     * two tools lands on the same heading levels.
     *
     * @param array<string, mixed>  $n
     * @param array<string, string> $style
     */
    private function tag_for(array $n, array $style): string
    {
        $tag = strtolower((string) ($n['tag'] ?? ''));
        if (preg_match('/^h[1-6]$/', $tag) === 1) {
            return $tag;
        }
        $ptype = strtolower((string) ($n['ptype'] ?? ''));
        if (preg_match('/^h[1-6]$/', $ptype) === 1) {
            return $ptype;
        }

        $size = (float) preg_replace('/[^0-9.]/', '', (string) ($style['font-size'] ?? ''));
        if ($size <= 0) {
            return 'p';
        }

        return match (true) {
            $size >= 40 => 'h1',
            $size >= 30 => 'h2',
            $size >= 24 => 'h3',
            $size >= 20 => 'h4',
            $size >= 17 => 'h5',
            default => 'p',
        };
    }

    /**
     * An image node becomes a box with a background image, which is what the
     * emitters already know how to render.
     *
     * @param array<string, mixed> $n
     * @param array<string, mixed> $out
     */
    private function apply_image(array $n, array &$out): void
    {
        $ref = (string) ($n['image_ref'] ?? '');
        $url = (string) ($n['image'] ?? '');

        if ($ref !== '') {
            $out['image_ref'] = $ref;
            if ($url === '' && isset($this->assets[$ref])) {
                $url = $this->assets[$ref];
            }
        }
        if (isset($n['alt']) && (string) $n['alt'] !== '') {
            $out['alt'] = (string) $n['alt'];
        }

        if ($url === '') {
            if ($ref !== '') {
                $this->warnings[] = sprintf(
                    /* translators: %s: the design's own reference for an image */
                    __('Image "%s" has no URL yet — attach it with attach_asset and re-convert.', 'nibwp'),
                    $ref
                );
            }

            return;
        }

        $out['image_url'] = $url;
        if (!isset($out['style']['background-image'])) {
            $out['style']['background-image'] = 'url(' . $url . ')';
            $out['style']['background-size'] = (string) ($out['style']['object-fit'] ?? '') === 'contain' ? 'contain' : 'cover';
            $out['style']['background-position'] = 'center';
            $out['style']['background-repeat'] = 'no-repeat';
        }
    }
}
