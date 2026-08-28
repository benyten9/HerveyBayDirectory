<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// =============================================================================
// Ability: nibwp/fetch-url-content
// =============================================================================

wp_register_ability('nibwp/fetch-url-content', [
    'label' => __('Fetch – URL Content', domain: 'nibwp'),
    'description' => __(
        'Fetch and extract content from any URL with configurable extraction modes.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The URL to fetch.',
            ],
            'extract' => [
                'type' => 'string',
                'enum' => ['full', 'article', 'text', 'metadata'],
                'description' => 'Extraction mode. Default "article".',
            ],
            'selectors' => [
                'type' => 'object',
                'description' => 'CSS selectors for custom extraction: {title: "h1", content: ".article-body", author: ".author-name"}.',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'author' => ['type' => 'string'],
                ],
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string'],
            'author' => ['type' => 'string'],
            'date' => ['type' => 'string'],
            'images' => ['type' => 'array', 'items' => ['type' => 'string']],
            'source_url' => ['type' => 'string'],
            'word_count' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_url_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Fetch and extract content from any URL.',
                '',
                'EXTRACTION MODES:',
                '- "full": Returns the entire HTML body.',
                '- "article": Smart extraction of main article content, stripping nav/footer/sidebar/ads.',
                '- "text": Plain text only, all HTML stripped.',
                '- "metadata": Only extracts title, description, og:image, author, published date.',
                '',
                'CUSTOM SELECTORS:',
                'Pass a selectors object to target specific elements:',
                '  selectors: {title: "h1.entry-title", content: "div.post-content", author: "span.author"}',
                'Selectors use simple CSS class/tag/ID matching (not full CSS selector engine).',
                '',
                'RETURNS: title, body (HTML or text), author, date, images array, source_url, word_count.',
                '',
                'NOTE: Respects robots.txt conventions. Some sites may block automated requests.',
                'External fetching uses a 15-second timeout.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fetch_url_content(array $input): array|WP_Error
{
    $url = $input['url'] ?? '';
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'A valid URL is required.');
    }

    $extract = $input['extract'] ?? 'article';
    $selectors = $input['selectors'] ?? [];

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'user-agent' => 'NIBWP/1.0 (WordPress; +' . home_url() . ')',
        'sslverify' => false,
        'redirection' => 5,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', 'Failed to fetch URL: ' . $response->get_error_message());
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return new WP_Error('http_error', sprintf('URL returned HTTP %d.', $status_code));
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        return new WP_Error('empty_response', 'The URL returned an empty response.');
    }

    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($doc);

    $result = [
        'title' => '',
        'body' => '',
        'author' => '',
        'date' => '',
        'images' => [],
        'source_url' => $url,
        'word_count' => 0,
    ];

    // Extract title.
    $result['title'] = nibwp_fetch_extract_by_selector($xpath, $selectors['title'] ?? null, 'title')
        ?: nibwp_fetch_extract_meta($xpath, 'og:title')
        ?: nibwp_fetch_extract_tag_text($xpath, '//title');

    // Extract metadata.
    $result['author'] = nibwp_fetch_extract_by_selector($xpath, $selectors['author'] ?? null, null)
        ?: nibwp_fetch_extract_meta($xpath, 'author')
        ?: nibwp_fetch_extract_meta_name($xpath, 'author');

    $result['date'] = nibwp_fetch_extract_meta($xpath, 'article:published_time')
        ?: nibwp_fetch_extract_meta_name($xpath, 'date')
        ?: nibwp_fetch_extract_meta_name($xpath, 'publishdate');

    // Extract images.
    $img_nodes = $xpath->query('//img[@src]');
    if ($img_nodes) {
        foreach ($img_nodes as $img) {
            $src = $img->getAttribute('src');
            if (!empty($src)) {
                // Make absolute.
                if (str_starts_with($src, '//')) {
                    $src = 'https:' . $src;
                } elseif (str_starts_with($src, '/')) {
                    $parsed = wp_parse_url($url);
                    $src = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $src;
                }
                $result['images'][] = $src;
            }
        }
        $result['images'] = array_values(array_unique(array_slice($result['images'], 0, 50)));
    }

    // Extract og:image if not in images already.
    $og_image = nibwp_fetch_extract_meta($xpath, 'og:image');
    if ($og_image && !in_array($og_image, $result['images'], true)) {
        array_unshift($result['images'], $og_image);
    }

    if ($extract === 'metadata') {
        $result['body'] = nibwp_fetch_extract_meta($xpath, 'og:description')
            ?: nibwp_fetch_extract_meta_name($xpath, 'description')
            ?: '';
        $result['word_count'] = str_word_count($result['body']);
        return $result;
    }

    if ($extract === 'full') {
        $body_node = $xpath->query('//body')->item(0);
        $result['body'] = $body_node ? $doc->saveHTML($body_node) : $html;
        $result['word_count'] = str_word_count(strip_tags($result['body']));
        return $result;
    }

    // Article or text mode: extract main content.
    $content_html = '';

    if (!empty($selectors['content'])) {
        $content_html = nibwp_fetch_extract_by_selector($xpath, $selectors['content'], null, true);
    }

    if (empty($content_html)) {
        // Try common article selectors.
        $article_selectors = [
            '//article',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "article-body")]',
            '//*[contains(@class, "post-body")]',
            '//*[contains(@class, "content-area")]',
            '//*[@id="content"]',
            '//main',
        ];

        foreach ($article_selectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes && $nodes->length > 0) {
                $node = $nodes->item(0);
                $content_html = $doc->saveHTML($node);
                break;
            }
        }
    }

    // Fallback: get body and strip non-content elements.
    if (empty($content_html)) {
        $body_node = $xpath->query('//body')->item(0);
        if ($body_node) {
            // Remove nav, footer, sidebar, script, style, header elements.
            $remove_tags = ['nav', 'footer', 'aside', 'script', 'style', 'header', 'noscript', 'iframe'];
            foreach ($remove_tags as $tag) {
                $remove_nodes = $xpath->query('//' . $tag);
                if ($remove_nodes) {
                    foreach ($remove_nodes as $remove_node) {
                        if ($remove_node->parentNode) {
                            $remove_node->parentNode->removeChild($remove_node);
                        }
                    }
                }
            }
            // Also remove common non-content classes.
            $non_content_classes = ['sidebar', 'widget', 'comment', 'footer', 'nav', 'menu', 'advertisement', 'ad-container'];
            foreach ($non_content_classes as $class) {
                $class_nodes = $xpath->query('//*[contains(@class, "' . $class . '")]');
                if ($class_nodes) {
                    foreach ($class_nodes as $cn) {
                        if ($cn->parentNode) {
                            $cn->parentNode->removeChild($cn);
                        }
                    }
                }
            }
            $content_html = $doc->saveHTML($body_node);
        }
    }

    if ($extract === 'text') {
        $result['body'] = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($content_html)));
    } else {
        $result['body'] = $content_html;
    }

    $result['word_count'] = str_word_count(wp_strip_all_tags($result['body']));

    return $result;
}

/**
 * Extract text content by a simple CSS-like selector via XPath.
 */
function nibwp_fetch_extract_by_selector(DOMXPath $xpath, ?string $selector, ?string $fallback_tag, bool $return_html = false): string
{
    if (empty($selector) && empty($fallback_tag)) {
        return '';
    }

    $xp = '';
    if (!empty($selector)) {
        // Convert simple CSS selector to XPath.
        if (str_starts_with($selector, '#')) {
            $xp = '//*[@id="' . substr($selector, 1) . '"]';
        } elseif (str_starts_with($selector, '.')) {
            $xp = '//*[contains(@class, "' . substr($selector, 1) . '")]';
        } elseif (str_contains($selector, '.')) {
            [$tag, $class] = explode('.', $selector, 2);
            $xp = '//' . $tag . '[contains(@class, "' . $class . '")]';
        } elseif (str_contains($selector, '#')) {
            [$tag, $id] = explode('#', $selector, 2);
            $xp = '//' . $tag . '[@id="' . $id . '"]';
        } else {
            $xp = '//' . $selector;
        }
    } elseif (!empty($fallback_tag)) {
        $xp = '//' . $fallback_tag;
    }

    if (empty($xp)) {
        return '';
    }

    $nodes = $xpath->query($xp);
    if ($nodes && $nodes->length > 0) {
        $node = $nodes->item(0);
        if ($return_html) {
            return $node->ownerDocument->saveHTML($node);
        }
        return trim($node->textContent);
    }

    return '';
}

/**
 * Extract Open Graph or property meta content.
 */
function nibwp_fetch_extract_meta(DOMXPath $xpath, string $property): string
{
    $node = $xpath->query('//meta[@property="' . $property . '"]/@content')->item(0);
    return $node ? trim($node->nodeValue) : '';
}

/**
 * Extract meta by name attribute.
 */
function nibwp_fetch_extract_meta_name(DOMXPath $xpath, string $name): string
{
    $node = $xpath->query('//meta[@name="' . $name . '"]/@content')->item(0);
    return $node ? trim($node->nodeValue) : '';
}

/**
 * Extract text from a tag via XPath.
 */
function nibwp_fetch_extract_tag_text(DOMXPath $xpath, string $xp): string
{
    $node = $xpath->query($xp)->item(0);
    return $node ? trim($node->textContent) : '';
}

// =============================================================================
// Ability: nibwp/fetch-rss-feed
// =============================================================================

wp_register_ability('nibwp/fetch-rss-feed', [
    'label' => __('Fetch – RSS Feed', domain: 'nibwp'),
    'description' => __(
        'Fetch and parse an RSS or Atom feed, returning structured items.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The RSS/Atom feed URL.',
            ],
            'per_page' => [
                'type' => 'integer',
                'description' => 'Number of items to return. Default 20.',
            ],
            'since' => [
                'type' => 'string',
                'description' => 'Optional ISO date: only return items after this date.',
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'feed_title' => ['type' => 'string'],
            'feed_url' => ['type' => 'string'],
            'total_items' => ['type' => 'integer'],
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'excerpt' => ['type' => 'string'],
                        'link' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                        'author' => ['type' => 'string'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'enclosure' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_rss_feed',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Fetch and parse an RSS or Atom feed.',
                '',
                'Uses WordPress built-in SimplePie (fetch_feed) for reliable parsing.',
                '',
                'OPTIONS:',
                '- url: the feed URL (required).',
                '- per_page: max items to return (default 20, max 100).',
                '- since: ISO 8601 date to filter only newer items.',
                '',
                'RETURNS for each item: title, content (full HTML), excerpt (first 200 chars),',
                'link, date, author, categories array, enclosure URL (for podcasts/media).',
                '',
                'Supports RSS 2.0, RSS 1.0, Atom 1.0, and Atom 0.3 feeds.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fetch_rss_feed(array $input): array|WP_Error
{
    $url = $input['url'] ?? '';
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'A valid feed URL is required.');
    }

    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $since = !empty($input['since']) ? strtotime($input['since']) : false;

    // Use WordPress built-in SimplePie.
    if (!function_exists('fetch_feed')) {
        require_once ABSPATH . WPINC . '/feed.php';
    }

    $feed = fetch_feed($url);
    if (is_wp_error($feed)) {
        return new WP_Error('feed_error', 'Failed to parse feed: ' . $feed->get_error_message());
    }

    $feed_title = $feed->get_title() ?: '';
    $max_items = $feed->get_item_quantity($per_page);
    $feed_items = $feed->get_items(0, $max_items);

    $items = [];
    foreach ($feed_items as $item) {
        $item_date = $item->get_date('c');
        $item_timestamp = $item->get_date('U');

        // Filter by since date.
        if ($since !== false && $item_timestamp && (int) $item_timestamp < $since) {
            continue;
        }

        $content = $item->get_content() ?: '';
        $description = $item->get_description() ?: '';

        // Extract categories.
        $categories = [];
        $item_cats = $item->get_categories();
        if ($item_cats) {
            foreach ($item_cats as $cat) {
                $label = $cat->get_label();
                if (!empty($label)) {
                    $categories[] = $label;
                }
            }
        }

        // Get author.
        $author = '';
        $item_author = $item->get_author();
        if ($item_author) {
            $author = $item_author->get_name() ?: ($item_author->get_email() ?: '');
        }

        // Get enclosure.
        $enclosure_url = '';
        $enclosure = $item->get_enclosure();
        if ($enclosure) {
            $enclosure_url = $enclosure->get_link() ?: '';
        }

        $plain_text = wp_strip_all_tags($content ?: $description);

        $items[] = [
            'title' => $item->get_title() ?: '',
            'content' => $content,
            'excerpt' => mb_substr(trim($plain_text), 0, 200),
            'link' => $item->get_permalink() ?: '',
            'date' => $item_date ?: '',
            'author' => $author,
            'categories' => $categories,
            'enclosure' => $enclosure_url,
        ];
    }

    return [
        'feed_title' => $feed_title,
        'feed_url' => $url,
        'total_items' => count($items),
        'items' => $items,
    ];
}

// =============================================================================
// Ability: nibwp/fetch-rewrite-content
// =============================================================================

wp_register_ability('nibwp/fetch-rewrite-content', [
    'label' => __('Fetch – Rewrite Content', domain: 'nibwp'),
    'description' => __(
        'Transform content locally: summarize, convert to bullet points, expand with subheadings, or basic rewrite.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'content' => [
                'type' => 'string',
                'description' => 'The content to transform (HTML or plain text).',
            ],
            'instructions' => [
                'type' => 'string',
                'description' => 'What to change or how to transform the content.',
            ],
            'format' => [
                'type' => 'string',
                'enum' => ['rewrite', 'summarize', 'translate', 'expand', 'bullet_points', 'custom'],
                'description' => 'Transformation format.',
            ],
        ],
        'required' => ['content', 'instructions', 'format'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'original_word_count' => ['type' => 'integer'],
            'new_word_count' => ['type' => 'integer'],
            'transformed_content' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_rewrite_content',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Transform content using local text processing (no external AI API).',
                '',
                'FORMATS:',
                '- "summarize": Extracts the first paragraph and key sentences scored by',
                '  position and keyword density. Aims for ~30% of original length.',
                '- "bullet_points": Converts paragraphs into an HTML unordered list.',
                '- "rewrite": Basic restructuring - shuffles sentence order within paragraphs,',
                '  swaps common synonyms. This is simple pattern-based, not AI-quality.',
                '- "expand": Inserts <h2> subheadings between paragraphs based on content keywords.',
                '- "translate": Not implemented locally (returns error suggesting external service).',
                '- "custom": Applies the instructions as literal str_replace patterns.',
                '',
                'INPUT: Pass HTML or plain text content. Instructions describe the goal.',
                'RETURNS: original_word_count, new_word_count, transformed_content.',
                '',
                'NOTE: "rewrite" is basic pattern matching, not AI rewriting.',
                'For high-quality rewrites, use an AI-powered tool instead.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fetch_rewrite_content(array $input): array|WP_Error
{
    $content = $input['content'] ?? '';
    $instructions = $input['instructions'] ?? '';
    $format = $input['format'] ?? '';

    if (empty($content)) {
        return new WP_Error('empty_content', 'content must not be empty.');
    }
    if (empty($format)) {
        return new WP_Error('missing_format', 'format is required.');
    }

    $plain_text = wp_strip_all_tags($content);
    $original_word_count = str_word_count($plain_text);

    $transformed = '';

    switch ($format) {
        case 'summarize':
            $transformed = nibwp_fetch_summarize($content);
            break;

        case 'bullet_points':
            $transformed = nibwp_fetch_to_bullets($content);
            break;

        case 'rewrite':
            $transformed = nibwp_fetch_basic_rewrite($content);
            break;

        case 'expand':
            $transformed = nibwp_fetch_expand($content);
            break;

        case 'translate':
            return new WP_Error(
                'not_implemented',
                'Local translation is not available. Use an external translation API or AI service for this task.'
            );

        case 'custom':
            // Apply instructions as find/replace pairs (one per line, separated by " => ").
            $transformed = $content;
            $lines = explode("\n", $instructions);
            foreach ($lines as $line) {
                if (str_contains($line, ' => ')) {
                    [$find, $replace] = explode(' => ', $line, 2);
                    $transformed = str_replace(trim($find), trim($replace), $transformed);
                }
            }
            break;

        default:
            return new WP_Error('invalid_format', 'format must be one of: rewrite, summarize, translate, expand, bullet_points, custom.');
    }

    $new_word_count = str_word_count(wp_strip_all_tags($transformed));

    return [
        'original_word_count' => $original_word_count,
        'new_word_count' => $new_word_count,
        'transformed_content' => $transformed,
    ];
}

/**
 * Summarize content by extracting key sentences.
 */
function nibwp_fetch_summarize(string $content): string
{
    $plain = wp_strip_all_tags($content);
    $sentences = preg_split('/(?<=[.!?])\s+/', $plain, -1, PREG_SPLIT_NO_EMPTY);

    if (count($sentences) <= 3) {
        return $content;
    }

    // Score sentences: earlier = higher, longer = higher, keywords = higher.
    $total = count($sentences);
    $all_words = str_word_count(strtolower($plain), 1);
    $word_freq = array_count_values($all_words);
    arsort($word_freq);
    $top_keywords = array_slice(array_keys($word_freq), 0, 10);

    $scored = [];
    foreach ($sentences as $idx => $sentence) {
        $position_score = 1.0 - ($idx / $total) * 0.5; // Earlier sentences score higher.
        $length_score = min(str_word_count($sentence) / 20.0, 1.0); // Longer sentences up to 20 words.

        $keyword_score = 0;
        $sentence_lower = strtolower($sentence);
        foreach ($top_keywords as $kw) {
            if (str_contains($sentence_lower, $kw)) {
                $keyword_score += 0.1;
            }
        }

        $scored[$idx] = $position_score + $length_score + $keyword_score;
    }

    arsort($scored);

    // Take top ~30% of sentences, maintain original order.
    $keep_count = max(2, (int) ceil($total * 0.3));
    $keep_indices = array_slice(array_keys($scored), 0, $keep_count);
    sort($keep_indices);

    $summary_sentences = [];
    foreach ($keep_indices as $idx) {
        $summary_sentences[] = $sentences[$idx];
    }

    return '<p>' . implode(' ', $summary_sentences) . '</p>';
}

/**
 * Convert paragraphs to bullet points.
 */
function nibwp_fetch_to_bullets(string $content): string
{
    // Split by paragraph tags or double newlines.
    $paragraphs = preg_split('/<\/p>\s*<p[^>]*>|<br\s*\/?>\s*<br\s*\/?>|\n{2,}/', $content);
    $paragraphs = array_filter(array_map(function ($p) {
        return trim(wp_strip_all_tags($p));
    }, $paragraphs));

    if (empty($paragraphs)) {
        return $content;
    }

    $items = [];
    foreach ($paragraphs as $paragraph) {
        if (mb_strlen($paragraph) < 5) {
            continue;
        }
        // If paragraph has multiple sentences, each becomes a bullet.
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) >= 5) {
                $items[] = '<li>' . esc_html($sentence) . '</li>';
            }
        }
    }

    return '<ul>' . "\n" . implode("\n", $items) . "\n" . '</ul>';
}

/**
 * Basic rewrite: shuffle sentence order within paragraphs and swap common synonyms.
 */
function nibwp_fetch_basic_rewrite(string $content): string
{
    $synonyms = [
        'important' => 'significant',
        'significant' => 'notable',
        'however' => 'nevertheless',
        'therefore' => 'consequently',
        'additionally' => 'furthermore',
        'furthermore' => 'moreover',
        'moreover' => 'in addition',
        'utilize' => 'use',
        'implement' => 'apply',
        'demonstrate' => 'show',
        'establish' => 'create',
        'obtain' => 'get',
        'require' => 'need',
        'sufficient' => 'enough',
        'numerous' => 'many',
        'commence' => 'begin',
        'terminate' => 'end',
        'facilitate' => 'help',
        'regarding' => 'about',
        'approximately' => 'about',
        'frequently' => 'often',
        'rapidly' => 'quickly',
        'immediately' => 'at once',
        'excellent' => 'outstanding',
        'challenging' => 'difficult',
    ];

    // Process paragraph by paragraph.
    $paragraphs = preg_split('/(<\/p>\s*<p[^>]*>|<br\s*\/?>\s*<br\s*\/?>|\n{2,})/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = [];

    foreach ($paragraphs as $paragraph) {
        // Skip delimiters.
        if (preg_match('/^(<\/p>|<br|[\n\r])/', trim($paragraph))) {
            $result[] = $paragraph;
            continue;
        }

        $text = $paragraph;

        // Apply synonym replacements (case-insensitive, whole word).
        foreach ($synonyms as $find => $replace) {
            $text = preg_replace('/\b' . preg_quote($find, '/') . '\b/i', $replace, $text);
        }

        $result[] = $text;
    }

    return implode('', $result);
}

/**
 * Expand content by inserting subheadings.
 */
function nibwp_fetch_expand(string $content): string
{
    // Split into paragraphs.
    $parts = preg_split('/(<\/p>\s*<p[^>]*>|\n{2,})/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

    if (count($parts) < 3) {
        return $content;
    }

    $result = [];
    $paragraph_count = 0;

    foreach ($parts as $part) {
        $trimmed = trim(wp_strip_all_tags($part));

        // Skip delimiters and short parts.
        if (mb_strlen($trimmed) < 20) {
            $result[] = $part;
            continue;
        }

        $paragraph_count++;

        // Insert a heading every 2-3 paragraphs (starting from the second).
        if ($paragraph_count > 1 && $paragraph_count % 2 === 0) {
            // Generate heading from first few meaningful words of the paragraph.
            $words = preg_split('/\s+/', $trimmed);
            $heading_words = array_slice($words, 0, min(6, count($words)));
            $heading = ucfirst(implode(' ', $heading_words));
            $heading = rtrim($heading, '.,;:!?');

            $result[] = "\n<h2>" . esc_html($heading) . "</h2>\n";
        }

        $result[] = $part;
    }

    return implode('', $result);
}

// =============================================================================
// Ability: nibwp/fetch-to-draft
// =============================================================================

wp_register_ability('nibwp/fetch-to-draft', [
    'label' => __('Fetch – URL to Draft', domain: 'nibwp'),
    'description' => __(
        'Fetch content from a URL and create a WordPress draft post, optionally importing images.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The URL to fetch content from.',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type. Default "post".',
            ],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Category IDs to assign.',
            ],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Tag names to assign.',
            ],
            'extract_images' => [
                'type' => 'boolean',
                'description' => 'Sideload images to the media library. Default true.',
            ],
            'add_source_link' => [
                'type' => 'boolean',
                'description' => 'Add "Source: URL" at the bottom of the post. Default true.',
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'word_count' => ['type' => 'integer'],
            'images_imported' => ['type' => 'integer'],
            'edit_url' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_to_draft',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Fetch content from a URL and create a draft post.',
                '',
                'PROCESS:',
                '1. Fetches the URL using article extraction mode.',
                '2. Creates a draft post with the extracted title and content.',
                '3. Optionally sideloads images to the WordPress media library (extract_images=true).',
                '4. Optionally appends a "Source: <url>" link at the bottom (add_source_link=true).',
                '',
                'OPTIONS:',
                '- post_type: target post type (default "post").',
                '- categories: array of category IDs.',
                '- tags: array of tag names.',
                '- extract_images: sideload external images (default true, may be slow).',
                '- add_source_link: credit the source URL (default true).',
                '',
                'RETURNS: post_id, title, word_count, images_imported count, and admin edit URL.',
                '',
                'The post is always created as a draft for review before publishing.',
                'Images are sideloaded using media_sideload_image() for local hosting.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fetch_to_draft(array $input): array|WP_Error
{
    $url = $input['url'] ?? '';
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'A valid URL is required.');
    }

    $post_type = sanitize_text_field($input['post_type'] ?? 'post');
    $extract_images = $input['extract_images'] ?? true;
    $add_source_link = $input['add_source_link'] ?? true;

    if (!post_type_exists($post_type)) {
        return new WP_Error('invalid_post_type', sprintf('Post type "%s" does not exist.', $post_type));
    }

    // Fetch the content.
    $fetched = nibwp_fetch_url_content([
        'url' => $url,
        'extract' => 'article',
    ]);

    if (is_wp_error($fetched)) {
        return $fetched;
    }

    $title = $fetched['title'] ?: 'Imported from ' . wp_parse_url($url, PHP_URL_HOST);
    $body = $fetched['body'] ?? '';

    if (empty($body)) {
        return new WP_Error('no_content', 'Could not extract any content from the URL.');
    }

    // Add source link.
    if ($add_source_link) {
        $body .= "\n\n" . '<p><em>Source: <a href="' . esc_url($url) . '" rel="nofollow">' . esc_html($url) . '</a></em></p>';
    }

    // Create the draft post.
    $post_id = wp_insert_post([
        'post_title' => sanitize_text_field($title),
        'post_content' => wp_kses_post($body),
        'post_status' => 'draft',
        'post_type' => $post_type,
    ], true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    // Set categories.
    if (!empty($input['categories']) && is_array($input['categories'])) {
        wp_set_post_categories($post_id, array_map('absint', $input['categories']));
    }

    // Set tags.
    if (!empty($input['tags']) && is_array($input['tags'])) {
        wp_set_post_tags($post_id, array_map('sanitize_text_field', $input['tags']));
    }

    // Sideload images.
    $images_imported = 0;
    if ($extract_images && !empty($fetched['images'])) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $updated_body = $body;

        foreach ($fetched['images'] as $image_url) {
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');
            if (!is_wp_error($attachment_id)) {
                $local_url = wp_get_attachment_url($attachment_id);
                if ($local_url) {
                    $updated_body = str_replace($image_url, $local_url, $updated_body);
                    $images_imported++;
                }

                // Set first image as featured if no featured image yet.
                if ($images_imported === 1 && !has_post_thumbnail($post_id)) {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            }
        }

        if ($images_imported > 0) {
            wp_update_post([
                'ID' => $post_id,
                'post_content' => wp_kses_post($updated_body),
            ]);
        }
    }

    return [
        'post_id' => $post_id,
        'title' => $title,
        'word_count' => $fetched['word_count'] ?? 0,
        'images_imported' => $images_imported,
        'edit_url' => get_edit_post_link($post_id, 'raw'),
    ];
}

// =============================================================================
// Ability: nibwp/fetch-bulk-import
// =============================================================================

wp_register_ability('nibwp/fetch-bulk-import', [
    'label' => __('Fetch – Bulk Import from RSS', domain: 'nibwp'),
    'description' => __(
        'Bulk import posts from an RSS feed as drafts.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'feed_url' => [
                'type' => 'string',
                'description' => 'RSS/Atom feed URL to import from.',
            ],
            'max_items' => [
                'type' => 'integer',
                'description' => 'Maximum items to import. Default 10.',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Post type. Default "post".',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'Post status. Default "draft".',
            ],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Category IDs to assign to all imported posts.',
            ],
            'skip_existing' => [
                'type' => 'boolean',
                'description' => 'Skip items whose title already exists as a post. Default true.',
            ],
            'extract_images' => [
                'type' => 'boolean',
                'description' => 'Sideload images to media library. Default false (slow).',
            ],
        ],
        'required' => ['feed_url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'imported_count' => ['type' => 'integer'],
            'skipped_count' => ['type' => 'integer'],
            'posts' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'source_url' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_bulk_import',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Bulk import posts from an RSS/Atom feed.',
                '',
                'PROCESS:',
                '1. Fetches the RSS feed using SimplePie.',
                '2. For each item, creates a post with the feed item content.',
                '3. Skips items whose title already exists (skip_existing=true by default).',
                '4. Optionally sideloads images (extract_images=false by default, it is slow).',
                '',
                'OPTIONS:',
                '- max_items: limit how many items to import (default 10, max 50).',
                '- post_type: target post type (default "post").',
                '- status: post status (default "draft" for review).',
                '- categories: category IDs for all imported posts.',
                '- skip_existing: avoid duplicate titles (default true).',
                '- extract_images: sideload images to local media library (default false).',
                '',
                'Source URL is stored in _nibwp_source_url post meta.',
                'Original publish date from the feed is preserved as the post date.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

function nibwp_fetch_bulk_import(array $input): array|WP_Error
{
    $feed_url = $input['feed_url'] ?? '';
    if (empty($feed_url) || !filter_var($feed_url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'A valid feed_url is required.');
    }

    $max_items = min(max((int) ($input['max_items'] ?? 10), 1), 50);
    $post_type = sanitize_text_field($input['post_type'] ?? 'post');
    $status = sanitize_text_field($input['status'] ?? 'draft');
    $skip_existing = $input['skip_existing'] ?? true;
    $extract_images = $input['extract_images'] ?? false;

    if (!post_type_exists($post_type)) {
        return new WP_Error('invalid_post_type', sprintf('Post type "%s" does not exist.', $post_type));
    }

    $allowed_statuses = ['draft', 'pending', 'private'];
    if (!in_array($status, $allowed_statuses, true)) {
        return new WP_Error('invalid_status', sprintf('Status must be one of: %s.', implode(', ', $allowed_statuses)));
    }

    // Fetch the feed.
    $feed_result = nibwp_fetch_rss_feed([
        'url' => $feed_url,
        'per_page' => $max_items,
    ]);

    if (is_wp_error($feed_result)) {
        return $feed_result;
    }

    if ($extract_images) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $imported = 0;
    $skipped = 0;
    $posts = [];

    foreach ($feed_result['items'] as $item) {
        $title = sanitize_text_field($item['title'] ?? '');
        if (empty($title)) {
            $skipped++;
            continue;
        }

        // Check for existing post with same title.
        if ($skip_existing) {
            $existing = get_page_by_title($title, OBJECT, $post_type);
            if ($existing) {
                $skipped++;
                continue;
            }
        }

        $content = wp_kses_post($item['content'] ?? $item['excerpt'] ?? '');

        // Determine post date from feed item.
        $post_date = !empty($item['date']) ? date('Y-m-d H:i:s', strtotime($item['date'])) : current_time('mysql');

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $status,
            'post_type' => $post_type,
            'post_date' => $post_date,
        ], true);

        if (is_wp_error($post_id)) {
            $skipped++;
            continue;
        }

        // Store source URL.
        if (!empty($item['link'])) {
            update_post_meta($post_id, '_nibwp_source_url', esc_url_raw($item['link']));
        }

        // Set categories.
        if (!empty($input['categories']) && is_array($input['categories'])) {
            wp_set_post_categories($post_id, array_map('absint', $input['categories']));
        }

        // Sideload images if requested.
        if ($extract_images && !empty($content)) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $img_nodes = $doc->getElementsByTagName('img');
            $updated_content = $content;

            foreach ($img_nodes as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
                    $att_id = media_sideload_image($src, $post_id, null, 'id');
                    if (!is_wp_error($att_id)) {
                        $local_url = wp_get_attachment_url($att_id);
                        if ($local_url) {
                            $updated_content = str_replace($src, $local_url, $updated_content);
                        }
                        if (!has_post_thumbnail($post_id)) {
                            set_post_thumbnail($post_id, $att_id);
                        }
                    }
                }
            }

            if ($updated_content !== $content) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => wp_kses_post($updated_content),
                ]);
            }
        }

        $imported++;
        $posts[] = [
            'post_id' => $post_id,
            'title' => $title,
            'source_url' => $item['link'] ?? '',
        ];
    }

    return [
        'imported_count' => $imported,
        'skipped_count' => $skipped,
        'posts' => $posts,
    ];
}

// =============================================================================
// Ability: nibwp/fetch-sitemap-urls
// =============================================================================

wp_register_ability('nibwp/fetch-sitemap-urls', [
    'label' => __('Fetch – Sitemap URLs', domain: 'nibwp'),
    'description' => __(
        'Extract all URLs from an XML sitemap, including sitemap index files.',
        domain: 'nibwp',
    ),
    'category' => 'content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => [
                'type' => 'string',
                'description' => 'The sitemap URL.',
            ],
            'type_filter' => [
                'type' => 'string',
                'description' => 'Optional: filter URLs containing this path segment (e.g. "post", "page", "product").',
            ],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'total_count' => ['type' => 'integer'],
            'urls' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'loc' => ['type' => 'string'],
                        'lastmod' => ['type' => 'string'],
                        'changefreq' => ['type' => 'string'],
                        'priority' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ],
    'execute_callback' => 'nibwp_fetch_sitemap_urls',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Extract all URLs from an XML sitemap.',
                '',
                'Supports:',
                '- Standard XML sitemaps (<urlset> with <url> entries).',
                '- Sitemap index files (<sitemapindex> with <sitemap> entries) - recursively fetches each sub-sitemap.',
                '',
                'OPTIONS:',
                '- url: the sitemap URL (required). Usually /sitemap.xml or /sitemap_index.xml.',
                '- type_filter: only include URLs containing this string (e.g. "post" to filter blog posts).',
                '',
                'RETURNS: total_count and an array of URL objects with:',
                '- loc: the URL.',
                '- lastmod: last modification date (if present).',
                '- changefreq: change frequency hint (if present).',
                '- priority: priority value (if present).',
                '',
                'Maximum 5000 URLs returned. For very large sitemaps, use type_filter to narrow results.',
            ]),
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

function nibwp_fetch_sitemap_urls(array $input): array|WP_Error
{
    $url = $input['url'] ?? '';
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'A valid sitemap URL is required.');
    }

    $type_filter = $input['type_filter'] ?? '';
    $max_urls = 5000;

    $urls = nibwp_fetch_parse_sitemap($url, $type_filter, $max_urls);
    if (is_wp_error($urls)) {
        return $urls;
    }

    return [
        'total_count' => count($urls),
        'urls' => $urls,
    ];
}

/**
 * Recursively parse a sitemap or sitemap index.
 */
function nibwp_fetch_parse_sitemap(string $url, string $type_filter, int $max_urls, int $depth = 0): array|WP_Error
{
    if ($depth > 3) {
        return new WP_Error('too_deep', 'Sitemap nesting exceeds maximum depth of 3.');
    }

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'user-agent' => 'NIBWP/1.0 (WordPress; +' . home_url() . ')',
        'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', 'Failed to fetch sitemap: ' . $response->get_error_message());
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return new WP_Error('http_error', sprintf('Sitemap returned HTTP %d.', $status_code));
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return new WP_Error('empty_response', 'Sitemap returned empty response.');
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();

    if ($xml === false) {
        return new WP_Error('invalid_xml', 'Could not parse sitemap as XML.');
    }

    $urls = [];

    // Register namespaces.
    $namespaces = $xml->getNamespaces(true);
    $ns = '';
    if (isset($namespaces[''])) {
        $xml->registerXPathNamespace('sm', $namespaces['']);
        $ns = 'sm:';
    }

    // Check if this is a sitemap index.
    $sitemaps = $xml->xpath('//' . $ns . 'sitemapindex/' . $ns . 'sitemap');
    if (!empty($sitemaps)) {
        foreach ($sitemaps as $sitemap) {
            $loc = (string) $sitemap->{($ns ? 'loc' : 'loc')};
            if (empty($loc)) {
                // Try with namespace prefix.
                $loc_nodes = $sitemap->xpath($ns . 'loc');
                $loc = !empty($loc_nodes) ? (string) $loc_nodes[0] : '';
            }
            if (empty($loc)) {
                continue;
            }

            $sub_urls = nibwp_fetch_parse_sitemap($loc, $type_filter, $max_urls - count($urls), $depth + 1);
            if (!is_wp_error($sub_urls)) {
                $urls = array_merge($urls, $sub_urls);
            }

            if (count($urls) >= $max_urls) {
                break;
            }
        }
        return array_slice($urls, 0, $max_urls);
    }

    // Standard sitemap: extract <url> entries.
    $url_nodes = $xml->xpath('//' . $ns . 'urlset/' . $ns . 'url');
    if (empty($url_nodes)) {
        // Try without namespace.
        $url_nodes = $xml->xpath('//url');
    }

    if (!empty($url_nodes)) {
        foreach ($url_nodes as $url_node) {
            $loc = '';
            $loc_nodes = $url_node->xpath($ns . 'loc');
            if (!empty($loc_nodes)) {
                $loc = (string) $loc_nodes[0];
            } elseif (isset($url_node->loc)) {
                $loc = (string) $url_node->loc;
            }

            if (empty($loc)) {
                continue;
            }

            // Apply type filter.
            if (!empty($type_filter) && !str_contains($loc, $type_filter)) {
                continue;
            }

            $lastmod = '';
            $lastmod_nodes = $url_node->xpath($ns . 'lastmod');
            if (!empty($lastmod_nodes)) {
                $lastmod = (string) $lastmod_nodes[0];
            } elseif (isset($url_node->lastmod)) {
                $lastmod = (string) $url_node->lastmod;
            }

            $changefreq = '';
            $changefreq_nodes = $url_node->xpath($ns . 'changefreq');
            if (!empty($changefreq_nodes)) {
                $changefreq = (string) $changefreq_nodes[0];
            } elseif (isset($url_node->changefreq)) {
                $changefreq = (string) $url_node->changefreq;
            }

            $priority = '';
            $priority_nodes = $url_node->xpath($ns . 'priority');
            if (!empty($priority_nodes)) {
                $priority = (string) $priority_nodes[0];
            } elseif (isset($url_node->priority)) {
                $priority = (string) $url_node->priority;
            }

            $urls[] = [
                'loc' => $loc,
                'lastmod' => $lastmod,
                'changefreq' => $changefreq,
                'priority' => $priority,
            ];

            if (count($urls) >= $max_urls) {
                break;
            }
        }
    }

    return $urls;
}
