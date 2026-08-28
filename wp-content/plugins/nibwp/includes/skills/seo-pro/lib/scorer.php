<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEO Pro — scorer.
 *
 * Turns a post's normalized SEO record + its content into a 0-100 score and a
 * weighted, prioritized issue list. The audit and the pre-publish gate both
 * call this so one rule set drives scoring everywhere.
 */

/** Issue severity weights (points deducted). */
function nibwp_seo_pro_weights(): array
{
    return ['high' => 15, 'medium' => 8, 'low' => 3];
}

/**
 * Score a single post.
 *
 * @param array<string,mixed> $rec  normalized record from nibwp_seo_pro_read()
 * @param array<string,mixed> $opts title_max(60), title_min(30), desc_max(160),
 *                                  desc_min(50), min_words(300), is_front(bool),
 *                                  has_schema(bool|null), existing_titles(map)
 * @return array{score:int,issues:array<int,array{id:string,severity:string,field:string,msg:string}>}
 */
function nibwp_seo_pro_score_post(int $post_id, array $rec, array $opts = []): array
{
    $title_max = (int) ($opts['title_max'] ?? 60);
    $title_min = (int) ($opts['title_min'] ?? 30);
    $desc_max  = (int) ($opts['desc_max'] ?? 160);
    $desc_min  = (int) ($opts['desc_min'] ?? 50);
    $min_words = (int) ($opts['min_words'] ?? 300);

    $post = get_post($post_id);
    $issues = [];
    $len = static fn (string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

    // Title.
    if (($rec['title'] ?? '') === '') {
        $issues[] = ['id' => 'missing_title', 'severity' => 'medium', 'field' => 'title', 'msg' => 'No custom SEO title (falling back to the post title + template).'];
    } else {
        $t = $len((string) $rec['title']);
        if ($t > $title_max) { $issues[] = ['id' => 'title_too_long', 'severity' => 'medium', 'field' => 'title', 'msg' => sprintf('SEO title is %d chars (max %d).', $t, $title_max)]; }
        elseif ($t < $title_min) { $issues[] = ['id' => 'title_too_short', 'severity' => 'low', 'field' => 'title', 'msg' => sprintf('SEO title is only %d chars.', $t)]; }
    }

    // Description.
    if (($rec['description'] ?? '') === '') {
        $issues[] = ['id' => 'missing_description', 'severity' => 'high', 'field' => 'description', 'msg' => 'No meta description — search engines will improvise one.'];
    } else {
        $d = $len((string) $rec['description']);
        if ($d > $desc_max) { $issues[] = ['id' => 'desc_too_long', 'severity' => 'medium', 'field' => 'description', 'msg' => sprintf('Meta description is %d chars (max %d).', $d, $desc_max)]; }
        elseif ($d < $desc_min) { $issues[] = ['id' => 'desc_too_short', 'severity' => 'low', 'field' => 'description', 'msg' => sprintf('Meta description is only %d chars.', $d)]; }
    }

    // Noindex on a public, published post (likely a mistake unless intended).
    if (!empty($rec['noindex']) && $post && $post->post_status === 'publish') {
        $issues[] = ['id' => 'noindex_published', 'severity' => empty($opts['is_front']) ? 'low' : 'high', 'field' => 'noindex', 'msg' => 'This published page is set to noindex — confirm that is intentional.'];
    }

    // Content checks.
    if ($post) {
        $content = (string) $post->post_content;
        $text = trim(wp_strip_all_tags($content));
        $words = $text === '' ? 0 : str_word_count($text);
        if ($words > 0 && $words < $min_words) {
            $issues[] = ['id' => 'thin_content', 'severity' => 'medium', 'field' => 'content', 'msg' => sprintf('Thin content: ~%d words (aim for %d+).', $words, $min_words)];
        }
        if (!preg_match('/<h1[\s>]/i', $content) && strpos($content, 'wp:heading {"level":1') === false) {
            $issues[] = ['id' => 'missing_h1', 'severity' => 'low', 'field' => 'content', 'msg' => 'No H1 found in the content (the theme may render one from the title).'];
        }
        $imgs = nibwp_seo_pro_images_without_alt($content);
        if ($imgs > 0) {
            $issues[] = ['id' => 'images_missing_alt', 'severity' => 'medium', 'field' => 'images', 'msg' => sprintf('%d image(s) in the content have no alt text.', $imgs)];
        }
    }

    // Canonical (informational/low).
    if (($rec['canonical'] ?? '') === '') {
        // Not an issue by itself (WP self-canonicalizes); skip unless duplicate detection elsewhere flags it.
    }

    $weights = nibwp_seo_pro_weights();
    $deduct = 0;
    foreach ($issues as $i) { $deduct += $weights[$i['severity']] ?? 0; }
    $score = max(0, 100 - $deduct);

    return ['score' => $score, 'issues' => $issues];
}

/** Count <img> tags in HTML that lack a non-empty alt attribute. */
function nibwp_seo_pro_images_without_alt(string $html): int
{
    if (stripos($html, '<img') === false) { return 0; }
    $count = 0;
    if (preg_match_all('/<img\b[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/\balt\s*=\s*("[^"]+"|\'[^\']+\')/i', $tag)) {
                $count++;
            }
        }
    }
    return $count;
}

/**
 * Aggregate a site score from per-post results.
 *
 * @param array<int,array{score:int,issues:array}> $results
 * @return array{site_score:int,posts:int,coverage:array<string,int>,top_issues:array<string,int>}
 */
function nibwp_seo_pro_score_site(array $results): array
{
    $n = count($results);
    if ($n === 0) {
        return ['site_score' => 0, 'posts' => 0, 'coverage' => [], 'top_issues' => []];
    }
    $sum = 0;
    $issue_counts = [];
    $with_title = 0; $with_desc = 0;
    foreach ($results as $r) {
        $sum += (int) ($r['score'] ?? 0);
        $has_title = true; $has_desc = true;
        foreach ((array) ($r['issues'] ?? []) as $iss) {
            $id = (string) $iss['id'];
            $issue_counts[$id] = ($issue_counts[$id] ?? 0) + 1;
            if ($id === 'missing_title') { $has_title = false; }
            if ($id === 'missing_description') { $has_desc = false; }
        }
        if ($has_title) { $with_title++; }
        if ($has_desc) { $with_desc++; }
    }
    arsort($issue_counts);
    return [
        'site_score' => (int) round($sum / $n),
        'posts'      => $n,
        'coverage'   => [
            'seo_title_pct'       => (int) round($with_title / $n * 100),
            'meta_description_pct'=> (int) round($with_desc / $n * 100),
        ],
        'top_issues' => array_slice($issue_counts, 0, 10, true),
    ];
}
