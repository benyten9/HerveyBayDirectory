<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — persister.
 *
 * The single write gate. Re-validates the payload (defense in depth — never
 * trust that the dry-run pass still holds) and only then writes through the
 * engine adapter. Returns a WP_Error carrying the failures when validation does
 * not pass, so no bad SEO data ever lands. Mirrors etchwp-pro/lib/persister.php.
 */

/**
 * Persist normalized SEO fields to a post.
 *
 * @param array<string,mixed> $fields normalized: title/description/canonical/
 *                                    focus_keyword/og_title/og_description/
 *                                    noindex/nofollow
 * @param array<string,mixed> $ctx    validation context (limits, is_front,
 *                                    existing_titles, post_id, schema?)
 * @return array{persisted:bool,post_id:int,changed:array<int,string>,engine:string}|WP_Error
 */
function nibwp_seo_pro_persist(int $post_id, array $fields, array $ctx = []): array|WP_Error
{
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id), ['status' => 404]);
    }

    $ctx['post_id'] = $post_id;
    $verdict = nibwp_seo_pro_validate($fields, $ctx);
    if (!$verdict['passed']) {
        return new WP_Error(
            'seo_pro_validation_failed',
            'Validation failed at the persist gate — fix the issues and resubmit.',
            ['status' => 422, 'failed' => $verdict['failed'], 'warnings' => $verdict['warnings']],
        );
    }

    $engine = nibwp_seo_pro_engine();
    if ($engine === null) {
        return new WP_Error('no_seo_engine', 'No supported SEO engine is active (Yoast, Rank Math, AIOSEO, SEOPress or Slim SEO).', ['status' => 409]);
    }

    // Only pass through the writable normalized fields.
    $writable = array_intersect_key($fields, array_flip([
        'title', 'description', 'canonical', 'focus_keyword', 'og_title', 'og_description', 'noindex', 'nofollow',
    ]));
    $changed = nibwp_seo_pro_write($post_id, $writable);

    return [
        'persisted' => true,
        'post_id'   => $post_id,
        'changed'   => $changed,
        'engine'    => $engine['slug'],
        'warnings'  => $verdict['warnings'],
    ];
}

/**
 * Build a {normalized-title => post_id} index across a set of post types, for
 * duplicate / cannibalization detection. Cached per-request.
 *
 * @return array<string,int>
 */
function nibwp_seo_pro_title_index(array $post_types = ['post', 'page'], int $limit = 2000): array
{
    static $cache = [];
    $ck = md5(implode(',', $post_types) . '|' . $limit);
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $index = [];
    $q = new WP_Query([
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    foreach ($q->posts as $pid) {
        $pid = (int) $pid;
        $rec = nibwp_seo_pro_read($pid);
        $title = (string) ($rec['title'] !== '' ? $rec['title'] : get_the_title($pid));
        $key = nibwp_seo_pro_norm_key($title);
        if ($key !== '' && !isset($index[$key])) {
            $index[$key] = $pid;
        }
    }
    $cache[$ck] = $index;
    return $index;
}

/**
 * Resolve the effective validation/scoring options from the cached preflight
 * answers (length limits, target post types, min words). Safe defaults when no
 * preflight data is present.
 *
 * @param array<string,mixed> $answers preflight answers (from token consume)
 * @return array<string,mixed>
 */
function nibwp_seo_pro_opts(array $answers = []): array
{
    $limits = (array) ($answers['length_limits'] ?? []);
    return [
        'title_max' => (int) ($limits['title_max'] ?? 60),
        'title_min' => (int) ($limits['title_min'] ?? 30),
        'desc_max'  => (int) ($limits['desc_max'] ?? 160),
        'desc_min'  => (int) ($limits['desc_min'] ?? 50),
        'min_words' => (int) ($answers['min_words'] ?? 300),
        'post_types'=> (array) ($answers['target_post_types'] ?? ['post', 'page']),
        'brand_voice' => (string) ($answers['brand_voice'] ?? ''),
        'market_locale' => (string) ($answers['market_locale'] ?? ''),
    ];
}
