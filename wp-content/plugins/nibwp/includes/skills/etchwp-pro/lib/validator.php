<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * EtchWP Pro — payload validator.
 *
 * Runs the agent-built Etch payload through every quality rule the playbook
 * enforces. Returns a structured pass/fail map so the agent can patch and
 * resubmit until the payload conforms.
 *
 * Rules (each implemented as a private function below):
 *  - BEM grammar on every selector
 *  - Token-with-fallback on every CSS value; reject Tailwind ramps + display aliases
 *  - No clamp() anywhere in a font-size value (including inside var() fallback)
 *  - Style hoist for every class used inside wp:html / etch/raw-html blocks
 *  - __libraryMeta shape and slug/folder/id consistency
 *  - Form-shortcode presence when source HTML contained <form>
 */

/**
 * ACSS tokens the playbook may name, mirrored from references/acss-tokens.md.
 *
 * Taken from a live Automatic.css 3.3.7 variables file, not from memory: the
 * previous list named tokens ACSS 3 does not define (--surface-light,
 * --text-muted, --radius-full, --content-padding, --leading-*) and gave
 * neutral roles to tokens that are brand-tinted or meant for dark surfaces.
 * Every one carried a fallback, so nothing failed and pages rendered wrong.
 *
 * This list keeps the documentation honest (tests/etchwp-docs-lint-check.php).
 * What a payload may use is decided per site: see
 * nibwp_etchwp_validate_site_tokens(), which checks against the tokens the
 * site actually defines.
 */
const NIBWP_ETCHWP_CANONICAL_TOKENS = [
    // Space
    '--space-xs', '--space-s', '--space-m', '--space-l', '--space-xl', '--space-xxl',
    '--section-space-xs', '--section-space-s', '--section-space-m', '--section-space-l', '--section-space-xl', '--section-space-xxl',
    '--gutter', '--section-gutter', '--grid-gap', '--content-gap', '--container-gap',
    // Layout
    '--content-width', '--content-width-safe',
    // Text size + rhythm
    '--text-xs', '--text-s', '--text-m', '--text-l', '--text-xl', '--text-xxl',
    '--h1', '--h2', '--h3', '--h4', '--h5', '--h6',
    '--text-line-height', '--heading-line-height', '--heading-font-weight',
    // Radius
    '--radius', '--radius-xs', '--radius-s', '--radius-m', '--radius-l', '--radius-xl', '--radius-xxl', '--radius-circle', '--radius-none',
    // Text color (dark-muted on light surfaces, light-muted on dark ones)
    '--text-color', '--text-dark', '--text-dark-muted', '--text-light', '--text-light-muted', '--body-color', '--link-color', '--link-color-hover',
    // Surfaces
    '--white', '--black', '--body-bg-color',
    '--bg-ultra-light', '--bg-light', '--bg-dark', '--bg-ultra-dark',
    // Borders (dark on light surfaces, light on dark ones)
    '--border-color-dark', '--border-color-light', '--border-size', '--border-style', '--border-width',
    '--divider-color-dark', '--divider-color-light',
    // Shadow, focus, motion
    '--box-shadow-m', '--box-shadow-xl', '--box-shadow-1', '--box-shadow-2', '--box-shadow-3',
    '--focus-color', '--focus-width', '--focus-offset',
    '--transition', '--transition-duration', '--transition-timing',
];

/**
 * Token families ACSS generates per color: `--primary`, `--primary-light`,
 * `--primary-hover`, `--black-trans-20`, and so on. Real on every site that
 * enables the color, so they are allowed by pattern rather than listed.
 */
const NIBWP_ETCHWP_CANONICAL_TOKEN_PATTERNS = [
    '/^--(primary|secondary|tertiary|accent|base|neutral|success|warning|danger|info)(-(ultra-light|light|semi-light|semi-dark|dark|ultra-dark|hover))?(-trans-\d0)?$/',
    '/^--(black|white)-trans-\d0$/',
];

/**
 * Forbidden invented token names (mirrors acss-tokens.md § Forbidden invented tokens).
 */
const NIBWP_ETCHWP_FORBIDDEN_TOKENS = [
    '--base-50', '--base-100', '--base-200', '--base-300', '--base-400',
    '--base-500', '--base-600', '--base-700', '--base-800', '--base-900',
    '--text-display-m', '--text-display-l', '--text-display-xl',
];

/**
 * Forbidden token regex patterns. Tailwind/Material muscle-memory tells.
 */
const NIBWP_ETCHWP_FORBIDDEN_PATTERNS = [
    '/^--text-\d+$/',
    '/^--space-\d+$/',
    '/^--base-\d{2,3}$/',
];

/**
 * Utility class allowlist for raw <wp:html> blocks — classes that may appear
 * without the brand prefix because they're framework-level state hooks, not
 * BEM components. Filterable.
 */
const NIBWP_ETCHWP_UTILITY_ALLOWLIST = [
    'is-active', 'is-open', 'is-hidden', 'is-loading', 'is-disabled', 'is-selected',
    'has-icon', 'has-children', 'has-error', 'has-success',
    'sr-only', 'visually-hidden', 'screen-reader-text',
];

/**
 * CSS properties whose values are policed by the hardcoded-color rule.
 * Property name → match group (always 1 for these patterns).
 */
const NIBWP_ETCHWP_COLOR_PROPERTIES = [
    'color', 'background-color', 'background', 'border-color', 'border',
    'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'outline-color', 'fill', 'stroke', 'box-shadow', 'text-shadow',
    'caret-color', 'column-rule-color', 'accent-color',
];

/**
 * CSS properties whose values are policed by the hardcoded-font-size rule.
 * Only `font-size` and the shorthand `font` are checked — line-height etc.
 * stay free to use literals.
 */
const NIBWP_ETCHWP_FONT_SIZE_PROPERTIES = ['font-size'];

/**
 * The complete Etch dynamic-data modifier catalog (Etch 1.5), used to warn when a
 * binding references a modifier the engine does not ship. Mirrors the modifier
 * classes in etch/classes/Blocks/Global/Utilities/Modifiers/*. See
 * references/dynamic-data.md. Compared case-insensitively.
 */
const NIBWP_ETCHWP_KNOWN_MODIFIERS = [
    // Arithmetic + Numeric
    'add', 'subtract', 'multiply', 'divide', 'mod',
    'numberFormat', 'toInt', 'ceil', 'round', 'floor',
    // String
    'toUpperCase', 'toLowerCase', 'toString', 'toBool', 'trim', 'ltrim', 'rtrim',
    'split', 'toSlug', 'truncateChars', 'truncateWords', 'urlEncode', 'urlDecode',
    'stripTags', 'replace', 'replaceAll', 'startsWith', 'endsWith',
    // Collection
    'concat', 'join', 'slice', 'at', 'includes', 'pluck', 'length', 'reverse',
    'values', 'keys', 'indexOf', 'intersects', 'unserializePHP',
    // Comparison
    'equal', 'less', 'lessOrEqual', 'greater', 'greaterOrEqual',
    // Date + dynamic-content-aware
    'format', 'dateFormat', 'applyData',
];

/**
 * Detect whether ACSS (Automatic.css) is active on the current site.
 *
 * Best-effort probe — checks the canonical constants/classes ACSS exposes.
 */
function nibwp_etchwp_acss_active(): bool
{
    return defined('ACSS_PLUGIN_FILE')
        || defined('ACSS_VERSION')
        || class_exists('\\Automatic_CSS\\Plugin')
        || function_exists('acss_get_setting');
}

/**
 * Brand color allowlist — colors the agent is allowed to use as literals
 * (outside var() fallback) without tripping the hardcoded_color rule.
 *
 * Source of truth is the existing nibwp_user_defaults preferences store
 * (etchwp_brand_color + etchwp_brand_color_2), written by the preferences
 * ability. NEVER invent a new option for this — reuse the one users set.
 *
 * @return array<int,string> Lowercased hex/rgb/hsl literals.
 */
function nibwp_etchwp_brand_color_allowlist(): array
{
    $defaults = (array) get_option('nibwp_user_defaults', []);
    $raw = array_filter([
        $defaults['etchwp_brand_color']   ?? null,
        $defaults['etchwp_brand_color_2'] ?? null,
    ], static fn ($v) => is_string($v) && $v !== '');
    $colors = array_map(static fn ($c) => strtolower(trim((string) $c)), $raw);
    /** @var array<int,string> $colors */
    $colors = (array) apply_filters('nibwp_etchwp_brand_color_allowlist', $colors);
    return array_values($colors);
}

/**
 * Every blockName in a payload tree, at any depth.
 *
 * @param array<string, mixed>|null $node
 * @param array<int, string>        $out
 */
function nibwp_etchwp_collect_block_names($node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }

    if (isset($node['blockName'])) {
        $out[] = (string) $node['blockName'];
    }

    foreach (['innerBlocks', 'inner_blocks'] as $key) {
        foreach ((array) ($node[$key] ?? []) as $child) {
            nibwp_etchwp_collect_block_names($child, $out);
        }
    }

    // A bare list of blocks, rather than one block with children.
    if (!isset($node['blockName'])) {
        foreach ($node as $child) {
            if (is_array($child)) {
                nibwp_etchwp_collect_block_names($child, $out);
            }
        }
    }
}

/**
 * Validate an agent-built Etch payload against the full playbook.
 *
 * @param array<string,mixed> $payload The full Etch artifact.
 * @param array{element_type?:string,brand?:string,has_raw_html_block?:bool,source_html?:string,acss_active?:bool} $ctx
 * @return array{passed:bool,failed:array<int,array{id:string,msg:string,path:string,fix_hint:string}>,warnings:array<int,array{id:string,msg:string,path:string}>}
 */
function nibwp_etchwp_validate_payload(array $payload, array $ctx): array
{
    $failed   = [];
    $warnings = [];

    $brand        = (string) ($ctx['brand'] ?? '');
    $acss_active  = array_key_exists('acss_active', $ctx) ? (bool) $ctx['acss_active'] : nibwp_etchwp_acss_active();
    $color_allow  = nibwp_etchwp_brand_color_allowlist();

    // The tokens this site defines. Checked only when ACSS is on and its files
    // could be read: an empty list means "cannot check", not "none exist".
    $site_tokens = array_key_exists('site_tokens', $ctx)
        ? (array) $ctx['site_tokens']
        : ($acss_active && function_exists('nibwp_acss_site_tokens') ? nibwp_acss_site_tokens() : []);

    // Custom properties the payload declares itself (in a style, or inline as
    // a component prop hook) are real even though ACSS never heard of them.
    $declared_props = [];
    if ($site_tokens !== [] && preg_match_all('/(--[a-z0-9-]+)\s*:/i', (string) wp_json_encode($payload), $declared_matches)) {
        foreach ($declared_matches[1] as $declared_name) {
            $declared_props[strtolower($declared_name)] = true;
        }
    }

    // 0) Something to actually write.
    //
    // Every other rule here reads gutenbergBlock with `?? null` and stays
    // quiet when it is absent, so a payload that carried no block tree passed
    // validation clean. The persister then created the page, merged the
    // styles, wrote no content and returned success — a blank page reported
    // as a successful build, which is how a customer spent a day on it.
    //
    // components-only payloads are legitimate: they register reusable
    // definitions without touching a page. Everything else must bring blocks.
    $block_tree = $payload['gutenbergBlock'] ?? null;
    $has_components = !empty($payload['components']) && is_array($payload['components']);
    if (!is_array($block_tree) || $block_tree === []) {
        if (!$has_components) {
            $failed[] = [
                'id'   => 'missing_block_tree',
                'msg'  => 'payload.gutenbergBlock is missing or empty, so this payload would create a page with no content on it. Put the etch/element tree under the gutenbergBlock key. (A payload with only `components` is allowed, and registers definitions without writing a page.)',
                'path' => 'gutenbergBlock',
            ];
        }
    }

    // 0b) The blocks have to be Etch's blocks.
    //
    // Nothing here ever checked this. Every mention of etch/element in this
    // file was a comment or an error message, and the only blockName tests are
    // for core/html and the shortcode blocks. So a tree built from core/group,
    // core/heading and core/paragraph passed with no failures and no warnings.
    //
    // That page is not empty and not broken. WordPress stores it, Gutenberg
    // edits it, the front end renders it — and the Etch builder shows inert
    // placeholders, because they are not its blocks. A customer read that as
    // the page having been created empty.
    if (is_array($block_tree) && $block_tree !== []) {
        $block_names = [];
        nibwp_etchwp_collect_block_names($block_tree, $block_names);

        $etch_blocks = array_filter($block_names, static fn(string $n): bool => str_starts_with($n, 'etch/'));
        // core/html and core/shortcode are deliberate escape hatches the rest
        // of this validator already supports; everything else from core is a
        // block Etch cannot edit.
        $foreign = array_values(array_unique(array_filter(
            $block_names,
            static fn(string $n): bool => $n !== '' && !str_starts_with($n, 'etch/') && $n !== 'core/html' && $n !== 'core/shortcode'
        )));

        if ($etch_blocks === []) {
            $failed[] = [
                'id'   => 'no_etch_blocks',
                'msg'  => sprintf(
                    'gutenbergBlock contains no etch/* blocks (found: %s). Etch can only edit its own blocks — a tree of core blocks produces a page that Gutenberg can edit and the Etch builder shows as inert placeholders. Build the tree from etch/element blocks.',
                    $block_names === [] ? 'none' : implode(', ', array_unique($block_names))
                ),
                'path' => 'gutenbergBlock',
            ];
        } elseif ($foreign !== []) {
            $warnings[] = [
                'id'   => 'foreign_blocks',
                'msg'  => sprintf('gutenbergBlock mixes in blocks Etch cannot edit: %s. They will render, but appear as inert placeholders in the builder.', implode(', ', $foreign)),
                'path' => 'gutenbergBlock',
            ];
        }
    }

    // 0c) Will Etch resolve it? Every rule above checks the payload against
    //     our conventions; these check it against Etch, which is what decides
    //     whether anything appears on the page at all.
    $resolvable = nibwp_etchwp_validate_etch_resolvable($payload);
    $failed = array_merge($failed, $resolvable['failed']);
    $warnings = array_merge($warnings, $resolvable['warnings']);

    // 0d) Will anything on the page actually be styled? Etch emits a style
    //     only when a block references its id, so CSS can be written and
    //     never rendered, and classes can exist with no CSS behind them.
    $failed = array_merge($failed, nibwp_etchwp_validate_style_coverage($payload, $brand));
    $failed = array_merge($failed, nibwp_etchwp_validate_element_classes($payload));

    // 1) __libraryMeta shape
    $manifest_issues = nibwp_etchwp_validate_manifest($payload);
    $failed = array_merge($failed, $manifest_issues['failed']);
    $warnings = array_merge($warnings, $manifest_issues['warnings']);

    // 2) Walk styles dict — BEM + tokens + no clamp() font-size + hardcoded font-size + hardcoded color + ACSS-absent
    $styles = (array) ($payload['styles'] ?? []);
    foreach ($styles as $style_id => $style) {
        if (!is_array($style)) {
            continue;
        }
        // Skip the readonly scaffold styles.
        if (in_array($style_id, ['etch-section-style', 'etch-container-style', 'etch-flex-div-style', 'etch-iframe-style', 'etch-global-variable-style'], true)) {
            continue;
        }

        // Read the style the same way the persister will write it. A payload
        // that used selector => {property: value} used to arrive here with no
        // 'selector' and no 'css', so every rule below tested an empty string
        // and passed — the payload was reported clean and then rendered
        // unstyled. Normalising first means the rules see the real CSS.
        $normalized = nibwp_etchwp_normalize_style((string) $style_id, $style);

        if ($normalized === null) {
            $failed[] = [
                'id'   => 'style_unreadable',
                'msg'  => sprintf(
                    'Style `%s` is neither {selector, css} nor a map of CSS properties, so it cannot be stored in a form Etch can render.',
                    (string) $style_id
                ),
                'path' => "styles.{$style_id}",
            ];
            continue;
        }

        $selector = (string) $normalized['style']['selector'];
        $css      = (string) $normalized['style']['css'];
        $path     = "styles.{$style_id}";

        // BEM (skip element / variable selectors that are not class selectors)
        if ($selector !== '' && str_starts_with($selector, '.')) {
            if (!nibwp_etchwp_validate_bem($selector, $brand)) {
                $failed[] = [
                    'id'   => 'bem_invalid',
                    'msg'  => sprintf('Selector %s does not match {brand}-{component}__{element}[--modifier] grammar (brand=%s).', $selector, $brand !== '' ? $brand : '(unset)'),
                    'path' => $path . '.selector',
                ];
            }
        }

        // Token rules
        $failed = array_merge($failed, nibwp_etchwp_validate_tokens($css, $path . '.css'));

        // A name the site does not define renders only its fallback, and a real
        // name can still be the wrong one for the surface it sits on.
        if ($site_tokens !== []) {
            $warnings = array_merge($warnings, nibwp_etchwp_validate_site_tokens($css, $path . '.css', $site_tokens, $declared_props));
            $warnings = array_merge($warnings, nibwp_etchwp_warn_token_roles($css, $path . '.css', $site_tokens));
        }

        // No clamp() on font-size
        $failed = array_merge($failed, nibwp_etchwp_validate_no_clamp_font_size($css, $path . '.css'));

        // Hardcoded font-size (e.g. `font-size: 24px` outside var())
        $failed = array_merge($failed, nibwp_etchwp_validate_hardcoded_font_size($css, $path . '.css'));

        // Hardcoded color (hex/rgb/hsl outside var() fallback and not in brand allowlist)
        $failed = array_merge($failed, nibwp_etchwp_validate_hardcoded_color($css, $path . '.css', $color_allow));

        // ACSS-absent-but-tokens-used: only police when caller explicitly told us ACSS is off.
        if ($acss_active === false) {
            $failed = array_merge($failed, nibwp_etchwp_validate_acss_tokens_when_absent($css, $path . '.css'));
        }
    }

    // 3) Style hoist for wp:html sub-blocks
    $failed = array_merge($failed, nibwp_etchwp_validate_style_hoist($payload));

    // 4) Form shortcode presence when source had <form>
    if (!empty($ctx['has_raw_html_block']) && !empty($ctx['source_html'])
        && stripos((string) $ctx['source_html'], '<form') !== false
    ) {
        $failed = array_merge($failed, nibwp_etchwp_validate_form_shortcode($payload));
    }

    // 5) Raw <style> tag inside any block content.
    $failed = array_merge($failed, nibwp_etchwp_validate_raw_style_tag($payload));

    // 6) External stylesheet / @import.
    $failed = array_merge($failed, nibwp_etchwp_validate_external_stylesheet($payload));

    // 7) Missing brand prefix on wp:html inner classes.
    if ($brand !== '') {
        $failed = array_merge($failed, nibwp_etchwp_validate_missing_brand_prefix($payload, $brand));
    }

    // 8) Component property references — every {props.X} in the block tree
    // must match a declared property in the relevant component definition.
    $failed = array_merge($failed, nibwp_etchwp_validate_component_refs($payload));

    // 9) Dynamic-data modifiers — warn (non-blocking) on any modifier not in the
    // Etch catalog, so hallucinated transforms surface before persist.
    $warnings = array_merge($warnings, nibwp_etchwp_validate_modifiers($payload));

    // 10) innerContent consistency — serialize_block() emits one child per null
    // chunk in innerContent, so a count mismatch with innerBlocks would drop
    // siblings on write (the "first-child spine" bug). The persister rebuilds
    // innerContent authoritatively, so this is a warning, not a hard fail: it
    // surfaces a bad innerContent so dry-run matches what actually persists.
    $warnings = array_merge($warnings, nibwp_etchwp_validate_inner_content($payload));

    // Decorate every failed entry with a copy-paste fix_hint.
    $failed = array_map(static function (array $item): array {
        if (!array_key_exists('fix_hint', $item) || $item['fix_hint'] === '') {
            $item['fix_hint'] = nibwp_etchwp_fix_hint_for($item);
        }
        return $item;
    }, $failed);

    return [
        'passed'   => count($failed) === 0,
        'failed'   => array_values($failed),
        'warnings' => array_values($warnings),
    ];
}

/**
 * Flag any node whose innerContent null-count != innerBlocks count.
 * serialize_block() emits one innerBlock per null chunk, so a mismatch silently
 * drops siblings. Warning-level: the persister rebuilds innerContent from
 * innerBlocks before writing, so this never truncates a real write — it just
 * tells the agent its innerContent shape is off (and keeps dry-run honest).
 *
 * @param array<string,mixed> $payload
 * @return array<int, array<string,string>>
 */
function nibwp_etchwp_validate_inner_content(array $payload): array
{
    $out = [];
    nibwp_etchwp_walk_inner_content($payload['gutenbergBlock'] ?? null, 'gutenbergBlock', $out);
    return $out;
}

/**
 * @param mixed                          $node
 * @param array<int, array<string,string>> $out
 */
function nibwp_etchwp_walk_inner_content($node, string $path, array &$out): void
{
    if (!is_array($node) || !isset($node['blockName'])) {
        return;
    }
    $children    = (array) ($node['innerBlocks'] ?? []);
    $child_count = count($children);
    if ($child_count > 0 && isset($node['innerContent']) && is_array($node['innerContent'])) {
        $nulls = count(array_filter($node['innerContent'], static fn ($c) => $c === null));
        if ($nulls !== $child_count) {
            $out[] = [
                'id'   => 'inner_content_mismatch',
                'msg'  => sprintf(
                    'innerContent has %1$d null slot(s) but the node has %2$d innerBlocks — serialize_block() would emit only %3$d. The persister rebuilds it on write; fix or omit innerContent so dry-run matches the write.',
                    $nulls,
                    $child_count,
                    min($nulls, $child_count),
                ),
                'path' => $path,
            ];
        }
    }
    foreach ($children as $i => $child) {
        nibwp_etchwp_walk_inner_content($child, $path . '.innerBlocks[' . $i . ']', $out);
    }
}

/**
 * Collect every author-supplied string from a block tree (content, tag,
 * attributes, conditionString) into $out — recursively through innerBlocks.
 *
 * @param mixed         $block
 * @param array<int,string> $out
 */
function nibwp_etchwp_collect_binding_strings($block, array &$out): void
{
    if (!is_array($block)) {
        return;
    }
    $attrs = $block['attrs'] ?? [];
    if (is_array($attrs)) {
        foreach (['content', 'tag', 'conditionString'] as $k) {
            if (isset($attrs[$k]) && is_string($attrs[$k])) {
                $out[] = $attrs[$k];
            }
        }
        $a = $attrs['attributes'] ?? [];
        if (is_array($a)) {
            foreach ($a as $v) {
                if (is_string($v)) {
                    $out[] = $v;
                }
            }
        }
    }
    foreach ((array) ($block['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_collect_binding_strings($child, $out);
    }
}

/**
 * Warn (non-blocking) when a dynamic-data binding chains a modifier that is not
 * in the Etch catalog (NIBWP_ETCHWP_KNOWN_MODIFIERS). Only inspects `{...}`
 * spans, and only `.name(` calls inside them — property access like
 * `{item.permalink.relative}` is ignored.
 *
 * @return array<int,array{id:string,msg:string}>
 */
function nibwp_etchwp_validate_modifiers(array $payload): array
{
    $strings = [];
    if (isset($payload['gutenbergBlock'])) {
        nibwp_etchwp_collect_binding_strings($payload['gutenbergBlock'], $strings);
    }
    foreach ((array) ($payload['components'] ?? []) as $comp) {
        foreach ((array) (($comp['blocks'] ?? [])) as $b) {
            nibwp_etchwp_collect_binding_strings($b, $strings);
        }
    }

    $known = array_map('strtolower', NIBWP_ETCHWP_KNOWN_MODIFIERS);
    $warnings = [];
    $seen = [];
    foreach ($strings as $s) {
        if (!preg_match_all('/\{([^{}]*)\}/', $s, $braces)) {
            continue;
        }
        foreach ($braces[1] as $expr) {
            if (!preg_match_all('/\.([a-zA-Z][a-zA-Z0-9]*)\s*\(/', $expr, $mm)) {
                continue;
            }
            foreach ($mm[1] as $name) {
                if (!in_array(strtolower($name), $known, true) && !isset($seen[$name])) {
                    $seen[$name] = true;
                    $warnings[] = [
                        'id'  => 'unknown_modifier',
                        'msg' => sprintf('Dynamic-data modifier "%s()" is not in the Etch modifier catalog — verify it exists or remove it (see references/dynamic-data.md).', $name),
                    ];
                }
            }
        }
    }
    return $warnings;
}

/**
 * BEM grammar — {brand}-{component}__{element}[--modifier].
 *
 * Accepts compound selectors (`.foo.bar`, `.foo:hover`, etc.) but only validates
 * the first class atom — that is sufficient to detect generic `.btn`, `.card`,
 * `.heading` violations and prefix mismatch.
 */
function nibwp_etchwp_validate_bem(string $selector, string $brand): bool
{
    $trimmed = trim($selector);
    if ($trimmed === '' || $trimmed[0] !== '.') {
        return true; // Non-class selectors are out of scope (e.g. `:root`, `body`).
    }
    // Pull the first class atom from the selector.
    if (!preg_match('/^\.([a-z][a-z0-9-]*(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?)/i', $trimmed, $m)) {
        return false;
    }
    $class = $m[1];

    // Brand prefix check. __libraryMeta.brand is PascalCase ("Alpha", "Luxe
    // Horizon") while BEM classes are slugs ("alpha-…", "luxe-horizon-…"), so
    // compare on the slug — otherwise a payload that passes with an explicit
    // lowercase brand fails at the persist gate, which derives brand from meta.
    if ($brand !== '') {
        $brand_slug = function_exists('sanitize_title')
            ? sanitize_title($brand)
            : strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $brand) ?? '', '-'));
        if ($brand_slug !== '' && !str_starts_with($class, $brand_slug . '-')) {
            return false;
        }
    }

    // Strict BEM shape: word-with-dashes optionally __element optionally --modifier.
    return (bool) preg_match('/^[a-z][a-z0-9]*-[a-z0-9-]+(__[a-z0-9-]+)?(--[a-z0-9-]+)?$/', $class);
}

/**
 * Undefined names the playbook used to teach, with what this site calls them.
 * Suggestions are only offered when the site actually defines them.
 */
const NIBWP_ETCHWP_TOKEN_RENAMES = [
    '--surface-light'    => ['--neutral-ultra-light', '--white'],
    '--surface-dark'     => ['--bg-dark', '--neutral-ultra-dark'],
    '--text-muted'       => ['--text-dark-muted', '--text-light-muted'],
    '--heading-color'    => ['--text-dark'],
    '--footer-text'      => ['--text-light-muted'],
    '--radius-full'      => ['--radius-circle'],
    '--content-padding'  => ['--gutter', '--section-gutter'],
    '--leading-snug'     => ['--heading-line-height'],
    '--leading-normal'   => ['--text-line-height'],
    '--leading-relaxed'  => ['--text-line-height'],
    '--card-gap'         => ['--grid-gap', '--content-gap'],
    '--section-padding-y'=> ['--section-space-m'],
    '--base-medium'      => ['--neutral-semi-dark', '--text-dark-muted'],
    '--action'           => ['--primary', '--focus-color'],
    '--action-hover'     => ['--primary-hover'],
];

/**
 * Flag var() names this site does not define.
 *
 * A warning, not a failure. A brand value the design system has no token for
 * is legitimately written as var(--name, #hex) so it can be defined later, and
 * failing it would spend one of the agent's three validation attempts on
 * something that renders exactly as intended.
 *
 * Every token carries a fallback, so an undefined one never errors in the
 * browser: it quietly renders the fallback, and a page that validated comes
 * out in colours and spacing nobody chose. A customer's cards got light-blue
 * backgrounds and no visible borders that way. The site's own ACSS file is the
 * list, so this holds for whichever ACSS version the site runs.
 *
 * @param array<string,string> $site_tokens name => value
 * @param array<string,bool>   $declared    custom properties the payload declares itself
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_site_tokens(string $css, string $path, array $site_tokens, array $declared): array
{
    $issues = [];
    if ($css === '' || !preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $matches)) {
        return $issues;
    }

    foreach (array_unique(array_map('strtolower', $matches[1])) as $name) {
        if (isset($site_tokens[$name]) || isset($declared[$name])
            || str_starts_with($name, '--wp--') || str_starts_with($name, '--bo-') || str_starts_with($name, '--etch-')) {
            continue;
        }

        $issues[] = [
            'id'   => 'token_not_on_site',
            'msg'  => sprintf(
                '%s is not defined by Automatic.css on this site, so the page renders only its fallback. Use a token this site defines instead: %s.',
                $name,
                implode(', ', nibwp_etchwp_nearest_tokens($name, $site_tokens))
            ),
            'path' => $path,
        ];
    }

    return $issues;
}

/**
 * The tokens this site defines that most likely mean what $name was reaching for.
 *
 * @param array<string,string> $site_tokens
 * @return array<int,string>
 */
function nibwp_etchwp_nearest_tokens(string $name, array $site_tokens, int $limit = 3): array
{
    $known = array_values(array_filter(
        NIBWP_ETCHWP_TOKEN_RENAMES[$name] ?? [],
        static fn(string $candidate): bool => isset($site_tokens[$candidate])
    ));
    if ($known !== []) {
        return $known;
    }

    // ponytail: edit distance with a bonus for the same role word ("-muted",
    // "-light"); good enough to point at the right family, not a thesaurus.
    $suffix = (string) strrchr($name, '-');
    $scores = [];
    foreach ($site_tokens as $candidate => $value) {
        // Colour channels (--primary-h: 195) and generated variants are not
        // something anyone should type into a stylesheet.
        if (preg_match('/^\d+(\.\d+)?%?$/', trim((string) $value)) || preg_match('/-(hex|hsl|rgb)$|-trans-\d0$/', $candidate)) {
            continue;
        }
        $score = levenshtein($name, $candidate);
        if ($suffix !== '' && str_ends_with($candidate, $suffix)) {
            $score -= 4;
        }
        $scores[$candidate] = $score;
    }
    asort($scores);

    return array_slice(array_keys($scores), 0, $limit);
}

/**
 * Warn when a real token is used for the wrong kind of surface.
 *
 * On ACSS 3 --border-color-light is white at 20%: right on a dark section,
 * invisible on a white card. Whether a given card is dark cannot be known from
 * CSS alone, so this is a warning with the site's actual value in it.
 *
 * @param array<string,string> $site_tokens
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_warn_token_roles(string $css, string $path, array $site_tokens): array
{
    if (!preg_match('/\b(?:border|outline)[a-z-]*\s*:[^;]*var\(\s*--border-color-light\b/i', $css)) {
        return [];
    }

    $value = strtolower((string) ($site_tokens['--border-color-light'] ?? ''));
    if (!str_contains($value, 'white') && !preg_match('/255\s*,\s*255\s*,\s*255/', $value)) {
        return [];
    }

    return [[
        'id'   => 'border_token_for_dark_surfaces',
        'msg'  => sprintf(
            'On this site --border-color-light is %s: a light border meant for dark backgrounds. On a light surface it is invisible; use --border-color-dark there.',
            $value
        ),
        'path' => $path,
    ]];
}

/**
 * Token validation. Extracts every `var(--name, fallback)` reference and rejects
 * forbidden token names (Tailwind ramps + display aliases + numeric blocklist).
 *
 * Also rejects bare `var(--name)` without a fallback, except for `--bo-*` which
 * is used raw by BookingOptimiser convention (acss-tokens.md § Brand-scoped).
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_tokens(string $css, string $path): array
{
    $issues = [];

    if ($css === '') {
        return $issues;
    }

    // Match every var(--token) or var(--token, fallback)
    if (!preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*(?:,\s*([^)]+))?\)/i', $css, $matches, PREG_SET_ORDER)) {
        return $issues;
    }

    foreach ($matches as $m) {
        $name     = strtolower(trim($m[1]));
        $fallback = isset($m[2]) ? trim($m[2]) : '';

        // Forbidden invented token?
        if (in_array($name, NIBWP_ETCHWP_FORBIDDEN_TOKENS, true)) {
            $issues[] = [
                'id'   => 'invented_token',
                'msg'  => sprintf('Token %s is forbidden (invented Tailwind ramp / display alias). See acss-tokens.md § Forbidden invented tokens.', $name),
                'path' => $path,
            ];
            continue;
        }
        foreach (NIBWP_ETCHWP_FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                $issues[] = [
                    'id'   => 'invented_token',
                    'msg'  => sprintf('Token %s matches forbidden numeric-ramp pattern %s.', $name, $pattern),
                    'path' => $path,
                ];
                continue 2;
            }
        }

        // Missing fallback — allowed for --bo-* only.
        if ($fallback === '' && !str_starts_with($name, '--bo-')) {
            $issues[] = [
                'id'   => 'missing_fallback',
                'msg'  => sprintf('var(%s) has no fallback. Use var(%s, sensible-default). Only --bo-* tokens are allowed without fallback.', $name, $name),
                'path' => $path,
            ];
        }
    }

    return $issues;
}

/**
 * Reject clamp() anywhere in a font-size value — including inside the fallback
 * slot of var(). Layout properties are unaffected; only font-size is policed.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_no_clamp_font_size(string $css, string $path): array
{
    $issues = [];
    if ($css === '') {
        return $issues;
    }

    // Look for `font-size:` declarations and any clamp() in their value.
    // We tolerate nested selectors and @container rules by scanning the full
    // CSS text for declarations matching the pattern.
    if (!preg_match_all('/font-size\s*:\s*([^;{}]+)[;}]/i', $css, $matches, PREG_SET_ORDER)) {
        return $issues;
    }

    foreach ($matches as $m) {
        $value = trim($m[1]);
        if (stripos($value, 'clamp(') !== false) {
            $issues[] = [
                'id'   => 'clamp_font_size',
                'msg'  => sprintf('font-size value `%s` contains clamp(). Switch to a smaller --text-* token at the breakpoint instead. See anti-patterns.md §13.', $value),
                'path' => $path,
            ];
        }
    }

    return $issues;
}

/**
 * Style hoist enforcement. Every class used inside a wp:html / etch/raw-html
 * block must be referenced by at least one wp:etch/element's attrs.styles.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_style_hoist(array $payload): array
{
    $raw_html_classes = [];
    $referenced_style_ids = [];

    nibwp_etchwp_walk_blocks(
        $payload['gutenbergBlock'] ?? null,
        $raw_html_classes,
        $referenced_style_ids,
    );

    $issues = [];
    foreach ($raw_html_classes as $class) {
        $style_id = $class . '-style'; // Convention: BEM class `foo__bar` → style id `foo__bar-style`.
        $alt_id   = $class;            // Some libraries store style IDs as the class itself.
        if (!in_array($style_id, $referenced_style_ids, true) && !in_array($alt_id, $referenced_style_ids, true)) {
            $issues[] = [
                'id'   => 'missing_style_hoist',
                'msg'  => sprintf('Class `%s` is used inside a wp:html block but no wp:etch/element references `%s` or `%s` in attrs.styles. Add a hidden style-hoist block (anti-patterns.md §15).', $class, $style_id, $alt_id),
                'path' => 'gutenbergBlock',
            ];
        }
    }
    return $issues;
}

/**
 * Walk the gutenbergBlock tree, collecting:
 *  - classes appearing inside wp:html / etch/raw-html blocks (`$raw_html_classes`)
 *  - every style ID referenced via attrs.styles[] (`$referenced_style_ids`)
 *
 * @param mixed             $node
 * @param array<int,string> $raw_html_classes  (in/out)
 * @param array<int,string> $referenced_style_ids (in/out)
 */
function nibwp_etchwp_walk_blocks($node, array &$raw_html_classes, array &$referenced_style_ids): void
{
    if (!is_array($node)) {
        return;
    }
    $name  = (string) ($node['blockName'] ?? '');
    $attrs = (array)  ($node['attrs']     ?? []);

    // Track every style ID referenced.
    foreach ((array) ($attrs['styles'] ?? []) as $sid) {
        if (is_string($sid) && $sid !== '') {
            $referenced_style_ids[] = $sid;
        }
    }
    // Etch artifacts sometimes carry styles inside attrs.metadata.etchData.styles too.
    $meta_styles = $attrs['metadata']['etchData']['styles'] ?? null;
    if (is_array($meta_styles)) {
        foreach ($meta_styles as $sid) {
            if (is_string($sid) && $sid !== '') {
                $referenced_style_ids[] = $sid;
            }
        }
    }

    // Capture classes from raw-HTML blocks.
    if ($name === 'core/html' || $name === 'etch/raw-html') {
        $inner = (string) ($node['innerHTML'] ?? '');
        if ($inner === '') {
            // wp:html sometimes carries the markup in innerContent (mixed array).
            foreach ((array) ($node['innerContent'] ?? []) as $piece) {
                if (is_string($piece)) {
                    $inner .= $piece;
                }
            }
        }
        if ($inner !== '' && preg_match_all('/class\s*=\s*"([^"]+)"/i', $inner, $matches)) {
            foreach ($matches[1] as $class_attr) {
                foreach (preg_split('/\s+/', trim($class_attr)) as $class) {
                    if ($class !== '' && !in_array($class, $raw_html_classes, true)) {
                        $raw_html_classes[] = $class;
                    }
                }
            }
        }
    }

    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_walk_blocks($child, $raw_html_classes, $referenced_style_ids);
    }
}

/**
 * Quick top-level scan: does the payload contain any wp:html / etch/raw-html block?
 */
function nibwp_etchwp_payload_has_wp_html(array $payload): bool
{
    $found = false;
    nibwp_etchwp_walk_for_wp_html($payload['gutenbergBlock'] ?? null, $found);
    return $found;
}

function nibwp_etchwp_walk_for_wp_html($node, bool &$found): void
{
    if ($found || !is_array($node)) {
        return;
    }
    $name = (string) ($node['blockName'] ?? '');
    if ($name === 'core/html' || $name === 'etch/raw-html') {
        $found = true;
        return;
    }
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_walk_for_wp_html($child, $found);
    }
}

/**
 * Form-shortcode requirement: if the source HTML contained <form>, the output
 * tree MUST include at least one core/shortcode block.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_form_shortcode(array $payload): array
{
    $found = false;
    nibwp_etchwp_walk_for_shortcode($payload['gutenbergBlock'] ?? null, $found);
    if ($found) {
        return [];
    }
    return [[
        'id'   => 'missing_form_shortcode',
        'msg'  => 'Source HTML contained <form> but the output has no core/shortcode block. Detect the installed form plugin via nibwp/forms-manage and wrap its shortcode in a core/shortcode block (Etch registers no shortcode block of its own). See checklists/form.md.',
        'path' => 'gutenbergBlock',
    ]];
}

function nibwp_etchwp_walk_for_shortcode($node, bool &$found): void
{
    if ($found || !is_array($node)) {
        return;
    }
    $name = (string) ($node['blockName'] ?? '');
    // Only core/shortcode. Etch registers no shortcode block, so an
    // etch/shortcode here would satisfy this rule with a block that renders
    // as nothing — the failure this rule exists to prevent.
    if ($name === 'core/shortcode') {
        $found = true;
        return;
    }
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_walk_for_shortcode($child, $found);
    }
}

/**
 * __libraryMeta shape + slug/folder/id consistency check.
 *
 * @return array{failed:array<int,array{id:string,msg:string,path:string}>,warnings:array<int,array{id:string,msg:string,path:string}>}
 */
function nibwp_etchwp_validate_manifest(array $payload): array
{
    $failed   = [];
    $warnings = [];

    $meta = (array) ($payload['__libraryMeta'] ?? []);
    $required = ['brand', 'type', 'category', 'tags', 'name', 'description'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $meta) || $meta[$field] === '' || $meta[$field] === []) {
            $failed[] = [
                'id'   => 'libmeta_missing',
                'msg'  => sprintf('__libraryMeta.%s is required.', $field),
                'path' => '__libraryMeta.' . $field,
            ];
        }
    }
    if (isset($meta['tags']) && is_array($meta['tags']) && count($meta['tags']) < 3) {
        $warnings[] = [
            'id'   => 'libmeta_few_tags',
            'msg'  => '__libraryMeta.tags has fewer than 3 entries. Add more for better discoverability.',
            'path' => '__libraryMeta.tags',
        ];
    }
    if (isset($meta['name'], $meta['category'])) {
        $expected_slug = function_exists('sanitize_title') ? sanitize_title((string) $meta['name']) : strtolower(str_replace(' ', '-', (string) $meta['name']));
        if (isset($meta['slug']) && (string) $meta['slug'] !== $expected_slug) {
            $failed[] = [
                'id'   => 'libmeta_slug_mismatch',
                'msg'  => sprintf('__libraryMeta.slug `%s` is not the kebab-case of name `%s` (`%s`).', $meta['slug'], $meta['name'], $expected_slug),
                'path' => '__libraryMeta.slug',
            ];
        }
    }
    return ['failed' => $failed, 'warnings' => $warnings];
}

/**
 * Strip every `var(...)` reference (and its fallback slot) from the CSS so
 * subsequent literal-checks don't false-positive on values that ARE inside
 * a var() fallback. Handles balanced parens up to one level of nesting.
 */
function nibwp_etchwp_strip_var_calls(string $css): string
{
    // Two passes — covers `var(--a, var(--b, #fff))` style nesting.
    for ($i = 0; $i < 2; $i++) {
        $css = (string) preg_replace('/var\(\s*--[a-z0-9-]+\s*(?:,\s*[^()]*)?\)/i', '', $css);
    }
    return $css;
}

/**
 * Reject `font-size: <literal>` outside a var() reference.
 *
 * Allowed: `font-size: var(--text-l, 1.125rem)`, `font-size: inherit`, `font-size: 1em` is
 * REJECTED — even em-based literals defeat the token system.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_hardcoded_font_size(string $css, string $path): array
{
    $issues = [];
    if ($css === '') {
        return $issues;
    }
    if (!preg_match_all('/font-size\s*:\s*([^;{}]+)[;}]/i', $css, $matches, PREG_SET_ORDER)) {
        return $issues;
    }
    foreach ($matches as $m) {
        $value = trim($m[1]);
        if ($value === '') {
            continue;
        }
        // Strip every var(...) so we only look at residual literals.
        $residual = trim(nibwp_etchwp_strip_var_calls($value));
        if ($residual === '') {
            continue; // Pure var() — fine.
        }
        // Allowed keywords with no literal sizing.
        $keywords = ['inherit', 'initial', 'unset', 'revert', 'revert-layer', 'currentcolor', '0'];
        if (in_array(strtolower($residual), $keywords, true)) {
            continue;
        }
        // Detect literal length / percentage units.
        if (preg_match('/\b\d+(?:\.\d+)?\s*(px|rem|em|pt|%|vw|vh|svh|lvh|dvh|cqi|cqb|cqw|cqh)\b/i', $residual)) {
            $issues[] = [
                'id'   => 'hardcoded_font_size',
                'msg'  => sprintf('font-size `%s` is a hardcoded literal. Use a --text-* token: var(--text-l, %s).', $value, $residual),
                'path' => $path,
            ];
        }
    }
    return $issues;
}

/**
 * Reject hardcoded color values on color-bearing properties when the value
 * is NOT inside a var() fallback AND not in the brand color allowlist.
 *
 * @param array<int,string> $allowlist Lowercased literal colors permitted as-is.
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_hardcoded_color(string $css, string $path, array $allowlist): array
{
    $issues = [];
    if ($css === '') {
        return $issues;
    }
    $properties = implode('|', array_map(static fn ($p) => preg_quote($p, '/'), NIBWP_ETCHWP_COLOR_PROPERTIES));
    if (!preg_match_all('/(?<![a-z-])(' . $properties . ')\s*:\s*([^;{}]+)[;}]/i', $css, $matches, PREG_SET_ORDER)) {
        return $issues;
    }
    foreach ($matches as $m) {
        $prop  = strtolower(trim($m[1]));
        $value = trim($m[2]);
        if ($value === '') {
            continue;
        }
        // Strip every var(...) call so we only look at residual literals.
        $residual = trim(nibwp_etchwp_strip_var_calls($value));
        $residual = trim((string) preg_replace_callback(
            '/\b(?:rgba?|hsla?)\s*\([^()]*\)/i',
            static fn (array $c): string => nibwp_etchwp_is_translucent_neutral($c[0]) ? '' : $c[0],
            $residual
        ));
        if ($residual === '') {
            continue; // Pure var() — fine.
        }
        $found_literal = '';
        if (preg_match('/#[0-9a-f]{3,8}\b/i', $residual, $cm)) {
            $found_literal = strtolower($cm[0]);
        } elseif (preg_match('/\brgba?\s*\(\s*[\d.,\s%\/]+\)/i', $residual, $cm)) {
            $found_literal = strtolower(preg_replace('/\s+/', '', $cm[0]));
        } elseif (preg_match('/\bhsla?\s*\(\s*[\d.,\s%\/]+\)/i', $residual, $cm)) {
            $found_literal = strtolower(preg_replace('/\s+/', '', $cm[0]));
        }
        if ($found_literal === '') {
            continue;
        }
        // Brand allowlist (lowercased; also accepts the normalised rgb()/hsl()).
        if (in_array($found_literal, $allowlist, true)) {
            continue;
        }
        $issues[] = [
            'id'   => 'hardcoded_color',
            'msg'  => sprintf('%s uses hardcoded color `%s`. Use var(--primary, %s) or add to brand allowlist (nibwp_user_defaults.etchwp_brand_color).', $prop, $found_literal, $found_literal),
            'path' => $path,
        ];
    }
    return $issues;
}

/**
 * ACSS-absent branch: when ACSS is not installed but the payload references
 * ACSS-namespace tokens (var(--text-*), var(--space-*), etc.), reject so the
 * agent must (a) install ACSS, (b) bake fallback literals, or (c) use a
 * brand stylesheet.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_acss_tokens_when_absent(string $css, string $path): array
{
    $issues = [];
    if ($css === '') {
        return $issues;
    }
    if (!preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $matches)) {
        return $issues;
    }
    $acss_prefixes = ['--text-', '--space-', '--section-space-', '--content-', '--card-', '--leading-', '--radius', '--base-', '--primary', '--secondary', '--heading-', '--surface-', '--border-color', '--h2', '--h3', '--white'];
    foreach ($matches[1] as $name) {
        $low = strtolower(trim((string) $name));
        foreach ($acss_prefixes as $px) {
            if (str_starts_with($low, $px)) {
                $issues[] = [
                    'id'   => 'acss_absent_tokens_used',
                    'msg'  => sprintf('ACSS not detected on this site but token `%s` is used. Either install Automatic.css, bake the fallback literal in place of the var(), or use a non-ACSS brand stylesheet.', $low),
                    'path' => $path,
                ];
                break;
            }
        }
    }
    return $issues;
}

/**
 * Reject raw <style> tags inside any block content (innerHTML / innerContent).
 * Etch enqueues styles from the styles dict; inline <style> tags bypass that
 * pipeline and produce inconsistent results.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_raw_style_tag(array $payload): array
{
    $strings = [];
    nibwp_etchwp_collect_inner_html($payload['gutenbergBlock'] ?? null, $strings);
    $issues = [];
    foreach ($strings as $i => $html) {
        if (preg_match('/<style\b/i', $html)) {
            $issues[] = [
                'id'   => 'raw_style_tag',
                'msg'  => 'Block content contains a raw <style> tag. Move the CSS into the styles dict — Etch enqueues styles from there.',
                'path' => 'gutenbergBlock.innerHTML[' . $i . ']',
            ];
        }
    }
    return $issues;
}

/**
 * Reject external stylesheets / @import inside block content.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_external_stylesheet(array $payload): array
{
    $strings = [];
    nibwp_etchwp_collect_inner_html($payload['gutenbergBlock'] ?? null, $strings);
    $issues = [];
    foreach ($strings as $i => $html) {
        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?stylesheet/i', $html)) {
            $issues[] = [
                'id'   => 'external_stylesheet',
                'msg'  => 'Block content contains an external <link rel="stylesheet">. Hoist the rules into the styles dict — external links break Etch enqueue + Pixel-perfect previews.',
                'path' => 'gutenbergBlock.innerHTML[' . $i . ']',
            ];
        }
        if (stripos($html, '@import') !== false) {
            $issues[] = [
                'id'   => 'external_stylesheet',
                'msg'  => 'Block content contains @import. Hoist the rules into the styles dict.',
                'path' => 'gutenbergBlock.innerHTML[' . $i . ']',
            ];
        }
    }
    // Also scan styles dict for @import.
    $styles = (array) ($payload['styles'] ?? []);
    foreach ($styles as $sid => $style) {
        if (!is_array($style)) {
            continue;
        }
        $css = (string) ($style['css'] ?? '');
        if (stripos($css, '@import') !== false) {
            $issues[] = [
                'id'   => 'external_stylesheet',
                'msg'  => sprintf('styles.%s.css uses @import. Inline the rules instead.', $sid),
                'path' => "styles.{$sid}.css",
            ];
        }
    }
    return $issues;
}

/**
 * Every class atom used inside a wp:html / etch/raw-html block must start
 * with the brand prefix, OR be in the utility allowlist.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_missing_brand_prefix(array $payload, string $brand): array
{
    $issues = [];
    $raw_html_classes = [];
    $unused = [];
    nibwp_etchwp_walk_blocks($payload['gutenbergBlock'] ?? null, $raw_html_classes, $unused);

    /** @var array<int,string> $allowlist */
    $allowlist = (array) apply_filters('nibwp_etchwp_utility_class_allowlist', NIBWP_ETCHWP_UTILITY_ALLOWLIST);

    foreach ($raw_html_classes as $class) {
        if ($class === '' || str_starts_with($class, $brand . '-')) {
            continue;
        }
        if (in_array($class, $allowlist, true)) {
            continue;
        }
        // wp-* / wp_block-* are WordPress core block classes — out of scope.
        if (str_starts_with($class, 'wp-') || str_starts_with($class, 'wp_')) {
            continue;
        }
        $issues[] = [
            'id'   => 'missing_brand_prefix',
            'msg'  => sprintf('Class `%s` inside wp:html lacks the brand prefix `%s-`. Rename to `%s-%s` or add to nibwp_etchwp_utility_class_allowlist filter.', $class, $brand, $brand, $class),
            'path' => 'gutenbergBlock.innerHTML',
        ];
    }
    return $issues;
}

/**
 * Collect innerHTML / innerContent strings across the block tree.
 *
 * @param mixed             $node
 * @param array<int,string> $out (in/out)
 */
function nibwp_etchwp_collect_inner_html($node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }
    $inner = (string) ($node['innerHTML'] ?? '');
    if ($inner !== '') {
        $out[] = $inner;
    }
    foreach ((array) ($node['innerContent'] ?? []) as $piece) {
        if (is_string($piece) && $piece !== '') {
            $out[] = $piece;
        }
    }
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_collect_inner_html($child, $out);
    }
}

/**
 * Map a failed-rule id to a short, copy-paste-ready fix hint. Pure dispatcher
 * — depends only on the item id + path. Agents read fix_hint to patch the
 * payload without further user prompting.
 */
function nibwp_etchwp_fix_hint_for(array $item): string
{
    $id = (string) ($item['id'] ?? '');
    switch ($id) {
        case 'clamp_font_size':
            return 'Replace clamp(...) with a --text-* token: `font-size: var(--text-l, 1.125rem);`. Switch to a smaller token inside @container queries for breakpoints.';
        case 'hardcoded_font_size':
            return 'Wrap in a token: `font-size: var(--text-{xs|s|m|l|xl|xxl}, <literal>);`. Pick the token closest to the literal size from acss-tokens.md.';
        case 'hardcoded_color':
            return 'Wrap in a token: `color: var(--primary, <literal>);` OR add the literal to wp_options.nibwp_user_defaults.etchwp_brand_color (preferences ability).';
        case 'raw_style_tag':
            return 'Delete the inline <style> tag. Move every selector + body into the payload `styles` dict; reference style IDs from wp:etch/element attrs.styles.';
        case 'external_stylesheet':
            return 'Delete the <link rel="stylesheet"> / @import. Inline the rules into the payload `styles` dict.';
        case 'missing_brand_prefix':
            return 'Rename the class to start with the brand prefix (e.g. `etched-cta__title`). Utility hooks like is-active/has-error are exempt.';
        case 'element_without_class':
            return 'Add a BEM class to every element ({brand}-{component}__{element}) and a matching style referenced from attrs.styles. SVG primitives inside an icon are exempt.';
        case 'styles_missing':
            return 'Write the rules into payload.styles and reference each id from the element it styles via attrs.styles. A class alone renders nothing.';
        case 'style_unreferenced':
            return 'Add the style id to attrs.styles on the element it styles, or delete the style. Etch renders only what a block asks for.';
        case 'style_reference_dangling':
            return 'Define the style in payload.styles under that id, or remove the reference from attrs.styles.';
        case 'missing_style_hoist':
            return 'Add a hidden wp:etch/element with attrs.attributes.hidden=true and attrs.styles=["<class>-style"] so Etch enqueues the CSS for classes used in wp:html.';
        case 'invented_token':
            return 'Replace with a canonical token from acss-tokens.md (e.g. --base-ultra-light, --text-xxl). Tailwind/numeric ramps are forbidden.';
        case 'missing_fallback':
            return 'Add a sensible fallback: `var(--token, <literal>)`. Only --bo-* tokens may omit the fallback.';
        case 'bem_invalid':
            return 'Rewrite the selector to `.{brand}-{component}__{element}[--modifier]`. Brand prefix is mandatory.';
        case 'libmeta_missing':
            return 'Fill the required __libraryMeta field. brand/type/category/tags/name/description are all required.';
        case 'libmeta_slug_mismatch':
            return 'Set __libraryMeta.slug = sanitize_title(__libraryMeta.name).';
        case 'missing_form_shortcode':
            return 'Call nibwp/forms-manage action=list_plugins, ask the user which form plugin, emit a core/shortcode block wrapping the chosen plugin shortcode.';
        case 'acss_absent_tokens_used':
            return 'ACSS not active on this site. Choose: (a) install Automatic.css, (b) replace `var(--token, X)` with the literal X everywhere, or (c) use a non-ACSS brand stylesheet.';
        case 'component_undefined_property':
            return 'Either add the property to the component\'s `properties` (with a `key` equal to X, a `type` object and a default), OR delete the {props.X} reference from the block tree.';
        case 'component_instance_unknown_id':
            return 'Define the component in payload.components under this key, OR set attrs.ref to the id of a component defined there (or of an existing wp_block post).';
        case 'token_not_on_site':
            return 'Swap the token for one this site defines (the message lists the closest). load-skill-playbook returns the full list as site_tokens.';
        case 'svg_markup_ignored':
            return 'Rebuild the icon as etch/element tag "svg" with etch/element path/circle/rect children, or set attrs.attributes.src to an uploaded SVG attachment ID.';
        case 'slot_name_missing':
            return 'Rename slotName to name on both etch/slot-placeholder and etch/slot-content, with the same value.';
        case 'condition_unreadable':
            return 'Replace attrs.conditions with attrs.condition {leftHand, operator, rightHand} and add attrs.conditionString.';
        case 'component_empty':
            return 'Put the component\'s block tree under `blocks` - the key Etch imports components from.';
        case 'component_instance_missing_required_prop':
            return 'Set the missing property in the instance\'s attrs.attributes, OR declare a default on the component\'s properties[].default field.';
        default:
            return 'See msg for details.';
    }
}

/**
 * The blocks Etch actually registers.
 *
 * Verified against a running Etch 1.6.5 registry, not documentation. Used only
 * when the live registry cannot be read (dry-run on a host where Etch is not
 * loaded); when Etch IS loaded its own registry wins, so a future release that
 * adds or renames a block needs no change here.
 */
const NIBWP_ETCHWP_KNOWN_BLOCKS = [
    'etch/component',
    'etch/condition',
    'etch/dynamic-element',
    'etch/dynamic-image',
    'etch/element',
    'etch/loop',
    'etch/raw-html',
    'etch/slot-content',
    'etch/slot-placeholder',
    'etch/svg',
    'etch/text',
];

/**
 * Every `etch/*` block name this site can actually render.
 *
 * @return array<int,string>
 */
function nibwp_etchwp_renderable_blocks(): array
{
    if (class_exists('WP_Block_Type_Registry')) {
        $names = array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered());
        $etch  = array_values(array_filter($names, static fn(string $n): bool => str_starts_with($n, 'etch/')));
        if ($etch !== []) {
            return $etch;
        }
    }

    return NIBWP_ETCHWP_KNOWN_BLOCKS;
}

/**
 * Walk every block node in a payload tree, passing each to $fn.
 */
function nibwp_etchwp_walk_block_nodes($node, callable $fn): void
{
    if (!is_array($node)) {
        return;
    }

    if (isset($node['blockName'])) {
        $fn($node);
    }

    foreach (['innerBlocks', 'inner_blocks'] as $key) {
        foreach ((array) ($node[$key] ?? []) as $child) {
            nibwp_etchwp_walk_block_nodes($child, $fn);
        }
    }

    // A bare list of blocks rather than one block with children.
    if (!isset($node['blockName'])) {
        foreach ($node as $child) {
            if (is_array($child)) {
                nibwp_etchwp_walk_block_nodes($child, $fn);
            }
        }
    }
}

/**
 * Will Etch actually resolve what this payload describes?
 *
 * Every other rule in this file checks the payload against our own conventions
 * — BEM, tokens, manifest shape — and a payload can satisfy all of them while
 * Etch renders nothing at all from it. That gap is not theoretical: a customer
 * shipped a page of `etch/component` blocks carrying `componentId`, this
 * validator returned passed:true, the persister reported success, and the
 * section came out empty, because `etch/component` declares exactly two
 * attributes — `ref` (a wp_block post id) and `attributes` — and returns an
 * empty string when `ref` is null.
 *
 * So these rules check the payload against ETCH, not against us:
 *
 *   - a block name Etch does not register renders as nothing;
 *   - an `etch/component` that resolves to no component renders as nothing;
 *   - a `<style>` tag inside raw HTML is stripped by wp_kses and its CSS is
 *     left behind as visible body text.
 *
 * The rule these encode: never return passed:true for a payload whose visible
 * output would be empty or broken.
 *
 * @return array{failed:array<int,array{id:string,msg:string,path:string}>,warnings:array<int,array{id:string,msg:string,path:string}>}
 */
function nibwp_etchwp_validate_etch_resolvable(array $payload): array
{
    $failed   = [];
    $warnings = [];

    $tree = $payload['gutenbergBlock'] ?? null;
    if (!is_array($tree) || $tree === []) {
        return ['failed' => $failed, 'warnings' => $warnings];
    }

    $renderable = nibwp_etchwp_renderable_blocks();
    $components = (array) ($payload['components'] ?? []);
    $seen_bad   = [];

    // Component trees render too: a dead block or ref inside one is as blank
    // as one on the page.
    $trees = [$tree];
    foreach ($components as $key => $def) {
        foreach (nibwp_etchwp_component_blocks($def, (string) $key) as $block) {
            $trees[] = $block;
        }
    }

    // Can this install resolve a ref? Only when Etch is loaded here; a dry run
    // elsewhere must not fail a ref it simply cannot see.
    $can_check_refs = function_exists('get_post') && function_exists('did_action') && did_action('init');

    $check = static function (array $node) use (
        &$failed,
        &$warnings,
        &$seen_bad,
        $renderable,
        $components,
        $can_check_refs
    ): void {
        $name  = (string) ($node['blockName'] ?? '');
        $attrs = (array) ($node['attrs'] ?? []);

        // 1) A block Etch does not register renders as nothing at all.
        if (str_starts_with($name, 'etch/') && !in_array($name, $renderable, true) && !isset($seen_bad[$name])) {
            $seen_bad[$name] = true;
            $hint = $name === 'etch/shortcode'
                ? ' Etch has no shortcode block — use core/shortcode, which Etch renders and its builder accepts.'
                : '';
            $failed[] = [
                'id'   => 'block_unknown_to_etch',
                'msg'  => sprintf(
                    'Block "%s" is not registered by Etch on this site, so WordPress renders it as nothing and the page section comes out empty. Etch registers: %s.%s',
                    $name,
                    implode(', ', $renderable),
                    $hint
                ),
                'path' => 'gutenbergBlock',
            ];
        }

        if ($name !== 'etch/component') {
            // 2a) etch/svg renders an uploaded SVG named by attributes.src and
            //     nothing else. Markup handed to it is dropped and Etch draws
            //     its placeholder logo instead: four different icons came out
            //     as four identical logos on a customer's page.
            if ($name === 'etch/svg' && trim((string) ($attrs['attributes']['src'] ?? '')) === '') {
                $failed[] = [
                    'id'   => 'svg_markup_ignored',
                    'msg'  => 'etch/svg only renders an uploaded SVG named by attrs.attributes.src (an attachment ID); inline markup is ignored and Etch draws its placeholder logo. For an inline icon use an etch/element with tag "svg" whose innerBlocks are etch/element tags path, circle, rect, line, polyline, polygon or g carrying their attributes.',
                    'path' => 'gutenbergBlock',
                ];
            }

            // 2b) Slots are matched by attrs.name. Without it the placeholder
            //     returns an empty string and the slot content disappears.
            if (($name === 'etch/slot-placeholder' || $name === 'etch/slot-content') && trim((string) ($attrs['name'] ?? '')) === '') {
                $failed[] = [
                    'id'   => 'slot_name_missing',
                    'msg'  => sprintf(
                        '%s has no attrs.name%s, so Etch cannot match the slot and renders nothing for it. Set attrs.name to the slot name, the same on the placeholder and the content.',
                        $name,
                        isset($attrs['slotName']) ? ' (it has slotName, which Etch does not read)' : ''
                    ),
                    'path' => 'gutenbergBlock',
                ];
            }

            // 2c) Etch reads a condition object (and its string form), not a
            //     list of conditions.
            if ($name === 'etch/condition' && !is_array($attrs['condition'] ?? null) && trim((string) ($attrs['conditionString'] ?? '')) === '') {
                $failed[] = [
                    'id'   => 'condition_unreadable',
                    'msg'  => sprintf(
                        'etch/condition has no attrs.condition%s, so Etch cannot evaluate it. Use attrs.condition {leftHand, operator, rightHand} with attrs.conditionString, e.g. {"condition":{"leftHand":"props.showCta","operator":"isTruthy","rightHand":null},"conditionString":"props.showCta"}.',
                        isset($attrs['conditions']) ? ' (it has a conditions array, which Etch does not read)' : ''
                    ),
                    'path' => 'gutenbergBlock',
                ];
            }

            // 2) wp_kses strips <style> from raw HTML and leaves the CSS behind
            //    as visible text on the page.
            if ($name === 'etch/raw-html' || $name === 'core/html') {
                $html = (string) ($attrs['content'] ?? '') . (string) ($node['innerHTML'] ?? '');
                if (stripos($html, '<style') !== false) {
                    $failed[] = [
                        'id'   => 'style_tag_in_html',
                        'msg'  => sprintf(
                            'A <style> tag inside %s is removed by wp_kses before the page renders, and its CSS is left on the page as visible text. Move the rules into payload.styles, which the persister writes to Etch\'s own stylesheet.',
                            $name
                        ),
                        'path' => 'gutenbergBlock',
                    ];
                }
            }

            return;
        }

        // 3) An etch/component has to resolve to something. Etch reads `ref`
        //    (a wp_block post id) and nothing else. One naming a component
        //    defined in this payload - by `ref` or by our `componentId` - is
        //    resolvable because the persister mints the wp_block and rewrites
        //    the instance to it before writing.
        $ref = $attrs['ref'] ?? null;
        $cid = (string) ($attrs['componentId'] ?? '');

        if (nibwp_etchwp_local_component_key($attrs, $components) !== '') {
            return;
        }

        if (is_numeric($ref) && (int) $ref > 0) {
            if ($can_check_refs) {
                $post = get_post((int) $ref);
                if (!$post || $post->post_type !== 'wp_block') {
                    $failed[] = [
                        'id'   => 'component_ref_dead',
                        'msg'  => sprintf(
                            'etch/component references ref %d, which is neither a component defined in payload.components nor a wp_block component post on this site, so Etch renders nothing for it. Set ref to the id of a component defined in payload.components, or to an existing component post.',
                            (int) $ref
                        ),
                        'path' => 'gutenbergBlock',
                    ];
                }
            }

            return;
        }

        // An undefined componentId is left to component_instance_unknown_id,
        // so the caller gets one error for the mistake rather than two.
        if ($cid === '') {
            $failed[] = [
                'id'   => 'component_unresolvable',
                'msg'  => 'An etch/component block carries neither a `ref` nor a `componentId`. Etch resolves components by `ref` and renders an empty string without one, so this block would leave a blank space on the page. Set attrs.ref to the id of a component defined in payload.components, or to an existing wp_block component post.',
                'path' => 'gutenbergBlock',
            ];
        }
    };

    foreach ($trees as $subtree) {
        nibwp_etchwp_walk_block_nodes($subtree, $check);
    }

    return ['failed' => $failed, 'warnings' => $warnings];
}

/**
 * SVG primitives. These are drawing instructions inside an icon, not elements
 * anyone styles individually, and a real conversion is full of them.
 *
 * @var array<int,string>
 */
const NIBWP_ETCHWP_SVG_PRIMITIVES = [
    'path', 'circle', 'rect', 'ellipse', 'line', 'polyline', 'polygon',
    'g', 'defs', 'use', 'symbol', 'marker', 'pattern', 'mask', 'clippath',
    'lineargradient', 'radialgradient', 'stop', 'filter', 'tspan', 'textpath',
    'title', 'desc', 'animate', 'animatetransform', 'foreignobject',
];

/**
 * Every element the payload owns has to be addressable.
 *
 * The playbook has always called this non-negotiable — "Every element MUST have
 * a BEM class" — and nothing enforced it. So a payload of bare semantic tags
 * passed with no failures and no warnings, which is exactly what a customer
 * got: they asked for a hero with Etch and ACSS, received a correct tree of
 * `section > div > h1 > a` carrying nothing but `aria-label` and `href`, and
 * every element in it rendered unstyled because there was nothing to style it
 * BY. The same request with a reference image produced a fully classed and
 * styled tree, so the difference never looked like a rule that could be
 * enforced — it looked like luck.
 *
 * An element is addressable if it carries a class, or if it references a style
 * id directly. Anything else has no way to be styled and no way to be targeted
 * later, and the page it belongs to can only ever be rebuilt, not edited.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_element_classes(array $payload): array
{
    $tree = $payload['gutenbergBlock'] ?? null;
    if (!is_array($tree) || $tree === []) {
        return [];
    }

    $trees = [$tree];
    foreach ((array) ($payload['components'] ?? []) as $key => $component) {
        foreach (nibwp_etchwp_component_blocks($component, (string) $key) as $block) {
            $trees[] = $block;
        }
    }

    $bare  = [];
    $total = 0;

    foreach ($trees as $subtree) {
        nibwp_etchwp_walk_block_nodes($subtree, static function (array $node) use (&$bare, &$total): void {
            if ((string) ($node['blockName'] ?? '') !== 'etch/element') {
                return; // etch/text carries content, not presentation.
            }

            $attrs = (array) ($node['attrs'] ?? []);
            $tag   = strtolower(trim((string) ($attrs['tag'] ?? '')));

            if ($tag === '' || in_array($tag, NIBWP_ETCHWP_SVG_PRIMITIVES, true)) {
                return;
            }

            // A direct style reference is addressing by another name.
            if (!empty($attrs['styles']) || !empty($attrs['metadata']['etchData']['styles'])) {
                return;
            }

            if (trim((string) ($attrs['attributes']['class'] ?? '')) !== '') {
                return;
            }

            $total++;
            if (count($bare) < 6 && !in_array($tag, $bare, true)) {
                $bare[] = $tag;
            }
        });
    }

    if ($total === 0) {
        return [];
    }

    return [[
        'id'   => 'element_without_class',
        'msg'  => sprintf(
            /* translators: 1: number of elements, 2: comma-separated tag names */
            _n(
                '%1$d element has no class and references no style (%2$s), so nothing can style it and nothing can target it later. Give every element a BEM class — {brand}-{component}__{element} — and a style that references it. A tree of bare semantic tags renders completely unstyled.',
                '%1$d elements have no class and reference no style (%2$s), so nothing can style them and nothing can target them later. Give every element a BEM class — {brand}-{component}__{element} — and a style that references it. A tree of bare semantic tags renders completely unstyled.',
                $total,
                'nibwp'
            ),
            $total,
            implode(', ', array_map(static fn(string $t): string => '<' . $t . '>', $bare))
        ),
        'path' => 'gutenbergBlock',
    ]];
}

/**
 * Styles Etch will never emit, and elements it will never style.
 *
 * Etch does not render a stylesheet for the classes on a page. It renders the
 * styles a BLOCK ASKED FOR: `StylesRegister::register_block_styles()` takes the
 * ids in `attrs.styles`, and only those reach `wp_head`. A class attribute on
 * its own emits nothing.
 *
 * That makes three payloads look finished and render bare, and until now all
 * three passed validation clean:
 *
 *   - no styles at all, on a tree full of BEM classes. A customer asked for a
 *     hero built with Etch and ACSS, got the section, and every element in it
 *     was unstyled.
 *   - styles written but never referenced. The CSS lands in the `etch_styles`
 *     option, Etch is never told any block needs it, and nothing is emitted.
 *   - a block referencing an id the payload never defines. There is nothing to
 *     emit for it.
 *
 * Component trees count as part of the page: a style used only inside a
 * component is referenced, and reporting it as dead would be wrong.
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_style_coverage(array $payload, string $brand): array
{
    $tree = $payload['gutenbergBlock'] ?? null;
    if (!is_array($tree) || $tree === []) {
        return [];
    }

    // Etch ships these itself; a block may reference them without the payload
    // defining them.
    $scaffold = ['etch-section-style', 'etch-container-style', 'etch-flex-div-style', 'etch-iframe-style', 'etch-global-variable-style'];

    // What the payload defines, under the id the persister will actually write.
    $defined = [];
    foreach ((array) ($payload['styles'] ?? []) as $key => $def) {
        $normalized = nibwp_etchwp_normalize_style((string) $key, $def);
        if ($normalized !== null) {
            $defined[$normalized['id']] = (string) $key;
        }
    }

    // What the blocks ask for — across the page tree and every component tree,
    // because a component is part of what renders.
    $referenced = [];
    $classes    = [];
    $trees      = [$tree];
    foreach ((array) ($payload['components'] ?? []) as $key => $component) {
        foreach (nibwp_etchwp_component_blocks($component, (string) $key) as $block) {
            $trees[] = $block;
        }
    }

    foreach ($trees as $subtree) {
        nibwp_etchwp_walk_block_nodes($subtree, static function (array $node) use (&$referenced, &$classes): void {
            $attrs = (array) ($node['attrs'] ?? []);

            foreach ((array) ($attrs['styles'] ?? []) as $sid) {
                if (is_string($sid) && $sid !== '') {
                    $referenced[$sid] = true;
                }
            }
            $meta_styles = $attrs['metadata']['etchData']['styles'] ?? null;
            foreach ((array) $meta_styles as $sid) {
                if (is_string($sid) && $sid !== '') {
                    $referenced[$sid] = true;
                }
            }

            $class = (string) ($attrs['attributes']['class'] ?? '');
            foreach (preg_split('/\s+/', trim($class)) ?: [] as $one) {
                if ($one !== '') {
                    $classes[] = $one;
                }
            }
        });
    }

    $issues = [];

    // 1) Nothing to style anything with.
    //
    // Only when the tree carries classes this payload is responsible for. A
    // build made entirely of framework utility classes is styled by the
    // framework's own stylesheet and defines nothing here, which is legitimate.
    if ($defined === [] && $referenced === []) {
        $owned = array_values(array_filter($classes, static function (string $class) use ($brand): bool {
            if ($brand !== '' && str_starts_with($class, $brand . '-')) {
                return true;
            }

            // BEM element and modifier markers never come from a utility
            // framework, whatever the brand is called.
            return str_contains($class, '__') || str_contains($class, '--');
        }));

        if ($owned !== []) {
            $issues[] = [
                'id'   => 'styles_missing',
                'msg'  => sprintf(
                    'The tree carries classes this payload has to style (%s) but payload.styles is empty, so the page renders with no CSS for them. Etch emits a style only when a block references its id, so add the rules to payload.styles and list the id in that element\'s attrs.styles.',
                    implode(', ', array_slice(array_unique($owned), 0, 4))
                ),
                'path' => 'styles',
            ];
        }
    }

    // 2) Written, and never asked for. The persister stores it in etch_styles
    //    and Etch renders nothing, so the element it was meant for is bare.
    foreach ($defined as $id => $key) {
        if (!isset($referenced[$id])) {
            $issues[] = [
                'id'   => 'style_unreferenced',
                'msg'  => sprintf(
                    'Style `%1$s` (id `%2$s`) is defined but no block references it. Etch only emits a style a block asked for, so this CSS is stored and never rendered. Add "%2$s" to attrs.styles on the element it styles.',
                    $key,
                    $id
                ),
                'path' => 'styles.' . $key,
            ];
        }
    }

    // 3) Asked for, and never written.
    foreach (array_keys($referenced) as $id) {
        if (!isset($defined[$id]) && !in_array($id, $scaffold, true)) {
            $issues[] = [
                'id'   => 'style_reference_dangling',
                'msg'  => sprintf(
                    'A block references style id `%s`, which payload.styles does not define, so Etch has nothing to emit for it and the element renders unstyled. Define it, or drop the reference.',
                    $id
                ),
                'path' => 'gutenbergBlock',
            ];
        }
    }

    return $issues;
}

/**
 * Validate component property references.
 *
 * Two checks:
 *   - Every etch/component instance must reference a componentId that exists
 *     in payload.components.
 *   - Every {props.NAME} reference inside the component definition tree must
 *     match a declared property key on that component (Etch binds by key).
 *
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_etchwp_validate_component_refs(array $payload): array
{
    $issues = [];
    $components = (array) ($payload['components'] ?? []);

    // Every {props.X} inside a component must name a declared property key -
    // Etch binds props by key, so a binding by name renders nothing.
    foreach ($components as $cid => $cdef) {
        if (!is_array($cdef)) {
            continue;
        }
        $keys   = array_values(array_filter(array_map('nibwp_etchwp_property_key', (array) ($cdef['properties'] ?? [])), 'strlen'));
        $blocks = nibwp_etchwp_component_blocks($cdef, (string) $cid);

        if ($blocks === []) {
            $issues[] = [
                'id'   => 'component_empty',
                'msg'  => sprintf('Component "%s" has no blocks, so every instance of it renders nothing. Put its block tree under components.%s.blocks.', $cid, $cid),
                'path' => sprintf('components.%s.blocks', $cid),
            ];
            continue;
        }

        $tree_string = wp_json_encode($blocks);
        if (is_string($tree_string) && preg_match_all('/\{props\.([a-z0-9_]+)\}/i', $tree_string, $m)) {
            foreach (array_unique($m[1]) as $referenced) {
                if (!in_array($referenced, $keys, true)) {
                    $issues[] = [
                        'id'   => 'component_undefined_property',
                        'msg'  => sprintf('Component "%s" references {props.%s} but no property with key "%s" is declared in properties[]. Declared keys: [%s].', $cid, $referenced, $referenced, implode(', ', $keys) ?: '(none)'),
                        'path' => sprintf('components.%s.blocks', $cid),
                    ];
                }
            }
        }
    }

    // Every instance - on the page or nested in a component - must resolve:
    // to a component defined here, or by ref to a post already on the site,
    // which the resolvability gate checks.
    $instances = [];
    $trees     = [$payload['gutenbergBlock'] ?? null];
    foreach ($components as $cid => $cdef) {
        foreach (nibwp_etchwp_component_blocks($cdef, (string) $cid) as $block) {
            $trees[] = $block;
        }
    }
    foreach ($trees as $tree) {
        nibwp_etchwp_walk_for_component_instances($tree, $instances);
    }

    foreach ($instances as $inst) {
        $local = nibwp_etchwp_local_component_key($inst['attrs'], $components);
        if ($local === '') {
            if ($inst['componentId'] !== '') {
                $issues[] = [
                    'id'   => 'component_instance_unknown_id',
                    'msg'  => sprintf('etch/component instance references componentId "%s" but it is not defined in payload.components.', $inst['componentId']),
                    'path' => 'gutenbergBlock',
                ];
            }
            continue;
        }

        // Required-property check — properties with required=true (and no default) must be set on the instance.
        $instance_props = (array) ($inst['attrs']['attributes'] ?? []) + (is_array($inst['props']) ? $inst['props'] : []);
        foreach ((array) ($components[$local]['properties'] ?? []) as $p) {
            $name = nibwp_etchwp_property_key($p);
            if ($name === '') {
                continue;
            }
            $required = !empty($p['required']);
            $has_default = array_key_exists('default', $p) && $p['default'] !== null && $p['default'] !== '';
            if ($required && !$has_default && !array_key_exists($name, $instance_props)) {
                $issues[] = [
                    'id'   => 'component_instance_missing_required_prop',
                    'msg'  => sprintf('etch/component instance of "%s" is missing required property "%s" (no default declared).', $local, $name),
                    'path' => 'gutenbergBlock',
                ];
            }
        }
    }

    return $issues;
}

/**
 * Walk the block tree, collecting every etch/component instance.
 *
 * @param mixed $node
 * @param array<int,array{componentId:string,props:mixed}> $out
 */
function nibwp_etchwp_walk_for_component_instances($node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }
    if (($node['blockName'] ?? '') === 'etch/component') {
        $attrs = (array) ($node['attrs'] ?? []);
        $out[] = [
            'componentId' => (string) ($attrs['componentId'] ?? ''),
            'props'       => $attrs['props'] ?? null,
            'attrs'       => $attrs,
        ];
    }
    foreach ((array) ($node['innerBlocks'] ?? []) as $child) {
        nibwp_etchwp_walk_for_component_instances($child, $out);
    }
}

/**
 * A component's top-level blocks, in whichever shape the payload carries them.
 *
 * Etch's own format is `blocks`, a list: it is what Etch's import reads and
 * what json-schema.md teaches. An older example used a single `gutenbergBlock`,
 * sometimes wrapped in an etch/component naming the component itself. Every
 * rule and the persister read components through here, because reading only
 * `gutenbergBlock` reported every component-only style as unreferenced and
 * saved the component as an empty post that rendered nothing.
 *
 * @param mixed $def
 * @return array<int,array<string,mixed>>
 */
function nibwp_etchwp_component_blocks($def, string $cid = ''): array
{
    if (!is_array($def)) {
        return [];
    }

    if (isset($def['blocks']) && is_array($def['blocks'])) {
        return isset($def['blocks']['blockName'])
            ? [$def['blocks']]
            : array_values(array_filter($def['blocks'], 'is_array'));
    }

    $tree = $def['gutenbergBlock'] ?? null;
    if (!is_array($tree) || $tree === []) {
        return [];
    }

    // A root instance of the component itself is a wrapper, not content: saved
    // as-is the component would contain itself.
    $attrs = (array) ($tree['attrs'] ?? []);
    if (($tree['blockName'] ?? '') === 'etch/component' && empty($attrs['ref'])
        && in_array((string) ($attrs['componentId'] ?? ''), ['', $cid], true)) {
        return array_values(array_filter((array) ($tree['innerBlocks'] ?? []), 'is_array'));
    }

    return [$tree];
}

/**
 * The payload.components key an etch/component instance points at, or ''.
 *
 * `componentId` names an entry by its key. `ref` is Etch's own addressing and
 * on a site means a wp_block post id, but inside a payload json-schema.md has
 * it name the component's `id` - the way Etch's exports are written. A ref
 * naming a component the payload defines is local: the persister mints the
 * post and rewrites the ref to it. The payload that defines it is the one that
 * means it, so local wins over a post that happens to share the number.
 */
function nibwp_etchwp_local_component_key(array $attrs, array $components): string
{
    $cid = (string) ($attrs['componentId'] ?? '');
    if ($cid !== '' && array_key_exists($cid, $components)) {
        return $cid;
    }

    $ref = $attrs['ref'] ?? null;
    if (!is_string($ref) && !is_int($ref)) {
        return '';
    }
    $ref = (string) $ref;
    if ($ref === '') {
        return '';
    }
    if (array_key_exists($ref, $components)) {
        return $ref;
    }
    foreach ($components as $key => $def) {
        if (is_array($def) && isset($def['id']) && (is_string($def['id']) || is_int($def['id'])) && (string) $def['id'] === $ref) {
            return (string) $key;
        }
    }

    return '';
}

/**
 * The key a property is bound by. Etch maps props by `key` and drops a
 * property without one; the skill's older shorthand only had `name`, which the
 * persister promotes to `key`.
 */
function nibwp_etchwp_property_key($property): string
{
    if (!is_array($property)) {
        return '';
    }

    return (string) ($property['key'] ?? '') !== '' ? (string) $property['key'] : (string) ($property['name'] ?? '');
}

/**
 * Black or white at partial opacity: a shadow, a scrim, a hairline. No brand
 * owns that colour, so demanding var(--primary, rgba(0,0,0,.08)) for it was
 * noise that cost agents their validation attempts.
 */
function nibwp_etchwp_is_translucent_neutral(string $literal): bool
{
    $literal = strtolower($literal);
    if (!preg_match_all('/\d*\.?\d+%?/', $literal, $m) || count($m[0]) !== 4) {
        return false;
    }

    [$a, $b, $c, $alpha] = $m[0];
    $alpha = str_ends_with($alpha, '%') ? (float) $alpha / 100 : (float) $alpha;
    if ($alpha >= 1) {
        return false;
    }

    if (str_starts_with($literal, 'hsl')) {
        return in_array((float) $c, [0.0, 100.0], true); // lightness 0% or 100%
    }

    $rgb = [(float) $a, (float) $b, (float) $c];

    return $rgb === [0.0, 0.0, 0.0] || $rgb === [255.0, 255.0, 255.0];
}

/**
 * Etch's own shape for one entry in `etch_styles`.
 *
 * Etch stores a map of style id => {type, selector, css, readonly?} and reads
 * `$style['selector']` and `$style['type']` unguarded when it collects the
 * styles a page needs. An entry shaped any other way therefore does not merely
 * fail to render — it emits undefined-index warnings on every front-end
 * request, on a page the customer is looking at.
 *
 * Agents write the intuitive thing instead: selector => {property: value}. That
 * is a reasonable guess and it is not Etch's format, so this converts it rather
 * than rejecting it. Anything already in Etch's shape passes through untouched.
 *
 * @param string $key The key the payload used — a style id, or a raw selector.
 * @param mixed  $def The definition, in either shape.
 * @return array{id:string,style:array{type:string,selector:string,css:string},legacy_key:?string}|null
 *         Null when it cannot be read as a style at all.
 */
function nibwp_etchwp_normalize_style(string $key, $def): ?array
{
    if (!is_array($def) || $def === []) {
        return null;
    }

    $looks_like_selector = static function (string $s): bool {
        $s = trim($s);
        return $s !== '' && (bool) preg_match('/^[.#:\[]|^[a-zA-Z][a-zA-Z0-9-]*$/', $s) && !str_ends_with($s, '-style');
    };

    // Already Etch-shaped: keep it, and only fill in what is missing.
    if (isset($def['css']) && is_string($def['css'])) {
        $selector = (string) ($def['selector'] ?? $def['name'] ?? ($looks_like_selector($key) ? $key : ''));
        if ($selector === '') {
            return null;
        }
        $style = $def;
        $style['selector'] = $selector;
        $style['css'] = trim($def['css']);
        $style['type'] = (string) ($def['type'] ?? nibwp_etchwp_style_type($selector));

        // When the entry names its own selector, the KEY is an id — that is
        // Etch's own shape, and its ids are opaque tokens like `p5icdeh`.
        // Deriving an id from the selector there renamed the style out from
        // under the blocks: the persister wrote `cc-hero-style` while every
        // attrs.styles still said `p5icdeh`, Etch found nothing under that id,
        // and the section rendered with no CSS. Only a key that is itself a CSS
        // selector (`.hero`, `#id`, a bare tag) gets an id derived for it.
        $names_own_selector = isset($def['selector']) && (string) $def['selector'] !== '';
        $key_is_a_selector  = (bool) preg_match('/^[.#:\[]/', trim($key)) || !$names_own_selector;

        return [
            'id' => $key_is_a_selector ? nibwp_etchwp_style_id($selector) : $key,
            'style' => $style,
            'legacy_key' => $key_is_a_selector && $looks_like_selector($key) ? $key : null,
        ];
    }

    // The property-map shape. The key has to be the selector — there is nowhere
    // else for it to have come from.
    if (!$looks_like_selector($key)) {
        return null;
    }

    $declarations = [];
    foreach ($def as $property => $value) {
        if (!is_string($property) || $property === '' || !is_scalar($value)) {
            continue; // Nested at-rules and the like are not expressible here.
        }
        $declarations[] = sprintf('%s: %s;', trim($property), trim((string) $value));
    }

    if ($declarations === []) {
        return null;
    }

    return [
        'id' => nibwp_etchwp_style_id($key),
        'style' => [
            'type' => nibwp_etchwp_style_type($key),
            'selector' => $key,
            'css' => implode(' ', $declarations),
        ],
        // The old code keyed these by the selector itself. Writing the repaired
        // entry under a proper id would otherwise leave the broken one behind,
        // still warning on every request.
        'legacy_key' => $key,
    ];
}

/**
 * Etch's `type`, inferred from the selector.
 *
 * Etch treats anything that is not class/id/element as always-loaded, so an
 * unrecognized selector errs toward being rendered rather than being dropped.
 */
function nibwp_etchwp_style_type(string $selector): string
{
    $selector = trim($selector);

    if (str_starts_with($selector, '.')) {
        return 'class';
    }
    if (str_starts_with($selector, '#')) {
        return 'id';
    }
    if ($selector === ':root') {
        return 'root';
    }

    return 'element';
}

/**
 * A stable style id for a selector, in the shape Etch's own ids take.
 */
function nibwp_etchwp_style_id(string $selector): string
{
    $slug = strtolower(trim($selector));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = substr(sha1($selector), 0, 12);
    }

    return $slug . '-style';
}
