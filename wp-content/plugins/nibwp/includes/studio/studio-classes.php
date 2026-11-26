<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Renaming a class or an ID everywhere the site uses it: the Builder's saved
 * CSS, its saved page changes and scripts, every post's content (class and id
 * attributes, block attributes, <style> blocks, #anchors), and the class lists
 * of the page builders that keep their own (Etch styles, Bricks global
 * classes). A block's BEM family follows it: renaming .hero renames
 * .hero__title and .hero--big too.
 *
 * The text work is in plain functions so it can be tested without WordPress.
 */

/** A class or ID name as CSS allows it. */
function nibwp_studio_class_name_ok(string $name): bool
{
    return (bool) preg_match('/^-?[_a-zA-Z][-_a-zA-Z0-9]{0,80}$/', $name);
}

/** Selectors and CSS text: ".from" (and its BEM family) or "#from". */
function nibwp_studio_rename_css(string $css, string $kind, string $from, string $to, int &$count = 0): string
{
    $q = preg_quote($from, '/');
    $re = $kind === '#'
        ? '/#' . $q . '(?![\w-])/'
        : '/\.' . $q . '(?=' . (strpos($from, '__') === false ? '__|' : '') . '--|[^\w-]|$)/';
    $out = preg_replace($re, $kind . $to, $css, -1, $n);

    $count += (int) $n;

    return is_string($out) ? $out : $css;
}

/** One class token, renamed with its BEM family, or as it was. */
function nibwp_studio_rename_token(string $token, string $from, string $to): string
{
    if ($token === $from) {
        return $to;
    }
    foreach (strpos($from, '__') === false ? ['__', '--'] : ['--'] as $sep) {
        if (strpos($token, $from . $sep) === 0) {
            return $to . substr($token, strlen($from));
        }
    }

    return $token;
}

/** A space-separated list of class names. */
function nibwp_studio_rename_classlist(string $list, string $from, string $to, int &$count = 0): string
{
    $parts = preg_split('/(\s+)/', $list, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    foreach ($parts as $i => $p) {
        if ($i % 2 === 0 && $p !== '') {
            $n = nibwp_studio_rename_token($p, $from, $to);
            if ($n !== $p) {
                $parts[$i] = $n;
                $count++;
            }
        }
    }

    return implode('', $parts);
}

/** A space-separated list of IDs (for, aria-*): exact names only. */
function nibwp_studio_rename_idlist(string $list, string $from, string $to, int &$count = 0): string
{
    return (string) preg_replace_callback('/\S+/', static function (array $m) use ($from, $to, &$count): string {
        if ($m[0] !== $from) {
            return $m[0];
        }
        $count++;

        return $to;
    }, $list);
}

/**
 * HTML, as saved in post content or in the Builder's changes: attributes,
 * block comments' JSON, and <style> blocks.
 */
function nibwp_studio_rename_html(string $html, string $kind, string $from, string $to, int &$count = 0): string
{
    if (strpos($html, $from) === false) {
        return $html;
    }
    $attrs = $kind === '#' ? 'id|for|href|aria-labelledby|aria-controls|aria-describedby|aria-owns|list|form|headers' : 'class';
    $html = (string) preg_replace_callback('/(\s(' . $attrs . ')\s*=\s*)(["\'])(.*?)\3/is', static function (array $m) use ($kind, $from, $to, &$count): string {
        $name = strtolower($m[2]);
        $v = $m[4];
        if ($kind === '.') {
            $v = nibwp_studio_rename_classlist($v, $from, $to, $count);
        } elseif ($name === 'href') {
            if ($v === '#' . $from) {
                $v = '#' . $to;
                $count++;
            }
        } else {
            $v = nibwp_studio_rename_idlist($v, $from, $to, $count);
        }

        return $m[1] . $m[3] . $v . $m[3];
    }, $html);

    // Block attributes: "className" / "class" for classes, "anchor" / "id" for IDs.
    $keys = $kind === '#' ? 'anchor|id' : 'className|class';
    $html = (string) preg_replace_callback('/("(?:' . $keys . ')"\s*:\s*")((?:[^"\\\\]|\\\\.)*)"/', static function (array $m) use ($kind, $from, $to, &$count): string {
        $v = $kind === '.' ? nibwp_studio_rename_classlist($m[2], $from, $to, $count) : nibwp_studio_rename_idlist($m[2], $from, $to, $count);

        return $m[1] . $v . '"';
    }, $html);

    return (string) preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', static function (array $m) use ($kind, $from, $to, &$count): string {
        return $m[1] . nibwp_studio_rename_css($m[2], $kind, $from, $to, $count) . $m[3];
    }, $html);
}

/** Every string inside a nested array, through $fn; keys too when they are selectors. */
function nibwp_studio_rename_deep($value, callable $fn)
{
    if (is_string($value)) {
        return $fn($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        $out[is_string($k) ? $fn($k) : $k] = nibwp_studio_rename_deep($v, $fn);
    }

    return $out;
}

/**
 * Rename across the site, or only count ($dry). Returns where it changed.
 *
 * @return array{rules: int, pages: int, changes: int, other: int, total: int}
 */
function nibwp_studio_rename_class_site(string $kind, string $from, string $to, bool $dry): array
{
    global $wpdb;
    $tally = ['rules' => 0, 'pages' => 0, 'changes' => 0, 'other' => 0];

    // The Builder's saved CSS: its keys are selectors.
    $css = get_option('nibwp_builder_css', []);
    $css = is_array($css) ? $css : [];
    $newCss = [];
    foreach ($css as $sel => $rule) {
        $n = 0;
        $k = nibwp_studio_rename_css((string) $sel, $kind, $from, $to, $n);
        $r = nibwp_studio_rename_css((string) $rule, $kind, $from, $to, $n);
        $newCss[$k] = $r;
        $tally['rules'] += $n > 0 ? 1 : 0;
    }
    if (!$dry && $tally['rules']) {
        update_option('nibwp_builder_css', $newCss, false);
    }

    // The Builder's saved page changes (per post and per path) and scripts.
    $patchFix = static function (array $patches) use ($kind, $from, $to): array {
        $n = 0;
        foreach ($patches as &$p) {
            foreach (['s', 'to'] as $f) {
                if (isset($p[$f])) {
                    $p[$f] = nibwp_studio_rename_css((string) $p[$f], $kind, $from, $to, $n);
                }
            }
            if (!isset($p['html'])) {
                continue;
            }
            if (in_array($p['t'] ?? '', ['attr', 'unattr'], true)) {
                $name = (string) ($p['name'] ?? '');
                if ($kind === '.' && $name === 'class') {
                    $p['html'] = nibwp_studio_rename_classlist((string) $p['html'], $from, $to, $n);
                } elseif ($kind === '#' && $name === 'id' && $p['html'] === $from) {
                    $p['html'] = $to;
                    $n++;
                } elseif ($kind === '#' && $name === 'href' && $p['html'] === '#' . $from) {
                    $p['html'] = '#' . $to;
                    $n++;
                }
            } else {
                $p['html'] = nibwp_studio_rename_html((string) $p['html'], $kind, $from, $to, $n);
            }
        }
        unset($p);

        return [$patches, $n];
    };
    // A page's scripts: a list of texts (one text before they were kept apart).
    $jsFix = static function (array $list) use ($kind, $from, $to): array {
        $n = 0;
        foreach ($list as &$code) {
            $code = nibwp_studio_rename_css((string) $code, $kind, $from, $to, $n);
        }
        unset($code);

        return [$list, $n];
    };
    $metaRows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ('_nibwp_builder_patches', '_nibwp_builder_js') AND meta_value LIKE %s", '%' . $wpdb->esc_like($from) . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off scan for a rename
    foreach ((array) $metaRows as $row) {
        $v = get_post_meta((int) $row->post_id, (string) $row->meta_key, true);
        $n = 0;
        if (is_array($v) && $row->meta_key === '_nibwp_builder_js') {
            [$nv, $n] = $jsFix($v);
        } elseif (is_array($v)) {
            [$nv, $n] = $patchFix($v);
        } else {
            $nv = nibwp_studio_rename_css((string) $v, $kind, $from, $to, $n);
        }
        if ($n) {
            $tally['changes']++;
            if (!$dry) {
                update_post_meta((int) $row->post_id, (string) $row->meta_key, wp_slash($nv));
            }
        }
    }
    $optRows = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s OR option_name = 'nibwp_builder_js') AND option_value LIKE %s", $wpdb->esc_like('nibwp_builder_patches_') . '%', $wpdb->esc_like('nibwp_builder_js_') . '%', '%' . $wpdb->esc_like($from) . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off scan for a rename
    foreach ((array) $optRows as $name) {
        $v = get_option((string) $name);
        $n = 0;
        if (is_array($v) && str_starts_with((string) $name, 'nibwp_builder_js_')) {
            [$nv, $n] = $jsFix($v);
        } elseif (is_array($v)) {
            [$nv, $n] = $patchFix($v);
        } else {
            $nv = nibwp_studio_rename_css((string) $v, $kind, $from, $to, $n);
        }
        if ($n) {
            $tally['changes']++;
            if (!$dry) {
                update_option((string) $name, $nv, false);
            }
        }
    }

    // Every post's content: pages, posts, templates, template parts, patterns.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit')", '%' . $wpdb->esc_like($from) . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off scan for a rename
    foreach ((array) $ids as $id) {
        $content = (string) get_post_field('post_content', (int) $id, 'raw');
        $n = 0;
        $new = nibwp_studio_rename_html($content, $kind, $from, $to, $n);
        if ($n && $new !== $content) {
            $tally['pages']++;
            if (!$dry) {
                // The Builder renamed it on screen too: a page open in it
                // still saves against what it shows.
                $was = function_exists('nibwp_studio_builder_base') ? nibwp_studio_builder_base((int) $id) : '';
                wp_update_post(['ID' => (int) $id, 'post_content' => wp_slash($new)]);
                if ($was !== '' && function_exists('nibwp_studio_builder_base_follow')) {
                    nibwp_studio_builder_base_follow((int) $id, $was);
                }
            }
        }
    }

    // Page builders that keep their own class lists.
    $n = 0;
    $etch = get_option('etch_styles', null);
    if (is_array($etch)) {
        $newEtch = nibwp_studio_rename_deep($etch, static fn(string $s): string => nibwp_studio_rename_css($s, $kind, $from, $to, $n));
        if ($n) {
            $tally['other']++;
            if (!$dry) {
                update_option('etch_styles', $newEtch);
            }
        }
    }
    $bricks = $kind === '.' ? get_option('bricks_global_classes', null) : null;
    if (is_array($bricks)) {
        $m = 0;
        foreach ($bricks as &$c) {
            if (is_array($c) && isset($c['name'])) {
                $nn = nibwp_studio_rename_token((string) $c['name'], $from, $to);
                if ($nn !== $c['name']) {
                    $c['name'] = $nn;
                    $m++;
                }
            }
        }
        unset($c);
        if ($m) {
            $tally['other']++;
            if (!$dry) {
                update_option('bricks_global_classes', $bricks);
            }
        }
    }

    $tally['total'] = $tally['rules'] + $tally['pages'] + $tally['changes'] + $tally['other'];

    return $tally;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('nibwp/v1', '/studio/class-rename', [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => current_user_can('manage_options') && current_user_can('unfiltered_html'),
        'callback' => 'nibwp_studio_rest_class_rename',
    ]);
});

function nibwp_studio_rest_class_rename(WP_REST_Request $request): WP_REST_Response
{
    $kind = (string) $request->get_param('kind') === '#' ? '#' : '.';
    $from = (string) $request->get_param('from');
    $to = (string) $request->get_param('to');
    if (!nibwp_studio_class_name_ok($from) || !nibwp_studio_class_name_ok($to)) {
        return new WP_REST_Response(['message' => __('Use letters, digits, dashes and underscores, starting with a letter.', 'nibwp')], 400);
    }
    if ($kind === '#' && (preg_match('/^[0-9a-f]{3,8}$/i', $from) || preg_match('/^[0-9a-f]{3,8}$/i', $to))) {
        return new WP_REST_Response(['message' => __('That ID reads like a colour code; pick another name.', 'nibwp')], 400);
    }
    if ($from === $to) {
        return new WP_REST_Response(['message' => __('That is already its name.', 'nibwp')], 400);
    }
    // A name already in use would merge two things into one, and its undo
    // would split them wrongly: refuse it.
    $taken = nibwp_studio_rename_class_site($kind, $to, $to . '-x', true);
    if ($taken['total'] > 0) {
        return new WP_REST_Response(['message' => sprintf(
            /* translators: %s: a class like .hero or an ID like #hero */
            __('%s is already used on the site; pick another name.', 'nibwp'),
            $kind . $to
        )], 409);
    }
    $dry = (bool) $request->get_param('dry');
    $done = nibwp_studio_rename_class_site($kind, $from, $to, $dry);
    $css = get_option('nibwp_builder_css', []);

    return new WP_REST_Response($done + ['css' => (object) (is_array($css) ? $css : [])]);
}
