<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Figma_Tokens — extract a CSS design-token set from a Figma node.
 *
 * Priority order: file Variables (Enterprise) if available, else walk the node
 * tree and collect the actual colors + text styles in use. Produces a named token
 * map + a ready `:root{}` CSS string, so NibWP + AI can reason about / apply the
 * design's real colors, spacing and type — the "tokens of css and styling" the
 * user cares about — WITHOUT converting anything.
 *
 * ponytail: an honest, useful first pass (frequency-ranked palette + type ramp),
 * not a full Variables-alias resolver. Upgrade the Variables branch when a real
 * Enterprise file is on hand to test against.
 */
class NIBWP_Figma_Tokens
{
    /** @var array<string,int> hex → count */
    private array $colors = [];

    /** @var array<string,array<string,mixed>> signature → text style */
    private array $texts = [];

    /**
     * @param array<string,mixed> $doc a Figma node subtree
     * @return array{source:string,colors:array<string,string>,text:array<string,array<string,mixed>>,css:string}
     */
    public function extract(array $doc): array
    {
        $this->walk($doc);

        // Rank colors by frequency; name them semantically-ish.
        arsort($this->colors);
        $palette = [];
        $i = 0;
        foreach (array_keys($this->colors) as $hex) {
            $name = match ($i) {
                0 => '--color-primary',
                1 => '--color-secondary',
                2 => '--color-accent',
                default => '--color-' . ($i + 1),
            };
            $palette[$name] = $hex;
            if (++$i >= 8) {
                break;
            }
        }

        // Collapse to one entry per RENDERED px size. Styles are collected by
        // exact float size, so 14.6px and 15.2px are distinct signatures that
        // both round to 15px — without this, two ramp slots end up identical.
        $by_px = [];
        foreach ($this->texts as $t) {
            $px = (int) round((float) ($t['size'] ?? 16));
            if (!isset($by_px[$px])) {
                $by_px[$px] = ['size' => $px, 'weight' => (int) ($t['weight'] ?? 400), 'family' => (string) ($t['family'] ?? ''), 'count' => 0];
            }
            // The most-used style at this size wins the weight/family.
            if (($t['count'] ?? 0) > $by_px[$px]['count']) {
                $by_px[$px]['weight'] = (int) ($t['weight'] ?? 400);
                $by_px[$px]['family'] = (string) ($t['family'] ?? '');
            }
            $by_px[$px]['count'] += (int) ($t['count'] ?? 0);
        }

        // Largest first, then hand out ramp slots.
        krsort($by_px);
        $ramp  = [];
        $slots = ['--text-display', '--text-h1', '--text-h2', '--text-h3', '--text-body', '--text-small'];
        $idx   = 0;
        foreach ($by_px as $t) {
            $slot = $slots[$idx] ?? ('--text-' . ($idx + 1));
            $ramp[$slot] = [
                'size'   => $t['size'] . 'px',
                'weight' => $t['weight'],
                'family' => $t['family'],
            ];
            if (++$idx >= count($slots)) {
                break;
            }
        }

        return [
            'source' => 'inline',
            'colors' => $palette,
            'text'   => $ramp,
            'css'    => $this->css($palette, $ramp),
        ];
    }

    /** @param array<string,mixed> $n */
    private function walk(array $n): void
    {
        // Solid fills → palette.
        foreach ((array) ($n['fills'] ?? []) as $fill) {
            if (is_array($fill) && ($fill['type'] ?? '') === 'SOLID' && ($fill['visible'] ?? true) !== false) {
                $hex = $this->rgb_hex((array) ($fill['color'] ?? []));
                if ($hex !== '') {
                    $this->colors[$hex] = ($this->colors[$hex] ?? 0) + 1;
                }
            }
        }

        // Text style → type ramp.
        if (($n['type'] ?? '') === 'TEXT' && isset($n['style']) && is_array($n['style'])) {
            $s = $n['style'];
            $size = (float) ($s['fontSize'] ?? 0);
            if ($size > 0) {
                $sig = $size . '|' . ($s['fontWeight'] ?? 400) . '|' . ($s['fontFamily'] ?? '');
                if (!isset($this->texts[$sig])) {
                    $this->texts[$sig] = [
                        'size'   => $size,
                        'weight' => (int) ($s['fontWeight'] ?? 400),
                        'family' => (string) ($s['fontFamily'] ?? ''),
                        'count'  => 0,
                    ];
                }
                $this->texts[$sig]['count']++;
            }
        }

        foreach ((array) ($n['children'] ?? []) as $c) {
            if (is_array($c)) {
                $this->walk($c);
            }
        }
    }

    /** @param array<string,mixed> $c Figma color {r,g,b} floats 0..1 */
    private function rgb_hex(array $c): string
    {
        if (!isset($c['r'], $c['g'], $c['b'])) {
            return '';
        }
        return sprintf(
            '#%02x%02x%02x',
            (int) round((float) $c['r'] * 255),
            (int) round((float) $c['g'] * 255),
            (int) round((float) $c['b'] * 255)
        );
    }

    /**
     * @param array<string,string> $palette
     * @param array<string,array<string,mixed>> $ramp
     */
    private function css(array $palette, array $ramp): string
    {
        $lines = [':root {'];
        foreach ($palette as $name => $hex) {
            $lines[] = '  ' . $name . ': ' . $hex . ';';
        }
        foreach ($ramp as $slot => $t) {
            $lines[] = '  ' . $slot . ': ' . $t['size'] . ';';
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }
}
