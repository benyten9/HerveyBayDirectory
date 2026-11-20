<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * NIBWP_Paper_Ingest — the door, and the only one.
 *
 * Everything in a Paper design arrives here as a JSON payload an agent built from
 * its own MCP session. That makes it outside input in the strictest sense: we did
 * not fetch it, we cannot re-fetch it to compare, and the shapes it claims to
 * have are whatever the sender typed. So this class does three jobs and refuses
 * to do a fourth:
 *
 *   1. BOUND it. Bytes, node count, tree depth, text length, CSS props per node,
 *      assets, fonts, tokens. Each limit has its own refusal code so a sender can
 *      tell "split the design" from "that is not a design".
 *   2. SANITISE it. CSS property names must look like CSS property names; values
 *      that could break out of an inline style attribute, load a script, or import
 *      another stylesheet are dropped with a warning. Text is text.
 *   3. SHAPE it. HTML, JSX or a node tree all collapse into one canonical node
 *      shape, which is the only thing the normalizer ever sees.
 *
 * What it will not do: execute anything. JSX is transformed by string rules and
 * then parsed as markup — there is no eval, no create_function, no unserialize,
 * and nothing from the payload is ever echoed as markup. The emitters rebuild the
 * page from the normalized object, escaping as they go, so a <script> in the
 * payload has no path to the page even if it survived parsing. It does not: the
 * tags that execute are dropped on sight and the drop is reported.
 */
class NIBWP_Paper_Ingest
{
    /** The whole payload, serialised. 2 MiB is a very large design and a small HTTP body. */
    private const MAX_BYTES = 2097152;

    /** Nodes in the tree. Past this the conversion is unreadable anyway. */
    private const MAX_NODES = 5000;

    /** Tree depth. Recursion is the parser's weak point, so this is the real guard. */
    private const MAX_DEPTH = 64;

    /** Characters in one text node. */
    private const MAX_TEXT = 20000;

    /** CSS declarations kept per node. */
    private const MAX_CSS_PROPS = 120;

    private const MAX_ASSETS = 200;
    private const MAX_FONTS = 50;
    private const MAX_COLORS = 64;
    private const MAX_TEXT_TOKENS = 32;

    /** Markup that executes, styles, or loads something. Dropped, never parsed into the tree. */
    private const SKIP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'link', 'meta',
        'base', 'noscript', 'template', 'head', 'form', 'input', 'button', 'select',
        'textarea', 'audio', 'video', 'source', 'track', 'canvas', 'portal',
    ];

    /** @var array<int, string> */
    private array $warnings = [];

    private int $nodes = 0;

    private int $max_depth_seen = 0;

    private bool $over_nodes = false;

    private bool $over_depth = false;

    /** @var array<string, array<string, string>> node id → CSS map supplied by the agent */
    private array $computed = [];

    /**
     * The bounds, published so an agent can size a payload before sending it.
     *
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return [
            'max_payload_bytes' => self::MAX_BYTES,
            'max_nodes' => self::MAX_NODES,
            'max_depth' => self::MAX_DEPTH,
            'max_text_characters' => self::MAX_TEXT,
            'max_css_properties_per_node' => self::MAX_CSS_PROPS,
            'max_assets' => self::MAX_ASSETS,
            'max_fonts' => self::MAX_FONTS,
            'max_color_tokens' => self::MAX_COLORS,
            'max_text_tokens' => self::MAX_TEXT_TOKENS,
        ];
    }

    /**
     * Validate, bound and shape one payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function prepare(mixed $raw)
    {
        return (new self())->run($raw);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function run(mixed $raw)
    {
        if (!is_array($raw) || $raw === []) {
            return nibwp_paper_err(
                'bad_payload',
                __('Send the design as an object: design { name, source, tree | html | jsx, ... }. Read it out of Paper with your own MCP connection first.', 'nibwp'),
                422
            );
        }

        // Size first, before anything walks it. json_encode is also how we get one
        // flat string to scan for the two things that are never acceptable.
        $serialised = wp_json_encode($raw);
        if (!is_string($serialised)) {
            return nibwp_paper_err('bad_payload', __('That payload is not JSON-encodable, so it cannot be a design.', 'nibwp'), 422);
        }

        $bytes = strlen($serialised);
        if ($bytes > self::MAX_BYTES) {
            return nibwp_paper_err(
                'payload_too_large',
                sprintf(
                    /* translators: 1: payload size in bytes, 2: the limit in bytes */
                    __('That payload is %1$d bytes and the limit is %2$d. Ingest one frame or section at a time.', 'nibwp'),
                    $bytes,
                    self::MAX_BYTES
                ),
                413
            );
        }

        $unsafe = $this->unsafe_reason($serialised);
        if ($unsafe === '') {
            // Inside JSON a quote is escaped, so a serialised-object marker would
            // slip straight past a pattern written for the literal form.
            $unsafe = $this->unsafe_reason(stripslashes($serialised));
        }
        if ($unsafe !== '') {
            return nibwp_paper_err('unsafe_payload', $unsafe, 422);
        }

        $name = nibwp_paper_str($raw['name'] ?? ($raw['title'] ?? ''), 120);
        if ($name === '') {
            $name = __('Paper design', 'nibwp');
        }

        $this->computed = $this->clean_computed($raw['computed'] ?? ($raw['computedStyles'] ?? null));

        $source = strtolower(nibwp_paper_str($raw['source'] ?? '', 20));
        $has_tree = is_array($raw['tree'] ?? null) || is_array($raw['nodes'] ?? null) || is_array($raw['root'] ?? null);
        $html = is_string($raw['html'] ?? null) ? (string) $raw['html'] : '';
        $jsx = is_string($raw['jsx'] ?? null) ? (string) $raw['jsx'] : '';

        if ($source === '' || !in_array($source, ['tree', 'html', 'jsx'], true)) {
            $source = $has_tree ? 'tree' : ($jsx !== '' ? 'jsx' : ($html !== '' ? 'html' : ''));
        }

        $tree = match ($source) {
            'tree' => $this->from_tree($raw),
            'html' => $this->from_markup($html, false),
            'jsx' => $this->from_markup($jsx, true),
            default => null,
        };

        if ($source === '') {
            return nibwp_paper_err(
                'unsupported_source',
                __('Nothing to read: give tree (a node array), html, or jsx. Paper can export any of the three.', 'nibwp'),
                422
            );
        }

        // The bounds are enforced while walking, and the walk reports which one it
        // hit. Separate codes on purpose: "too many nodes" and "too deep" are
        // different problems with different fixes.
        if ($this->over_nodes) {
            return nibwp_paper_err(
                'too_many_nodes',
                sprintf(
                    /* translators: %d: the node limit */
                    __('That design has more than %d nodes. Ingest it one section at a time.', 'nibwp'),
                    self::MAX_NODES
                ),
                413
            );
        }
        if ($this->over_depth) {
            return nibwp_paper_err(
                'too_deep',
                sprintf(
                    /* translators: %d: the depth limit */
                    __('That tree nests deeper than %d levels. Flatten it in Paper, or ingest an inner frame directly.', 'nibwp'),
                    self::MAX_DEPTH
                ),
                413
            );
        }

        if ($tree === null || $tree === []) {
            return nibwp_paper_err(
                'unparsable',
                sprintf(
                    /* translators: %s: the source kind that was sent */
                    __('Nothing usable came out of the %s you sent — no node survived parsing. Check that you exported the frame and not an empty selection.', 'nibwp'),
                    $source
                ),
                422
            );
        }

        $root = count($tree) === 1 ? $tree[0] : [
            'ptype' => 'frame',
            'name' => $name,
            'text' => '',
            'tag' => '',
            'css' => [],
            'children' => $tree,
        ];

        $prepared = [
            'name' => $name,
            'source' => $source,
            'paper_url' => $this->safe_url($raw['paper_url'] ?? ($raw['url'] ?? '')),
            'file_id' => nibwp_paper_str($raw['file_id'] ?? ($raw['fileId'] ?? ''), 120),
            'node_id' => nibwp_paper_str($raw['node_id'] ?? ($raw['nodeId'] ?? ''), 120),
            'screenshot_url' => $this->safe_url($raw['screenshot_url'] ?? ($raw['screenshot'] ?? '')),
            'tree' => $root,
            'tokens' => $this->clean_tokens($raw['tokens'] ?? null),
            'fonts' => $this->clean_fonts($raw['fonts'] ?? null),
            'assets' => $this->clean_assets($raw['assets'] ?? ($raw['images'] ?? null)),
            'warnings' => array_values(array_unique($this->warnings)),
        ];

        $prepared['stats'] = [
            'payload_bytes' => $bytes,
            'nodes' => $this->nodes,
            'depth' => $this->max_depth_seen,
            'assets' => count($prepared['assets']),
            'fonts' => count($prepared['fonts']),
            'warnings' => count($prepared['warnings']),
        ];

        return $prepared;
    }

    /**
     * The two things that are never a design, whatever they claim to be.
     *
     * A PHP open tag in a payload means somebody is hoping it lands in a file that
     * gets included. A serialised PHP object means somebody is hoping something
     * hands it to PHP's unserializer. Nothing here does either, and the right answer is
     * still to refuse the whole payload by name rather than to clean it and carry
     * on — a sender with a legitimate design has no reason to include either one.
     */
    private function unsafe_reason(string $payload): string
    {
        if (preg_match('/<\?(?:php|=)|<\?\s/i', $payload) === 1) {
            return __('That payload contains a PHP open tag. Refused whole — a design has no reason to carry one.', 'nibwp');
        }

        // O:8:"stdClass": the PHP serialisation marker for an object, plus the
        // marker a tampered payload leaves behind. Nothing here unserialises
        // anything; this refuses the attempt rather than storing it.
        if (preg_match('/\bO:\d+:"[^"]+":\d+:\{|__PHP_Incomplete_Class|C:\d+:"[^"]+":\d+:\{/', $payload) === 1) {
            return __('That payload contains a serialised PHP object. Refused whole — nothing here deserialises anything, and a design does not need it.', 'nibwp');
        }

        return '';
    }

    /* ─────────────────────────── node tree input ─────────────────────────── */

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array<string, mixed>>|null
     */
    private function from_tree(array $raw): ?array
    {
        $source = $raw['tree'] ?? ($raw['root'] ?? ($raw['nodes'] ?? null));
        if (!is_array($source)) {
            return null;
        }

        // Either one node or a list of them.
        $list = isset($source['type']) || isset($source['children']) || isset($source['name'])
            ? [$source]
            : $source;

        $out = [];
        foreach ($list as $node) {
            if (!is_array($node)) {
                continue;
            }
            $built = $this->tree_node($node, 1);
            if ($built !== null) {
                $out[] = $built;
            }
        }

        return $out;
    }

    /**
     * One Paper node → the canonical shape.
     *
     * Paper is CSS-native: its styles arrive as CSS declarations rather than as
     * Figma's 0..1 colour floats, so they are kept as strings and validated as CSS
     * instead of being converted.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function tree_node(array $node, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->over_depth = true;

            return null;
        }
        if ($this->nodes >= self::MAX_NODES) {
            $this->over_nodes = true;

            return null;
        }
        $this->nodes++;
        $this->max_depth_seen = max($this->max_depth_seen, $depth);

        $id = nibwp_paper_str($node['id'] ?? '', 120);
        $css = $this->clean_css($node['styles'] ?? ($node['style'] ?? ($node['css'] ?? null)));
        if ($id !== '' && isset($this->computed[$id])) {
            // The agent's computed styles win: they are what the design actually
            // renders as, and the authored style is often a token reference.
            $css = array_slice($this->computed[$id] + $css, 0, self::MAX_CSS_PROPS, true);
        }

        $out = [
            'ptype' => strtolower(nibwp_paper_str($node['type'] ?? ($node['kind'] ?? ''), 60)),
            'name' => nibwp_paper_str($node['name'] ?? ($node['label'] ?? ''), 120),
            'text' => $this->clean_text($node['text'] ?? ($node['characters'] ?? ($node['content'] ?? ''))),
            'tag' => $this->clean_tag($node['tag'] ?? ($node['element'] ?? '')),
            'css' => $css,
            'children' => [],
        ];

        foreach (['width' => 'w', 'height' => 'h'] as $from => $to) {
            if (isset($node[$from]) && is_numeric($node[$from])) {
                $out[$to] = (float) $node[$from];
            }
        }
        if (isset($node['size']) && is_array($node['size'])) {
            if (is_numeric($node['size']['width'] ?? null)) {
                $out['w'] = (float) $node['size']['width'];
            }
            if (is_numeric($node['size']['height'] ?? null)) {
                $out['h'] = (float) $node['size']['height'];
            }
        }

        $image = $this->safe_url($node['src'] ?? ($node['image'] ?? ($node['imageUrl'] ?? '')));
        if ($image !== '') {
            $out['image'] = $image;
        }
        $ref = nibwp_paper_str($node['imageRef'] ?? ($node['assetRef'] ?? ($node['ref'] ?? '')), 160);
        if ($ref !== '') {
            $out['image_ref'] = $ref;
        }
        $alt = $this->clean_text($node['alt'] ?? '');
        if ($alt !== '') {
            $out['alt'] = mb_substr($alt, 0, 300);
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $built = $this->tree_node($child, $depth + 1);
            if ($built !== null) {
                $out['children'][] = $built;
            }
        }

        return $out;
    }

    /* ─────────────────────────── markup input ─────────────────────────── */

    /**
     * HTML or JSX → the canonical tree.
     *
     * Parsed, never executed. For JSX the transform is textual: the expression
     * syntax PHP cannot evaluate is removed, the two React attribute spellings are
     * renamed, and what is left is markup. If that leaves nothing parseable the
     * caller gets "unparsable" rather than a half-built tree.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function from_markup(string $markup, bool $is_jsx): ?array
    {
        $markup = trim($markup);
        if ($markup === '') {
            return null;
        }
        if (!class_exists('DOMDocument')) {
            $this->warnings[] = __('PHP has no DOM extension on this host, so html and jsx cannot be read. Send tree instead.', 'nibwp');

            return null;
        }

        if ($is_jsx) {
            $markup = $this->jsx_to_markup($markup);
        }

        $doc = new DOMDocument();
        // Malformed markup is the normal case, not an error worth surfacing: a
        // design export is a fragment, so the parser's complaints about a missing
        // doctype are noise. What matters is whether any element came out.
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="nibwp-paper-root">' . $markup . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return null;
        }

        $root = $doc->getElementById('nibwp-paper-root');
        if (!$root instanceof DOMElement) {
            return null;
        }

        $out = [];
        foreach ($root->childNodes as $child) {
            $built = $this->dom_node($child, 1);
            if ($built !== null) {
                $out[] = $built;
            }
        }

        return $out;
    }

    /**
     * JSX → something a markup parser can read.
     *
     * Textual, deliberately. The alternative would be evaluating the component,
     * which is exactly what must never happen to a payload: there is no eval here,
     * no create_function, and no code path that runs anything the sender wrote.
     * Anything the rules cannot resolve is deleted, and what survives is markup.
     */
    private function jsx_to_markup(string $jsx): string
    {
        // Module plumbing and anything before the first tag.
        $jsx = (string) preg_replace('/^\s*(?:import|export)\b[^\n;]*;?\s*$/m', '', $jsx);
        // {/* comment */} and /* comment */
        $jsx = (string) preg_replace('/\{\s*\/\*.*?\*\/\s*\}/s', '', $jsx);
        $jsx = (string) preg_replace('#/\*.*?\*/#s', '', $jsx);
        // React spellings that are really HTML attributes.
        $jsx = (string) preg_replace('/\bclassName\s*=/', 'class=', $jsx);
        $jsx = (string) preg_replace('/\bhtmlFor\s*=/', 'for=', $jsx);
        // style={{ fontSize: 16 }} → an inline style we can read.
        $jsx = (string) preg_replace_callback(
            '/\bstyle\s*=\s*\{\{(.*?)\}\}/s',
            static function (array $m): string {
                $css = '';
                foreach (explode(',', $m[1]) as $pair) {
                    if (!str_contains($pair, ':')) {
                        continue;
                    }
                    [$prop, $value] = explode(':', $pair, 2);
                    $prop = trim($prop, " \t\n\r\"'");
                    // camelCase → kebab-case, which is the only transform needed.
                    $prop = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $prop));
                    $value = trim($value, " \t\n\r\"'");
                    if ($prop === '' || $value === '' || str_contains($value, '{')) {
                        continue;
                    }
                    if (is_numeric($value)) {
                        $value .= 'px';
                    }
                    $css .= $prop . ':' . $value . ';';
                }

                return $css === '' ? '' : 'style="' . str_replace('"', '', $css) . '"';
            },
            $jsx
        );
        // Any remaining prop={expression} — a value PHP has no business guessing.
        $jsx = (string) preg_replace('/\s[\w:.$-]+\s*=\s*\{[^{}]*\}/', '', $jsx);
        // Spread props and bare {expressions} used as children.
        $jsx = (string) preg_replace('/\{\s*\.\.\.[^}]*\}/', '', $jsx);
        $jsx = (string) preg_replace('/\{[^{}<>]*\}/', '', $jsx);
        // Fragments.
        $jsx = str_replace(['<>', '</>'], ['<div>', '</div>'], $jsx);

        // Markup only: everything before the first tag and after the last one is
        // the component around the markup, and leaving it in turns "); }" into a
        // text node that then becomes a paragraph on the page.
        $first = strpos($jsx, '<');
        $last = strrpos($jsx, '>');
        if ($first === false || $last === false || $last < $first) {
            return '';
        }

        return substr($jsx, $first, $last - $first + 1);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dom_node(DOMNode $node, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->over_depth = true;

            return null;
        }

        if ($node instanceof DOMText) {
            $text = $this->clean_text($node->wholeText);
            if ($text === '') {
                return null;
            }
            if ($this->nodes >= self::MAX_NODES) {
                $this->over_nodes = true;

                return null;
            }
            $this->nodes++;
            $this->max_depth_seen = max($this->max_depth_seen, $depth);

            return ['ptype' => 'text', 'name' => '', 'text' => $text, 'tag' => 'p', 'css' => [], 'children' => []];
        }

        if (!$node instanceof DOMElement) {
            // Comments, CDATA, processing instructions: nothing a design needs.
            return null;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SKIP_TAGS, true)) {
            // Neutralised, and said out loud: a <script> in a design export is
            // either a mistake or an attempt, and either way it is reported.
            $this->warnings[] = sprintf(
                /* translators: %s: an HTML tag name */
                __('Dropped a "%s" element and everything inside it — markup that executes or loads is never part of a design.', 'nibwp'),
                $tag
            );

            return null;
        }

        if ($this->nodes >= self::MAX_NODES) {
            $this->over_nodes = true;

            return null;
        }
        $this->nodes++;
        $this->max_depth_seen = max($this->max_depth_seen, $depth);

        $this->warn_on_handlers($node);

        $id = $node->getAttribute('data-node-id');
        if ($id === '') {
            $id = $node->getAttribute('id');
        }
        $css = $this->clean_css($node->getAttribute('style'));
        $id = nibwp_paper_str($id, 120);
        if ($id !== '' && isset($this->computed[$id])) {
            $css = array_slice($this->computed[$id] + $css, 0, self::MAX_CSS_PROPS, true);
        }

        $out = [
            'ptype' => $tag,
            'name' => nibwp_paper_str($node->getAttribute('data-name') ?: $node->getAttribute('class'), 120),
            'text' => '',
            'tag' => $this->clean_tag($tag),
            'css' => $css,
            'children' => [],
        ];

        if ($tag === 'img') {
            $src = $this->safe_url($node->getAttribute('src'));
            if ($src !== '') {
                $out['image'] = $src;
            }
            $alt = $this->clean_text($node->getAttribute('alt'));
            if ($alt !== '') {
                $out['alt'] = mb_substr($alt, 0, 300);
            }
        }

        foreach ($node->childNodes as $child) {
            $built = $this->dom_node($child, $depth + 1);
            if ($built !== null) {
                $out['children'][] = $built;
            }
        }

        // A box whose only child is a bare text run IS the text node — otherwise
        // every <p> becomes a container wrapping an anonymous paragraph.
        if (count($out['children']) === 1
            && ($out['children'][0]['ptype'] ?? '') === 'text'
            && ($out['children'][0]['name'] ?? '') === ''
        ) {
            $out['text'] = (string) $out['children'][0]['text'];
            $out['children'] = [];
            $this->nodes--;
        }

        return $out;
    }

    /** Record, once, that event-handler attributes were present and ignored. */
    private function warn_on_handlers(DOMElement $node): void
    {
        if (!$node->hasAttributes()) {
            return;
        }
        foreach ($node->attributes as $attribute) {
            $name = strtolower((string) $attribute->nodeName);
            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'formaction') {
                $this->warnings[] = __('Ignored event-handler and script-carrying attributes on the markup. Only layout, text and image references are read.', 'nibwp');

                return;
            }
        }
    }

    /* ─────────────────────────── field cleaners ─────────────────────────── */

    /**
     * A CSS declaration map that cannot escape an inline style attribute.
     *
     * Property names must look like property names. Values lose the characters
     * that end a declaration or a tag, and anything that loads or evaluates is
     * dropped outright rather than cleaned — a design has no use for it.
     *
     * @return array<string, string>
     */
    private function clean_css(mixed $raw): array
    {
        $pairs = [];

        if (is_string($raw)) {
            foreach (explode(';', $raw) as $declaration) {
                if (!str_contains($declaration, ':')) {
                    continue;
                }
                [$prop, $value] = explode(':', $declaration, 2);
                $pairs[$prop] = $value;
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $prop => $value) {
                $pairs[(string) $prop] = $value;
            }
        } else {
            return [];
        }

        $out = [];
        foreach ($pairs as $prop => $value) {
            if (count($out) >= self::MAX_CSS_PROPS) {
                $this->warnings[] = sprintf(
                    /* translators: %d: the per-node CSS property limit */
                    __('A node declared more than %d CSS properties; the rest were dropped.', 'nibwp'),
                    self::MAX_CSS_PROPS
                );
                break;
            }
            // camelCase arrives from anything JS-shaped; kebab-case is what CSS is.
            $prop = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', trim((string) $prop)));
            if (preg_match('/^-{0,2}[a-z][a-z0-9-]*$/', $prop) !== 1) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $clean = $this->clean_css_value($prop, trim((string) $value));
            if ($clean === '') {
                continue;
            }
            $out[$prop] = $clean;
        }

        return $out;
    }

    private function clean_css_value(string $prop, string $value): string
    {
        if ($value === '' || strlen($value) > 500) {
            return '';
        }

        // Properties that exist to run something.
        if (in_array($prop, ['behavior', '-moz-binding'], true)) {
            $this->warnings[] = __('Dropped a CSS property whose only purpose is to execute code.', 'nibwp');

            return '';
        }

        $lower = strtolower($value);
        foreach (['expression(', 'javascript:', 'vbscript:', '@import', 'progid:', '</', '<script'] as $needle) {
            if (str_contains($lower, $needle)) {
                $this->warnings[] = sprintf(
                    /* translators: %s: the CSS property whose value was dropped */
                    __('Dropped the value of "%s": it tried to load or run something.', 'nibwp'),
                    $prop
                );

                return '';
            }
        }

        // url() may only point at a real image over http(s) or a data: image.
        if (str_contains($lower, 'url(')) {
            if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $value, $matches) === false) {
                return '';
            }
            foreach ((array) ($matches[1] ?? []) as $candidate) {
                if ($this->safe_url($candidate) === '') {
                    $this->warnings[] = sprintf(
                        /* translators: %s: the CSS property whose value was dropped */
                        __('Dropped the value of "%s": the url() it carries is not an image this site may load.', 'nibwp'),
                        $prop
                    );

                    return '';
                }
            }
        }

        // What is left cannot end the declaration, close the attribute, or open a tag.
        $value = str_replace(['\\', '"', ';', '{', '}', '<', '>'], '', $value);
        $value = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $value);

        return trim($value);
    }

    /** Text is text: no markup survives, no control characters, bounded length. */
    private function clean_text(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $text = (string) $raw;
        // Tags out before anything else — a text run is never markup, and the
        // emitters escape on the way out, so this is belt and braces on purpose.
        $text = wp_strip_all_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));

        return $text === '' ? '' : mb_substr($text, 0, self::MAX_TEXT);
    }

    /** An element name hint, or nothing. Never interpolated anywhere. */
    private function clean_tag(mixed $raw): string
    {
        $tag = strtolower(nibwp_paper_str($raw, 20));

        return preg_match('/^[a-z][a-z0-9]{0,14}$/', $tag) === 1 ? $tag : '';
    }

    /**
     * A URL this site may actually load, or an empty string.
     *
     * http and https for a real export, data: for an inlined image. Everything
     * else — javascript:, file:, a bare path, anything with a control character —
     * comes back empty and the caller decides whether that is fatal.
     */
    private function safe_url(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $url = trim((string) $raw);
        if ($url === '' || strlen($url) > 2000) {
            return '';
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return '';
        }

        if (stripos($url, 'data:') === 0) {
            return preg_match('#^data:image/(png|jpe?g|gif|webp|avif|svg\+xml);base64,[A-Za-z0-9+/=\s]+$#i', $url) === 1
                ? (string) preg_replace('/\s+/', '', $url)
                : '';
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        return esc_url_raw($url);
    }

    /**
     * Per-node computed styles the agent read out of Paper.
     *
     * @return array<string, array<string, string>>
     */
    private function clean_computed(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $node_id => $css) {
            if (count($out) >= self::MAX_NODES) {
                break;
            }
            $node_id = nibwp_paper_str($node_id, 120);
            if ($node_id === '') {
                continue;
            }
            $clean = $this->clean_css($css);
            if ($clean !== []) {
                $out[$node_id] = $clean;
            }
        }

        return $out;
    }

    /**
     * The design's own token map, if the agent read one.
     *
     * @return array<string, mixed>
     */
    private function clean_tokens(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $colors = [];
        foreach ((array) ($raw['colors'] ?? []) as $name => $value) {
            if (count($colors) >= self::MAX_COLORS) {
                break;
            }
            $token = $this->clean_token_name($name, count($colors), 'color');
            $hex = $this->clean_color($value);
            if ($token !== '' && $hex !== '') {
                $colors[$token] = $hex;
            }
        }

        $text = [];
        foreach ((array) ($raw['text'] ?? ($raw['type'] ?? [])) as $name => $spec) {
            if (count($text) >= self::MAX_TEXT_TOKENS) {
                break;
            }
            $token = $this->clean_token_name($name, count($text), 'text');
            if ($token === '') {
                continue;
            }
            if (is_scalar($spec)) {
                $spec = ['size' => $spec];
            }
            if (!is_array($spec)) {
                continue;
            }
            $size = nibwp_paper_str($spec['size'] ?? ($spec['fontSize'] ?? ''), 24);
            if ($size !== '' && is_numeric($size)) {
                $size .= 'px';
            }
            $text[$token] = [
                'size' => preg_match('/^[0-9.]+(px|rem|em|%)$/', $size) === 1 ? $size : '',
                'weight' => isset($spec['weight']) && is_numeric($spec['weight']) ? max(100, min(900, (int) $spec['weight'])) : 400,
                'family' => $this->clean_font_family($spec['family'] ?? ($spec['fontFamily'] ?? '')),
            ];
        }

        return $colors === [] && $text === [] ? [] : ['source' => 'paper', 'colors' => $colors, 'text' => $text];
    }

    private function clean_token_name(mixed $raw, int $index, string $kind): string
    {
        $name = strtolower(nibwp_paper_str($raw, 80));
        $name = (string) preg_replace('/[^a-z0-9_-]/', '-', $name);
        $name = trim($name, '-');
        if ($name === '') {
            return '--' . $kind . '-' . ($index + 1);
        }

        return str_starts_with($name, '--') ? $name : '--' . $name;
    }

    /** A CSS colour, or nothing. Keywords are allowed; functions are parsed, not trusted. */
    private function clean_color(mixed $raw): string
    {
        $value = strtolower(nibwp_paper_str($raw, 80));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla|oklch|lab)\([0-9a-z%.,\/\s+-]{1,60}\)$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[a-z]{3,20}$/', $value) === 1) {
            return $value;
        }

        return '';
    }

    private function clean_font_family(mixed $raw): string
    {
        $family = nibwp_paper_str($raw, 120);
        $family = (string) preg_replace('/[^A-Za-z0-9 ,.\'_-]/', '', $family);

        return trim($family);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clean_fonts(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $font) {
            if (count($out) >= self::MAX_FONTS) {
                break;
            }
            if (is_scalar($font)) {
                $font = ['family' => $font];
            }
            if (!is_array($font)) {
                continue;
            }
            $family = $this->clean_font_family($font['family'] ?? ($font['name'] ?? ''));
            if ($family === '') {
                continue;
            }
            $weights = [];
            foreach ((array) ($font['weights'] ?? []) as $weight) {
                if (is_numeric($weight) && count($weights) < 12) {
                    $weights[] = max(100, min(900, (int) $weight));
                }
            }
            $out[] = ['family' => $family, 'weights' => array_values(array_unique($weights))];
        }

        return $out;
    }

    /**
     * Image references the design carries. Nothing is downloaded here — that is
     * attach_asset, which takes upload_files and says what it did.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clean_assets(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $asset) {
            if (count($out) >= self::MAX_ASSETS) {
                $this->warnings[] = sprintf(
                    /* translators: %d: the asset limit */
                    __('More than %d image references were sent; the rest were dropped.', 'nibwp'),
                    self::MAX_ASSETS
                );
                break;
            }
            if (is_scalar($asset)) {
                $asset = ['url' => $asset];
            }
            if (!is_array($asset)) {
                continue;
            }
            $url = $this->safe_url($asset['url'] ?? ($asset['src'] ?? ''));
            $ref = nibwp_paper_str($asset['ref'] ?? (is_string($key) ? $key : ''), 160);
            if ($url === '' && $ref === '') {
                continue;
            }
            if ($url === '' && isset($asset['url'])) {
                $this->warnings[] = sprintf(
                    /* translators: %s: the asset reference whose URL was rejected */
                    __('Image "%s" came with a URL this site may not load, so only the reference was kept.', 'nibwp'),
                    $ref
                );
            }
            $out[] = [
                'ref' => $ref,
                'source_url' => $url,
                'alt' => mb_substr($this->clean_text($asset['alt'] ?? ''), 0, 300),
                'attachment_id' => 0,
                'url' => '',
            ];
        }

        return $out;
    }
}
