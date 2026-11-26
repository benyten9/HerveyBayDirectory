<?php

declare(strict_types=1);

/**
 * Mosaic node trees: the authoring shorthand, the stored shape, and the
 * difference between what was sent and what Mosaic kept.
 *
 * Mosaic's own nested format is {type, data, children}; its inserter
 * (NodeMResourceInserterHelper::convertNodeDataTreeToRevisionEnvelopeInserterData)
 * flattens that into rows. Everything here works on that nested format, plus a
 * few shorthands an agent can write without knowing Mosaic's storage:
 *
 *   tag      → data.tagName
 *   text     → a wysiwyg-text child (on text, button, list-item, wysiwyg-link)
 *   style    → {base|tablet|mobile|hover|"hover@mobile": {css props}} → data.style.states
 *   css      → data.style.states['&']['_'].customDeclarations
 *   classes  → universal classes by NAME, resolved to ids by the writer
 *   cssClasses (strings) → [{className, uuid}]
 *
 * Pure PHP: no WordPress, no Mosaic. The Pro skill and its standalone check
 * load this file directly.
 */

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('nibwp_mosaic_normalize_tree')) {

    /** array_is_list() for PHP 8.0. */
    function nibwp_mosaic_is_list(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * One node or a list of nodes → a list of nodes.
     *
     * @param mixed $tree
     */
    function nibwp_mosaic_roots($tree): array
    {
        if (!is_array($tree) || $tree === []) {
            return [];
        }

        return nibwp_mosaic_is_list($tree) ? array_values(array_filter($tree, 'is_array')) : [$tree];
    }

    /** RFC 4122 v4, the shape Mosaic's ValidatorUUID accepts. */
    function nibwp_mosaic_uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Shorthand node (array) → Mosaic nested node (array). Notes collect what was
     * moved where, so nothing the caller wrote disappears without a word.
     *
     * @param array<string,mixed> $node
     * @param array<int,array<string,string>> $notes
     * @return array<string,mixed>
     */
    function nibwp_mosaic_normalize_tree(array $node, array &$notes = [], string $path = ''): array
    {
        $type = (string) ($node['type'] ?? '');
        $path = $path === '' ? $type : $path;
        $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : [];

        if (isset($node['name']) && !isset($data['name'])) {
            $data['name'] = (string) $node['name'];
        }
        if (isset($node['tag']) && !isset($data['tagName'])) {
            $data['tagName'] = strtolower((string) $node['tag']);
        }
        foreach (['url', 'target', 'alt', 'image', 'svg'] as $key) {
            if (isset($node[$key]) && !isset($data[$key])) {
                $data[$key] = $node[$key];
            }
        }

        $style = isset($data['style']) && is_array($data['style']) ? $data['style'] : [];
        if (isset($node['style']) && is_array($node['style'])) {
            $style = nibwp_mosaic_merge($style, nibwp_mosaic_style_from_shorthand($node['style'], $notes, $path));
        }
        if (isset($node['css']) && is_string($node['css']) && trim($node['css']) !== '') {
            $existing = (string) ($style['states']['&']['_']['customDeclarations'] ?? '');
            $style['states']['&']['_']['customDeclarations'] = trim($existing . "\n" . nibwp_mosaic_clean_declarations($node['css']));
        }
        if (isset($node['classes']) && is_array($node['classes'])) {
            $style['_classNames'] = array_values(array_map('strval', $node['classes']));
        }
        if ($style !== []) {
            $data['style'] = $style;
        }

        if (isset($data['cssClasses']) && is_array($data['cssClasses'])) {
            $data['cssClasses'] = nibwp_mosaic_css_classes($data['cssClasses']);
        } elseif (isset($node['cssClasses']) && is_array($node['cssClasses'])) {
            $data['cssClasses'] = nibwp_mosaic_css_classes($node['cssClasses']);
        }

        $children = [];
        if (isset($node['text']) && is_string($node['text'])) {
            $children = nibwp_mosaic_text_children($type, $node['text']);
        }
        foreach ((array) ($node['children'] ?? []) as $i => $child) {
            if (is_array($child)) {
                $children[] = nibwp_mosaic_normalize_tree($child, $notes, $path . ' > ' . (string) ($child['type'] ?? '?') . '[' . $i . ']');
            }
        }

        $out = ['type' => $type, 'data' => $data];
        if ($children !== []) {
            $out['children'] = $children;
        }

        return $out;
    }

    /** A plain label becomes the children Mosaic expects for that element. */
    function nibwp_mosaic_text_children(string $type, string $text): array
    {
        $run = ['type' => 'wysiwyg-text', 'data' => ['text' => $text]];
        if ($type === 'text' || $type === 'wysiwyg-link' || $type === 'wysiwyg-span') {
            return [$run];
        }

        // button / list-item / anything with a label: a text element holding the run.
        return [['type' => 'text', 'data' => [], 'children' => [$run]]];
    }

    /** ["btn", "btn--lg"] or [{className}] → Mosaic's [{className, uuid}] records. */
    function nibwp_mosaic_css_classes(array $classes): array
    {
        $out = [];
        foreach ($classes as $class) {
            $name = is_array($class) ? (string) ($class['className'] ?? '') : (string) $class;
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'className' => $name,
                'uuid'      => is_array($class) && !empty($class['uuid']) ? (string) $class['uuid'] : nibwp_mosaic_uuid(),
            ];
        }

        return $out;
    }

    /**
     * {base:{padding:"24px"}, mobile:{...}, hover:{...}, "hover@mobile":{...}} → {states:{...}}.
     *
     * @param array<string,mixed> $shorthand
     */
    function nibwp_mosaic_style_from_shorthand(array $shorthand, array &$notes = [], string $path = ''): array
    {
        $bp_alias = ['base' => '_', 'desktop' => '_', '_' => '_', 'tablet' => '_t', '_t' => '_t', 'mobile' => '_m', '_m' => '_m'];
        $states = array_keys(nibwp_mosaic_catalog()['states']);

        // A flat map of CSS properties means "base, default state".
        $flat = true;
        foreach (array_keys($shorthand) as $key) {
            $head = explode('@', (string) $key)[0];
            if (isset($bp_alias[$key]) || in_array($head, $states, true) || $head === 'default') {
                $flat = false;
                break;
            }
        }
        if ($flat) {
            $shorthand = ['base' => $shorthand];
        }

        $out = [];
        foreach ($shorthand as $key => $props) {
            if (!is_array($props)) {
                continue;
            }
            $parts = explode('@', (string) $key, 2);
            if (isset($bp_alias[$parts[0]])) {
                $state = '&';
                $bp = $bp_alias[$parts[0]];
            } else {
                $state = $parts[0] === 'default' ? '&' : $parts[0];
                $bp = $bp_alias[$parts[1] ?? 'base'] ?? (string) ($parts[1] ?? '_');
            }
            if (!in_array($state, $states, true)) {
                $notes[] = ['path' => $path, 'message' => sprintf('Unknown style state "%s" skipped. States: %s.', $state, implode(', ', $states))];
                continue;
            }
            $block = nibwp_mosaic_css_block($props, $notes, $path);
            if ($block !== []) {
                $out['states'][$state][$bp] = nibwp_mosaic_merge($out['states'][$state][$bp] ?? [], $block);
            }
        }

        return $out;
    }

    /**
     * CSS properties (kebab or camel) → one Mosaic breakpoint block. Shorthands
     * Mosaic stores as separate keys are expanded; properties it only stores in
     * its own structured shape go to customDeclarations as CSS, which Mosaic
     * validates and keeps verbatim.
     *
     * @param array<string,mixed> $props
     * @return array<string,string>
     */
    function nibwp_mosaic_css_block(array $props, array &$notes = [], string $path = ''): array
    {
        $out = [];
        $free = [];
        $structured = nibwp_mosaic_catalog()['style']['structured'];

        foreach ($props as $prop => $value) {
            $prop = (string) $prop;
            if ($prop === 'customDeclarations' || $prop === 'css') {
                $free[] = nibwp_mosaic_clean_declarations((string) $value);
                continue;
            }
            if (is_array($value) || is_object($value)) {
                // Already Mosaic-shaped (e.g. a borderRadius group): pass through untouched.
                $out[$prop] = $value;
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $kebab = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $prop));

            $sides = nibwp_mosaic_box_sides($kebab, $value);
            if ($sides !== null) {
                $out = array_merge($out, $sides);
                continue;
            }
            if ($kebab === 'gap') {
                $parts = preg_split('/\s+/', $value) ?: [$value];
                $out['rowGap'] = $parts[0];
                $out['columnGap'] = $parts[1] ?? $parts[0];
                continue;
            }
            if ($kebab === 'background' && preg_match('/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|[a-z]+|var\([^)]*\))$/i', $value) === 1) {
                $out['backgroundColor'] = $value;
                continue;
            }
            if ($kebab === 'font-weight' && preg_match('/^(normal|bold)$/i', $value) === 1) {
                $out['fontWeight'] = strtolower($value) === 'bold' ? '700' : '400';
                continue;
            }

            $key = nibwp_mosaic_css_to_key($kebab);
            if ($key !== '' && $key !== 'gap' && !in_array($key, $structured, true)) {
                $out[$key] = $value;
                continue;
            }

            $free[] = $kebab . ': ' . $value . ';';
        }

        if ($free !== []) {
            $out['customDeclarations'] = trim(implode("\n", array_filter($free)));
        }

        return $out;
    }

    /** padding / margin shorthand (1-4 values) → the four longhands Mosaic stores. */
    function nibwp_mosaic_box_sides(string $kebab, string $value): ?array
    {
        if ($kebab !== 'padding' && $kebab !== 'margin') {
            return null;
        }
        $v = preg_split('/\s+(?![^(]*\))/', trim($value)) ?: [$value];
        $top = $v[0];
        $right = $v[1] ?? $top;
        $bottom = $v[2] ?? $top;
        $left = $v[3] ?? $right;

        return [
            $kebab . 'Top'    => $top,
            $kebab . 'Right'  => $right,
            $kebab . 'Bottom' => $bottom,
            $kebab . 'Left'   => $left,
        ];
    }

    /** "a:b;c:d" → one declaration per line, each ending in ";". */
    function nibwp_mosaic_clean_declarations(string $css): string
    {
        $lines = [];
        foreach (preg_split('/;(?![^(]*\))/', $css) ?: [] as $decl) {
            $decl = trim($decl);
            if ($decl === '' || strpos($decl, ':') === false) {
                continue;
            }
            [$p, $v] = array_map('trim', explode(':', $decl, 2));
            if ($p !== '' && $v !== '') {
                $lines[] = strtolower($p) . ': ' . $v . ';';
            }
        }

        return implode("\n", $lines);
    }

    /** Recursive merge where the right side wins on scalars. */
    function nibwp_mosaic_merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k]) && !nibwp_mosaic_is_list($v)) {
                $a[$k] = nibwp_mosaic_merge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }

    /**
     * What Mosaic did not keep. $given is what was sent, $stored what Mosaic
     * exported after its validators ran. Mosaic drops an invalid value (or keeps
     * the previous one) without raising anything, so this is the only report.
     *
     * @return array<int,array{path:string,given:mixed,stored:mixed}>
     */
    function nibwp_mosaic_diff($given, $stored, string $path = ''): array
    {
        $out = [];
        $given = json_decode((string) json_encode($given), true);
        $stored = json_decode((string) json_encode($stored), true);
        // Nothing was asked for: an empty value or a preset's "off" is not a loss.
        if ($given === null || $given === false || $given === '' || $given === []) {
            return [];
        }

        if (is_array($given) && !nibwp_mosaic_is_list($given)) {
            foreach ($given as $k => $v) {
                if ($path === '' && in_array($k, ['hierarchy', 'currentStatus'], true)) {
                    continue;
                }
                $sub = $path === '' ? (string) $k : $path . '.' . $k;
                if (!is_array($stored) || !array_key_exists($k, $stored)) {
                    if ($v !== null && $v !== [] && $v !== '' && $v !== false) {
                        $out[] = ['path' => $sub, 'given' => $v, 'stored' => null];
                    }
                    continue;
                }
                $out = array_merge($out, nibwp_mosaic_diff($v, $stored[$k], $sub));
            }

            return $out;
        }

        if (is_array($given)) {
            if (json_encode($given) !== json_encode($stored)) {
                $out[] = ['path' => $path, 'given' => $given, 'stored' => $stored];
            }

            return $out;
        }

        // Mosaic re-spaces what it keeps (rgba(0,0,0,.4) → rgba(0, 0, 0, .4)); that is not a change.
        $squash = static fn($v): string => is_scalar($v) ? (string) preg_replace(['/\s+/', '/^0(px|rem|em|%)$/'], ['', '0'], (string) $v) : (string) json_encode($v);
        if ($squash($given) !== $squash($stored)) {
            $out[] = ['path' => $path, 'given' => $given, 'stored' => $stored];
        }

        return $out;
    }

    /**
     * Flat mosaic_nodes rows → nested trees, each child list in Mosaic order.
     *
     * @param array<int,object|array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    function nibwp_mosaic_rows_to_tree(array $rows): array
    {
        $byId = [];
        $kids = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            // Override rows are a component instance's per-instance copies, not page content.
            if (($row['status'] ?? 'publish') !== 'publish' || ($row['parentType'] ?? '') === 'override') {
                continue;
            }
            $node = [
                'id'       => (string) $row['ID'],
                'type'     => (string) $row['type'],
                'data'     => is_string($row['data'] ?? null) ? (json_decode($row['data'], true) ?: []) : (array) ($row['data'] ?? []),
                'ordering' => (string) ($row['ordering'] ?? ''),
                'parent'   => (string) ($row['parentID'] ?? ''),
                'ptype'    => (string) ($row['parentType'] ?? ''),
            ];
            $byId[$node['id']] = $node;
            $kids[$node['parent']][] = $node['id'];
        }

        $build = function (string $id) use (&$build, &$byId, &$kids): array {
            $n = $byId[$id];
            $out = ['id' => $n['id'], 'type' => $n['type'], 'data' => $n['data']];
            $childIds = $kids[$id] ?? [];
            usort($childIds, static fn($a, $b) => strcmp($byId[$a]['ordering'], $byId[$b]['ordering']));
            $children = [];
            foreach ($childIds as $cid) {
                $children[] = $build($cid);
            }
            if ($children !== []) {
                $out['children'] = $children;
            }

            return $out;
        };

        $roots = [];
        foreach ($byId as $id => $n) {
            if ($n['ptype'] !== 'node' || !isset($byId[$n['parent']])) {
                $roots[] = $id;
            }
        }
        usort($roots, static fn($a, $b) => strcmp($byId[$a]['ordering'], $byId[$b]['ordering']));

        return array_map($build, $roots);
    }

    /** The words a node shows, gathered from its wysiwyg runs. */
    function nibwp_mosaic_node_text(array $node): string
    {
        if (($node['type'] ?? '') === 'wysiwyg-text') {
            return (string) ($node['data']['text'] ?? '');
        }
        $parts = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $parts[] = nibwp_mosaic_node_text($child);
            }
        }

        // Runs carry their own spaces; blocks do not.
        $glue = in_array($node['type'] ?? '', ['text', 'wysiwyg-link', 'wysiwyg-span'], true) ? '' : ' ';

        return trim((string) preg_replace('/\s+/u', ' ', implode($glue, array_filter($parts, 'strlen'))));
    }

    /**
     * One line per element, indented: "section#id "Hero"", "text<h1> "Welcome"".
     * Inline runs fold into their text element so the outline reads like the page.
     *
     * @param array<int,array<string,mixed>> $trees
     * @return string[]
     */
    function nibwp_mosaic_outline(array $trees, int $depth = 0, int $limit = 400): array
    {
        $lines = [];
        foreach ($trees as $node) {
            if (count($lines) >= $limit) {
                $lines[] = str_repeat('  ', $depth) . '…';
                break;
            }
            $type = (string) ($node['type'] ?? '?');
            if (str_starts_with($type, 'wysiwyg-')) {
                continue;
            }
            $data = (array) ($node['data'] ?? []);
            $label = $type;
            if (!empty($data['tagName'])) {
                $label .= '<' . $data['tagName'] . '>';
            }
            if (!empty($node['id'])) {
                $label .= '#' . $node['id'];
            }
            if (!empty($data['name'])) {
                $label .= ' [' . $data['name'] . ']';
            }
            $text = $type === 'text' || $type === 'button' ? nibwp_mosaic_node_text($node) : '';
            if ($text !== '') {
                $label .= ' "' . (strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text) . '"';
            }
            $lines[] = str_repeat('  ', $depth) . $label;
            if ($type !== 'text') {
                $lines = array_merge($lines, nibwp_mosaic_outline((array) ($node['children'] ?? []), $depth + 1, $limit - count($lines)));
            }
        }

        return $lines;
    }
}
