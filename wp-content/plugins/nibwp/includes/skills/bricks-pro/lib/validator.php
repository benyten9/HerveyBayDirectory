<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bricks Pro — payload validator.
 *
 * Hard reject rules (validation.failed):
 *   - bricks_element_unknown       : `name` not in NIBWP_BRICKS_CORE_ELEMENTS whitelist
 *   - bricks_element_missing_id    : element has no `_id` (settings._id) and no top-level `id`
 *   - bricks_inline_style_attr     : raw HTML inside settings has style="…" attribute
 *   - bricks_inline_media_query    : @media inside settings._cssCustom (use Bricks breakpoint settings)
 *   - bricks_hardcoded_font_size   : font-size literal outside var() fallback inside CSS values
 *   - bricks_hardcoded_color       : color/bg literal outside var() fallback (brand allowlist exempt)
 *   - bricks_missing_brand_prefix  : globalClasses entry without {brand}- prefix
 *   - bricks_missing_global_class  : non-leaf element has zero globalClasses references
 *   - bricks_static_form_html      : raw <form> markup inside a code/html element
 *   - bricks_raw_iframe_provider   : raw YouTube/Vimeo <iframe> inside code/html (should be video element)
 *   - bricks_template_type_invalid : template_type not in {header,footer,content,section,archive,error,popup}
 *   - bricks_invalid_query_loop    : posts/query element missing `query` settings dict
 *   - acss_absent_tokens_used      : ACSS not active but payload uses ACSS-namespace tokens
 *
 * Decoration: every failed entry receives `fix_hint` from the pure dispatcher.
 */

require_once __DIR__ . '/element-registry.php';

const NIBWP_BRICKS_TEMPLATE_TYPES = ['header', 'footer', 'content', 'section', 'archive', 'error', 'popup'];

const NIBWP_BRICKS_UTILITY_ALLOWLIST = [
    'is-active', 'is-open', 'is-hidden', 'is-loading', 'is-disabled', 'is-selected',
    'has-icon', 'has-children', 'has-error', 'has-success',
    'sr-only', 'visually-hidden', 'screen-reader-text',
    'brxe-root', 'brx-loop', // Bricks-emitted helper classes
];

const NIBWP_BRICKS_COLOR_PROPERTIES = [
    'color', 'background-color', 'background', 'border-color', 'border',
    'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'outline-color', 'fill', 'stroke', 'box-shadow', 'text-shadow',
    'caret-color', 'column-rule-color', 'accent-color',
];

/**
 * Best-effort ACSS detection (mirror of etchwp helper).
 */
function nibwp_bricks_pro_acss_active(): bool
{
    return defined('ACSS_PLUGIN_FILE')
        || defined('ACSS_VERSION')
        || class_exists('\\Automatic_CSS\\Plugin')
        || function_exists('acss_get_setting');
}

/**
 * Brand-color allowlist — reuses nibwp_user_defaults preferences store.
 *
 * @return array<int,string>
 */
function nibwp_bricks_pro_brand_color_allowlist(): array
{
    $defaults = (array) get_option('nibwp_user_defaults', []);
    $raw = array_filter([
        $defaults['bricks_brand_color']   ?? null,
        $defaults['bricks_brand_color_2'] ?? null,
        $defaults['brand_color']          ?? null, // shared fallback
        $defaults['brand_color_2']        ?? null,
    ], static fn ($v) => is_string($v) && $v !== '');
    $colors = array_map(static fn ($c) => strtolower(trim((string) $c)), $raw);
    /** @var array<int,string> $colors */
    $colors = (array) apply_filters('nibwp_bricks_pro_brand_color_allowlist', $colors);
    return array_values($colors);
}

/**
 * Strip every `var(...)` reference + fallback so subsequent literal checks
 * don't false-positive on values that ARE inside a var() fallback.
 */
function nibwp_bricks_pro_strip_var_calls(string $css): string
{
    for ($i = 0; $i < 2; $i++) {
        $css = (string) preg_replace('/var\(\s*--[a-z0-9-]+\s*(?:,\s*[^()]*)?\)/i', '', $css);
    }
    return $css;
}

/**
 * Validate a Bricks-payload.
 *
 * @param array<string,mixed> $payload {
 *   template_type?: string,
 *   global_classes?: array<int,array{id:string,name:string,settings:array}>,
 *   elements:       array<int,array{name:string,settings:array,parent?:int,children?:array<int,int>}>
 * }
 * @param array<string,mixed> $ctx { brand?, acss_active?, element_type?, source_html? }
 *
 * @return array{passed:bool,failed:array<int,array{id:string,msg:string,path:string,fix_hint:string}>,warnings:array<int,array{id:string,msg:string,path:string}>}
 */
function nibwp_bricks_pro_validate_payload(array $payload, array $ctx = []): array
{
    $failed   = [];
    $warnings = [];

    $brand        = sanitize_key((string) ($ctx['brand'] ?? ''));
    $acss_active  = array_key_exists('acss_active', $ctx) ? (bool) $ctx['acss_active'] : nibwp_bricks_pro_acss_active();
    $color_allow  = nibwp_bricks_pro_brand_color_allowlist();

    // 0) template_type sanity (warning, not reject — persister will coerce).
    if (isset($payload['template_type']) && !in_array((string) $payload['template_type'], NIBWP_BRICKS_TEMPLATE_TYPES, true)) {
        $failed[] = [
            'id'   => 'bricks_template_type_invalid',
            'msg'  => sprintf('template_type `%s` is not one of: %s.', (string) $payload['template_type'], implode(', ', NIBWP_BRICKS_TEMPLATE_TYPES)),
            'path' => 'template_type',
        ];
    }

    // 1) Global classes — BEM brand prefix.
    $global_classes = (array) ($payload['global_classes'] ?? []);
    foreach ($global_classes as $i => $gc) {
        if (!is_array($gc)) {
            continue;
        }
        $name = (string) ($gc['name'] ?? '');
        if ($name === '') {
            $warnings[] = ['id' => 'bricks_global_class_unnamed', 'msg' => 'global_classes[' . $i . '] has no `name`.', 'path' => "global_classes[$i]"];
            continue;
        }
        if ($brand !== '' && !str_starts_with($name, $brand . '-') && !in_array($name, NIBWP_BRICKS_UTILITY_ALLOWLIST, true)) {
            $failed[] = [
                'id'   => 'bricks_missing_brand_prefix',
                'msg'  => sprintf('Global class `%s` lacks the brand prefix `%s-`. Rename to `%s-%s` or add to nibwp_bricks_pro_element_whitelist filter.', $name, $brand, $brand, ltrim($name, '_-')),
                'path' => "global_classes[$i].name",
            ];
        }
        // CSS inside global class settings (Bricks calls this _cssCustom).
        $css = (string) (($gc['settings']['_cssCustom']) ?? '');
        if ($css !== '') {
            $failed = array_merge($failed, nibwp_bricks_pro_check_css($css, "global_classes[$i]._cssCustom", $acss_active, $color_allow));
        }
    }

    // 2) Elements — name whitelist, _id presence, inline style attr, _cssCustom checks.
    $elements = (array) ($payload['elements'] ?? []);
    foreach ($elements as $idx => $el) {
        if (!is_array($el)) {
            continue;
        }
        $name = (string) ($el['name'] ?? '');
        $path = "elements[$idx]";

        // Element name whitelist.
        if ($name === '' || !nibwp_bricks_pro_element_exists($name)) {
            $failed[] = [
                'id'   => 'bricks_element_unknown',
                'msg'  => sprintf('Element name `%s` is not in the Bricks core catalog. See references/bricks-elements.md.', $name === '' ? '(empty)' : $name),
                'path' => $path . '.name',
            ];
            continue;
        }

        $settings = (array) ($el['settings'] ?? []);
        // Element ID — Bricks elements need a stable _id. Persister generates
        // one when missing, but we warn so the agent learns to set it.
        if (!isset($settings['_id']) && !isset($el['id'])) {
            $warnings[] = ['id' => 'bricks_element_missing_id', 'msg' => "Element [$idx] has no _id; persister will mint one.", 'path' => $path];
        }

        // Inline style attr inside any HTML-bearing setting (text, code, _cssCustom).
        foreach (['text', 'code', '_cssCustom'] as $k) {
            if (!isset($settings[$k]) || !is_string($settings[$k])) {
                continue;
            }
            $val = (string) $settings[$k];
            if ($k === '_cssCustom') {
                if (preg_match('/@media\b/i', $val)) {
                    $failed[] = [
                        'id'   => 'bricks_inline_media_query',
                        'msg'  => sprintf('Element [%d] settings._cssCustom contains @media. Use Bricks breakpoint settings instead — Bricks renders per-breakpoint blocks automatically.', $idx),
                        'path' => $path . '.settings._cssCustom',
                    ];
                }
                $failed = array_merge($failed, nibwp_bricks_pro_check_css($val, $path . '.settings._cssCustom', $acss_active, $color_allow));
            } else {
                if (preg_match('/\sstyle\s*=\s*["\']/i', $val)) {
                    $failed[] = [
                        'id'   => 'bricks_inline_style_attr',
                        'msg'  => sprintf('Element [%d] settings.%s contains a raw `style="..."` attribute. Move declarations into settings._cssCustom or a global class.', $idx, $k),
                        'path' => $path . ".settings.$k",
                    ];
                }
                if ($name === 'code' || $name === 'html') {
                    if (preg_match('/<form\b/i', $val)) {
                        $failed[] = [
                            'id'   => 'bricks_static_form_html',
                            'msg'  => 'Code/HTML element contains raw <form> markup. Use the native Bricks "form" element OR a shortcode element wrapping nibwp/forms-manage output.',
                            'path' => $path . ".settings.$k",
                        ];
                    }
                    if (preg_match('~<iframe\b[^>]*\bsrc\s*=\s*["\'][^"\']*(?:youtube\.com|youtu\.be|vimeo\.com)~i', $val)) {
                        $failed[] = [
                            'id'   => 'bricks_raw_iframe_provider',
                            'msg'  => 'Code/HTML element contains a raw YouTube/Vimeo iframe. Use the native Bricks "video" element (videoType=youtube|vimeo) instead.',
                            'path' => $path . ".settings.$k",
                        ];
                    }
                }
            }
        }

        // Global class usage — every layout element (section/container/div/block)
        // and every visual element (heading/text/button/image) should reference
        // at least one global class. Mirrors Kevin Geary's "no inline-only" rule.
        $structural = in_array($name, ['section', 'container', 'block', 'div', 'heading', 'text', 'text-basic', 'button', 'image'], true);
        if ($structural) {
            $classes = (array) ($settings['_cssGlobalClasses'] ?? $settings['globalClasses'] ?? []);
            if ($classes === []) {
                $warnings[] = ['id' => 'bricks_missing_global_class', 'msg' => "Element [$idx] ($name) has no globalClasses reference. Prefer global classes over per-element CSS.", 'path' => $path];
            }
        }

        // Query loops — posts element needs a query dict.
        if ($name === 'posts' && empty($settings['query'])) {
            $failed[] = [
                'id'   => 'bricks_invalid_query_loop',
                'msg'  => "Element [$idx] is a `posts` query loop without a settings.query dict. Provide { post_type, posts_per_page, orderby, order, ... }.",
                'path' => $path . '.settings.query',
            ];
        }

        // _conditions shape check (when present).
        if (isset($settings['_conditions']) && is_array($settings['_conditions'])) {
            foreach ($settings['_conditions'] as $ci => $cond) {
                if (!is_array($cond)) {
                    $failed[] = [
                        'id'   => 'bricks_invalid_condition_shape',
                        'msg'  => sprintf('Element [%d] settings._conditions[%d] must be an object with { key, operator, value, ... }.', $idx, $ci),
                        'path' => $path . ".settings._conditions[$ci]",
                    ];
                    continue;
                }
                if (empty($cond['key'])) {
                    $failed[] = [
                        'id'   => 'bricks_invalid_condition_shape',
                        'msg'  => sprintf('Element [%d] settings._conditions[%d] missing `key` (e.g. "user-role", "post-meta", "post-type", "is-front-page").', $idx, $ci),
                        'path' => $path . ".settings._conditions[$ci].key",
                    ];
                }
                if (isset($cond['operator']) && !in_array((string) $cond['operator'], ['==', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains', 'isTruthy', 'isFalsy', 'in', 'not_in', 'any', 'all'], true)) {
                    $failed[] = [
                        'id'   => 'bricks_invalid_condition_shape',
                        'msg'  => sprintf('Element [%d] settings._conditions[%d].operator `%s` is not a Bricks condition operator. Use one of: == != > >= < <= contains not_contains isTruthy isFalsy in not_in any all.', $idx, $ci, (string) $cond['operator']),
                        'path' => $path . ".settings._conditions[$ci].operator",
                    ];
                }
            }
        }
    }

    // Decorate every failed entry with fix_hint.
    $failed = array_map(static function (array $item): array {
        if (!array_key_exists('fix_hint', $item) || $item['fix_hint'] === '') {
            $item['fix_hint'] = nibwp_bricks_pro_fix_hint_for($item);
        }
        return $item;
    }, $failed);

    return [
        'passed'   => $failed === [],
        'failed'   => array_values($failed),
        'warnings' => array_values($warnings),
    ];
}

/**
 * Per-CSS-string checks (hardcoded font-size, hardcoded color, ACSS-absent).
 *
 * @param array<int,string> $color_allow
 * @return array<int,array{id:string,msg:string,path:string}>
 */
function nibwp_bricks_pro_check_css(string $css, string $path, bool $acss_active, array $color_allow): array
{
    $issues = [];
    if ($css === '') {
        return $issues;
    }

    // hardcoded font-size
    if (preg_match_all('/font-size\s*:\s*([^;{}]+)[;}]/i', $css, $m_fs, PREG_SET_ORDER)) {
        foreach ($m_fs as $m) {
            $value    = trim($m[1]);
            $residual = trim(nibwp_bricks_pro_strip_var_calls($value));
            if ($residual === '' || in_array(strtolower($residual), ['inherit', 'initial', 'unset', 'revert', 'currentcolor', '0'], true)) {
                continue;
            }
            if (preg_match('/\b\d+(?:\.\d+)?\s*(px|rem|em|pt|%|vw|vh|svh|lvh|dvh|cqi|cqb|cqw|cqh)\b/i', $residual)) {
                $issues[] = [
                    'id'   => 'bricks_hardcoded_font_size',
                    'msg'  => sprintf('font-size `%s` is a hardcoded literal. Use a --text-* token: var(--text-l, %s).', $value, $residual),
                    'path' => $path,
                ];
            }
        }
    }

    // hardcoded color (props from registry, residual after var-strip)
    $props = implode('|', array_map(static fn ($p) => preg_quote($p, '/'), NIBWP_BRICKS_COLOR_PROPERTIES));
    if (preg_match_all('/(?<![a-z-])(' . $props . ')\s*:\s*([^;{}]+)[;}]/i', $css, $m_col, PREG_SET_ORDER)) {
        foreach ($m_col as $m) {
            $prop     = strtolower(trim($m[1]));
            $value    = trim($m[2]);
            $residual = trim(nibwp_bricks_pro_strip_var_calls($value));
            if ($residual === '') {
                continue;
            }
            $found = '';
            if (preg_match('/#[0-9a-f]{3,8}\b/i', $residual, $cm)) {
                $found = strtolower($cm[0]);
            } elseif (preg_match('/\brgba?\s*\(\s*[\d.,\s%\/]+\)/i', $residual, $cm)) {
                $found = strtolower(preg_replace('/\s+/', '', $cm[0]));
            } elseif (preg_match('/\bhsla?\s*\(\s*[\d.,\s%\/]+\)/i', $residual, $cm)) {
                $found = strtolower(preg_replace('/\s+/', '', $cm[0]));
            }
            if ($found === '' || in_array($found, $color_allow, true)) {
                continue;
            }
            $issues[] = [
                'id'   => 'bricks_hardcoded_color',
                'msg'  => sprintf('%s uses hardcoded color `%s`. Use var(--primary, %s) or add the literal to nibwp_user_defaults.bricks_brand_color.', $prop, $found, $found),
                'path' => $path,
            ];
        }
    }

    // ACSS-absent token usage
    if (!$acss_active && preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $m_tok)) {
        $acss_prefixes = ['--text-', '--space-', '--section-space-', '--content-', '--card-', '--leading-', '--radius', '--base-', '--primary', '--secondary', '--heading-', '--surface-', '--border-color', '--h2', '--h3', '--white'];
        foreach ($m_tok[1] as $name) {
            $low = strtolower(trim((string) $name));
            foreach ($acss_prefixes as $px) {
                if (str_starts_with($low, $px)) {
                    $issues[] = [
                        'id'   => 'acss_absent_tokens_used',
                        'msg'  => sprintf('ACSS not detected but token `%s` is used. Install ACSS, bake fallback literals, or use the Bricks Design System variables instead.', $low),
                        'path' => $path,
                    ];
                    break;
                }
            }
        }
    }

    return $issues;
}

/**
 * Pure dispatcher — failed id → copy-paste fix hint.
 */
function nibwp_bricks_pro_fix_hint_for(array $item): string
{
    switch ((string) ($item['id'] ?? '')) {
        case 'bricks_element_unknown':
            return 'Rename `name` to a real Bricks element (section / container / block / div / heading / text / button / image / icon / video / form / nav-menu / posts / accordion / tabs / slider / shortcode / html / template / ...). See references/bricks-elements.md.';
        case 'bricks_element_missing_id':
            return 'Add settings._id = bin2hex(random_bytes(3)) (6-char hex) per element. The persister will mint one if you omit it, but explicit IDs make later refines stable.';
        case 'bricks_inline_style_attr':
            return 'Move every declaration from style="…" into a global class settings.{property} OR settings._cssCustom. Then reference the class via settings._cssGlobalClasses=["{brand}-foo"].';
        case 'bricks_inline_media_query':
            return 'Delete the @media block. Set the per-breakpoint value via Bricks settings: { setting: { _base: …, _mobile_landscape: …, _mobile_portrait: … } }. Bricks renders the breakpoint logic.';
        case 'bricks_hardcoded_font_size':
            return 'Wrap in a token: `font-size: var(--text-{xs|s|m|l|xl|xxl}, <literal>);`. Pick the closest --text-* from ACSS or Bricks Design System.';
        case 'bricks_hardcoded_color':
            return 'Wrap in a token: `color: var(--primary, <literal>);` OR add the literal to nibwp_user_defaults.bricks_brand_color (preferences ability).';
        case 'bricks_missing_brand_prefix':
            return 'Rename the global class to start with the brand prefix (e.g. `etched-card`). Utility hooks like is-active/has-error are exempt.';
        case 'bricks_missing_global_class':
            return 'Add a global class reference: settings._cssGlobalClasses = ["{brand}-foo"]. Bricks renders the class on the element wrapper. Prefer global classes over per-element CSS for reusability.';
        case 'bricks_static_form_html':
            return 'Replace the <form> markup with a Bricks "form" element (settings.fields = [{name, label, type, required, ...}]) OR a "shortcode" element wrapping the chosen plugin output via nibwp/forms-manage.';
        case 'bricks_raw_iframe_provider':
            return 'Replace the iframe with a Bricks "video" element: { name: "video", settings: { videoType: "youtube", url: "https://...", ratio: "16-9" } }. Auto-responsive + privacy mode + lazy load.';
        case 'bricks_template_type_invalid':
            return 'Use one of: header / footer / content / section / archive / error / popup. Defaults to "content" when omitted.';
        case 'bricks_invalid_query_loop':
            return 'Provide a query settings dict: { post_type: ["project"], posts_per_page: 6, orderby: "date", order: "DESC", paged: 1 }. Match the field choices Bricks exposes in the Query Loop builder.';
        case 'acss_absent_tokens_used':
            return 'ACSS not active. Choose: (a) install Automatic.css, (b) bake the fallback literal in place of the var(), or (c) use Bricks Design System variables (Bricks → Settings → Design System).';
        default:
            return 'See msg for details.';
    }
}
