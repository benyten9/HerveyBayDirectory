<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detect which SEO plugin is active.
 *
 * @return array{name: string, slug: string}|null
 */
function nibwp_seo_detect_plugin(): ?array
{
    // Yoast SEO.
    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
        return ['name' => 'Yoast SEO', 'slug' => 'yoast'];
    }

    // Rank Math.
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        return ['name' => 'Rank Math', 'slug' => 'rankmath'];
    }

    // All in One SEO.
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) {
        return ['name' => 'All in One SEO', 'slug' => 'aioseo'];
    }

    return null;
}

// --- Ability: nibwp/seo-get-post-meta ---

wp_register_ability('nibwp/seo-get-post-meta', [
    'label' => __('SEO – Get Post Meta', domain: 'nibwp'),
    'description' => __(
        'Get SEO metadata for a post. Auto-detects active SEO plugin (Yoast, Rank Math, or AIOSEO) and returns normalized SEO data.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The post ID to get SEO metadata for.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'plugin_name' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'focus_keyword' => ['type' => 'string'],
            'canonical' => ['type' => 'string'],
            'og_title' => ['type' => 'string'],
            'og_description' => ['type' => 'string'],
            'robots' => ['type' => 'string'],
            'seo_score' => ['description' => 'SEO score if available from the plugin.'],
        ],
    ],
    'execute_callback' => 'nibwp_seo_get_post_meta',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Get SEO metadata for a single post/page.',
                '',
                'AUTO-DETECTS the active SEO plugin:',
                '- Yoast SEO: reads _yoast_wpseo_* meta fields.',
                '- Rank Math: reads rank_math_* meta fields.',
                '- All in One SEO: reads _aioseo_* meta fields.',
                '',
                'RETURNED FIELDS (normalized across all plugins):',
                '- title: SEO title / meta title.',
                '- description: Meta description.',
                '- focus_keyword: Primary focus keyword.',
                '- canonical: Canonical URL override.',
                '- og_title / og_description: Open Graph overrides.',
                '- robots: Robot directives (noindex, nofollow, etc.).',
                '- seo_score: Numeric score if the plugin provides one.',
                '- plugin_name: Which SEO plugin was detected.',
                '',
                'Returns WP_Error if no SEO plugin is active or post not found.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_get_post_meta(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    $plugin = nibwp_seo_detect_plugin();
    if (!$plugin) {
        return new WP_Error('no_seo_plugin', 'No supported SEO plugin detected. Install and activate Yoast SEO, Rank Math, or All in One SEO.');
    }

    $data = [
        'post_id' => $post_id,
        'plugin_name' => $plugin['name'],
        'title' => '',
        'description' => '',
        'focus_keyword' => '',
        'canonical' => '',
        'og_title' => '',
        'og_description' => '',
        'robots' => '',
        'seo_score' => null,
    ];

    switch ($plugin['slug']) {
        case 'yoast':
            $data['title'] = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
            $data['description'] = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            $data['focus_keyword'] = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            $data['canonical'] = (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true);
            $data['og_title'] = (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true);
            $data['og_description'] = (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true);

            // Build robots string from individual meta.
            $robots_parts = [];
            $noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
            $nofollow = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);
            if ($noindex === '1') $robots_parts[] = 'noindex';
            if ($nofollow === '1') $robots_parts[] = 'nofollow';
            $advanced = get_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', true);
            if ($advanced && $advanced !== 'none') {
                $robots_parts = array_merge($robots_parts, explode(',', $advanced));
            }
            $data['robots'] = implode(', ', $robots_parts);

            // Yoast SEO score.
            $linkdex = get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
            if ($linkdex !== '') {
                $data['seo_score'] = (int) $linkdex;
            }
            break;

        case 'rankmath':
            $data['title'] = (string) get_post_meta($post_id, 'rank_math_title', true);
            $data['description'] = (string) get_post_meta($post_id, 'rank_math_description', true);
            $data['focus_keyword'] = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
            $data['canonical'] = (string) get_post_meta($post_id, 'rank_math_canonical_url', true);
            $data['og_title'] = (string) get_post_meta($post_id, 'rank_math_facebook_title', true);
            $data['og_description'] = (string) get_post_meta($post_id, 'rank_math_facebook_description', true);

            $robots_meta = get_post_meta($post_id, 'rank_math_robots', true);
            if (is_array($robots_meta)) {
                $data['robots'] = implode(', ', $robots_meta);
            } elseif (is_string($robots_meta)) {
                $data['robots'] = $robots_meta;
            }

            $seo_score = get_post_meta($post_id, 'rank_math_seo_score', true);
            if ($seo_score !== '') {
                $data['seo_score'] = (int) $seo_score;
            }
            break;

        case 'aioseo':
            $data['title'] = (string) get_post_meta($post_id, '_aioseo_title', true);
            $data['description'] = (string) get_post_meta($post_id, '_aioseo_description', true);
            $data['focus_keyword'] = (string) get_post_meta($post_id, '_aioseo_keywords', true);
            $data['og_title'] = (string) get_post_meta($post_id, '_aioseo_og_title', true);
            $data['og_description'] = (string) get_post_meta($post_id, '_aioseo_og_description', true);

            // AIOSEO also stores data in its own table; try the object if available.
            if (function_exists('aioseo') && method_exists(aioseo()->meta, 'getMetaData')) {
                $aioseo_meta = aioseo()->meta->getMetaData($post_id);
                if ($aioseo_meta) {
                    if (!empty($aioseo_meta->title)) $data['title'] = $aioseo_meta->title;
                    if (!empty($aioseo_meta->description)) $data['description'] = $aioseo_meta->description;
                    if (!empty($aioseo_meta->keywords)) $data['focus_keyword'] = $aioseo_meta->keywords;
                    if (!empty($aioseo_meta->canonical_url)) $data['canonical'] = $aioseo_meta->canonical_url;
                    if (!empty($aioseo_meta->og_title)) $data['og_title'] = $aioseo_meta->og_title;
                    if (!empty($aioseo_meta->og_description)) $data['og_description'] = $aioseo_meta->og_description;
                    if (isset($aioseo_meta->robots_noindex) && $aioseo_meta->robots_noindex) {
                        $data['robots'] = 'noindex';
                    }
                    if (isset($aioseo_meta->seo_score)) {
                        $data['seo_score'] = (int) $aioseo_meta->seo_score;
                    }
                }
            }
            break;
    }

    return $data;
}

// --- Ability: nibwp/seo-update-post-meta ---

wp_register_ability('nibwp/seo-update-post-meta', [
    'label' => __('SEO – Update Post Meta', domain: 'nibwp'),
    'description' => __(
        'Update SEO metadata for a post. Auto-detects the active SEO plugin and updates the appropriate meta fields.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The post ID to update.'],
            'title' => ['type' => 'string', 'description' => 'SEO title.'],
            'description' => ['type' => 'string', 'description' => 'Meta description.'],
            'focus_keyword' => ['type' => 'string', 'description' => 'Primary focus keyword.'],
            'canonical' => ['type' => 'string', 'description' => 'Canonical URL override.'],
            'og_title' => ['type' => 'string', 'description' => 'Open Graph title.'],
            'og_description' => ['type' => 'string', 'description' => 'Open Graph description.'],
            'robots' => ['type' => 'string', 'description' => 'Robots directives (e.g. "noindex, nofollow").'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'plugin_name' => ['type' => 'string'],
            'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_seo_update_post_meta',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Update SEO metadata for a post/page.',
                '',
                'Only fields you include will be updated; omitted fields stay unchanged.',
                '',
                'AUTO-DETECTS the active SEO plugin and writes to the correct meta keys:',
                '- Yoast SEO: _yoast_wpseo_* fields.',
                '- Rank Math: rank_math_* fields.',
                '- All in One SEO: _aioseo_* fields and AIOSEO table if available.',
                '',
                'ROBOTS:',
                '- Pass a comma-separated string: "noindex, nofollow".',
                '- Common values: noindex, nofollow, noarchive, noimageindex, nosnippet.',
                '',
                'TIP: Use seo-get-post-meta first to see current values before updating.',
                '',
                'Returns the list of fields that were updated and the detected plugin.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_update_post_meta(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('invalid_post_id', 'post_id must be a positive integer.');
    }

    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
    }

    $plugin = nibwp_seo_detect_plugin();
    if (!$plugin) {
        return new WP_Error('no_seo_plugin', 'No supported SEO plugin detected. Install and activate Yoast SEO, Rank Math, or All in One SEO.');
    }

    $updated_fields = [];

    // Map of normalized field => plugin-specific meta keys.
    $field_map = [
        'yoast' => [
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
            'og_title' => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description',
        ],
        'rankmath' => [
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
            'og_title' => 'rank_math_facebook_title',
            'og_description' => 'rank_math_facebook_description',
        ],
        'aioseo' => [
            'title' => '_aioseo_title',
            'description' => '_aioseo_description',
            'focus_keyword' => '_aioseo_keywords',
            'og_title' => '_aioseo_og_title',
            'og_description' => '_aioseo_og_description',
        ],
    ];

    $slug = $plugin['slug'];
    $fields = $field_map[$slug] ?? [];

    // Update simple meta fields.
    foreach ($fields as $normalized => $meta_key) {
        if (array_key_exists($normalized, $input)) {
            update_post_meta($post_id, $meta_key, (string) $input[$normalized]);
            $updated_fields[] = $normalized;
        }
    }

    // Handle robots separately per plugin.
    if (array_key_exists('robots', $input)) {
        $robots = (string) $input['robots'];
        $robots_values = array_map('trim', explode(',', $robots));

        switch ($slug) {
            case 'yoast':
                $has_noindex = in_array('noindex', $robots_values, true);
                $has_nofollow = in_array('nofollow', $robots_values, true);
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $has_noindex ? '1' : '0');
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', $has_nofollow ? '1' : '0');
                $advanced = array_filter($robots_values, fn($v) => !in_array($v, ['noindex', 'nofollow'], true));
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-adv', !empty($advanced) ? implode(',', $advanced) : 'none');
                break;

            case 'rankmath':
                update_post_meta($post_id, 'rank_math_robots', $robots_values);
                break;

            case 'aioseo':
                // AIOSEO stores robots as individual flags.
                $noindex = in_array('noindex', $robots_values, true);
                $nofollow = in_array('nofollow', $robots_values, true);
                // Try AIOSEO API first.
                if (function_exists('aioseo') && method_exists(aioseo()->meta, 'updateMetaData')) {
                    aioseo()->meta->updateMetaData($post_id, [
                        'robots_noindex' => $noindex,
                        'robots_nofollow' => $nofollow,
                    ]);
                } else {
                    update_post_meta($post_id, '_aioseo_noindex', $noindex ? '1' : '0');
                    update_post_meta($post_id, '_aioseo_nofollow', $nofollow ? '1' : '0');
                }
                break;
        }

        $updated_fields[] = 'robots';
    }

    // Handle canonical for AIOSEO separately (not in the field_map).
    if ($slug === 'aioseo' && array_key_exists('canonical', $input)) {
        if (function_exists('aioseo') && method_exists(aioseo()->meta, 'updateMetaData')) {
            aioseo()->meta->updateMetaData($post_id, [
                'canonical_url' => (string) $input['canonical'],
            ]);
        } else {
            update_post_meta($post_id, '_aioseo_canonical_url', (string) $input['canonical']);
        }
        $updated_fields[] = 'canonical';
    }

    return [
        'post_id' => $post_id,
        'plugin_name' => $plugin['name'],
        'updated_fields' => array_unique($updated_fields),
    ];
}

// --- Ability: nibwp/seo-analyze-content ---

wp_register_ability('nibwp/seo-analyze-content', [
    'label' => __('SEO – Analyze Content', domain: 'nibwp'),
    'description' => __(
        'Analyze content for SEO quality. Checks keyword density, title length, meta description, readability, internal/external links, image alt text, and more.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID to analyze. If provided, content and title are read from the post.',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Raw HTML content to analyze. Used if post_id is not provided.',
            ],
            'focus_keyword' => [
                'type' => 'string',
                'description' => 'The focus keyword to check for.',
            ],
        ],
        'required' => ['focus_keyword'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'analysis' => ['type' => 'object', 'description' => 'Detailed SEO analysis results.'],
            'score' => ['type' => 'integer', 'description' => 'Overall SEO score 0-100.'],
            'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_seo_analyze_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Analyze content for SEO quality against a focus keyword.',
                '',
                'INPUT OPTIONS (provide one):',
                '- post_id: Analyze an existing post (reads title, content, meta from DB).',
                '- content: Analyze raw HTML content directly.',
                '- focus_keyword is always required.',
                '',
                'ANALYSIS CHECKS:',
                '- Title: Length (optimal 50-60 chars), keyword presence.',
                '- Meta description: Length (optimal 120-160 chars), keyword presence.',
                '- Content: Word count, keyword density (optimal 1-3%), keyword in first paragraph.',
                '- Headings: H1 presence, keyword in headings.',
                '- Links: Internal and external link counts.',
                '- Images: Alt text presence, keyword in alt text.',
                '- Readability: Flesch-Kincaid readability score.',
                '',
                'RETURNS:',
                '- Detailed analysis object with individual check results.',
                '- Overall score (0-100).',
                '- Actionable suggestions for improvement.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_analyze_content(array $input): array|WP_Error
{
    $focus_keyword = trim((string) ($input['focus_keyword'] ?? ''));
    if ($focus_keyword === '') {
        return new WP_Error('missing_keyword', 'focus_keyword is required.');
    }

    $title = '';
    $content = '';
    $meta_description = '';

    if (!empty($input['post_id'])) {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id));
        }
        $title = $post->post_title;
        $content = $post->post_content;

        // Try to get meta description from SEO plugin.
        $plugin = nibwp_seo_detect_plugin();
        if ($plugin) {
            switch ($plugin['slug']) {
                case 'yoast':
                    $meta_description = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                    break;
                case 'rankmath':
                    $meta_description = (string) get_post_meta($post_id, 'rank_math_description', true);
                    break;
                case 'aioseo':
                    $meta_description = (string) get_post_meta($post_id, '_aioseo_description', true);
                    break;
            }
        }
    } elseif (!empty($input['content'])) {
        $content = (string) $input['content'];
    } else {
        return new WP_Error('no_content', 'Provide either post_id or content to analyze.');
    }

    $keyword_lower = mb_strtolower($focus_keyword);

    // Strip HTML for text analysis.
    $text = wp_strip_all_tags($content);
    $text_lower = mb_strtolower($text);
    $word_count = str_word_count($text);

    // Keyword density.
    $keyword_count = mb_substr_count($text_lower, $keyword_lower);
    $keyword_density = $word_count > 0 ? round(($keyword_count / $word_count) * 100, 2) : 0;

    // Title analysis.
    $title_length = mb_strlen($title);
    $keyword_in_title = mb_stripos($title, $focus_keyword) !== false;

    // Meta description analysis.
    $meta_desc_length = mb_strlen($meta_description);
    $keyword_in_meta = $meta_description !== '' && mb_stripos($meta_description, $focus_keyword) !== false;

    // First paragraph check.
    $first_para = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $content, $m)) {
        $first_para = wp_strip_all_tags($m[1]);
    } elseif ($text !== '') {
        $first_para = mb_substr($text, 0, 300);
    }
    $keyword_in_first_para = mb_stripos($first_para, $focus_keyword) !== false;

    // Headings analysis.
    $h1_count = 0;
    $keyword_in_headings = false;
    if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $heading_matches)) {
        foreach ($heading_matches[1] as $i => $level) {
            if ($level === '1') $h1_count++;
            $heading_text = wp_strip_all_tags($heading_matches[2][$i]);
            if (mb_stripos($heading_text, $focus_keyword) !== false) {
                $keyword_in_headings = true;
            }
        }
    }

    // Links analysis.
    $internal_links = 0;
    $external_links = 0;
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $link_matches)) {
        foreach ($link_matches[1] as $href) {
            $link_host = wp_parse_url($href, PHP_URL_HOST);
            if ($link_host === null || $link_host === '' || $link_host === $site_host) {
                $internal_links++;
            } else {
                $external_links++;
            }
        }
    }

    // Images analysis.
    $images_total = 0;
    $images_with_alt = 0;
    $keyword_in_alt = false;
    if (preg_match_all('/<img\s[^>]*>/i', $content, $img_matches)) {
        $images_total = count($img_matches[0]);
        foreach ($img_matches[0] as $img_tag) {
            if (preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match)) {
                if (trim($alt_match[1]) !== '') {
                    $images_with_alt++;
                    if (mb_stripos($alt_match[1], $focus_keyword) !== false) {
                        $keyword_in_alt = true;
                    }
                }
            }
        }
    }

    // Flesch-Kincaid readability (simplified).
    $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $sentence_count = max(count($sentences), 1);
    $syllable_count = nibwp_seo_count_syllables($text);
    $flesch_score = $word_count > 0
        ? round(206.835 - 1.015 * ($word_count / $sentence_count) - 84.6 * ($syllable_count / $word_count), 1)
        : 0;
    $flesch_score = max(0, min(100, $flesch_score));

    // Score calculation.
    $score = 0;
    $suggestions = [];

    // Title checks (20 points max).
    if ($title !== '') {
        if ($title_length >= 50 && $title_length <= 60) {
            $score += 10;
        } elseif ($title_length >= 30 && $title_length <= 70) {
            $score += 5;
            $suggestions[] = sprintf('Title length is %d characters. Optimal range is 50-60 characters.', $title_length);
        } else {
            $suggestions[] = sprintf('Title length is %d characters. Aim for 50-60 characters.', $title_length);
        }
        if ($keyword_in_title) {
            $score += 10;
        } else {
            $suggestions[] = 'Focus keyword not found in the title. Add it for better rankings.';
        }
    } else {
        $suggestions[] = 'No title found. Add an SEO-optimized title.';
    }

    // Meta description (15 points max).
    if ($meta_description !== '') {
        if ($meta_desc_length >= 120 && $meta_desc_length <= 160) {
            $score += 10;
        } elseif ($meta_desc_length >= 80 && $meta_desc_length <= 200) {
            $score += 5;
            $suggestions[] = sprintf('Meta description is %d characters. Optimal range is 120-160 characters.', $meta_desc_length);
        } else {
            $suggestions[] = sprintf('Meta description is %d characters. Aim for 120-160 characters.', $meta_desc_length);
        }
        if ($keyword_in_meta) {
            $score += 5;
        } else {
            $suggestions[] = 'Focus keyword not found in meta description.';
        }
    } else {
        $suggestions[] = 'No meta description found. Add one for better click-through rates.';
    }

    // Content length (10 points).
    if ($word_count >= 300) {
        $score += 10;
    } elseif ($word_count >= 150) {
        $score += 5;
        $suggestions[] = sprintf('Content is %d words. Aim for at least 300 words.', $word_count);
    } else {
        $suggestions[] = sprintf('Content is only %d words. Aim for at least 300 words for better SEO.', $word_count);
    }

    // Keyword density (10 points).
    if ($keyword_density >= 1.0 && $keyword_density <= 3.0) {
        $score += 10;
    } elseif ($keyword_density > 0 && $keyword_density < 1.0) {
        $score += 5;
        $suggestions[] = sprintf('Keyword density is %.1f%%. Aim for 1-3%%.', $keyword_density);
    } elseif ($keyword_density > 3.0) {
        $score += 3;
        $suggestions[] = sprintf('Keyword density is %.1f%% (over-optimized). Aim for 1-3%%.', $keyword_density);
    } else {
        $suggestions[] = 'Focus keyword not found in content.';
    }

    // Keyword in first paragraph (10 points).
    if ($keyword_in_first_para) {
        $score += 10;
    } else {
        $suggestions[] = 'Focus keyword not found in the first paragraph. Add it early in the content.';
    }

    // Headings (10 points).
    if ($h1_count === 1) {
        $score += 5;
    } elseif ($h1_count === 0) {
        $suggestions[] = 'No H1 heading found. Add one H1 tag with your focus keyword.';
    } else {
        $suggestions[] = sprintf('Found %d H1 tags. Use only one H1 per page.', $h1_count);
    }
    if ($keyword_in_headings) {
        $score += 5;
    } else {
        $suggestions[] = 'Focus keyword not found in any heading. Use it in at least one heading.';
    }

    // Links (10 points).
    if ($internal_links > 0) {
        $score += 5;
    } else {
        $suggestions[] = 'No internal links found. Add links to other pages on your site.';
    }
    if ($external_links > 0) {
        $score += 5;
    } else {
        $suggestions[] = 'No external links found. Link to authoritative sources.';
    }

    // Images (10 points).
    if ($images_total > 0) {
        $score += 3;
        if ($images_with_alt === $images_total) {
            $score += 4;
        } else {
            $suggestions[] = sprintf('%d of %d images missing alt text.', $images_total - $images_with_alt, $images_total);
        }
        if ($keyword_in_alt) {
            $score += 3;
        } else {
            $suggestions[] = 'Focus keyword not found in any image alt text.';
        }
    } else {
        $suggestions[] = 'No images found. Add images with keyword-optimized alt text.';
    }

    // Readability (5 points).
    if ($flesch_score >= 60) {
        $score += 5;
    } elseif ($flesch_score >= 40) {
        $score += 3;
        $suggestions[] = sprintf('Readability score is %.0f (fairly difficult). Aim for 60+ for general audiences.', $flesch_score);
    } else {
        $suggestions[] = sprintf('Readability score is %.0f (difficult). Simplify sentences and use shorter words.', $flesch_score);
    }

    $analysis = [
        'title' => [
            'value' => $title,
            'length' => $title_length,
            'keyword_present' => $keyword_in_title,
        ],
        'meta_description' => [
            'value' => $meta_description,
            'length' => $meta_desc_length,
            'keyword_present' => $keyword_in_meta,
        ],
        'content' => [
            'word_count' => $word_count,
            'keyword_count' => $keyword_count,
            'keyword_density' => $keyword_density,
            'keyword_in_first_paragraph' => $keyword_in_first_para,
        ],
        'headings' => [
            'h1_count' => $h1_count,
            'keyword_in_headings' => $keyword_in_headings,
        ],
        'links' => [
            'internal' => $internal_links,
            'external' => $external_links,
        ],
        'images' => [
            'total' => $images_total,
            'with_alt' => $images_with_alt,
            'keyword_in_alt' => $keyword_in_alt,
        ],
        'readability' => [
            'flesch_score' => $flesch_score,
            'sentence_count' => $sentence_count,
        ],
    ];

    return [
        'analysis' => $analysis,
        'score' => min($score, 100),
        'suggestions' => $suggestions,
    ];
}

/**
 * Count approximate syllables in English text.
 */
function nibwp_seo_count_syllables(string $text): int
{
    $words = str_word_count(mb_strtolower($text), 1);
    $count = 0;
    foreach ($words as $word) {
        $word = preg_replace('/[^a-z]/', '', $word);
        if (strlen($word) <= 3) {
            $count += 1;
            continue;
        }
        // Count vowel groups.
        $syllables = (int) preg_match_all('/[aeiouy]+/', $word);
        // Subtract silent e.
        if (str_ends_with($word, 'e') && $syllables > 1) {
            $syllables--;
        }
        // Words like "le" at end add a syllable.
        if (str_ends_with($word, 'le') && strlen($word) > 2 && !preg_match('/[aeiouy]le$/', $word)) {
            $syllables++;
        }
        $count += max(1, $syllables);
    }
    return $count;
}

// --- Ability: nibwp/seo-get-sitemap-info ---

wp_register_ability('nibwp/seo-get-sitemap-info', [
    'label' => __('SEO – Get Sitemap Info', domain: 'nibwp'),
    'description' => __(
        'Get information about the site\'s XML sitemap including URL, provider, indexed URLs count, and included post types.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => new stdClass(),
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'sitemap_url' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'indexed_urls_count' => ['type' => 'integer'],
            'post_types_included' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => 'nibwp_seo_get_sitemap_info',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Get information about the XML sitemap configuration.',
                '',
                'No input required. Automatically detects the sitemap source:',
                '- Yoast SEO sitemap (/sitemap_index.xml).',
                '- Rank Math sitemap (/sitemap_index.xml).',
                '- All in One SEO sitemap (/sitemap.xml).',
                '- WordPress core sitemap (/wp-sitemap.xml).',
                '',
                'RETURNS:',
                '- sitemap_url: The primary sitemap URL.',
                '- provider: Which plugin/system provides the sitemap.',
                '- indexed_urls_count: Approximate number of URLs (based on published posts/pages).',
                '- post_types_included: Which post types are included in the sitemap.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_get_sitemap_info(array $input): array|WP_Error
{
    $plugin = nibwp_seo_detect_plugin();
    $sitemap_url = '';
    $provider = '';

    if ($plugin) {
        switch ($plugin['slug']) {
            case 'yoast':
                $sitemap_url = home_url('/sitemap_index.xml');
                $provider = 'Yoast SEO';
                break;
            case 'rankmath':
                $sitemap_url = home_url('/sitemap_index.xml');
                $provider = 'Rank Math';
                break;
            case 'aioseo':
                $sitemap_url = home_url('/sitemap.xml');
                $provider = 'All in One SEO';
                break;
        }
    }

    // Fallback to WordPress core sitemap.
    if ($sitemap_url === '') {
        $sitemap_url = home_url('/wp-sitemap.xml');
        $provider = 'WordPress Core';
    }

    // Determine included post types.
    $public_post_types = get_post_types(['public' => true], 'names');
    $excluded = ['attachment'];

    // Check SEO plugin settings for excluded post types.
    if ($plugin && $plugin['slug'] === 'yoast' && class_exists('WPSEO_Options')) {
        foreach ($public_post_types as $pt) {
            $option_key = 'noindex-' . $pt;
            if (WPSEO_Options::get($option_key)) {
                $excluded[] = $pt;
            }
        }
    }

    $included = array_values(array_diff($public_post_types, $excluded));

    // Approximate indexed URLs count.
    $total_urls = 0;
    foreach ($included as $pt) {
        $count = wp_count_posts($pt);
        if ($count && isset($count->publish)) {
            $total_urls += (int) $count->publish;
        }
    }

    // Add taxonomies approximate count.
    $public_taxonomies = get_taxonomies(['public' => true], 'names');
    foreach ($public_taxonomies as $tax) {
        $total_urls += (int) wp_count_terms(['taxonomy' => $tax, 'hide_empty' => true]);
    }

    return [
        'sitemap_url' => $sitemap_url,
        'provider' => $provider,
        'indexed_urls_count' => $total_urls,
        'post_types_included' => $included,
    ];
}

// --- Ability: nibwp/seo-bulk-get-meta ---

wp_register_ability('nibwp/seo-bulk-get-meta', [
    'label' => __('SEO – Bulk Get Meta', domain: 'nibwp'),
    'description' => __(
        'Get SEO metadata for multiple posts at once. Efficient batch query supporting up to 50 posts per request.',
        domain: 'nibwp',
    ),
    'category' => 'seo',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Array of post IDs (max 50).',
            ],
        ],
        'required' => ['post_ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'plugin_name' => ['type' => 'string'],
            'results' => [
                'type' => 'array',
                'description' => 'Array of SEO meta per post.',
            ],
        ],
    ],
    'execute_callback' => 'nibwp_seo_bulk_get_meta',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Get SEO metadata for multiple posts in a single batch request.',
                '',
                'INPUT:',
                '- post_ids: Array of integer post IDs. Maximum 50 per request.',
                '',
                'Uses efficient batch meta queries instead of individual lookups.',
                'Auto-detects the active SEO plugin (Yoast, Rank Math, or AIOSEO).',
                '',
                'RETURNS per post:',
                '- post_id, title (post title), seo_title, description, focus_keyword, canonical, robots.',
                '- error field if a specific post was not found.',
                '',
                'Useful for auditing SEO across many pages at once.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_seo_bulk_get_meta(array $input): array|WP_Error
{
    $post_ids = $input['post_ids'] ?? [];
    if (!is_array($post_ids) || empty($post_ids)) {
        return new WP_Error('missing_post_ids', 'post_ids array is required and must not be empty.');
    }

    $post_ids = array_map('intval', $post_ids);
    $post_ids = array_filter($post_ids, fn($id) => $id > 0);

    if (count($post_ids) > 50) {
        return new WP_Error('too_many_ids', 'Maximum 50 post IDs per request.');
    }

    $plugin = nibwp_seo_detect_plugin();
    if (!$plugin) {
        return new WP_Error('no_seo_plugin', 'No supported SEO plugin detected. Install and activate Yoast SEO, Rank Math, or All in One SEO.');
    }

    // Prime the post meta cache for efficiency.
    update_meta_cache('post', $post_ids);

    // Define meta keys per plugin.
    $meta_keys = match ($plugin['slug']) {
        'yoast' => [
            'seo_title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focus_keyword' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
        ],
        'rankmath' => [
            'seo_title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
        ],
        'aioseo' => [
            'seo_title' => '_aioseo_title',
            'description' => '_aioseo_description',
            'focus_keyword' => '_aioseo_keywords',
            'canonical' => '_aioseo_canonical_url',
        ],
        default => [],
    };

    $results = [];
    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            $results[] = [
                'post_id' => $post_id,
                'error' => 'Post not found.',
            ];
            continue;
        }

        $entry = [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
        ];

        foreach ($meta_keys as $normalized => $meta_key) {
            $entry[$normalized] = (string) get_post_meta($post_id, $meta_key, true);
        }

        // Robots.
        switch ($plugin['slug']) {
            case 'yoast':
                $parts = [];
                if (get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1') {
                    $parts[] = 'noindex';
                }
                if (get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true) === '1') {
                    $parts[] = 'nofollow';
                }
                $entry['robots'] = implode(', ', $parts);
                break;
            case 'rankmath':
                $robots = get_post_meta($post_id, 'rank_math_robots', true);
                $entry['robots'] = is_array($robots) ? implode(', ', $robots) : (string) ($robots ?? '');
                break;
            case 'aioseo':
                $parts = [];
                if (get_post_meta($post_id, '_aioseo_noindex', true) === '1') {
                    $parts[] = 'noindex';
                }
                if (get_post_meta($post_id, '_aioseo_nofollow', true) === '1') {
                    $parts[] = 'nofollow';
                }
                $entry['robots'] = implode(', ', $parts);
                break;
        }

        $results[] = $entry;
    }

    return [
        'plugin_name' => $plugin['name'],
        'results' => $results,
    ];
}
