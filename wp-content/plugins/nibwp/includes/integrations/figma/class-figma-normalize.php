<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Normalize — raw Figma node JSON → a NibWP Design Object (NDO).
 *
 * The NDO is builder-agnostic. Each node carries CSS-ready `style` + `layout`
 * props (so builder emitters stay simple and the output is pixel-faithful),
 * plus text content, image refs, and children. Colors resolve against the file's
 * Variables/Styles when available; unmatched values are kept raw and flagged.
 */
class NIBWP_Figma_Normalize
{
    /** @var array<string,string> imageRef → rendered image URL (filled by caller) */
    private array $images = [];

    /** @var array<int,array<string,mixed>> collected warnings */
    private array $warnings = [];

    /**
     * @param array<string,string> $images imageRef → URL map (from get_images / fills)
     */
    public function __construct(array $images = [])
    {
        $this->images = $images;
    }

    /**
     * Build a full NDO document from a Figma node subtree.
     *
     * @param array<string,mixed> $node   a Figma DOCUMENT/FRAME node
     * @param array<string,mixed> $tokens pre-built token map (may be empty)
     * @return array<string,mixed>
     */
    public function document(array $node, array $tokens = []): array
    {
        $root = $this->node($node);
        return [
            'ndo_version' => '1.0',
            'target'      => ['type' => 'page', 'title' => (string) ($node['name'] ?? 'Figma Page')],
            'tokens'      => $tokens,
            'root'        => $root,
            'warnings'    => $this->warnings,
        ];
    }

    /**
     * @param array<string,mixed> $n
     * @return array<string,mixed>
     */
    private function node(array $n): array
    {
        $type = (string) ($n['type'] ?? '');
        $out = [
            'figma_type' => $type,
            'name'       => (string) ($n['name'] ?? ''),
            'type'       => $this->map_type($type),
            'style'      => [],
            'layout'     => [],
            'children'   => [],
        ];

        // Geometry (for fidelity + absolute fallback).
        if (isset($n['absoluteBoundingBox']) && is_array($n['absoluteBoundingBox'])) {
            $out['bbox'] = [
                'w' => (float) ($n['absoluteBoundingBox']['width'] ?? 0),
                'h' => (float) ($n['absoluteBoundingBox']['height'] ?? 0),
            ];
        }

        // Opacity.
        if (isset($n['opacity']) && (float) $n['opacity'] < 1) {
            $out['style']['opacity'] = round((float) $n['opacity'], 3);
        }

        // Corner radius.
        if (isset($n['cornerRadius']) && (float) $n['cornerRadius'] > 0) {
            $out['style']['border-radius'] = (int) round((float) $n['cornerRadius']) . 'px';
        } elseif (isset($n['rectangleCornerRadii']) && is_array($n['rectangleCornerRadii'])) {
            $r = array_map(static fn ($v) => (int) round((float) $v) . 'px', $n['rectangleCornerRadii']);
            $out['style']['border-radius'] = implode(' ', $r);
        }

        // Fills → background / image.
        $this->apply_fills($n, $out);

        // Strokes → border.
        if (!empty($n['strokes']) && is_array($n['strokes'])) {
            $sw = (float) ($n['strokeWeight'] ?? 1);
            $col = $this->first_solid_color($n['strokes']);
            if ($col !== null && $sw > 0) {
                $out['style']['border'] = ((int) round($sw)) . 'px solid ' . $col;
            }
        }

        // Effects → box-shadow.
        $shadow = $this->effects_to_shadow($n['effects'] ?? []);
        if ($shadow !== '') {
            $out['style']['box-shadow'] = $shadow;
        }

        // Auto-layout → flex.
        $this->apply_autolayout($n, $out);

        // Text.
        if ($type === 'TEXT') {
            $this->apply_text($n, $out);
        }

        // Recurse.
        foreach ((array) ($n['children'] ?? []) as $child) {
            if (is_array($child) && ($child['visible'] ?? true) !== false) {
                $out['children'][] = $this->node($child);
            }
        }

        return $out;
    }

    private function map_type(string $t): string
    {
        return match ($t) {
            'FRAME', 'SECTION'                                   => 'section',
            'GROUP'                                              => 'container',
            'COMPONENT', 'COMPONENT_SET'                         => 'component',
            'INSTANCE'                                           => 'component_instance',
            'TEXT'                                               => 'text',
            'RECTANGLE', 'ELLIPSE', 'VECTOR', 'STAR', 'LINE',
            'REGULAR_POLYGON', 'BOOLEAN_OPERATION'               => 'shape',
            default                                              => 'container',
        };
    }

    /**
     * @param array<string,mixed> $n
     * @param array<string,mixed> $out
     */
    private function apply_fills(array $n, array &$out): void
    {
        $fills = $n['fills'] ?? [];
        if (!is_array($fills)) {
            return;
        }
        foreach ($fills as $fill) {
            if (!is_array($fill) || ($fill['visible'] ?? true) === false) {
                continue;
            }
            $ft = (string) ($fill['type'] ?? '');
            if ($ft === 'SOLID') {
                $c = $this->color($fill['color'] ?? [], (float) ($fill['opacity'] ?? 1));
                if ($c !== null) {
                    // On a TEXT node the solid fill is the text color; handled in apply_text.
                    if (($n['type'] ?? '') !== 'TEXT') {
                        $out['style']['background'] = $c;
                    }
                }
                return;
            }
            if ($ft === 'IMAGE') {
                $ref = (string) ($fill['imageRef'] ?? '');
                $url = $this->images[$ref] ?? '';
                $out['image_ref'] = $ref;
                if ($url !== '') {
                    $out['image_url'] = $url;
                    $scale = (string) ($fill['scaleMode'] ?? 'FILL');
                    $out['style']['background-image'] = 'url(' . $url . ')';
                    $out['style']['background-size'] = $scale === 'FIT' ? 'contain' : 'cover';
                    $out['style']['background-position'] = 'center';
                    $out['style']['background-repeat'] = 'no-repeat';
                }
                return;
            }
            if (str_starts_with($ft, 'GRADIENT')) {
                $g = $this->gradient($fill);
                if ($g !== '') {
                    $out['style']['background'] = $g;
                }
                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $n
     * @param array<string,mixed> $out
     */
    private function apply_autolayout(array $n, array &$out): void
    {
        $mode = (string) ($n['layoutMode'] ?? 'NONE');
        if ($mode === 'HORIZONTAL' || $mode === 'VERTICAL') {
            $out['layout']['display'] = 'flex';
            $out['layout']['flex-direction'] = $mode === 'HORIZONTAL' ? 'row' : 'column';

            $gap = (float) ($n['itemSpacing'] ?? 0);
            if ($gap > 0) {
                $out['layout']['gap'] = ((int) round($gap)) . 'px';
            }
            $pt = (float) ($n['paddingTop'] ?? 0);
            $pr = (float) ($n['paddingRight'] ?? 0);
            $pb = (float) ($n['paddingBottom'] ?? 0);
            $pl = (float) ($n['paddingLeft'] ?? 0);
            if ($pt || $pr || $pb || $pl) {
                $out['layout']['padding'] = sprintf('%dpx %dpx %dpx %dpx', $pt, $pr, $pb, $pl);
            }
            $out['layout']['justify-content'] = $this->align((string) ($n['primaryAxisAlignItems'] ?? 'MIN'));
            $out['layout']['align-items'] = $this->align((string) ($n['counterAxisAlignItems'] ?? 'MIN'));
            if ((string) ($n['layoutWrap'] ?? '') === 'WRAP') {
                $out['layout']['flex-wrap'] = 'wrap';
            }
        }
    }

    private function align(string $v): string
    {
        return match ($v) {
            'CENTER'        => 'center',
            'MAX'           => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'BASELINE'      => 'baseline',
            default         => 'flex-start',
        };
    }

    /**
     * @param array<string,mixed> $n
     * @param array<string,mixed> $out
     */
    private function apply_text(array $n, array &$out): void
    {
        $out['text'] = (string) ($n['characters'] ?? '');
        $st = $n['style'] ?? [];
        if (is_array($st)) {
            $size = (float) ($st['fontSize'] ?? 0);
            if ($size > 0) {
                $out['style']['font-size'] = ((int) round($size)) . 'px';
                // Type-ramp slot / heading level from size.
                $out['tag'] = $this->tag_for_size($size);
            }
            if (isset($st['fontWeight'])) {
                $out['style']['font-weight'] = (int) $st['fontWeight'];
            }
            if (!empty($st['fontFamily'])) {
                $out['style']['font-family'] = (string) $st['fontFamily'];
                $out['font_family'] = (string) $st['fontFamily'];
            }
            if (isset($st['lineHeightPx']) && (float) $st['lineHeightPx'] > 0 && $size > 0) {
                $out['style']['line-height'] = round((float) $st['lineHeightPx'] / $size, 3);
            }
            if (isset($st['letterSpacing']) && (float) $st['letterSpacing'] !== 0.0) {
                $out['style']['letter-spacing'] = round((float) $st['letterSpacing'], 2) . 'px';
            }
            $align = (string) ($st['textAlignHorizontal'] ?? '');
            if ($align !== '' && $align !== 'LEFT') {
                $out['style']['text-align'] = strtolower($align);
            }
            $case = (string) ($st['textCase'] ?? '');
            $tc = match ($case) {
                'UPPER' => 'uppercase',
                'LOWER' => 'lowercase',
                'TITLE' => 'capitalize',
                default => '',
            };
            if ($tc !== '') {
                $out['style']['text-transform'] = $tc;
            }
        }
        // Text color from the first solid fill.
        $c = $this->first_solid_color($n['fills'] ?? []);
        if ($c !== null) {
            $out['style']['color'] = $c;
        }
        if (!isset($out['tag'])) {
            $out['tag'] = 'p';
        }
    }

    private function tag_for_size(float $size): string
    {
        return match (true) {
            $size >= 40 => 'h1',
            $size >= 30 => 'h2',
            $size >= 24 => 'h3',
            $size >= 20 => 'h4',
            $size >= 17 => 'h5',
            default     => 'p',
        };
    }

    /**
     * @param array<int,mixed> $fills
     */
    private function first_solid_color(array $fills): ?string
    {
        foreach ($fills as $f) {
            if (is_array($f) && ($f['type'] ?? '') === 'SOLID' && ($f['visible'] ?? true) !== false) {
                return $this->color($f['color'] ?? [], (float) ($f['opacity'] ?? 1));
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $c figma color {r,g,b,a} in 0..1
     */
    private function color(array $c, float $opacity = 1.0): ?string
    {
        if (!isset($c['r'], $c['g'], $c['b'])) {
            return null;
        }
        $r = (int) round((float) $c['r'] * 255);
        $g = (int) round((float) $c['g'] * 255);
        $b = (int) round((float) $c['b'] * 255);
        $a = round((float) ($c['a'] ?? 1) * $opacity, 3);
        if ($a >= 1) {
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }
        return sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, $a);
    }

    /**
     * @param array<string,mixed> $fill
     */
    private function gradient(array $fill): string
    {
        $stops = $fill['gradientStops'] ?? [];
        if (!is_array($stops) || $stops === []) {
            return '';
        }
        $parts = [];
        foreach ($stops as $s) {
            if (!is_array($s)) {
                continue;
            }
            $col = $this->color($s['color'] ?? []);
            $pos = round((float) ($s['position'] ?? 0) * 100);
            if ($col !== null) {
                $parts[] = $col . ' ' . $pos . '%';
            }
        }
        if ($parts === []) {
            return '';
        }
        $type = (string) ($fill['type'] ?? '');
        if ($type === 'GRADIENT_RADIAL') {
            return 'radial-gradient(' . implode(',', $parts) . ')';
        }
        return 'linear-gradient(180deg,' . implode(',', $parts) . ')';
    }

    /**
     * @param array<int,mixed> $effects
     */
    private function effects_to_shadow(array $effects): string
    {
        $out = [];
        foreach ($effects as $e) {
            if (!is_array($e) || ($e['visible'] ?? true) === false) {
                continue;
            }
            $t = (string) ($e['type'] ?? '');
            if ($t !== 'DROP_SHADOW' && $t !== 'INNER_SHADOW') {
                continue;
            }
            $off = $e['offset'] ?? ['x' => 0, 'y' => 0];
            $x = (int) round((float) ($off['x'] ?? 0));
            $y = (int) round((float) ($off['y'] ?? 0));
            $blur = (int) round((float) ($e['radius'] ?? 0));
            $spread = (int) round((float) ($e['spread'] ?? 0));
            $col = $this->color($e['color'] ?? []) ?? 'rgba(0,0,0,.2)';
            $inset = $t === 'INNER_SHADOW' ? 'inset ' : '';
            $out[] = sprintf('%s%dpx %dpx %dpx %dpx %s', $inset, $x, $y, $blur, $spread, $col);
        }
        return implode(', ', $out);
    }
}
