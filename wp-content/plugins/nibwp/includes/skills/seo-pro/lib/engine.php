<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — engine adapter.
 *
 * One normalized read/write surface over every supported SEO engine:
 * Yoast, Rank Math, All in One SEO, SEOPress and Slim SEO. The rest of the
 * skill (audit, meta, schema, fix, migrate…) talks only to these functions and
 * never touches engine-specific meta keys directly.
 *
 * Normalized record shape:
 *   [
 *     'title'         => string,
 *     'description'   => string,
 *     'focus_keyword' => string,
 *     'canonical'     => string,
 *     'og_title'      => string,
 *     'og_description'=> string,
 *     'noindex'       => bool,
 *     'nofollow'      => bool,
 *   ]
 */

/** Per-engine map: normalized field => post-meta key (simple string fields). */
function nibwp_seo_pro_field_map(): array
{
    return [
        'yoast' => [
            'title'          => '_yoast_wpseo_title',
            'description'    => '_yoast_wpseo_metadesc',
            'focus_keyword'  => '_yoast_wpseo_focuskw',
            'canonical'      => '_yoast_wpseo_canonical',
            'og_title'       => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description',
        ],
        'rankmath' => [
            'title'          => 'rank_math_title',
            'description'    => 'rank_math_description',
            'focus_keyword'  => 'rank_math_focus_keyword',
            'canonical'      => 'rank_math_canonical_url',
            'og_title'       => 'rank_math_facebook_title',
            'og_description' => 'rank_math_facebook_description',
        ],
        'aioseo' => [
            'title'          => '_aioseo_title',
            'description'    => '_aioseo_description',
            'focus_keyword'  => '_aioseo_keywords',
            'og_title'       => '_aioseo_og_title',
            'og_description' => '_aioseo_og_description',
        ],
        'seopress' => [
            'title'          => '_seopress_titles_title',
            'description'    => '_seopress_titles_desc',
            'focus_keyword'  => '_seopress_analysis_target_kw',
            'canonical'      => '_seopress_robots_canonical',
            'og_title'       => '_seopress_social_fb_title',
            'og_description' => '_seopress_social_fb_desc',
        ],
        // Slim SEO stores everything in one array meta key — handled specially.
        'slimseo' => [],
    ];
}

/** Detect the active SEO engine. Returns ['name','slug'] or null. */
function nibwp_seo_pro_engine(): ?array
{
    if (function_exists('nibwp_seo_detect_plugin')) {
        $p = nibwp_seo_detect_plugin();
        if (is_array($p)) {
            return $p;
        }
    }
    if (defined('SEOPRESS_VERSION')) {
        return ['name' => 'SEOPress', 'slug' => 'seopress'];
    }
    if (defined('SLIM_SEO_VER') || function_exists('slim_seo')) {
        return ['name' => 'Slim SEO', 'slug' => 'slimseo'];
    }
    return null;
}

/** Every SEO engine installed on the site (for migration). */
function nibwp_seo_pro_engines_present(): array
{
    $present = [];
    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) { $present[] = ['name' => 'Yoast SEO', 'slug' => 'yoast']; }
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) { $present[] = ['name' => 'Rank Math', 'slug' => 'rankmath']; }
    if (defined('AIOSEO_VERSION')) { $present[] = ['name' => 'All in One SEO', 'slug' => 'aioseo']; }
    if (defined('SEOPRESS_VERSION')) { $present[] = ['name' => 'SEOPress', 'slug' => 'seopress']; }
    if (defined('SLIM_SEO_VER') || function_exists('slim_seo')) { $present[] = ['name' => 'Slim SEO', 'slug' => 'slimseo']; }
    return $present;
}

/** Normalized empty record. */
function nibwp_seo_pro_blank(): array
{
    return [
        'title' => '', 'description' => '', 'focus_keyword' => '', 'canonical' => '',
        'og_title' => '', 'og_description' => '', 'noindex' => false, 'nofollow' => false,
    ];
}

/**
 * Read the normalized SEO record for a post from a specific engine (defaults to
 * the active one).
 */
function nibwp_seo_pro_read(int $post_id, ?string $slug = null): array
{
    $slug = $slug ?: (nibwp_seo_pro_engine()['slug'] ?? '');
    $rec  = nibwp_seo_pro_blank();
    if ($slug === '' || $post_id <= 0) {
        return $rec;
    }

    if ($slug === 'slimseo') {
        $data = get_post_meta($post_id, 'slim_seo', true);
        $data = is_array($data) ? $data : [];
        $rec['title']        = (string) ($data['title'] ?? '');
        $rec['description']  = (string) ($data['description'] ?? '');
        $rec['canonical']    = (string) ($data['canonical'] ?? '');
        $rec['og_title']     = (string) ($data['facebook_title'] ?? '');
        $rec['og_description'] = (string) ($data['facebook_description'] ?? '');
        $rec['noindex']      = !empty($data['noindex']);
        $rec['nofollow']     = !empty($data['nofollow']);
        return $rec;
    }

    $map = nibwp_seo_pro_field_map()[$slug] ?? [];
    foreach ($map as $norm => $key) {
        $rec[$norm] = (string) get_post_meta($post_id, $key, true);
    }
    $rec = nibwp_seo_pro_read_robots($post_id, $slug, $rec);
    return $rec;
}

/** Read engine-specific robots into normalized noindex/nofollow. */
function nibwp_seo_pro_read_robots(int $post_id, string $slug, array $rec): array
{
    switch ($slug) {
        case 'yoast':
            $rec['noindex'] = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1';
            $rec['nofollow'] = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true) === '1';
            break;
        case 'rankmath':
            $r = get_post_meta($post_id, 'rank_math_robots', true);
            $r = is_array($r) ? $r : [];
            $rec['noindex'] = in_array('noindex', $r, true);
            $rec['nofollow'] = in_array('nofollow', $r, true);
            break;
        case 'aioseo':
            $rec['noindex'] = get_post_meta($post_id, '_aioseo_noindex', true) === '1';
            $rec['nofollow'] = get_post_meta($post_id, '_aioseo_nofollow', true) === '1';
            break;
        case 'seopress':
            $rec['noindex'] = get_post_meta($post_id, '_seopress_robots_index', true) === 'yes';
            $rec['nofollow'] = get_post_meta($post_id, '_seopress_robots_follow', true) === 'yes';
            break;
    }
    return $rec;
}

/**
 * Write normalized fields to a post via a specific engine (defaults to active).
 * Only keys present in $fields are written. Returns the changed normalized keys.
 *
 * @param array<string,mixed> $fields
 * @return string[]
 */
function nibwp_seo_pro_write(int $post_id, array $fields, ?string $slug = null): array
{
    $slug = $slug ?: (nibwp_seo_pro_engine()['slug'] ?? '');
    if ($slug === '' || $post_id <= 0) {
        return [];
    }
    $changed = [];

    if ($slug === 'slimseo') {
        $data = get_post_meta($post_id, 'slim_seo', true);
        $data = is_array($data) ? $data : [];
        $ss = [
            'title' => 'title', 'description' => 'description', 'canonical' => 'canonical',
            'og_title' => 'facebook_title', 'og_description' => 'facebook_description',
        ];
        foreach ($ss as $norm => $key) {
            if (array_key_exists($norm, $fields)) {
                $val = sanitize_text_field((string) $fields[$norm]);
                if ($val === '') { unset($data[$key]); } else { $data[$key] = $val; }
                $changed[] = $norm;
            }
        }
        foreach (['noindex', 'nofollow'] as $flag) {
            if (array_key_exists($flag, $fields)) {
                if (!empty($fields[$flag])) { $data[$flag] = true; } else { unset($data[$flag]); }
                $changed[] = $flag;
            }
        }
        if ($data === []) { delete_post_meta($post_id, 'slim_seo'); } else { update_post_meta($post_id, 'slim_seo', $data); }
        return $changed;
    }

    $map = nibwp_seo_pro_field_map()[$slug] ?? [];
    foreach ($map as $norm => $key) {
        if (array_key_exists($norm, $fields)) {
            update_post_meta($post_id, $key, sanitize_text_field((string) $fields[$norm]));
            $changed[] = $norm;
        }
    }
    foreach (['noindex', 'nofollow'] as $flag) {
        if (array_key_exists($flag, $fields)) {
            nibwp_seo_pro_write_robot_flag($post_id, $slug, $flag, !empty($fields[$flag]));
            $changed[] = $flag;
        }
    }
    return $changed;
}

/** Write a single engine-specific robot flag. */
function nibwp_seo_pro_write_robot_flag(int $post_id, string $slug, string $flag, bool $on): void
{
    switch ($slug) {
        case 'yoast':
            update_post_meta($post_id, $flag === 'noindex' ? '_yoast_wpseo_meta-robots-noindex' : '_yoast_wpseo_meta-robots-nofollow', $on ? '1' : '0');
            break;
        case 'rankmath':
            $r = get_post_meta($post_id, 'rank_math_robots', true);
            $r = is_array($r) ? $r : [];
            $r = array_values(array_diff($r, [$flag]));
            if ($on) { $r[] = $flag; }
            update_post_meta($post_id, 'rank_math_robots', $r);
            break;
        case 'aioseo':
            update_post_meta($post_id, $flag === 'noindex' ? '_aioseo_noindex' : '_aioseo_nofollow', $on ? '1' : '0');
            break;
        case 'seopress':
            update_post_meta($post_id, $flag === 'noindex' ? '_seopress_robots_index' : '_seopress_robots_follow', $on ? 'yes' : '');
            break;
    }
}
