<?php

declare(strict_types=1);

/**
 * Mosaic Pro — HTML into a Mosaic tree.
 *
 * Structure maps to Mosaic elements (section, div, text, button, image, list,
 * icon, video embeds); inline formatting maps to Mosaic's wysiwyg runs, which is
 * how Mosaic itself stores bold, italic, links and line breaks inside a text.
 * Inline style attributes become style shorthand. Class rules in a <style> block
 * become Mosaic universal classes of the same name, so the design arrives as a
 * design system rather than as a copy of the same declarations on every element.
 *
 * What cannot be carried (scripts, forms, tables, complex selectors) is reported
 * by name, never dropped silently.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @return array{tree: array<int,array<string,mixed>>, classes: array<int,array<string,mixed>>, warnings: array<int,array<string,string>>}
 */
function nibwp_mosaic_pro_html_to_tree(string $html, bool $keep_classes = false): array
{
    $warnings = [];
    $classes = [];

    if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $html, $m)) {
        $classes = nibwp_mosaic_pro_css_classes(implode("\n", $m[1]), $warnings);
    }
    $class_names = array_column($classes, 'name', 'source');

    if (!class_exists('DOMDocument')) {
        return ['tree' => [], 'classes' => $classes, 'warnings' => [['path' => '', 'message' => 'The PHP DOM extension is missing; HTML cannot be read.']]];
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="nibwp-mosaic-root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('nibwp-mosaic-root');
    if (!$root) {
        return ['tree' => [], 'classes' => $classes, 'warnings' => [['path' => '', 'message' => 'The HTML could not be parsed.']]];
    }

    // A full document: convert what is inside <body>.
    $body = $root->getElementsByTagName('body')->item(0);
    $ctx = ['keep' => $keep_classes, 'classes' => $class_names, 'warnings' => &$warnings, 'depth' => 0];
    $tree = nibwp_mosaic_pro_block_children($body ?: $root, $ctx, 'root');

    return ['tree' => $tree, 'classes' => array_values($classes), 'warnings' => $warnings];
}

/** Tags whose content is phrasing (words), mapped to a Mosaic text. */
function nibwp_mosaic_pro_text_tags(): array
{
    return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'figcaption', 'label', 'address', 'pre', 'small', 'cite', 'dt', 'dd', 'legend', 'caption'];
}

function nibwp_mosaic_pro_inline_tags(): array
{
    return ['span', 'strong', 'b', 'em', 'i', 'u', 'a', 'br', 'code', 'mark', 'sup', 'sub', 'abbr', 'time', 'kbd', 'q', 's', 'del', 'ins'];
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_pro_block_children(DOMNode $parent, array &$ctx, string $path): array
{
    $out = [];
    $run = [];
    $flush = static function () use (&$run, &$out, &$ctx, $path): void {
        if ($run === []) {
            return;
        }
        $inline = [];
        foreach ($run as $n) {
            $inline = array_merge($inline, nibwp_mosaic_pro_inline($n, [], $ctx));
        }
        $inline = nibwp_mosaic_pro_trim_runs($inline);
        if ($inline !== []) {
            $out[] = ['type' => 'text', 'tag' => 'p', 'children' => $inline];
        }
        $run = [];
    };

    foreach ($parent->childNodes as $i => $child) {
        if ($child instanceof DOMText) {
            if (trim($child->textContent) !== '') {
                $run[] = $child;
            }
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($child->tagName);
        if (in_array($tag, ['script', 'style', 'noscript', 'template', 'meta', 'link', 'title', 'head'], true)) {
            continue;
        }
        if (in_array($tag, nibwp_mosaic_pro_inline_tags(), true) && !($tag === 'a' && nibwp_mosaic_pro_is_cta($child))) {
            $run[] = $child;
            continue;
        }
        $flush();
        $node = nibwp_mosaic_pro_block($child, $ctx, $path . ' > ' . $tag . '[' . $i . ']');
        if ($node !== null) {
            $out[] = $node;
        }
    }
    $flush();

    return $out;
}

/** A standalone link styled or named like a button, or the only thing in its row. */
function nibwp_mosaic_pro_is_cta(DOMElement $a): bool
{
    $cls = strtolower($a->getAttribute('class') . ' ' . $a->getAttribute('role'));
    if (preg_match('/\b(btn|button|cta)\b/', $cls) === 1 || str_contains($cls, 'btn-') || str_contains($cls, 'button-')) {
        return true;
    }
    foreach ($a->childNodes as $c) {
        if ($c instanceof DOMElement && !in_array(strtolower($c->tagName), nibwp_mosaic_pro_inline_tags(), true)) {
            return true;
        }
    }
    $parent = $a->parentNode;
    if ($parent instanceof DOMElement && !in_array(strtolower($parent->tagName), nibwp_mosaic_pro_text_tags(), true)) {
        foreach ($parent->childNodes as $sib) {
            if ($sib !== $a && $sib instanceof DOMText && trim($sib->textContent) !== '') {
                return false;
            }
        }

        return true;
    }

    return false;
}

function nibwp_mosaic_pro_block(DOMElement $el, array &$ctx, string $path): ?array
{
    $tag = strtolower($el->tagName);
    $node = null;

    if (in_array($tag, ['section', 'header', 'footer', 'main', 'nav', 'article', 'aside'], true)) {
        $node = ['type' => $ctx['depth'] === 0 ? 'section' : 'div'];
        if (!($tag === 'section' && $node['type'] === 'section')) {
            $node['tag'] = $tag;
        }
    } elseif (in_array($tag, ['div', 'figure', 'hgroup', 'fieldset', 'details', 'summary', 'center'], true)) {
        $node = ['type' => 'div'];
        if ($tag !== 'div' && $tag !== 'center') {
            $node['tag'] = $tag;
        }
    } elseif (in_array($tag, nibwp_mosaic_pro_text_tags(), true)) {
        $node = ['type' => 'text', 'tag' => $tag];
        $node['children'] = nibwp_mosaic_pro_trim_runs(nibwp_mosaic_pro_inline_children($el, [], $ctx));
        if ($node['children'] === []) {
            return null;
        }
    } elseif ($tag === 'a' || $tag === 'button') {
        $node = ['type' => 'button'];
        if ($tag === 'a' && $el->getAttribute('href') !== '') {
            $node['url'] = $el->getAttribute('href');
            if ($el->getAttribute('target') !== '') {
                $node['target'] = $el->getAttribute('target');
            }
        }
        $runs = nibwp_mosaic_pro_trim_runs(nibwp_mosaic_pro_inline_children($el, [], $ctx));
        $node['children'] = $runs !== [] ? [['type' => 'text', 'tag' => 'span', 'children' => $runs]] : [];
        foreach ($el->getElementsByTagName('svg') as $svg) {
            $node['children'][] = ['type' => 'icon', 'svg' => $el->ownerDocument->saveHTML($svg)];
        }
    } elseif ($tag === 'img' || $tag === 'picture') {
        $img = $tag === 'img' ? $el : $el->getElementsByTagName('img')->item(0);
        if (!$img instanceof DOMElement || $img->getAttribute('src') === '') {
            $ctx['warnings'][] = ['path' => $path, 'message' => 'Image without a src skipped.'];

            return null;
        }
        $node = ['type' => 'image', 'image' => $img->getAttribute('src'), 'alt' => $img->getAttribute('alt')];
        foreach (['width', 'height'] as $dim) {
            if (ctype_digit($img->getAttribute($dim))) {
                $node['data'][$dim] = $img->getAttribute($dim);
            }
        }
        if ($img->getAttribute('loading') === 'lazy') {
            $node['data']['loading'] = 'lazy';
        }
    } elseif ($tag === 'svg') {
        $node = ['type' => 'icon', 'svg' => $el->ownerDocument->saveHTML($el)];
    } elseif ($tag === 'ul' || $tag === 'ol') {
        $node = ['type' => 'list', 'children' => []];
        if ($tag === 'ol') {
            $node['tag'] = 'ol';
        }
        foreach ($el->childNodes as $li) {
            if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $item = ['type' => 'list-item'];
            $ctx['depth']++;
            $item['children'] = nibwp_mosaic_pro_block_children($li, $ctx, $path . ' > li');
            $ctx['depth']--;
            nibwp_mosaic_pro_apply_attributes($li, $item, $ctx);
            $node['children'][] = $item;
        }
    } elseif ($tag === 'video') {
        $src = $el->getAttribute('src');
        if ($src === '') {
            $source = $el->getElementsByTagName('source')->item(0);
            $src = $source instanceof DOMElement ? $source->getAttribute('src') : '';
        }
        $node = ['type' => 'video', 'data' => ['video' => $src]];
    } elseif ($tag === 'iframe') {
        $src = $el->getAttribute('src');
        if (preg_match('#youtube(?:-nocookie)?\.com/embed/([\w-]+)|youtu\.be/([\w-]+)#', $src, $m) === 1) {
            $node = ['type' => 'youtube', 'data' => ['youtubeUrl' => 'https://www.youtube.com/watch?v=' . ($m[1] !== '' ? $m[1] : $m[2])]];
        } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $src, $m) === 1) {
            $node = ['type' => 'vimeo', 'data' => ['vimeoUrl' => 'https://vimeo.com/' . $m[1]]];
        } else {
            $ctx['warnings'][] = ['path' => $path, 'message' => 'An iframe (' . $src . ') was skipped. Mosaic embeds YouTube, Vimeo and maps natively; anything else needs the code element.'];

            return null;
        }
    } elseif ($tag === 'hr') {
        $node = ['type' => 'div', 'css' => 'border-top: 1px solid currentColor; opacity: .15;'];
    } elseif (in_array($tag, ['form', 'table', 'select', 'input', 'textarea', 'canvas', 'object', 'embed'], true)) {
        $ctx['warnings'][] = ['path' => $path, 'message' => sprintf('<%s> was not converted. Build it with Mosaic\'s own %s, or ask before using the code element.', $tag, $tag === 'table' ? 'elements (a grid of divs)' : 'form element')];

        return null;
    } else {
        $node = ['type' => 'div'];
    }

    if (in_array($node['type'], ['section', 'div'], true) && !isset($node['children'])) {
        $ctx['depth']++;
        $node['children'] = nibwp_mosaic_pro_block_children($el, $ctx, $path);
        $ctx['depth']--;
    }
    nibwp_mosaic_pro_apply_attributes($el, $node, $ctx);
    if (isset($node['children']) && $node['children'] === []) {
        unset($node['children']);
    }
    // A section gets a layer name: its landmark, or its first heading.
    if ($node['type'] === 'section' && empty($node['name'])) {
        $heading = null;
        foreach (['h1', 'h2', 'h3'] as $h) {
            $heading = $heading ?? $el->getElementsByTagName($h)->item(0);
        }
        $node['name'] = in_array($tag, ['header', 'footer', 'nav'], true)
            ? ucfirst($tag)
            : ($heading ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $heading->textContent)), 0, 40) : 'Section');
    }

    return $node;
}

/** style="" → style shorthand; class="" → Mosaic classes (when defined) or cssClasses; id → attrID. */
function nibwp_mosaic_pro_apply_attributes(DOMElement $el, array &$node, array &$ctx): void
{
    $style = nibwp_mosaic_pro_parse_declarations($el->getAttribute('style'));
    if ($style !== []) {
        $node['style'] = ['base' => $style];
    }
    $mosaic = [];
    $plain = [];
    foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [] as $cls) {
        if ($cls === '') {
            continue;
        }
        if (isset($ctx['classes'][$cls])) {
            $mosaic[] = $ctx['classes'][$cls];
        } elseif ($ctx['keep']) {
            $plain[] = $cls;
        }
    }
    if ($mosaic !== []) {
        $node['classes'] = array_values(array_unique($mosaic));
    }
    if ($plain !== []) {
        $node['cssClasses'] = $plain;
    }
    if ($el->getAttribute('id') !== '') {
        $node['data']['attrID'] = $el->getAttribute('id');
    }
    $label = $el->getAttribute('aria-label');
    if ($label !== '' && empty($node['name'])) {
        $node['name'] = $label;
    }
}

/** @return array<int,array<string,mixed>> */
function nibwp_mosaic_pro_inline_children(DOMNode $parent, array $format, array &$ctx): array
{
    $out = [];
    foreach ($parent->childNodes as $c) {
        $out = array_merge($out, nibwp_mosaic_pro_inline($c, $format, $ctx));
    }

    return $out;
}

/**
 * One inline DOM node → wysiwyg runs. Bold and italic are a run's format, the
 * way Mosaic stores them; links and styled spans are containers.
 *
 * @param string[] $format
 * @return array<int,array<string,mixed>>
 */
function nibwp_mosaic_pro_inline(DOMNode $n, array $format, array &$ctx): array
{
    if ($n instanceof DOMText) {
        $text = (string) preg_replace('/\s+/u', ' ', $n->textContent);
        if ($text === '') {
            return [];
        }
        $run = ['type' => 'wysiwyg-text', 'data' => ['text' => $text]];
        if ($format !== []) {
            $run['data']['format'] = array_values(array_unique($format));
        }

        return [$run];
    }
    if (!$n instanceof DOMElement) {
        return [];
    }
    $tag = strtolower($n->tagName);
    if ($tag === 'br') {
        return [['type' => 'wysiwyg-linebreak']];
    }
    if (in_array($tag, ['strong', 'b'], true)) {
        return nibwp_mosaic_pro_inline_children($n, array_merge($format, ['bold']), $ctx);
    }
    if (in_array($tag, ['em', 'i', 'cite', 'q'], true)) {
        return nibwp_mosaic_pro_inline_children($n, array_merge($format, ['italic']), $ctx);
    }
    if ($tag === 'a') {
        $link = ['type' => 'wysiwyg-link', 'data' => ['url' => $n->getAttribute('href')], 'children' => nibwp_mosaic_pro_inline_children($n, $format, $ctx)];
        if ($n->getAttribute('target') !== '') {
            $link['data']['target'] = $n->getAttribute('target');
        }

        return [$link];
    }
    if (in_array($tag, ['script', 'style', 'svg'], true)) {
        return [];
    }
    $style = nibwp_mosaic_pro_parse_declarations($n->getAttribute('style'));
    $children = nibwp_mosaic_pro_inline_children($n, $format, $ctx);
    $classes = [];
    foreach (preg_split('/\s+/', trim($n->getAttribute('class'))) ?: [] as $cls) {
        if ($cls !== '' && isset($ctx['classes'][$cls])) {
            $classes[] = $ctx['classes'][$cls];
        }
    }
    if ($style === [] && $classes === [] && !in_array($tag, ['mark', 'code', 'kbd'], true)) {
        return $children;
    }
    $span = ['type' => 'wysiwyg-span', 'children' => $children];
    if ($style !== []) {
        $span['style'] = ['base' => $style];
    }
    if ($tag === 'code' || $tag === 'kbd') {
        $span['style']['base']['font-family'] = 'ui-monospace, SFMono-Regular, Menlo, monospace';
    }
    if ($classes !== []) {
        $span['classes'] = $classes;
    }

    return [$span];
}

/** Collapse leading/trailing whitespace of a run list, drop empty runs. */
function nibwp_mosaic_pro_trim_runs(array $runs): array
{
    $runs = array_values($runs);
    $n = count($runs);
    if ($n > 0 && ($runs[0]['type'] ?? '') === 'wysiwyg-text') {
        $runs[0]['data']['text'] = ltrim((string) $runs[0]['data']['text']);
    }
    if ($n > 0 && ($runs[$n - 1]['type'] ?? '') === 'wysiwyg-text') {
        $runs[$n - 1]['data']['text'] = rtrim((string) $runs[$n - 1]['data']['text']);
    }

    return array_values(array_filter($runs, static fn($r) => ($r['type'] ?? '') !== 'wysiwyg-text' || (string) ($r['data']['text'] ?? '') !== ''));
}

/** "a: b; c: d" → ['a' => 'b', 'c' => 'd'] (kebab keys, as written). */
function nibwp_mosaic_pro_parse_declarations(string $css): array
{
    $out = [];
    foreach (preg_split('/;(?![^(]*\))/', $css) ?: [] as $decl) {
        if (strpos($decl, ':') === false) {
            continue;
        }
        [$p, $v] = array_map('trim', explode(':', $decl, 2));
        $v = (string) preg_replace('/\s*!important\s*$/i', '', $v);
        if ($p !== '' && $v !== '') {
            $out[strtolower($p)] = $v;
        }
    }

    return $out;
}

/**
 * Class rules → Mosaic class definitions. Handles `.name`, `.name:hover`,
 * `.name:focus(-visible)` and max-width media queries (≤767 → mobile,
 * ≤1079 → tablet). Anything else is named in a warning.
 *
 * @return array<string,array<string,mixed>> source class → {name, source, style}
 */
function nibwp_mosaic_pro_css_classes(string $css, array &$warnings): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $out = [];
    $skipped = [];

    $blocks = [['bp' => 'base', 'css' => $css]];
    if (preg_match_all('/@media[^{]*max-width\s*:\s*(\d+)px[^{]*\{((?:[^{}]*\{[^}]*\})*)\s*\}/i', $css, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $media) {
            $blocks[] = ['bp' => (int) $media[1] <= 767 ? 'mobile' : ((int) $media[1] <= 1079 ? 'tablet' : 'base'), 'css' => $media[2]];
            $blocks[0]['css'] = str_replace($media[0], '', $blocks[0]['css']);
        }
    }
    $blocks[0]['css'] = (string) preg_replace('/@[a-z-]+[^{]*\{(?:[^{}]*\{[^}]*\})*\s*\}/i', '', $blocks[0]['css']);

    foreach ($blocks as $block) {
        if (!preg_match_all('/([^{}]+)\{([^}]*)\}/', $block['css'], $rules, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($rules as $rule) {
            $decls = nibwp_mosaic_pro_parse_declarations($rule[2]);
            foreach (array_map('trim', explode(',', $rule[1])) as $selector) {
                if (preg_match('/^\.([A-Za-z_][\w-]*)(?::(hover|focus|focus-visible))?$/', $selector, $sm) !== 1) {
                    if ($selector !== '') {
                        $skipped[$selector] = true;
                    }
                    continue;
                }
                $source = $sm[1];
                $name = strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $source));
                $name = trim($name, '-');
                if ($name === '' || !ctype_alpha($name[0])) {
                    $name = 'c-' . $name;
                }
                $key = isset($sm[2]) ? $sm[2] . ($block['bp'] !== 'base' ? '@' . $block['bp'] : '') : $block['bp'];
                $out[$source]['name'] = $name;
                $out[$source]['source'] = $source;
                $out[$source]['style'][$key] = array_merge($out[$source]['style'][$key] ?? [], $decls);
            }
        }
    }
    if ($skipped !== []) {
        $warnings[] = ['path' => '<style>', 'message' => 'Only single-class rules become Mosaic classes. Not carried over: ' . implode(', ', array_slice(array_keys($skipped), 0, 12)) . (count($skipped) > 12 ? ', …' : '') . '. Put that styling on the elements, or express it as classes.'];
    }

    return $out;
}
